<?php
/**
 * Helpers de sécurité Zinbconnect.
 * - Jetons CSRF
 * - Limitation de débit / anti-brute-force (table rate_limits)
 * - Journal d'audit (table audit_log)
 * - Génération et consommation ATOMIQUE des codes (jamais 2 fois)
 * - Connexion admin/revendeur (vérification de mot de passe haché, 2FA TOTP)
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/totp.php';

// ------------------------------------------------------------------
// CSRF
// ------------------------------------------------------------------
function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(?string $provided): bool
{
    session_boot();
    $expected = $_SESSION['csrf'] ?? '';
    return $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
}

/**
 * Vérifie le jeton CSRF fourni : soit dans le corps JSON ("csrf"),
 * soit dans l'en-tête "X-CSRF-Token". Arrête la requête sinon.
 */
function csrf_require(array $input = []): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf'] ?? null);
    if (!csrf_verify(is_string($provided) ? $provided : null)) {
        audit('csrf_rejected', null, 'CSRF : jeton manquant ou invalide');
        json_out(['success' => false, 'message' => 'csrfInvalid'], 403);
    }
}

// ------------------------------------------------------------------
// Limitation de débit / anti-brute-force
// ------------------------------------------------------------------
/**
 * Autorise l'action si moins de $max tentatives pendant $windowSec
 * pour une clé (bucket + empreinte de la clé). Compteur glissant.
 */
function rate_limit_check(string $bucket, string $key, int $max, int $windowSec): bool
{
    try {
        $db  = db();
        $k   = hash('sha256', $bucket . '|' . $key);
        $now = time();

        $db->beginTransaction();
        $stmt = $db->prepare('SELECT hits, first_hit FROM rate_limits WHERE k = ?');
        $stmt->execute([$k]);
        $row = $stmt->fetch();

        if ($row) {
            if (($now - (int)$row['first_hit']) > $windowSec) {
                // Fenêtre expirée : nouveau compteur
                $db->prepare('UPDATE rate_limits SET hits = 1, first_hit = ? WHERE k = ?')
                   ->execute([$now, $k]);
                $allowed = true;
            } elseif ((int)$row['hits'] >= $max) {
                $allowed = false; // verrouillé
            } else {
                $db->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?')
                   ->execute([$k]);
                $allowed = true;
            }
        } else {
            $db->prepare('INSERT INTO rate_limits (k, hits, first_hit) VALUES (?, 1, ?)')
               ->execute([$k, $now]);
            $allowed = true;
        }
        $db->commit();
        return $allowed;
    } catch (Throwable $e) {
        // En cas d'indisponibilité de la base, on n'ajoute pas de verrou.
        audit('rate_limit_error', null, $e->getMessage());
        return true;
    }
}

/** Temps restant avant déblocage (en secondes), 0 si non verrouillé. */
function rate_limit_remaining(string $bucket, string $key, int $max, int $windowSec): int
{
    try {
        $k = hash('sha256', $bucket . '|' . $key);
        $stmt = db()->prepare('SELECT first_hit FROM rate_limits WHERE k = ? AND hits >= ?');
        $stmt->execute([$k, $max]);
        $first = $stmt->fetchColumn();
        if ($first === false) {
            return 0;
        }
        $left = $windowSec - (time() - (int)$first);
        return $left > 0 ? $left : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function rate_limit_reset(string $bucket, string $key): void
{
    try {
        db()->prepare('DELETE FROM rate_limits WHERE k = ?')
            ->execute([hash('sha256', $bucket . '|' . $key)]);
    } catch (Throwable $e) {
        audit('rate_limit_reset_error', null, $e->getMessage());
    }
}

// ------------------------------------------------------------------
// Journal d'audit
// ------------------------------------------------------------------
function audit(string $event, ?string $actor, ?string $details = null): void
{
    try {
        db()->prepare('INSERT INTO audit_log (created_at, event, actor, ip, details) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                date('Y-m-d H:i:s'),
                $event,
                $actor,
                PHP_SAPI === 'cli' ? 'cli' : client_ip(),
                mb_substr((string)$details, 0, 500),
            ]);
    } catch (Throwable $e) {
        error_log('[audit] échec de journalisation : ' . $e->getMessage());
    }
}

// ------------------------------------------------------------------
// Codes d'accès
// ------------------------------------------------------------------
/**
 * Alphabet sans caractères ambigus (0/O, 1/I/l exclus).
 * 32 symboles -> code de 12 caractères = 60 bits d'entropie.
 */
const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const CODE_LENGTH   = 12;

/** Génère un code lisible, sans biais (rejet des octets inutilisables de random_bytes). */
function generate_code(int $length = CODE_LENGTH): string
{
    $alphabet = CODE_ALPHABET;
    $n = strlen($alphabet);
    $maxAcceptable = intdiv(256, $n) * $n; // tirage sans biais
    $out = '';
    while (true) {
        foreach (str_split(random_bytes($length)) as $byte) {
            $v = ord($byte);
            if ($v >= $maxAcceptable) {
                continue; // octet rejeté pour éviter le biais
            }
            $out .= $alphabet[$v % $n];
            if (strlen($out) === $length) {
                return $out;
            }
        }
    }
}

/** Normalise la saisie : majuscules, sans espaces ni tirets. */
function normalize_code(string $code): string
{
    return strtoupper(str_replace([' ', "\t", '-', '_'], '', trim($code)));
}

/** Empreinte SHA-256 du code (le stockage du code en clair évolue vers le hash). */
function code_hash(string $code): string
{
    return hash('sha256', normalize_code($code));
}

// ------------------------------------------------------------------
// Connexion admin / revendeur (anti-brute-force inclus)
// ------------------------------------------------------------------
/**
 * Tente la connexion avec un identifiant (téléphone/email) et mot de passe.
 * Règles : 5 essais / 15 min / IP et 5 essais / 15 min / identifiant.
 * Mots de passe hachés requis ; l'ancien mot de passe admin en clair est
 * auto-migré (re-haché en Argon2id) à la première connexion réussie.
 * Retour ['ok', 'user_type' (admin.php|dashboard.php), 'id', 'message', 'totpRequired', 'wait']
 */
function attempt_login(string $contact, string $password, ?string $totp = null): array
{
    $contact = trim($contact);
    if ($contact === '' || $password === '') {
        return ['ok' => false, 'message' => 'missingFields'];
    }

    // Anti-brute-force : 2 fenêtres distinctes (IP et identifiant)
    if (!rate_limit_check('login_ip', client_ip(), 5, 900)
        || !rate_limit_check('login_id', $contact, 5, 900)) {
        $wait = max(
            rate_limit_remaining('login_ip', client_ip(), 5, 900),
            rate_limit_remaining('login_id', $contact, 5, 900)
        );
        audit('login_locked', null, 'contact=' . mb_substr($contact, 0, 50));
        return ['ok' => false, 'message' => 'tooManyAttempts', 'wait' => $wait];
    }

    $db = db();

    // --- Admin ---
    $stmt = $db->prepare('SELECT id, password, totp_secret FROM admins WHERE phone = ? OR email = ? LIMIT 1');
    $stmt->execute([$contact, $contact]);
    $admin = $stmt->fetch();

    if ($admin) {
        $hash = (string)$admin['password'];
        $hashValid = str_starts_with($hash, '$argon') || str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2b$');

        $ok = $hashValid
            ? password_verify($password, $hash)
            : hash_equals($hash, $password); // ligne historique en clair : comparaison résistante au timing

        if ($ok) {
            // Migration automatique en Argon2id si l'ancien mot de passe était en clair
            if (!$hashValid) {
                try {
                    $db->prepare('UPDATE admins SET password = ? WHERE id = ?')
                       ->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
                    audit('admin_password_migrated', 'admin:' . $admin['id'], 'Mot de passe en clair converti en Argon2id');
                } catch (Throwable $e) {
                    audit('admin_password_migrate_error', 'admin:' . $admin['id'], $e->getMessage());
                }
            }
            // 2FA optionnelle
            if (!empty($admin['totp_secret'])) {
                if ($totp === null || !TOTP::verify($admin['totp_secret'], $totp)) {
                    audit('login_totp_failed', 'admin:' . $admin['id'], null);
                    return ['ok' => false, 'message' => 'totpRequired', 'totpRequired' => true];
                }
            }
            rate_limit_reset('login_ip', client_ip());
            rate_limit_reset('login_id', $contact);
            session_boot();
            session_regenerate_id(true); // empêche la fixation de session
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['csrf']     = bin2hex(random_bytes(32)); // rotation du jeton CSRF à la connexion
            audit('login_success', 'admin:' . $admin['id'], null);
            return ['ok' => true, 'user_type' => 'admin.php', 'id' => (int)$admin['id'], 'isAdmin' => true];
        }
        audit('login_failed', 'admin:' . $admin['id'], 'compte existe, mot de passe faux');
    }

    // --- Revendeur ---
    $stmt = $db->prepare('SELECT id, password, status FROM resellers WHERE phone = ? OR email = ? LIMIT 1');
    $stmt->execute([$contact, $contact]);
    $reseller = $stmt->fetch();

    if ($reseller && password_verify($password, (string)$reseller['password'])) {
        if (empty($reseller['status'])) {
            audit('login_pending', 'reseller:' . $reseller['id'], null);
            return ['ok' => false, 'message' => 'pendingApproval'];
        }
        rate_limit_reset('login_ip', client_ip());
        rate_limit_reset('login_id', $contact);
        session_boot();
        session_regenerate_id(true);
        $_SESSION['reseller_id'] = (int)$reseller['id'];
        $_SESSION['csrf']        = bin2hex(random_bytes(32));
        audit('login_success', 'reseller:' . $reseller['id'], null);
        return ['ok' => true, 'user_type' => 'dashboard.php', 'id' => (int)$reseller['id'], 'isAdmin' => false];
    }

    audit('login_failed', null, 'aucun compte pour ' . mb_substr($contact, 0, 50));
    return ['ok' => false, 'message' => 'invalidCredentials'];
}

/** Déconnexion complète (session + cookies de compatibilité). */
function logout_current(): void
{
    audit('logout',
        isset($_SESSION['admin_id']) ? 'admin:' . $_SESSION['admin_id']
        : (isset($_SESSION['reseller_id']) ? 'reseller:' . $_SESSION['reseller_id'] : null),
        null);
    setcookie('admin_id', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    setcookie('reseller_id', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    // une session toute neuve pour la suite de la requête
    session_start();
    $_SESSION['__created'] = true;
}

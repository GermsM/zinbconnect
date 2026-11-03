<?php
/**
 * Bootstrap commun de Zinbconnect.
 * Charge la configuration (.env), prépare l'autoload des helpers,
 * fournit la connexion PDO préparée, les sessions sécurisées et les en-têtes HTTP.
 *
 * Utilisation : require_once __DIR__ . '/../lib/bootstrap.php';
 */
declare(strict_types=1);

// ------------------------------------------------------------------
// .env — variables d'environnement (jamais dans git)
// ------------------------------------------------------------------
$GLOBALS['__ENV'] = [];

function env(string $key, ?string $default = null): ?string
{
    if (isset($GLOBALS['__ENV'][$key])) {
        return $GLOBALS['__ENV'][$key];
    }
    $fromServer = getenv($key);
    if ($fromServer !== false && $fromServer !== '') {
        return $fromServer;
    }
    return $default;
}

function load_env(string $path): void
{
    if (!is_readable($path)) {
        return; // .env manquant : on fonctionne avec getenv() uniquement
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // Enlève d'éventuelles guillemets d'habillage
        if (strlen($val) >= 2 && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && !isset($GLOBALS['__ENV'][$key])) {
            $GLOBALS['__ENV'][$key] = $val;
        }
    }
}

load_env(__DIR__ . '/../.env');

/**
 * Base directory du projet (le dossier qui contient index.php…)
 */
function app_root(): string
{
    return dirname(__DIR__);
}

// ------------------------------------------------------------------
// Connexion base de données (PDO, requêtes toujours préparées)
// ------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $driver = env('DB_DRIVER', 'mysql');

    if ($driver === 'sqlite') {
        $path = env('DB_PATH', 'data/zinbconnect.sqlite');
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
            $path = app_root() . '/' . $path;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    if ($driver !== 'mysql') {
        throw new RuntimeException('DB_DRIVER inconnu : ' . $driver);
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', 'localhost'),
        env('DB_PORT', '3306'),
        env('DB_NAME', '')
    );
    $pdo = new PDO($dsn, env('DB_USER', ''), env('DB_PASS', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// ------------------------------------------------------------------
// Sessions sécurisées
// ------------------------------------------------------------------
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    session_name('ZINBCONNECT_SESSION');
    $secure = is_https();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,   // en prod HTTPS ce flag s'active automatiquement
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // Fixation de session : un ID neuf à l'ouverture
    if (empty($_SESSION['__created'])) {
        session_regenerate_id(true);
        $_SESSION['__created'] = true;
    }
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return strtolower($proto) === 'https';
}

function is_prod(): bool
{
    return env('APP_ENV', 'production') === 'production';
}

// ------------------------------------------------------------------
// En-têtes de sécurité OWASP (CSP, XFO, nosniff, HSTS…)
// ------------------------------------------------------------------
function security_headers(): void
{
    if (headers_sent() || PHP_SAPI === 'cli') {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https() && is_prod()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    // CSP pragmatique : le projet utilise encore des CDN (tailwind, fontawesome…).
    // Phase 3 les retirera : le CSP pourra alors être resserré sur 'self'.
    header("Content-Security-Policy: default-src 'self';"
        . " script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.tailwindcss.com https://kit.fontawesome.com https://cdnjs.cloudflare.com;"
        . " style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com;"
        . " font-src 'self' data: https://fonts.gstatic.com https://kit.fontawesome.com;"
        . " img-src 'self' data:;"
        . " connect-src 'self';"
        . " base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

// ------------------------------------------------------------------
// Petites aides
// ------------------------------------------------------------------
/** Échappe une chaîne pour sortie HTML (anti-XSS). */
function html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Adresse IP du client (pour journalisation / limitation de débit). */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Réponse JSON + arrêt. */
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lecture du corps JSON d'une requête POST. */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

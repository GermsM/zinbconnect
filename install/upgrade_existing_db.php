<?php
/**
 * Migration de la base Zinbconnect EXISTANTE (production InfinityFree) vers
 * l'organisation sécurisée de Phase 2, sans casser les données :
 *
 *   1. Sauvegarde SQL complète des tables sensibles dans backups/
 *   2. Colonnes manquantes ajoutées (code_hash, status, revoked_at, totp_secret…)
 *   3. Empreintes (hash) des codes calculées pour les lignes existantes
 *   4. Mot de passe admin converti en clair -> Argon2id
 *   5. Tables rate_limits et audit_log créées
 *
 * Usage : php install/upgrade_existing_db.php [--dry-run]
 * Testable avec --dry-run : affiche les opérations sans exécuter la moindre écriture.
 */
declare(strict_types=1);

// Script d'administration : jamais exécutable depuis le web.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être lancé en ligne de commande.');
}

require_once __DIR__ . '/../lib/security.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
function cli_out(string $m): void { echo $m, PHP_EOL; }
function op(string $m): void { cli_out('  - ' . $m); }

$db      = db();
$driver  = env('DB_DRIVER', 'mysql');
$isMysql = $driver === 'mysql';

cli_out(">>> Migration (driver={$driver})" . ($dryRun ? ' — MODE TEST (aucune écriture)' : ''));

//
// 1) Sauvegarde des tables sensibles
//
$backupDir = app_root() . '/backups';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }
$stamp    = date('Ymd-His');
$backupFile = $backupDir . "/upgrade_{$stamp}.sql";
$fh = fopen($backupFile, 'w');

if ($fh) {
    fwrite($fh, "-- Sauvegarde Zinbconnect avant migration — {$stamp}\n");
    foreach (['admins', 'access_codes', 'current_codes', 'subscriptions', 'transactions', 'resellers'] as $table) {
        try {
            $rows = $db->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            op("table {$table} absente : ignorée");
            continue;
        }
        fwrite($fh, "\n-- {$table} (" . count($rows) . " lignes)\n");
        if ($rows) {
            $cols = array_keys($rows[0]);
            $ins = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (";
            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $db->quote((string)$v);
                }
                fwrite($fh, $ins . implode(',', $vals) . ");\n");
            }
        }
    }
    fclose($fh);
    cli_out(">>> Sauvegarde écrite : backups/upgrade_{$stamp}.sql (" . filesize($backupFile) . ' octets)');
} else {
    cli_out('!!! Sauvegarde impossible (droits dossier). ARRET pour éviter tout risque.');
    exit(1);
}
if ($dryRun) {
    cli_out('>>> Mode test terminé : rien n\'a été modifié.');
    exit(0);
}

//
// 2) Fonction : ajoute une colonne si absente
//
function ensure_column(PDO $db, bool $isMysql, string $table, string $column, string $definitionMysql, string $definitionSqlite, array &$done): void
{
    try {
        if ($isMysql) {
            $q = $db->query("SHOW COLUMNS FROM {$table} LIKE " . $db->quote($column));
            if ($q && $q->fetch()) { $done[$table . '.' . $column] = true; return; }
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definitionMysql}");
        } else {
            $q = $db->query("PRAGMA table_info({$table})");
            $exists = false;
            foreach ($q->fetchAll() as $col) { if (strcasecmp($col['name'], $column) === 0) { $exists = true; } }
            if ($exists) { $done[$table . '.' . $column] = true; return; }
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definitionSqlite}");
        }
        $done[$table . '.' . $column] = true;
        op("colonne ajoutée : {$table}.{$column}");
    } catch (Throwable $e) {
        $done[$table . '.' . $column] = true; // existe déjà ? on continue quoi qu'il arrive
        op("colonne {$table}.{$column} : " . $e->getMessage());
    }
}

$cols = [];
// Stocks et codes actifs
ensure_column($db, $isMysql, 'access_codes', 'code_hash',   'CHAR(64) NULL',         'TEXT NULL',       $cols);
ensure_column($db, $isMysql, 'access_codes', 'status',      "VARCHAR(20) NOT NULL DEFAULT 'available'", "TEXT NOT NULL DEFAULT 'available'", $cols);
ensure_column($db, $isMysql, 'access_codes', 'claimed_at',  'DATETIME NULL',         'TEXT NULL',       $cols);
ensure_column($db, $isMysql, 'current_codes','code_hash',   'CHAR(64) NULL',         'TEXT NULL',       $cols);
ensure_column($db, $isMysql, 'current_codes','revoked_at',  'DATETIME NULL',         'TEXT NULL',       $cols);
// Forfaits à l'heure près
ensure_column($db, $isMysql, 'subscriptions', 'duration_minutes', 'INT NULL',       'INTEGER NULL',    $cols);
// Admin
ensure_column($db, $isMysql, 'admins', 'totp_secret', 'VARCHAR(64) NULL', 'TEXT NULL',        $cols);

// Identifiant interne pour access_codes si absent (requis pour l'activation atomique)
try {
    if ($isMysql) {
        $q = $db->query('SHOW COLUMNS FROM access_codes LIKE ' . $db->quote('id'));
        if (!$q->fetch()) {
            $db->exec('ALTER TABLE access_codes DROP PRIMARY KEY'); // au cas où altri clé naturelle
            $db->exec('ALTER TABLE access_codes ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ADD INDEX idx_stock_hash (code_hash)');
            op('colonne ajoutée : access_codes.id (clé auto-incrémentée)');
        }
    }
} catch (Throwable $e) {
    op('access_codes.id : ' . $e->getMessage());
}

//
// 3) Empreintes (hash) des codes existants
//
try {
    $missing = $db->query(
        "SELECT id, code FROM access_codes WHERE (code_hash IS NULL OR code_hash = '') AND code IS NOT NULL AND code != ''"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($missing) {
        $upd = $db->prepare('UPDATE access_codes SET code_hash = ? WHERE id = ?');
        foreach ($missing as $row) {
            $upd->execute([code_hash((string)$row['code']), $row['id']]);
        }
        op(count($missing) . ' empreintes de codes calculées dans access_codes');
    } else {
        op('access_codes : empreintes déjà à jour');
    }

    $missing2 = $db->query(
        "SELECT id, code FROM current_codes WHERE (code_hash IS NULL OR code_hash = '') AND code IS NOT NULL AND code != ''"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($missing2) {
        $upd = $db->prepare('UPDATE current_codes SET code_hash = ? WHERE id = ?');
        foreach ($missing2 as $row) {
            $upd->execute([code_hash((string)$row['code']), $row['id']]);
        }
        op(count($missing2) . ' empreintes de codes calculées dans current_codes');
    } else {
        op('current_codes : empreintes déjà à jour');
    }
} catch (Throwable $e) {
    op('empreintes : ' . $e->getMessage());
}

try {
    if ($isMysql) {
        $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (k CHAR(64) NOT NULL PRIMARY KEY, hits INT UNSIGNED NOT NULL DEFAULT 0, first_hit INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->exec('CREATE TABLE IF NOT EXISTS audit_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, event VARCHAR(64) NOT NULL, actor VARCHAR(80) NULL, ip VARCHAR(45) NULL, details VARCHAR(500) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } else {
        $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (k TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, first_hit INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, event TEXT NOT NULL, actor TEXT NULL, ip TEXT NULL, details TEXT NULL)');
    }
    op('tables rate_limits et audit_log prêtes');
} catch (Throwable $e) {
    op('tables sécurité : ' . $e->getMessage());
}

//
// 4) Mot de passe admin en clair -> Argon2id
//
try {
    $rows = $db->query('SELECT id, password FROM admins')->fetchAll(PDO::FETCH_ASSOC);
    $nb = 0; $upd = $db->prepare('UPDATE admins SET password = ? WHERE id = ?');
    foreach ($rows as $row) {
        $h = (string)$row['password'];
        if (!str_starts_with($h, '$argon') && !str_starts_with($h, '$2y$') && !str_starts_with($h, '$2b$') && $h !== '') {
            $upd->execute([password_hash($h, PASSWORD_DEFAULT), $row['id']]);
            $nb++;
        }
    }
    op($nb === 0 ? 'admins : mots de passe tous hachés (rien à convertir)' : $nb . ' mot(s) de passe admin maintenant hachés (Argon2id)');
} catch (Throwable $e) {
    op('admins : ' . $e->getMessage());
}

cli_out('>>> Migration terminée. Gardez la sauvegarde backups/upgrade_*.sql hors ligne.');
cli_out('>>> IMPORTANT : garantissez à l\'avenir que la colonne code de access_codes est vidée (code_hash suffit) — la génération de lots (Phase 3) exportera les codes imprimables à la création.');

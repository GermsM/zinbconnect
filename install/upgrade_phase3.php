<?php
/**
 * Zinbconnect — migration Phase 3 (tickets, plafonds, réglages, consommation).
 *
 * À lancer après install/upgrade_existing_db.php (Phase 2) :
 *   php install/upgrade_phase3.php            (applique)
 *   php install/upgrade_phase3.php --dry-run  (affiche, n'écrit rien)
 *
 * Ne détruit aucune donnée :
 *   - ajoute des colonnes et des tables si elles manquent ;
 *   - fixe les plafonds de données des forfaits existants (une seule fois) ;
 *   - recopie les anciens codes actifs (current_codes) dans la nouvelle table
 *     tickets comme historique (statut calculé, code ré-chiffré si APP_KEY).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être lancé en ligne de commande.');
}

require_once __DIR__ . '/../lib/tickets.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
function out(string $m): void { echo $m, PHP_EOL; }
function step(string $m): void { out('  - ' . $m); }

$db     = db();
$driver = env('DB_DRIVER', 'mysql');
$isMysql = $driver === 'mysql';

out('>>> Migration Phase 3 (driver=' . $driver . ')' . ($dryRun ? ' — MODE TEST' : ''));

// ------------------------------------------------------------------
// 1) Sauvegarde préalable (obligatoire)
// ------------------------------------------------------------------
$backupDir = app_root() . '/backups';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }
$stamp = date('Ymd-His');

if ($dryRun) {
    step('mode test : aucune sauvegarde écrite');
} else {
    require_once __DIR__ . '/../lib/backup.php';
    $sql = db_dump_sql();
    $file = $backupDir . "/phase3_{$stamp}.sql";
    if (file_put_contents($file, $sql) === false) {
        out('!!! Sauvegarde impossible (droits dossier). ARRÊT.');
        exit(1);
    }
    step('sauvegarde : backups/phase3_' . $stamp . '.sql (' . strlen($sql) . ' octets)');
}

// ------------------------------------------------------------------
// 2) Colonnes
// ------------------------------------------------------------------
function add_column(PDO $db, bool $isMysql, string $table, string $column, string $mysqlDef, string $sqliteDef, bool $dry): void
{
    try {
        if ($isMysql) {
            $q = $db->query("SHOW COLUMNS FROM {$table} LIKE " . $db->quote($column));
            if ($q && $q->fetch()) { return; }
        } else {
            $q = $db->query("PRAGMA table_info({$table})");
            foreach ($q->fetchAll() as $c) { if (strcasecmp($c['name'], $column) === 0) { return; } }
        }
        step("colonne {$table}.{$column} : à ajouter" . ($dry ? ' (test)' : ''));
        if (!$dry) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} " . ($isMysql ? $mysqlDef : $sqliteDef));
            step("colonne {$table}.{$column} ajoutée");
        }
    } catch (Throwable $e) {
        step("colonne {$table}.{$column} : " . $e->getMessage());
    }
}

add_column($db, $isMysql, 'subscriptions', 'data_limit_bytes', 'BIGINT NULL', 'INTEGER NULL', $dryRun);
add_column($db, $isMysql, 'transactions', 'ticket_id', 'INT NULL', 'INTEGER NULL', $dryRun);

// ------------------------------------------------------------------
// 3) Nouvelles tables
// ------------------------------------------------------------------
$tables = $isMysql ? [
    "CREATE TABLE IF NOT EXISTS batches (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      reference VARCHAR(32) NOT NULL UNIQUE,
      subscription_id INT NULL,
      quantity INT UNSIGNED NOT NULL DEFAULT 0,
      unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_by VARCHAR(80) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS tickets (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      batch_id INT NULL, subscription_id INT NULL,
      code_hash CHAR(64) NOT NULL UNIQUE, code_enc TEXT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'available',
      price DECIMAL(12,2) NOT NULL DEFAULT 0,
      data_limit_bytes BIGINT NULL, bytes_used BIGINT NOT NULL DEFAULT 0,
      contact VARCHAR(120) NULL, contact_type VARCHAR(10) NULL,
      created_at DATETIME NOT NULL, activated_at DATETIME NULL, expires_at DATETIME NULL,
      revoked_at DATETIME NULL, last_sync_at DATETIME NULL, reseller_id INT NULL, note VARCHAR(200) NULL,
      KEY idx_ticket_status (status), KEY idx_ticket_expires (expires_at),
      KEY idx_ticket_contact (contact), KEY idx_ticket_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS usage_samples (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      sample_date DATE NOT NULL, bytes_down BIGINT NOT NULL DEFAULT 0,
      bytes_up BIGINT NOT NULL DEFAULT 0, source VARCHAR(20) NOT NULL DEFAULT 'router',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY idx_usage_day (sample_date, source)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] : [
    "CREATE TABLE IF NOT EXISTS batches (
      id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT NOT NULL UNIQUE,
      subscription_id INTEGER NULL, quantity INTEGER NOT NULL DEFAULT 0,
      unit_price REAL NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_by TEXT NULL)",
    "CREATE TABLE IF NOT EXISTS tickets (
      id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER NULL, subscription_id INTEGER NULL,
      code_hash TEXT NOT NULL UNIQUE, code_enc TEXT NULL,
      status TEXT NOT NULL DEFAULT 'available', price REAL NOT NULL DEFAULT 0,
      data_limit_bytes INTEGER NULL, bytes_used INTEGER NOT NULL DEFAULT 0,
      contact TEXT NULL, contact_type TEXT NULL, created_at TEXT NOT NULL,
      activated_at TEXT NULL, expires_at TEXT NULL, revoked_at TEXT NULL,
      last_sync_at TEXT NULL, reseller_id INTEGER NULL, note TEXT NULL)",
    "CREATE INDEX IF NOT EXISTS idx_ticket_status ON tickets(status)",
    "CREATE INDEX IF NOT EXISTS idx_ticket_expires ON tickets(expires_at)",
    "CREATE INDEX IF NOT EXISTS idx_ticket_contact ON tickets(contact)",
    "CREATE INDEX IF NOT EXISTS idx_ticket_batch ON tickets(batch_id)",
    "CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NULL)",
    "CREATE TABLE IF NOT EXISTS usage_samples (
      id INTEGER PRIMARY KEY AUTOINCREMENT, sample_date TEXT NOT NULL,
      bytes_down INTEGER NOT NULL DEFAULT 0, bytes_up INTEGER NOT NULL DEFAULT 0,
      source TEXT NOT NULL DEFAULT 'router', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (sample_date, source))",
];

foreach ($tables as $sql) {
    if (!$dryRun) {
        try { $db->exec($sql); } catch (Throwable $e) { step('table : ' . $e->getMessage()); }
    }
}
step($dryRun ? 'tables : seraient créées si absentes' : 'tables batches, tickets, settings, usage_samples prêtes');

// ------------------------------------------------------------------
// 4) Plafonds par forfait (valeurs Kitutu demandées)
// ------------------------------------------------------------------
$defaults = [
    // [minutes, jours, octets]
    [60, 0, 100 * 1024 * 1024],   // 1 h  -> 100 Mo
    [720, 0, 300 * 1024 * 1024],  // 12 h -> 300 Mo
    [1440, 0, 500 * 1024 * 1024], // 24 h -> 500 Mo
    [0, 3, 1024 * 1024 * 1024],   // 3 j  -> 1 Go
];

try {
    $plans = $db->query('SELECT id, name, duration_days, duration_minutes, data_limit_bytes FROM subscriptions')->fetchAll();
    foreach ($plans as $p) {
        if (!empty($p['data_limit_bytes'])) {
            continue;
        }
        $limit = null;
        foreach ($defaults as [$min, $days, $bytes]) {
            $m = (int)($p['duration_minutes'] ?? 0);
            $d = (int)($p['duration_days'] ?? 0);
            if (($m > 0 && $m === (int)$min) || ($m === 0 && $d === (int)$days && $min === 0)) {
                $limit = $bytes;
                break;
            }
        }
        if ($limit !== null) {
            step("forfait « {$p['name']} » : plafond " . format_bytes($limit) . ($dryRun ? ' (test)' : ''));
            if (!$dryRun) {
                $db->prepare('UPDATE subscriptions SET data_limit_bytes = ? WHERE id = ?')->execute([$limit, $p['id']]);
            }
        }
    }
} catch (Throwable $e) {
    step('plafonds : ' . $e->getMessage());
}

// ------------------------------------------------------------------
// 5) Réglages par défaut
// ------------------------------------------------------------------
if (!$dryRun) {
    settings_seed();
}
step('réglages : quota Starlink 500 Gio, devise FC, commission 25 %');

// ------------------------------------------------------------------
// 6) Import de l'historique current_codes -> tickets
// ------------------------------------------------------------------
try {
    $rows = $db->query('SELECT * FROM current_codes')->fetchAll();
    $ins  = $db->prepare(
        'INSERT INTO tickets (subscription_id, code_hash, code_enc, status, price, data_limit_bytes,
                              contact, contact_type, created_at, activated_at, expires_at, revoked_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $chk = $db->prepare('SELECT 1 FROM tickets WHERE code_hash = ?');
    $imported = 0;
    $skipped  = 0;

    foreach ($rows as $r) {
        $hash = !empty($r['code_hash']) ? (string)$r['code_hash']
            : (!empty($r['code']) ? code_hash((string)$r['code']) : null);
        if ($hash === null) { $skipped++; continue; }

        $chk->execute([$hash]);
        if ($chk->fetchColumn()) { $skipped++; continue; }

        $plan = $r['subscription_id'] ? plan_by_id((int)$r['subscription_id']) : null;
        $expired = !empty($r['expiration_date']) && strtotime((string)$r['expiration_date']) <= time();
        $status  = !empty($r['revoked_at']) ? 'revoked' : ($expired ? 'active' : 'active');

        if (!$dryRun) {
            $ins->execute([
                $r['subscription_id'] ?? null,
                $hash,
                !empty($r['code']) ? code_encrypt((string)$r['code']) : null,
                $status,
                $plan ? (float)$plan['rate'] : 0,
                $plan ? plan_data_limit($plan) : null,
                $r['contact'] ?? null,
                $r['contact_type'] ?? null,
                $r['created_at'] ?: date('Y-m-d H:i:s'),
                $r['created_at'] ?? null,
                $r['expiration_date'] ?? null,
                $r['revoked_at'] ?? null,
            ]);
        }
        $imported++;
    }
    step(($dryRun ? 'importerait ' : 'importés ') . $imported . ' ancien(s) code(s) dans tickets' . ($skipped ? ", {$skipped} ignoré(s)" : ''));
} catch (Throwable $e) {
    step('import historique : ' . $e->getMessage());
}

out('>>> Migration Phase 3 terminée.');

<?php
/**
 * Installation d'une base Zinbconnect vierge (MySQL ou SQLite selon .env).
 *
 * Usage :
 *   php install/init_db.php            -> crée les tables, ensemle les 4 forfaits
 *   php install/init_db.php --demo     -> en plus : compte admin de démo + 10 codes de test
 *
 * On ne crée JAMAIS la base si le fichier de base SQLite existe déjà (on le sauvegarde).
 */
declare(strict_types=1);

// Script d'installation : jamais exécutable depuis le web.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être lancé en ligne de commande.');
}

$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? 'cli';
require_once __DIR__ . '/../lib/security.php';

function cli_out(string $msg): void { echo $msg, PHP_EOL; }

$db = db();
$driver = env('DB_DRIVER', 'mysql');
cli_out(">>> Driver : {$driver}");

// ------------------------------------------------------------------
// 1) Sauvegarde avant tout (SQLite direct ; sur MySQL l'hébergeur exporte
//    via phpMyAdmin : ce script invoque ici seulement la sauvegarde locale)
// ------------------------------------------------------------------
if ($driver === 'sqlite') {
    $path = env('DB_PATH', 'data/zinbconnect.sqlite');
    $full = (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path))
        ? $path : app_root() . '/' . $path;
    if (is_file($full)) {
        $stamp = date('Ymd-His');
        $backupDir = app_root() . '/backups';
        if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }
        copy($full, $backupDir . "/pre_init_{$stamp}.sqlite");
        cli_out(">>> Sauvegarde : backups/pre_init_{$stamp}.sqlite");
    }
}

// ------------------------------------------------------------------
// 2) Schéma
// ------------------------------------------------------------------
$schemaFile = $driver === 'sqlite' ? __DIR__ . '/schema.sqlite.sql' : __DIR__ . '/schema.mysql.sql';
$sql = file_get_contents($schemaFile);
if ($driver === 'sqlite') {
    $db->exec($sql);
} else {
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') { $db->exec($stmt); }
    }
}
cli_out('>>> Tables créées (si absentes).');

// ------------------------------------------------------------------
// 3) Forfaits par défaut (4 tarifs de Kitutu)
// ------------------------------------------------------------------
$defaults = [
    ['1 h — 1 000 FC',  1000, 0, 60    ],
    ['12 h — 1 500 FC', 1500, 0, 720   ],
    ['24 h — 2 500 FC', 2500, 0, 1440  ],
    ['3 jours — 5 000 FC', 5000, 3, 0 ],
];
$stmt = $db->prepare('SELECT COUNT(*) c FROM subscriptions');
$stmt->execute();
if ((int)$stmt->fetch()['c'] === 0) {
    $ins = $db->prepare('INSERT INTO subscriptions (name, rate, duration_days, duration_minutes) VALUES (?,?,?,?)');
    foreach ($defaults as $d) { $ins->execute([$d[0], $d[1], $d[2], $d[3]]); }
    cli_out('>>> Forfaits par défaut insérés : ' . count($defaults));
} else {
    cli_out('>>> Forfaits existants : aucun changement.');
}

// ------------------------------------------------------------------
// 4) Compte administrateur (variables ADMIN_IDENTIFIER / ADMIN_PASSWORD du .env)
// ------------------------------------------------------------------
$adminId  = env('ADMIN_IDENTIFIER');
$adminPw  = env('ADMIN_PASSWORD');
if ($adminId && $adminPw && is_string($adminId) && is_string($adminPw)) {
    $stmt = $db->prepare('SELECT id FROM admins LIMIT 1');
    $stmt->execute();
    if (!$stmt->fetch()) {
        // is phone? XXXXXX sinon email
        $isPhone = (bool)preg_match('/^[+0-9]{7,16}$/', $adminId);
        $db->prepare('INSERT INTO admins (phone, email, password) VALUES (?,?,?)')
           ->execute([
               $isPhone ? $adminId : null,
               $isPhone ? null : $adminId,
               password_hash($adminPw, PASSWORD_DEFAULT),   // haché Argon2id/bcrypt, jamais en clair
           ]);
        cli_out('>>> Administrateur créé (mot de passe haché).');
    } else {
        cli_out('>>> Administrateur déjà présent : ignoré.');
    }
} else {
    cli_out('>>> ADMIN_IDENTIFIER / ADMIN_PASSWORD absents du .env : pas de compte admin créé.');
}

// ------------------------------------------------------------------
// 5) Mode démo : compte admin démonstration + codes de test
// ------------------------------------------------------------------
if (in_array('--demo', $argv ?? [], true)) {
    $stmt = $db->prepare('SELECT COUNT(*) c FROM access_codes');
    $stmt->execute();
    if ((int)$stmt->fetch()['c'] === 0) {
        // 10 codes de test, exportés dans un CSV (c'est ce lot que l'admin imprimerait)
        $csv  = "code,forfait\n";
        $ins  = $db->prepare("INSERT INTO access_codes (code, code_hash, status, subscription_id) VALUES (?,?, 'available', NULL)");
        foreach (range(1, 10) as $i) {
            $code = generate_code();
            $ins->execute([$code, code_hash($code)]);
            $csv .= $code . ",\n";
        }
        $backupDir = app_root() . '/backups';
        if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }
        file_put_contents($backupDir . '/demo_codes_' . date('Ymd-His') . '.csv', $csv);
        cli_out('>>> 10 codes de test en stock ; liste exportée dans backups/ (à conserver hors ligne).');
    } else {
        cli_out('>>> Stock déjà rempli : codes de test non ajoutés.');
    }
}

cli_out('>>> Fin init_db.php. (Base : ' . env('DB_NAME', env('DB_PATH', '')) . ')');

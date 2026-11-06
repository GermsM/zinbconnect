<?php
/**
 * Zinbconnect — Suite de tests automatisés (Phase 4).
 *
 * Exécution : php tests/test_suite.php
 * Ne nécessite aucune dépendance externe (PHP standard avec sqlite + openssl).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/tickets.php';
require_once __DIR__ . '/../lib/routeros.php';
require_once __DIR__ . '/../lib/crypto.php';
require_once __DIR__ . '/../lib/settings.php';

// Création d'une base de test SQLite isolée en mémoire
$testPdo = new PDO('sqlite::memory:');
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Initialisation du schéma dans la base de test
$schemaSql = file_get_contents(__DIR__ . '/../install/schema.sqlite.sql');
$testPdo->exec($schemaSql);

// Remplace la fonction db() par réflexion ou injecte la connexion
// Dans Zinbconnect, db() utilise un PDO statique : configurons un DB_PATH temporaire ou testons via la connexion
$testDbFile = __DIR__ . '/test_run.sqlite';
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}
$db = new PDO('sqlite:' . $testDbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec($schemaSql);

// Données de base pour les tests
$db->exec("INSERT INTO subscriptions (id, name, rate, duration_minutes, duration_days, data_limit_bytes) VALUES
    (1, '1 heure', 1500, 60, NULL, 104857600),
    (2, '24 heures', 2500, 1440, NULL, 524288000),
    (3, '3 jours', 5000, NULL, 3, 1073741824)");

$db->exec("INSERT INTO resellers (id, name, phone, email, password, status) VALUES
    (1, 'Revendeur Test', '+243990000001', 'revendeur@test.local', '" . password_hash('Secret2026!', PASSWORD_DEFAULT) . "', 1)");

$GLOBALS['__ENV']['DB_DRIVER'] = 'sqlite';
$GLOBALS['__ENV']['DB_PATH'] = 'tests/test_run.sqlite';
$GLOBALS['__ENV']['APP_KEY'] = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function it(string $description, callable $fn): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    try {
        $fn();
        $passedTests++;
        echo "  [PASS] $description\n";
    } catch (Throwable $e) {
        $failedTests++;
        echo "  [FAIL] $description : " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")\n";
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new RuntimeException($msg);
}

function assert_equals(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($msg ?: "Attendu: " . var_export($expected, true) . ", Obtenu: " . var_export($actual, true));
    }
}

echo "=== Zinbconnect — Démarrage de la suite de tests (Phase 4) ===\n\n";

// -------------------------------------------------------------
// 1. GÉNÉRATEUR CRYPTOGRAPHIQUE & CODES
// -------------------------------------------------------------
echo "[1] Générateur cryptographique et codes d'accès :\n";

it('Longueur et alphabet sans caractères ambigus (0, O, 1, I, l exclus)', function () {
    $code = generate_code();
    assert_equals(12, strlen($code), 'Le code doit comporter 12 caractères');
    assert_true(preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{12}$/', $code) === 1, 'Caractères invalides ou ambigus détectés');
});

it('Unicité et absence de collision sur 500 tirages consécutifs', function () {
    $set = [];
    for ($i = 0; $i < 500; $i++) {
        $c = generate_code();
        if (isset($set[$c])) {
            throw new RuntimeException("Collision détectée sur le code: $c");
        }
        $set[$c] = true;
    }
    assert_equals(500, count($set), 'Tous les 500 codes doivent être strictement uniques');
});

it('Normalisation insensible à la casse, tirets et espaces', function () {
    $raw = " ab-cd_ef 23-45 ";
    $norm = normalize_code($raw);
    assert_equals('ABCDEF2345', $norm);
    assert_equals(code_hash($raw), code_hash($norm));
});

// -------------------------------------------------------------
// 2. GESTION DES LOTS & TICKETS DE TEST GRATUITS
// -------------------------------------------------------------
echo "\n[2] Génération de lots et tickets de TEST (Phase 2.9) :\n";

it('Génération d\'un lot commercial standard (prix entier FC)', function () {
    $batch = generate_batch(1, 10, null, 'admin:test', 'Lot marché');
    assert_true($batch['ok']);
    assert_true(str_starts_with($batch['reference'], 'LOT-'));
    assert_equals(10, count($batch['codes']));
    assert_equals(1500.0, (float)$batch['unit_price']);
});

it('Génération d\'un lot d\'essai gratuit TEST (30 personnes, 0 FC)', function () {
    $batchTest = generate_batch(2, 30, null, 'admin:test', 'Essai village', true);
    assert_true($batchTest['ok']);
    assert_true(str_starts_with($batchTest['reference'], 'TEST-'));
    assert_equals(30, count($batchTest['codes']));
    assert_equals(0.0, (float)$batchTest['unit_price']);

    // Vérifie que les tickets du lot sont à 0 FC et marqués essai gratuit
    $tickets = batch_tickets($batchTest['batch_id']);
    assert_equals(30, count($tickets));
    assert_equals(0.0, (float)$tickets[0]['price']);
    assert_true(str_contains((string)$tickets[0]['note'], '[ESSAI GRATUIT]'));
});

// -------------------------------------------------------------
// 3. ACTIVATION, ATOMICITÉ & CONCURRENCE
// -------------------------------------------------------------
echo "\n[3] Cycle de vie des tickets et concurrence :\n";

it('Activation normale d\'un ticket disponible', function () {
    $b = generate_batch(1, 1, 1500, 'admin:test');
    $code = $b['codes'][0];

    $act = activate_ticket($code, '+243999111222');
    assert_true($act['ok']);
    assert_equals('active', $act['ticket']['status']);
    assert_true(!empty($act['expires_at']));
});

it('Concurrence : double activation simultanée du même code (verrou atomique)', function () {
    $b = generate_batch(1, 1, 1500, 'admin:test');
    $code = $b['codes'][0];

    $act1 = activate_ticket($code);
    assert_true($act1['ok'], 'Première activation réussie');

    $act2 = activate_ticket($code);
    assert_true(!$act2['ok'], 'Deuxième activation doit échouer');
    assert_equals('alreadyUsed', $act2['error']);
});

it('Vente par revendeur sans démarrer le temps du client', function () {
    $b = generate_batch(1, 1, 1500, 'admin:test');
    $code = $b['codes'][0];

    $sell = sell_ticket($code, '+243988777666', 1);
    assert_true($sell['ok']);

    $t = ticket_by_code($code);
    assert_equals('sold', $t['status']);
    assert_equals(null, $t['activated_at'], 'La vente ne doit pas démarrer le compte à rebours');
    assert_equals(null, $t['expires_at']);
    assert_equals('available', ticket_state($t), 'Le ticket reste disponible pour le premier login');

    // Le client se connecte ensuite sur le portail Wi-Fi
    $act = activate_ticket($code);
    assert_true($act['ok']);
    $tActive = ticket_by_code($code);
    assert_equals('active', $tActive['status']);
    assert_true(!empty($tActive['activated_at']));
});

it('Les tickets de TEST gratuits n\'impactent pas les ventes financières', function () {
    $salesBefore = sales_stats();

    $bTest = generate_batch(1, 5, 0, 'admin:test', 'Test impact', true);
    foreach ($bTest['codes'] as $c) {
        $act = activate_ticket($c);
        assert_true($act['ok']);
    }

    $salesAfter = sales_stats();
    assert_equals($salesBefore['today']['total'], $salesAfter['today']['total'], 'Le montant total des ventes ne doit pas bouger');
    assert_equals($salesBefore['today']['count'], $salesAfter['today']['count'], 'Le nombre de ventes commerciales ne doit pas augmenter');
});

// -------------------------------------------------------------
// 4. RÉVOCATION
// -------------------------------------------------------------
echo "\n[4] Révocation de tickets :\n";

it('Révocation d\'un ticket actif', function () {
    $b = generate_batch(1, 1, 1500, 'admin:test');
    $code = $b['codes'][0];
    activate_ticket($code);

    $revOk = revoke_ticket($code, 'admin:test');
    assert_true($revOk);

    $t = ticket_by_code($code);
    assert_equals('revoked', ticket_state($t));

    $actAgain = activate_ticket($code);
    assert_true(!$actAgain['ok']);
    assert_equals('revoked', $actAgain['error']);
});

// -------------------------------------------------------------
// 5. SYNTAXE & SÉCURITÉ ROUTEROS 7
// -------------------------------------------------------------
echo "\n[5] Scripts RouterOS 7 et sécurité hotspot :\n";

it('Génération de script .rsc valide RouterOS 7 avec profil explicite et quote sans injection', function () {
    $b = generate_batch(1, 2, 1500, 'admin:test');
    $rows = batch_tickets($b['batch_id']);
    $rsc = routeros_hotspot_import($rows, $b['reference']);

    assert_true(str_contains($rsc, 'profile="code-usable"'), 'Profil code-usable requis');
    assert_true(str_contains($rsc, 'limit-uptime="1h"'), 'limit-uptime présent');
    assert_true(str_contains($rsc, 'limit-bytes-total=104857600'), 'limit-bytes-total présent');
    assert_true(str_contains($rsc, ':do { /ip hotspot user add'), 'on-error anti-doublon présent');
    assert_true(str_contains($rsc, 'exp=0000000000 w=00003600 u='), 'Format comment exp/w/u présent');
});

it('Re-import d\'un lot ne crée pas de doublon et retire les codes révoqués', function () {
    $b = generate_batch(1, 2, 1500, 'admin:test');
    $rows1 = batch_tickets($b['batch_id']);
    $rsc1 = routeros_hotspot_import($rows1, $b['reference']);
    assert_true(str_contains($rsc1, $b['codes'][0]));
    assert_true(str_contains($rsc1, $b['codes'][1]));

    // Révocation d'un des deux codes
    revoke_ticket($b['codes'][0], 'admin:test');
    $rows2 = batch_tickets($b['batch_id']);
    $rsc2 = routeros_hotspot_import($rows2, $b['reference']);

    // Le code révoqué doit avoir une commande remove
    assert_true(str_contains($rsc2, '/ip hotspot user remove [ find name="' . $b['codes'][0] . '" ]'));
    // L'autre code reste en add (avec on-error)
    assert_true(str_contains($rsc2, '/ip hotspot user add name="' . $b['codes'][1] . '"'));
});

it('Parade Reset/Restauration du routeur (export_consumed_routeros_rsc)', function () {
    $rscPurge = export_consumed_routeros_rsc();
    assert_true(str_contains($rscPurge, '/ip hotspot user remove'));
    assert_true(str_contains($rscPurge, 'codes consommes ou expires purges du hotspot'));
});

// -------------------------------------------------------------
// 6. IMPORT DE L'USAGE DU ROUTEUR & MOYENNES
// -------------------------------------------------------------
echo "\n[6] Import de consommation routeur et statistiques (Phase 2.11) :\n";

it('Parser de durée RouterOS (routeros_parse_duration)', function () {
    assert_equals(0, routeros_parse_duration('0s'));
    assert_equals(45, routeros_parse_duration('45s'));
    assert_equals(750, routeros_parse_duration('12m30s'));
    assert_equals(8100, routeros_parse_duration('2h15m'));
    assert_equals(6135, routeros_parse_duration('01:42:15'));
});

it('Import du fichier CSV généré par export-usage.rsc', function () {
    $b = generate_batch(1, 2, 1500, 'admin:test');
    $c1 = $b['codes'][0];
    $c2 = $b['codes'][1];

    $csv = "#WAN_RX=1073741824,WAN_TX=536870912\r\n" .
           "code,uptime,bytes_in,bytes_out,bytes_total,comment\r\n" .
           "$c1,45m,50000000,10000000,60000000,\"exp=0000000000 w=00003600 {$b['reference']}\"\r\n" .
           "$c2,1h15m,90000000,20000000,110000000,\"exp=0000000000 w=00003600 {$b['reference']}\"\r\n";

    $res = import_usage_csv($csv, 'admin:test');
    assert_true($res['ok']);
    assert_equals(2, $res['matched']);
    assert_equals(2, $res['updated']);
    assert_equals(1073741824, $res['wan_rx']);
    assert_equals(536870912, $res['wan_tx']);

    // Vérifie que le ticket ayant dépassé le quota (100 Mo = 104857600) est passé en 'used'
    $t2 = ticket_by_code($c2);
    assert_equals('used', $t2['status'], 'Le ticket ayant dépassé 104 Mo doit être marqué used');

    // Vérifie le calcul des moyennes
    assert_true(count($res['stats']) >= 1);
    assert_equals(1, $res['stats'][0]['plan_id']);
    assert_equals(85000000, $res['stats'][0]['avg_bytes']); // (60M + 110M)/2 = 85M
});

// -------------------------------------------------------------
// 7. SÉCURITÉ APPLICATIVE & GESTION DES LOGS
// -------------------------------------------------------------
echo "\n[7] Sécurité applicative, hachage, chiffrement et rétention des données :\n";

it('Chiffrement réversible AES-256-GCM des codes (lib/crypto.php)', function () {
    $code = 'ABCD2345WXYZ';
    $enc = code_encrypt($code);
    assert_true($enc !== null);
    assert_true(str_starts_with($enc, 'v1.'));
    assert_equals($code, code_decrypt($enc));
});

it('Protection contre la modification du jeton chiffré (GCM authentication tag)', function () {
    $code = 'ABCD2345WXYZ';
    $enc = code_encrypt($code);
    $corrupted = substr($enc, 0, -4) . 'AAAA';
    assert_equals(null, code_decrypt($corrupted), 'Un tag altéré doit refuser le déchiffrement');
});

it('Purge automatique de rétention des logs (prune_old_logs)', function () {
    db()->exec("INSERT INTO audit_log (created_at, event, actor, ip, details) VALUES ('2020-01-01 00:00:00', 'old_event', 'test', '127.0.0.1', 'test')");
    $deleted = prune_old_logs(30);
    assert_true($deleted >= 1, 'Les logs vieux de plus de 30 jours doivent être supprimés');
});

// Nettoyage de la base de test
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}

echo "\n============================================================\n";
echo "RÉSULTATS : $passedTests/$totalTests tests passés avec succès.\n";
if ($failedTests > 0) {
    echo "ÉCHEC : $failedTests test(s) ont échoué.\n";
    exit(1);
} else {
    echo "FÉLICITATIONS : Tous les tests sont au vert !\n";
    exit(0);
}

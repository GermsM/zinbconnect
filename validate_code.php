<?php
/**
 * Zinbconnect — Vérification d'un code d'accès (point d'entrée conservé pour
 * compatibilité ; l'entrée canonique est l'action "validate_code" d'index.php).
 * Lecture seule : un code de stock (access_codes) ne peut PAS s'activer ici.
 * Protégé comme le portail : CSRF + anti-brute-force (20 essais / 5 min / IP).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';

security_headers();
session_boot();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'invalidMethod'], 405);
}

$input = json_input();
if ($input === []) { // compat : le corps peut aussi être application/x-www-form-urlencoded
    $input = $_POST;
}
csrf_require($input);

$code = normalize_code((string)($input['code'] ?? $input['accessCode'] ?? ''));
if ($code === '') {
    json_out(['valid' => false, 'reason' => 'invalid']);
}

if (!rate_limit_check('code_entry', client_ip(), 20, 300)) {
    audit('code_entry_ratelimited', null, 'ip=' . client_ip());
    json_out(['valid' => false, 'reason' => 'rateLimited']);
}

$result = check_code_public($code);
if ($result['valid']) {
    $_SESSION['access_code'] = $code;
    $_SESSION['code_expiry'] = $result['expiration_date'];
    audit('code_validated', null, 'hash=' . substr(code_hash($code), 0, 8) . '…');
}
json_out($result);

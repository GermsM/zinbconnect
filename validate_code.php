<?php
/**
 * Zinbconnect — validation d'un code depuis le portail.
 *
 * - un code encore « available » est activé ici (première utilisation) ;
 * - un code « active » renvoie simplement le temps et les données restants ;
 * - expiré / révoqué / quota atteint : refus avec un motif clair.
 *
 * Anti-brute-force : 20 essais / 5 min par IP, plus une pause progressive
 * par code (le code lui-même n'est pas un secret assez long pour être deviné).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/tickets.php';

security_headers();
session_boot();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['valid' => false, 'reason' => 'invalidMethod'], 405);
}

// Le VPS est le plan de gestion, pas le contrôleur du trafic Wi-Fi. Tant qu'un
// connecteur RouterOS authentifié n'est pas installé, une validation PHP seule
// pourrait consommer un ticket sans débloquer le client. Le portail réel est
// donc login.html, servi par le hotspot MikroTik.
if (strtolower((string)env('PORTAL_AUTHORITY', 'routeros')) !== 'php') {
    json_out(['valid' => false, 'reason' => 'routerosPortal'], 409);
}

$input = json_input();
if ($input === []) {
    $input = $_POST;
}
csrf_require($input);

$code = normalize_code((string)($input['code'] ?? $input['accessCode'] ?? ''));
if ($code === '') {
    json_out(['valid' => false, 'reason' => 'invalid']);
}

// Limite par IP (le portail est public, derrière le hotspot).
if (!rate_limit_check('code_entry', client_ip(), 20, 300)) {
    audit('code_entry_ratelimited', null, 'ip=' . client_ip());
    json_out(['valid' => false, 'reason' => 'rateLimited']);
}
// Limite par code : 8 essais / 10 min (protection contre l'acharnement ciblé).
if (!rate_limit_check('code_guess', $code, 8, 600)) {
    audit('code_guess_ratelimited', null, 'hash=' . substr(code_hash($code), 0, 8) . '…');
    json_out(['valid' => false, 'reason' => 'rateLimited']);
}

$ticket = ticket_by_code($code);
if (!$ticket) {
    audit('code_invalid', null, 'ip=' . client_ip());
    json_out(['valid' => false, 'reason' => 'invalid']);
}

$state = ticket_state($ticket);

if ($state === 'available') {
    $activation = activate_ticket($code, null, null);
    if (!$activation['ok']) {
        // Course avec une autre requête : on relit l'état réel.
        $ticket = ticket_by_code($code);
        $state  = $ticket ? ticket_state($ticket) : 'invalid';
    } else {
        $ticket = $activation['ticket'];
        $state  = 'active';
    }
}

if ($state !== 'active') {
    $reason = match ($state) {
        'expired' => 'expired',
        'revoked' => 'revoked',
        'used'    => 'used',
        default   => 'invalid',
    };
    json_out([
        'valid'  => false,
        'reason' => $reason,
        'expires_at' => $ticket['expires_at'] ?? null,
    ]);
}

$plan = plan_by_id((int)$ticket['subscription_id']);
$remaining = ticket_remaining_seconds($ticket);

$_SESSION['access_code'] = $code;
$_SESSION['code_expiry'] = $ticket['expires_at'];
audit('code_validated', null, 'ticket=' . $ticket['id']);

// Le client ne voit que sa durée : aucun volume de données dans la réponse.
json_out([
    'valid'             => true,
    'plan'              => $plan ? plan_title($plan) : '',
    'expires_at'        => $ticket['expires_at'],
    'expires_at_fr'     => date('d/m/Y à H:i', strtotime((string)$ticket['expires_at'])),
    'remaining_seconds' => $remaining,
]);

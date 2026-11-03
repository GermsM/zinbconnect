<?php
/**
 * Zinbconnect — Achats en ligne : DÉSACTIVÉS (faille critique corrigée).
 *
 * Dans la version d'origine, n'importe quel visiteur anonyme pouvait obtenir un
 * code réel sans paiement en appelant ce script. La vente se fait maintenant :
 *   - auprès d'un revendeur (dashboard.php, session + CSRF) ;
 *   - ou par la console admin (admin.php, phase 3 : lots imprimables).
 * Toute demande ici est refusée et enregistrée dans le journal d'audit.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';

security_headers();
session_boot();

audit('free_code_attempt', null, 'endpoint=purchase_subscription.php ip=' . client_ip());

if (($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null)) !== null) {
    csrf_require();  // jeton présent mais invalide : log supplémentaire
}

json_out(['success' => false, 'message' => 'purchaseDisabled'], 410);

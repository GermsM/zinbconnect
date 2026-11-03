<?php
/**
 * 2FA TOTP optionnelle pour l'administrateur.
 *
 * Usage :
 *   php install/totp_setup.php <téléphone-ou-email-de-l'admin>
 *
 * Génère un secret, affiche l'URI otpauth:// (à transformer en QR via n'importe
 * quel générateur) et enregistre le secret pour la connexion. Tant que le compte
 * n'a pas de totp_secret, la 2FA n'est pas active.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/security.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Exécutable uniquement en ligne de commande.' . PHP_EOL);
}

$identifier = $argv[1] ?? null;
if (!$identifier) {
    echo "Usage : php install/totp_setup.php <téléphone-ou-email-admin>" . PHP_EOL;
    exit(1);
}

$db   = db();
$stmt = $db->prepare('SELECT id FROM admins WHERE phone = ? OR email = ? LIMIT 1');
$stmt->execute([$identifier, $identifier]);
$admin = $stmt->fetch();
if (!$admin) {
    echo "Compte admin introuvable pour : {$identifier}" . PHP_EOL;
    exit(1);
}

$secret = TOTP::generateSecret();
$db->prepare('UPDATE admins SET totp_secret = ? WHERE id = ?')->execute([$secret, $admin['id']]);

$label   = $identifier;
$otpUri  = TOTP::otpauthUri($secret, $label, 'ZinbConnect');

echo "=== 2FA activée pour l'admin ===" . PHP_EOL;
echo "Secret (à saisir manuellement si besoin) : {$secret}" . PHP_EOL;
echo "URI otpauth (à encoder en QR) : " . PHP_EOL . $otpUri . PHP_EOL;
echo "Astuce : collez l'URI dans un générateur de QR en ligne local, puis scannez-le" . PHP_EOL;
echo "avec Aegis / Google Authenticator. L'application affichera 6 chiffres, renouvelés toutes les 30 s." . PHP_EOL;

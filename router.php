<?php
/**
 * Zinbconnect — routeur pour le serveur de développement PHP.
 *
 * Le serveur intégré (`php -S`) ne lit PAS le fichier .htaccess : sans ce
 * routeur, `/.env` et `data/zinbconnect.sqlite` seraient téléchargeables.
 * On reproduit ici les mêmes interdictions, puis on laisse PHP servir
 * normalement les pages et les fichiers publics (images, etc.).
 *
 * Lancement :
 *   php -S 127.0.0.1:8099 router.php
 *   php -S 0.0.0.0:8099 router.php      (test depuis un téléphone du même Wi-Fi)
 *
 * En production (Apache/Nginx), c'est .htaccess qui joue ce rôle.
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);

// Dossiers et fichiers qui ne doivent jamais être servis.
$blockedPrefixes = ['/.git', '/data/', '/backups/', '/lib/', '/install/', '/tools/'];
$blockedExact    = ['/.env', '/.env.local', '/.env.production', '/router.php'];

$blocked = in_array($uri, $blockedExact, true);
foreach ($blockedPrefixes as $prefix) {
    if (str_starts_with($uri, $prefix)) {
        $blocked = true;
        break;
    }
}
// Extensions sensibles (sauvegardes, journaux, base).
if (!$blocked && preg_match('/\.(sql|sqlite|sqlite3|log|lock)$/i', $uri)) {
    $blocked = true;
}
// Remontée de dossier.
if (str_contains($uri, '..')) {
    $blocked = true;
}

if ($blocked) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Introuvable.";
    exit;
}

// Fichier réel : le serveur intégré le sert (ou exécute le PHP).
return false;

<?php
/**
 * Zinbconnect — export RouterOS (architecture « import périodique »).
 *
 * Le hotspot du RB2011 est la barrière qui valide les codes : il ne connaît
 * rien de l'application. À chaque lot, l'admin télécharge ici un fichier .rsc
 * contenant les utilisateurs hotspot correspondant aux tickets encore
 * disponibles du lot, et l'importe dans le routeur depuis New Terminal
 * (administration à distance : voir install/rb2011-hotspot.rsc).
 *
 * Sémantique côté RouterOS (différence assumée avec l'application) :
 *   - limit-uptime = durée CONNECTÉE cumulative, le comptage démarre à la
 *     première connexion du client (conforme au « temps démarre à l'usage ») ;
 *   - limit-bytes-total = quota de données total du ticket (0 octet échangé
 *     avant la première connexion) ;
 *   - profile=code-usable : un seul appareil connecté à la fois
 *     (shared-users=1, posé dans rb2011-hotspot.rsc) ;
 *   - commentaire = « exp=0000000000 w=00086400 <lot> » : le routeur y
 *     inscrit l'heure de la première connexion puis supprime le code au bout
 *     de la fenêtre w. Sans cela, limit-uptime seul laisserait un ticket
 *     « 24 h » vivant pendant des semaines (10 min d'usage par jour) ;
 *   - l'application ne voit pas l'usage réel du code (il faudrait la Voie 2,
 *     un pont local qui synchronise via l'API) : les statuts restent
 *     en app « disponibles » jusqu'à révocation/export suivant. Le suivi
 *     d'usage se lit sur le routeur.
 */

declare(strict_types=1);

/** Quote une valeur RouterOS (chaîne entre guillemets, antislash inclus). */
function routeros_quote(string $v): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

/** Durée à la RouterOS : 9000 => « 2h30m », 259200 => « 3d ». */
function routeros_duration(int $seconds): string
{
    $seconds = max(60, $seconds);
    $out = '';
    foreach ([86400 => 'd', 3600 => 'h', 60 => 'm', 1 => 's'] as $unit => $suffix) {
        $q = intdiv($seconds, $unit);
        if ($q > 0) {
            $out .= $q . $suffix;
        }
        $seconds %= $unit;
    }
    return $out;
}

/**
 * Génère le contenu d'un fichier .rsc d'import hotspot pour un lot.
 * $rows = lignes de batch_tickets() (code_plain déjà déchiffré, plan joint).
 * Les tickets revendus (already imported puis révoqués côté app) sont
 * supprimés du hotspot si présents.
 */
function routeros_hotspot_import(array $rows, string $batchRef): ?string
{
    $adds = [];
    $removes = [];
    foreach ($rows as $t) {
        $code = (string)($t['code_plain'] ?? '');
        if ($code === '') {
            continue; // code indisponible (APP_KEY changée) : rien à importer
        }
        $state = (string)($t['state'] ?? 'available');
        if ($state === 'revoked') {
            // Un code révoqué doit sortir du hotspot s'il y avait été importé.
            $removes[] = '/ip hotspot user remove ' . routeros_code_find($code);
            continue;
        }
        if (!in_array($state, ['available', 'sold'], true)) {
            // used / active / expired relevés côté app : il faut les laisser
            // finir leur vie au hotspot, pas les re-bloquer.
            continue;
        }

        $props = 'name=' . routeros_quote($code) . ' password=' . routeros_quote($code);
        $uptime = plan_duration_seconds($t);
        $props .= ' limit-uptime=' . routeros_quote(routeros_duration($uptime));
        $limit = $t['data_limit_bytes'] ?? null;
        if ($limit !== null && $limit !== '' && (int)$limit > 0) {
            $props .= ' limit-bytes-total=' . (int)$limit;
        }
        $props .= ' profile=' . routeros_quote('code-usable');
        $props .= ' comment=' . routeros_quote(routeros_user_comment($batchRef, $uptime));

        // on-error : réimporter le même lot n'échoue pas et n'écrase rien
        // (l'utilisateur déjà présent reste tel quel).
        $adds[] = ':do { /ip hotspot user add ' . $props . ' } on-error={ }';
    }

    if ($adds === [] && $removes === []) {
        return null; // rien à faire : éviter un fichier vide qui ferait perdre du temps
    }

    $lines = [
        '# ============================================================',
        '# Zinbconnect — import hotspot MikroTik (lot ' . $batchRef . ')',
        '# Généré le ' . date('d/m/Y H:i') . ' par la console admin.',
        '#',
        '# Mode d\'emploi :',
        '#   1. WinBox > Files : déposez le dossier des pages du hotspot',
        '#      (install/routeros-hotspot/zinbconnect) si ce n\'est pas fait,',
        '#   2. New Terminal : /import file-name=' . routeros_import_filename($batchRef) . '
#      ou copiez-collez tout ce fichier (sans les lignes #).',
        '# Réimporter le même lot ne crée pas de doublon.',
        '# ============================================================',
        '',
    ];
    // Garde-fou : sans le profil, /ip hotspot user add échouerait pour chaque
    // code et le silence du on-error laisserait croire à un import réussi.
    $lines[] = ':if ([:len [/ip hotspot user profile find name=code-usable]] = 0) do={';
    $lines[] = '  :log warning "zinbconnect : profil code-usable ABSENT - importer install/rb2011-hotspot.rsc, puis relancer cet import"';
    $lines[] = '} else={';
    foreach ($removes as $l) {
        $lines[] = '  ' . $l;
    }
    foreach ($adds as $l) {
        $lines[] = '  ' . $l;
    }
    $lines[] = '  :log info "zinbconnect : import lot ' . $batchRef . ' applique"';
    $lines[] = '}';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Commentaire d'un utilisateur hotspot. Format lu par le planificateur
 * « code-validite » du routeur (install/rb2011-hotspot.rsc) :
 *
 *     exp=0000000000 w=00086400 <lot>
 *
 * exp (10 chiffres) : date de première connexion en epoch, 0 = pas encore
 *                     utilisé — le routeur la remplit lui-même ;
 * w   (8 chiffres)  : fenêtre de validité en secondes.
 *
 * Les positions sont FIXES (date aux caractères 4-14, durée aux 17-25).
 * Changer ce format sans changer le script du routeur casse l'expiration.
 */
function routeros_user_comment(string $batchRef, int $window): string
{
    $window = max(60, min(99999999, $window));
    return sprintf('exp=%010d w=%08d %s', 0, $window, $batchRef);
}

/** Nom de fichier conseillé pour l'import de ce lot (router Files). */
function routeros_import_filename(string $batchRef): string
{
    return 'zinbconnect_' . preg_replace('/[^A-Za-z0-9._-]/', '-', $batchRef) . '.rsc';
}

/** Fragment find et échappé proprement pour un /ip hotspot user remove. */
function routeros_code_find(string $code): string
{
    // name est unique côté hotspot ("already have such login").
    return '[ find name=' . routeros_quote($code) . ' ]';
}

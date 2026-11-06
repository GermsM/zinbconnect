<?php
/**
 * Zinbconnect — tickets (lots imprimables) et cycle de vie complet.
 *
 * Un ticket existe en base dès la génération du lot :
 *   available -> (activation) -> active -> used (quota atteint)
 *                                     \-> expired (temps écoulé)
 *   n'importe quel état -> revoked (révocation manuelle)
 *
 * Le code en clair n'est jamais stocké : on garde le hash (recherche) et une
 * version chiffrée (réimpression par l'admin). Voir lib/crypto.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/crypto.php';

const TICKET_LABELS = [
    'available' => 'Non utilisé',
    'sold'      => 'Vendu',
    'active'    => 'En cours',
    'used'      => 'Consommé',
    'expired'   => 'Expiré',
    'revoked'   => 'Révoqué',
];

/** Plafond de données d'un forfait (null = illimité). */
function plan_data_limit(array $plan): ?int
{
    $v = $plan['data_limit_bytes'] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    $v = (int)$v;
    return $v > 0 ? $v : null;
}

/** Durée d'un forfait en secondes (duration_minutes prioritaire). */
function plan_duration_seconds(array $plan): int
{
    $minutes = (int)($plan['duration_minutes'] ?? 0);
    if ($minutes > 0) {
        return $minutes * 60;
    }
    return max(1, (int)($plan['duration_days'] ?? 1)) * 86400;
}

function plan_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM subscriptions WHERE id = ?');

    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function plans_all(): array
{
    $rows = db()->query('SELECT * FROM subscriptions ORDER BY rate ASC, id ASC')->fetchAll();
    foreach ($rows as &$r) {
        $r['title'] = plan_title($r);
        $r['label'] = plan_label($r);
    }
    return $rows;
}

/**
 * Statut affiché d'un ticket : tient compte de l'heure et du quota,
 * sans dépendre d'une tâche de fond.
 */
function ticket_state(array $t, ?int $now = null): string
{
    $now = $now ?? time();

    if (!empty($t['revoked_at'])) {
        return 'revoked';
    }
    $status = (string)($t['status'] ?? 'available');
    if ($status === 'available' || $status === 'sold') {
        // « sold » = carte vendue par un revendeur, pas encore activée :
        // le temps ne commence qu'à la première connexion du client.
        return 'available';
    }
    if ($status === 'used') {
        return 'used';
    }
    if (!empty($t['expires_at']) && strtotime((string)$t['expires_at']) <= $now) {
        return 'expired';
    }
    $limit = $t['data_limit_bytes'] ?? null;
    if ($limit !== null && $limit !== '' && (int)$t['bytes_used'] >= (int)$limit) {
        return 'used';
    }
    return 'active';
}

function ticket_remaining_seconds(array $t, ?int $now = null): int
{
    if (ticket_state($t, $now) !== 'active' || empty($t['expires_at'])) {
        return 0;
    }
    return max(0, strtotime((string)$t['expires_at']) - ($now ?? time()));
}

/**
 * Génère un lot de $quantity tickets pour un forfait.
 * Retourne les codes EN CLAIR (une seule fois) pour l'impression/CSV.
 */
function generate_batch(int $subscriptionId, int $quantity, ?float $unitPrice, ?string $actor, ?string $note = null, bool $isTest = false): array
{
    $plan = plan_by_id($subscriptionId);
    if (!$plan) {
        return ['ok' => false, 'error' => 'invalidSubscription'];
    }
    $quantity = max(1, min(1000, $quantity));

    if ($isTest) {
        $unitPrice = 0.0;
        $note = trim('[ESSAI GRATUIT] ' . ($note ?? ''));
        $prefix = 'TEST-';
    } else {
        $unitPrice = $unitPrice !== null ? (float)(int)round($unitPrice) : (float)(int)$plan['rate'];
        if ($unitPrice < 0) {
            $unitPrice = 0.0;
        }
        $prefix = 'LOT-';
    }

    $db = db();
    $reference = $prefix . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $limit = plan_data_limit($plan);

    try {
        $db->beginTransaction();

        $db->prepare('INSERT INTO batches (reference, subscription_id, quantity, unit_price, created_at, created_by) VALUES (?,?,?,?,?,?)')
           ->execute([$reference, $subscriptionId, $quantity, $unitPrice, date('Y-m-d H:i:s'), $actor]);

        $batchId = (int)$db->lastInsertId();

        $ins = $db->prepare(
            'INSERT INTO tickets (batch_id, subscription_id, code_hash, code_enc, status, price, data_limit_bytes, created_at, note)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $exists = $db->prepare('SELECT 1 FROM tickets WHERE code_hash = ?');

        $codes = [];
        for ($i = 0; $i < $quantity; $i++) {
            // Un code unique, même en cas de collision (probabilité infime).
            do {
                $code = generate_code();
                $hash = code_hash($code);
                $exists->execute([$hash]);
            } while ($exists->fetchColumn());

            $ins->execute([
                $batchId, $subscriptionId, $hash, code_encrypt($code), 'available',
                $unitPrice, $limit, date('Y-m-d H:i:s'), $note,
            ]);
            $codes[] = $code;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        audit('batch_generate_error', $actor, $e->getMessage());
        return ['ok' => false, 'error' => 'generationFailed'];
    }

    audit('batch_generated', $actor, 'lot=' . $reference . ' forfait=' . $plan['name'] . ' quantité=' . $quantity);

    return [
        'ok'        => true,
        'reference' => $reference,
        'batch_id'  => $batchId,
        'plan'      => $plan,
        'unit_price'=> $unitPrice,
        'codes'     => $codes,
        'error'     => null,
    ];
}

function ticket_by_code(string $codeRaw): ?array
{
    $code = normalize_code($codeRaw);
    if ($code === '') {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT t.*, s.name AS plan_name, s.duration_days, s.duration_minutes
         FROM tickets t LEFT JOIN subscriptions s ON s.id = t.subscription_id
         WHERE t.code_hash = ? LIMIT 1'
    );
    $stmt->execute([code_hash($code)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Active un ticket (vente ou première connexion du client).
 * Atomique : l'UPDATE n'aboutit que si le ticket est encore "available".
 * $contact peut être null (le client final n'a qu'un code).
 */
function activate_ticket(string $codeRaw, ?string $contact = null, ?int $resellerId = null): array
{
    $ticket = ticket_by_code($codeRaw);
    if (!$ticket) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    $state = ticket_state($ticket);
    if ($state === 'revoked') {
        return ['ok' => false, 'error' => 'revoked'];
    }
    if ($state !== 'available') {
        return ['ok' => false, 'error' => $state === 'active' ? 'alreadyUsed' : $state];
    }

    // Contact facultatif, mais validé s'il est fourni.
    $contactType = null;
    if ($contact !== null && trim($contact) !== '') {
        $contact = trim($contact);
        if (preg_match('/^[\w\-.]+@([\w-]+\.)+[\w-]{2,4}$/', $contact)) {
            $contactType = 'email';
        } elseif (preg_match('/^\+?\d{7,15}$/', $contact)) {
            $contactType = 'phone';
            if (!str_starts_with($contact, '+')) {
                $contact = '+243' . ltrim($contact, '0');
            }
        } else {
            return ['ok' => false, 'error' => 'invalidContact'];
        }
    } else {
        $contact = null;
    }

    $plan = plan_by_id((int)$ticket['subscription_id']);
    if (!$plan) {
        return ['ok' => false, 'error' => 'invalidSubscription'];
    }

    $now     = time();
    $expires = date('Y-m-d H:i:s', $now + plan_duration_seconds($plan));
    $limit   = $ticket['data_limit_bytes'] !== null && $ticket['data_limit_bytes'] !== ''
        ? (int)$ticket['data_limit_bytes']
        : plan_data_limit($plan);

    $db = db();
    try {
        $db->beginTransaction();

        // Verrou atomique : seul le premier passage trouve encore un statut
        // « disponible » (available) ou « vendu » (sold, pas encore activé).
        $upd = $db->prepare(
            "UPDATE tickets
                SET status = 'active', activated_at = ?, expires_at = ?,
                    contact = COALESCE(?, contact), contact_type = COALESCE(?, contact_type),
                    data_limit_bytes = ?, reseller_id = COALESCE(?, reseller_id)
              WHERE id = ? AND status IN ('available','sold') AND revoked_at IS NULL"
        );
        $upd->execute([
            date('Y-m-d H:i:s', $now), $expires, $contact, $contactType,
            $limit, $resellerId, $ticket['id'],
        ]);

        if ($upd->rowCount() !== 1) {
            $db->rollback();
            return ['ok' => false, 'error' => 'alreadyUsed'];
        }

        // Vente : une ligne dans transactions UNIQUEMENT si le ticket est payant (> 0 FC)
        // et qu'il n'a pas déjà été encaissé par un revendeur.
        // Les tickets de TEST (0 FC) ne comptent JAMAIS comme vente.
        $alreadySold = ($ticket['status'] ?? '') === 'sold';
        $isPaying    = ((float)($ticket['price'] ?? 0)) > 0.0;
        if (!$alreadySold && $isPaying) {
            $ticketPriceInt = (int)round((float)$ticket['price']);
            $commission = 0;
            if ($resellerId !== null) {
                $pct = (float)(setting('reseller_commission') ?? '25');
                $commission = (int)round($ticketPriceInt * $pct / 100);
            }
            $db->prepare(
                'INSERT INTO transactions (reseller_id, subscription_id, buyer_contact, access_code, amount, commission, created_at)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $resellerId, $ticket['subscription_id'], $contact,
                normalize_code($codeRaw), $ticketPriceInt, $commission, date('Y-m-d H:i:s', $now),
            ]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        audit('ticket_activate_error', null, $e->getMessage());
        return ['ok' => false, 'error' => 'activationFailed'];
    }

    $ticket['status']       = 'active';
    $ticket['activated_at'] = date('Y-m-d H:i:s', $now);
    $ticket['expires_at']   = $expires;
    $ticket['contact']      = $contact;
    $ticket['data_limit_bytes'] = $limit;

    audit('ticket_activated', $resellerId ? 'reseller:' . $resellerId : 'portal', 'ticket=' . $ticket['id'] . ' forfait=' . $plan['name']);

    return [
        'ok'          => true,
        'ticket'      => $ticket,
        'plan'        => $plan,
        'expires_at'  => $expires,
        'remaining'   => plan_duration_seconds($plan),
        'data_limit'  => $limit,
        'error'       => null,
    ];
}

/**
 * Vente par un revendeur : la carte est marquée vendue et la commission est
 * enregistrée, mais le compte à rebours NE démarre PAS.
 * Le temps ne commence qu'à la première connexion du client sur le portail.
 */
function sell_ticket(string $codeRaw, ?string $contact, int $resellerId): array
{
    $ticket = ticket_by_code($codeRaw);
    if (!$ticket) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    $state = ticket_state($ticket);
    if ($state === 'revoked') {
        return ['ok' => false, 'error' => 'revoked'];
    }
    if ($state !== 'available') {
        return ['ok' => false, 'error' => $state === 'active' ? 'alreadyUsed' : $state];
    }

    $contactType = null;
    if ($contact !== null && trim($contact) !== '') {
        $contact = trim($contact);
        if (preg_match('/^[\w\-.]+@([\w-]+\.)+[\w-]{2,4}$/', $contact)) {
            $contactType = 'email';
        } elseif (preg_match('/^\+?\d{7,15}$/', $contact)) {
            $contactType = 'phone';
            if (!str_starts_with($contact, '+')) {
                $contact = '+243' . ltrim($contact, '0');
            }
        } else {
            return ['ok' => false, 'error' => 'invalidContact'];
        }
    } else {
        $contact = null;
    }

    $plan = plan_by_id((int)$ticket['subscription_id']);
    if (!$plan) {
        return ['ok' => false, 'error' => 'invalidSubscription'];
    }

    $db = db();
    $commission = 0.0;
    try {
        $db->beginTransaction();
        // Verrou atomique : une carte ne peut être vendue qu'une seule fois.
        $upd = $db->prepare(
            "UPDATE tickets SET status = 'sold', reseller_id = ?, contact = ?, contact_type = ?
              WHERE id = ? AND status = 'available' AND revoked_at IS NULL"
        );
        $upd->execute([$resellerId, $contact, $contactType, $ticket['id']]);
        if ($upd->rowCount() !== 1) {
            $db->rollback();
            return ['ok' => false, 'error' => 'alreadyUsed'];
        }

        $ticketPriceInt = (int)round((float)$ticket['price']);
        $commission = 0;
        if ($ticketPriceInt > 0) {
            $pct = (float)(setting('reseller_commission') ?? '25');
            $commission = (int)round($ticketPriceInt * $pct / 100);
            $db->prepare(
                'INSERT INTO transactions (reseller_id, subscription_id, buyer_contact, access_code, amount, commission, created_at)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $resellerId, $ticket['subscription_id'], $contact,
                normalize_code($codeRaw), $ticketPriceInt, $commission, date('Y-m-d H:i:s'),
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        audit('ticket_sell_error', 'reseller:' . $resellerId, $e->getMessage());
        return ['ok' => false, 'error' => 'activationFailed'];
    }

    audit('ticket_sold', 'reseller:' . $resellerId, 'ticket=' . $ticket['id'] . ' forfait=' . plan_title($plan));

    return [
        'ok'         => true,
        'ticket'     => $ticket,
        'plan'       => $plan,
        'price'      => (float)$ticket['price'],
        'commission' => $commission,
        'error'      => null,
    ];
}

/** Révoque un ticket par code ou par identifiant. */
function revoke_ticket(string $codeOrId, ?string $actor = null): bool
{
    $db = db();
    $isId = ctype_digit($codeOrId);
    $stmt = $isId
        ? $db->prepare("SELECT id, status, revoked_at FROM tickets WHERE id = ?")
        : $db->prepare("SELECT id, status, revoked_at FROM tickets WHERE code_hash = ?");
    $stmt->execute([$isId ? (int)$codeOrId : code_hash(normalize_code($codeOrId))]);
    $row = $stmt->fetch();
    if (!$row || !empty($row['revoked_at'])) {
        return false;
    }

    $upd = $db->prepare("UPDATE tickets SET revoked_at = ?, status = 'revoked' WHERE id = ? AND revoked_at IS NULL");
    $upd->execute([date('Y-m-d H:i:s'), $row['id']]);
    $ok = $upd->rowCount() === 1;
    audit($ok ? 'ticket_revoked' : 'ticket_revoke_miss', $actor, 'ticket=' . $row['id']);
    return $ok;
}

/** Tickets d'un lot (avec code déchiffré si APP_KEY présente). */
function batch_tickets(int $batchId): array
{
    $stmt = db()->prepare(
        'SELECT t.*, s.name AS plan_name, s.duration_days, s.duration_minutes
         FROM tickets t LEFT JOIN subscriptions s ON s.id = t.subscription_id
         WHERE t.batch_id = ? ORDER BY t.id ASC'
    );
    $stmt->execute([$batchId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['code_plain'] = code_decrypt($r['code_enc'] ?? null);
        $r['state']      = ticket_state($r);
        if ($r['state'] === 'available' && ($r['status'] ?? '') === 'sold') {
            $r['state'] = 'sold';
        }
        $r['plan_title'] = plan_title($r);
    }
    return $rows;
}

function batch_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT b.*, s.name AS plan_name FROM batches b LEFT JOIN subscriptions s ON s.id = b.subscription_id WHERE b.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function batches_recent(int $limit = 30): array
{
    $stmt = db()->prepare(
        "SELECT b.*, s.name AS plan_name, s.duration_minutes, s.duration_days,
                (SELECT COUNT(*) FROM tickets t WHERE t.batch_id = b.id) AS tickets_count,
                (SELECT COUNT(*) FROM tickets t WHERE t.batch_id = b.id AND t.status <> 'available') AS tickets_used
         FROM batches b LEFT JOIN subscriptions s ON s.id = b.subscription_id
         ORDER BY b.id DESC LIMIT ?"
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['plan_title'] = plan_title($r);
    }
    return $rows;
}

/** Recherche : code exact d'abord, sinon contact / référence de lot / note. */
function search_tickets(string $q, int $limit = 60): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $byCode = ticket_by_code($q);
    if ($byCode) {
        $byCode['state'] = ticket_state($byCode);
        if ($byCode['state'] === 'available' && ($byCode['status'] ?? '') === 'sold') {
            $byCode['state'] = 'sold';
        }
        $byCode['code_plain'] = code_decrypt($byCode['code_enc'] ?? null);
        $byCode['plan_title'] = plan_title($byCode);
        return [$byCode];
    }

    /* ESCAPE ? est indispensable sur SQLite (contrairement à MySQL, il n'y a
       pas de caractère d'échappement par défaut) : sans cette clause, une
       recherche contenant « % » ou « _ » ne remontait rien. */
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $stmt = db()->prepare(
        "SELECT t.*, s.name AS plan_name, b.reference AS batch_ref
         FROM tickets t
         LEFT JOIN subscriptions s ON s.id = t.subscription_id
         LEFT JOIN batches b ON b.id = t.batch_id
         WHERE t.contact LIKE ? ESCAPE ? OR b.reference LIKE ? ESCAPE ? OR t.note LIKE ? ESCAPE ?
         ORDER BY t.id DESC LIMIT ?"
    );
    $stmt->execute([$like, '\\', $like, '\\', $like, '\\', $limit]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['state'] = ticket_state($r);
        if ($r['state'] === 'available' && ($r['status'] ?? '') === 'sold') {
            $r['state'] = 'sold';
        }
        $r['code_plain'] = code_decrypt($r['code_enc'] ?? null);
        $r['plan_title'] = plan_title($r);
    }
    return $rows;
}

/** Tickets en cours (connectés ou pas encore expirés). */
function active_sessions(int $limit = 100): array
{
    $stmt = db()->prepare(
        "SELECT t.*, s.name AS plan_name
         FROM tickets t LEFT JOIN subscriptions s ON s.id = t.subscription_id
         WHERE t.status = 'active' AND t.revoked_at IS NULL
         ORDER BY t.expires_at ASC LIMIT ?"
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $r['state'] = ticket_state($r);
        if ($r['state'] === 'active') {
            $r['code_plain'] = code_decrypt($r['code_enc'] ?? null);
            $r['plan_title'] = plan_title($r);
            $out[] = $r;
        }
    }
    return $out;
}

/** Ventes agrégées : jour, semaine (lundi), mois. */
function sales_stats(): array
{
    $db  = db();
    $now = time();

    $ranges = [
        'today' => date('Y-m-d 00:00:00', $now),
        'week'  => date('Y-m-d 00:00:00', strtotime('monday this week', $now)),
        'month' => date('Y-m-01 00:00:00', $now),
    ];

    $out = [];
    $stmt = $db->prepare('SELECT COALESCE(SUM(amount),0) total, COUNT(*) nb FROM transactions WHERE created_at >= ? AND amount > 0');
    foreach ($ranges as $key => $since) {
        $stmt->execute([$since]);
        $row = $stmt->fetch();
        $out[$key] = ['total' => (int)round((float)$row['total']), 'count' => (int)$row['nb']];
    }

    $byPlan = $db->query(
        "SELECT s.id, s.name, s.duration_minutes, s.duration_days, COUNT(*) nb, COALESCE(SUM(tr.amount),0) total
         FROM transactions tr JOIN subscriptions s ON s.id = tr.subscription_id
         WHERE tr.created_at >= '" . $ranges['month'] . "' AND tr.amount > 0
         GROUP BY s.id, s.name, s.duration_minutes, s.duration_days ORDER BY total DESC"
    )->fetchAll();
    foreach ($byPlan as &$p) {
        $p['title'] = plan_title($p);
    }
    $out['by_plan'] = $byPlan;

    return $out;
}

/** Consommation Internet mesurée (table usage_samples), en octets. */
function usage_totals(): array
{
    $db = db();
    $todayStart = date('Y-m-d 00:00:00');
    $monthStart = date('Y-m-01 00:00:00');

    $sum = function (string $since) use ($db): int {
        $stmt = $db->prepare('SELECT COALESCE(SUM(bytes_down + bytes_up),0) t FROM usage_samples WHERE sample_date >= ?');
        $stmt->execute([substr($since, 0, 10)]);
        return (int)$stmt->fetchColumn();
    };

    return [
        'today' => $sum($todayStart),
        'month' => $sum($monthStart),
        'quota' => setting_int('starlink_quota_bytes', 536870912000),
    ];
}

/** Estimation issue des tickets (utile avant le branchement MikroTik). */
function usage_estimate_tickets(): array
{
    $db = db();
    $sum = function (string $since) use ($db): int {
        $stmt = $db->prepare('SELECT COALESCE(SUM(bytes_used),0) t FROM tickets WHERE activated_at >= ?');
        $stmt->execute([$since]);
        return (int)$stmt->fetchColumn();
    };
    return [
        'today' => $sum(date('Y-m-d 00:00:00')),
        'month' => $sum(date('Y-m-01 00:00:00')),
    ];
}

/** Nombre de tickets encore disponibles par forfait (alerte de stock). */
function stock_by_plan(): array
{
    $rows = db()->query(
        "SELECT s.id, s.name, s.rate, s.duration_minutes, s.duration_days, COUNT(t.id) available
         FROM subscriptions s LEFT JOIN tickets t ON t.subscription_id = s.id AND t.status = 'available'
         GROUP BY s.id, s.name, s.rate, s.duration_minutes, s.duration_days ORDER BY s.rate ASC"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['title'] = plan_title($r);
        $r['label'] = plan_label($r);
    }
    return $rows;
}

/** Convertit une durée RouterOS ("2h15m", "01:42:15", "45s") en secondes entières. */
function routeros_parse_duration(string $s): int
{
    $s = trim($s);
    if ($s === '' || $s === '0' || $s === '0s') {
        return 0;
    }
    if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $s, $m)) {
        $hours = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 0;
        return $hours * 3600 + ((int)$m[2]) * 60 + ((int)$m[3]);
    }
    $sec = 0;
    if (preg_match_all('/(\d+)([wdhms])/i', $s, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $val = (int)$match[1];
            $unit = strtolower($match[2]);
            $sec += match ($unit) {
                'w' => $val * 604800,
                'd' => $val * 86400,
                'h' => $val * 3600,
                'm' => $val * 60,
                's' => $val,
                default => 0,
            };
        }
        return $sec;
    }
    return (int)$s;
}

/**
 * Importe le fichier d'usage généré par le routeur (export-usage.rsc -> zinb_usage.csv).
 * Met à jour la consommation réelle (octets, uptime) de chaque ticket, actualise
 * les statuts (marquage 'used' si quota atteint) et calcule la consommation moyenne par forfait.
 */
function import_usage_csv(string $csvContent, ?string $actor = null): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
    if (!$lines || count($lines) === 0) {
        return ['ok' => false, 'error' => 'emptyFile'];
    }

    $wanRx = 0;
    $wanTx = 0;
    $updated = 0;
    $matched = 0;
    $byPlan = [];

    $db = db();
    $now = date('Y-m-d H:i:s');

    $findTicket = $db->prepare('SELECT t.*, s.duration_minutes, s.duration_days, s.name as plan_name FROM tickets t LEFT JOIN subscriptions s ON s.id = t.subscription_id WHERE t.code_hash = ? LIMIT 1');
    $updTicket = $db->prepare('UPDATE tickets SET bytes_used = ?, status = CASE WHEN ? THEN ? ELSE status END, last_sync_at = ? WHERE id = ?');

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#WAN_RX=')) {
            if (preg_match('/#WAN_RX=(\d+),WAN_TX=(\d+)/', $line, $m)) {
                $wanRx = (int)$m[1];
                $wanTx = (int)$m[2];
                try {
                    $day = date('Y-m-d');
                    $db->prepare('INSERT INTO usage_samples (sample_date, bytes_down, bytes_up, source, created_at)
                                  VALUES (?,?,?,?,?)
                                  ON CONFLICT(sample_date, source) DO UPDATE SET bytes_down = excluded.bytes_down, bytes_up = excluded.bytes_up')
                       ->execute([$day, $wanRx, $wanTx, 'router_wan', $now]);
                } catch (Throwable $e) {}
            }
            continue;
        }
        if (str_starts_with($line, 'code,') || str_starts_with($line, '#')) {
            continue;
        }

        $cols = str_getcsv($line);
        if (count($cols) < 5) {
            continue;
        }

        $codeRaw = normalize_code($cols[0]);
        if ($codeRaw === '') {
            continue;
        }

        $uptimeStr = trim($cols[1]);
        $bytesIn = (int)trim($cols[2]);
        $bytesOut = (int)trim($cols[3]);
        $bytesTotal = (int)trim($cols[4]);
        if ($bytesTotal === 0 && ($bytesIn > 0 || $bytesOut > 0)) {
            $bytesTotal = $bytesIn + $bytesOut;
        }
        $uptimeSec = routeros_parse_duration($uptimeStr);

        $hash = code_hash($codeRaw);
        $findTicket->execute([$hash]);
        $ticket = $findTicket->fetch();
        if (!$ticket) {
            continue;
        }

        $matched++;
        $newBytes = max((int)$ticket['bytes_used'], $bytesTotal);
        $planId = (int)$ticket['subscription_id'];

        if (!isset($byPlan[$planId])) {
            $byPlan[$planId] = [
                'name' => $ticket['plan_name'] ?? ('Forfait #' . $planId),
                'count' => 0,
                'total_bytes' => 0,
                'total_uptime' => 0,
            ];
        }
        $byPlan[$planId]['count']++;
        $byPlan[$planId]['total_bytes'] += $newBytes;
        $byPlan[$planId]['total_uptime'] += $uptimeSec;

        $newStatus = $ticket['status'];
        $statusChanged = false;
        $limit = $ticket['data_limit_bytes'] !== null && $ticket['data_limit_bytes'] !== '' ? (int)$ticket['data_limit_bytes'] : null;

        if ($limit !== null && $newBytes >= $limit) {
            $newStatus = 'used';
            $statusChanged = true;
        } elseif ($uptimeSec > 0 && ($ticket['status'] === 'available' || $ticket['status'] === 'sold')) {
            $newStatus = 'active';
            $statusChanged = true;
        }

        $updTicket->execute([$newBytes, $statusChanged ? 1 : 0, $newStatus, $now, $ticket['id']]);
        $updated++;
    }

    $stats = [];
    foreach ($byPlan as $pid => $data) {
        $c = max(1, $data['count']);
        $stats[] = [
            'plan_id' => $pid,
            'name' => $data['name'],
            'tickets_count' => $data['count'],
            'avg_bytes' => (int)round($data['total_bytes'] / $c),
            'avg_bytes_fmt' => format_bytes((int)round($data['total_bytes'] / $c)),
            'avg_uptime_sec' => (int)round($data['total_uptime'] / $c),
            'avg_uptime_fmt' => routeros_duration((int)round($data['total_uptime'] / $c)),
        ];
    }

    audit('usage_imported', $actor, "Import de $updated tickets traites depuis export routeur");

    return [
        'ok' => true,
        'matched' => $matched,
        'updated' => $updated,
        'wan_rx' => $wanRx,
        'wan_tx' => $wanTx,
        'wan_total' => $wanRx + $wanTx,
        'wan_total_fmt' => format_bytes($wanRx + $wanTx),
        'stats' => $stats,
    ];
}

/**
 * Génère le script .rsc pour supprimer du routeur tous les codes déjà consommés ou expirés.
 * Parade en cas de réinitialisation ou restauration du routeur avec une ancienne sauvegarde.
 */
function export_consumed_routeros_rsc(): string
{
    require_once __DIR__ . '/routeros.php';
    $stmt = db()->query("SELECT t.id, t.code_enc, t.status FROM tickets t WHERE t.status IN ('used', 'expired', 'revoked') ORDER BY t.id ASC");
    $rows = $stmt->fetchAll();

    $lines = [
        '# ============================================================',
        '# Zinbconnect — revocation des codes consommes, expires ou revoques',
        '# Genere le ' . date('d/m/Y H:i') . ' par la console admin.',
        '#',
        '# Utilisation :',
        '#   Apres un reset du RB2011 ou la restauration d\'une ancienne',
        '#   sauvegarde, importez ce script pour supprimer du hotspot tous',
        '#   les codes qui ont deja ete consommes selon la base de donnees.',
        '# ============================================================',
        '',
    ];

    $count = 0;
    foreach ($rows as $r) {
        $code = code_decrypt($r['code_enc'] ?? null);
        if ($code === null || $code === '') {
            continue;
        }
        $lines[] = '/ip hotspot user remove ' . routeros_code_find($code);
        $count++;
    }

    $lines[] = '';
    $lines[] = ':log info "Zinbconnect : ' . $count . ' codes consommes ou expires purges du hotspot"';
    $lines[] = '';

    return implode("\n", $lines);
}

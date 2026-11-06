<?php
/**
 * Zinbconnect — console d'administration (français, mobile-first).
 *
 * Une seule page, sans dépendance externe (aucun CDN) :
 *   - tableau de bord : ventes jour/semaine/mois, consommation Starlink ;
 *   - tickets : génération de lots, PDF avec QR, CSV, recherche, révocation,
 *     export hotspot MikroTik (.rsc) ;
 *   - ventes par forfait, sessions actives ;
 *   - réglages : prix, plafonds de données, quota Starlink ;
 *   - revendeurs, journal d'audit ;
 *   - sauvegarde et restauration.
 *
 * Toutes les actions passent par du JSON protégé par CSRF ; la session admin
 * est la seule preuve d'accès (cookie HttpOnly, régénéré à la connexion).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/tickets.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/pdf.php';
require_once __DIR__ . '/lib/routeros.php';

security_headers();
session_boot();

if (empty($_SESSION['admin_id'])) {
    header('Location: reseller.php');
    exit;
}
$adminId = (int)$_SESSION['admin_id'];
$actor   = 'admin:' . $adminId;

/**
 * Téléchargement du PDF d'un lot, en GET.
 * Le fichier est envoyé tel quel (plus de base64 côté navigateur) : c'est ce
 * qui provoquait le gel de l'écran sur les gros lots.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pdf'])) {
    $batch = batch_by_id((int)$_GET['pdf']);
    if (!$batch) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Lot introuvable.';
        exit;
    }

    $tickets = [];
    $missing = 0;
    foreach (batch_tickets((int)$batch['id']) as $row) {
        if (empty($row['code_plain'])) {
            $missing++;
            continue;
        }
        $tickets[] = [
            'code'     => $row['code_plain'],
            'plan'     => $row['plan_title'],
            'price'    => (float)$row['price'],
            'duration' => plan_duration_label($row),
        ];
    }

    if (!$tickets) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Aucun code lisible dans ce lot (clé APP_KEY absente ou lot vide).';
        exit;
    }

    set_time_limit(120);
    $pdf = pdf_ticket_sheet($tickets, [
        'portal_name' => setting('portal_name') ?? 'Zinbconnect',
        'portal_url'  => setting('portal_url', ''),
        'batch'       => $batch['reference'],
        'date'        => date('d/m/Y'),
    ]);
    audit('batch_printed', $actor, 'lot=' . $batch['reference'] . ' tickets=' . count($tickets));

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="tickets_' . $batch['reference'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}

/**
 * Téléchargement en GET du fichier d'import MikroTik d'un lot (Voie 1).
 * Mêmes garde-fous que le PDF : réservé à la session admin, envoi direct
 * du fichier (aucun traitement navigateur).
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['rsc'])) {
    $batch = batch_by_id((int)$_GET['rsc']);
    if (!$batch) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Lot introuvable.';
        exit;
    }

    $rows = array_values(array_filter(
        batch_tickets((int)$batch['id']),
        function (array $t): bool { return ($t['code_plain'] ?? '') !== ''; }
    ));
    $rsc = routeros_hotspot_import($rows, (string)$batch['reference']);
    if ($rsc === null) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Rien à importer : tous les tickets de ce lot sont déjà consommés, expirés ou révoqués ; gérez-les au hotspot.';
        exit;
    }
    audit('batch_rsc_exported', $actor, 'lot=' . $batch['reference']);

    $filename = routeros_import_filename((string)$batch['reference']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($rsc));
    header('Cache-Control: no-store');
    echo $rsc;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_consumed_rsc'])) {
    $rsc = export_consumed_routeros_rsc();
    audit('consumed_rsc_exported', $actor, null);
    $filename = 'zinbconnect_purge_consommes_' . date('Ymd_His') . '.rsc';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($rsc));
    header('Cache-Control: no-store');
    echo $rsc;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_input();
    csrf_require($input);
    $action = (string)($input['action'] ?? '');

    switch ($action) {
        case 'logout':
            logout_current();
            json_out(['success' => true]);

        case 'dashboard':
            // Rétention des journaux : purge automatique des logs > 30 jours (RGPD/minimisation des données)
            prune_old_logs(30);

            $sales    = sales_stats();
            $usage    = usage_totals();
            $estimate = usage_estimate_tickets();
            $active   = active_sessions(500);
            $stock    = stock_by_plan();
            $quota    = max(1, (int)$usage['quota']);

            $recent = db()->query(
                'SELECT tr.id, tr.amount, tr.created_at, tr.buyer_contact,
                        s.name AS plan_name, s.duration_minutes, s.duration_days
                 FROM transactions tr LEFT JOIN subscriptions s ON s.id = tr.subscription_id
                 ORDER BY tr.id DESC LIMIT 8'
            )->fetchAll();
            foreach ($recent as &$row) {
                $row['plan_title'] = plan_title($row);
            }
            unset($row);

            json_out([
                'success'    => true,
                'sales'      => $sales,
                'usage'      => [
                    'today'        => $usage['today'],
                    'month'        => $usage['month'],
                    'quota'        => $quota,
                    'today_pct'    => round($usage['today'] * 100 / $quota, 1),
                    'month_pct'    => round($usage['month'] * 100 / $quota, 1),
                    'today_est'    => $estimate['today'],
                    'month_est'    => $estimate['month'],
                ],
                'active_count' => count($active),
                'stock'        => $stock,
                'recent'       => $recent,
                'low_stock'    => setting_int('low_stock_threshold', 20),
            ]);

        case 'generate_batch':
            $planId   = (int)($input['subscription_id'] ?? 0);
            $quantity = (int)($input['quantity'] ?? 0);
            $unit     = isset($input['unit_price']) && $input['unit_price'] !== '' ? (float)$input['unit_price'] : null;
            $note     = isset($input['note']) ? mb_substr(trim((string)$input['note']), 0, 200) : null;
            $isTest   = !empty($input['is_test']);
            $result   = generate_batch($planId, $quantity, $unit, $actor, $note ?: null, $isTest);
            json_out($result);

        case 'batches':
            json_out(['success' => true, 'batches' => batches_recent(40)]);

        case 'search':
            $q = trim((string)($input['q'] ?? ''));
            $rows = search_tickets($q, 80);
            foreach ($rows as &$r) {
                $r['state_label'] = TICKET_LABELS[$r['state']] ?? $r['state'];
                $r['remaining']   = ticket_remaining_seconds($r);
            }
            json_out(['success' => true, 'tickets' => $rows]);

        case 'revoke':
            $id  = (string)($input['id'] ?? '');
            $ok  = $id !== '' && revoke_ticket($id, $actor);
            json_out(['success' => $ok, 'message' => $ok ? 'revoked' : 'notRevoked']);

        case 'sessions':
            $rows = active_sessions(300);
            foreach ($rows as &$r) {
                $r['remaining'] = ticket_remaining_seconds($r);
            }
            json_out(['success' => true, 'sessions' => $rows]);

        case 'plans':
            json_out(['success' => true, 'plans' => plans_all()]);

        case 'save_plan':
            $id = (int)($input['id'] ?? 0);
            $plan = plan_by_id($id);
            if (!$plan) {
                json_out(['success' => false, 'message' => 'invalidPlan']);
            }
            $rate = (int)round(max(0.0, (float)($input['rate'] ?? $plan['rate'])));
            $minutes = max(0, (int)($input['duration_minutes'] ?? 0));
            $days = max(0, (int)($input['duration_days'] ?? 0));
            if ($minutes === 0 && $days === 0) {
                json_out(['success' => false, 'message' => 'invalidDuration']);
            }
            $limitMb = (int)($input['data_limit_mb'] ?? 0);
            $limit = $limitMb > 0 ? $limitMb * 1024 * 1024 : null;

            db()->prepare('UPDATE subscriptions SET rate = ?, duration_minutes = ?, duration_days = ?, data_limit_bytes = ? WHERE id = ?')
                ->execute([$rate, $minutes ?: null, $days ?: null, $limit, $id]);

            $updated = plan_by_id($id);
            audit('plan_updated', $actor, 'forfait=' . ($updated ? plan_label($updated) : $id) . ' plafond=' . ($limit ? format_bytes($limit) : 'illimité'));
            // On renvoie la liste complète : le navigateur n'a rien à recalculer.
            json_out([
                'success' => true,
                'label'   => $updated ? plan_label($updated) : '',
                'plans'   => plans_all(),
            ]);

        case 'import_usage':
            $csv = (string)($input['csv_content'] ?? '');
            if (trim($csv) === '') {
                json_out(['success' => false, 'message' => 'emptyContent']);
            }
            $res = import_usage_csv($csv, $actor);
            json_out(array_merge(['success' => $res['ok']], $res));

        case 'save_settings':
            $map = [
                'portal_name'         => mb_substr(trim((string)($input['portal_name'] ?? '')), 0, 80),
                'portal_url'          => mb_substr(trim((string)($input['portal_url'] ?? '')), 0, 200),
                'reseller_commission' => (string)max(0, min(90, (int)($input['reseller_commission'] ?? 25))),
                'low_stock_threshold' => (string)max(1, min(500, (int)($input['low_stock_threshold'] ?? 20))),
                'starlink_quota_bytes'=> (string)max(1, (int)($input['starlink_quota_gb'] ?? 500)) * 1024 * 1024 * 1024,
            ];
            foreach ($map as $k => $v) {
                setting_set($k, $v);
            }
            audit('settings_updated', $actor, null);
            json_out(['success' => true, 'settings' => $map]);

        case 'settings':
            json_out([
                'success' => true,
                'settings' => [
                    'portal_name'          => setting('portal_name'),
                    'portal_url'           => setting('portal_url', ''),
                    'reseller_commission'  => setting('reseller_commission'),
                    'low_stock_threshold'  => setting('low_stock_threshold'),
                    'starlink_quota_gb'    => (int)round(setting_int('starlink_quota_bytes', 536870912000) / (1024 ** 3)),
                ],
            ]);

        case 'resellers':
            $rows = db()->query(
                'SELECT r.id, r.name, r.phone, r.email, r.status, r.created_at,
                        (SELECT COUNT(*) FROM transactions t WHERE t.reseller_id = r.id) AS sales,
                        (SELECT COALESCE(SUM(commission),0) FROM transactions t WHERE t.reseller_id = r.id) AS commission
                 FROM resellers r ORDER BY r.id DESC LIMIT 100'
            )->fetchAll();
            json_out(['success' => true, 'resellers' => $rows]);

        case 'toggle_reseller':
            $id = (int)($input['id'] ?? 0);
            $stmt = db()->prepare('UPDATE resellers SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END WHERE id = ?');
            $stmt->execute([$id]);
            audit('reseller_toggled', $actor, 'reseller=' . $id);
            json_out(['success' => $stmt->rowCount() === 1]);

        case 'create_reseller':
            $name  = trim((string)($input['name'] ?? ''));
            $phone = trim((string)($input['phone'] ?? ''));
            $email = trim((string)($input['email'] ?? ''));
            $pass  = (string)($input['password'] ?? '');
            if ($name === '' || !preg_match('/^\+?\d{7,15}$/', $phone) || strlen($pass) < 8) {
                json_out(['success' => false, 'message' => 'invalidFields']);
            }
            try {
                db()->prepare('INSERT INTO resellers (name, phone, email, password, status, created_at) VALUES (?,?,?,?,1,?)')
                    ->execute([$name, $phone, $email ?: null, password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
                audit('reseller_created', $actor, 'phone=' . $phone);
                json_out(['success' => true]);
            } catch (Throwable $e) {
                json_out(['success' => false, 'message' => 'alreadyExists']);
            }

        case 'audit':
            $rows = db()->query('SELECT created_at, event, actor, ip, details FROM audit_log ORDER BY id DESC LIMIT 120')->fetchAll();
            json_out(['success' => true, 'events' => $rows]);

        case 'backup_sql':
            $sql = db_dump_sql();
            audit('backup_downloaded', $actor, strlen($sql) . ' octets');
            json_out([
                'success'  => true,
                'filename' => 'zinbconnect_' . date('Ymd-His') . '.sql',
                'sql'      => $sql,
            ]);

        case 'restore':
            $sql = (string)($input['sql'] ?? '');
            if (strlen($sql) < 20 || strlen($sql) > 12 * 1024 * 1024) {
                json_out(['success' => false, 'message' => 'invalidFile']);
            }
            $safety = db_backup_to_file('before_restore');
            if ($safety === null) {
                json_out(['success' => false, 'message' => 'backupFailed']);
            }
            $res = db_restore_replace($sql);
            $res['safety_file'] = basename($safety);
            json_out($res);

        case 'export_batch':
            $batchId = (int)($input['batch_id'] ?? 0);
            $batch = batch_by_id($batchId);
            if (!$batch) {
                json_out(['success' => false, 'message' => 'invalidBatch']);
            }
            $rows = batch_tickets($batchId);
            $csv = "\xEF\xBB\xBF" . "code;forfait;prix;plafond;lot;statut\n";
            foreach ($rows as $r) {
                if (empty($r['code_plain'])) {
                    continue;
                }
                $csv .= implode(';', [
                    $r['code_plain'],
                    str_replace(';', ',', (string)$r['plan_title']),
                    number_format((float)$r['price'], 0, ',', ''),
                    $r['data_limit_bytes'] ? (string)round((int)$r['data_limit_bytes'] / 1048576) : '',
                    $batch['reference'],
                    TICKET_LABELS[$r['state']] ?? $r['state'],
                ]) . "\n";
            }
            json_out([
                'success'  => true,
                'filename' => 'tickets_' . $batch['reference'] . '.csv',
                'csv'      => $csv,
            ]);

        case 'delete_batch':
            $batchId = (int)($input['batch_id'] ?? 0);
            $batch = batch_by_id($batchId);
            if (!$batch) {
                json_out(['success' => false, 'message' => 'invalidBatch']);
            }
            $db = db();
            $stmt = $db->prepare("SELECT COUNT(*) FROM tickets WHERE batch_id = ? AND status <> 'available'");
            $stmt->execute([$batchId]);
            $used = (int)$stmt->fetchColumn();
            if ($used > 0) {
                json_out(['success' => false, 'message' => 'batchInUse', 'used' => $used]);
            }
            try {
                $db->beginTransaction();
                $db->prepare('DELETE FROM tickets WHERE batch_id = ?')->execute([$batchId]);
                $db->prepare('DELETE FROM batches WHERE id = ?')->execute([$batchId]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                audit('batch_delete_error', $actor, $e->getMessage());
                json_out(['success' => false, 'message' => 'deleteFailed']);
            }
            audit('batch_deleted', $actor, 'lot=' . $batch['reference'] . ' tickets=' . (int)$batch['quantity']);
            json_out(['success' => true]);

        case 'change_password':
            $current = (string)($input['current'] ?? '');
            $new     = (string)($input['new'] ?? '');
            $confirm = (string)($input['confirm'] ?? '');
            if ($new !== $confirm) {
                json_out(['success' => false, 'message' => 'mismatch']);
            }
            if (strlen($new) < 10) {
                json_out(['success' => false, 'message' => 'tooShort']);
            }
            $stmt = db()->prepare('SELECT password FROM admins WHERE id = ?');
            $stmt->execute([$adminId]);
            $hash = (string)$stmt->fetchColumn();
            if ($hash === '' || !password_verify($current, $hash)) {
                audit('admin_password_change_failed', $actor, 'mot de passe actuel erroné');
                json_out(['success' => false, 'message' => 'wrongPassword']);
            }
            if (password_verify($new, $hash)) {
                json_out(['success' => false, 'message' => 'samePassword']);
            }
            db()->prepare('UPDATE admins SET password = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            audit('admin_password_changed', $actor, null);
            json_out(['success' => true, 'csrf' => $_SESSION['csrf']]);

        case 'reset_system':
            $password = (string)($input['password'] ?? '');
            $confirm  = strtoupper(trim((string)($input['confirm'] ?? '')));
            if ($confirm !== 'EFFACER') {
                json_out(['success' => false, 'message' => 'confirmRequired']);
            }
            $stmt = db()->prepare('SELECT password FROM admins WHERE id = ?');
            $stmt->execute([$adminId]);
            $hash = (string)$stmt->fetchColumn();
            if ($hash === '' || !password_verify($password, $hash)) {
                audit('reset_refused', $actor, 'mot de passe erroné');
                json_out(['success' => false, 'message' => 'wrongPassword']);
            }
            $safety = db_backup_to_file('before_reset');
            if ($safety === null) {
                json_out(['success' => false, 'message' => 'backupFailed']);
            }
            $db = db();
            $tables = ['tickets', 'batches', 'transactions', 'current_codes', 'access_codes', 'usage_samples', 'rate_limits', 'audit_log'];
            try {
                $db->beginTransaction();
                foreach ($tables as $table) {
                    try {
                        $db->exec('DELETE FROM ' . $table);
                    } catch (Throwable $e) {
                        // table absente sur une base ancienne : on continue
                    }
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                json_out(['success' => false, 'message' => 'resetFailed']);
            }
            audit('system_reset', $actor, 'sauvegarde=' . basename($safety));
            json_out(['success' => true, 'safety_file' => basename($safety)]);
    }

    json_out(['success' => false, 'message' => 'unknownAction'], 400);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#4c1d95">
<meta name="csrf-token" content="<?php echo html(csrf_token()); ?>">
<title>Administration — <?php echo html(setting('portal_name') ?? 'Zinbconnect'); ?></title>
<link rel="icon" href="download.png">
<style>
:root{--v:#5b21b6;--v2:#7c3aed;--ok:#15803d;--ko:#b91c1c;--warn:#b45309;--bg:#f3f4f6;--card:#fff;--txt:#111827;--muted:#6b7280;--line:#e5e7eb}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--txt);font-size:15px;line-height:1.45}
.top{position:sticky;top:0;z-index:10;background:var(--v);color:#fff;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.top h1{font-size:16px;margin:0;font-weight:700}
.top small{display:block;font-weight:400;opacity:.8;font-size:11px}
.top button{background:rgba(255,255,255,.15);border:0;color:#fff;padding:8px 12px;border-radius:8px;font-size:13px;cursor:pointer}
nav{display:flex;gap:6px;overflow-x:auto;padding:10px 10px 0;background:var(--v)}
nav button{white-space:nowrap;border:0;background:rgba(255,255,255,.12);color:#fff;padding:9px 13px;border-radius:9px 9px 0 0;font-size:13px;cursor:pointer}
nav button.active{background:var(--bg);color:var(--v);font-weight:700}
main{max-width:900px;margin:0 auto;padding:14px 12px 60px}
section{display:none}
section.active{display:block}
h2{font-size:15px;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted)}
h2:first-child{margin-top:0}
.card{background:var(--card);border-radius:12px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.06);margin-bottom:12px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
@media(min-width:640px){.grid{grid-template-columns:repeat(4,1fr)}}
.kpi{background:var(--card);border-radius:12px;padding:12px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.kpi span{display:block;font-size:12px;color:var(--muted)}
.kpi strong{font-size:20px;display:block;margin-top:2px;font-variant-numeric:tabular-nums}
.kpi em{font-style:normal;font-size:11px;color:var(--muted)}
label{display:block;font-size:13px;font-weight:600;margin:10px 0 4px}
input,select,textarea{width:100%;padding:11px;border:1px solid var(--line);border-radius:9px;font-size:15px;background:#fff;color:var(--txt)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--v2);box-shadow:0 0 0 3px rgba(124,58,237,.12)}
button.primary{background:var(--v);color:#fff;border:0;padding:12px 16px;border-radius:9px;font-size:15px;font-weight:600;cursor:pointer}
button.primary:disabled{opacity:.55}
button.ghost{background:#f3f4f6;color:var(--txt);border:1px solid var(--line);padding:9px 12px;border-radius:8px;font-size:13px;cursor:pointer}
button.danger{background:#fee2e2;color:var(--ko);border:1px solid #fecaca;padding:8px 11px;border-radius:8px;font-size:13px;cursor:pointer}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(min-width:620px){.row2{grid-template-columns:repeat(4,1fr)}}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:11px;text-transform:uppercase;color:var(--muted);letter-spacing:.3px}
.scroll{overflow-x:auto}
.chip{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
.s-available{background:#ede9fe;color:#5b21b6}
.s-active{background:#dcfce7;color:#166534}
.s-used{background:#fef3c7;color:#92400e}
.s-sold{background:#e0f2fe;color:#075985}
.s-expired{background:#f3f4f6;color:#4b5563}
.s-revoked{background:#fee2e2;color:#991b1b}
.bar{height:9px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-top:6px}
.bar i{display:block;height:100%;background:var(--v2)}
.bar i.warn{background:#f59e0b}
.bar i.ko{background:#ef4444}
.msg{display:none;margin-top:10px;padding:11px 12px;border-radius:9px;font-size:14px}
.msg.show{display:block}
.msg.ko{background:#fef2f2;color:#7f1d1d;border:1px solid #fecaca}
#toasts{position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:80;display:flex;flex-direction:column;gap:8px;width:min(92vw,420px);pointer-events:none}
.toast{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d;padding:12px 14px;border-radius:10px;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,.18);animation:rise .25s ease both}
.toast.hide{opacity:0;transform:translateY(-8px);transition:opacity .35s ease,transform .35s ease}
.muted{color:var(--muted);font-size:12px}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.pill{display:inline-block;background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6;border-radius:8px;padding:4px 9px;font-size:12px;margin:2px 4px 2px 0}
dialog{border:0;border-radius:14px;padding:0;width:min(92vw,420px);box-shadow:0 10px 40px rgba(0,0,0,.25)}
dialog .body{padding:18px}
dialog::backdrop{background:rgba(0,0,0,.45);backdrop-filter:blur(2px)}

/* --- finitions --- */
section.active{animation:rise .28s ease both}
@keyframes rise{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes pop{from{opacity:0;transform:scale(.97)}to{opacity:1;transform:none}}
@keyframes sp{to{transform:rotate(360deg)}}
.kpi{transition:transform .2s ease,box-shadow .2s ease}
.kpi:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(76,29,149,.10)}
.kpi strong{background:linear-gradient(135deg,#5b21b6,#7c3aed);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
button.primary{background:linear-gradient(135deg,#5b21b6,#7c3aed);box-shadow:0 4px 12px rgba(91,33,182,.22);transition:transform .12s ease,box-shadow .2s,opacity .2s}
button.primary:active{transform:scale(.985)}
button.ghost,button.danger{transition:filter .15s ease,transform .12s ease}
button.ghost:active,button.danger:active{transform:scale(.985)}
nav button{transition:background .2s ease,color .2s ease}
nav button:hover{background:rgba(255,255,255,.22)}
.chip{animation:pop .25s ease both}
.pw{position:relative}
.pw input{padding-right:46px}
.pw .eye{position:absolute;right:5px;top:50%;transform:translateY(-50%);background:none;border:0;font-size:17px;line-height:1;padding:8px;cursor:pointer;color:#6b7280;border-radius:8px}
.pw .eye:active{background:#f3f4f6}
.loading{position:fixed;inset:0;background:rgba(243,244,246,.72);backdrop-filter:blur(2px);display:none;align-items:center;justify-content:center;z-index:60}
.loading.on{display:flex;animation:pop .15s ease both}
.loading .box{background:#fff;border-radius:14px;padding:18px 22px;box-shadow:0 10px 40px rgba(0,0,0,.18);text-align:center;font-size:14px;font-weight:600;color:#4c1d95;max-width:78vw}
.spin{width:26px;height:26px;border:3px solid #ddd6fe;border-top-color:#5b21b6;border-radius:50%;margin:0 auto 10px;animation:sp 1s linear infinite}
.danger-zone{border:1px solid #fecaca;background:#fffafa}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>

<div class="top">
  <div>
    <h1>Administration</h1>
    <small id="whoami"><?php echo html(setting('portal_name') ?? 'Zinbconnect'); ?> · Kitutu</small>
  </div>
  <button id="logoutBtn" type="button">Déconnexion</button>
</div>

<nav id="nav">
  <button data-tab="dash" class="active">Tableau de bord</button>
  <button data-tab="tickets">Tickets</button>
  <button data-tab="sales">Ventes</button>
  <button data-tab="sessions">Sessions</button>
  <button data-tab="usage">Consommation & Starlink</button>
  <button data-tab="resellers">Revendeurs</button>
  <button data-tab="settings">Réglages</button>
  <button data-tab="backup">Sauvegarde</button>
  <button data-tab="log">Journal</button>
</nav>

<main>
  <!-- ============ TABLEAU DE BORD ============ -->
  <section id="tab-dash" class="active">
    <div class="grid" id="kpis">
      <div class="kpi"><span>Ventes aujourd'hui</span><strong id="k-today">—</strong><em id="k-today-n"></em></div>
      <div class="kpi"><span>Cette semaine</span><strong id="k-week">—</strong><em id="k-week-n"></em></div>
      <div class="kpi"><span>Ce mois</span><strong id="k-month">—</strong><em id="k-month-n"></em></div>
      <div class="kpi"><span>Sessions actives</span><strong id="k-active">—</strong><em>en cours</em></div>
    </div>

    <h2>Consommation Starlink</h2>
    <div class="card">
      <div style="display:flex;justify-content:space-between"><span>Aujourd'hui</span><strong id="u-today">—</strong></div>
      <div class="bar"><i id="u-today-bar" style="width:0"></i></div>
      <div class="muted" id="u-today-note"></div>

      <div style="display:flex;justify-content:space-between;margin-top:14px"><span>Ce mois</span><strong id="u-month">—</strong></div>
      <div class="bar"><i id="u-month-bar" style="width:0"></i></div>
      <div class="muted" id="u-month-note"></div>
    </div>

    <h2>Stock de tickets</h2>
    <div class="card scroll">
      <table><thead><tr><th>Forfait</th><th>Tickets non utilisés</th></tr></thead><tbody id="stock-body"></tbody></table>
      <p class="muted" id="stock-note"></p>
    </div>

    <h2>Dernières ventes</h2>
    <div class="card scroll">
      <table><thead><tr><th>Date</th><th>Forfait</th><th>Montant</th></tr></thead><tbody id="recent-body"></tbody></table>
    </div>
  </section>

  <!-- ============ TICKETS ============ -->
  <section id="tab-tickets">
    <h2>Générer un lot de tickets</h2>
    <div class="card">
      <form id="genForm">
        <label for="gen-plan">Forfait</label>
        <select id="gen-plan" required></select>
        <div class="row2">
          <div>
            <label for="gen-qty">Quantité</label>
            <input id="gen-qty" type="number" min="1" max="1000" value="20" required>
            <p class="muted" style="margin:4px 0 0">Au-delà de 300 tickets, le PDF prend quelques secondes.</p>
          </div>
          <div>
            <label for="gen-price">Prix unitaire (FC)</label>
            <input id="gen-price" type="number" min="0" step="100" placeholder="prix du forfait">
          </div>
        </div>
        <label for="gen-note">Note (facultatif)</label>
        <input id="gen-note" type="text" maxlength="120" placeholder="ex. lot revendeur marché">
        <div style="margin:10px 0">
          <label style="display:flex;align-items:center;gap:8px;font-weight:normal;cursor:pointer">
            <input id="gen-test" type="checkbox" style="width:auto;margin:0">
            <span><strong>Lot de test / essai gratuit</strong> (30 personnes, 0 FC, n'impacte pas les rapports de ventes)</span>
          </label>
        </div>
        <div class="actions">
          <button class="primary" id="genBtn" type="submit">Générer le lot</button>
        </div>
        <div class="msg" id="genMsg"></div>
        <div class="actions" id="genResult" style="display:none">
          <button class="ghost" id="printBtn" type="button">Imprimer le PDF (QR)</button>
          <button class="ghost" id="csvBtn" type="button">Télécharger le CSV</button>
          <button class="ghost" id="rscBtn" type="button">Import MikroTik (.rsc)</button>
        </div>
      </form>
    </div>

    <h2>Rechercher un ticket</h2>
    <div class="card">
      <div style="display:flex;gap:8px">
        <input id="searchInput" type="text" placeholder="Code, téléphone, lot…" autocomplete="off">
        <button class="primary" id="searchBtn" type="button">Chercher</button>
      </div>
      <div class="scroll" style="margin-top:10px">
        <table><thead><tr><th>Code / lot</th><th>Forfait</th><th>Statut</th><th>Échéance</th><th></th></tr></thead><tbody id="search-body"></tbody></table>
      </div>
      <p class="muted" id="search-note"></p>
    </div>

    <h2>Lots récents</h2>
    <div class="card scroll">
      <table><thead><tr><th>Lot</th><th>Forfait</th><th>Qté</th><th>Utilisés</th><th>PDF</th></tr></thead><tbody id="batches-body"></tbody></table>
    </div>
  </section>

  <!-- ============ VENTES ============ -->
  <section id="tab-sales">
    <h2>Ce mois par forfait</h2>
    <div class="card scroll">
      <table><thead><tr><th>Forfait</th><th>Tickets</th><th>Montant</th></tr></thead><tbody id="sales-body"></tbody></table>
    </div>
    <div class="card">
      <p class="muted">Les montants sont enregistrés à l'activation d'un ticket. Les tickets générés mais non vendus n'apparaissent pas.</p>
    </div>
  </section>

  <!-- ============ SESSIONS ============ -->
  <section id="tab-sessions">
    <h2>Sessions actives</h2>
    <div class="card scroll">
      <table><thead><tr><th>Code</th><th>Forfait</th><th>Temps restant</th><th>Données</th><th></th></tr></thead><tbody id="sessions-body"></tbody></table>
      <p class="muted" id="sessions-note"></p>
    </div>
  </section>

  <!-- ============ CONSOMMATION & STARLINK ============ -->
  <section id="tab-usage">
    <h2>Suivi du quota Starlink</h2>
    <div class="card">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px">
        <span>Volume WAN consommé ce mois</span>
        <strong id="usage-wan-vol">—</strong>
      </div>
      <div class="bar"><div id="usage-wan-bar" style="width:0%"></div></div>
      <p class="muted" id="usage-wan-note" style="margin-top:6px">Limite mensuelle : 500 Go (Starlink Kitutu)</p>
    </div>

    <h2>Synchroniser l'usage depuis le routeur MikroTik</h2>
    <div class="card">
      <p>Pour remonter la consommation réelle (octets, uptime) de chaque ticket et actualiser les moyennes :</p>
      <ol style="margin:8px 0 14px;padding-left:20px;font-size:14px;line-height:1.6">
        <li>Sur le routeur, lancez : <code>/import file-name=export-usage.rsc</code> (ou attendez la tâche planifiée).</li>
        <li>Dans WinBox &gt; <strong>Files</strong>, téléchargez le fichier <code>zinb_usage.csv</code>.</li>
        <li>Chargez ce fichier ci-dessous ou collez son contenu, puis validez.</li>
      </ol>
      <input id="usageCsvFile" type="file" accept=".csv,text/plain" style="margin-bottom:10px">
      <textarea id="usageCsvText" rows="5" placeholder="Ou collez ici le contenu de zinb_usage.csv…" style="width:100%;font-family:monospace;font-size:12px;padding:8px;border:1px solid #ddd6fe;border-radius:8px"></textarea>
      <div class="actions" style="margin-top:10px">
        <button class="primary" id="importUsageBtn" type="button">Importer l'usage du routeur</button>
      </div>
      <div class="msg" id="importUsageMsg"></div>
    </div>

    <h2>Consommation moyenne constatée par forfait</h2>
    <div class="card scroll">
      <table>
        <thead><tr><th>Forfait</th><th>Tickets analysés</th><th>Volume moyen / ticket</th><th>Temps moyen / ticket</th></tr></thead>
        <tbody id="usage-stats-body"><tr><td colspan="4" class="muted">Aucune donnée importée pour l'instant</td></tr></tbody>
      </table>
    </div>

    <h2>Parade Reset / Restauration du routeur</h2>
    <div class="card">
      <p><strong>En cas de réinitialisation ou restauration d'une ancienne sauvegarde du routeur :</strong> les tickets déjà consommés redeviendraient neufs sur le hotspot. Ce script généré à partir de la base supprime d'un coup tous les tickets déjà utilisés, expirés ou révoqués.</p>
      <div class="actions">
        <a href="?download_consumed_rsc=1" class="ghost" style="display:inline-block;padding:12px 18px;text-decoration:none;font-weight:700">Télécharger le script de purge (.rsc)</a>
      </div>
      <p class="muted">À importer dans New Terminal sur le routeur : <code>/import file-name=zinbconnect_purge_consommes.rsc</code></p>
    </div>
  </section>

  <!-- ============ REVENDEURS ============ -->
  <section id="tab-resellers">
    <h2>Revendeurs</h2>
    <div class="card scroll">
      <table><thead><tr><th>Nom</th><th>Téléphone</th><th>Ventes</th><th>Statut</th><th></th></tr></thead><tbody id="resellers-body"></tbody></table>
    </div>
    <h2>Ajouter un revendeur</h2>
    <div class="card">
      <form id="resellerForm">
        <label for="r-name">Nom</label>
        <input id="r-name" type="text" maxlength="120" required>
        <label for="r-phone">Téléphone (identifiant de connexion)</label>
        <input id="r-phone" type="tel" placeholder="+243…" required>
        <label for="r-email">E-mail (facultatif)</label>
        <input id="r-email" type="email">
        <label for="r-pass">Mot de passe (8 caractères minimum)</label>
        <input id="r-pass" type="password" minlength="8" required>
        <div class="actions"><button class="primary" type="submit">Créer le revendeur</button></div>
        <div class="msg" id="resellerMsg"></div>
      </form>
    </div>
  </section>

  <!-- ============ RÉGLAGES ============ -->
  <section id="tab-settings">
    <h2>Forfaits et plafonds de données</h2>
    <div class="card scroll">
      <table><thead><tr><th>Forfait</th><th>Prix (FC)</th><th>Durée</th><th>Plafond (Mo)</th><th></th></tr></thead><tbody id="plans-body"></tbody></table>
      <p class="muted">Plafond vide = illimité. Valeurs conseillées : 1 h = 100 Mo, 12 h = 300 Mo, 24 h = 500 Mo, 3 j = 1 024 Mo.</p>
      <div class="msg" id="plansMsg"></div>
    </div>

    <h2>Portail et Starlink</h2>
    <div class="card">
      <form id="settingsForm">
        <label for="s-name">Nom du portail</label>
        <input id="s-name" type="text" maxlength="80">
        <label for="s-url">Adresse du portail (pour les QR codes)</label>
        <input id="s-url" type="url" placeholder="http://10.5.50.1/login">
        <div class="row2">
          <div>
            <label for="s-quota">Forfait Starlink (Go/mois)</label>
            <input id="s-quota" type="number" min="1" value="500">
          </div>
          <div>
            <label for="s-comm">Commission revendeur (%)</label>
            <input id="s-comm" type="number" min="0" max="90" value="25">
          </div>
          <div>
            <label for="s-stock">Alerte stock (tickets)</label>
            <input id="s-stock" type="number" min="1" max="500" value="20">
          </div>
        </div>
        <div class="actions"><button class="primary" type="submit">Enregistrer</button></div>
        <div class="msg" id="settingsMsg"></div>
      </form>
    </div>

    <h2>Mon compte</h2>
    <div class="card">
      <form id="pwForm">
        <label for="pw-cur">Mot de passe actuel</label>
        <input id="pw-cur" type="password" autocomplete="current-password" required>
        <label for="pw-new">Nouveau mot de passe (10 caractères minimum)</label>
        <input id="pw-new" type="password" autocomplete="new-password" minlength="10" required>
        <label for="pw-conf">Confirmer le nouveau mot de passe</label>
        <input id="pw-conf" type="password" autocomplete="new-password" minlength="10" required>
        <div class="actions"><button class="primary" type="submit">Changer le mot de passe</button></div>
        <div class="msg" id="pwMsg"></div>
      </form>
    </div>
  </section>

  <!-- ============ SAUVEGARDE ============ -->
  <section id="tab-backup">
    <h2>Sauvegarde</h2>
    <div class="card">
      <p>Téléchargez une copie complète de la base (structure + données) dans un fichier texte. Conservez-la hors ligne : c'est votre seul filet de sécurité en cas de panne du serveur.</p>
      <div class="actions">
        <button class="primary" id="backupBtn" type="button">Télécharger la sauvegarde</button>
      </div>
      <div class="msg" id="backupMsg"></div>
      <p class="muted">Conseil : une sauvegarde par semaine, et avant chaque mise à jour.</p>
    </div>

    <h2>Restauration</h2>
    <div class="card">
      <p><strong>Attention :</strong> la restauration remplace toutes les données actuelles. Une sauvegarde automatique est faite avant, mais vérifiez le fichier choisi.</p>
      <input id="restoreFile" type="file" accept=".sql,text/plain">
      <div class="actions">
        <button class="danger" id="restoreBtn" type="button">Restaurer ce fichier</button>
      </div>
      <div class="msg" id="restoreMsg"></div>
    </div>

    <h2>Réinitialisation complète</h2>
    <div class="card danger-zone">
      <p><strong>Effacer toutes les données et repartir de zéro :</strong> tickets, lots, ventes, sessions, consommation, journal et compteurs de sécurité. Les comptes (administrateur, revendeurs), les forfaits et les réglages sont conservés.</p>
      <p class="muted">Une sauvegarde automatique de la base est enregistrée dans le dossier <code>backups/</code> avant l'effacement. Action irréversible.</p>
      <label for="reset-pass">Votre mot de passe administrateur</label>
      <input id="reset-pass" type="password" autocomplete="current-password">
      <label for="reset-confirm">Tapez EFFACER pour confirmer</label>
      <input id="reset-confirm" type="text" placeholder="EFFACER" autocomplete="off">
      <div class="actions"><button class="danger" id="resetBtn" type="button">Tout effacer</button></div>
      <div class="msg" id="resetMsg"></div>
    </div>
  </section>

  <!-- ============ JOURNAL ============ -->
  <section id="tab-log">
    <h2>Journal d'audit</h2>
    <div class="card scroll">
      <table><thead><tr><th>Date</th><th>Événement</th><th>Acteur</th><th>IP</th><th>Détail</th></tr></thead><tbody id="log-body"></tbody></table>
    </div>
  </section>
</main>

<div id="toasts" aria-live="polite"></div>

<div class="loading" id="loading" role="status" aria-live="polite">
  <div class="box"><div class="spin"></div><div id="loadingText">Traitement en cours…</div></div>
</div>

<dialog id="confirmDlg">
  <div class="body">
    <h2 style="margin:0 0 8px">Confirmation</h2>
    <p id="confirmText" style="margin:0 0 14px"></p>
    <div class="actions">
      <button class="primary" id="confirmYes" type="button">Confirmer</button>
      <button class="ghost" id="confirmNo" type="button">Annuler</button>
    </div>
  </div>
</dialog>

<script>
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var $ = function (id) { return document.getElementById(id); };
  var state = { plans: [], lastBatch: null };

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function fc(n) { return Number(n || 0).toLocaleString('fr-FR') + ' FC'; }
  function bytes(b) {
    if (b === null || b === undefined || b === '') return 'illimité';
    var u = ['o','Ko','Mo','Go'], i = 0, v = Number(b);
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return v.toFixed(i === 0 ? 0 : 1).replace('.', ',') + ' ' + u[i];
  }
  function duration(sec) {
    sec = Math.max(0, Math.floor(sec));
    var j = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
    if (j > 0) return j + ' j ' + h + ' h';
    if (h > 0) return h + ' h ' + String(m).padStart(2, '0');
    return m + ' min';
  }
  function dt(s) {
    if (!s) return '—';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? esc(s) : d.toLocaleString('fr-FR', {day:'2-digit', month:'2-digit', year:'2-digit', hour:'2-digit', minute:'2-digit'});
  }
  function chip(st) {
    return '<span class="chip s-' + esc(st) + '">' + esc(stateLabel(st)) + '</span>';
  }
  function stateLabel(st) {
    return {available:'Non utilisé', active:'En cours', used:'Consommé', expired:'Expiré', revoked:'Révoqué'}[st] || st;
  }
  /* Confirmation de succès : pop-up temporaire (disparaît toute seule).
     Erreurs : message en place, qui reste affiché pour pouvoir être lu. */
  function showMsg(el, text, ok) {
    if (ok) { toast(text); el.className = 'msg'; el.textContent = ''; return; }
    el.className = 'msg show ko';
    el.textContent = text;
  }
  function toast(text) {
    var t = document.createElement('div');
    t.className = 'toast';
    t.setAttribute('role', 'status');
    t.textContent = text;
    $('toasts').appendChild(t);
    setTimeout(function () { t.classList.add('hide'); }, 4200);
    setTimeout(function () { t.remove(); }, 4700);
  }

  var busyCount = 0;
  function busy(on, text) {
    busyCount += on ? 1 : -1;
    if (busyCount > 0) {
      $('loadingText').textContent = text || 'Traitement en cours…';
      $('loading').classList.add('on');
    } else {
      busyCount = 0;
      $('loading').classList.remove('on');
    }
  }

  async function api(action, payload, timeoutMs) {
    var ctrl = new AbortController();
    var to = setTimeout(function () { ctrl.abort(); }, timeoutMs || 60000);
    try {
      var res = await fetch('admin.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify(Object.assign({action: action, csrf: csrf}, payload || {})),
        signal: ctrl.signal
      });
      if (res.status === 403) { location.reload(); throw new Error('session'); }
      return await res.json();
    } finally {
      clearTimeout(to);
    }
  }

  /** Télécharge le PDF d'un lot sans base64 (aucun blocage de l'écran). */
  async function downloadPdf(batchId) {
    busy(true, 'Préparation du PDF…');
    try {
      var res = await fetch('admin.php?pdf=' + encodeURIComponent(batchId), {headers: {'Accept': 'application/pdf'}});
      if (!res.ok) {
        var txt = await res.text();
        throw new Error(txt || ('Erreur ' + res.status));
      }
      var cd = res.headers.get('Content-Disposition') || '';
      var m = /filename="([^"]+)"/.exec(cd);
      var blob = await res.blob();
      download(m ? m[1] : ('tickets_' + batchId + '.pdf'), blob, 'application/pdf');
      return true;
    } catch (err) {
      alert(err.message || 'PDF impossible.');
      return false;
    } finally {
      busy(false);
    }
  }

  /** Télécharge le fichier d'import MikroTik (.rsc) d'un lot. */
  async function downloadRsc(batchId) {
    busy(true, 'Préparation du fichier…');
    try {
      var res = await fetch('admin.php?rsc=' + encodeURIComponent(batchId), {headers: {'Accept': 'application/octet-stream'}});
      if (!res.ok) {
        var txt = await res.text();
        throw new Error(txt || ('Erreur ' + res.status));
      }
      var cd = res.headers.get('Content-Disposition') || '';
      var m = /filename="([^"]+)"/.exec(cd);
      var blob = await res.blob();
      download(m ? m[1] : ('import_' + batchId + '.rsc'), blob, 'application/octet-stream');
      return true;
    } catch (err) {
      alert(err.message || 'Export MikroTik impossible');
      return false;
    } finally {
      busy(false);
    }
  }

  function download(filename, content, type) {
    var blob = content instanceof Blob ? content : new Blob([content], {type: type || 'text/plain;charset=utf-8'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  }

  function confirmBox(text) {
    return new Promise(function (resolve) {
      $('confirmText').textContent = text;
      var dlg = $('confirmDlg');
      dlg.showModal();
      $('confirmYes').onclick = function () { dlg.close(); resolve(true); };
      $('confirmNo').onclick = function () { dlg.close(); resolve(false); };
      dlg.addEventListener('close', function once() { dlg.removeEventListener('close', once); resolve(false); });
    });
  }

  // ---------- Navigation ----------
  var nav = $('nav');
  nav.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-tab]');
    if (!btn) return;
    Array.prototype.forEach.call(nav.querySelectorAll('button'), function (b) { b.classList.toggle('active', b === btn); });
    Array.prototype.forEach.call(document.querySelectorAll('main section'), function (s) {
      s.classList.toggle('active', s.id === 'tab-' + btn.dataset.tab);
    });
    loadTab(btn.dataset.tab);
  });

  function loadTab(tab) {
    if (tab === 'dash') loadDashboard();
    if (tab === 'tickets') { loadPlans(); loadBatches(); }
    if (tab === 'sales') loadSales();
    if (tab === 'sessions') loadSessions();
    if (tab === 'usage') loadUsageStats();
    if (tab === 'resellers') loadResellers();
    if (tab === 'settings') { loadPlans(); loadSettings(); }
    if (tab === 'log') loadLog();
  }

  // ---------- Tableau de bord ----------
  async function loadDashboard() {
    var d = await api('dashboard');
    if (!d.success) return;
    $('k-today').textContent = fc(d.sales.today.total);
    $('k-today-n').textContent = d.sales.today.count + ' ticket(s)';
    $('k-week').textContent = fc(d.sales.week.total);
    $('k-week-n').textContent = d.sales.week.count + ' ticket(s)';
    $('k-month').textContent = fc(d.sales.month.total);
    $('k-month-n').textContent = d.sales.month.count + ' ticket(s)';
    $('k-active').textContent = d.active_count;

    $('u-today').textContent = bytes(d.usage.today) + ' / ' + bytes(d.usage.quota);
    $('u-today-bar').style.width = Math.min(100, d.usage.today_pct) + '%';
    $('u-today-bar').className = d.usage.today_pct > 90 ? 'ko' : (d.usage.today_pct > 70 ? 'warn' : '');
    $('u-today-note').textContent = d.usage.today_pct + ' % du forfait mensuel' +
      (d.usage.today === 0 ? ' · estimation tickets : ' + bytes(d.usage.today_est) : ' (mesuré sur le routeur)');

    $('u-month').textContent = bytes(d.usage.month) + ' / ' + bytes(d.usage.quota);
    $('u-month-bar').style.width = Math.min(100, d.usage.month_pct) + '%';
    $('u-month-bar').className = d.usage.month_pct > 90 ? 'ko' : (d.usage.month_pct > 70 ? 'warn' : '');
    $('u-month-note').textContent = d.usage.month_pct + ' % consommé' +
      (d.usage.month === 0 ? ' · estimation tickets : ' + bytes(d.usage.month_est) : '');

    $('stock-body').innerHTML = d.stock.map(function (s) {
      var low = Number(s.available) <= d.low_stock;
      return '<tr><td>' + esc(s.title) + '</td><td>' + esc(s.available) +
        ' ' + (low ? '<span class="chip s-used">stock bas</span>' : '') + '</td></tr>';
    }).join('') || '<tr><td colspan="2" class="muted">Aucun forfait</td></tr>';
    $('stock-note').textContent = 'Seuil d\'alerte : ' + d.low_stock + ' tickets.';

    $('recent-body').innerHTML = d.recent.map(function (r) {
      return '<tr><td>' + dt(r.created_at) + '</td><td>' + esc(r.plan_title || '—') + '</td><td>' + fc(r.amount) + '</td></tr>';
    }).join('') || '<tr><td colspan="3" class="muted">Aucune vente enregistrée</td></tr>';
  }

  function loadSales() {
    api('dashboard').then(function (d) {
      if (!d.success) return;
      $('sales-body').innerHTML = (d.sales.by_plan || []).map(function (p) {
        return '<tr><td>' + esc(p.title) + '</td><td>' + p.nb + '</td><td>' + fc(p.total) + '</td></tr>';
      }).join('') || '<tr><td colspan="3" class="muted">Aucune vente ce mois</td></tr>';
    });
  }

  // ---------- Forfaits ----------
  /** Recale le nom et le prix affichés partout (liste, sélecteur de lot). */
  function syncPlanLabels(plans) {
    state.plans = plans || [];
    var current = $('gen-plan').value;
    $('gen-plan').innerHTML = state.plans.map(function (p) {
      return '<option value="' + p.id + '" data-rate="' + p.rate + '">' + esc(p.label) + '</option>';
    }).join('');
    if (current) $('gen-plan').value = current;
    Array.prototype.forEach.call(document.querySelectorAll('#plans-body tr'), function (tr) {
      var p = state.plans.filter(function (x) { return String(x.id) === tr.dataset.id; })[0];
      if (p) tr.querySelector('.p-name').textContent = p.title;
    });
  }

  function bindPlanSaves() {
    Array.prototype.forEach.call(document.querySelectorAll('.p-save'), function (btn) {
      btn.onclick = async function () {
        var tr = btn.closest('tr');
        btn.disabled = true;
        try {
          var r = await api('save_plan', {
            id: tr.dataset.id,
            rate: tr.querySelector('.p-rate').value,
            duration_minutes: tr.querySelector('.p-min').value,
            duration_days: tr.querySelector('.p-days').value,
            data_limit_mb: tr.querySelector('.p-limit').value
          });
          if (r.success) {
            showMsg($('plansMsg'), 'Forfait enregistré : ' + (r.label || '') + '. Le portail et les tickets affichent déjà ce prix.', true);
            syncPlanLabels(r.plans || []);
          } else {
            showMsg($('plansMsg'), r.message === 'invalidDuration' ? 'Indiquez une durée en minutes ou en jours.' : 'Enregistrement impossible.', false);
          }
        } catch (err) {
          showMsg($('plansMsg'), 'Le serveur ne répond pas. Réessayez.', false);
        }
        btn.disabled = false;
      };
    });
  }

  async function loadPlans() {
    var d = await api('plans');
    if (!d.success) return;
    $('plans-body').innerHTML = d.plans.map(function (p) {
      var mins = Number(p.duration_minutes || 0), days = Number(p.duration_days || 0);
      var limitMb = p.data_limit_bytes ? Math.round(Number(p.data_limit_bytes) / 1048576) : '';
      return '<tr data-id="' + p.id + '">' +
        '<td class="p-name">' + esc(p.title) + '</td>' +
        '<td><input class="p-rate" type="number" min="0" step="100" value="' + p.rate + '" style="width:90px"></td>' +
        '<td><div style="display:flex;gap:4px">' +
          '<input class="p-min" type="number" min="0" value="' + mins + '" style="width:64px" title="minutes">min ' +
          '<input class="p-days" type="number" min="0" value="' + days + '" style="width:52px" title="jours">j</div></td>' +
        '<td><input class="p-limit" type="number" min="0" step="50" value="' + limitMb + '" style="width:80px" placeholder="∞"></td>' +
        '<td><button class="ghost p-save" type="button">OK</button></td>' +
      '</tr>';
    }).join('');
    syncPlanLabels(d.plans);
    if (!$('gen-price').value && d.plans.length) $('gen-price').value = d.plans[0].rate;
    bindPlanSaves();
  }

  $('gen-plan').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    if (opt && opt.dataset.rate && (!($('gen-test') && $('gen-test').checked))) {
      $('gen-price').value = opt.dataset.rate;
    }
  });

  if ($('gen-test')) {
    $('gen-test').onchange = function () {
      if (this.checked) {
        $('gen-price').value = 0;
        $('gen-price').disabled = true;
        $('gen-qty').value = 30;
      } else {
        $('gen-price').disabled = false;
        var opt = $('gen-plan').options[$('gen-plan').selectedIndex];
        if (opt && opt.dataset.rate) $('gen-price').value = opt.dataset.rate;
        $('gen-qty').value = 20;
      }
    };
  }

  // ---------- Génération de lot ----------
  $('genForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var qty = Number($('gen-qty').value);
    $('genBtn').disabled = true;
    busy(true, 'Génération de ' + qty + ' ticket(s)…');
    try {
      var r = await api('generate_batch', {
        subscription_id: Number($('gen-plan').value),
        quantity: qty,
        unit_price: $('gen-test') && $('gen-test').checked ? 0 : $('gen-price').value,
        note: $('gen-note').value,
        is_test: $('gen-test') && $('gen-test').checked ? 1 : 0
      }, 180000);
      if (!r.ok) {
        showMsg($('genMsg'), 'Génération impossible. Vérifiez le forfait et la quantité.', false);
      } else {
        state.lastBatch = r.batch_id;
        showMsg($('genMsg'), 'Lot ' + r.reference + ' : ' + r.codes.length + ' ticket(s) générés. Téléchargez le PDF avant de fermer.', true);
        $('genResult').style.display = 'flex';
        loadBatches();
        loadDashboard();
      }
    } catch (err) {
      showMsg($('genMsg'), 'Le serveur n\'a pas répondu à temps. Vérifiez la quantité, puis réessayez.', false);
    }
    busy(false);
    $('genBtn').disabled = false;
  });

  $('printBtn').onclick = function () {
    if (state.lastBatch) downloadPdf(state.lastBatch);
  };

  $('csvBtn').onclick = async function () {
    if (!state.lastBatch) return;
    var r = await api('export_batch', {batch_id: state.lastBatch});
    if (!r.success) { showMsg($('genMsg'), 'Export CSV impossible.', false); return; }
    download(r.filename, r.csv, 'text/csv;charset=utf-8');
  };

  $('rscBtn').onclick = function () {
    if (state.lastBatch) downloadRsc(state.lastBatch);
  };

  async function loadBatches() {
    var d = await api('batches');
    if (!d.success) return;
    $('batches-body').innerHTML = d.batches.map(function (b) {
      return '<tr><td>' + esc(b.reference) + '<div class="muted">' + dt(b.created_at) + '</div></td>' +
        '<td>' + esc(b.plan_title || '—') + '</td>' +
        '<td>' + b.tickets_count + '</td><td>' + b.tickets_used + '</td>' +
        '<td><button class="ghost b-print" type="button" data-id="' + b.id + '">PDF</button> ' +
        '<button class="ghost b-rsc" type="button" data-id="' + b.id + '">Import MikroTik</button> ' +
        '<button class="danger b-del" type="button" data-id="' + b.id + '">Suppr.</button></td></tr>';
    }).join('') || '<tr><td colspan="5" class="muted">Aucun lot</td></tr>';
    Array.prototype.forEach.call(document.querySelectorAll('.b-print'), function (btn) {
      btn.onclick = function () { downloadPdf(Number(btn.dataset.id)); };
    });
    Array.prototype.forEach.call(document.querySelectorAll('.b-rsc'), function (btn) {
      btn.onclick = function () { downloadRsc(Number(btn.dataset.id)); };
    });
    Array.prototype.forEach.call(document.querySelectorAll('.b-del'), function (btn) {
      btn.onclick = async function () {
        var ok = await confirmBox('Supprimer ce lot et ses tickets non utilisés ? Un lot contenant un ticket déjà activé ne peut pas être supprimé.');
        if (!ok) return;
        btn.disabled = true;
        var r = await api('delete_batch', {batch_id: Number(btn.dataset.id)});
        if (r.success) {
          loadBatches();
          loadDashboard();
        } else if (r.message === 'batchInUse') {
          alert('Ce lot contient ' + r.used + ' ticket(s) déjà activé(s). Révoquez-les d\'abord, ou gardez le lot.');
        } else {
          alert('Suppression impossible.');
        }
        btn.disabled = false;
      };
    });
  }

  // ---------- Recherche ----------
  async function doSearch() {
    var q = $('searchInput').value.trim();
    if (!q) return;
    var d = await api('search', {q: q});
    $('search-body').innerHTML = (d.tickets || []).map(function (t) {
      var ident = t.code_plain ? t.code_plain : ('Ticket #' + t.id);
      var sub = t.batch_ref ? 'Lot ' + t.batch_ref : '';
      return '<tr><td><strong>' + esc(ident) + '</strong><div class="muted">' + esc(sub) +
        (t.contact ? ' · ' + esc(t.contact) : '') + '</div></td>' +
        '<td>' + esc(t.plan_title || '—') + '</td>' +
        '<td>' + chip(t.state) + '</td>' +
        '<td>' + (t.expires_at ? dt(t.expires_at) : '—') + '</td>' +
        '<td>' + (t.state === 'revoked' ? '' : '<button class="danger t-revoke" type="button" data-id="' + t.id + '">Révoquer</button>') + '</td></tr>';
    }).join('');
    $('search-note').textContent = (d.tickets || []).length ? '' : 'Aucun ticket trouvé.';
    bindRevoke();
  }
  $('searchBtn').onclick = doSearch;
  $('searchInput').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

  function bindRevoke() {
    Array.prototype.forEach.call(document.querySelectorAll('.t-revoke'), function (btn) {
      btn.onclick = async function () {
        var ok = await confirmBox('Révoquer ce ticket ? Il ne pourra plus être utilisé, même s\'il reste du temps.');
        if (!ok) return;
        var r = await api('revoke', {id: btn.dataset.id});
        if (r.success) { btn.closest('tr').remove(); loadSessions(); }
        else alert('Révocation impossible : ticket introuvable ou déjà révoqué.');
      };
    });
  }

  // ---------- Sessions ----------
  async function loadSessions() {
    var d = await api('sessions');
    if (!d.success) return;
    $('sessions-body').innerHTML = (d.sessions || []).map(function (t) {
      var code = t.code_plain || ('#' + t.id);
      var limit = t.data_limit_bytes ? bytes(t.data_limit_bytes) : 'illimité';
      return '<tr><td><strong>' + esc(code) + '</strong></td>' +
        '<td>' + esc(t.plan_title || '—') + '</td>' +
        '<td>' + duration(t.remaining) + '</td>' +
        '<td>' + bytes(t.bytes_used) + ' / ' + limit + '</td>' +
        '<td><button class="danger s-revoke" type="button" data-id="' + t.id + '">Révoquer</button></td></tr>';
    }).join('');
    $('sessions-note').textContent = (d.sessions || []).length ? '' : 'Aucune session active.';
    Array.prototype.forEach.call(document.querySelectorAll('.s-revoke'), function (btn) {
      btn.onclick = async function () {
        var ok = await confirmBox('Révoquer cette session ? Le client sera déconnecté au prochain contrôle.');
        if (!ok) return;
        var r = await api('revoke', {id: btn.dataset.id});
        if (r.success) loadSessions();
      };
    });
  }

  // ---------- Revendeurs ----------
  async function loadResellers() {
    var d = await api('resellers');
    if (!d.success) return;
    $('resellers-body').innerHTML = (d.resellers || []).map(function (r) {
      return '<tr><td>' + esc(r.name) + '<div class="muted">' + esc(r.email || '') + '</div></td>' +
        '<td>' + esc(r.phone) + '</td>' +
        '<td>' + r.sales + '<div class="muted">' + fc(r.commission) + '</div></td>' +
        '<td>' + (Number(r.status) ? '<span class="chip s-active">Actif</span>' : '<span class="chip s-expired">En attente</span>') + '</td>' +
        '<td><button class="ghost r-toggle" type="button" data-id="' + r.id + '">' + (Number(r.status) ? 'Désactiver' : 'Activer') + '</button></td></tr>';
    }).join('') || '<tr><td colspan="5" class="muted">Aucun revendeur</td></tr>';
    Array.prototype.forEach.call(document.querySelectorAll('.r-toggle'), function (btn) {
      btn.onclick = async function () {
        await api('toggle_reseller', {id: Number(btn.dataset.id)});
        loadResellers();
      };
    });
  }

  $('resellerForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var r = await api('create_reseller', {
      name: $('r-name').value, phone: $('r-phone').value,
      email: $('r-email').value, password: $('r-pass').value
    });
    if (r.success) {
      showMsg($('resellerMsg'), 'Revendeur créé.', true);
      $('resellerForm').reset();
      loadResellers();
    } else {
      showMsg($('resellerMsg'), r.message === 'alreadyExists' ? 'Ce numéro existe déjà.' : 'Vérifiez le nom, le téléphone et le mot de passe (8 caractères).', false);
    }
  });

  // ---------- Réglages ----------
  async function loadSettings() {
    var d = await api('settings');
    if (!d.success) return;
    $('s-name').value = d.settings.portal_name || '';
    $('s-url').value = d.settings.portal_url || '';
    $('s-quota').value = d.settings.starlink_quota_gb;
    $('s-comm').value = d.settings.reseller_commission;
    $('s-stock').value = d.settings.low_stock_threshold;
  }
  $('settingsForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var r = await api('save_settings', {
      portal_name: $('s-name').value,
      portal_url: $('s-url').value,
      starlink_quota_gb: $('s-quota').value,
      reseller_commission: $('s-comm').value,
      low_stock_threshold: $('s-stock').value
    });
    showMsg($('settingsMsg'), r.success ? 'Réglages enregistrés.' : 'Enregistrement impossible.', r.success);
  });

  // ---------- Consommation & Starlink ----------
  async function loadUsageStats() {
    var d = await api('dashboard');
    if (!d.success) return;
    var u = d.usage;
    $('usage-wan-vol').textContent = bytes(u.month) + ' / ' + bytes(u.quota);
    $('usage-wan-bar').style.width = Math.min(100, u.month_pct) + '%';
    $('usage-wan-bar').className = u.month_pct > 90 ? 'ko' : (u.month_pct > 70 ? 'warn' : '');
    $('usage-wan-note').textContent = u.month_pct + ' % consommé du forfait Starlink (500 Go). ' +
      (u.month === 0 ? 'Estimation tickets : ' + bytes(u.month_est) : '(Mesuré sur l\'interface WAN)');
  }

  if ($('usageCsvFile')) {
    $('usageCsvFile').onchange = function () {
      var file = this.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function (e) {
        $('usageCsvText').value = e.target.result;
      };
      reader.readAsText(file);
    };
  }

  if ($('importUsageBtn')) {
    $('importUsageBtn').onclick = async function () {
      var csv = $('usageCsvText').value.trim();
      if (!csv) {
        showMsg($('importUsageMsg'), 'Veuillez coller le contenu ou choisir le fichier zinb_usage.csv.', false);
        return;
      }
      $('importUsageBtn').disabled = true;
      busy(true, 'Traitement de l\'usage du routeur…');
      try {
        var r = await api('import_usage', {csv_content: csv});
        if (r.success) {
          showMsg($('importUsageMsg'), 'Succès : ' + r.updated + ' ticket(s) mis à jour. WAN RX=' + bytes(r.wan_rx) + ' TX=' + bytes(r.wan_tx) + ' (Total ' + r.wan_total_fmt + ').', true);
          if (r.stats && r.stats.length) {
            $('usage-stats-body').innerHTML = r.stats.map(function (s) {
              return '<tr><td><strong>' + esc(s.name) + '</strong></td><td>' + s.tickets_count + ' ticket(s)</td><td>' + s.avg_bytes_fmt + '</td><td>' + s.avg_uptime_fmt + '</td></tr>';
            }).join('');
          }
          loadUsageStats();
          loadDashboard();
        } else {
          showMsg($('importUsageMsg'), 'Erreur lors de l\'import : ' + (r.message || 'fichier invalide'), false);
        }
      } catch (err) {
        showMsg($('importUsageMsg'), 'Erreur de communication : ' + err.message, false);
      } finally {
        busy(false);
        $('importUsageBtn').disabled = false;
      }
    };
  }

  // ---------- Sauvegarde / restauration ----------
  $('backupBtn').onclick = async function () {
    $('backupBtn').disabled = true;
    var r = await api('backup_sql');
    $('backupBtn').disabled = false;
    if (!r.success) { showMsg($('backupMsg'), 'Sauvegarde impossible.', false); return; }
    download(r.filename, r.sql, 'application/sql');
    showMsg($('backupMsg'), 'Sauvegarde téléchargée : ' + r.filename, true);
  };

  $('restoreBtn').onclick = async function () {
    var file = $('restoreFile').files[0];
    if (!file) { showMsg($('restoreMsg'), 'Choisissez d\'abord un fichier .sql.', false); return; }
    var ok = await confirmBox('Restaurer ' + file.name + ' ? Toutes les données actuelles seront remplacées.');
    if (!ok) return;
    var text = await file.text();
    $('restoreBtn').disabled = true;
    var r = await api('restore', {sql: text});
    $('restoreBtn').disabled = false;
    if (r.ok) {
      showMsg($('restoreMsg'), 'Restauration terminée (' + r.statements + ' instructions). Sauvegarde de sécurité : ' + r.safety_file, true);
    } else {
      showMsg($('restoreMsg'), 'Restauration refusée ou incomplète (fichier non reconnu ?).', false);
    }
  };

  // ---------- Journal ----------
  async function loadLog() {
    var d = await api('audit');
    if (!d.success) return;
    $('log-body').innerHTML = (d.events || []).map(function (e) {
      return '<tr><td>' + dt(e.created_at) + '</td><td>' + esc(e.event) + '</td><td>' + esc(e.actor || '') +
        '</td><td>' + esc(e.ip || '') + '</td><td class="muted">' + esc(e.details || '') + '</td></tr>';
    }).join('') || '<tr><td colspan="5" class="muted">Journal vide</td></tr>';
  }

  // ---------- Mon compte : mot de passe ----------
  $('pwForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var msg = $('pwMsg');
    if ($('pw-new').value !== $('pw-conf').value) {
      showMsg(msg, 'Les deux nouveaux mots de passe ne correspondent pas.', false);
      return;
    }
    busy(true, 'Enregistrement…');
    try {
      var r = await api('change_password', {
        current: $('pw-cur').value,
        new: $('pw-new').value,
        confirm: $('pw-conf').value
      });
      if (r.success) {
        csrf = r.csrf;
        document.querySelector('meta[name="csrf-token"]').content = r.csrf;
        $('pwForm').reset();
        showMsg(msg, 'Mot de passe changé. Il sera demandé à la prochaine connexion.', true);
      } else {
        var err = {
          wrongPassword: 'Mot de passe actuel incorrect.',
          tooShort: 'Le nouveau mot de passe doit faire au moins 10 caractères.',
          samePassword: 'Choisissez un mot de passe différent de l\'ancien.',
          mismatch: 'Les deux nouveaux mots de passe ne correspondent pas.'
        }[r.message] || 'Changement impossible.';
        showMsg(msg, err, false);
      }
    } catch (err) {
      showMsg(msg, 'Le serveur ne répond pas. Réessayez.', false);
    }
    busy(false);
  });

  // ---------- Réinitialisation complète ----------
  $('resetBtn').onclick = async function () {
    var ok = await confirmBox('Effacer TOUTES les données (tickets, lots, ventes, sessions, consommation, journal) ? Les comptes, les forfaits et les réglages sont conservés. Une sauvegarde automatique est créée avant.');
    if (!ok) return;
    busy(true, 'Effacement en cours…');
    try {
      var r = await api('reset_system', {
        password: $('reset-pass').value,
        confirm: $('reset-confirm').value
      });
      if (r.success) {
        $('reset-pass').value = '';
        $('reset-confirm').value = '';
        showMsg($('resetMsg'), 'Système réinitialisé. Sauvegarde de sécurité : ' + r.safety_file, true);
        loadDashboard();
        loadBatches();
      } else {
        var err = {
          confirmRequired: 'Tapez EFFACER pour confirmer.',
          wrongPassword: 'Mot de passe administrateur incorrect.',
          backupFailed: 'Sauvegarde impossible : effacement annulé par sécurité.',
          resetFailed: 'Effacement incomplet. Vérifiez les droits du dossier.'
        }[r.message] || 'Réinitialisation refusée.';
        showMsg($('resetMsg'), err, false);
      }
    } catch (err) {
      showMsg($('resetMsg'), 'Le serveur ne répond pas. Réessayez.', false);
    }
    busy(false);
  };

  // ---------- Afficher / masquer les mots de passe ----------
  Array.prototype.forEach.call(document.querySelectorAll('input[type=password]'), function (input) {
    if (input.dataset.eye) return;
    input.dataset.eye = '1';
    var wrap = document.createElement('div');
    wrap.className = 'pw';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var eye = document.createElement('button');
    eye.type = 'button';
    eye.className = 'eye';
    eye.textContent = '👁';
    eye.setAttribute('aria-label', 'Afficher le mot de passe');
    eye.onclick = function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      eye.textContent = show ? '🙈' : '👁';
      eye.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    };
    wrap.appendChild(eye);
  });

  $('logoutBtn').onclick = async function () {
    await api('logout');
    location.href = 'reseller.php';
  };

  loadDashboard();
})();
</script>
</body>
</html>

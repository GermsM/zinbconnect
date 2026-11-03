<?php
/**
 * Zinbconnect — console d'administration (français, mobile-first).
 *
 * Une seule page, sans dépendance externe (aucun CDN) :
 *   - tableau de bord : ventes jour/semaine/mois, consommation Starlink ;
 *   - tickets : génération de lots, PDF avec QR, CSV, recherche, révocation ;
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

security_headers();
session_boot();

if (empty($_SESSION['admin_id'])) {
    header('Location: reseller.php');
    exit;
}
$adminId = (int)$_SESSION['admin_id'];
$actor   = 'admin:' . $adminId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_input();
    csrf_require($input);
    $action = (string)($input['action'] ?? '');

    switch ($action) {
        case 'logout':
            logout_current();
            json_out(['success' => true]);

        case 'dashboard':
            $sales    = sales_stats();
            $usage    = usage_totals();
            $estimate = usage_estimate_tickets();
            $active   = active_sessions(500);
            $stock    = stock_by_plan();
            $quota    = max(1, (int)$usage['quota']);

            $recent = db()->query(
                'SELECT tr.id, tr.amount, tr.created_at, tr.buyer_contact, s.name AS plan_name
                 FROM transactions tr LEFT JOIN subscriptions s ON s.id = tr.subscription_id
                 ORDER BY tr.id DESC LIMIT 8'
            )->fetchAll();

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
            $result   = generate_batch($planId, $quantity, $unit, $actor, $note ?: null);
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
            $rate = max(0.0, (float)($input['rate'] ?? $plan['rate']));
            $minutes = max(0, (int)($input['duration_minutes'] ?? 0));
            $days = max(0, (int)($input['duration_days'] ?? 0));
            if ($minutes === 0 && $days === 0) {
                json_out(['success' => false, 'message' => 'invalidDuration']);
            }
            $limitMb = (int)($input['data_limit_mb'] ?? 0);
            $limit = $limitMb > 0 ? $limitMb * 1024 * 1024 : null;

            db()->prepare('UPDATE subscriptions SET rate = ?, duration_minutes = ?, duration_days = ?, data_limit_bytes = ? WHERE id = ?')
                ->execute([$rate, $minutes ?: null, $days ?: null, $limit, $id]);
            audit('plan_updated', $actor, 'plan=' . $id . ' plafond=' . ($limit ? format_bytes($limit) : 'illimité'));
            json_out(['success' => true]);

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
                    str_replace(';', ',', (string)$r['plan_name']),
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

        case 'print_batch':
            $batchId = (int)($input['batch_id'] ?? 0);
            $batch = batch_by_id($batchId);
            if (!$batch) {
                json_out(['success' => false, 'message' => 'invalidBatch']);
            }
            $rows = batch_tickets($batchId);
            $tickets = [];
            $missing = 0;
            foreach ($rows as $r) {
                if (empty($r['code_plain'])) {
                    $missing++;
                    continue;
                }
                $plan = [
                    'duration_minutes' => $r['duration_minutes'] ?? null,
                    'duration_days'    => $r['duration_days'] ?? null,
                ];
                $tickets[] = [
                    'code'     => $r['code_plain'],
                    'plan'     => (string)$r['plan_name'],
                    'price'    => (float)$r['price'],
                    'limit'    => $r['data_limit_bytes'] !== null ? (int)$r['data_limit_bytes'] : null,
                    'duration' => format_duration(plan_duration_seconds($plan)),
                ];
            }
            if (!$tickets) {
                json_out(['success' => false, 'message' => 'noCodes', 'missing' => $missing]);
            }
            $pdf = pdf_ticket_sheet($tickets, [
                'portal_name' => setting('portal_name') ?? 'Zinbconnect',
                'portal_url'  => setting('portal_url', ''),
                'batch'       => $batch['reference'],
                'date'        => date('d/m/Y'),
            ]);
            audit('batch_printed', $actor, 'lot=' . $batch['reference'] . ' tickets=' . count($tickets));
            json_out([
                'success'  => true,
                'filename' => 'tickets_' . $batch['reference'] . '.pdf',
                'pdf'      => base64_encode($pdf),
                'missing'  => $missing,
            ]);
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
.s-expired{background:#f3f4f6;color:#4b5563}
.s-revoked{background:#fee2e2;color:#991b1b}
.bar{height:9px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-top:6px}
.bar i{display:block;height:100%;background:var(--v2)}
.bar i.warn{background:#f59e0b}
.bar i.ko{background:#ef4444}
.msg{display:none;margin-top:10px;padding:11px 12px;border-radius:9px;font-size:14px}
.msg.show{display:block}
.msg.ok{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0}
.msg.ko{background:#fef2f2;color:#7f1d1d;border:1px solid #fecaca}
.muted{color:var(--muted);font-size:12px}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.pill{display:inline-block;background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6;border-radius:8px;padding:4px 9px;font-size:12px;margin:2px 4px 2px 0}
dialog{border:0;border-radius:14px;padding:0;width:min(92vw,420px);box-shadow:0 10px 40px rgba(0,0,0,.25)}
dialog .body{padding:18px}
dialog::backdrop{background:rgba(0,0,0,.45)}
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
          </div>
          <div>
            <label for="gen-price">Prix unitaire (FC)</label>
            <input id="gen-price" type="number" min="0" step="100" placeholder="prix du forfait">
          </div>
        </div>
        <label for="gen-note">Note (facultatif)</label>
        <input id="gen-note" type="text" maxlength="120" placeholder="ex. lot revendeur marché">
        <div class="actions">
          <button class="primary" id="genBtn" type="submit">Générer le lot</button>
        </div>
        <div class="msg" id="genMsg"></div>
        <div class="actions" id="genResult" style="display:none">
          <button class="ghost" id="printBtn" type="button">Imprimer le PDF (QR)</button>
          <button class="ghost" id="csvBtn" type="button">Télécharger le CSV</button>
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
    </div>

    <h2>Portail et Starlink</h2>
    <div class="card">
      <form id="settingsForm">
        <label for="s-name">Nom du portail</label>
        <input id="s-name" type="text" maxlength="80">
        <label for="s-url">Adresse du portail (pour les QR codes)</label>
        <input id="s-url" type="url" placeholder="https://portail.exemple.cd/index.php">
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
  </section>

  <!-- ============ JOURNAL ============ -->
  <section id="tab-log">
    <h2>Journal d'audit</h2>
    <div class="card scroll">
      <table><thead><tr><th>Date</th><th>Événement</th><th>Acteur</th><th>IP</th><th>Détail</th></tr></thead><tbody id="log-body"></tbody></table>
    </div>
  </section>
</main>

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
  function showMsg(el, text, ok) {
    el.className = 'msg show ' + (ok ? 'ok' : 'ko');
    el.textContent = text;
  }

  async function api(action, payload) {
    var res = await fetch('admin.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify(Object.assign({action: action, csrf: csrf}, payload || {}))
    });
    if (res.status === 403) { location.reload(); throw new Error('session'); }
    return res.json();
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
      return '<tr><td>' + esc(s.name) + '</td><td>' + esc(s.available) +
        ' ' + (low ? '<span class="chip s-used">stock bas</span>' : '') + '</td></tr>';
    }).join('') || '<tr><td colspan="2" class="muted">Aucun forfait</td></tr>';
    $('stock-note').textContent = 'Seuil d\'alerte : ' + d.low_stock + ' tickets.';

    $('recent-body').innerHTML = d.recent.map(function (r) {
      return '<tr><td>' + dt(r.created_at) + '</td><td>' + esc(r.plan_name || '—') + '</td><td>' + fc(r.amount) + '</td></tr>';
    }).join('') || '<tr><td colspan="3" class="muted">Aucune vente enregistrée</td></tr>';
  }

  function loadSales() {
    api('dashboard').then(function (d) {
      if (!d.success) return;
      $('sales-body').innerHTML = (d.sales.by_plan || []).map(function (p) {
        return '<tr><td>' + esc(p.name) + '</td><td>' + p.nb + '</td><td>' + fc(p.total) + '</td></tr>';
      }).join('') || '<tr><td colspan="3" class="muted">Aucune vente ce mois</td></tr>';
    });
  }

  // ---------- Forfaits ----------
  async function loadPlans() {
    var d = await api('plans');
    if (!d.success) return;
    state.plans = d.plans;
    $('gen-plan').innerHTML = d.plans.map(function (p) {
      return '<option value="' + p.id + '" data-rate="' + p.rate + '">' + esc(p.name) + ' — ' + fc(p.rate) + '</option>';
    }).join('');
    if (!$('gen-price').value && d.plans.length) $('gen-price').value = d.plans[0].rate;

    $('plans-body').innerHTML = d.plans.map(function (p) {
      var mins = Number(p.duration_minutes || 0), days = Number(p.duration_days || 0);
      var limitMb = p.data_limit_bytes ? Math.round(Number(p.data_limit_bytes) / 1048576) : '';
      return '<tr data-id="' + p.id + '">' +
        '<td>' + esc(p.name) + '</td>' +
        '<td><input class="p-rate" type="number" min="0" step="100" value="' + p.rate + '" style="width:90px"></td>' +
        '<td><div style="display:flex;gap:4px">' +
          '<input class="p-min" type="number" min="0" value="' + mins + '" style="width:64px" title="minutes">min ' +
          '<input class="p-days" type="number" min="0" value="' + days + '" style="width:52px" title="jours">j</div></td>' +
        '<td><input class="p-limit" type="number" min="0" step="50" value="' + limitMb + '" style="width:80px" placeholder="∞"></td>' +
        '<td><button class="ghost p-save" type="button">OK</button></td>' +
      '</tr>';
    }).join('');

    Array.prototype.forEach.call(document.querySelectorAll('.p-save'), function (btn) {
      btn.onclick = async function () {
        var tr = btn.closest('tr');
        var r = await api('save_plan', {
          id: tr.dataset.id,
          rate: tr.querySelector('.p-rate').value,
          duration_minutes: tr.querySelector('.p-min').value,
          duration_days: tr.querySelector('.p-days').value,
          data_limit_mb: tr.querySelector('.p-limit').value
        });
        btn.textContent = r.success ? '✓' : '✗';
        if (r.success) { loadPlans(); setTimeout(function () { btn.textContent = 'OK'; }, 1200); }
      };
    });
  }

  $('gen-plan').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    if (opt && opt.dataset.rate) $('gen-price').value = opt.dataset.rate;
  });

  // ---------- Génération de lot ----------
  $('genForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    $('genBtn').disabled = true;
    var payload = {
      subscription_id: Number($('gen-plan').value),
      quantity: Number($('gen-qty').value),
      unit_price: $('gen-price').value,
      note: $('gen-note').value
    };
    var r = await api('generate_batch', payload);
    $('genBtn').disabled = false;
    if (!r.ok) {
      showMsg($('genMsg'), 'Génération impossible. Vérifiez le forfait et la quantité.', false);
      return;
    }
    state.lastBatch = r.batch_id;
    showMsg($('genMsg'), 'Lot ' + r.reference + ' : ' + r.codes.length + ' ticket(s) générés. Imprimez le PDF avant de fermer.', true);
    $('genResult').style.display = 'flex';
    loadBatches();
    loadDashboard();
  });

  $('printBtn').onclick = async function () {
    if (!state.lastBatch) return;
    $('printBtn').disabled = true;
    var r = await api('print_batch', {batch_id: state.lastBatch});
    $('printBtn').disabled = false;
    if (!r.success) { showMsg($('genMsg'), 'PDF impossible : les codes de ce lot ne sont pas déchiffrables (APP_KEY absente ?).', false); return; }
    var bin = atob(r.pdf);
    var arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    download(r.filename, new Blob([arr], {type: 'application/pdf'}));
    if (r.missing) showMsg($('genMsg'), r.missing + ' ticket(s) sans code déchiffrable ont été ignorés.', false);
  };

  $('csvBtn').onclick = async function () {
    if (!state.lastBatch) return;
    var r = await api('export_batch', {batch_id: state.lastBatch});
    if (!r.success) { showMsg($('genMsg'), 'Export CSV impossible.', false); return; }
    download(r.filename, r.csv, 'text/csv;charset=utf-8');
  };

  async function loadBatches() {
    var d = await api('batches');
    if (!d.success) return;
    $('batches-body').innerHTML = d.batches.map(function (b) {
      return '<tr><td>' + esc(b.reference) + '<div class="muted">' + dt(b.created_at) + '</div></td>' +
        '<td>' + esc(b.plan_name || '—') + '</td>' +
        '<td>' + b.tickets_count + '</td><td>' + b.tickets_used + '</td>' +
        '<td><button class="ghost b-print" type="button" data-id="' + b.id + '">PDF</button></td></tr>';
    }).join('') || '<tr><td colspan="5" class="muted">Aucun lot</td></tr>';
    Array.prototype.forEach.call(document.querySelectorAll('.b-print'), function (btn) {
      btn.onclick = async function () {
        var r = await api('print_batch', {batch_id: Number(btn.dataset.id)});
        if (!r.success) { alert('PDF impossible pour ce lot.'); return; }
        var bin = atob(r.pdf), arr = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        download(r.filename, new Blob([arr], {type: 'application/pdf'}));
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
        '<td>' + esc(t.plan_name || '—') + '</td>' +
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
        '<td>' + esc(t.plan_name || '—') + '</td>' +
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

  $('logoutBtn').onclick = async function () {
    await api('logout');
    location.href = 'reseller.php';
  };

  loadDashboard();
})();
</script>
</body>
</html>

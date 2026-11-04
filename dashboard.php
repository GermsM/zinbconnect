<?php
/**
 * Zinbconnect — tableau de bord revendeur.
 *
 * Page autonome (aucun CDN) : elle doit s'ouvrir même avec une connexion
 * lente. Le revendeur y enregistre ses ventes de cartes et suit ses gains.
 *
 * Règle importante : enregistrer une vente NE démarre PAS le temps du client.
 * La carte reste « vendue, non activée » ; le compte à rebours commence quand
 * le client saisit le code sur le portail Wi-Fi.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/tickets.php';

security_headers();
session_boot();

if (!isset($_SESSION['reseller_id'])) {
    header('Location: reseller.php');
    exit;
}
$resellerId = (int)$_SESSION['reseller_id'];

// Le revendeur existe toujours et son compte est actif ?
$stmt = db()->prepare('SELECT id, name, status FROM resellers WHERE id = ? LIMIT 1');
$stmt->execute([$resellerId]);
$reseller = $stmt->fetch();
if (!$reseller || empty($reseller['status'])) {
    audit('dashboard_stale_session', 'reseller:' . $resellerId, 'Compte disparu ou désactivé.');
    logout_current();
    header('Location: reseller.php');
    exit;
}
$resellerName = trim((string)$reseller['name']) !== '' ? trim((string)$reseller['name']) : 'Revendeur';
$firstName = explode(' ', $resellerName)[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_input();
    csrf_require($input);

    switch ((string)($input['action'] ?? '')) {
        case 'logout':
            logout_current();
            json_out(['success' => true]);

        case 'sell':
            $code = normalize_code((string)($input['code'] ?? ''));
            $buyer = trim((string)($input['buyer_contact'] ?? ''));
            if ($code === '') {
                json_out(['success' => false, 'message' => 'invalidFields']);
            }
            if (!rate_limit_check('sell', 'reseller:' . $resellerId, 80, 300)) {
                json_out(['success' => false, 'message' => 'tooManyAttempts']);
            }

            $result = sell_ticket($code, $buyer !== '' ? $buyer : null, $resellerId);
            if (!$result['ok']) {
                audit('sell_failed', 'reseller:' . $resellerId, (string)$result['error']);
                json_out(['success' => false, 'message' => $result['error']]);
            }

            json_out([
                'success'    => true,
                'code'       => normalize_code($code),
                'plan'       => plan_title($result['plan']),
                'price'      => (float)$result['price'],
                'commission' => (float)$result['commission'],
            ]);

        case 'get_transactions':
            $stmt = db()->prepare(
                'SELECT tr.id, s.name AS subscription, s.duration_minutes, s.duration_days,
                        tr.buyer_contact, tr.access_code, tr.amount, tr.commission, tr.created_at
                   FROM transactions tr
                   JOIN subscriptions s ON s.id = tr.subscription_id
                  WHERE tr.reseller_id = ?
                  ORDER BY tr.id DESC LIMIT 200'
            );
            $stmt->execute([$resellerId]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['plan_title'] = plan_title($row);
            }
            json_out(['success' => true, 'transactions' => $rows]);

        case 'get_earnings_data':
            $db = db();
            $totals = function (string $since) use ($db, $resellerId): array {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) n, COALESCE(SUM(amount),0) amount, COALESCE(SUM(commission),0) commission
                       FROM transactions WHERE reseller_id = ? AND created_at >= ?'
                );
                $stmt->execute([$resellerId, $since]);
                $row = $stmt->fetch() ?: ['n' => 0, 'amount' => 0, 'commission' => 0];
                return [
                    'count'      => (int)$row['n'],
                    'amount'     => (float)$row['amount'],
                    'commission' => (float)$row['commission'],
                ];
            };

            $stmt = $db->prepare('SELECT COALESCE(SUM(commission), 0) FROM transactions WHERE reseller_id = ?');
            $stmt->execute([$resellerId]);
            $lifeCommission = (float)$stmt->fetchColumn();

            $stmt = $db->prepare(
                'SELECT s.id, s.duration_minutes, s.duration_days, COUNT(*) nb
                   FROM transactions tr JOIN subscriptions s ON s.id = tr.subscription_id
                  WHERE tr.reseller_id = ? GROUP BY s.id, s.duration_minutes, s.duration_days'
            );
            $stmt->execute([$resellerId]);
            $byPlan = [];
            foreach ($stmt->fetchAll() as $row) {
                $byPlan[] = ['title' => plan_title($row), 'count' => (int)$row['nb']];
            }

            json_out([
                'success'      => true,
                'today'        => $totals(date('Y-m-d 00:00:00')),
                'month'        => $totals(date('Y-m-01 00:00:00')),
                'life'         => ['count' => array_sum(array_column($byPlan, 'count')), 'commission' => $lifeCommission],
                'by_plan'      => $byPlan,
                'commission_pct' => (float)(setting('reseller_commission') ?? 25),
            ]);

        default:
            json_out(['success' => false, 'message' => 'invalidAction'], 400);
    }
}

$portalName = setting('portal_name') ?? 'Zinbconnect';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#4c1d95">
<meta name="csrf-token" content="<?php echo html(csrf_token()); ?>">
<title>Revendeur — <?php echo html($portalName); ?></title>
<link rel="icon" href="download.png">
<style>
:root{--v:#5b21b6;--v2:#7c3aed;--v3:#a78bfa;--ok:#15803d;--ko:#b91c1c;--bg:#f5f3ff;--card:#fff;--txt:#1f2937;--muted:#6b7280;--line:#ede9fe}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--txt);font-size:15px;line-height:1.45}
.top{position:sticky;top:0;z-index:10;background:linear-gradient(120deg,#4c1d95,#6d28d9);color:#fff;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;box-shadow:0 3px 14px rgba(76,29,149,.22)}
.top h1{font-size:15px;margin:0;font-weight:800;display:flex;align-items:center;gap:8px}
.top small{display:block;font-weight:400;opacity:.82;font-size:11.5px;margin-top:2px}
.top .bars{display:inline-flex;align-items:flex-end;gap:2px;height:12px}
.top .bars i{width:3px;border-radius:2px;background:#c4b5fd;transform-origin:bottom;animation:signal 1.7s ease-in-out infinite}
.top .bars i:nth-child(1){height:6px}.top .bars i:nth-child(2){height:9px;animation-delay:.2s}.top .bars i:nth-child(3){height:12px;animation-delay:.4s}
.top button{background:rgba(255,255,255,.16);border:0;color:#fff;padding:8px 12px;border-radius:9px;font-size:13px;cursor:pointer;transition:background .2s,transform .12s}
.top button:active{transform:scale(.97)}
main{max-width:900px;margin:0 auto;padding:14px 12px 50px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
@media(min-width:640px){.grid{grid-template-columns:repeat(4,1fr)}}
.kpi{background:#fff;border-radius:13px;padding:12px;box-shadow:0 1px 3px rgba(76,29,149,.08);animation:rise .35s ease both;transition:transform .2s,box-shadow .2s}
.kpi:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(76,29,149,.10)}
.kpi span{display:block;font-size:11.5px;color:var(--muted)}
.kpi strong{display:block;font-size:18px;margin-top:3px;font-variant-numeric:tabular-nums;background:linear-gradient(135deg,#5b21b6,#7c3aed);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.kpi em{font-style:normal;font-size:11px;color:var(--muted)}
h2{font-size:12.5px;text-transform:uppercase;letter-spacing:.6px;color:#7c3aed;margin:20px 0 8px;font-weight:800}
.card{background:#fff;border-radius:14px;padding:15px;box-shadow:0 1px 3px rgba(76,29,149,.08);border:1px solid rgba(237,233,254,.9);animation:rise .4s ease both}
label{display:block;font-size:13px;font-weight:700;margin:0 0 5px;color:#3b0764}
input{width:100%;padding:12px;border:1.6px solid var(--line);border-radius:10px;font-size:16px;background:#fdfcff;transition:border-color .2s,box-shadow .2s}
input:focus{outline:none;border-color:var(--v2);box-shadow:0 0 0 4px rgba(124,58,237,.13)}
input#code{font-family:ui-monospace,Consolas,monospace;text-transform:uppercase;letter-spacing:1.5px;font-size:18px}
button.primary{background:linear-gradient(135deg,var(--v),var(--v2));color:#fff;border:0;padding:13px 16px;border-radius:10px;font-size:15px;font-weight:800;cursor:pointer;box-shadow:0 5px 14px rgba(91,33,182,.24);transition:transform .12s,opacity .2s}
button.primary:active{transform:scale(.985)}
button.primary:disabled{opacity:.6}
.msg{display:none;margin-top:12px;padding:12px;border-radius:10px;font-size:14px;animation:rise .2s ease both}
.msg.show{display:block}
.msg.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.msg.ko{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}
.msg b{display:block;font-size:15px}
.table{overflow-x:auto;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:8px 6px;border-bottom:1px solid #f3f4f6;vertical-align:top}
th{font-size:10.5px;text-transform:uppercase;color:var(--muted);letter-spacing:.4px}
tbody tr{transition:background .15s}
tbody tr:hover{background:#faf5ff}
.muted{color:var(--muted);font-size:12px}
.note{background:#faf5ff;border:1px solid #ede9fe;border-radius:10px;padding:11px 12px;font-size:12.5px;color:#4c1d95;line-height:1.5;margin-top:10px}
.pill{display:inline-block;background:#ede9fe;color:#5b21b6;border-radius:99px;padding:3px 9px;font-size:11.5px;font-weight:700}
.steps{counter-reset:s;list-style:none;padding:0;margin:8px 0 0}
.steps li{counter-increment:s;position:relative;padding-left:30px;margin-bottom:8px;font-size:13.5px}
.steps li::before{content:counter(s);position:absolute;left:0;top:0;width:21px;height:21px;border-radius:50%;background:#ede9fe;color:#5b21b6;font-size:11.5px;font-weight:800;display:flex;align-items:center;justify-content:center}
.bars2{display:flex;gap:8px;align-items:flex-end;height:70px;margin-top:12px}
.bars2 div{flex:1;text-align:center;font-size:11px;color:var(--muted)}
.bars2 i{display:block;background:linear-gradient(180deg,#a78bfa,#7c3aed);border-radius:6px 6px 3px 3px;transition:height .5s ease;min-height:3px}
@keyframes rise{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes signal{0%,100%{transform:scaleY(.5);opacity:.55}50%{transform:scaleY(1);opacity:1}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>

<div class="top">
  <div>
    <h1><span class="bars" aria-hidden="true"><i></i><i></i><i></i></span>Bonjour <?php echo html($firstName); ?></h1>
    <small><?php echo html($portalName); ?> · espace revendeur</small>
  </div>
  <button id="logoutBtn" type="button">Déconnexion</button>
</div>

<main>
  <div class="grid">
    <div class="kpi"><span>Ventes aujourd'hui</span><strong id="k-today">—</strong><em id="k-today-c"></em></div>
    <div class="kpi"><span>Gains aujourd'hui</span><strong id="k-today-g">—</strong><em>commission</em></div>
    <div class="kpi"><span>Ce mois</span><strong id="k-month">—</strong><em id="k-month-c"></em></div>
    <div class="kpi"><span>Gains cumulés</span><strong id="k-life">—</strong><em id="k-pct"></em></div>
  </div>

  <h2>Enregistrer une vente</h2>
  <div class="card">
    <form id="sellForm" autocomplete="off">
      <label for="code">Code de la carte</label>
      <input id="code" type="text" inputmode="text" maxlength="20" placeholder="EX : K7M2PQRS9TUV" required>
      <label for="buyer" style="margin-top:10px">Téléphone du client (facultatif)</label>
      <input id="buyer" type="tel" placeholder="+243…">
      <div style="margin-top:12px"><button class="primary" id="sellBtn" type="submit">Enregistrer la vente</button></div>
      <div class="msg" id="sellMsg"></div>
    </form>
    <p class="note"><strong>Le temps du client ne démarre pas ici.</strong> La carte est simplement marquée vendue (pour votre commission) ; le compte à rebours commence quand le client saisit le code sur le portail Wi-Fi.</p>
  </div>

  <h2>Mes ventes</h2>
  <div class="card">
    <div class="table">
      <table>
        <thead><tr><th>Date</th><th>Forfait</th><th>Code</th><th>Montant</th><th>Commission</th></tr></thead>
        <tbody id="salesBody"><tr><td colspan="5" class="muted">Chargement…</td></tr></tbody>
      </table>
    </div>
  </div>

  <h2>Répartition par forfait</h2>
  <div class="card">
    <div class="bars2" id="planBars"></div>
    <p class="muted" id="planNote" style="margin:8px 0 0"></p>
  </div>

  <h2>Comment ça marche</h2>
  <div class="card">
    <ol class="steps">
      <li>L'administrateur vous remet des cartes imprimées (chaque carte a un code et un QR).</li>
      <li>Le client vous paie en espèces : vous choisissez le forfait qu'il demande.</li>
      <li>Vous tapez le code ci-dessus pour enregistrer la vente et votre commission.</li>
      <li>Le client se connecte au Wi-Fi, ouvre le portail, saisit son code : c'est là que son temps commence.</li>
      <li>Le client revient plus tard ? Il retape le même code : le temps restant s'affiche.</li>
    </ol>
  </div>
</main>

<script>
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var $ = function (id) { return document.getElementById(id); };
  var timer = null;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function fc(n) { return Number(n || 0).toLocaleString('fr-FR') + ' FC'; }
  function dt(s) {
    if (!s) return '—';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? esc(s) : d.toLocaleString('fr-FR', {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'});
  }
  function msg(el, text, ok) {
    el.className = 'msg show ' + (ok ? 'ok' : 'ko');
    el.innerHTML = text;
  }

  async function api(action, payload) {
    var res = await fetch('dashboard.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify(Object.assign({action: action, csrf: csrf}, payload || {}))
    });
    if (res.status === 403) { location.reload(); throw new Error('session'); }
    return res.json();
  }

  async function loadStats() {
    var d = await api('get_earnings_data');
    if (!d.success) return;
    $('k-today').textContent = fc(d.today.amount);
    $('k-today-c').textContent = d.today.count + ' carte(s)';
    $('k-today-g').textContent = fc(d.today.commission);
    $('k-month').textContent = fc(d.month.amount);
    $('k-month-c').textContent = d.month.count + ' carte(s)';
    $('k-life').textContent = fc(d.life.commission);
    $('k-pct').textContent = 'commission ' + d.commission_pct + ' % · ' + d.life.count + ' vente(s)';

    var plans = d.by_plan || [];
    var max = Math.max(1, Math.max.apply(null, plans.map(function (p) { return p.count; })));
    $('planBars').innerHTML = plans.map(function (p) {
      var h = Math.max(6, Math.round(p.count / max * 60));
      return '<div><i style="height:' + h + 'px"></i>' + esc(p.title) + '<div class="muted">' + p.count + '</div></div>';
    }).join('') || '';
    $('planNote').textContent = plans.length ? '' : 'Aucune vente enregistrée pour le moment.';
  }

  async function loadSales() {
    var d = await api('get_transactions');
    if (!d.success) return;
    $('salesBody').innerHTML = (d.transactions || []).map(function (t) {
      return '<tr><td>' + dt(t.created_at) + '</td>' +
        '<td>' + esc(t.plan_title || '—') + '</td>' +
        '<td><strong>' + esc(t.access_code || '—') + '</strong>' +
        (t.buyer_contact ? '<div class="muted">' + esc(t.buyer_contact) + '</div>' : '') + '</td>' +
        '<td>' + fc(t.amount) + '</td>' +
        '<td><span class="pill">' + fc(t.commission) + '</span></td></tr>';
    }).join('') || '<tr><td colspan="5" class="muted">Aucune vente enregistrée</td></tr>';
  }

  $('sellForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    $('sellBtn').disabled = true;
    $('sellBtn').textContent = 'Enregistrement…';
    var code = $('code').value.toUpperCase().replace(/[\s\-_]/g, '');
    try {
      var d = await api('sell', {code: code, buyer_contact: $('buyer').value});
      if (d.success) {
        msg($('sellMsg'), '<b>Vente enregistrée</b>' + esc(d.plan) + ' · ' + fc(d.price) +
          '<br>Votre commission : ' + fc(d.commission) + '<br><span class="muted">Le temps du client démarrera à sa première connexion.</span>', true);
        $('sellForm').reset();
        loadStats();
        loadSales();
      } else {
        var errors = {
          invalid: 'Code inconnu. Vérifiez la saisie (pas de 0, O, 1, I ni l).',
          alreadyUsed: 'Cette carte est déjà vendue ou déjà activée.',
          revoked: 'Cette carte a été révoquée par l\'administrateur.',
          expired: 'Cette carte est expirée.',
          used: 'Cette carte est déjà consommée.',
          invalidContact: 'Le numéro de téléphone n\'est pas valide.',
          tooManyAttempts: 'Trop d\'essais : attendez quelques minutes.'
        };
        msg($('sellMsg'), errors[d.message] || 'Vente impossible. Réessayez.', false);
      }
    } catch (err) {
      msg($('sellMsg'), 'Serveur injoignable. Vérifiez le Wi-Fi.', false);
    }
    $('sellBtn').disabled = false;
    $('sellBtn').textContent = 'Enregistrer la vente';
  });

  $('logoutBtn').onclick = async function () {
    try { await api('logout'); } catch (e) { /* on sort quand même */ }
    location.href = 'reseller.php';
  };

  loadStats();
  loadSales();
  timer = setInterval(function () { loadStats(); }, 60000);
})();
</script>
</body>
</html>

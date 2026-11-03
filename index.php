<?php
/**
 * Zinbconnect — portail captif (page publique).
 *
 * Volontairement très légère : aucun CDN, aucune police externe, une seule
 * requête réseau (le CSS est inclus). Objectif < 30 Ko pour rester utilisable
 * sur un vieux téléphone et sur une connexion lente.
 *
 * Ce que fait la page :
 *   - affiche les forfaits et les prix ;
 *   - un seul champ : le code du ticket ;
 *   - messages d'erreur clairs (invalide / expiré / déjà utilisé / révoqué) ;
 *   - après validation : temps et données restants, décompte en direct.
 *
 * L'autorisation réelle sur le routeur MikroTik est branchée en Phase 4.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/tickets.php';

security_headers();
session_boot();

$portalName = setting('portal_name') ?? 'Zinbconnect';
$plans = [];
try {
    $plans = plans_all();
} catch (Throwable $e) {
    // Base non migrée : le portail reste lisible, la validation affichera une erreur.
    $plans = [];
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#4c1d95">
<meta name="csrf-token" content="<?php echo html($csrf); ?>">
<title><?php echo html($portalName); ?> — Connexion Wi-Fi</title>
<link rel="icon" href="download.png">
<style>
:root{--v:#5b21b6;--v2:#7c3aed;--ok:#15803d;--ko:#b91c1c;--bg:#f5f3ff;--card:#fff;--txt:#1f2937;--muted:#6b7280;--line:#e5e7eb}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--txt);line-height:1.45;font-size:17px}
.wrap{max-width:520px;margin:0 auto;padding:16px 14px 40px}
header{display:flex;align-items:center;gap:10px;padding:10px 0 14px}
.logo{width:38px;height:38px;border-radius:10px;background:var(--v);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px}
h1{font-size:19px;margin:0;line-height:1.2}
header p{margin:2px 0 0;font-size:13px;color:var(--muted)}
.card{background:var(--card);border-radius:14px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:14px}
label{display:block;font-weight:600;font-size:15px;margin-bottom:8px}
input[type=text]{width:100%;padding:14px 12px;font-size:22px;letter-spacing:2px;font-family:ui-monospace,Consolas,monospace;text-transform:uppercase;border:2px solid var(--line);border-radius:10px;background:#fff;color:var(--txt)}
input[type=text]:focus{outline:none;border-color:var(--v2);box-shadow:0 0 0 3px rgba(124,58,237,.15)}
button{width:100%;margin-top:12px;padding:15px;font-size:18px;font-weight:700;color:#fff;background:var(--v);border:0;border-radius:10px;cursor:pointer}
button:disabled{opacity:.6}
.hint{font-size:13px;color:var(--muted);margin-top:8px}
.msg{margin-top:14px;padding:14px;border-radius:10px;font-size:15px;display:none}
.msg.show{display:block}
.msg.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.msg.ko{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}
.msg b{font-size:17px;display:block;margin-bottom:4px}
.big{font-size:26px;font-weight:700;font-variant-numeric:tabular-nums}
.rows{display:flex;gap:10px;margin-top:10px;flex-wrap:wrap}
.row{flex:1 1 120px;background:#faf5ff;border-radius:8px;padding:8px 10px}
.row span{display:block;font-size:12px;color:var(--muted)}
.row strong{font-size:16px}
h2{font-size:15px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin:0 0 10px}
.plan{display:flex;justify-content:space-between;align-items:baseline;padding:9px 0;border-bottom:1px solid var(--line);font-size:15px}
.plan:last-child{border-bottom:0}
.plan strong{font-size:16px;color:var(--v)}
.plan em{font-style:normal;color:var(--muted);font-size:13px}
details{background:var(--card);border-radius:14px;padding:14px 18px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
summary{cursor:pointer;font-weight:600;font-size:15px}
details p,details li{font-size:14px;color:#374151}
details ul{padding-left:18px;margin:8px 0}
footer{text-align:center;font-size:12px;color:var(--muted);margin-top:18px}
footer a{color:var(--v)}
.note{font-size:12px;color:var(--muted);text-align:center;margin-top:8px}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo">Z</div>
    <div>
      <h1><?php echo html($portalName); ?></h1>
      <p>Wi-Fi à Kitutu — entrez votre code pour vous connecter</p>
    </div>
  </header>

  <main>
    <section class="card">
      <form id="codeForm" autocomplete="off" novalidate>
        <label for="code">Votre code</label>
        <input id="code" name="code" type="text" inputmode="text" autocomplete="off"
               autocapitalize="characters" spellcheck="false" maxlength="20"
               placeholder="EX : K7M2 PQRS 9TUV" aria-describedby="codeHint" required>
        <p class="hint" id="codeHint">12 caractères, sans les lettres 0, O, 1, I et l.</p>
        <button id="submitBtn" type="submit">Se connecter</button>
      </form>

      <div id="message" class="msg" role="status" aria-live="polite"></div>
    </section>

    <section class="card">
      <h2>Forfaits</h2>
      <?php if ($plans): ?>
        <?php foreach ($plans as $p): ?>
          <?php
            $dur = plan_duration_seconds($p);
            $label = format_duration($dur);
            $limit = plan_data_limit($p);
          ?>
          <div class="plan">
            <div>
              <strong><?php echo html(format_money((float)$p['rate'])); ?></strong>
              <em> · <?php echo html($label); ?></em>
            </div>
            <div><em><?php echo $limit ? html(format_bytes($limit)) : 'illimité'; ?></em></div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="hint">Les forfaits s'afficheront dès que la base sera initialisée.</p>
      <?php endif; ?>
    </section>

    <details>
      <summary>Aide et questions fréquentes</summary>
      <ul>
        <li><strong>Où acheter un code ?</strong> Chez un revendeur à Kitutu, en espèces (FC).</li>
        <li><strong>Le code ne passe pas ?</strong> Vérifiez les caractères : il n'y a jamais de <b>0</b>, <b>O</b>, <b>1</b>, <b>I</b> ni <b>l</b>.</li>
        <li><strong>Le temps commence quand ?</strong> À la première utilisation du code, pas à l'achat.</li>
        <li><strong>J'ai perdu ma connexion ?</strong> Réentrez le même code : il reprend le temps restant.</li>
        <li><strong>Données épuisées ?</strong> Le code s'arrête même s'il reste du temps. Un nouveau ticket est nécessaire.</li>
        <li>Un code ne peut pas servir deux fois en même temps.</li>
      </ul>
    </details>

    <p class="note">Page allégée : elle fonctionne même avec une connexion très lente.</p>
  </main>

  <footer>
    <?php echo html($portalName); ?> · <a href="reseller.php">Espace revendeur</a>
  </footer>
</div>

<script>
(function () {
  var form = document.getElementById('codeForm');
  var input = document.getElementById('code');
  var btn = document.getElementById('submitBtn');
  var box = document.getElementById('message');
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var timer = null;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function fmtBytes(b) {
    if (b === null || b === undefined) return 'illimité';
    var units = ['o','Ko','Mo','Go'], i = 0, v = b;
    while (v >= 1024 && i < units.length - 1) { v = v / 1024; i++; }
    return v.toFixed(i === 0 ? 0 : 1).replace('.', ',') + ' ' + units[i];
  }

  function fmtTime(sec) {
    sec = Math.max(0, Math.floor(sec));
    var j = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    if (j > 0) return j + ' j ' + h + ' h';
    if (h > 0) return h + ' h ' + String(m).padStart(2, '0') + ' min';
    if (m > 0) return m + ' min ' + String(s).padStart(2, '0') + ' s';
    return s + ' s';
  }

  var messages = {
    invalid: ['Code invalide', "Ce code n'existe pas. Vérifiez les caractères : il n'y a jamais de 0, O, 1, I ou l."],
    expired: ['Code expiré', 'Le temps de ce ticket est terminé.'],
    revoked: ['Code désactivé', 'Ce code a été révoqué. Contactez votre revendeur.'],
    used: ['Déjà utilisé', 'Ce code a déjà été utilisé : son plafond de données est atteint.'],
    rateLimited: ['Trop d\'essais', 'Attendez quelques minutes avant de réessayer.'],
    csrfInvalid: ['Session expirée', 'Rechargez la page et réessayez.'],
    empty: ['Code vide', 'Entrez le code inscrit sur votre ticket.']
  };

  function showError(reason) {
    var m = messages[reason] || ['Erreur', "La vérification a échoué. Réessayez dans un instant."];
    box.className = 'msg ko show';
    box.innerHTML = '<b>' + esc(m[0]) + '</b>' + esc(m[1]);
  }

  function showSuccess(d) {
    box.className = 'msg ok show';
    var remaining = d.remaining_seconds || 0;
    box.innerHTML =
      '<b>Code valide — bonne connexion !</b>' +
      '<div>Forfait : ' + esc(d.plan || '') + '</div>' +
      '<div class="rows">' +
        '<div class="row"><span>Temps restant</span><strong class="big" id="left">' + fmtTime(remaining) + '</strong></div>' +
        '<div class="row"><span>Données restantes</span><strong id="data">' + fmtBytes(d.remaining_bytes) + '</strong></div>' +
      '</div>' +
      '<div class="hint">Expire le ' + esc(d.expires_at_fr || '') + '. Réentrez ce code pour revoir le temps restant.</div>';
    startCountdown(remaining);
  }

  function startCountdown(sec) {
    if (timer) clearInterval(timer);
    var el = document.getElementById('left');
    if (!el) return;
    timer = setInterval(function () {
      sec--;
      if (sec <= 0) {
        clearInterval(timer);
        el.textContent = 'terminé';
        return;
      }
      el.textContent = fmtTime(sec);
    }, 1000);
  }

  async function check(code) {
    var res = await fetch('validate_code.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify({code: code, csrf: csrf})
    });
    return res.json();
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var code = input.value.toUpperCase().replace(/[\s\-_]/g, '');
    if (!code) { showError('empty'); return; }
    btn.disabled = true;
    btn.textContent = 'Vérification…';
    box.className = 'msg';
    try {
      var d = await check(code);
      if (d.valid) {
        showSuccess(d);
      } else {
        showError(d.reason || 'invalid');
      }
    } catch (err) {
      box.className = 'msg ko show';
      box.innerHTML = '<b>Connexion impossible</b>Vérifiez que le Wi-Fi est bien connecté, puis réessayez.';
    }
    btn.disabled = false;
    btn.textContent = 'Se connecter';
  });

  // Affiche directement le champ pour les QR codes : index.php?c=CODE
  var params = new URLSearchParams(location.search);
  var pre = params.get('c');
  if (pre) {
    input.value = pre.toUpperCase();
    form.dispatchEvent(new Event('submit'));
  }
})();
</script>
</body>
</html>

<?php
/**
 * Zinbconnect — portail captif (page publique).
 *
 * Volontairement très légère : aucun CDN, aucune police externe, une seule
 * requête réseau (le CSS est inclus). Objectif < 30 Ko pour rester utilisable
 * sur un vieux téléphone et sur une connexion lente.
 *
 * Ce que fait la page :
 *   - affiche les forfaits (durée et prix uniquement : l'offre est illimitée) ;
 *   - un seul champ : le code du ticket, ou le scan du QR de la carte ;
 *   - messages d'erreur clairs (invalide / expiré / déjà utilisé / révoqué) ;
 *   - après validation : temps restant, décompte en direct.
 *
 * Les plafonds techniques (limit-bytes-total) restent côté administration :
 * le client ne voit jamais de volume.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/tickets.php';

security_headers();
session_boot();

$portalName = setting('portal_name') ?? 'Zinbconnect';
try {
    $plans = plans_all();
} catch (Throwable $e) {
    $plans = []; // base non migrée : le portail reste lisible
}
$prefill = isset($_GET['c']) ? normalize_code((string)$_GET['c']) : '';
if ($prefill !== '' && !preg_match('/^[A-Z2-9]{6,16}$/', $prefill)) {
    $prefill = '';
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
:root{--v:#5b21b6;--v2:#7c3aed;--v3:#a78bfa;--bg1:#f5f3ff;--bg2:#ede9fe;--card:#fff;--txt:#1f2937;--muted:#6b7280;--line:#ede9fe;--ok:#15803d;--ko:#b91c1c}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--txt);line-height:1.45;font-size:17px;
 background:linear-gradient(160deg,var(--bg1) 0%,#fff 45%,var(--bg2) 100%);background-attachment:fixed;min-height:100vh}
.wrap{max-width:520px;margin:0 auto;padding:14px 14px 36px}
header{display:flex;align-items:center;gap:11px;padding:8px 0 16px;animation:fade .5s ease both}
.logo{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--v),var(--v3));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:19px;box-shadow:0 6px 16px rgba(91,33,182,.28);position:relative;overflow:hidden}
.logo::after{content:"";position:absolute;inset:-40%;background:linear-gradient(115deg,transparent 40%,rgba(255,255,255,.55) 50%,transparent 60%);animation:shine 4.5s ease-in-out infinite}
h1{font-size:19px;margin:0;line-height:1.2;letter-spacing:-.2px}
header p{margin:3px 0 0;font-size:12.5px;color:var(--muted)}
.bars{display:inline-flex;align-items:flex-end;gap:2px;height:12px;margin-left:6px;vertical-align:middle}
.bars i{width:3px;background:var(--v3);border-radius:2px;transform-origin:bottom;animation:signal 1.6s ease-in-out infinite}
.bars i:nth-child(1){height:5px}
.bars i:nth-child(2){height:8px;animation-delay:.2s}
.bars i:nth-child(3){height:12px;animation-delay:.4s}
.card{background:var(--card);border-radius:16px;padding:18px;box-shadow:0 2px 10px rgba(76,29,149,.07),0 1px 2px rgba(0,0,0,.04);margin-bottom:14px;border:1px solid rgba(237,233,254,.9);animation:rise .45s ease both}
.card:nth-of-type(2){animation-delay:.06s}
.card:nth-of-type(3){animation-delay:.12s}
label{display:block;font-weight:700;font-size:14px;margin-bottom:8px;color:#3b0764}
input[type=text]{width:100%;padding:15px 13px;font-size:22px;letter-spacing:2.5px;font-family:ui-monospace,Consolas,monospace;text-transform:uppercase;border:2px solid var(--line);border-radius:12px;background:#fdfcff;color:var(--txt);transition:border-color .2s,box-shadow .2s}
input[type=text]::placeholder{letter-spacing:1px;font-size:17px;color:#b3aec4}
input[type=text]:focus{outline:none;border-color:var(--v2);box-shadow:0 0 0 4px rgba(124,58,237,.14);background:#fff}
button{width:100%;margin-top:12px;padding:15px;font-size:17px;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--v),var(--v2));border:0;border-radius:12px;cursor:pointer;box-shadow:0 6px 16px rgba(91,33,182,.25);transition:transform .12s ease,box-shadow .2s,opacity .2s;-webkit-tap-highlight-color:transparent}
button:active{transform:scale(.985);box-shadow:0 3px 8px rgba(91,33,182,.22)}
button:disabled{opacity:.6}
button.ghost{background:#f5f3ff;color:var(--v);border:1.5px solid #ddd6fe;box-shadow:none;font-size:15.5px;margin-top:10px}
button.ghost:active{transform:scale(.99)}
.hint{font-size:12.5px;color:var(--muted);margin-top:8px}
.msg{margin-top:14px;padding:14px;border-radius:12px;font-size:15px;display:none;animation:rise .25s ease both}
.msg.show{display:block}
.msg.ok{background:linear-gradient(180deg,#f0fdf4,#dcfce7);border:1px solid #bbf7d0;color:#14532d}
.msg.ko{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}
.msg b{font-size:17px;display:block;margin-bottom:4px}
.big{font-size:26px;font-weight:800;font-variant-numeric:tabular-nums;letter-spacing:-.5px;color:var(--v)}
.rows{display:flex;gap:10px;margin-top:10px;flex-wrap:wrap}
.row{flex:1 1 140px;background:#faf5ff;border:1px solid #f3e8ff;border-radius:10px;padding:9px 11px}
.row span{display:block;font-size:12px;color:var(--muted)}
.row strong{font-size:16px}
h2{font-size:13px;text-transform:uppercase;letter-spacing:.7px;color:#7c3aed;margin:0 0 10px;font-weight:800}
.plan{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:11px 0;border-bottom:1px solid var(--line);font-size:15px}
.plan:last-child{border-bottom:0;padding-bottom:0}
.plan div:first-child{display:flex;flex-direction:column}
.plan strong{font-size:17px;color:var(--v)}
.plan em{font-style:normal;color:var(--muted);font-size:13px}
.tag{background:#ede9fe;color:#5b21b6;border-radius:99px;padding:4px 10px;font-size:11.5px;font-weight:700;white-space:nowrap}
details{background:var(--card);border-radius:16px;padding:4px 18px;box-shadow:0 2px 10px rgba(76,29,149,.07);border:1px solid rgba(237,233,254,.9);animation:rise .45s ease both;animation-delay:.18s}
summary{cursor:pointer;font-weight:700;font-size:15px;padding:13px 0;list-style:none;display:flex;justify-content:space-between;align-items:center}
summary::-webkit-details-marker{display:none}
summary::after{content:"＋";color:var(--v2);font-weight:700}
details[open] summary::after{content:"−"}
details p,details li{font-size:14px;color:#374151;line-height:1.5}
details ul{padding-left:18px;margin:0 0 12px}
details li{margin-bottom:7px}
footer{text-align:center;font-size:12px;color:var(--muted);margin-top:18px}
footer a{color:var(--v)}
#camWrap{display:none;margin-top:12px;border-radius:14px;overflow:hidden;background:#000;position:relative}
#camWrap.on{display:block;animation:rise .25s ease both}
#cam{width:100%;display:block;max-height:60vh;object-fit:cover}
#camHint{position:absolute;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);color:#fff;font-size:12.5px;padding:8px 10px;text-align:center}
@keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes fade{from{opacity:0}to{opacity:1}}
@keyframes shine{0%,70%{transform:translateX(-120%)}100%{transform:translateX(120%)}}
@keyframes signal{0%,100%{transform:scaleY(.55);opacity:.6}50%{transform:scaleY(1);opacity:1}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo">Z</div>
    <div>
      <h1><?php echo html($portalName); ?><span class="bars" aria-hidden="true"><i></i><i></i><i></i></span></h1>
      <p>Wi-Fi à Kitutu — entrez votre code pour vous connecter</p>
    </div>
  </header>

  <main>
    <section class="card">
      <form id="codeForm" autocomplete="off" novalidate>
        <label for="code">Votre code</label>
        <input id="code" name="code" type="text" inputmode="text" autocomplete="off"
               autocapitalize="characters" spellcheck="false" maxlength="20"
               placeholder="EX : K7M2PQRS9TUV" value="<?php echo html($prefill); ?>" required>
        <button id="submitBtn" type="submit">Se connecter</button>
        <button id="scanBtn" class="ghost" type="button">Se connecter avec le QR code</button>
        <p class="hint">Le code figure sur votre carte. Il n'y a jamais de 0, O, 1, I ou l.</p>
      </form>

      <div id="camWrap">
        <video id="cam" muted playsinline></video>
        <div id="camHint">Placez le QR code de la carte dans le cadre</div>
      </div>

      <div id="message" class="msg" role="status" aria-live="polite"></div>
    </section>

    <section class="card">
      <h2>Forfaits</h2>
      <?php if ($plans): ?>
        <?php foreach ($plans as $p): ?>
          <div class="plan">
            <div>
              <strong><?php echo html(format_money((float)$p['rate'])); ?></strong>
              <em><?php echo html(plan_title($p)); ?> de connexion</em>
            </div>
            <span class="tag">Illimité</span>
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
        <li><strong>Combien de temps ?</strong> Votre forfait est illimité pendant toute sa durée : pas de compteur de données.</li>
        <li><strong>Le temps commence quand ?</strong> À la première utilisation du code, pas à l'achat.</li>
        <li><strong>J'ai perdu ma connexion ?</strong> Réentrez le même code : il reprend le temps restant.</li>
        <li><strong>Le code ne passe pas ?</strong> Vérifiez les caractères : il n'y a jamais de <b>0</b>, <b>O</b>, <b>1</b>, <b>I</b> ni <b>l</b>.</li>
        <li><strong>Un code pour deux personnes ?</strong> Non : un code ne peut pas servir deux fois en même temps.</li>
      </ul>
    </details>

    <p class="hint" style="text-align:center;margin-top:10px">Une connexion lente ? Cette page reste légère pour fonctionner partout.</p>
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
  var stream = null;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
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
    expired: ['Code expiré', 'La durée de ce forfait est terminée.'],
    revoked: ['Code désactivé', 'Ce code a été révoqué. Contactez votre revendeur.'],
    used: ['Déjà utilisé', 'Ce code a déjà servi. Achetez un nouveau ticket.'],
    rateLimited: ['Trop d\'essais', 'Attendez quelques minutes avant de réessayer.'],
     csrfInvalid: ['Session expirée', 'Rechargez la page et réessayez.'],
     empty: ['Code vide', 'Entrez le code inscrit sur votre carte.'],
     invalidMethod: ['Erreur', 'Rechargez la page et réessayez.'],
     routerosPortal: ['Portail Wi-Fi local requis', 'Connectez-vous au Wi-Fi Zinbconnect puis ouvrez http://10.20.30.1/login.html pour utiliser votre ticket.']
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
      '<div class="rows">' +
        '<div class="row"><span>Forfait</span><strong>' + esc(d.plan || '') + '</strong></div>' +
        '<div class="row"><span>Temps restant</span><strong class="big" id="left">' + fmtTime(remaining) + '</strong></div>' +
      '</div>' +
      '<div class="hint">Internet illimité jusqu\'au ' + esc(d.expires_at_fr || '') + '. Réentrez ce code pour revoir le temps restant.</div>';
    startCountdown(remaining);
    stopCamera();
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

  function post(code) {
    return fetch('validate_code.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify({code: code, csrf: csrf})
    }).then(function (r) { return r.json(); });
  }

  function submitCode(raw) {
    var code = String(raw || '').toUpperCase().replace(/[\s\-_]/g, '');
    input.value = code;
    if (!code) { showError('empty'); return; }
    btn.disabled = true;
    btn.textContent = 'Vérification…';
    box.className = 'msg';
    post(code).then(function (d) {
      if (d.valid) { showSuccess(d); } else { showError(d.reason || 'invalid'); }
    }).catch(function () {
      box.className = 'msg ko show';
      box.innerHTML = '<b>Connexion impossible</b>Vérifiez que le Wi-Fi est bien connecté, puis réessayez.';
    }).then(function () {
      btn.disabled = false;
      btn.textContent = 'Se connecter';
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    submitCode(input.value);
  });

  // ---- Scan du QR code (caméra) ----
  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
    document.getElementById('camWrap').classList.remove('on');
  }

  function scanMessage(text) {
    box.className = 'msg ko show';
    box.innerHTML = '<b>Scan indisponible</b>' + esc(text);
  }

  document.getElementById('scanBtn').addEventListener('click', async function () {
    if (!('BarcodeDetector' in window)) {
      scanMessage("Ce téléphone ne sait pas lire les QR depuis le navigateur. Ouvrez l'appareil photo et visez le QR de la carte : il ouvre cette page avec le code déjà rempli.");
      return;
    }
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      scanMessage("Le scan direct demande une connexion sécurisée (HTTPS). En attendant, utilisez l'appareil photo de votre téléphone sur le QR de la carte : le code se remplit tout seul.");
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}});
    } catch (err) {
      scanMessage("Impossible d'ouvrir la caméra. Autorisez l'accès dans le navigateur, ou tapez le code.");
      return;
    }
    var wrap = document.getElementById('camWrap');
    var video = document.getElementById('cam');
    wrap.classList.add('on');
    video.srcObject = stream;
    try { await video.play(); } catch (err) { /* certains navigateurs demandent un geste */ }

    var detector = new BarcodeDetector({formats: ['qr_code']});
    var ticks = 0;
    var loop = async function () {
      if (!stream) return;
      ticks++;
      if (ticks > 300) { stopCamera(); scanMessage('Aucun QR détecté. Réessayez ou tapez le code.'); return; }
      try {
        var found = await detector.detect(video);
        if (found && found.length) {
          var raw = found[0].rawValue || '';
          var code = raw;
          try {
            var u = new URL(raw);
            code = u.searchParams.get('c') || raw;
          } catch (e) { code = raw; }
          var clean = String(code).toUpperCase().replace(/[\s\-_]/g, '');
          if (/^[A-Z2-9]{6,16}$/.test(clean)) {
            stopCamera();
            submitCode(clean);
            return;
          }
        }
      } catch (e) { /* image pas encore prête */ }
      setTimeout(loop, 250);
    };
    loop();
  });

  // QR scanné avec l'appareil photo du téléphone : index.php?c=CODE
  var params = new URLSearchParams(location.search);
  var pre = params.get('c');
  if (pre) {
    setTimeout(function () { submitCode(pre); }, 150);
  }
})();
</script>
</body>
</html>

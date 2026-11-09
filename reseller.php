<?php
/**
 * Zinbconnect — connexion administrateur / revendeur.
 *
 * Page autonome (aucun CDN, aucune police externe) : elle doit s'afficher même
 * quand Internet est coupé, puisque l'administration se fait depuis le portail.
 *
 * Sécurité : mot de passe haché vérifié côté serveur, anti-brute-force
 * (5 essais / 15 min par IP et par identifiant), CSRF, session régénérée,
 * 2FA TOTP optionnelle pour l'administrateur.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/settings.php';

security_headers();
session_boot();

if (!empty($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}
if (!empty($_SESSION['reseller_id'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = json_input();
    csrf_require($input);

    $contact  = trim((string)($input['contact'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $totp     = isset($input['totp']) ? preg_replace('/\D+/', '', (string)$input['totp']) : null;

    $result = attempt_login($contact, $password, $totp);

    if ($result['ok']) {
        setcookie('admin_id',    '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        setcookie('reseller_id', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        json_out(['success' => true, 'user_type' => $result['user_type']]);
    }

    json_out([
        'success'      => false,
        'message'      => $result['message'] ?? 'invalidCredentials',
        'wait'         => (int)($result['wait'] ?? 0),
        'totpRequired' => !empty($result['totpRequired']),
    ]);
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
<title>ZinbConnect — Connexion</title>
<link rel="icon" href="download.png">
<style>
:root{--v:#5b21b6;--v2:#7c3aed;--v3:#a78bfa;--txt:#1f2937;--muted:#6b7280;--line:#ede9fe}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--txt);line-height:1.5;font-size:16px;min-height:100vh;
 background:linear-gradient(160deg,#f5f3ff 0%,#fff 48%,#ede9fe 100%);background-attachment:fixed;display:flex;flex-direction:column}
.top{display:flex;justify-content:space-between;align-items:center;padding:14px 16px}
.brand{display:flex;align-items:center;gap:9px;font-weight:800;color:var(--v);text-decoration:none;font-size:15px}
.brand .dot{width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,var(--v),var(--v3));color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;box-shadow:0 5px 14px rgba(91,33,182,.28);position:relative;overflow:hidden}
.brand .dot::after{content:"";position:absolute;inset:-40%;background:linear-gradient(115deg,transparent 40%,rgba(255,255,255,.6) 50%,transparent 60%);animation:shine 4.5s ease-in-out infinite}
.top a.home{font-size:13px;color:var(--v);text-decoration:none;border:1px solid #ddd6fe;border-radius:9px;padding:7px 11px;background:#fff;transition:background .2s,transform .12s}
.top a.home:active{transform:scale(.98)}
main{flex:1;display:flex;align-items:center;justify-content:center;padding:8px 16px 30px}
.box{width:100%;max-width:420px}
.hero{text-align:center;margin-bottom:20px;animation:rise .5s ease both}
.hero h1{font-size:29px;margin:0;font-weight:800;letter-spacing:-.6px;
 background:linear-gradient(100deg,#4c1d95,#7c3aed,#a78bfa,#7c3aed);background-size:300% 100%;
 -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;animation:flow 9s ease infinite;display:inline-flex;align-items:center;gap:10px}
.bars{display:inline-flex;align-items:flex-end;gap:3px;height:20px}
.bars i{width:4px;border-radius:2px;background:linear-gradient(180deg,#a78bfa,#7c3aed);transform-origin:bottom;animation:signal 1.7s ease-in-out infinite}
.bars i:nth-child(1){height:8px}
.bars i:nth-child(2){height:13px;animation-delay:.18s}
.bars i:nth-child(3){height:20px;animation-delay:.36s}
.hero p{margin:7px 0 0;font-size:13.5px;color:var(--muted)}
.card{background:#fff;border-radius:18px;padding:22px;box-shadow:0 12px 36px rgba(76,29,149,.12),0 2px 6px rgba(0,0,0,.04);border:1px solid rgba(237,233,254,.9);animation:rise .45s .05s ease both}
label{display:block;font-size:13px;font-weight:700;margin:0 0 6px;color:#3b0764}
.field{margin-bottom:14px;position:relative}
input{width:100%;padding:13px 14px;font-size:16px;border:1.6px solid var(--line);border-radius:11px;background:#fdfcff;transition:border-color .2s,box-shadow .2s;color:var(--txt)}
input:focus{outline:none;border-color:var(--v2);box-shadow:0 0 0 4px rgba(124,58,237,.13);background:#fff}
.field .eye{position:absolute;right:5px;bottom:6px;background:none;border:0;font-size:17px;padding:7px;cursor:pointer;color:#6b7280;border-radius:8px;line-height:1}
.field .eye:active{background:#f3f4f6}
button.primary{width:100%;padding:14px;font-size:16.5px;font-weight:800;color:#fff;border:0;border-radius:12px;cursor:pointer;
 background:linear-gradient(135deg,var(--v),var(--v2));box-shadow:0 7px 18px rgba(91,33,182,.28);transition:transform .12s,opacity .2s}
button.primary:active{transform:scale(.985)}
button.primary:disabled{opacity:.6}
.msg{display:none;margin-top:14px;padding:12px 13px;border-radius:11px;font-size:14px;animation:rise .2s ease both}
.msg.show{display:block}
.msg.ko{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}
.msg.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
details{margin-top:12px;border-top:1px solid var(--line);padding-top:12px}
summary{cursor:pointer;font-size:13px;color:var(--v);font-weight:600;list-style:none}
summary::-webkit-details-marker{display:none}
summary::before{content:"② ";}
details input{margin-top:9px;letter-spacing:3px;text-align:center;font-size:18px}
.help{text-align:center;font-size:12.5px;color:var(--muted);margin-top:16px;animation:rise .5s .15s ease both}
.help a{color:var(--v)}
footer{text-align:center;font-size:12px;color:var(--muted);padding:14px 16px 22px}
@keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes flow{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes shine{0%,70%{transform:translateX(-120%)}100%{transform:translateX(120%)}}
@keyframes signal{0%,100%{transform:scaleY(.5);opacity:.55}50%{transform:scaleY(1);opacity:1}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>
  <header class="top">
    <a class="brand" href="index.php"><span class="dot">Z</span><?php echo html($portalName); ?></a>
    <a class="home" href="index.php">Portail client</a>
  </header>

  <main>
    <div class="box">
      <div class="hero">
        <h1><span class="bars" aria-hidden="true"><i></i><i></i><i></i></span>ZinbConnect</h1>
        <p>Espace revendeur et administration</p>
      </div>

      <form class="card" id="loginForm" autocomplete="on">
        <div class="field">
          <label for="contact">Téléphone ou e-mail</label>
          <input id="contact" name="contact" type="text" autocomplete="username" placeholder="+243… ou admin@…" required>
        </div>
        <div class="field">
          <label for="password">Mot de passe</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="primary" id="loginBtn" type="submit">Se connecter</button>

        <details id="totpBox">
          <summary>J'utilise la double authentification (2FA)</summary>
          <label for="totp" style="margin-top:10px">Code à 6 chiffres</label>
          <input id="totp" name="totp" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="123456">
        </details>

        <div class="msg" id="notice" role="status" aria-live="polite"></div>
      </form>

      <p class="help">Pas encore de compte ? Les comptes revendeurs sont créés par l'administrateur.<br>
        <a href="index.php">← Retour au portail client</a></p>
    </div>
  </main>

  <footer><?php echo html($portalName); ?> · Kitutu · Zinbo Technology</footer>

<script>
(function () {
  var form = document.getElementById('loginForm');
  var btn = document.getElementById('loginBtn');
  var notice = document.getElementById('notice');
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var waitTimer = null;

  function show(text, ok) {
    notice.className = 'msg show ' + (ok ? 'ok' : 'ko');
    notice.textContent = text;
  }

  // Œil : afficher / masquer le mot de passe
  var pass = document.getElementById('password');
  var eye = document.createElement('button');
  eye.type = 'button';
  eye.className = 'eye';
  eye.textContent = '👁';
  eye.setAttribute('aria-label', 'Afficher le mot de passe');
  eye.onclick = function () {
    var showPw = pass.type === 'password';
    pass.type = showPw ? 'text' : 'password';
    eye.textContent = showPw ? '🙈' : '👁';
    eye.setAttribute('aria-label', showPw ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
  };
  pass.parentNode.appendChild(eye);

  function lockLeft(sec) {
    if (waitTimer) clearInterval(waitTimer);
    var left = Math.max(0, Math.floor(sec));
    btn.disabled = true;
    var tick = function () {
      if (left <= 0) {
        clearInterval(waitTimer);
        btn.disabled = false;
        btn.textContent = 'Se connecter';
        show('Vous pouvez réessayer.', false);
        return;
      }
      var m = Math.floor(left / 60), s = left % 60;
      btn.textContent = 'Patientez ' + (m > 0 ? m + ' min ' : '') + s + ' s';
      left--;
    };
    tick();
    waitTimer = setInterval(tick, 1000);
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    btn.disabled = true;
    btn.textContent = 'Connexion…';
    try {
      var res = await fetch('reseller.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify({
          contact: document.getElementById('contact').value,
          password: pass.value,
          totp: document.getElementById('totp').value,
          csrf: csrf
        })
      });
      var d;
      try {
        d = await res.json();
      } catch (errJson) {
        if (!res.ok) {
          show('Erreur serveur HTTP ' + res.status + ' (' + res.statusText + '). Vérifiez les logs Apache et la configuration de la base de données.', false);
        } else {
          show('Réponse serveur invalide (non JSON).', false);
        }
        btn.disabled = false;
        btn.textContent = 'Se connecter';
        return;
      }
      if (d.success) {
        show('Connexion réussie, ouverture…', true);
        location.href = d.user_type === 'admin.php' ? 'admin.php' : 'dashboard.php';
        return;
      }
      btn.disabled = false;
      btn.textContent = 'Se connecter';
      if (d.totpRequired) {
        document.getElementById('totpBox').open = true;
        show('Entrez le code à 6 chiffres de votre application d\'authentification.', false);
        document.getElementById('totp').focus();
        return;
      }
      if (d.message === 'tooManyAttempts') {
        show('Trop d\'essais. Compte verrouillé quelques minutes par sécurité.', false);
        lockLeft(d.wait || 300);
        return;
      }
      if (d.message === 'pendingApproval') {
        show('Ce compte revendeur est désactivé par l\'administrateur.', false);
        return;
      }
      if (d.message === 'csrfInvalid') {
        show('Session expirée. Rechargez la page.', false);
        return;
      }
      if (d.message === 'missingFields') {
        show('Remplissez le téléphone (ou e-mail) et le mot de passe.', false);
        return;
      }
      show('Identifiant ou mot de passe incorrect.', false);
    } catch (err) {
      btn.disabled = false;
      btn.textContent = 'Se connecter';
      show('Serveur injoignable. Vérifiez que le Wi-Fi est connecté.', false);
    }
  });
})();
</script>
</body>
</html>

<?php
/**
 * Zinbconnect — Connexion administrateur / revendeur (Phase 2 : sécurité renforcée)
 * - Configuration via .env uniquement (plus aucun identifiant en dur)
 * - PDO préparé ; mots de passe admin hachés Argon2id (l'ancien en clair est migré à la 1re connexion)
 * - Anti-brute-force : 5 essais / 15 min par IP ET par identifiant
 * - Jeton CSRF de session ; sessions régénérées à la connexion ; 2FA TOTP optionnelle ; journal d'audit
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';

security_headers();
session_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = json_input();
    csrf_require($input);

    $contact  = trim((string)($input['contact'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $totp     = isset($input['totp']) ? preg_replace('/\D+/', '', (string)$input['totp']) : null;

    $result = attempt_login($contact, $password, $totp);

    if ($result['ok']) {
        // Le cookie "30 jours" (admin_id / reseller_id) est supprimé :
        // la session PHP régénérée est la seule preuve d'accès, révocable côté serveur.
        setcookie('admin_id',   '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        setcookie('reseller_id','', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        json_out(['success' => true, 'user_type' => $result['user_type']]);
    }

    json_out([
        'success'      => false,
        'message'      => $result['message'] ?? 'invalidCredentials',
        'wait'         => (int)($result['wait'] ?? 0),
        'totpRequired' => !empty($result['totpRequired']),
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZinbConnect - Login</title>
    <meta name="csrf-token" content="<?php echo html(csrf_token()); ?>">
    <link rel="icon" type="image/x-icon" href="download.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://kit.fontawesome.com/4a5d7f6b46.js" crossorigin="anonymous"></script>
    <style>
        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.7; }
            50% { transform: scale(1.2); opacity: 1; }
            100% { transform: scale(1); opacity: 0.7; }
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .wifi-icon, .button-icon {
            animation: pulse 2s infinite;
            color: #d8b4fe;
        }
        .dark .wifi-icon, .dark .button-icon {
            color: #a78bfa;
        }
        .bg-gradient-animate {
            background: linear-gradient(45deg, #d8b4fe, #f3e8ff, #d8b4fe, #f3e8ff);
            background-size: 400%;
            animation: gradient 15s ease infinite;
        }
        .dark .bg-gradient-animate {
            background: linear-gradient(45deg, #6b7280, #1f2937, #6b7280, #1f2937);
        }
        .signal-icon-container {
            position: relative;
            display: inline-flex;
            align-items: flex-end;
            height: 1.5rem;
            width: 1.5rem;
        }
        .signal-bar {
            width: 4px;
            background: #d8b4fe;
            margin-left: 2px;
            transform-origin: bottom;
        }
        .dark .signal-bar {
            background: #a78bfa;
        }
        .signal-bar:nth-child(1) { height: 0.5rem; animation: signal 1.5s infinite; }
        .signal-bar:nth-child(2) { height: 0.8rem; animation: signal 1.5s infinite 0.3s; }
        .signal-bar:nth-child(3) { height: 1.2rem; animation: signal 1.5s infinite 0.6s; }
        .signal-icon-container:hover .signal-bar {
            animation-duration: 1s;
        }
        @keyframes signal {
            0% { transform: scaleY(1); }
            50% { transform: scaleY(1.5); }
            100% { transform: scaleY(1); }
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-animate flex flex-col font-sans text-gray-900 dark:text-gray-100 dark:bg-gray-900 transition-colors duration-300">
    <header class="w-full p-4 flex justify-between items-center bg-white dark:bg-gray-800 shadow-md">
        <div class="flex space-x-2">
            <a href="index.php" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition flex items-center">
                <i class="fas fa-home text-lg button-icon mr-1"></i>
                <span id="homeText">Home</span>
            </a>
        </div>
        <div class="flex space-x-2">
            <select id="languageSelect" class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="en">English</option>
                <option value="fr">Français</option>
                <option value="sw">Kiswahili</option>
            </select>
        </div>
    </header>

    <main class="flex-grow flex flex-col items-center justify-center p-4">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-purple-600 dark:text-purple-400 flex items-center justify-center animate-pulse">
                <div class="signal-icon-container mr-2">
                    <div class="signal-bar"></div>
                    <div class="signal-bar"></div>
                    <div class="signal-bar"></div>
                </div>
                ZinbConnect - Login
            </h1>
            <p id="welcomeText" class="text-lg mt-2">Log in to start managing access codes.</p>
        </div>
        <div class="w-full max-w-md bg-white dark:bg-gray-800 p-6 rounded-lg shadow-lg">
            <div class="flex justify-center mb-4">
                <i class="fas fa-user-lock text-4xl text-purple-600 dark:text-purple-400 wifi-icon"></i>
            </div>
            <div id="loginFormContainer" class="space-y-4">
                <input id="contact" type="text" placeholder="Enter Phone or Email" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md bg-white text-gray-900" required>
                <input id="password" type="password" placeholder="Enter Password" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                <input id="totp" type="text" inputmode="numeric" maxlength="6" placeholder="2FA code (optional)" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <button id="loginBtn" class="w-full px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">Log In</button>
            </div>
            <p id="notification" class="mt-4 text-red-500 hidden"></p>
            <div class="mt-6 text-center">
                <a href="reseller-s.php" id="signupLink" class="text-purple-600 dark:text-purple-400 hover:underline">Create a Reseller Account</a>
            </div>
        </div>
    </main>

    <footer class="w-full p-4 bg-white dark:bg-gray-800 text-center space-x-4">
        <a href="#" id="termsLink" class="text-purple-600 dark:text-purple-400 hover:underline">Terms of Service</a>
        <a href="#" id="privacyLink" class="text-purple-600 dark:text-purple-400 hover:underline">Privacy</a>
        <a href="#" id="legalLink" class="text-purple-600 dark:text-purple-400 hover:underline">Legal</a>
        <div class="mt-4 bg-gradient-to-r from-purple-100 to-purple-200 dark:from-gray-700 dark:to-gray-800 p-2 rounded-md text-sm font-semibold italic text-purple-600 dark:text-purple-400">
            <p id="zinboLink">©2025 All rights reserved - Zinbo Technology</p>
        </div>
    </footer>

    <div id="termsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Terms of Service</h2>
            <p class="text-gray-900 dark:text-gray-100">Placeholder for Terms of Service content.</p>
            <button id="closeTermsModal" class="mt-4 text-gray-600 dark:text-gray-400 hover:underline">Close</button>
        </div>
    </div>
    <div id="privacyModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Privacy Policy</h2>
            <p class="text-gray-900 dark:text-gray-100">Placeholder for Privacy Policy content.</p>
            <button id="closePrivacyModal" class="mt-4 text-gray-600 dark:text-gray-400 hover:underline">Close</button>
        </div>
    </div>
    <div id="legalModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Legal Information</h2>
            <p class="text-gray-900 dark:text-gray-100">Placeholder for Legal content.</p>
            <button id="closeLegalModal" class="mt-4 text-gray-600 dark:text-gray-400 hover:underline">Close</button>
        </div>
    </div>
    <div id="approvalModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-md w-full">
            <h2 id="approvalModalTitle" class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Awaiting Approval</h2>
            <p id="approvalModalMessage" class="text-gray-900 dark:text-gray-100">Your account is pending admin approval. Please contact the administrator to start selling access codes.</p>
            <button id="closeApprovalModal" class="mt-4 px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">Close</button>
        </div>
    </div>

    <script>
        const translations = {
            en: {
                welcome: "Log in to access codes or manage the platform.",
                home: "Home",
                login: "Log In",
                contactPlaceholder: "Enter Phone or Email",
                passwordPlaceholder: "Enter Password",
                signupLink: "Create a Reseller Account",
                terms: "Terms of Service",
                privacy: "Privacy",
                legal: "Legal",
                zinbo: "©2025 All rights reserved - Zinbo Technology",
                invalidCredentials: "Invalid credentials. Please try again.",
                missingFields: "Please fill in all fields.",
                pendingApproval: "Your account is pending admin approval. Please contact the administrator to start selling access codes.",
                approvalTitle: "Pending Approval",
                adminLoginSuccess: "Logged in as Admin. Redirecting to admin dashboard...",
                resellerLoginSuccess: "Logged in as Reseller. Redirecting to dashboard...",
                tooManyAttempts: "Too many attempts. Retry in {min} minute(s).",
                totpRequired: "Enter the 6-digit code from your authenticator app.",
                csrfInvalid: "Security check failed. Reload the page and retry."
            },
            fr: {
                welcome: "Connectez-vous pour commencer à gérer les codes d'accès.",
                home: "Accueil",
                login: "Connexion",
                contactPlaceholder: "Entrez le téléphone ou l'email",
                passwordPlaceholder: "Entrez le mot de passe",
                signupLink: "Créer un compte revendeur",
                terms: "Conditions de service",
                privacy: "Confidentialité",
                legal: "Mentions légales",
                zinbo: "©2025 Tous droits réservés - Zinbo Technology",
                invalidCredentials: "Identifiants invalides. Veuillez réessayer.",
                missingFields: "Veuillez remplir tous les champs.",
                pendingApproval: "Votre compte est en attente d'approbation. Veuillez contacter l'administrateur pour commencer à vendre des codes d'accès.",
                approvalTitle: "En attente d'approbation",
                adminLoginSuccess: "Connecté en tant qu'Admin. Redirection vers le tableau de bord admin...",
                resellerLoginSuccess: "Connecté en tant que Revendeur. Redirection vers le tableau de bord...",
                tooManyAttempts: "Trop de tentatives. Réessayez dans {min} minute(s).",
                totpRequired: "Saisissez le code à 6 chiffres de votre application d'authentification.",
                csrfInvalid: "Vérification de sécurité échouée. Rechargez la page puis réessayez."
            },
            sw: {
                welcome: "Ingia ili uanze kupata nambari za upatikanaji.",
                home: "Nyumbani",
                login: "Ingia",
                contactPlaceholder: "Ingiza Simu au Barua Pepe",
                passwordPlaceholder: "Ingiza Nenosiri",
                signupLink: "Fungua Akaunti ya Muuzaji",
                terms: "Masharti ya Huduma",
                privacy: "Faragha",
                legal: "Taarifa za Kisheria",
                zinbo: "©2025 Haki zote zimehifadhiwa - Zinbo Technology",
                invalidCredentials: "Taarifa zisizo sahihi. Tafadhali jaribu tena.",
                missingFields: "Tafadhali jaza sehemu zote.",
                pendingApproval: "Akaunti yako inasubiri idhini ya admin. Tafadhali wasiliana na msimamizi ili uanze kuuza nambari za upatikanaji.",
                approvalTitle: "Inasubiri Idhini",
                adminLoginSuccess: "Umeingia kama Admin. Inaelekeza kwenye dashibodi ya admin...",
                resellerLoginSuccess: "Umeingia kama Muuzaji. Inaelekeza kwenye dashibodi...",
                tooManyAttempts: "Jaribio nyingi sana. Jaribu tena baada ya dakika {min}.",
                totpRequired: "Ingiza nambari ya 6 kutoka kwenye programu yako ya uthibitishaji.",
                csrfInvalid: "Ukaguzi wa usalama umeshindikana. Pakia upya ukurasa kisha ujaribu tena."
            }
        };

        let currentLang = 'en';
        const languageSelect = document.getElementById('languageSelect');
        languageSelect.addEventListener('change', (e) => {
            currentLang = e.target.value;
            updateLanguage();
        });

        function updateLanguage() {
            document.getElementById('welcomeText').textContent = translations[currentLang].welcome;
            document.getElementById('homeText').textContent = translations[currentLang].home;
            document.getElementById('loginBtn').textContent = translations[currentLang].login;
            document.getElementById('contact').placeholder = translations[currentLang].contactPlaceholder;
            document.getElementById('password').placeholder = translations[currentLang].passwordPlaceholder;
            document.getElementById('signupLink').textContent = translations[currentLang].signupLink;
            document.getElementById('termsLink').textContent = translations[currentLang].terms;
            document.getElementById('privacyLink').textContent = translations[currentLang].privacy;
            document.getElementById('legalLink').textContent = translations[currentLang].legal;
            document.getElementById('zinboLink').textContent = translations[currentLang].zinbo;
            document.getElementById('approvalModalTitle').textContent = translations[currentLang].approvalTitle;
            document.getElementById('approvalModalMessage').textContent = translations[currentLang].pendingApproval;
        }

        const termsModal = document.getElementById('termsModal');
        const privacyModal = document.getElementById('privacyModal');
        const legalModal = document.getElementById('legalModal');
        const approvalModal = document.getElementById('approvalModal');
        const notification = document.getElementById('notification');

        document.getElementById('termsLink').addEventListener('click', (e) => {
            e.preventDefault();
            termsModal.classList.remove('hidden');
        });

        document.getElementById('zinboLink').addEventListener('click', (e) => {
            e.preventDefault();
            termsModal.classList.remove('hidden');
        });

        document.getElementById('privacyLink').addEventListener('click', (e) => {
            e.preventDefault();
            privacyModal.classList.remove('hidden');
        });

        document.getElementById('legalLink').addEventListener('click', (e) => {
            e.preventDefault();
            legalModal.classList.remove('hidden');
        });

        document.getElementById('closeTermsModal').addEventListener('click', () => {
            termsModal.classList.add('hidden');
        });

        document.getElementById('closePrivacyModal').addEventListener('click', () => {
            privacyModal.classList.add('hidden');
        });

        document.getElementById('closeLegalModal').addEventListener('click', () => {
            legalModal.classList.add('hidden');
        });

        document.getElementById('closeApprovalModal').addEventListener('click', () => {
            approvalModal.classList.add('hidden');
        });

        // Jeton CSRF exposé par la page (une seule lecture)
        const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        document.getElementById('loginBtn').addEventListener('click', () => {
            const contact = document.getElementById('contact').value.trim();
            const password = document.getElementById('password').value;
            const totpEl = document.getElementById('totp');
            const totp = totpEl ? totpEl.value.trim() : '';
            if (contact && password) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: JSON.stringify({ contact, password, totp })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        notification.textContent = data.user_type === 'admin.php' ? translations[currentLang].adminLoginSuccess : translations[currentLang].resellerLoginSuccess;
                        notification.classList.remove('hidden', 'text-red-500');
                        notification.classList.add('text-green-500');
                        setTimeout(() => {
                            window.location.href = data.user_type;
                        }, 800);
                    } else {
                        let msg;
                        switch (data.message) {
                            case 'pendingApproval':
                                approvalModal.classList.remove('hidden');
                                break;
                            case 'tooManyAttempts': {
                                const min = Math.max(1, Math.ceil((data.wait || 60) / 60));
                                msg = translations[currentLang].tooManyAttempts.replace('{min}', min);
                                break;
                            }
                            case 'totpRequired':
                                msg = translations[currentLang].totpRequired;
                                break;
                            case 'csrfInvalid':
                                msg = translations[currentLang].csrfInvalid;
                                break;
                            case 'missingFields':
                                msg = translations[currentLang].missingFields;
                                break;
                            default:
                                msg = translations[currentLang].invalidCredentials;
                        }
                        if (msg) {
                            notification.textContent = msg;
                            notification.classList.remove('hidden', 'text-green-500');
                            notification.classList.add('text-red-500');
                        }
                    }
                })
                .catch(() => {
                    notification.textContent = translations[currentLang].invalidCredentials;
                    notification.classList.remove('hidden', 'text-green-500');
                    notification.classList.add('text-red-500');
                });
            } else {
                notification.textContent = translations[currentLang].missingFields;
                notification.classList.remove('hidden', 'text-green-500');
                notification.classList.add('text-red-500');
            }
        });

        updateLanguage();
    </script>
</body>
</html>
<?php
session_start();
define('DB_HOST', 'sql307.infinityfree.com');
define('DB_USER', 'if0_39009379');
define('DB_PASS', 'Malinga7');
define('DB_NAME', 'if0_39009379_zinbconnect');
define('DB_PORT', 3306);

function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }
    return $conn;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $phone = $input['phone'] ?? '';
    $email = $input['email'] ?? null;
    $address = $input['address'] ?? '';
    $password = $input['password'] ?? '';
    
    if ($name && $phone && $address && $password) {
        $conn = getDBConnection();
        $query = "SELECT id FROM resellers WHERE phone = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Phone already registered']);
            $stmt->close();
            $conn->close();
            exit;
        }
        $stmt->close();
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $query = "INSERT INTO resellers (name, phone, email, address, password, status, created_at) VALUES (?, ?, ?, ?, ?, FALSE, NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('sssss', $name, $phone, $email, $address, $hashed_password);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Registration failed']);
        }
        $stmt->close();
        $conn->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZinbConnect - Reseller Signup</title>
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
                ZinbConnect - Reseller Signup
            </h1>
            <p id="welcomeText" class="text-lg mt-2">Create an account to start managing access codes.</p>
        </div>
        <div class="w-full max-w-md bg-white dark:bg-gray-800 p-6 rounded-lg shadow-lg">
            <div class="flex justify-center mb-4">
                <i class="fas fa-user-plus text-4xl text-purple-600 dark:text-purple-400 wifi-icon"></i>
            </div>
            <div id="signupFormContainer" class="space-y-4">
                <input id="name" type="text" placeholder="Enter Full Name" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                <input id="phone" type="text" placeholder="Enter Phone Number" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                <input id="email" type="email" placeholder="Enter Email (Optional)" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <textarea id="address" placeholder="Enter Address" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required></textarea>
                <input id="password" type="password" placeholder="Enter Password" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                <button id="signupBtn" class="w-full px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">Sign Up</button>
            </div>
            <p id="notification" class="mt-4 text-red-500 hidden"></p>
            <div class="mt-6 text-center">
                <a href="reseller.php" id="loginLink" class="text-purple-600 dark:text-purple-400 hover:underline">Already have an account? Log In</a>
            </div>
        </div>
    </main>
    <footer class="w-full p-4 bg-white dark:bg-gray-800 text-center space-x-4">
        <a href="#" id="termsLink" class="text-purple-600 dark:text-purple-400 hover:underline">Terms of Service</a>
        <a href="#" id="privacyLink" class="text-purple-600 dark:text-purple-400 hover:underline">Privacy</a>
        <a href="#" id="legalLink" class="text-purple-600 dark:text-purple-400 hover:underline">Legal</a>
        <br><br>
        <div class="bg-gradient-to-r from-purple-100 to-purple-200 dark:from-gray-700 dark:to-gray-800 p-2 rounded-md text-sm font-semibold italic text-purple-600 dark:text-purple-400">
            <p id="zinboLink" class="copyright-icon">©2025 All rights reserved - Zinbo Technology</p>
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
    <script>
        const translations = {
            en: {
                welcome: "Create an account to start managing access codes.",
                home: "Home",
                signup: "Sign Up",
                namePlaceholder: "Enter Full Name",
                phonePlaceholder: "Enter Phone Number",
                emailPlaceholder: "Enter Email (Optional)",
                addressPlaceholder: "Enter Address",
                passwordPlaceholder: "Enter Password",
                loginLink: "Already have an account? Log In",
                terms: "Terms of Service",
                privacy: "Privacy",
                legal: "Legal",
                zinbo: "©2025 All rights reserved - Zinbo Technology",
                phoneRegistered: "Phone number already registered.",
                registrationFailed: "Registration failed. Please try again.",
                missingFields: "Please fill in all required fields.",
                signupSuccess: "Account created successfully! Please wait for admin approval."
            },
            fr: {
                welcome: "Créez un compte pour commencer à gérer les codes d'accès.",
                home: "Accueil",
                signup: "Inscription",
                namePlaceholder: "Entrez le nom complet",
                phonePlaceholder: "Entrez le numéro de téléphone",
                emailPlaceholder: "Entrez l'email (facultatif)",
                addressPlaceholder: "Entrez l'adresse",
                passwordPlaceholder: "Entrez le mot de passe",
                loginLink: "Vous avez déjà un compte ? Connectez-vous",
                terms: "Conditions de service",
                privacy: "Confidentialité",
                legal: "Mentions légales",
                zinbo: "©2025 Tous droits réservés - Zinbo Technology",
                phoneRegistered: "Numéro de téléphone déjà enregistré.",
                registrationFailed: "Échec de l'inscription. Veuillez réessayer.",
                missingFields: "Veuillez remplir tous les champs obligatoires.",
                signupSuccess: "Compte créé avec succès ! Veuillez attendre l'approbation de l'admin."
            },
            sw: {
                welcome: "Fungua akaunti ili uanze kudhibiti nambari za upatikanaji.",
                home: "Nyumbani",
                signup: "Jisajili",
                namePlaceholder: "Ingiza Jina Kamili",
                phonePlaceholder: "Ingiza Nambari ya Simu",
                emailPlaceholder: "Ingiza Barua Pepe (Hiari)",
                addressPlaceholder: "Ingiza Anwani",
                passwordPlaceholder: "Ingiza Nenosiri",
                loginLink: "Tayari una akaunti? Ingia",
                terms: "Masharti ya Huduma",
                privacy: "Faragha",
                legal: "Taarifa za Kisheria",
                zinbo: "©2025 Haki zote zimehifadhiwa - Zinbo Technology",
                phoneRegistered: "Nambari ya simu tayari imesajiliwa.",
                registrationFailed: "Usajili umeshindwa. Tafadhali jaribu tena.",
                missingFields: "Tafadhali jaza sehemu zote za lazima.",
                signupSuccess: "Akaunti imefunguliwa kwa mafanikio! Tafadhali subiri idhini ya admin."
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
            document.getElementById('signupBtn').textContent = translations[currentLang].signup;
            document.getElementById('name').placeholder = translations[currentLang].namePlaceholder;
            document.getElementById('phone').placeholder = translations[currentLang].phonePlaceholder;
            document.getElementById('email').placeholder = translations[currentLang].emailPlaceholder;
            document.getElementById('address').placeholder = translations[currentLang].addressPlaceholder;
            document.getElementById('password').placeholder = translations[currentLang].passwordPlaceholder;
            document.getElementById('loginLink').textContent = translations[currentLang].loginLink;
            document.getElementById('termsLink').textContent = translations[currentLang].terms;
            document.getElementById('privacyLink').textContent = translations[currentLang].privacy;
            document.getElementById('legalLink').textContent = translations[currentLang].legal;
            document.getElementById('zinboLink').textContent = translations[currentLang].zinbo;
        }
        const termsModal = document.getElementById('termsModal');
        const privacyModal = document.getElementById('privacyModal');
        const legalModal = document.getElementById('legalModal');
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
        document.getElementById('signupBtn').addEventListener('click', () => {
            const name = document.getElementById('name').value.trim();
            const phone = document.getElementById('phone').value.trim();
            const email = document.getElementById('email').value.trim() || null;
            const address = document.getElementById('address').value.trim();
            const password = document.getElementById('password').value;
            if (name && phone && address && password) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, phone, email, address, password })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        notification.textContent = translations[currentLang].signupSuccess;
                        notification.classList.remove('hidden', 'text-red-500');
                        notification.classList.add('text-green-500');
                        setTimeout(() => {
                            window.location.href = 'reseller_login.php';
                        }, 2000);
                    } else {
                        notification.textContent = translations[currentLang][data.message === 'Phone already registered' ? 'phoneRegistered' : data.message === 'Registration failed' ? 'registrationFailed' : 'missingFields'];
                        notification.classList.remove('hidden', 'text-green-500');
                        notification.classList.add('text-red-500');
                    }
                })
                .catch(() => {
                    notification.textContent = translations[currentLang].registrationFailed;
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZinbConnect - Connection Successful</title>
    <link rel="icon" type="image/x-icon" href="download.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://kit.fontawesome.com/4a5d7f6b46.js" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }
        .bg-gradient-animate {
            background: linear-gradient(45deg, #d8b4fe, #f3e8ff, #d8b4fe, #f3e8ff);
            background-size: 400%;
            animation: gradient 15s ease infinite;
        }
        .dark .bg-gradient-animate {
            background: linear-gradient(45deg, #6b7280, #1f2937, #6b7280, #1f2937);
        }
        .success-icon {
            animation: pulse 2s infinite;
            color: #10b981;
        }
        .dark .success-icon {
            color: #34d399;
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
        @keyframes signal {
            0% { transform: scaleY(1); }
            50% { transform: scaleY(1.5); }
            100% { transform: scaleY(1); }
        }
        .card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
        }
        .notification {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 50;
            max-width: 20rem;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-animate flex flex-col font-sans text-gray-900 dark:text-gray-100 dark:bg-gray-900 transition-colors duration-300">
    <!-- Notification -->
    <div id="notification" class="notification hidden bg-red-500 text-white px-4 py-2 rounded-md shadow-lg"></div>

    <!-- Header -->
    <header class="w-full p-4 flex justify-between items-center bg-white dark:bg-gray-800 shadow-md">
        <div class="flex space-x-2">
            <button onclick="window.location.href='reseller.html'" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition flex items-center">
                <i class="fas fa-store text-lg mr-1"></i>
                <span id="resellerText">Reseller</span>
            </button>
            <button id="disconnectBtn" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition flex items-center">
                <i class="fas fa-sign-out-alt text-lg mr-1"></i>
                <span id="disconnectText">Disconnect</span>
            </button>
        </div>
        <div>
            <select id="languageSelect" class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="en">English</option>
                <option value="fr">Français</option>
                <option value="sw">Kiswahili</option>
            </select>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow flex flex-col items-center justify-center p-4">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-purple-600 dark:text-purple-400 flex items-center justify-center">
                <div class="signal-icon-container mr-2">
                    <div class="signal-bar"></div>
                    <div class="signal-bar"></div>
                    <div class="signal-bar"></div>
                </div>
                ZinbConnect
            </h1>
            <p id="successMessage" class="text-2xl mt-4 text-green-600 dark:text-green-400">Connection Successful!</p>
        </div>
        <div class="w-full max-w-md bg-white dark:bg-gray-800 p-6 rounded-lg shadow-lg card">
            <div class="flex justify-center mb-4">
                <i class="fas fa-check-circle text-5xl success-icon"></i>
            </div>
            <p id="connectionDetails" class="text-center text-gray-900 dark:text-gray-100 mb-4"></p>
            <p id="usageTip" class="text-center text-gray-700 dark:text-gray-300">Enjoy your high-speed internet powered by Starlink!</p>
        </div>
    </main>

    <!-- Footer -->
    <footer class="w-full p-4 bg-white dark:bg-gray-800 text-center space-x-4">
        <a href="#" id="termsLink" class="text-purple-600 dark:text-purple-400 hover:underline">Terms of Service</a>
        <a href="#" id="privacyLink" class="text-purple-600 dark:text-purple-400 hover:underline">Privacy</a>
        <a href="#" id="legalLink" class="text-purple-600 dark:text-purple-400 hover:underline">Legal</a>
        <br><br>
        <div class="bg-gradient-to-r from-purple-100 to-purple-200 dark:from-gray-700 dark:to-gray-800 p-2 rounded-md text-sm font-semibold italic text-purple-600 dark:text-purple-400">
            <p id="zinboLink">©2025 All rights reserved - Zinbo Technology</p>
        </div>
    </footer>

    <!-- Terms Modal -->
    <div id="termsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-2xl w-full max-h-[80vh] overflow-y-auto transform transition-all duration-300">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400" id="termsTitle">Terms of Service</h2>
            <div id="termsContent" class="text-gray-900 dark:text-gray-100 space-y-4">
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-info-circle mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="termsAboutUs">About ZinbConnect</span>
                    </h3>
                    <p id="termsAboutUsText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-wifi mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="termsServices">Our Services</span>
                    </h3>
                    <p id="termsServicesText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-shopping-cart mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="termsSubscriptions">Subscriptions and Payments</span>
                    </h3>
                    <p id="termsSubscriptionsText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-store mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="termsResellers">Buying from Resellers</span>
                    </h3>
                    <p id="termsResellersText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-gavel mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="termsCompliance">Legal Compliance</span>
                    </h3>
                    <p id="termsComplianceText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-headset mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="termsClaims">Claims and Support</span>
                    </h3>
                    <p id="termsClaimsText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-envelope mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="termsContact">Contact Us</span>
                    </h3>
                    <p class="flex items-center">
                        <i class="fas fa-envelope mr-2 text-purple-600 dark:text-purple-400"></i>
                        Email: <a href="mailto:zinboentreprise@gmail.com" class="ml-1 text-purple-600 dark:text-purple-400 hover:underline">zinboentreprise@gmail.com</a>
                    </p>
                    <p class="flex items-center">
                        <i class="fas fa-phone-alt mr-2 text-purple-600 dark:text-purple-400"></i>
                        Phone: <a href="tel:+243990034591" class="ml-1 text-purple-600 dark:text-purple-400 hover:underline">+243 990034591</a>
                    </p>
                </div>
            </div>
            <div class="flex justify-between mt-6">
                <button id="termsClaimBtn" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span id="termsClaimBtnText">Submit a Claim</span>
                </button>
                <button id="closeTermsModal" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition">Close</button>
            </div>
        </div>
    </div>

    <!-- Privacy Modal -->
    <div id="privacyModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-2xl w-full max-h-[80vh] overflow-y-auto transform transition-all duration-300">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400" id="privacyTitle">Privacy Policy</h2>
            <div id="privacyContent" class="text-gray-900 dark:text-gray-100 space-y-4">
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-shield-alt mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="privacyDataCollection">Data Collection</span>
                    </h3>
                    <p id="privacyDataCollectionText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-store mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="privacyResellerData">Reseller Data</span>
                    </h3>
                    <p id="privacyResellerDataText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-lock mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="privacyDataProtection">Data Protection</span>
                    </h3>
                    <p id="privacyDataProtectionText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-gavel mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="privacyCompliance">Legal Compliance</span>
                    </h3>
                    <p id="privacyComplianceText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-envelope mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="privacyContact">Contact Us</span>
                    </h3>
                    <p class="flex items-center">
                        <i class="fas fa-envelope mr-2 text-purple-600 dark:text-purple-400"></i>
                        Email: <a href="mailto:zinboentreprise@gmail.com" class="ml-1 text-purple-600 dark:text-purple-400 hover:underline">zinboentreprise@gmail.com</a>
                    </p>
                    <p class="flex items-center">
                        <i class="fas fa-phone-alt mr-2 text-purple-600 dark:text-purple-400"></i>
                        Phone: <a href="tel:+243990034591" class="ml-1 text-purple-600 dark:text-purple-400 hover:underline">+243 990034591</a>
                    </p>
                </div>
            </div>
            <div class="flex justify-end mt-6">
                <button id="closePrivacyModal" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition">Close</button>
            </div>
        </div>
    </div>

    <!-- Legal Modal -->
    <div id="legalModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-2xl w-full max-h-[80vh] overflow-y-auto transform transition-all duration-300">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400" id="legalTitle">Legal Information</h2>
            <div id="legalContent" class="text-gray-900 dark:text-gray-100 space-y-4">
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-file-contract mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="legalStatus">Legal Status</span>
                    </h3>
                    <p id="legalStatusText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-balance-scale mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="legalCompliance">Compliance and Dispute Resolution</span>
                    </h3>
                    <p id="legalComplianceText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-users mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="legalConsumerProtection">Consumer Protection</span>
                    </h3>
                    <p id="legalConsumerProtectionText"></p>
                </div>
                <div>
                    <h3 class="text-xl font-semibold flex items-center">
                        <i class="fas fa-envelope mr-2 text-purple-600 dark:text-purple-400"></i>
                        <span id="legalContact">Contact Us</span>
                    </h3>
                    <p class="flex items-center">
                        <i class="fas fa-envelope mr-2 text-purple-600 dark:text-purple-400"></i>
                        Email: <a href="mailto:zinboentreprise@gmail.com" class="ml-1 text-purple-600 dark:text-purple-400 hover:underline">zinboentreprise@gmail.com</a>
                    </p>
                    <p class="flex items-center">
                        <i class="fas fa-phone-alt mr-2 text-purple-600 dark:text-purple-400"></i>
                        Phone: <a href="tel:+243990034591" class="ml-1 text-purple-600 dark:text-purple-400 hover:underline">+243 990034591</a>
                    </p>
                </div>
            </div>
            <div class="flex justify-between mt-6">
                <button id="legalKnowMoreBtn" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition flex items-center">
                    <i class="fas fa-globe mr-2"></i>
                    <span id="legalKnowMoreBtnText">Know More About Us</span>
                </button>
                <button id="closeLegalModal" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition">Close</button>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        // XLSX processing script (retained from original)
        var gk_isXlsx = false;
        var gk_xlsxFileLookup = {};
        var gk_fileData = {};
        function filledCell(cell) {
            return cell !== '' && cell != null;
        }
        function loadFileData(filename) {
            if (gk_isXlsx && gk_xlsxFileLookup[filename]) {
                try {
                    var workbook = XLSX.read(gk_fileData[filename], { type: 'base64' });
                    var firstSheetName = workbook.SheetNames[0];
                    var worksheet = workbook.Sheets[firstSheetName];
                    var jsonData = XLSX.utils.sheet_to_json(worksheet, { header: 1, blankrows: false, defval: '' });
                    var filteredData = jsonData.filter(row => row.some(filledCell));
                    var headerRowIndex = filteredData.findIndex((row, index) =>
                        row.filter(filledCell).length >= filteredData[index + 1]?.filter(filledCell).length
                    );
                    if (headerRowIndex === -1 || headerRowIndex > 25) {
                        headerRowIndex = 0;
                    }
                    var csv = XLSX.utils.aoa_to_sheet(filteredData.slice(headerRowIndex));
                    csv = XLSX.utils.sheet_to_csv(csv, { header: 1 });
                    return csv;
                } catch (e) {
                    console.error('XLSX processing error:', e);
                    return "";
                }
            }
            return gk_fileData[filename] || "";
        }

        // Language translations (aligned with index.php)
        const translations = {
            en: {
                successMessage: "Connection Successful!",
                reseller: "Reseller",
                disconnect: "Disconnect",
                terms: "Terms of Service",
                privacy: "Privacy",
                legal: "Legal",
                zinbo: "©2025 All rights reserved - Zinbo Technology",
                usageTip: "Enjoy your high-speed internet powered by Starlink!",
                connectionDetails: "Subscription ID: {id}, Valid until: {date}",
                invalidCode: "Invalid or expired access code. Redirecting to login...",
                connectionFailedTitle: "Error",
                connectionFailedMessage: "Failed to validate connection. Please try again.",
                termsAboutUs: "About ZinbConnect",
                termsAboutUsText: "ZinbConnect, a proud division of Zinbo Technology, is dedicated to bridging the digital divide in the Democratic Republic of Congo (DRC). Starting from Bukavu, our mission is to connect more people to the Internet, empowering communities with high-speed, low-latency access using Starlink Satellite Internet technology. We aim to expand gradually across the country, unlocking new opportunities for education, business, and productivity.",
                termsServices: "Our Services",
                termsServicesText: "We provide high-bandwidth Internet subscriptions tailored to your needs, including Premium, VIP, and Turbo plans with speeds up to 50 Mb/s down and unlimited or high data caps (50 GB, 100 GB, 300 GB). Our services leverage Starlink’s cutting-edge technology to ensure reliable connectivity. Future offerings will include enterprise solutions, community Wi-Fi hubs, and digital literacy programs to further empower users.",
                termsSubscriptions: "Subscriptions and Payments",
                termsSubscriptionsText: "Users can purchase subscriptions of their choice via ZinbPay, supporting mobile money (e.g., Airtel Money) and other payment methods to be added soon. Subscriptions are non-refundable, but all transactions are stored for transparency. Any claims or disputes will be addressed based on evidence, ensuring fairness and accountability.",
                termsResellers: "Buying from Resellers",
                termsResellersText: "ZinbConnect partners with approved resellers located near our Wi-Fi stations in Bukavu. You can find them at designated hotspots, ready to assist with subscription purchases. To locate a reseller, visit our platform’s reseller section or check near Wi-Fi stations for authorized agents. Resellers provide an accessible way to purchase access codes in person.",
                termsCompliance: "Legal Compliance",
                termsComplianceText: "Zinbo Technology complies with local, national, and regional laws, including digital and telecommunications regulations in the DRC. Any fraudulent activities, such as unauthorized access or misuse of services, will be addressed under applicable laws. We are committed to maintaining a secure and transparent platform for all users.",
                termsClaims: "Claims and Support",
                termsClaimsText: "If you have a complaint, issue, misunderstanding, data violation, or privacy concern, please submit a claim through the button below. Our team will review your case promptly and fairly, ensuring transparency and resolution based on evidence.",
                termsContact: "Contact Us",
                termsClaimBtnText: "Submit a Claim",
                privacyDataCollection: "Data Collection",
                privacyDataCollectionText: "ZinbConnect, a division of Zinbo Technology, collects only essential information—your email or phone number—to send billing confirmations after purchasing a subscription. This ensures seamless communication and transparency in our transactions.",
                privacyResellerData: "Reseller Data",
                privacyResellerDataText: "For resellers, we collect additional information (e.g., name, contact details, address) to verify their identity and monitor their activities. This helps protect consumers, maintain network integrity, and ensure the reliability of Zinbo Technology in compliance with DRC’s local laws and digital regulations.",
                privacyDataProtection: "Data Protection",
                privacyDataProtectionText: "We prioritize your privacy. Our Wi-Fi network is secured against attacks, and your data will never be sold, manipulated, or shared without your consent, except when required by authorities for transparency purposes under DRC law. We employ robust security measures to safeguard your information.",
                privacyCompliance: "Legal Compliance",
                privacyComplianceText: "Zinbo Technology adheres to DRC’s digital and privacy laws, ensuring your data is handled responsibly. Any data shared with authorities is done solely to comply with legal obligations, maintaining transparency and trust.",
                privacyContact: "Contact Us",
                legalStatus: "Legal Status",
                legalStatusText: "Zinbo Technology, the parent company of ZinbConnect, is in the process of obtaining full legal registration and documentation to ensure compliance, liability, and reliability in the DRC. We will inform our users as soon as these documents are finalized.",
                legalCompliance: "Compliance and Dispute Resolution",
                legalComplianceText: "Despite our ongoing registration, we are fully committed to complying with all applicable DRC laws, including those governing digital services, technology, communications, finance, and taxation. We take all legal matters seriously and will address disputes, misunderstandings, or violations promptly to protect our consumers and maintain trust.",
                legalConsumerProtection: "Consumer Protection",
                legalConsumerProtectionText: "Zinbo Technology is dedicated to ensuring a fair and transparent experience for all users. We handle complaints and disputes with care, ensuring resolutions are based on evidence and align with local, national, and regional regulations.",
                legalContact: "Contact Us",
                legalKnowMoreBtnText: "Know More About Us"
            },
            fr: {
                successMessage: "Connexion réussie !",
                reseller: "Revendeur",
                disconnect: "Déconnexion",
                terms: "Conditions de service",
                privacy: "Confidentialité",
                legal: "Mentions légales",
                zinbo: "©2025 Tous droits réservés - Zinbo Technology",
                usageTip: "Profitez de votre Internet haut débit alimenté par Starlink !",
                connectionDetails: "ID d'abonnement : {id}, Valide jusqu'au : {date}",
                invalidCode: "Code d'accès invalide ou expiré. Redirection vers la connexion...",
                connectionFailedTitle: "Erreur",
                connectionFailedMessage: "Échec de la validation de la connexion. Veuillez réessayer.",
                termsAboutUs: "À propos de ZinbConnect",
                termsAboutUsText: "ZinbConnect, une division fière de Zinbo Technology, s'engage à réduire la fracture numérique en République Démocratique du Congo (RDC). À partir de Bukavu, notre mission est de connecter davantage de personnes à Internet, en offrant aux communautés un accès à haute vitesse et à faible latence grâce à la technologie satellite Starlink. Nous visons à nous étendre progressivement à travers le pays, ouvrant de nouvelles opportunités pour l'éducation, les affaires et la productivité.",
                termsServices: "Nos services",
                termsServicesText: "Nous proposons des abonnements Internet à large bande passante adaptés à vos besoins, incluant des forfaits Premium, VIP et Turbo avec des vitesses allant jusqu'à 50 Mb/s en téléchargement et des limites de données illimitées ou élevées (50 Go, 100 Go, 300 Go). Nos services s'appuient sur la technologie de pointe de Starlink pour garantir une connectivité fiable. Les futures offres incluront des solutions pour entreprises, des hubs Wi-Fi communautaires et des programmes d'alphabétisation numérique pour renforcer l'autonomie des utilisateurs.",
                termsSubscriptions: "Abonnements et paiements",
                termsSubscriptionsText: "Les utilisateurs peuvent acheter des abonnements de leur choix via ZinbPay, qui prend en charge le paiement par mobile money (par exemple, Airtel Money) et d'autres méthodes de paiement à venir bientôt. Les abonnements ne sont pas remboursables, mais toutes les transactions sont enregistrées pour garantir la transparence. Toute réclamation ou litige sera traité sur la base de preuves, garantissant équité et responsabilité.",
                termsResellers: "Achat auprès des revendeurs",
                termsResellersText: "ZinbConnect collabore avec des revendeurs agréés situés à proximité de nos stations Wi-Fi à Bukavu. Vous pouvez les trouver dans les hotspots désignés, prêts à aider pour l'achat d'abonnements. Pour localiser un revendeur, visitez la section des revendeurs sur notre plateforme ou cherchez près des stations Wi-Fi pour trouver des agents autorisés. Les revendeurs offrent un moyen accessible d'acheter des codes d'accès en personne.",
                termsCompliance: "Conformité légale",
                termsComplianceText: "Zinbo Technology respecte les lois locales, nationales et régionales, y compris les réglementations numériques et de télécommunications en RDC. Toute activité frauduleuse, telle que l'accès non autorisé ou l'utilisation abusive des services, sera traitée conformément aux lois applicables. Nous nous engageons à maintenir une plateforme sécurisée et transparente pour tous les utilisateurs.",
                termsClaims: "Réclamations et support",
                termsClaimsText: "Si vous avez une réclamation, un problème, un malentendu, une violation de données ou une préoccupation concernant la confidentialité, veuillez soumettre une réclamation via le bouton ci-dessous. Notre équipe examinera votre cas rapidement et équitablement, garantissant transparence et résolution basée sur des preuves.",
                termsContact: "Contactez-nous",
                termsClaimBtnText: "Soumettre une réclamation",
                privacyDataCollection: "Collecte de données",
                privacyDataCollectionText: "ZinbConnect, une division de Zinbo Technology, collecte uniquement les informations essentielles—votre email ou numéro de téléphone—pour envoyer des confirmations de facturation après l'achat d'un abonnement. Cela garantit une communication fluide et une transparence dans nos transactions.",
                privacyResellerData: "Données des revendeurs",
                privacyResellerDataText: "Pour les revendeurs, nous collectons des informations supplémentaires (par exemple, nom, coordonnées, adresse) pour vérifier leur identité et surveiller leurs activités. Cela protège les consommateurs, maintient l'intégrité du réseau et garantit la fiabilité de Zinbo Technology conformément aux lois locales et réglementations numériques de la RDC.",
                privacyDataProtection: "Protection des données",
                privacyDataProtectionText: "Nous priorisons votre confidentialité. Notre réseau Wi-Fi est sécurisé contre les attaques, et vos données ne seront jamais vendues, manipulées ou partagées sans votre consentement, sauf si les autorités en font la demande pour des raisons de transparence conformément à la loi de la RDC. Nous utilisons des mesures de sécurité robustes pour protéger vos informations.",
                privacyCompliance: "Conformité légale",
                privacyComplianceText: "Zinbo Technology respecte les lois numériques et de confidentialité de la RDC, garantissant que vos données sont gérées de manière responsable. Toute donnée partagée avec les autorités l'est uniquement pour se conformer aux obligations légales, maintenant transparence et confiance.",
                privacyContact: "Contactez-nous",
                legalStatus: "Statut légal",
                legalStatusText: "Zinbo Technology, la société mère de ZinbConnect, est en cours d'obtention d'une inscription légale complète et de documents pour garantir la conformité, la responsabilité et la fiabilité en RDC. Nous informerons nos utilisateurs dès que ces documents seront finalisés.",
                legalCompliance: "Conformité et résolution des litiges",
                legalComplianceText: "Malgré notre inscription en cours, nous nous engageons pleinement à respecter toutes les lois applicables de la RDC, y compris celles régissant les services numériques, la technologie, les communications, les finances et la fiscalité. Nous prenons tous les problèmes légaux au sérieux et traiterons les litiges, malentendus ou violations rapidement pour protéger nos consommateurs et maintenir la confiance.",
                legalConsumerProtection: "Protection des consommateurs",
                legalConsumerProtectionText: "Zinbo Technology s'engage à garantir une expérience équitable et transparente pour tous les utilisateurs. Nous traitons les plaintes et litiges avec soin, garantissant des résolutions basées sur des preuves et conformes aux réglementations locales, nationales et régionales.",
                legalContact: "Contactez-nous",
                legalKnowMoreBtnText: "En savoir plus sur nous"
            },
            sw: {
                successMessage: "Umeunganishwa kwa mafanikio!",
                reseller: "Muuzaji",
                disconnect: "Tenganisha",
                terms: "Masharti ya Huduma",
                privacy: "Faragha",
                legal: "Taarifa za Kisheria",
                zinbo: "©2025 Haki zote zimehifadhiwa - Zinbo Technology",
                usageTip: "Furahia intaneti yako ya kasi ya juu inayotumia Starlink!",
                connectionDetails: "Kitambulisho cha Usajili: {id}, Halali hadi: {date}",
                invalidCode: "Nambari ya upatikanaji sio sahihi au imekwisha muda. Inaelekeza tena kwenye kuingia...",
                connectionFailedTitle: "Hitilafu",
                connectionFailedMessage: "Imeshindwa kuthibitisha muunganisho. Tafadhali jaribu tena.",
                termsAboutUs: "Kuhusu ZinbConnect",
                termsAboutUsText: "ZinbConnect, kitengo cha fahari cha Zinbo Technology, kimejitolea kupunguza mgawanyiko wa kidijitali katika Jamhuri ya Kidemokrasia ya Kongo (DRC). Kuanzia Bukavu, dhamira yetu ni kuunganisha watu wengi zaidi kwenye Intaneti, kuwezesha jamii na upatikanaji wa kasi ya juu na latensi ya chini kwa kutumia teknolojia ya Starlink Satellite Internet. Tunakusudia kupanuka polepole kote nchini, kufungua fursa mpya za elimu, Biashara, na tija.",
                termsServices: "Huduma Zetu",
                termsServicesText: "Tunatoa usajili wa Intaneti wa kipimo data cha juu ulioboreshwa kwa mahitaji yako, ikiwa ni pamoja na mipango ya Premium, VIP, na Turbo yenye kasi hadi 50 Mb/s chini na mipaka ya data isiyo na kikomo au ya juu (50 GB, 100 GB, 300 GB). Huduma zetu zinatumia teknolojia ya hali ya juu ya Starlink ili kuhakikisha muunganisho wa kuaminika. Matoleo ya baadaye yatakuwa na suluhisho za Biashara, vituo vya Wi-Fi vya jamii, na programu za elimu ya kidijitali ili kuwezesha zaidi watumiaji.",
                termsSubscriptions: "Usajili na Malipo",
                termsSubscriptionsText: "Watumiaji wanaweza kununua usajili wa chaguo lao kupitia ZinbPay, inayoauni malipo ya pesa za simu (k.m., Airtel Money) na njia zingine za malipo zitakazoongezwa hivi karibuni. Usajili hauwezi kurudishwa pesa, lakini miamala yote inahifadhiwa kwa uwazi. Madai yoyote au mizozo itashughulikiwa kwa msingi wa ushahidi, kuhakikisha usawa na uwajibikaji.",
                termsResellers: "Kununua kutoka kwa Wauzaji",
                termsResellersText: "ZinbConnect inashirikiana na wauzaji waliokubaliwa walioko karibu na vituo vyetu vya Wi-Fi huko Bukavu. Unaweza kuwapata kwenye hotspots zilizochaguliwa, wako tayari kusaidia na ununuzi wa usajili. Ili kupata muuzaji, tembelea sehemu ya wauzaji kwenye jukwaa letu au angalia karibu na vituo vya Wi-Fi kwa mawakala walioidhinishwa. Wauzaji hutoa njia rahisi ya kununua nambari za upatikanaji moja kwa moja.",
                termsCompliance: "Uzingatiaji wa Sheria",
                termsComplianceText: "Zinbo Technology inazingatia sheria za mitaa, kitaifa, na za kikanda, ikiwa ni pamoja na kanuni za kidijitali na mawasiliano katika DRC. Shughuli zozote za udanganyifu, kama vile upatikanaji usioidhinishwa au matumizi mabaya ya huduma, zitashughulikiwa chini ya sheria zinazotumika. Tumejitolea kudumisha jukwaa salama na la uwazi kwa watumiaji wote.",
                termsClaims: "Madai na Msaada",
                termsClaimsText: "Ikiwa una malalamiko, suala, kutokuelewana, ukiukaji wa data, au wasiwasi wa faragha, tafadhali wasilisha dai kupitia kitufe hapa chini. Timu yetu itapitia kesi yako kwa haraka na kwa haki, kuhakikisha uwazi na utatuzi unaotegemea ushahidi.",
                termsContact: "Wasiliana Nasi",
                termsClaimBtnText: "Wasilisha Dai",
                privacyDataCollection: "Ukusanyiko wa Data",
                privacyDataCollectionText: "ZinbConnect, kitengo cha Zinbo Technology, hukusanya habari za msingi tu—barua pepe yako au nambari ya simu—ili kutuma uthibitisho wa bili baada ya kununua usajili. Hii inahakikisha mawasiliano ya moja kwa moja na uwazi katika miamala yetu.",
                privacyResellerData: "Data ya Wauzaji",
                privacyResellerDataText: "Kwa wauzaji, tunakusanya habari za ziada (k.m., jina, maelezo ya mawasiliano, anwani) ili kuthibitisha utambulisho wao na kufuatilia shughuli zao. Hii inasaidia kulinda wateja, kudumisha uadilifu wa mtandao, na kuhakikisha uaminifu wa Zinbo Technology kwa kufuata sheria za mitaa za DRC na kanuni za kidijitali.",
                privacyDataProtection: "Ulinzi wa Data",
                privacyDataProtectionText: "Tunapendelea faragha yako. Mtandao wetu wa Wi-Fi umelindwa dhidi ya mashambulizi, na data yako haitauzwa, kubadilishwa, au kushirikiwa bila idhini yako, isipokuwa inapohitajika na mamlaka kwa madhumuni ya uwazi chini ya sheria ya DRC. Tunatumia hatua za usalama za nguvu kulinda habari yako.",
                privacyCompliance: "Uzingatiaji wa Sheria",
                privacyComplianceText: "Zinbo Technology inazingatia sheria za kidijitali na za faragha za DRC, kuhakikisha kuwa data yako inashughulikiwa kwa uwajibikaji. Data yoyote iliyoshirikiwa na mamlaka inafanywa tu ili kuzingatia wajib wa kisheria, dudumisha uwazi na imani.",
                privacyContact: "Wasiliana Nasi",
                legalStatus: "Hali ya Kisheria",
                legalStatusText: "Zinbo Technology, kampuni ya mama ya ZinbConnect, iko katika mchakato wa kupata usajili wa kisheria kamili na hati za kuhakikisha uzingatiwa, dhima, na uaminifu katika DRC. Tutawajulisha watumiaji wetu mara tu hati hizi zitakapokamilika.",
                legalCompliance: "Uzingatiaji na Utatuzi wa Mizozo",
                legalComplianceText: "Licha ya usajili wetu unaendelea, tumejiweka kufuata sheria zote zinazotumika za DRC, ikiwa ni pamoja na zile za huduma za dijitali, teknolojia, mawasiliano, fedha, na ushuru. Tunachukua masuala yote ya kisheria kwa uzito na tutashughulikia mizozo, kutokuelewana, au ukiukaji kwa haraka ili kulinda wateja wetu na kudumisha imani.",
                legalConsumerProtection: "Ulinzi wa Wateja",
                legalConsumerProtectionText: "Zinbo Technology imejitolea kuhakikisha uzoefu wa haki na wa uwazi kwa watumiaji wote. Tunashughulikia malalamiko na mizozo kwa uangalifu, kuhakikisha utatuzi unaotegemea ushahidi na unaolingana na kanuni za mitaa, kitaifa, na za kikanda.",
                legalContact: "Wasiliana Nasi",
                legalKnowMoreBtnText: "Jua Zaidi Kuhusu Sisi"
            }
        };

        // Apply language translations
        function applyTranslations(lang) {
            const t = translations[lang] || translations['en'];
            document.getElementById('successMessage').textContent = t.successMessage;
            document.getElementById('resellerText').textContent = t.reseller;
            document.getElementById('disconnectText').textContent = t.disconnect;
            document.getElementById('termsLink').textContent = t.terms;
            document.getElementById('privacyLink').textContent = t.privacy;
            document.getElementById('legalLink').textContent = t.legal;
            document.getElementById('zinboLink').textContent = t.zinbo;
            document.getElementById('usageTip').textContent = t.usageTip;
            document.getElementById('termsTitle').textContent = t.terms;
            document.getElementById('termsAboutUs').textContent = t.termsAboutUs;
            document.getElementById('termsAboutUsText').textContent = t.termsAboutUsText;
            document.getElementById('termsServices').textContent = t.termsServices;
            document.getElementById('termsServicesText').textContent = t.termsServicesText;
            document.getElementById('termsSubscriptions').textContent = t.termsSubscriptions;
            document.getElementById('termsSubscriptionsText').textContent = t.termsSubscriptionsText;
            document.getElementById('termsResellers').textContent = t.termsResellers;
            document.getElementById('termsResellersText').textContent = t.termsResellersText;
            document.getElementById('termsCompliance').textContent = t.termsCompliance;
            document.getElementById('termsComplianceText').textContent = t.termsComplianceText;
            document.getElementById('termsClaims').textContent = t.termsClaims;
            document.getElementById('termsClaimsText').textContent = t.termsClaimsText;
            document.getElementById('termsContact').textContent = t.termsContact;
            document.getElementById('termsClaimBtnText').textContent = t.termsClaimBtnText;
            document.getElementById('privacyTitle').textContent = t.privacy;
            document.getElementById('privacyDataCollection').textContent = t.privacyDataCollection;
            document.getElementById('privacyDataCollectionText').textContent = t.privacyDataCollectionText;
            document.getElementById('privacyResellerData').textContent = t.privacyResellerData;
            document.getElementById('privacyResellerDataText').textContent = t.privacyResellerDataText;
            document.getElementById('privacyDataProtection').textContent = t.privacyDataProtection;
            document.getElementById('privacyDataProtectionText').textContent = t.privacyDataProtectionText;
            document.getElementById('privacyCompliance').textContent = t.privacyCompliance;
            document.getElementById('privacyComplianceText').textContent = t.privacyComplianceText;
            document.getElementById('privacyContact').textContent = t.privacyContact;
            document.getElementById('legalTitle').textContent = t.legal;
            document.getElementById('legalStatus').textContent = t.legalStatus;
            document.getElementById('legalStatusText').textContent = t.legalStatusText;
            document.getElementById('legalCompliance').textContent = t.legalCompliance;
            document.getElementById('legalComplianceText').textContent = t.legalComplianceText;
            document.getElementById('legalConsumerProtection').textContent = t.legalConsumerProtection;
            document.getElementById('legalConsumerProtectionText').textContent = t.legalConsumerProtectionText;
            document.getElementById('legalContact').textContent = t.legalContact;
            document.getElementById('legalKnowMoreBtnText').textContent = t.legalKnowMoreBtnText;

            // Update connection details
            const accessCode = getCookie('access_code');
            const expiration = getCookie('expiration_date');
            if (accessCode && expiration) {
                const date = new Date(expiration).toLocaleString();
                document.getElementById('connectionDetails').textContent = t.connectionDetails
                    .replace('{id}', accessCode)
                    .replace('{date}', date);
            } else {
                document.getElementById('connectionDetails').textContent = t.invalidCode;
                setTimeout(() => window.location.href = 'index.html', 3000);
            }
        }

        // Cookie handling
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }

        function deleteCookie(name) {
            document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Strict`;
        }

        // Show notification
        function showNotification(message) {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.classList.remove('hidden');
            setTimeout(() => {
                notification.classList.add('hidden');
            }, 5000);
        }

        // Validate connection
        async function validateConnection() {
            const accessCode = getCookie('access_code');
            const expiration = getCookie('expiration_date');
            const t = translations[document.getElementById('languageSelect').value] || translations['en'];

            if (!accessCode || !expiration || new Date(expiration) <= new Date()) {
                deleteCookie('access_code');
                deleteCookie('expiration_date');
                showNotification(t.invalidCode);
                setTimeout(() => window.location.href = 'index.php', 3000);
                return;
            }

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'validate_code', code: accessCode })
                });
                const result = await response.json();

                if (!result.valid) {
                    deleteCookie('access_code');
                    deleteCookie('expiration_date');
                    showNotification(t.invalidCode);
                    setTimeout(() => window.location.href = 'index.php', 3000);
                } else {
                    const date = new Date(result.expiration_date).toLocaleString();
                    document.getElementById('connectionDetails').textContent = t.connectionDetails
                        .replace('{id}', accessCode)
                        .replace('{date}', date);
                }
            } catch (error) {
                showNotification(t.connectionFailedMessage);
                setTimeout(() => window.location.href = 'index.php', 3000);
            }
        }

        // Modal handling
        function showModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.getElementById(modalId).querySelector('.transform').classList.add('scale-100', 'opacity-100');
            document.getElementById(modalId).querySelector('.transform').classList.remove('scale-95', 'opacity-0');
        }

        function hideModal(modalId) {
            document.getElementById(modalId).querySelector('.transform').classList.add('scale-95', 'opacity-0');
            document.getElementById(modalId).querySelector('.transform').classList.remove('scale-100', 'opacity-100');
            setTimeout(() => {
                document.getElementById(modalId).classList.add('hidden');
            }, 300);
        }

        // Event listeners
        document.getElementById('languageSelect').addEventListener('change', (e) => {
            applyTranslations(e.target.value);
            localStorage.setItem('preferredLanguage', e.target.value);
        });

        document.getElementById('disconnectBtn').addEventListener('click', async () => {
            deleteCookie('access_code');
            deleteCookie('expiration_date');
            try {
                await caches.delete('zinbconnect-cache');
            } catch (e) {
                console.error('Cache deletion error:', e);
            }
            window.location.href = 'index.html';
        });

        document.getElementById('termsLink').addEventListener('click', (e) => {
            e.preventDefault();
            showModal('termsModal');
        });

        document.getElementById('privacyLink').addEventListener('click', (e) => {
            e.preventDefault();
            showModal('privacyModal');
        });

        document.getElementById('legalLink').addEventListener('click', (e) => {
            e.preventDefault();
            showModal('legalModal');
        });

        document.getElementById('closeTermsModal').addEventListener('click', () => {
            hideModal('termsModal');
        });

        document.getElementById('closePrivacyModal').addEventListener('click', () => {
            hideModal('privacyModal');
        });

        document.getElementById('closeLegalModal').addEventListener('click', () => {
            hideModal('legalModal');
        });

        document.getElementById('termsClaimBtn').addEventListener('click', () => {
            window.location.href = 'mailto:zinboentreprise@gmail.com?subject=Claim Submission';
        });

        document.getElementById('legalKnowMoreBtn').addEventListener('click', () => {
            window.location.href = 'https://zinbotech.great-site.net';
        });

        // Initialize
        window.addEventListener('load', () => {
            const preferredLang = localStorage.getItem('preferredLanguage') || 'en';
            document.getElementById('languageSelect').value = preferredLang;
            applyTranslations(preferredLang);
            validateConnection();
        });
    </script>
</body>
</html>
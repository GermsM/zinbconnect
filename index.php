<?php
// Database configuration
define('DB_HOST', '');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_NAME', '');
define('DB_PORT', );

// Database connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }
    return $conn;
}

// Cleanup expired codes
function cleanupExpiredCodes($conn) {
    $conn->query("DELETE FROM current_codes WHERE expiration_date <= NOW()");
}

// Assign access code from access_codes to current_codes
function assignAccessCode($conn, $subscription_id, $contact, $contact_type) {
    $conn->begin_transaction();
    try {
        // Get a random code from access_codes
        $query = "SELECT code FROM access_codes ORDER BY RAND() LIMIT 1";
        $result = $conn->query($query);
        if ($result->num_rows === 0) {
            throw new Exception("No available access codes.");
        }
        $row = $result->fetch_assoc();
        $code = $row['code'];
        
        // Get subscription duration
        $query = "SELECT duration_days FROM subscriptions WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $subscription_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            throw new Exception("Invalid subscription ID.");
        }
        $row = $result->fetch_assoc();
        $duration_days = $row['duration_days'];
        $stmt->close();
        
        // Insert into current_codes
        $expiration_date = date('Y-m-d H:i:s', strtotime("+$duration_days days"));
        $query = "INSERT INTO current_codes (code, contact, contact_type, subscription_id, expiration_date) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('sssis', $code, $contact, $contact_type, $subscription_id, $expiration_date);
        $stmt->execute();
        $stmt->close();
        
        // Delete from access_codes
        $query = "DELETE FROM access_codes WHERE code = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        return ['success' => true, 'code' => $code, 'expiration_date' => $expiration_date];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Handle mobile money purchase
function purchaseSubscription($subscription_id, $contact, $contact_type) {
    $conn = getDBConnection();
    cleanupExpiredCodes($conn);
    $result = assignAccessCode($conn, $subscription_id, $contact, $contact_type);
    $conn->close();
    return $result;
}

// Validate access code
function validateAccessCode($code) {
    $conn = getDBConnection();
    cleanupExpiredCodes($conn);
    $query = "SELECT expiration_date FROM current_codes WHERE code = ? AND expiration_date > NOW()";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $isValid = $result->num_rows > 0;
    if ($isValid) {
        $row = $result->fetch_assoc();
        $expiration_date = $row['expiration_date'];
        $stmt->close();
        $conn->close();
        return ['valid' => true, 'expiration_date' => $expiration_date];
    }
    $stmt->close();
    $conn->close();
    return ['valid' => false];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['action'])) {
        switch ($input['action']) {
            case 'validate_code':
                $code = $input['code'] ?? '';
                $result = validateAccessCode($code);
                if ($result['valid']) {
                    // Set cookies for auto-connect
                    setcookie('access_code', $code, strtotime($result['expiration_date']), '/', '', false, true);
                    setcookie('expiration_date', $result['expiration_date'], strtotime($result['expiration_date']), '/', '', false, true);
                }
                echo json_encode($result);
                break;
                
            case 'purchase_subscription':
                $subscription_id = $input['subscription_id'] ?? 0;
                $contact = $input['contact'] ?? '';
                $contact_type = $input['contact_type'] ?? '';
                $result = purchaseSubscription($subscription_id, $contact, $contact_type);
                echo json_encode($result);
                break;
                
            case 'assign_code':
                $subscription_id = $input['subscription_id'] ?? 0;
                $contact = $input['contact'] ?? '';
                $contact_type = $input['contact_type'] ?? '';
                $conn = getDBConnection();
                $result = assignAccessCode($conn, $subscription_id, $contact, $contact_type);
                $conn->close();
                echo json_encode($result);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZinbConnect - Bukavu</title>
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
        .wifi-icon, .web-icon, .reseller-icon, .table-icon, .reseller-profile-icon, .button-icon {
            animation: pulse 2s infinite;
            color: #d8b4fe;
        }
        .dark .wifi-icon, .dark .web-icon, .dark .reseller-icon, .dark .table-icon, .dark .reseller-profile-icon, .dark .button-icon {
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
        tr {
            transition: transform 0.2s ease-in-out;
        }
        tr:hover {
            transform: scale(1.05);
        }
        .scrollable-table::-webkit-scrollbar {
            width: 8px;
        }
        .scrollable-table::-webkit-scrollbar-track {
            background: #f3e8ff;
            border-radius: 4px;
        }
        .scrollable-table::-webkit-scrollbar-thumb {
            background: #d8b4fe;
            border-radius: 4px;
        }
        .dark .scrollable-table::-webkit-scrollbar-track {
            background: #1f2937;
        }
        .dark .scrollable-table::-webkit-scrollbar-thumb {
            background: #a78bfa;
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
    <!-- Header -->
    <header class="w-full p-4 flex justify-between items-center bg-white dark:bg-gray-800 shadow-md">
        <div class="flex space-x-2">
            <button id="resellerBtn" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition flex items-center">
                <i class="fas fa-store text-lg reseller-icon mr-1"></i>
                <span id="resellerText">Reseller</span>
            </button>
            <button id="disconnectBtn" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition flex items-center hidden">
                <i class="fas fa-sign-out-alt text-lg mr-1"></i>
                <span>Disconnect</span>
            </button>
        </div>
        <div class="flex space-x-2">
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
            <h1 class="text-4xl font-bold text-purple-600 dark:text-purple-400 flex items-center justify-center animate-pulse">
                <div class="signal-icon-container mr-2">
                    <div class="signal-bar"></div>
                    <div class="signal-bar"></div>
                    <div class="signal-bar"></div>
                </div>
                ZinbConnect
            </h1>
            <p id="welcomeText" class="text-lg mt-2">Welcome to ZinbConnect! Enter your access code to connect.</p>
        </div>
        <div class="w-full max-w-md bg-white dark:bg-gray-800 p-6 rounded-lg shadow-lg">
            <div class="flex justify-center mb-4">
                <i class="fas fa-wifi text-4xl text-purple-600 dark:text-purple-400 wifi-icon"></i>
            </div>
            <div id="connectFormContainer" class="space-y-4">
                <input id="accessCode" type="text" placeholder="Enter Access Code" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                <button id="connectBtn" class="w-full px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">Connect</button>
            </div>
            <p id="notification" class="mt-4 text-red-500 hidden"></p>
            <div class="mt-6 text-center">
                <button id="buySubscriptionBtn" class="text-purple-600 dark:text-purple-400 hover:underline">Buy a Subscription</button>
            </div>
        </div>
    </main>

    <!-- Connection Status Modal -->
    <div id="connectionModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-sm w-full text-center">
            <div id="connectionIcon" class="text-4xl mb-4"></div>
            <h2 id="connectionTitle" class="text-2xl font-bold mb-2 text-purple-600 dark:text-purple-400"></h2>
            <p id="connectionMessage" class="text-gray-900 dark:text-gray-100 mb-4"></p>
            <button id="closeConnectionModal" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">OK</button>
        </div>
    </div>

    <!-- Subscription Modal -->
    <div id="subscriptionModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full">
            <h2 id="subscriptionTitle" class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Choose Subscription Option</h2>
            <div class="flex space-x-4">
                <button id="buyResellerBtn" class="sm:w-auto w-full px-3 py-1 text-sm bg-purple-600 text-white rounded-md hover:bg-purple-700 transition flex items-center">
                    <i class="fas fa-store text-sm button-icon mr-1"></i>
                    <span>Buy from a Reseller</span>
                </button>
                <button id="buyMobileMoneyBtn" class="sm:w-auto w-full px-3 py-1 text-sm bg-purple-600 text-white rounded-md hover:bg-purple-700 transition flex items-center">
                    <i class="fas fa-mobile-alt text-sm button-icon mr-1"></i>
                    <span>Buy with Mobile Money</span>
                </button>
            </div>
            <button id="closeSubscriptionModal" class="mt-4 text-gray-600 dark:text-gray-400 hover:underline">Close</button>
        </div>
    </div>

    <!-- Reseller Modal -->
    <div id="resellerModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-4xl w-full">
            <h2 id="resellerTitle" class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Reseller / Admin Panel</h2>
            <div id="bundles" class="mb-4">
                <div class="overflow-y-auto max-h-64 scrollable-table">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-purple-600 text-white sticky top-0">
                                <th id="forfaits" class="p-2 rounded-tl-lg flex items-center justify-center">
                                    <i class="fas fa-star text-sm table-icon mr-1"></i> Forfaits
                                </th>
                                <th id="duree" class="p-2 flex items-center justify-center">
                                    <i class="fas fa-clock text-sm table-icon mr-1"></i> Durée
                                </th>
                                <th id="tarifs" class="p-2 flex items-center justify-center">
                                    <i class="fas fa-money-bill text-sm table-icon mr-1"></i> Tarifs
                                </th>
                                <th id="type" class="p-2 flex items-center justify-center">
                                    <i class="fas fa-tags text-sm table-icon mr-1"></i> Type
                                </th>
                                <th id="debit" class="p-2 rounded-tr-lg flex items-center justify-center">
                                    <i class="fas fa-tachometer-alt text-sm table-icon mr-1"></i> Débit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-premium">Premium</td>
                                <td class="p-2 text-center" id="bundle-journalier">Journalier</td>
                                <td class="p-2 text-center">1 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimitee">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitPremium">525 Kb/s up, 2 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-premium">Premium</td>
                                <td class="p-2 text-center" id="bundle-troisJours">3 jours</td>
                                <td class="p-2 text-center">2 500 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimitee">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitPremium">525 Kb/s up, 2 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-premium">Premium</td>
                                <td class="p-2 text-center" id="bundle-hebdomadaire">Hebdomadaire</td>
                                <td class="p-2 text-center">5 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimitee">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitPremium">525 Kb/s up, 2 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-premium">Premium</td>
                                <td class="p-2 text-center" id="bundle-mensuel">Mensuel</td>
                                <td class="p-2 text-center">20 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimitee">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitPremium">525 Kb/s up, 2 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-vip">VIP</td>
                                <td class="p-2 text-center" id="bundle-journalier">Journalier</td>
                                <td class="p-2 text-center">2 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimitee">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitVIP">3 Mb/s up, 10 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-vip">VIP</td>
                                <td class="p-2 text-center" id="bundle-troisJours">3 jours</td>
                                <td class="p-2 text-center">4 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimitee">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitVIP">3 Mb/s up, 10 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-vip">VIP</td>
                                <td class="p-2 text-center" id="bundle-hebdomadaire">Hebdomadaire</td>
                                <td class="p-2 text-center">10 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimitee">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitVIP">3 Mb/s up, 10 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-vip">VIP</td>
                                <td class="p-2 text-center" id="bundle-mensuel">Mensuel</td>
                                <td class="p-2 text-center">35 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimitee">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitVIP">3 Mb/s up, 10 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-turbo">Turbo</td>
                                <td class="p-2 text-center" id="bundle-journalier">Journalier</td>
                                <td class="p-2 text-center">5 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-gb50">50 GB</td>
                                <td class="p-2 text-center" id="bundle-debitTurbo">10 Mb/s up, 50 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-turbo">Turbo</td>
                                <td class="p-2 text-center" id="bundle-journalier">Journalier</td>
                                <td class="p-2 text-center">10 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-gb100">100 GB</td>
                                <td class="p-2 text-center" id="bundle-debitTurbo">10 Mb/s up, 50 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-turbo">Turbo</td>
                                <td class="p-2 text-center" id="bundle-troisJours">3 jours</td>
                                <td class="p-2 text-center">15 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-gb100">100 GB</td>
                                <td class="p-2 text-center" id="bundle-debitTurbo">10 Mb/s up, 50 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-turbo">Turbo</td>
                                <td class="p-2 text-center" id="bundle-troisJours">3 jours</td>
                                <td class="p-2 text-center">30 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-gb300">300 GB</td>
                                <td class="p-2 text-center" id="bundle-debitTurbo">10 Mb/s up, 50 Mb/s down</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <h3 id="nearbyResellers" class="text-lg font-semibold">Nearby Resellers</h3>
            <ul id="resellerList" class="space-y-2 mt-2">
                <li class="flex items-center text-gray-900 dark:text-gray-100">
                    <i class="fas fa-user-circle text-2xl reseller-profile-icon mr-2"></i>
                    <span id="reseller1Name">John Doe</span> - <span id="reseller1Phone">+1234567890</span>, <span id="reseller1Address">123 Main St, Kinshasa</span>
                </li>
                <li class="flex items-center text-gray-900 dark:text-gray-100">
                    <i class="fas fa-user-circle text-2xl reseller-profile-icon mr-2"></i>
                    <span id="reseller2Name">Jane Smith</span> - <span id="reseller2Phone">+0987654321</span>, <span id="reseller2Address">456 Market Rd, Goma</span>
                </li>
            </ul>
            <h3 id="assignCodeTitle" class="text-lg font-semibold mt-4">Assign Code to User</h3>
            <div id="assignCodeForm" class="space-y-4 mt-2">
                <input id="resellerContact" type="text" placeholder="Enter Phone or Email" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                <select id="resellerContactType" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                    <option value="phone">Phone</option>
                    <option value="email">Email</option>
                </select>
                <select id="resellerSubscription" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                    <option value="1">Premium Daily - 1 000 CDF</option>
                    <option value="2">Premium 3 Days - 2 500 CDF</option>
                    <option value="3">Premium Weekly - 5 000 CDF</option>
                    <option value="4">Premium Monthly - 20 000 CDF</option>
                    <option value="5">VIP Daily - 2 000 CDF</option>
                    <option value="6">VIP 3 Days - 4 000 CDF</option>
                    <option value="7">VIP Weekly - 10 000 CDF</option>
                    <option value="8">VIP Monthly - 35 000 CDF</option>
                    <option value="9">Turbo Daily - 5 000 CDF (50 GB)</option>
                    <option value="10">Turbo Daily - 10 000 CDF (100 GB)</option>
                    <option value="11">Turbo 3 Days - 15 000 CDF (100 GB)</option>
                    <option value="12">Turbo 3 Days - 30 000 CDF (300 GB)</option>
                </select>
                <button id="assignCodeBtn" class="w-full px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">Assign Code</button>
            </div>
            <div class="flex space-x-4 mt-4">
                <button id="backResellerBtn" class="text-gray-600 dark:text-gray-400 hover:underline">Back</button>
                <button id="closeResellerModal" class="text-gray-600 dark:text-gray-400 hover:underline">Close</button>
            </div>
        </div>
    </div>

    <!-- Mobile Money Modal -->
    <div id="mobileMoneyModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-4xl w-full">
            <h2 id="mobileMoneyTitle" class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Buy with Mobile Money</h2>
            <div id="mobileBundles" class="mb-4">
                <div class="overflow-y-auto max-h-64 scrollable-table">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-purple-600 text-white sticky top-0">
                                <th id="forfaitsMobile" class="p-2 rounded-tl-lg flex items-center justify-center">
                                    <i class="fas fa-star text-sm table-icon mr-1"></i> Forfaits
                                </th>
                                <th id="dureeMobile" class="p-2 flex items-center justify-center">
                                    <i class="fas fa-clock text-sm table-icon mr-1"></i> Durée
                                </th>
                                <th id="tarifsMobile" class="p-2 flex items-center justify-center">
                                    <i class="fas fa-money-bill text-sm table-icon mr-1"></i> Tarifs
                                </th>
                                <th id="typeMobile" class="p-2 flex items-center justify-center">
                                    <i class="fas fa-tags text-sm table-icon mr-1"></i> Type
                                </th>
                                <th id="debitMobile" class="p-2 rounded-tr-lg flex items-center justify-center">
                                    <i class="fas fa-tachometer-alt text-sm table-icon mr-1"></i> Débit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-premiumMobile">Premium</td>
                                <td class="p-2 text-center" id="bundle-journalierMobile">Journalier</td>
                                <td class="p-2 text-center">1 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimiteeMobile">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitPremiumMobile">525 Kb/s up, 2 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-premiumMobile">Premium</td>
                                <td class="p-2 text-center" id="bundle-troisJoursMobile">3 jours</td>
                                <td class="p-2 text-center">2 500 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimiteeMobile">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitPremiumMobile">525 Kb/s up, 2 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-premiumMobile">Premium</td>
                                <td class="p-2 text-center" id="bundle-hebdomadaireMobile">Hebdomadaire</td>
                                <td class="p-2 text-center">5 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimiteeMobile">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitPremiumMobile">525 Kb/s up, 2 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-premiumMobile">Premium</td>
                                <td class="p-2 text-center" id="bundle-mensuelMobile">Mensuel</td>
                                <td class="p-2 text-center">20 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimiteeMobile">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitPremiumMobile">525 Kb/s up, 2 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-vipMobile">VIP</td>
                                <td class="p-2 text-center" id="bundle-journalierMobile">Journalier</td>
                                <td class="p-2 text-center">2 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimiteeMobile">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitVIPMobile">3 Mb/s up, 10 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-vipMobile">VIP</td>
                                <td class="p-2 text-center" id="bundle-troisJoursMobile">3 jours</td>
                                <td class="p-2 text-center">4 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimiteeMobile">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitVIPMobile">3 Mb/s up, 10 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-vipMobile">VIP</td>
                                <td class="p-2 text-center" id="bundle-hebdomadaireMobile">Hebdomadaire</td>
                                <td class="p-2 text-center">10 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimiteeMobile"></td>
                                <td class="p-2 text-center" id="bundle-debitVIPMobile">3 Mb/s up, 10 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-vipMobile">VIP</td>
                                <td class="p-2 text-center" id="bundle-mensuelMobile">Mensuel</td>
                                <td class="p-2 text-center">35 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-illimiteeMobile">Illimitée</td>
                                <td class="p-2 text-center" id="bundle-debitVIPMobile">3 Mb/s up, 10 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-turboMobile">Turbo</td>
                                <td class="p-2 text-center" id="bundle-journalierMobile">Journalier</td>
                                <td class="p-2 text-center">5 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-gb50Mobile">50 GB</td>
                                <td class="p-2 text-center" id="bundle-debitTurboMobile">10 Mb/s up, 50 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-turboMobile">Turbo</td>
                                <td class="p-2 text-center" id="bundle-journalierMobile">Journalier</td>
                                <td class="p-2 text-center">10 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-gb100Mobile">100 GB</td>
                                <td class="p-2 text-center" id="bundle-debitTurboMobile">10 Mb/s up, 50 Mb/s down</td>
                            </tr>
                            <tr class="bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-turboMobile">Turbo</td>
                                <td class="p-2 text-center" id="bundle-troisJoursMobile">3 jours</td>
                                <td class="p-2 text-center">15 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-gb100Mobile">100 GB</td>
                                <td class="p-2 text-center" id="bundle-debitTurboMobile">10 Mb/s up, 50 Mb/s down</td>
                            </tr>
                            <tr class="hover:bg-purple-100 dark:hover:bg-purple-900">
                                <td class="p-2 text-center" id="bundle-turboMobile">Turbo</td>
                                <td class="p-2 text-center" id="bundle-troisJoursMobile">3 jours</td>
                                <td class="p-2 text-center">30 000 CDF</td>
                                <td class="p-2 text-center" id="bundle-gb300Mobile">300 GB</td>
                                <td class="p-2 text-center" id="bundle-debitTurboMobile">10 Mb/s up, 50 Mb/s down</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div id="mobileMoneyForm" class="space-y-4">
                <input type="text" id="contactInput" placeholder="Enter Phone or Email" class="w-full px-4 py-1 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 dark:text-gray-100 text-gray-900" required>
                <select id="contactType" class="w-full px-4 py-1 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                    <option value="phone">Phone</option>
                    <option value="email">Email</option>
                </select>
                <button id="payBtn" class="w-full px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">Pay</button>
            </div>
            <div class="flex space-x-4 mt-4">
                <button id="backMobileMoneyBtn" class="text-gray-600 dark:text-gray-400 hover:underline">Back</button>
                <button id="closeMobileMoneyModal" class="text-gray-600 dark:text-gray-400 hover:underline">Close</button>
            </div>
        </div>
    </div>

    <!-- Subscription Type Modal -->
    <div id="subscriptionTypeModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full">
            <h2 id="selectSubscription" class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Select Subscription Type</h2>
            <ul class="space-y-2 max-h-64 overflow-y-auto scrollable-table">
                <li class="p-2 bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="1">
                    <span id="bundle-premium">Premium</span> <span id="bundle-journalier">Journalier</span> - 1 000 CDF
                </li>
                <li class="p-2 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="2">
                    <span id="bundle-premium">Premium</span> <span id="bundle-troisJours">3 jours</span> - 2 500 CDF
                </li>
                <li class="p-2 bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="3">
                    <span id="bundle-premium">Premium</span> <span id="bundle-hebdomadaire">Hebdomadaire</span> - 5 000 CDF
                </li>
                <li class="p-2 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="4">
                    <span id="bundle-premium">Premium</span> <span id="bundle-mensuel">Mensuel</span> - 20 000 CDF
                </li>
                <li class="p-2 bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="5">
                    <span id="bundle-vip">VIP</span> <span id="bundle-journalier">Journalier</span> - 2 000 CDF
                </li>
                <li class="p-2 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="6">
                    <span id="bundle-vip">VIP</span> <span id="bundle-troisJours">3 jours</span> - 4 000 CDF
                </li>
                <li class="p-2 bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="7">
                    <span id="bundle-vip">VIP</span> <span id="bundle-hebdomadaire">Hebdomadaire</span> - 10 000 CDF
                </li>
                <li class="p-2 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="8">
                    <span id="bundle-vip">VIP</span> <span id="bundle-mensuel">Mensuel</span> - 35 000 CDF
                </li>
                <li class="p-2 bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="9">
                    <span id="bundle-turbo">Turbo</span> <span id="bundle-journalier">Journalier</span> - 5 000 CDF (50 GB)
                </li>
                <li class="p-2 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="10">
                    <span id="bundle-turbo">Turbo</span> <span id="bundle-journalier">Journalier</span> - 10 000 CDF (100 GB)
                </li>
                <li class="p-2 bg-gray-50 dark:bg-gray-700 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="11">
                    <span id="bundle-turbo">Turbo</span> <span id="bundle-troisJours">3 jours</span> - 15 000 CDF (100 GB)
                </li>
                <li class="p-2 hover:bg-purple-100 dark:hover:bg-purple-900 cursor-pointer" data-subscription="12">
                    <span id="bundle-turbo">Turbo</span> <span id="bundle-troisJours">3 jours</span> - 30 000 CDF (300 GB)
                </li>
            </ul>
            <div class="flex space-x-4 mt-4">
                <button id="confirmSubscriptionBtn" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">Confirm</button>
                <button id="closeSubscriptionTypeModal" class="text-gray-600 dark:text-gray-400 hover:underline">Close</button>
            </div>
        </div>
    </div>

    <!-- Payment Loading Modal -->
    <div id="paymentLoadingModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full flex items-center justify-center">
            <div class="flex flex-col items-center">
                <i class="fas fa-spinner fa-spin text-4xl text-purple-600 dark:text-purple-400"></i>
                <p id="processingText" class="mt-4 text-gray-900 dark:text-gray-100">Processing Payment...</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="w-full p-4 bg-white dark:bg-gray-800 text-center space-x-4">
        <a href="#" id="termsLink" class="text-purple-600 dark:text-purple-400 hover:underline">Terms of Service</a>
        <a href="#" id="privacyLink" class="text-purple-600 dark:text-purple-400 hover:underline">Privacy</a>
        <a href="#" id="legalLink" class="text-purple-600 dark:text-purple-400 hover:underline">Legal</a>
        <br><br>
        <div class="bg-gradient-to-r from-purple-100 to-purple-200 dark:from-gray-700 dark:to-gray-800 p-2 rounded-md text-sm font-semibold italic text-purple-600 dark:text-purple-400">
            <p id="zinboLink" class="copyright-icon">©2025 All rights reserved - Zinbo Technology</p>
        </div>
    </footer>

    <!-- Terms Modal -->
    <div id="termsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Terms of Service</h2>
            <p class="text-gray-900 dark:text-gray-100">Placeholder for Terms of Service content.</p>
            <button id="closeTermsModal" class="mt-4 text-gray-600 dark:text-gray-400 hover:underline">Close</button>
        </div>
    </div>

    <!-- Privacy Modal -->
    <div id="privacyModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Privacy Policy</h2>
            <p class="text-gray-900 dark:text-gray-100">Placeholder for Privacy Policy content.</p>
            <button id="closePrivacyModal" class="mt-4 text-gray-600 dark:text-gray-400 hover:underline">Close</button>
        </div>
    </div>

    <!-- Legal Modal -->
    <div id="legalModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden">
        <div class="bg-gradient-animate p-6 rounded-lg shadow-lg max-w-lg w-full">
            <h2 class="text-2xl font-bold mb-4 text-purple-600 dark:text-purple-400">Legal Information</h2>
            <p class="text-gray-900 dark:text-gray-100">Placeholder for Legal content.</p>
            <button id="closeLegalModal" class="mt-4 text-gray-600 dark:text-gray-400 hover:underline">Close</button>
        </div>
    </div>

    <script>
        // Language translations
        const translations = {
            en: {
                welcome: "Welcome to ZinbConnect! Enter your access code to connect.",
                reseller: "Reseller",
                connect: "Connect",
                accessCodePlaceholder: "Enter Access Code",
                buySubscription: "Buy a Subscription",
                subscriptionTitle: "Choose Subscription Option",
                buyReseller: "Buy from a Reseller",
                buyMobileMoney: "Buy with Mobile Money",
                resellerTitle: "Reseller / Admin Panel",
                mobileMoneyTitle: "Buy with Mobile Money",
                pay: "Pay",
                processing: "Processing Payment...",
                terms: "Terms of Service",
                privacy: "Privacy",
                legal: "Legal",
                invalidCode: "Invalid or expired access code. Please enter a new one.",
                connected: "Connected successfully!",
                nearbyResellers: "Nearby Resellers",
                reseller1Name: "John Doe",
                reseller1Phone: "+1234567890",
                reseller1Address: "123 Main St, Kinshasa",
                reseller2Name: "Jane Smith",
                reseller2Phone: "+0987654321",
                reseller2Address: "456 Market Rd, Goma",
                forfaits: "Packages",
                duree: "Duration",
                tarifs: "Rates",
                type: "Type",
                debit: "Bandwidth",
                premium: "Premium",
                vip: "VIP",
                turbo: "Turbo",
                journalier: "Daily",
                troisJours: "3 Days",
                hebdomadaire: "Weekly",
                mensuel: "Monthly",
                illimitee: "Unlimited",
                gb50: "50 GB",
                gb100: "100 GB",
                gb300: "300 GB",
                debitPremium: "525 Kb/s up, 2 Mb/s down",
                debitVIP: "3 Mb/s up, 10 Mb/s down",
                debitTurbo: "10 Mb/s up, 50 Mb/s down",
                back: "Back",
                selectSubscription: "Select Subscription Type",
                confirm: "Confirm",
                zinbo: "©2025 All rights reserved - Zinbo Technology",
                contactPlaceholder: "Enter Phone or Email",
                purchaseSuccess: "Purchase successful! Your access code is: ",
                purchaseFailed: "Purchase failed. Please try again.",
                assignCodeTitle: "Assign Code to User",
                assignCode: "Assign Code",
                assignSuccess: "Code assigned successfully: ",
                assignFailed: "Failed to assign code: ",
                connectionSuccessTitle: "Success!",
                connectionSuccessMessage: "You are now connected to the internet.",
                connectionFailedTitle: "Error",
                connectionFailedMessage: "Invalid or expired access code.",
                disconnect: "Disconnect"
            },
            fr: {
                welcome: "Bienvenue sur ZinbConnect ! Entrez votre code d'accès pour vous connecter.",
                reseller: "Revendeur",
                connect: "Connexion",
                accessCodePlaceholder: "Entrez le code d'accès",
                buySubscription: "Acheter un abonnement",
                subscriptionTitle: "Choisir une option d'abonnement",
                buyReseller: "Acheter auprès d'un revendeur",
                buyMobileMoney: "Acheter avec Mobile Money",
                resellerTitle: "Panel Revendeur / Admin",
                mobileMoneyTitle: "Acheter avec Mobile Money",
                pay: "Payer",
                processing: "Traitement du paiement...",
                terms: "Conditions de service",
                privacy: "Confidentialité",
                legal: "Mentions légales",
                invalidCode: "Code d'accès invalide ou expiré. Veuillez en entrer un nouveau.",
                connected: "Connexion réussie !",
                nearbyResellers: "Revendeurs à proximité",
                reseller1Name: "Jean Dupont",
                reseller1Phone: "+1234567890",
                reseller1Address: "123 Rue Principale, Kinshasa",
                reseller2Name: "Marie Dubois",
                reseller2Phone: "+0987654321",
                reseller2Address: "456 Route du Marché, Goma",
                forfaits: "Forfaits",
                duree: "Durée",
                tarifs: "Tarifs",
                type: "Type",
                debit: "Débit",
                premium: "Premium",
                vip: "VIP",
                turbo: "Turbo",
                journalier: "Journalier",
                troisJours: "3 jours",
                hebdomadaire: "Hebdomadaire",
                mensuel: "Mensuel",
                illimitee: "Illimitée",
                gb50: "50 Go",
                gb100: "100 Go",
                gb300: "300 Go",
                debitPremium: "525 Kb/s montant, 2 Mb/s descendant",
                debitVIP: "3 Mb/s montant, 10 Mb/s descendant",
                debitTurbo: "10 Mb/s montant, 50 Mb/s descendant",
                back: "Retour",
                selectSubscription: "Sélectionner le type d'abonnement",
                confirm: "Confirmer",
                zinbo: "©2025 Tous droits réservés - Zinbo Technology",
                contactPlaceholder: "Entrez le téléphone ou l'email",
                purchaseSuccess: "Achat réussi ! Votre code d'accès est : ",
                purchaseFailed: "Échec de l'achat. Veuillez réessayer.",
                assignCodeTitle: "Attribuer un code à l'utilisateur",
                assignCode: "Attribuer un code",
                assignSuccess: "Code attribué avec succès : ",
                assignFailed: "Échec de l'attribution du code : ",
                connectionSuccessTitle: "Succès !",
                connectionSuccessMessage: "Vous êtes maintenant connecté à Internet.",
                connectionFailedTitle: "Erreur",
                connectionFailedMessage: "Code d'accès invalide ou expiré.",
                disconnect: "Déconnexion"
            },
            sw: {
                welcome: "Karibu ZinbConnect! Ingiza nambari yako ya upatikanaji kuunganisha.",
                reseller: "Muuzaji",
                connect: "Unganisha",
                accessCodePlaceholder: "Ingiza Nambari ya Upatikanaji",
                buySubscription: "Nunua Usajili",
                subscriptionTitle: "Chagua Chaguo la Usajili",
                buyReseller: "Nunua kutoka kwa Muuzaji",
                buyMobileMoney: "Nunua na Pesa za Simu",
                resellerTitle: "Jopo la Muuzaji / Admin",
                mobileMoneyTitle: "Nunua na Pesa za Simu",
                pay: "Lipa",
                processing: "Inachakata Malipo...",
                terms: "Masharti ya Huduma",
                privacy: "Faragha",
                legal: "Taarifa za Kisheria",
                invalidCode: "Nambari ya upatikanaji sio sahihi au imekwisha muda. Tafadhali ingiza nyingine.",
                connected: "Umeunganishwa kwa mafanikio!",
                nearbyResellers: "Wauzaji wa Karibu",
                reseller1Name: "John Doe",
                reseller1Phone: "+1234567890",
                reseller1Address: "123 Mtaa wa Mkuu, Kinshasa",
                reseller2Name: "Jane Smith",
                reseller2Phone: "+0987654321",
                reseller2Address: "456 Barabara ya Soko, Goma",
                forfaits: "Vifurushi",
                duree: "Muda",
                tarifs: "Viira",
                type: "Aina",
                debit: "Kasi",
                premium: "Premium",
                vip: "VIP",
                turbo: "Turbo",
                journalier: "Siku moja",
                troisJours: "Siku 3",
                hebdomadaire: "Wiki moja",
                mensuel: "Mwezi moja",
                illimitee: "Bila Mipaka",
                gb50: "50 GB",
                gb100: "100 GB",
                gb300: "300 GB",
                debitPremium: "525 Kb/s juu, 2 Mb/s chini",
                debitVIP: "3 Mb/s juu, 10 Mb/s chini",
                debitTurbo: "10 Mb/s juu, 50 Mb/s chini",
                back: "Rudi",
                selectSubscription: "Chagua Aina ya Usajili",
                confirm: "Thibitisha",
                zinbo: "©2025 Haki zote zimehifadhiwa - Zinbo Technology",
                contactPlaceholder: "Ingiza Simu au Barua Pepe",
                purchaseSuccess: "Ununuzi umefanikiwa! Nambari yako ya upatikanaji ni: ",
                purchaseFailed: "Ununuzi umeshindwa. Tafadhali jaribu tena.",
                assignCodeTitle: "Mpe Mtumiaji Nambari",
                assignCode: "Mpe Nambari",
                assignSuccess: "Nambari imetolewa kwa mafanikio: ",
                assignFailed: "Imeshindwa kutoa nambari: ",
                connectionSuccessTitle: "Mafanikio!",
                connectionSuccessMessage: "Sasa umeunganishwa kwenye mtandao.",
                connectionFailedTitle: "Hitilafu",
                connectionFailedMessage: "Nambari ya upatikanaji sio sahihi au imekwisha muda.",
                disconnect: "Tenganisha"
            }
        };

        // Initialize language
        let currentLang = 'en';
        const languageSelect = document.getElementById('languageSelect');
        languageSelect.addEventListener('change', (e) => {
            currentLang = e.target.value;
            updateLanguage();
        });

        function updateLanguage() {
            document.getElementById('welcomeText').textContent = translations[currentLang].welcome;
            document.getElementById('resellerText').textContent = translations[currentLang].reseller;
            document.getElementById('connectBtn').textContent = translations[currentLang].connect;
            document.getElementById('accessCode').placeholder = translations[currentLang].accessCodePlaceholder;
            document.getElementById('buySubscriptionBtn').textContent = translations[currentLang].buySubscription;
            document.getElementById('subscriptionTitle').textContent = translations[currentLang].subscriptionTitle;
            document.getElementById('buyResellerBtn').querySelector('span').textContent = translations[currentLang].buyReseller;
            document.getElementById('buyMobileMoneyBtn').querySelector('span').textContent = translations[currentLang].buyMobileMoney;
            document.getElementById('resellerTitle').textContent = translations[currentLang].resellerTitle;
            document.getElementById('mobileMoneyTitle').textContent = translations[currentLang].mobileMoneyTitle;
            document.getElementById('payBtn').textContent = translations[currentLang].pay;
            document.getElementById('processingText').textContent = translations[currentLang].processing;
            document.getElementById('termsLink').textContent = translations[currentLang].terms;
            document.getElementById('privacyLink').textContent = translations[currentLang].privacy;
            document.getElementById('zinboLink').textContent = translations[currentLang].zinbo;
            document.getElementById('legalLink').textContent = translations[currentLang].legal;
            document.getElementById('nearbyResellers').textContent = translations[currentLang].nearbyResellers;
            document.getElementById('reseller1Name').textContent = translations[currentLang].reseller1Name;
            document.getElementById('reseller1Phone').textContent = translations[currentLang].reseller1Phone;
            document.getElementById('reseller1Address').textContent = translations[currentLang].reseller1Address;
            document.getElementById('reseller2Name').textContent = translations[currentLang].reseller2Name;
            document.getElementById('reseller2Phone').textContent = translations[currentLang].reseller2Phone;
            document.getElementById('reseller2Address').textContent = translations[currentLang].reseller2Address;
            document.getElementById('forfaits').textContent = translations[currentLang].forfaits;
            document.getElementById('duree').textContent = translations[currentLang].duree;
            document.getElementById('tarifs').textContent = translations[currentLang].tarifs;
            document.getElementById('type').textContent = translations[currentLang].type;
            document.getElementById('debit').textContent = translations[currentLang].debit;
            document.getElementById('forfaitsMobile').textContent = translations[currentLang].forfaits;
            document.getElementById('dureeMobile').textContent = translations[currentLang].duree;
            document.getElementById('tarifsMobile').textContent = translations[currentLang].tarifs;
            document.getElementById('typeMobile').textContent = translations[currentLang].type;
            document.getElementById('debitMobile').textContent = translations[currentLang].debit;
            document.getElementById('selectSubscription').textContent = translations[currentLang].selectSubscription;
            document.getElementById('confirmSubscriptionBtn').textContent = translations[currentLang].confirm;
            document.getElementById('backResellerBtn').textContent = translations[currentLang].back;
            document.getElementById('backMobileMoneyBtn').textContent = translations[currentLang].back;
            document.getElementById('contactInput').placeholder = translations[currentLang].contactPlaceholder;
            document.getElementById('resellerContact').placeholder = translations[currentLang].contactPlaceholder;
            document.getElementById('assignCodeTitle').textContent = translations[currentLang].assignCodeTitle;
            document.getElementById('assignCodeBtn').textContent = translations[currentLang].assignCode;

            const bundleIds = [
                'bundle-premium', 'bundle-vip', 'bundle-turbo',
                'bundle-journalier', 'bundle-troisJours', 'bundle-hebdomadaire', 'bundle-mensuel',
                'bundle-illimitee', 'bundle-gb50', 'bundle-gb100', 'bundle-gb300',
                'bundle-debitPremium', 'bundle-debitVIP', 'bundle-debitTurbo',
                'bundle-premiumMobile', 'bundle-vipMobile', 'bundle-turboMobile',
                'bundle-journalierMobile', 'bundle-troisJoursMobile', 'bundle-hebdomadaireMobile', 'bundle-mensuelMobile',
                'bundle-illimiteeMobile', 'bundle-gb50Mobile', 'bundle-gb100Mobile', 'bundle-gb300Mobile',
                'bundle-debitPremiumMobile', 'bundle-debitVIPMobile', 'bundle-debitTurboMobile'
            ];
            bundleIds.forEach(id => {
                const elements = document.querySelectorAll(`#${id}`);
                elements.forEach(element => {
                    if (translations[currentLang][id.replace('bundle-', '').replace('Mobile', '').toLowerCase()]) {
                        element.textContent = translations[currentLang][id.replace('bundle-', '').replace('Mobile', '').toLowerCase()];
                    }
                });
            });
        }

        // Modal controls
        const subscriptionModal = document.getElementById('subscriptionModal');
        const resellerModal = document.getElementById('resellerModal');
        const mobileMoneyModal = document.getElementById('mobileMoneyModal');
        const subscriptionTypeModal = document.getElementById('subscriptionTypeModal');
        const paymentLoadingModal = document.getElementById('paymentLoadingModal');
        const connectionModal = document.getElementById('connectionModal');
        const termsModal = document.getElementById('termsModal');
        const privacyModal = document.getElementById('privacyModal');
        const legalModal = document.getElementById('legalModal');
        const notification = document.getElementById('notification');
        const disconnectBtn = document.getElementById('disconnectBtn');

        // Auto-connect on page load
        function autoConnect() {
            const code = getCookie('access_code');
            const expirationDate = getCookie('expiration_date');
            if (code && expirationDate && new Date(expirationDate) > new Date()) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'validate_code', code })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.valid) {
                        showConnectionModal(true);
                        disconnectBtn.classList.remove('hidden');
                        document.getElementById('connectFormContainer').innerHTML = `<p class="text-green-500 text-center">${translations[currentLang].connected}</p>`;
                    } else {
                        clearCookies();
                    }
                })
                .catch(() => clearCookies());
            }
        }

        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }

        function clearCookies() {
            document.cookie = 'access_code=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            document.cookie = 'expiration_date=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            disconnectBtn.classList.add('hidden');
            document.getElementById('connectFormContainer').innerHTML = `
                <input id="accessCode" type="text" placeholder="${translations[currentLang].accessCodePlaceholder}" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                <button id="connectBtn" class="w-full px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">${translations[currentLang].connect}</button>
            `;
            bindConnectButton();
        }

        function showConnectionModal(success) {
            const icon = document.getElementById('connectionIcon');
            const title = document.getElementById('connectionTitle');
            const message = document.getElementById('connectionMessage');
            if (success) {
                icon.innerHTML = '<i class="fas fa-check-circle text-green-500"></i>';
                title.textContent = translations[currentLang].connectionSuccessTitle;
                message.textContent = translations[currentLang].connectionSuccessMessage;
                connectionModal.classList.remove('hidden');
                setTimeout(() => {
                    // Placeholder for router redirection
                    window.location.href = 'https://www.google.com'; // Replace with router API call
                }, 2000);
            } else {
                icon.innerHTML = '<i class="fas fa-times-circle text-red-500"></i>';
                title.textContent = translations[currentLang].connectionFailedTitle;
                message.textContent = translations[currentLang].connectionFailedMessage;
                connectionModal.classList.remove('hidden');
            }
        }

        function bindConnectButton() {
            document.getElementById('connectBtn').addEventListener('click', () => {
                const code = document.getElementById('accessCode').value.trim();
                if (code) {
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'validate_code', code })
                    })
                    .then(response => response.json())
                    .then(data => {
                        showConnectionModal(data.valid);
                        if (data.valid) {
                            disconnectBtn.classList.remove('hidden');
                            document.getElementById('connectFormContainer').innerHTML = `<p class="text-green-500 text-center">${translations[currentLang].connected}</p>`;
                        }
                    })
                    .catch(() => showConnectionModal(false));
                } else {
                    notification.textContent = translations[currentLang].invalidCode;
                    notification.classList.remove('hidden', 'text-green-500');
                    notification.classList.add('text-red-500');
                }
            });
        }

        document.getElementById('buySubscriptionBtn').addEventListener('click', () => {
            subscriptionModal.classList.remove('hidden');
        });

        document.getElementById('buyResellerBtn').addEventListener('click', () => {
            subscriptionModal.classList.add('hidden');
            resellerModal.classList.remove('hidden');
        });

        document.getElementById('buyMobileMoneyBtn').addEventListener('click', () => {
            subscriptionModal.classList.add('hidden');
            mobileMoneyModal.classList.remove('hidden');
        });

        document.getElementById('payBtn').addEventListener('click', () => {
            mobileMoneyModal.classList.add('hidden');
            subscriptionTypeModal.classList.remove('hidden');
        });

        document.getElementById('confirmSubscriptionBtn').addEventListener('click', () => {
            const selectedSubscription = document.querySelector('#subscriptionTypeModal li.bg-purple-100, #subscriptionTypeModal li.bg-purple-900');
            if (selectedSubscription) {
                const subscriptionId = selectedSubscription.getAttribute('data-subscription');
                const contact = document.getElementById('contactInput').value;
                const contactType = document.getElementById('contactType').value;
                
                subscriptionTypeModal.classList.add('hidden');
                paymentLoadingModal.classList.remove('hidden');
                
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'purchase_subscription', subscription_id: subscriptionId, contact, contact_type })
})
.then(response => response.json())
.then(data => {
paymentLoadingModal.classList.add('hidden');
if (data.success) {
notification.textContent = translations[currentLang].purchaseSuccess + data.code;
notification.classList.remove('hidden', 'text-red-500');
notification.classList.add('text-green-500');
document.getElementById('accessCode').value = data.code;
} else {
notification.textContent = translations[currentLang].purchaseFailed;
notification.classList.remove('hidden', 'text-green-500');
notification.classList.add('text-red-500');
}
})
.catch(() => {
paymentLoadingModal.classList.add('hidden');
notification.textContent = translations[currentLang].purchaseFailed;
notification.classList.remove('hidden', 'text-green-500');
notification.classList.add('text-red-500');
});
}
});

document.getElementById('resellerBtn').addEventListener('click', () => {
resellerModal.classList.remove('hidden');
});

document.getElementById('backResellerBtn').addEventListener('click', () => {
resellerModal.classList.add('hidden');
subscriptionModal.classList.remove('hidden');
});

document.getElementById('backMobileMoneyBtn').addEventListener('click', () => {
mobileMoneyModal.classList.add('hidden');
subscriptionModal.classList.remove('hidden');
});

document.getElementById('closeSubscriptionModal').addEventListener('click', () => {
subscriptionModal.classList.add('hidden');
});

document.getElementById('closeResellerModal').addEventListener('click', () => {
resellerModal.classList.add('hidden');
});

document.getElementById('closeMobileMoneyModal').addEventListener('click', () => {
mobileMoneyModal.classList.add('hidden');
});

document.getElementById('closeSubscriptionTypeModal').addEventListener('click', () => {
subscriptionTypeModal.classList.add('hidden');
});

document.getElementById('closeConnectionModal').addEventListener('click', () => {
connectionModal.classList.add('hidden');
});

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

document.getElementById('assignCodeBtn').addEventListener('click', () => {
const subscriptionId = document.getElementById('resellerSubscription').value;
const contact = document.getElementById('resellerContact').value;
const contactType = document.getElementById('resellerContactType').value;

fetch('', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'assign_code', subscription_id: subscriptionId, contact, contact_type: contactType })
})
.then(response => response.json())
.then(data => {
if (data.success) {
notification.textContent = translations[currentLang].assignSuccess + data.code;
notification.classList.remove('hidden', 'text-red-500');
notification.classList.add('text-green-500');
} else {
notification.textContent = translations[currentLang].assignFailed + (data.message || '');
notification.classList.remove('hidden', 'text-green-500');
notification.classList.add('text-red-500');
}
})
.catch(() => {
notification.textContent = translations[currentLang].assignFailed;
notification.classList.remove('hidden', 'text-green-500');
notification.classList.add('text-red-500');
});
});

document.getElementById('disconnectBtn').addEventListener('click', () => {
clearCookies();
notification.textContent = translations[currentLang].disconnect;
notification.classList.remove('hidden', 'text-red-500');
notification.classList.add('text-green-500');
// Placeholder for router disconnect (e.g., API call to revoke access)
});

// Highlight selected subscription
document.querySelectorAll('#subscriptionTypeModal li').forEach(item => {
item.addEventListener('click', () => {
document.querySelectorAll('#subscriptionTypeModal li').forEach(li => {
li.classList.remove('bg-purple-100', 'dark:bg-purple-900');
});
item.classList.add('bg-purple-100', 'dark:bg-purple-900');
});
});

// Initialize
updateLanguage();
bindConnectButton();
autoConnect();
</script>
</body>
</html>

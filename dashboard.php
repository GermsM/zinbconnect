<?php
// Start output buffering to capture unintended output
ob_start();
session_start();

// Log errors to file instead of displaying
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/debug.log');
error_reporting(E_ALL);

// Database configuration
define('DB_HOST', '');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_NAME', '');
define('DB_PORT', );

function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }
    return $conn;
}

// Centralized logging function
function logDebug($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents(__DIR__ . '/debug.log', $log, FILE_APPEND);
}

// Check if reseller is logged in
if (!isset($_SESSION['reseller_id'])) {
    logDebug("No reseller_id in session, redirecting to reseller.php", 'WARNING');
    header('Location: reseller.php');
    exit;
}
$reseller_id = $_SESSION['reseller_id'];

// Validate reseller_id and get first name
$conn = getDBConnection();
$query = "SELECT name FROM resellers WHERE id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    logDebug("Prepare failed for reseller query: " . $conn->error, 'ERROR');
    header('Location: reseller.php');
    exit;
}
$stmt->bind_param('i', $reseller_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    logDebug("Invalid reseller_id: $reseller_id", 'ERROR');
    header('Location: reseller.php');
    exit;
}
$row = $result->fetch_assoc();
$reseller_name = $row['name'] ? htmlspecialchars(trim($row['name'])) : 'Reseller';
$first_name = explode(' ', $reseller_name)[0] ?? 'Reseller';
$stmt->close();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logDebug("Invalid JSON input: " . json_last_error_msg(), 'ERROR');
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    $action = $input['action'] ?? '';
    logDebug("Received POST request with action: $action");
    
    switch ($action) {
        case 'logout':
            session_unset();
            session_destroy();
            setcookie('reseller_id', '', time() - 3600, '/', '', false, true);
            logDebug("Logout successful for reseller_id: $reseller_id");
            echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            exit;

        case 'activate_code':
            $subscription_id = $input['subscription_id'] ?? 0;
            $buyer_contact = trim($input['buyer_contact'] ?? '');
            if ($subscription_id && $buyer_contact) {
                $conn->begin_transaction();
                try {
                    // Check if access codes are available
                    $query = "SELECT COUNT(*) AS code_count FROM access_codes";
                    $result = $conn->query($query);
                    if (!$result) {
                        throw new Exception("Failed to check access codes: " . $conn->error);
                    }
                    if ($result->fetch_assoc()['code_count'] == 0) {
                        throw new Exception("No available access codes");
                    }

                    // Get and delete access code
                    $query = "SELECT code FROM access_codes LIMIT 1 FOR UPDATE";
                    $result = $conn->query($query);
                    if (!$result || $result->num_rows === 0) {
                        throw new Exception("No available access codes");
                    }
                    $code = $result->fetch_assoc()['code'];
                    
                    $query = "DELETE FROM access_codes WHERE code = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) throw new Exception("Prepare failed for delete: " . $conn->error);
                    $stmt->bind_param('s', $code);
                    if (!$stmt->execute()) throw new Exception("Delete failed: " . $stmt->error);
                    $stmt->close();
                    
                    // Get subscription details
                    $query = "SELECT id, name, rate, duration_days FROM subscriptions WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) throw new Exception("Prepare failed for subscription: " . $conn->error);
                    $stmt->bind_param('i', $subscription_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows === 0) throw new Exception("Invalid subscription");
                    $row = $result->fetch_assoc();
                    $subscription_name = $row['name'];
                    $rate = $row['rate'];
                    $duration_days = $row['duration_days'] ?? 30;
                    $commission = $rate * 0.25;
                    $expiration_date = date('Y-m-d H:i:s', strtotime("+$duration_days days"));
                    $stmt->close();
                    
                    // Validate contact type (relaxed phone regex)
                    $contact_type = preg_match('/^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$/', $buyer_contact) ? 'email' :
                        (preg_match('/^\+?\d{7,15}$/', $buyer_contact) ? 'phone' : null);
                    if (!$contact_type) throw new Exception("Invalid contact format: $buyer_contact");
                    if ($contact_type === 'phone' && !preg_match('/^\+\d{7,15}$/', $buyer_contact)) {
                        $buyer_contact = '+243' . ltrim($buyer_contact, '0');
                        if (!preg_match('/^\+\d{7,15}$/', $buyer_contact)) {
                            throw new Exception("Invalid phone number after formatting: $buyer_contact");
                        }
                    }
                    
                    // Payment confirmation
                    logDebug("Sending $contact_type confirmation to $buyer_contact for code $code");
                    
                    // Insert transaction
                    $query = "INSERT INTO transactions (reseller_id, subscription_id, buyer_contact, access_code, amount, commission, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) throw new Exception("Prepare failed for transaction: " . $conn->error);
                    $stmt->bind_param('iissdd', $reseller_id, $subscription_id, $buyer_contact, $code, $rate, $commission);
                    if (!$stmt->execute()) throw new Exception("Insert transaction failed: " . $stmt->error);
                    $stmt->close();
                    
                    // Insert into current_codes
                    $query = "INSERT INTO current_codes (code, contact, contact_type, subscription_id, created_at, expiration_date) VALUES (?, ?, ?, ?, NOW(), ?)";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) throw new Exception("Prepare failed for current_codes: " . $conn->error);
                    $stmt->bind_param('sssls', $code, $buyer_contact, $contact_type, $subscription_id, $expiration_date);
                    if (!$stmt->execute()) throw new Exception("Insert current_codes failed: " . $stmt->error);
                    $stmt->close();
                    
                    $conn->commit();
                    logDebug("Code activated successfully: $code for reseller_id: $reseller_id");
                    echo json_encode([
                        'success' => true,
                        'code' => $code,
                        'rate' => $rate,
                        'commission' => $commission,
                        'contact_type' => $contact_type,
                        'buyer_contact' => $buyer_contact,
                        'subscription_name' => $subscription_name,
                        'expiration_date' => $expiration_date
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = $e->getMessage();
                    logDebug("Activate code error: $error_message", 'ERROR');
                    $user_message = match (true) {
                        str_contains($error_message, 'No available access codes') => 'noCodesAvailable',
                        str_contains($error_message, 'Invalid subscription') => 'invalidSubscription',
                        str_contains($error_message, 'Invalid contact format') || str_contains($error_message, 'Invalid phone number') => 'invalidContact',
                        default => 'activationFailed'
                    };
                    echo json_encode(['success' => false, 'message' => $user_message, 'debug' => $error_message]);
                }
            } else {
                logDebug("Invalid fields: subscription_id=$subscription_id, buyer_contact=$buyer_contact", 'ERROR');
                echo json_encode(['success' => false, 'message' => 'invalidFields']);
            }
            break;
            
        case 'get_transactions':
            $query = "SELECT t.id, s.name AS subscription, t.buyer_contact, t.access_code, t.amount, t.commission, t.created_at 
                      FROM transactions t 
                      JOIN subscriptions s ON t.subscription_id = s.id 
                      WHERE t.reseller_id = ? 
                      ORDER BY t.id DESC";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                logDebug("Prepare failed: " . $conn->error, 'ERROR');
                echo json_encode(['success' => false, 'message' => 'dbQueryFailed']);
                exit;
            }
            $stmt->bind_param('i', $reseller_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
            logDebug("Fetched " . count($transactions) . " transactions for reseller_id: $reseller_id");
            echo json_encode(['success' => true, 'transactions' => $transactions]);
            $stmt->close();
            break;
            
        case 'get_earnings_data':
            $query = "SELECT SUM(commission) AS total_earnings FROM transactions WHERE reseller_id = ?";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                logDebug("Prepare failed: " . $conn->error, 'ERROR');
                echo json_encode(['success' => false, 'message' => 'dbQueryFailed']);
                exit;
            }
            $stmt->bind_param('i', $reseller_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $personal_earnings = $result->fetch_assoc()['total_earnings'] ?? 0;
            $stmt->close();
            
            $query = "SELECT s.name AS subscription, COUNT(*) AS count 
                      FROM transactions t JOIN subscriptions s ON t.subscription_id = s.id 
                      WHERE t.reseller_id = ? GROUP BY s.id";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                logDebug("Prepare failed: " . $conn->error, 'ERROR');
                echo json_encode(['success' => false, 'message' => 'dbQueryFailed']);
                exit;
            }
            $stmt->bind_param('i', $reseller_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $pie_data = ['labels' => [], 'data' => []];
            while ($row = $result->fetch_assoc()) {
                $pie_data['labels'][] = $row['subscription'];
                $pie_data['data'][] = $row['count'];
            }
            $stmt->close();
            
            $query = "SELECT DATE(created_at) AS date, SUM(commission) AS daily_earnings 
                      FROM transactions 
                      WHERE reseller_id = ? 
                      GROUP BY DATE(created_at) ORDER BY date LIMIT 30";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                logDebug("Commission earnings query failed: " . $conn->error, 'ERROR');
                echo json_encode(['success' => false, 'message' => 'dbQueryFailed']);
                exit;
            }
            $stmt->bind_param('i', $reseller_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $line_data = ['labels' => [], 'data' => []];
            while ($row = $result->fetch_assoc()) {
                $line_data['labels'][] = $row['date'];
                $line_data['data'][] = $row['daily_earnings'];
            }
            
            logDebug("Earnings data fetched: personal=$personal_earnings");
            echo json_encode([
                'success' => true,
                'personal_earnings' => $personal_earnings,
                'pie_data' => $pie_data,
                'line_data' => $line_data
            ]);
            break;
            
        default:
            logDebug("Invalid action: $action", 'ERROR');
            echo json_encode(['success' => false, 'message' => 'invalidAction']);
    }
    $conn->close();
    ob_end_clean();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZinbConnect - Reseller Dashboard</title>
    <link rel="icon" type="image/x-icon" href="download.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://kit.fontawesome.com/4a5d7f6b46.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <style>
        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.7; }
            50% { transform: scale(1.2); opacity: 1; }
            100% { transform: scale(1); opacity: 0.7; }
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .wifi-icon { animation: pulse 2s infinite; color: #a78bfa; }
        .dark .wifi-icon { color: #d8b4fe; }
        .bg-gradient-animate {
            background: linear-gradient(135deg, #a78bfa, #d8b4fe, #f3e8ff, #a78bfa);
            background-size: 400%;
            animation: gradient 12s ease infinite;
        }
        .dark .bg-gradient-animate {
            background: linear-gradient(135deg, #1f2937, #374151, #6b7280, #1f2937);
            background-size: 400%;
            animation: gradient 12s ease infinite;
        }
        .modal { animation: fadeIn 0.3s ease-out; }
        .card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2); }
        .btn { transition: background-color 0.3s ease, transform 0.2s ease; }
        .btn:hover { transform: scale(1.05); }
        .logo-text {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(90deg, #a78bfa, #d8b4fe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .table-container {
            max-height: 300px;
            overflow-y: auto;
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: #a78bfa #f3e8ff;
        }
        .table-container::-webkit-scrollbar {
            width: 8px;
        }
        .table-container::-webkit-scrollbar-track {
            background: #f3e8ff;
        }
        .table-container::-webkit-scrollbar-thumb {
            background: #a78bfa;
            border-radius: 4px;
        }
        th { position: sticky; top: 0; background: #f3f4f6; z-index: 10; }
        .dark th { background: #374151; }
        tr:hover { background-color: #f5f3ff; }
        .dark tr:hover { background-color: #4b5563; }
        .logout-btn { color: #ffffff; background-color: #dc2626; }
        .dark .logout-btn { background-color: #b91c1c; }
        @media (max-width: 640px) {
            .logo-text { font-size: 1.25rem; position: static; transform: none; }
            .logout-btn { padding: 0.5rem 1rem; font-size: 0.875rem; }
        }
        @font-face {
            font-family: 'Poppins';
            src: url('https://fonts.googleapis.com/css2?family=Poppins:wght@700&display=swap');
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-animate flex flex-col font-sans text-gray-900 dark:text-gray-100 dark:bg-gray-900 relative">
    <div id="particles-js" class="absolute inset-0 z-0 opacity-15"></div>
    <header class="w-full p-4 flex justify-between items-center bg-white dark:bg-gray-800 shadow-lg relative z-10">
        <h1 class="text-xl sm:text-2xl font-bold logo-text">ZinbConnect</h1>
        <div class="flex items-center space-x-2">
            <button id="logoutBtn" class="px-2 py-1 sm:px-4 sm:py-2 text-sm sm:text-base bg-red-600 text-white rounded-md hover:bg-red-700 flex items-center logout-btn btn">
                <i class="fas fa-sign-out-alt mr-1 sm:mr-2"></i> <span id="logoutText">Logout</span>
            </button>
            <select id="languageSelect" class="px-2 py-1 sm:px-3 sm:py-2 text-sm sm:text-base border rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="en">English</option>
                <option value="fr">Français</option>
                <option value="sw">Kiswahili</option>
            </select>
        </div>
    </header>

    <main class="flex-grow p-6 max-w-7xl mx-auto w-full relative z-10">
        <div class="text-center mb-8">
            <h1 class="text-3xl sm:text-4xl font-bold text-purple-600 dark:text-purple-400 text-opacity-100" id="welcomeText">Welcome, <?php echo htmlspecialchars($first_name); ?></h1>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 p-6 rounded-xl shadow-lg card">
                <h2 class="text-xl font-bold mb-4 text-purple-600 dark:text-purple-400 text-opacity-100 flex items-center">
                    <i class="fas fa-key mr-2 wifi-icon"></i> <span id="activateCodeTitle">Activate Code</span>
                </h2>
                <div class="space-y-4">
                    <select id="subscriptionId" class="w-full p-2 border rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-opacity-100" required>
                        <option value="" id="selectSubscription">Select Subscription</option>
                        <?php
                        $query = "SELECT id, name, rate FROM subscriptions ORDER BY id";
                        $result = $conn->query($query);
                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                echo "<option value='{$row['id']}' data-rate='{$row['rate']}'>{$row['name']} - {$row['rate']} CDF</option>";
                            }
                        } else {
                            echo "<option value='' disabled id='noSubscriptions'>No subscriptions available</option>";
                            logDebug("No subscriptions found: " . ($result ? "Empty result" : $conn->error), 'WARNING');
                        }
                        ?>
                    </select>
                    <input id="buyerContact" type="text" class="w-full p-2 border rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-opacity-100" placeholder="Phone or Email" required>
                    <button id="activateBtn" class="w-full p-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 btn">Activate</button>
                </div>
                <div id="codeResult" class="mt-4 hidden">
                    <p id="codeDetails" class="text-gray-700 dark:text-gray-300"></p>
                    <div class="flex flex-wrap gap-2 mt-2">
                        <button id="copyCode" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 btn"><i class="fas fa-copy mr-1"></i> <span id="copyText">Copy</span></button>
                        <button id="printCode" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 btn"><i class="fas fa-print mr-1"></i> <span id="printText">Print</span></button>
                        <button id="shareCode" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 btn"><i class="fas fa-share-alt mr-1"></i> <span id="shareText">Share</span></button>
                    </div>
                </div>
            </div>

            <div class="bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 p-6 rounded-xl shadow-lg card">
                <h2 class="text-xl font-bold mb-4 text-purple-600 dark:text-purple-400 text-opacity-100 flex items-center">
                    <i class="fas fa-chart-line mr-2 wifi-icon"></i> <span id="earningsTitle">Your Earnings</span>
                </h2>
                <div class="text-center mb-4">
                    <p id="personalEarnings" class="text-2xl font-bold text-green-600 text-opacity-100">0 CDF</p>
                </div>
                <div class="flex justify-between mb-4">
                    <button id="exportEarnings" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 btn">
                        <i class="fas fa-file-excel mr-1"></i> <span id="exportEarningsText">Export</span>
                    </button>
                    <button id="viewInsights" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 btn">
                        <i class="fas fa-lightbulb mr-1"></i> <span id="insightsText">View Growth Tips</span>
                    </button>
                </div>
                <div class="grid grid-cols-1 gap-4">
                    <canvas id="pieChart" class="w-full h-48 sm:h-64"></canvas>
                    <canvas id="lineChart" class="w-full h-48 sm:h-64"></canvas>
                </div>
            </div>

            <div class="bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 p-6 rounded-xl shadow-lg lg:col-span-2 card">
                <h2 class="text-xl font-bold mb-4 text-purple-600 dark:text-purple-400 text-opacity-100 flex items-center">
                    <i class="fas fa-table mr-2 wifi-icon"></i> <span id="transactionsTitle">Your Transactions</span>
                </h2>
                <button id="exportTransactions" class="mb-4 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 btn">
                    <i class="fas fa-file-excel mr-1"></i> <span id="exportTransactionsText">Export</span>
                </button>
                <div class="overflow-x-auto">
                    <table id="transactionsTable" class="w-full border-collapse">
                        <thead>
                            <tr class="bg-purple-100 dark:bg-gray-800">
                                <th class="p-2 text-left" id="thId">ID</th>
                                <th class="p-2 text-left" id="thSubscription">Subscription</th>
                                <th class="p-2 text-left" id="thBuyerContact">Buyer Contact</th>
                                <th class="p-2 text-left" id="thCode">Code</th>
                                <th class="p-2 text-left" id="thAmount">Amount (CDF)</th>
                                <th class="p-2 text-left" id="thCommission">Commission (CDF)</th>
                                <th class="p-2 text-left" id="thDate">Date</th>
                            </tr>
                        </thead>
                        <tbody id="transactionsBody" class="table-container"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div id="modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-20">
        <div class="bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 p-6 rounded-xl shadow-2xl max-w-md w-11/12 sm:w-full modal">
            <div class="flex justify-between items-center mb-4">
                <h3 id="modalTitle" class="text-lg font-bold text-purple-600 dark:text-purple-400 text-opacity-100"></h3>
                <button id="closeModal" class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p id="modalMessage" class="text-gray-700 dark:text-gray-300 text-opacity-100"></p>
            <div id="modalContent" class="mt-4"></div>
            <div class="mt-6 flex justify-end">
                <button id="modalCloseBtn" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 btn">Close</button>
            </div>
        </div>
    </div>

    <footer class="w-full p-4 bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 text-center text-purple-600 dark:text-purple-400 text-opacity-100 relative z-10">
        <p id="footerText">©2025 Zinbo Technology</p>
    </footer>

    <script>
        // Initialize Particles.js
        particlesJS('particles-js', {
            particles: {
                number: { value: 50, density: { enable: true, value_area: 800 } },
                color: { value: '#a78bfa' },
                shape: { type: 'circle' },
                opacity: { value: 0.5, random: true },
                size: { value: 3, random: true },
                line_linked: { enable: true, distance: 150, color: '#d8b4fe', opacity: 0.4, width: 1 },
                move: { enable: true, speed: 2, direction: 'none', random: true, straight: false, out_mode: 'out' }
            },
            interactivity: {
                detect_on: 'canvas',
                events: { onhover: { enable: true, mode: 'repulse' }, onclick: { enable: true, mode: 'push' }, resize: true },
                modes: { repulse: { distance: 100, duration: 0.4 }, push: { particles_nb: 4 } }
            },
            retina_detect: true
        });

        const translations = {
            en: {
                logout: "Logout",
                welcome: "Welcome",
                activateCode: "Activate Code",
                selectSubscription: "Select Subscription",
                noSubscriptions: "No subscriptions available",
                buyerContactPlaceholder: "Phone or Email",
                activate: "Activate",
                copy: "Copy",
                print: "Print",
                share: "Share",
                earnings: "Your Earnings",
                export: "Export",
                insights: "View Growth Tips",
                transactions: "Your Transactions",
                thId: "ID",
                thSubscription: "Subscription",
                thBuyerContact: "Buyer Contact",
                thCode: "Code",
                thAmount: "Amount (CDF)",
                thCommission: "Commission (CDF)",
                thDate: "Date",
                footer: "©2025 Zinbo Technology",
                loading: "Loading...",
                noData: "No data yet",
                noCodesAvailable: "No access codes available. Contact support.",
                success: "Code activated successfully!",
                logoutSuccess: "Logged out successfully!",
                invalidFields: "Please fill all fields.",
                invalidSubscription: "Invalid subscription selected.",
                invalidContact: "Invalid phone or email format.",
                activationFailed: "Code activation failed. Try again or contact support.",
                copied: "Code copied to clipboard!",
                shared: "Code shared successfully!",
                insightsTitle: "Growth Tips for Resellers",
                growthAdvice: "Growth Tips",
                close: "Close",
                nextSteps: "Next: Copy, print, or share the code with the buyer."
            },
            fr: {
                logout: "Déconnexion",
                welcome: "Bienvenue",
                activateCode: "Activer le Code",
                selectSubscription: "Sélectionner un Abonnement",
                noSubscriptions: "Aucun abonnement disponible",
                buyerContactPlaceholder: "Téléphone ou Email",
                activate: "Activer",
                copy: "Copier",
                print: "Imprimer",
                share: "Partager",
                earnings: "Vos Gains",
                export: "Exporter",
                insights: "Voir les Conseils de Croissance",
                transactions: "Vos Transactions",
                thId: "ID",
                thSubscription: "Abonnement",
                thBuyerContact: "Contact Acheteur",
                thCode: "Code",
                thAmount: "Montant (CDF)",
                thCommission: "Commission (CDF)",
                thDate: "Date",
                footer: "©2025 Zinbo Technologie",
                loading: "Chargement...",
                noData: "Aucune donnée pour le moment",
                noCodesAvailable: "Aucun code d'accès disponible. Contactez le support.",
                success: "Code activé avec succès !",
                logoutSuccess: "Déconnexion réussie !",
                invalidFields: "Veuillez remplir tous les champs.",
                invalidSubscription: "Abonnement invalide sélectionné.",
                invalidContact: "Format de téléphone ou email invalide.",
                activationFailed: "Échec de l'activation du code. Réessayez ou contactez le support.",
                copied: "Code copié dans le presse-papiers !",
                shared: "Code partagé avec succès !",
                insightsTitle: "Conseils de Croissance pour Revendeurs",
                growthAdvice: "Conseils de Croissance",
                close: "Fermer",
                nextSteps: "Suivant : Copiez, imprimez ou partagez le code avec l'acheteur."
            },
            sw: {
                logout: "Toka",
                welcome: "Karibu",
                activateCode: "Washa Nambari",
                selectSubscription: "Chagua Usajili",
                noSubscriptions: "Hakuna usajili unaopatikana",
                buyerContactPlaceholder: "Simu au Barua Pepe",
                activate: "Washa",
                copy: "Nakili",
                print: "Chapa",
                share: "Shiriki",
                earnings: "Mapato Yako",
                export: "Hamisha",
                insights: "Ona Vidokezo vya Ukuaji",
                transactions: "Miamala Yako",
                thId: "ID",
                thSubscription: "Usajili",
                thBuyerContact: "Mwasiliani wa Mnunuzi",
                thCode: "Nambari",
                thAmount: "Kiasi (CDF)",
                thCommission: "Tume (CDF)",
                thDate: "Tarehe",
                footer: "©2025 Teknolojia ya Zinbo",
                loading: "Inapakia...",
                noData: "Hakuna data bado",
                noCodesAvailable: "Hakuna nambari za upatikanaji zinazopatikana. Wasiliana na msaada.",
                success: "Nambari imewashwa vizuri!",
                logoutSuccess: "Umetoka vizuri!",
                invalidFields: "Tafadhali jaza sehemu zote.",
                invalidSubscription: "Usajili batili umechaguliwa.",
                invalidContact: "Fomati ya simu au barua pepe haifai.",
                activationFailed: "Imeshindwa kuwasha nambari. Jaribu tena au wasiliana na msaada.",
                copied: "Nambari imenakiliwa kwenye ubao wa kunakili!",
                shared: "Nambari imeshirikiwa vizuri!",
                insightsTitle: "Vidokezo vya Ukuaji kwa Wauzaji",
                growthAdvice: "Vidokezo vya Ukuaji",
                close: "Funga",
                nextSteps: "Ifuatayo: Nakili, chapa, au shiriki nambari na mnunuzi."
            }
        };

        let currentLang = 'en';
        let currentActivationData = null;
        const modal = document.getElementById('modal');
        const modalTitle = document.getElementById('modalTitle');
        const modalMessage = document.getElementById('modalMessage');
        const modalContent = document.getElementById('modalContent');
        const closeModal = document.getElementById('closeModal');
        const modalCloseBtn = document.getElementById('modalCloseBtn');

        function showModal(title, message, content = '') {
            console.debug(`Showing modal: title=${title}, message=${message}`);
            modalTitle.textContent = title;
            modalMessage.innerHTML = message;
            modalContent.innerHTML = content;
            modal.classList.remove('hidden');
            modalCloseBtn.focus();
        }

        function hideModal() {
            console.debug('Hiding modal');
            modal.classList.add('hidden');
            modalContent.innerHTML = '';
            modalMessage.innerHTML = '';
        }

        closeModal.addEventListener('click', hideModal);
        modalCloseBtn.addEventListener('click', hideModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) hideModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) hideModal();
        });

        document.getElementById('languageSelect').addEventListener('change', () => {
            currentLang = document.getElementById('languageSelect').value;
            updateLanguage();
            loadCharts();
            loadTransactions();
        });

        function updateLanguage() {
            document.getElementById('logoutText').textContent = translations[currentLang].logout;
            document.getElementById('welcomeText').childNodes[0].textContent = `${translations[currentLang].welcome}, `;
            document.getElementById('activateCodeTitle').textContent = translations[currentLang].activateCode;
            document.getElementById('selectSubscription').textContent = translations[currentLang].selectSubscription;
            document.getElementById('noSubscriptions')?.setAttribute('id', translations[currentLang].noSubscriptions);
            document.getElementById('buyerContact').placeholder = translations[currentLang].buyerContactPlaceholder;
            document.getElementById('activateBtn').textContent = translations[currentLang].activate;
            document.getElementById('copyText').textContent = translations[currentLang].copy;
            document.getElementById('printText').textContent = translations[currentLang].print;
            document.getElementById('shareText').textContent = translations[currentLang].share;
            document.getElementById('earningsTitle').textContent = translations[currentLang].earnings;
            document.getElementById('exportEarningsText').textContent = translations[currentLang].export;
            document.getElementById('insightsText').textContent = translations[currentLang].insights;
            document.getElementById('transactionsTitle').textContent = translations[currentLang].transactions;
            document.getElementById('exportTransactionsText').textContent = translations[currentLang].export;
            document.getElementById('thId').textContent = translations[currentLang].thId;
            document.getElementById('thSubscription').textContent = translations[currentLang].thSubscription;
            document.getElementById('thBuyerContact').textContent = translations[currentLang].thBuyerContact;
            document.getElementById('thCode').textContent = translations[currentLang].thCode;
            document.getElementById('thAmount').textContent = translations[currentLang].thAmount;
            document.getElementById('thCommission').textContent = translations[currentLang].thCommission;
            document.getElementById('thDate').textContent = translations[currentLang].thDate;
            document.getElementById('footerText').textContent = translations[currentLang].footer;
            document.getElementById('modalCloseBtn').textContent = translations[currentLang].close;
            if (!document.getElementById('codeResult').classList.contains('hidden') && currentActivationData) {
                updateCodeResult(currentActivationData);
            }
        }

        function performLogout() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'logout' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showModal(translations[currentLang].logoutSuccess, translations[currentLang].logoutSuccess);
                    setTimeout(() => {
                        window.location.href = 'reseller.php';
                    }, 1000);
                } else {
                    showModal('Error', translations[currentLang].activationFailed);
                }
            })
            .catch(error => {
                console.error('Logout error:', error);
                showModal('Error', translations[currentLang].activationFailed);
            });
        }

        document.getElementById('logoutBtn').addEventListener('click', performLogout);

        function updateCodeResult(data) {
            const codeDetails = document.getElementById('codeDetails');
            codeDetails.innerHTML = `
                <strong>Code:</strong> ${data.code}<br>
                <strong>Subscription:</strong> ${data.subscription_name}<br>
                <strong>Rate:</strong> ${data.rate} CDF<br>
                <strong>Commission:</strong> ${data.commission} CDF<br>
                <strong>Expires:</strong> ${new Date(data.expiration_date).toLocaleString()}
            `;
            document.getElementById('codeResult').classList.remove('hidden');
        }

        document.getElementById('activateBtn').addEventListener('click', () => {
            const subscriptionId = document.getElementById('subscriptionId').value;
            let buyerContact = document.getElementById('buyerContact').value.trim();
            if (!subscriptionId || !buyerContact) {
                showModal('Error', translations[currentLang].invalidFields);
                return;
            }
            document.getElementById('activateBtn').textContent = translations[currentLang].loading;
            document.getElementById('activateBtn').disabled = true;
            // Only prepend +243 if it's a phone number and doesn't already have a country code
            if (!buyerContact.match(/^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$/) && !buyerContact.match(/^\+\d/)) {
                buyerContact = '+243' + buyerContact.replace(/^0+/, '');
            }
            console.debug(`Sending activation request: subscription_id=${subscriptionId}, buyer_contact=${buyerContact}`);
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'activate_code', subscription_id: parseInt(subscriptionId), buyer_contact: buyerContact })
            })
            .then(response => {
                console.debug('Activate code response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.debug('Activate code response data:', data);
                document.getElementById('activateBtn').textContent = translations[currentLang].activate;
                document.getElementById('activateBtn').disabled = false;
                if (data.success) {
                    currentActivationData = data;
                    updateCodeResult(data);
                    showModal(
                        translations[currentLang].success,
                        `${translations[currentLang].nextSteps}<br><br>
                         <strong>Code:</strong> ${data.code}<br>
                         <strong>Contact:</strong> ${data.buyer_contact} (${data.contact_type})`
                    );
                    Promise.all([loadCharts(), loadTransactions()]).catch(error => {
                        console.error('Error loading charts/transactions:', error);
                    });
                } else {
                    showModal('Error', translations[currentLang][data.message] || translations[currentLang].activationFailed);
                    if (data.debug) {
                        console.error('Activation debug info:', data.debug);
                    }
                }
            })
            .catch(error => {
                console.error('Activate code error:', error);
                document.getElementById('activateBtn').textContent = translations[currentLang].activate;
                document.getElementById('activateBtn').disabled = false;
                showModal('Error', translations[currentLang].activationFailed);
            });
        });

        document.getElementById('copyCode').addEventListener('click', () => {
            if (currentActivationData) {
                navigator.clipboard.writeText(currentActivationData.code).then(() => {
                    showModal(translations[currentLang].copied, translations[currentLang].copied);
                }).catch(error => {
                    console.error('Copy error:', error);
                    showModal('Error', translations[currentLang].activationFailed);
                });
            }
        });

        document.getElementById('printCode').addEventListener('click', () => {
            if (currentActivationData) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html><head><title>ZinbConnect Code</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h1 { color: #a78bfa; }
                        p { margin: 10px 0; }
                    </style>
                    </head><body>
                    <h1>ZinbConnect Access Code</h1>
                    <p><strong>Code:</strong> ${currentActivationData.code}</p>
                    <p><strong>Subscription:</strong> ${currentActivationData.subscription_name}</p>
                    <p><strong>Contact:</strong> ${currentActivationData.buyer_contact} (${currentActivationData.contact_type})</p>
                    <p><strong>Rate:</strong> ${currentActivationData.rate} CDF</p>
                    <p><strong>Commission:</strong> ${currentActivationData.commission} CDF</p>
                    <p><strong>Reseller:</strong> <?php echo htmlspecialchars($first_name); ?></p>
                    <p><strong>Expires:</strong> ${new Date(currentActivationData.expiration_date).toLocaleString()}</p>
                    <p><strong>Date:</strong> ${new Date().toLocaleString()}</p>
                    </body></html>
                `);
                printWindow.document.close();
                printWindow.print();
            }
        });

        document.getElementById('shareCode').addEventListener('click', () => {
            if (currentActivationData) {
                const shareData = {
                    title: 'ZinbConnect Access Code',
                    text: `Your ZinbConnect Access Code: ${currentActivationData.code}\nSubscription: ${currentActivationData.subscription_name}\nRate: ${currentActivationData.rate} CDF\nExpires: ${new Date(currentActivationData.expiration_date).toLocaleString()}`,
                    url: window.location.origin
                };
                if (navigator.share) {
                    navigator.share(shareData)
                        .then(() => showModal(translations[currentLang].shared, translations[currentLang].shared))
                        .catch(error => {
                            console.error('Share error:', error);
                            showModal('Error', translations[currentLang].activationFailed);
                        });
                } else {
                    navigator.clipboard.writeText(shareData.text).then(() => {
                        showModal(translations[currentLang].shared, translations[currentLang].copied);
                    }).catch(error => {
                        console.error('Share fallback error:', error);
                        showModal('Error', translations[currentLang].activationFailed);
                    });
                }
            }
        });

        function loadTransactions() {
            const tbody = document.getElementById('transactionsBody');
            tbody.innerHTML = `<tr><td colspan="7" class="p-2 text-center">${translations[currentLang].loading}</td></tr>`;
            return fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_transactions' })
            })
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = '';
                if (data.success && data.transactions.length > 0) {
                    data.transactions.forEach(t => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td class="p-2">${t.id}</td>
                            <td class="p-2">${t.subscription}</td>
                            <td class="p-2">${t.buyer_contact}</td>
                            <td class="p-2">${t.access_code}</td>
                            <td class="p-2">${t.amount}</td>
                            <td class="p-2">${t.commission}</td>
                            <td class="p-2">${new Date(t.created_at).toLocaleString()}</td>
                        `;
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = `<tr><td colspan="7" class="p-2 text-center">${translations[currentLang].noData}</td></tr>`;
                }
            })
            .catch(error => {
                console.error('Transaction fetch error:', error);
                tbody.innerHTML = `<tr><td colspan="7" class="p-2 text-center">${translations[currentLang].noData}</td></tr>`;
            });
        }

        let pieChart, lineChart;
        function loadCharts() {
            document.getElementById('personalEarnings').textContent = translations[currentLang].loading;
            return fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_earnings_data' })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('personalEarnings').textContent = `${data.personal_earnings || 0} CDF`;
                if (data.success) {
                    if (pieChart) pieChart.destroy();
                    const pieCtx = document.getElementById('pieChart').getContext('2d');
                    const pieGradient = pieCtx.createLinearGradient(0, 0, 0, 200);
                    pieGradient.addColorStop(0, '#a78bfa');
                    pieGradient.addColorStop(1, '#d8b4fe');
                    pieChart = new Chart(pieCtx, {
                        type: 'pie',
                        data: {
                            labels: data.pie_data.labels.length ? data.pie_data.labels : [translations[currentLang].noData],
                            datasets: [{
                                data: data.pie_data.data.length ? data.pie_data.data : [1],
                                backgroundColor: data.pie_data.data.length ? [pieGradient, '#f3e8ff', '#6b7280', '#9333ea'] : ['#e5e7eb'],
                                borderColor: '#ffffff',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { position: 'top', labels: { color: document.documentElement.classList.contains('dark') ? '#d1d5db' : '#1f2937' } },
                                tooltip: { backgroundColor: '#1f2937', titleColor: '#ffffff', bodyColor: '#ffffff' }
                            },
                            animation: { animateRotate: true, animateScale: true }
                        }
                    });

                    if (lineChart) lineChart.destroy();
                    const lineCtx = document.getElementById('lineChart').getContext('2d');
                    const lineGradient = lineCtx.createLinearGradient(0, 0, 0, 200);
                    lineGradient.addColorStop(0, '#a78bfa');
                    lineGradient.addColorStop(1, '#d8b4fe');
                    lineChart = new Chart(lineCtx, {
                        type: 'line',
                        data: {
                            labels: data.line_data.labels.length ? data.line_data.labels : [translations[currentLang].noData],
                            datasets: [{
                                label: translations[currentLang].earnings,
                                data: data.line_data.data.length ? data.line_data.data : [0],
                                borderColor: lineGradient,
                                backgroundColor: lineGradient,
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { labels: { color: document.documentElement.classList.contains('dark') ? '#d1d5db' : '#1f2937' } },
                                tooltip: { backgroundColor: '#1f2937', titleColor: '#ffffff', bodyColor: '#ffffff' }
                            },
                            scales: {
                                y: { beginAtZero: true, ticks: { color: document.documentElement.classList.contains('dark') ? '#d1d5db' : '#1f2937' } },
                                x: { ticks: { color: document.documentElement.classList.contains('dark') ? '#d1d5db' : '#1f2937' } }
                            },
                            animation: { duration: 1000 }
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Charts fetch error:', error);
                document.getElementById('personalEarnings').textContent = '0 CDF';
            });
        }

        function linearRegression(x, y) {
            const n = x.length;
            let sumX = 0, sumY = 0, sumXY = 0, sumXX = 0;
            for (let i = 0; i < n; i++) {
                sumX += x[i];
                sumY += y[i];
                sumXY += x[i] * y[i];
                sumXX += x[i] * x[i];
            }
            const slope = (n * sumXY - sumX * sumY) / (n * sumXX - sumX * sumX);
            const intercept = (sumY - slope * sumX) / n;
            return { slope, intercept };
        }

        function predictFuture(data) {
            if (!data.line_data.labels.length) return { predictions: [], advice: [] };
            const x = data.line_data.labels.map((_, i) => i);
            const y = data.line_data.data.map(Number);
            const { slope, intercept } = linearRegression(x, y);
            const predictions = [];
            for (let i = x.length; i < x.length + 30; i++) {
                predictions.push(Math.max(0, slope * i + intercept));
            }
            const growthRate = slope > 0 ? (slope / y[y.length - 1] * 100).toFixed(2) : 0;
            const advice = [
                growthRate > 0 ? `Continue your current strategy: Your commissions are growing at ${growthRate}% per day.` : `No growth detected. Try promoting higher-value subscriptions.`,
                `Engage customers via social media to increase sales.`,
                `Offer bundle deals to attract more buyers.`,
                `Follow up with previous buyers to encourage repeat purchases.`
            ];
            return { predictions, advice };
        }

        document.getElementById('viewInsights').addEventListener('click', () => {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_earnings_data' })
            })
            .then(response => response.json())
            .then(data => {
                const { predictions, advice } = predictFuture(data);
                const futureLabels = Array.from({ length: 30 }, (_, i) => {
                    const date = new Date();
                    date.setDate(date.getDate() + i + 1);
                    return date.toISOString().split('T')[0];
                });
                const insightCanvas = document.createElement('canvas');
                insightCanvas.id = 'insightChart';
                insightCanvas.className = 'w-full h-48 sm:h-64 mt-4';
                const insightChart = new Chart(insightCanvas, {
                    type: 'line',
                    data: {
                        labels: futureLabels,
                        datasets: [{
                            label: translations[currentLang].earnings,
                            data: predictions,
                            borderColor: '#a78bfa',
                            backgroundColor: '#d8b4fe',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { labels: { color: document.documentElement.classList.contains('dark') ? '#d1d5db' : '#1f2937' } } },
                        scales: { y: { beginAtZero: true }, x: { ticks: { maxTicksLimit: 10 } } }
                    }
                });
                const content = `
                    <h4 class="font-bold mt-4">${translations[currentLang].growthAdvice}</h4>
                    <ul class="list-disc pl-5">${advice.map(a => `<li>${a}</li>`).join('')}</ul>
                `;
                showModal(translations[currentLang].insightsTitle, '', content);
                modalContent.appendChild(insightCanvas);
            })
            .catch(error => {
                console.error('Insights fetch error:', error);
                showModal('Error', translations[currentLang].noData);
            });
        });

        document.getElementById('exportEarnings').addEventListener('click', () => {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_earnings_data' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const csv = [
                        ['Type', 'Amount (CDF)'],
                        [`${translations[currentLang].earnings}`, data.personal_earnings || 0]
                    ].map(row => row.join(',')).join('\n');
                    const blob = new Blob([csv], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'earnings_report.csv';
                    a.click();
                    window.URL.revokeObjectURL(url);
                } else {
                    showModal('Error', translations[currentLang].noData);
                }
            })
            .catch(error => {
                console.error('Export earnings error:', error);
                showModal('Error', translations[currentLang].noData);
            });
        });

        document.getElementById('exportTransactions').addEventListener('click', () => {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_transactions' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.transactions.length > 0) {
                    const csv = [
                        ['ID', 'Subscription', 'Buyer Contact', 'Code', 'Amount (CDF)', 'Commission (CDF)', 'Date'],
                        ...data.transactions.map(t => [
                            t.id,
                            `"${t.subscription}"`,
                            `"${t.buyer_contact}"`,
                            t.access_code,
                            t.amount,
                            t.commission,
                            new Date(t.created_at).toLocaleString()
                        ])
                    ].map(row => row.join(',')).join('\n');
                    const blob = new Blob([csv], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'transactions_report.csv';
                    a.click();
                    window.URL.revokeObjectURL(url);
                } else {
                    showModal('Error', translations[currentLang].noData);
                }
            })
            .catch(error => {
                console.error('Export transactions error:', error);
                showModal('Error', translations[currentLang].noData);
            });
        });

        function initDarkMode() {
            const darkModeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            function applyDarkMode(e) {
                if (e.matches) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
                loadCharts();
            }
            darkModeMediaQuery.addEventListener('change', applyDarkMode);
            applyDarkMode(darkModeMediaQuery);
        }

        function initDashboard() {
            updateLanguage();
            loadCharts();
            loadTransactions();
            initDarkMode();
        }

        document.addEventListener('DOMContentLoaded', initDashboard);
    </script>
</body>
</html>

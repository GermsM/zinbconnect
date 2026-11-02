<?php
// Log script start
file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [INFO] Script execution started' . PHP_EOL, FILE_APPEND);

// Set error handling
ini_set('display_errors', 0); // Disable display errors for production
ini_set('log_errors', 1); // Enable error logging
ini_set('error_log', __DIR__ . '/php_errors.log'); // Custom error log
error_reporting(E_ALL);
ini_set('memory_limit', '128M'); // Increase memory limit for InfinityFree

// Log PHP version and key modules
$php_version = PHP_VERSION;
$required_modules = ['mysqli', 'curl', 'openssl', 'json', 'mbstring'];
$loaded_modules = get_loaded_extensions();
$missing_modules = array_diff($required_modules, $loaded_modules);
file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [INFO] PHP Version: ' . $php_version . PHP_EOL, FILE_APPEND);
if (!empty($missing_modules)) {
    file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [ERROR] Missing PHP modules: ' . implode(', ', $missing_modules) . PHP_EOL, FILE_APPEND);
    die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'Missing PHP modules: ' . implode(', ', $missing_modules)]));
}

// Start session
try {
    if (!session_start()) {
        throw new Exception('Session start failed');
    }
    file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [INFO] Session started successfully' . PHP_EOL, FILE_APPEND);
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [ERROR] Session error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'Session error: ' . $e->getMessage()]));
}

// Define PHPMailer and Twilio credentials
define('GMAIL_USERNAME', '');
define('GMAIL_APP_PASSWORD', ''); // Replace with your actual app password
define('TWILIO_ACCOUNT_SID', '');
define('TWILIO_AUTH_TOKEN', '');
define('TWILIO_PHONE_NUMBER', '');
file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [INFO] Credentials defined' . PHP_EOL, FILE_APPEND);

// Include PHPMailer libraries
$phpmailer_files = [
    __DIR__ . '/lib/PHPMailer/Exception.php',
    __DIR__ . '/lib/PHPMailer/PHPMailer.php',
    __DIR__ . '/lib/PHPMailer/SMTP.php'
];

foreach ($phpmailer_files as $file) {
    if (!file_exists($file)) {
        file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [ERROR] PHPMailer file missing: ' . $file . PHP_EOL, FILE_APPEND);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'PHPMailer file missing: ' . $file]));
    }
    try {
        require_once $file;
        file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [INFO] Loaded PHPMailer file: ' . $file . PHP_EOL, FILE_APPEND);
    } catch (Throwable $e) {
        file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [ERROR] PHPMailer file load error: ' . $file . ' - ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'PHPMailer load error: ' . $e->getMessage()]));
    }
}

// Include Twilio autoloader
$twilio_autoloader = __DIR__ . '/lib/Twilio/autoload.php';
if (!file_exists($twilio_autoloader)) {
    file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [ERROR] Twilio autoloader missing: ' . $twilio_autoloader . PHP_EOL, FILE_APPEND);
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'Twilio autoloader missing: ' . $twilio_autoloader]));
}
try {
    require_once $twilio_autoloader;
    file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [INFO] Loaded Twilio autoloader: ' . $twilio_autoloader . PHP_EOL, FILE_APPEND);
} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [ERROR] Twilio autoloader error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'Twilio autoloader error: ' . $e->getMessage()]));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Twilio\Rest\Client;

file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [INFO] Namespaces imported' . PHP_EOL, FILE_APPEND);

// Database configuration
define('DB_HOST', '');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_NAME', '');
define('DB_PORT', );
define('DEBUG_LOG_FILE', __DIR__ . '/debug.log');

// Centralized logging function
function logDebug($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[$timestamp] [$level] $message\n";
    file_put_contents(DEBUG_LOG_FILE, $log, FILE_APPEND);
    error_log($log);
}

// Check PHP version
if (version_compare(PHP_VERSION, '7.2.0', '<')) {
    logDebug('PHP version too low: ' . PHP_VERSION, 'ERROR');
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'PHP version must be 7.2 or higher']));
}

// Check file permissions for debug.log
if (!is_writable(__DIR__ . '/debug.log')) {
    error_log('[' . date('Y-m-d H:i:s') . '] [ERROR] debug.log not writable');
    die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'debug.log file not writable']));
}

// Log library loading success
try {
    logDebug('PHPMailer and Twilio libraries loaded successfully');
} catch (Exception $e) {
    logDebug('Failed to load libraries: ' . $e->getMessage(), 'ERROR');
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => 'serverError', 'debug' => 'Library loading failed: ' . $e->getMessage()]));
}

// Database connection function
function getDBConnection() {
    try {
        logDebug('Attempting database connection');
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            throw new Exception('Database connection failed: ' . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');
        logDebug('Database connection established');
        return $conn;
    } catch (Exception $e) {
        logDebug($e->getMessage(), 'ERROR');
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'dbConnectionFailed', 'debug' => $e->getMessage()]));
    }
}

// Generate unique code function
function generateUniqueCode($conn) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $max_attempts = 10;
    $attempt = 0;

    try {
        while ($attempt < $max_attempts) {
            $code = '';
            $bytes = random_bytes(8);
            for ($i = 0; $i < 8; $i++) {
                $code .= $characters[ord($bytes[$i]) % 36];
            }
            logDebug("Generated code attempt #$attempt: $code");

            $query = "SELECT code FROM current_codes WHERE code = ? FOR UPDATE";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Prepare failed for code check: ' . $conn->error);
            }
            $stmt->bind_param('s', $code);
            if (!$stmt->execute()) {
                throw new Exception('Code check execution failed: ' . $stmt->error);
            }
            $result = $stmt->get_result();
            $stmt->close();

            if ($result->num_rows === 0) {
                logDebug("Unique code generated: $code");
                return $code;
            }
            $attempt++;
            logDebug("Code $code already exists, retrying");
        }
        throw new Exception("Failed to generate unique code after $max_attempts attempts");
    } catch (Exception $e) {
        logDebug('Code generation error: ' . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

// Check admin login
if (!isset($_SESSION['admin_id'])) {
    logDebug('No admin_id in session, redirecting to reseller.php', 'WARNING');
    header('Location: reseller.php');
    exit;
}
$admin_id = $_SESSION['admin_id'];
logDebug("Admin ID from session: $admin_id");

try {
    $conn = getDBConnection();
    $query = "SELECT name FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Prepare failed for admin query: ' . $conn->error);
    }
    $stmt->bind_param('i', $admin_id);
    if (!$stmt->execute()) {
        throw new Exception('Admin query execution failed: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Invalid admin_id: $admin_id");
    }
    $row = $result->fetch_assoc();
    $admin_name = $row['name'] ? htmlspecialchars(trim($row['name'])) : 'Admin';
    $first_name = explode(' ', $admin_name)[0] ?? 'Admin';
    $stmt->close();
    logDebug("Admin validated: ID=$admin_id, Name=$admin_name");
} catch (Exception $e) {
    logDebug($e->getMessage(), 'ERROR');
    header('Location: reseller.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        logDebug('POST request received');
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg());
        }
        $action = $input['action'] ?? '';
        logDebug("Received POST request with action: $action");

        switch ($action) {
            case 'logout':
                try {
                    session_unset();
                    session_destroy();
                    setcookie('admin_id', '', time() - 3600, '/', '', false, true);
                    logDebug('Logout successful for admin_id: ' . $admin_id);
                    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
                } catch (Exception $e) {
                    logDebug('Logout error: ' . $e->getMessage(), 'ERROR');
                    echo json_encode(['success' => false, 'message' => 'logoutFailed', 'debug' => $e->getMessage()]);
                }
                break;

            case 'activate_code':
                $subscription_id = $input['subscription_id'] ?? 0;
                $buyer_contact = trim($input['buyer_contact'] ?? '');
                if (!$subscription_id || !$buyer_contact) {
                    throw new Exception("Invalid fields: subscription_id=$subscription_id, buyer_contact=$buyer_contact");
                }
                logDebug("Activating code: subscription_id=$subscription_id, buyer_contact=$buyer_contact");

                $conn->begin_transaction();
                try {
                    // Clean up expired codes
                    $query = "DELETE FROM current_codes WHERE expiration_date <= NOW()";
                    if (!$conn->query($query)) {
                        throw new Exception("Cleanup failed: " . $conn->error);
                    }
                    logDebug('Expired codes cleaned successfully');

                    // Validate subscription
                    $query = "SELECT id, name, rate, duration_days FROM subscriptions WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for subscription: " . $conn->error);
                    }
                    $stmt->bind_param('i', $subscription_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Subscription query execution failed: " . $stmt->error);
                    }
                    $result = $stmt->get_result();
                    if ($result->num_rows === 0) {
                        throw new Exception("Invalid subscription ID: $subscription_id");
                    }
                    $row = $result->fetch_assoc();
                    $subscription_name = $row['name'];
                    $rate = $row['rate'];
                    $duration_days = $row['duration_days'] ?? 30;
                    $commission = $rate * 0.25;
                    $expiration_date = date('Y-m-d H:i:s', strtotime("+$duration_days days"));
                    $stmt->close();
                    logDebug("Subscription validated: ID=$subscription_id, Name=$subscription_name, Duration=$duration_days days");

                    // Detect contact type
                    $contact_type = preg_match('/^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$/', $buyer_contact) ? 'email' :
                        (preg_match('/^\+?\d{9,15}$/', $buyer_contact) ? 'phone' : null);
                    if (!$contact_type) {
                        throw new Exception("Invalid contact format: $buyer_contact");
                    }
                    if ($contact_type === 'phone') {
                        if (!preg_match('/^\+\d{9,15}$/', $buyer_contact)) {
                            $buyer_contact = '+243' . ltrim($buyer_contact, '0');
                            if (!preg_match('/^\+\d{9,15}$/', $buyer_contact)) {
                                throw new Exception("Invalid phone number after adding +243: $buyer_contact");
                            }
                        }
                    }
                    logDebug("Contact type: $contact_type, Contact: $buyer_contact");

                    // Generate unique code
                    $code = generateUniqueCode($conn);

                    // Insert into current_codes
                    $query = "INSERT INTO current_codes (code, contact, contact_type, subscription_id, created_at, expiration_date) VALUES (?, ?, ?, ?, NOW(), ?)";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for current_codes: " . $conn->error);
                    }
                    $stmt->bind_param('sssis', $code, $buyer_contact, $contact_type, $subscription_id, $expiration_date);
                    if (!$stmt->execute()) {
                        throw new Exception("Current_codes insert failed: " . $stmt->error);
                    }
                    $stmt->close();
                    logDebug("Inserted code into current_codes: $code");

                    // Prepare invoice
                    $invoice = "ZinbConnect Invoice\n";
                    $invoice .= "Contact: $buyer_contact\n";
                    $invoice .= "Access Code: $code\n";
                    $invoice .= "Subscription: $subscription_name\n";
                    $invoice .= "Amount: $rate CDF\n";
                    $invoice .= "Expiration: $expiration_date\n";

                    // Send confirmation
                    if ($contact_type === 'email') {
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host = 'smtp.gmail.com';
                            $mail->SMTPAuth = true;
                            $mail->Username = GMAIL_USERNAME;
                            $mail->Password = GMAIL_APP_PASSWORD;
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = 587;
                            $mail->setFrom(GMAIL_USERNAME, 'ZinbConnect');
                            $mail->addAddress($buyer_contact);
                            $mail->Subject = 'ZinbConnect Subscription Invoice';
                            $mail->Body = $invoice;
                            $mail->send();
                            logDebug("Email sent to $buyer_contact");
                        } catch (Exception $e) {
                            throw new Exception("Email sending failed: " . $mail->ErrorInfo);
                        }
                    } else {
                        $twilio = new Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
                        try {
                            $message = $twilio->messages->create(
                                $buyer_contact,
                                [
                                    'from' => TWILIO_PHONE_NUMBER,
                                    'body' => $invoice
                                ]
                            );
                            logDebug("SMS sent to $buyer_contact: SID {$message->sid}");
                        } catch (Exception $e) {
                            throw new Exception("SMS sending failed: " . $e->getMessage());
                        }
                    }

                    // Insert transaction into DB
                    $query = "INSERT INTO transactions (reseller_id, subscription_id, buyer_contact, access_code, amount, commission, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for transaction: " . $conn->error);
                    }
                    $stmt->bind_param('iissdd', $admin_id, $subscription_id, $buyer_contact, $code, $rate, $commission);
                    if (!$stmt->execute()) {
                        throw new Exception("Transaction insert failed: " . $stmt->error);
                    }
                    $stmt->close();
                    logDebug("Transaction recorded: Code=$code, Amount=$rate, Commission=$commission");

                    $conn->commit();
                    logDebug("Code activation successful: $code");
                    echo json_encode([
                        'success' => true,
                        'code' => $code,
                        'rate' => $rate,
                        'commission' => $commission,
                        'confirmation_sent' => $contact_type === 'email' ? 'Email sent' : 'SMS sent',
                        'expiration_date' => $expiration_date
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = $e->getMessage();
                    logDebug("Activate code error: $error_message", 'ERROR');
                    $user_message = match (true) {
                        str_contains($error_message, 'Invalid contact format') || str_contains($error_message, 'Invalid phone number') => 'invalidContact',
                        str_contains($error_message, 'Invalid subscription') => 'invalidSubscription',
                        str_contains($error_message, 'Email sending failed') => 'emailFailed',
                        str_contains($error_message, 'SMS sending failed') => 'smsFailed',
                        str_contains($error_message, 'Current_codes insert failed') => 'currentCodesInsertFailed',
                        str_contains($error_message, 'Transaction insert failed') => 'transactionInsertFailed',
                        str_contains($error_message, 'Cleanup failed') => 'dbCleanupFailed',
                        str_contains($error_message, 'Failed to generate unique code') => 'codeGenerationFailed',
                        default => 'activationFailed'
                    };
                    echo json_encode(['success' => false, 'message' => $user_message, 'debug' => $error_message]);
                }
                break;

            case 'get_transactions':
                try {
                    $query = "SELECT t.id, COALESCE(r.name, a.name, 'Unknown') AS reseller, s.name AS subscription, t.buyer_contact, t.access_code, t.amount, t.commission, t.created_at 
                              FROM transactions t 
                              LEFT JOIN resellers r ON t.reseller_id = r.id 
                              LEFT JOIN users a ON t.reseller_id = 0 AND a.id = ? 
                              JOIN subscriptions s ON t.subscription_id = s.id 
                              ORDER BY t.id DESC";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param('i', $admin_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Transaction query execution failed: " . $stmt->error);
                    }
                    $result = $stmt->get_result();
                    $transactions = [];
                    while ($row = $result->fetch_assoc()) {
                        $transactions[] = $row;
                    }
                    $stmt->close();
                    logDebug("Fetched " . count($transactions) . " transactions for admin_id: $admin_id");
                    echo json_encode(['success' => true, 'transactions' => $transactions]);
                } catch (Exception $e) {
                    logDebug('Get transactions error: ' . $e->getMessage(), 'ERROR');
                    echo json_encode(['success' => false, 'message' => 'dbQueryFailed', 'debug' => $e->getMessage()]);
                }
                break;

            case 'get_earnings_data':
                try {
                    $query = "SELECT SUM(commission) AS total_earnings FROM transactions WHERE reseller_id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param('i', $admin_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Earnings query execution failed: " . $stmt->error);
                    }
                    $result = $stmt->get_result();
                    $personal_earnings = $result->fetch_assoc()['total_earnings'] ?? 0;
                    $stmt->close();

                    $query = "SELECT s.name AS subscription, COUNT(*) AS count 
                              FROM transactions t JOIN subscriptions s ON t.subscription_id = s.id 
                              WHERE t.reseller_id = ? GROUP BY s.id";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param('i', $admin_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Pie data query execution failed: " . $stmt->error);
                    }
                    $result = $stmt->get_result();
                    $pie_data = ['labels' => [], 'data' => []];
                    while ($row = $result->fetch_assoc()) {
                        $pie_data['labels'][] = $row['subscription'];
                        $pie_data['data'][] = $row['count'];
                    }
                    $stmt->close();

                    $query = "SELECT SUM(amount) - SUM(commission) AS enterprise_earnings FROM transactions";
                    $result = $conn->query($query);
                    if (!$result) {
                        throw new Exception("Enterprise earnings query failed: " . $conn->error);
                    }
                    $enterprise_earnings = $result->fetch_assoc()['enterprise_earnings'] ?? 0;

                    $query = "SELECT DATE(created_at) AS date, SUM(amount) - SUM(commission) AS daily_earnings 
                              FROM transactions 
                              GROUP BY DATE(created_at) ORDER BY date LIMIT 30";
                    $result = $conn->query($query);
                    if (!$result) {
                        throw new Exception("Line chart query failed: " . $conn->error);
                    }
                    $line_data = ['labels' => [], 'data' => []];
                    while ($row = $result->fetch_assoc()) {
                        $line_data['labels'][] = $row['date'];
                        $line_data['data'] = $row['daily_earnings'];
                    }

                    logDebug("Earnings data fetched: personal=$personal_earnings, enterprise=$enterprise_earnings");
                    echo json_encode([
                        'success' => true,
                        'personal_earnings' => $personal_earnings,
                        'global_earnings' => $enterprise_earnings,
                        'pie_data' => $pie_data,
                        'line_data' => $line_data
                    ]);
                } catch (Exception $e) {
                    logDebug('Get earnings data error: ' . $e->getMessage(), 'ERROR');
                    echo json_encode(['success' => false, 'message' => 'dbQueryFailed', 'debug' => $e->getMessage()]);
                }
                break;

            case 'toggle_reseller_status':
                $reseller_id = $input['reseller_id'] ?? 0;
                $new_status = $input['new_status'] ?? null;
                if (!$reseller_id || !in_array($new_status, [0, 1], true)) {
                    throw new Exception("Invalid reseller_id or new_status: reseller_id=$reseller_id, new_status=$new_status");
                }
                logDebug("Toggling reseller status: reseller_id=$reseller_id, new_status=$new_status");

                $conn->begin_transaction();
                try {
                    $query = "SELECT id FROM resellers WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param('i', $reseller_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Reseller query execution failed: " . $stmt->error);
                    }
                    $result = $stmt->get_result();
                    if ($result->num_rows === 0) {
                        throw new Exception("Reseller not found: ID=$reseller_id");
                    }
                    $stmt->close();

                    $query = "UPDATE resellers SET status = ? WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param('ii', $new_status, $reseller_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Update failed: " . $stmt->error);
                    }
                    $conn->commit();
                    logDebug("Reseller status updated: reseller_id=$reseller_id, status=$new_status");
                    echo json_encode(['success' => true, 'status' => $new_status]);
                } catch (Exception $e) {
                    $conn->rollback();
                    logDebug('Toggle reseller status error: ' . $e->getMessage(), 'ERROR');
                    echo json_encode(['success' => false, 'message' => 'statusUpdateFailed', 'debug' => $e->getMessage()]);
                }
                break;

            case 'get_resellers':
                try {
                    $query = "SELECT id, name, phone, email, address, status, created_at FROM resellers ORDER BY id DESC";
                    $result = $conn->query($query);
                    if (!$result) {
                        throw new Exception("Resellers query failed: " . $conn->error);
                    }
                    $resellers = [];
                    while ($row = $result->fetch_assoc()) {
                        $resellers[] = $row;
                    }
                    logDebug("Fetched " . count($resellers) . " resellers");
                    echo json_encode(['success' => true, 'resellers' => $resellers]);
                } catch (Exception $e) {
                    logDebug('Get resellers error: ' . $e->getMessage(), 'ERROR');
                    echo json_encode(['success' => false, 'message' => 'dbQueryFailed', 'debug' => $e->getMessage()]);
                }
                break;

            default:
                throw new Exception("Invalid action: $action");
        }
    } catch (Exception $e) {
        logDebug('POST request error: ' . $e->getMessage(), 'ERROR');
        echo json_encode(['success' => false, 'message' => 'invalidAction', 'debug' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZinbConnect - Admin Dashboard</title>
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
        .signal-icon-container {
            position: relative;
            display: inline-flex;
            align-items: flex-end;
            height: 1rem;
            width: 1rem;
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
<body class="min-h-screen bg-gradient-animate flex flex-col font-sans text-gray-900 dark:text-gray-100 dark:bg-gray-900 relative">
    <div id="particles-js" class="absolute inset-0 z-0 opacity-15"></div>
    <header class="w-full p-4 flex justify-between items-center bg-white dark:bg-gray-600 shadow-lg relative z-10">
        <h1 class="text-1xl font-bold text-purple-500 dark:text-purple-200 flex items-center justify-left animate-pulse">
            <div class="signal-icon-container mr-2">
                <div class="signal-bar"></div>
                <div class="signal-bar"></div>
                <div class="signal-bar"></div>
            </div>
            ZinbConnect
        </h1>
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
            <h1 class="text-3xl sm:text-4xl font-bold text-purple-400 dark:text-purple-200 text-opacity-100" id="welcomeText">Welcome, <?php echo htmlspecialchars($first_name); ?></h1>
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
                        try {
                            $query = "SELECT id, name, rate FROM subscriptions ORDER BY id";
                            $result = $conn->query($query);
                            if (!$result) {
                                throw new Exception("Subscriptions query failed: " . $conn->error);
                            }
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    echo "<option value='{$row['id']}' data-rate='{$row['rate']}'>{$row['name']} - {$row['rate']} CDF</option>";
                                }
                                logDebug("Loaded " . $result->num_rows . " subscriptions");
                            } else {
                                echo "<option value='' disabled id='noSubscriptions'>No subscriptions available</option>";
                                logDebug("No subscriptions found", 'WARNING');
                            }
                        } catch (Exception $e) {
                            logDebug('Subscription load error: ' . $e->getMessage(), 'ERROR');
                            echo "<option value='' disabled id='noSubscriptions'>Error loading subscriptions</option>";
                        }
                        ?>
                    </select>
                    <input id="buyerContact" type="text" class="w-full p-2 border rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-opacity-100" placeholder="Phone or Email" required>
                    <button id="activateBtn" class="w-full p-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 btn">Activate</button>
                </div>
                <div id="codeResult" class="mt-4 hidden">
                    <div class="flex flex-wrap gap-2 mt-2">
                        <button id="copyCode" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 btn"><i class="fas fa-copy mr-1"></i> <span id="copyText">Copy</span></button>
                        <button id="printCode" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 btn"><i class="fas fa-print mr-1"></i> <span id="printText">Print</span></button>
                    </div>
                </div>
            </div>

            <div class="bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 p-6 rounded-xl shadow-lg card">
                <h2 class="text-xl font-bold mb-4 text-purple-600 dark:text-purple-400 text-opacity-100 flex items-center">
                    <i class="fas fa-chart-line mr-2 wifi-icon"></i> <span id="earningsTitle">Earnings</span>
                </h2>
                <div class="text-center mb-4">
                    <p id="personalEarnings" class="text-2xl font-bold text-green-600 text-opacity-100">0 CDF</p>
                    <p id="enterpriseEarnings" class="text-xl font-bold text-blue-600 text-opacity-100 mt-2">0 CDF (Enterprise)</p>
                </div>
                <div class="flex justify-between mb-4">
                    <button id="exportEarnings" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 btn">
                        <i class="fas fa-file-excel mr-1"></i> <span id="exportEarningsText">Export</span>
                    </button>
                    <button id="viewInsights" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 btn">
                        <i class="fas fa-lightbulb mr-1"></i> <span id="insightsText">View Insights</span>
                    </button>
                </div>
                <div class="grid grid-cols-1 gap-4">
                    <canvas id="pieChart" class="w-full h-48 sm:h-64"></canvas>
                    <canvas id="lineChart" class="w-full h-48 sm:h-64"></canvas>
                </div>
            </div>

            <div class="bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 p-6 rounded-xl shadow-lg lg:col-span-2 card">
                <h2 class="text-xl font-bold mb-4 text-purple-600 dark:text-purple-400 text-opacity-100 flex items-center">
                    <i class="fas fa-table mr-2 wifi-icon"></i> <span id="transactionsTitle">Transactions</span>
                </h2>
                <button id="exportTransactions" class="mb-4 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 btn">
                    <i class="fas fa-file-excel mr-1"></i> <span id="exportTransactionsText">Export</span>
                </button>
                <div class="overflow-x-auto">
                    <table id="transactionsTable" class="w-full border-collapse">
                        <thead>
                            <tr class="bg-purple-100 dark:bg-gray-800">
                                <th class="p-2 text-left" id="thId">ID</th>
                                <th class="p-2 text-left" id="thReseller">Reseller</th>
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

            <div class="bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 p-6 rounded-xl shadow-lg lg:col-span-2 card">
                <h2 class="text-xl font-bold mb-4 text-purple-600 dark:text-purple-400 text-opacity-100 flex items-center">
                    <i class="fas fa-users mr-2 wifi-icon"></i> <span id="resellersTitle">Resellers</span>
                </h2>
                <button id="exportResellers" class="mb-4 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 btn">
                    <i class="fas fa-file-excel mr-1"></i> <span id="exportResellersText">Export</span>
                </button>
                <div class="overflow-x-auto">
                    <table id="resellersTable" class="w-full border-collapse rounded-md shadow-sm">
                        <thead>
                            <tr class="bg-purple-100 dark:bg-gray-800">
                                <th class="p-2 text-left" id="thResellerId">ID</th>
                                <th class="p-2 text-left" id="thName">Name</th>
                                <th class="p-2 text-left" id="thPhone">Phone</th>
                                <th class="p-2 text-left" id="thEmail">Email</th>
                                <th class="p-2 text-left" id="thAddress">Address</th>
                                <th class="p-2 text-left" id="thStatus">Status</th>
                                <th class="p-2 text-left" id="thCreated">Created</th>
                                <th class="p-2 text-left" id="thAction">Action</th>
                            </tr>
                        </thead>
                        <tbody id="resellersBody" class="table-container"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div id="modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-20">
        <div class="bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 p-6 rounded-xl shadow-2xl max-w-md w-11/12 sm:w-full modal">
            <div class="flex justify-between items-center mb-4">
                <h3 id="modalTitle" class="text-lg font-bold text-purple-600 dark:text-purple-400"></h3>
                <button id="closeModal" class="text-gray-600 dark:text-gray-400 hover:text-gray-800">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p id="modalMessage" class="text-gray-700 dark:text-gray-300"></p>
            <div id="modalContent" class="mt-4"></div>
            <div class="mt-6 flex justify-end">
                <button id="modalCloseBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 btn">Close</button>
            </div>
        </div>
    </div>

    <footer class="w-full p-4 bg-white bg-opacity-80 dark:bg-gray-800 dark:bg-opacity-80 text-center text-purple-600 dark:text-purple-400 text-opacity-100 relative z-10">
        <p id="footerText">©2025 Zinbo Technology</p>
    </footer>

    <script>
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
                activating: "Activating...",
                copy: "Copy",
                print: "Print",
                earnings: "Earnings",
                enterprise: "Enterprise",
                export: "Export",
                insights: "View Insights",
                transactions: "Transactions",
                thId: "ID",
                thReseller: "Reseller",
                thSubscription: "Subscription",
                thBuyerContact: "Buyer Contact",
                thCode: "Code",
                thAmount: "Amount (CDF)",
                thCommission: "Commission (CDF)",
                thDate: "Date",
                resellers: "Resellers",
                thResellerId: "ID",
                thName: "Name",
                thPhone: "Phone",
                thEmail: "Email",
                thAddress: "Address",
                thStatus: "Status",
                thCreated: "Created",
                thAction: "Action",
                approve: "Approved",
                unapprove: "Unapproved",
                footer: "©2025 Zinbo Technology",
                loading: "Loading...",
                noData: "No data yet",
                noResellers: "No resellers yet",
                success: "Code activated successfully!",
                logoutSuccess: "Logged out successfully!",
                invalidFields: "Please fill all fields.",
                invalidSubscription: "Invalid subscription selected.",
                invalidContact: "Invalid email or phone format.",
                activationFailed: "Code activation failed. Please try again.",
                emailFailed: "Failed to send email. Please try again.",
                smsFailed: "Failed to send SMS. Please try again.",
                transactionInsertFailed: "Failed to record transaction.",
                currentCodesInsertFailed: "Failed to store code.",
                dbCleanupFailed: "Failed to clean expired codes.",
                codeGenerationFailed: "Failed to generate unique code.",
                dbConnectionFailed: "Server error. Contact support.",
                emailConfigFailed: "Email service not configured.",
                smsConfigFailed: "SMS service not configured.",
                copied: "Code copied to clipboard!",
                statusUpdated: "Reseller status updated!",
                statusFailed: "Failed to update reseller status.",
                insightsTitle: "Business Insights & Predictions",
                growthAdvice: "Growth Advice",
                securityWarnings: "Security Warnings",
                behavioralInsights: "Behavioral Insights",
                close: "Close",
                confirmationSent: "Confirmation sent successfully!",
                serverError: "Server error occurred."
            },
            fr: {
                logout: "Déconnexion",
                welcome: "Bienvenue",
                activateCode: "Activer le Code",
                selectSubscription: "Sélectionner un Abonnement",
                noSubscriptions: "Aucun abonnement disponible",
                buyerContactPlaceholder: "Téléphone ou Email",
                activate: "Activer",
                activating: "Activation...",
                copy: "Copier",
                print: "Imprimer",
                earnings: "Gains",
                enterprise: "Entreprise",
                export: "Exporter",
                insights: "Insights & Prédictions",
                transactions: "Transactions",
                thId: "ID",
                thReseller: "Revendeur",
                thSubscription: "Abonnement",
                thBuyerContact: "Contact Acheteur",
                thCode: "Code",
                thAmount: "Montant (CDF)",
                thCommission: "Commission (CDF)",
                thDate: "Date",
                resellers: "Revendeurs",
                thResellerId: "ID",
                thName: "Nom",
                thPhone: "Téléphone",
                thEmail: "Email",
                thAddress: "Adresse",
                thStatus: "Statut",
                thCreated: "Créé",
                thAction: "Action",
                approve: "Approuvé",
                unapprove: "Non approuvé",
                footer: "©2025 Zinbo Technologie",
                loading: "Chargement...",
                noData: "Aucune donnée",
                noResellers: "Aucun revendeur",
                success: "Code activé avec succès !",
                logoutSuccess: "Déconnexion réussie !",
                invalidFields: "Veuillez remplir tous les champs.",
                invalidSubscription: "Abonnement invalide sélectionné.",
                invalidContact: "Format d'email ou de téléphone invalide.",
                activationFailed: "Échec de l'activation. Réessayez.",
                emailFailed: "Échec de l'envoi de l'email.",
                smsFailed: "Échec de l'envoi du SMS.",
                transactionInsertFailed: "Échec de l'enregistrement de la transaction.",
                currentCodesInsertFailed: "Échec de l'enregistrement du code.",
                dbCleanupFailed: "Échec du nettoyage des codes expirés.",
                codeGenerationFailed: "Échec de la génération d'un code unique.",
                dbConnectionFailed: "Erreur serveur. Contactez-nous.",
                emailConfigFailed: "Service email non configuré.",
                smsConfigFailed: "Service SMS non configuré.",
                copied: "Code copié avec succès !",
                statusUpdated: "Statut du revendeur mis à jour !",
                statusFailed: "Échec de la mise à jour du statut.",
                insightsTitle: "Insights & Prédictions Commerciales",
                growthAdvice: "Conseils de Croissance",
                securityWarnings: "Avertissements de Sécurité",
                behavioralInsights: "Insights Comportementaux",
                close: "Fermer",
                confirmationSent: "Confirmation envoyée avec succès !",
                serverError: "Erreur serveur survenue."
            },
            sw: {
                logout: "Toka",
                welcome: "Karibu",
                activateCode: "Washa Nambari",
                selectSubscription: "Chagua Usajili",
                noSubscriptions: "Hakuna usajili unaopatikana",
                buyerContactPlaceholder: "Simu au Barua Pepe",
                activate: "Washa",
                activating: "Inawasha...",
                copy: "Nakili",
                print: "Chapa",
                earnings: "Mapato",
                enterprise: "Biashara",
                export: "Hamisha",
                insights: "Ona Maarifa",
                transactions: "Miamala",
                thId: "ID",
                thReseller: "Muuzaji",
                thSubscription: "Usajili",
                thBuyerContact: "Mwasiliani wa Mnunuzi",
                thCode: "Nambari",
                thAmount: "Kiasi (CDF)",
                thCommission: "Tume (CDF)",
                thDate: "Tarehe",
                resellers: "Wauzaji",
                thResellerId: "ID",
                thName: "Jina",
                thPhone: "Simu",
                thEmail: "Barua Pepe",
                thAddress: "Anwani",
                thStatus: "Hali",
                thCreated: "Imeundwa",
                thAction: "Kitendo",
                approve: "Imeidhinishwa",
                unapprove: "Haijaidhinishwa",
                footer: "©2025 Teknolojia ya Zinbo",
                loading: "Inapakia...",
                noData: "Hakuna data bado",
                noResellers: "Hakuna wauzaji bado",
                success: "Nambari imewashwa vizuri!",
                logoutSuccess: "Umetoka vizuri!",
                invalidFields: "Tafadhali jaza sehemu zote.",
                invalidSubscription: "Usajili batili umechaguliwa.",
                invalidContact: "Muundo wa barua pepe au simu si sahihi.",
                activationFailed: "Imeshindwa kuwasha nambari.",
                emailFailed: "Imeshindwa kutuma barua pepe.",
                smsFailed: "Imeshindwa kutuma SMS.",
                transactionInsertFailed: "Imeshindwa kurekodi miamala.",
                currentCodesInsertFailed: "Imeshindwa kuhifadhi nambari.",
                dbCleanupFailed: "Imeshindwa kusafisha nambari zilizopita muda.",
                codeGenerationFailed: "Imeshindwa kutoa nambari ya pekee.",
                dbConnectionFailed: "Hitilafu ya seva. Wasiliana nasi.",
                emailConfigFailed: "Huduma ya barua pepe haijasanidiwa.",
                smsConfigFailed: "Huduma ya SMS haijasanidiwa.",
                copied: "Nambari imenakiliwa kwenye ubao wa kunakili!",
                statusUpdated: "Hali ya muuzaji imesasishwa!",
                statusFailed: "Imeshindwa kusasisha hali ya muuzaji.",
                insightsTitle: "Maarifa ya Biashara & Utabiri",
                growthAdvice: "Ushauri wa Ukuaji",
                securityWarnings: "Onyo za Usalama",
                behavioralInsights: "Maarifa ya Tabia",
                close: "Funga",
                confirmationSent: "Uthibitisho umetumwa vizuri!",
                serverError: "Hitilafu ya seva imetokea."
            }
        };

        let currentLang = 'en';
        const modal = document.getElementById('modal');
        const modalTitle = document.getElementById('modalTitle');
        const modalMessage = document.getElementById('modalMessage');
        const modalContent = document.getElementById('modalContent');
        const closeModal = document.getElementById('closeModal');
        const modalCloseBtn = document.getElementById('modalCloseBtn');

        function showModal(title, message, content = '') {
            modalTitle.textContent = title;
            modalMessage.textContent = message;
            modalContent.innerHTML = content;
            modal.classList.remove('hidden');
            modalCloseBtn.focus();
        }

        function hideModal() {
            modal.classList.add('hidden');
            modalContent.innerHTML = '';
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
            loadResellers();
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
            document.getElementById('earningsTitle').textContent = translations[currentLang].earnings;
            document.getElementById('exportEarningsText').textContent = translations[currentLang].export;
            document.getElementById('insightsText').textContent = translations[currentLang].insights;
            document.getElementById('transactionsTitle').textContent = translations[currentLang].transactions;
            document.getElementById('exportTransactionsText').textContent = translations[currentLang].export;
            document.getElementById('resellersTitle').textContent = translations[currentLang].resellers;
            document.getElementById('exportResellersText').textContent = translations[currentLang].export;
            document.getElementById('thId').textContent = translations[currentLang].thId;
            document.getElementById('thReseller').textContent = translations[currentLang].thReseller;
            document.getElementById('thSubscription').textContent = translations[currentLang].thSubscription;
            document.getElementById('thBuyerContact').textContent = translations[currentLang].thBuyerContact;
            document.getElementById('thCode').textContent = translations[currentLang].thCode;
            document.getElementById('thAmount').textContent = translations[currentLang].thAmount;
            document.getElementById('thCommission').textContent = translations[currentLang].thCommission;
            document.getElementById('thDate').textContent = translations[currentLang].thDate;
            document.getElementById('thResellerId').textContent = translations[currentLang].thResellerId;
            document.getElementById('thName').textContent = translations[currentLang].thName;
            document.getElementById('thPhone').textContent = translations[currentLang].thPhone;
            document.getElementById('thEmail').textContent = translations[currentLang].thEmail;
            document.getElementById('thAddress').textContent = translations[currentLang].thAddress;
            document.getElementById('thStatus').textContent = translations[currentLang].thStatus;
            document.getElementById('thCreated').textContent = translations[currentLang].thCreated;
            document.getElementById('thAction').textContent = translations[currentLang].thAction;
            document.getElementById('footerText').textContent = translations[currentLang].footer;
            document.getElementById('modalCloseBtn').textContent = translations[currentLang].close;
        }

        function performLogout() {
            fetch('/admin.php', {
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
                    showModal('Error', translations[currentLang].activationFailed + ': ' + data.debug);
                }
            })
            .catch(error => {
                console.error('Logout error:', error);
                showModal('Error', translations[currentLang].serverError);
            });
        }

        document.getElementById('logoutBtn').addEventListener('click', performLogout);

        let currentCode = '';
        document.getElementById('activateBtn').addEventListener('click', () => {
            const subscriptionId = document.getElementById('subscriptionId').value;
            let buyerContact = document.getElementById('buyerContact').value.trim();
            if (subscriptionId && buyerContact) {
                const activateBtn = document.getElementById('activateBtn');
                activateBtn.disabled = true;
                activateBtn.textContent = translations[currentLang].activating;
                fetch('/admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'activate_code', subscription_id: subscriptionId, buyer_contact: buyerContact })
                })
                .then(response => response.json())
                .then(data => {
                    activateBtn.disabled = false;
                    activateBtn.textContent = translations[currentLang].activate;
                    if (data.success) {
                        currentCode = data.code;
                        const content = `Code: ${currentCode}<br>Rate: ${data.rate} CDF<br>Commission: ${data.commission} CDF<br>${data.confirmation_sent}<br>Expires: ${data.expiration_date}`;
                        showModal(translations[currentLang].success, content);
                        document.getElementById('codeResult').classList.remove('hidden');
                        loadCharts();
                        loadTransactions();
                    } else {
                        showModal('Error', translations[currentLang][data.message] || translations[currentLang].activationFailed + ': ' + data.debug);
                        console.error('Activation error:', data.debug);
                    }
                })
                .catch(error => {
                    console.error('Activate code error:', error);
                    activateBtn.disabled = false;
                    activateBtn.textContent = translations[currentLang].activate;
                    showModal('Error', translations[currentLang].serverError);
                });
            } else {
                showModal('Error', translations[currentLang].invalidFields);
            }
        });

        document.getElementById('copyCode').addEventListener('click', () => {
            navigator.clipboard.writeText(currentCode).then(() => {
                showModal(translations[currentLang].copied, translations[currentLang].copied);
            });
        });

        document.getElementById('printCode').addEventListener('click', () => {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html><head><title>ZinbConnect Code</title></head><body>
                    <h1>ZinbConnect Access Code</h1>
                    <p><strong>Code:</strong> ${currentCode}</p>
                    <p><strong>Admin:</strong> <?php echo htmlspecialchars($first_name); ?></p>
                    <p><strong>Date:</strong> ${new Date().toLocaleString()}</p>
                </body></html>
            `);
            printWindow.document.close();
            printWindow.print();
        });

        function loadTransactions() {
            const tbody = document.getElementById('transactionsBody');
            tbody.innerHTML = `<tr><td colspan="8" class="p-2 text-center">${translations[currentLang].loading}</td></tr>`;
            fetch('/admin.php', {
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
                            <td class="p-2">${t.reseller}</td>
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
                    tbody.innerHTML = `<tr><td colspan="8" class="p-2 text-center">${translations[currentLang].noData}</td></tr>`;
                }
            })
            .catch(error => {
                console.error('Transaction fetch error:', error);
                tbody.innerHTML = `<tr><td colspan="8" class="p-2 text-center">${translations[currentLang].noData}</td></tr>`;
            });
        }

        function loadResellers() {
            const tbody = document.getElementById('resellersBody');
            tbody.innerHTML = `<tr><td colspan="8" class="p-2 text-center">${translations[currentLang].loading}</td></tr>`;
            fetch('/admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_resellers' })
            })
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = '';
                if (data.success && data.resellers.length > 0) {
                    data.resellers.forEach(r => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td class="p-2">${r.id}</td>
                            <td class="p-2">${r.name || '-'}</td>
                            <td class="p-2">${r.phone || '-'}</td>
                            <td class="p-2">${r.email || '-'}</td>
                            <td class="p-2">${r.address || '-'}</td>
                            <td class="p-2">${r.status === 1 ? translations[currentLang].approve : translations[currentLang].unapprove}</td>
                            <td class="p-2">${new Date(r.created_at).toLocaleString()}</td>
                            <td class="p-2">
                                <button class="toggle-status px-2 py-1 rounded-md text-white ${r.status === 1 ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'} btn" data-id="${r.id}" data-status="${r.status}">
                                    ${r.status === 1 ? translations[currentLang].approve : translations[currentLang].unapprove}
                                </button>
                            </td>
                        `;
                        tbody.appendChild(row);
                    });
                    document.querySelectorAll('.toggle-status').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const resellerId = btn.dataset.id;
                            const newStatus = btn.dataset.status === '1' ? 0 : 1;
                            btn.disabled = true;
                            fetch('/admin.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'toggle_reseller_status', reseller_id: resellerId, new_status: newStatus })
                            })
                            .then(response => response.json())
                            .then(data => {
                                btn.disabled = false;
                                if (data.success) {
                                    btn.textContent = data.status === 1 ? translations[currentLang].approve : translations[currentLang].unapprove;
                                    btn.dataset.status = data.status;
                                    btn.classList.toggle('bg-green-600');
                                    btn.classList.toggle('bg-red-600');
                                    showModal(translations[currentLang].statusUpdated, translations[currentLang].statusUpdated);
                                    loadResellers();
                                } else {
                                    showModal('Error', translations[currentLang].statusFailed + ': ' + data.debug);
                                }
                            })
                            .catch(error => {
                                btn.disabled = false;
                                showModal('Error', translations[currentLang].statusFailed);
                            });
                        });
                    });
                } else {
                    tbody.innerHTML = `<tr><td colspan="8" class="p-2 text-center">${translations[currentLang].noResellers}</td></tr>`;
                }
            })
            .catch(error => {
                console.error('Resellers fetch error:', error);
                tbody.innerHTML = `<tr><td colspan="8" class="p-2 text-center">${translations[currentLang].noResellers}</td></tr>`;
            });
        }

        let pieChart, lineChart;
        function loadCharts() {
            document.getElementById('personalEarnings').textContent = translations[currentLang].loading;
            document.getElementById('enterpriseEarnings').textContent = translations[currentLang].loading;
            fetch('/admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_earnings_data' })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('personalEarnings').textContent = `${data.personal_earnings || 0} CDF`;
                document.getElementById('enterpriseEarnings').textContent = `${data.global_earnings || 0} CDF (${translations[currentLang].enterprise})`;
                if (data.success) {
                    if (pieChart) pieChart.destroy();
                    const pieCtx = document.getElementById('pieChart').getContext('2d');
                    pieChart = new Chart(pieCtx, {
                        type: 'pie',
                        data: {
                            labels: data.pie_data.labels,
                            datasets: [{
                                data: data.pie_data.data,
                                backgroundColor: ['#a78bfa', '#d8b4fe', '#f3e8ff', '#6b7280']
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: { legend: { position: 'top' } }
                        }
                    });

                    if (lineChart) lineChart.destroy();
                    const lineCtx = document.getElementById('lineChart').getContext('2d');
                    lineChart = new Chart(lineCtx, {
                        type: 'line',
                        data: {
                            labels: data.line_data.labels,
                            datasets: [{
                                label: translations[currentLang].enterprise,
                                data: data.line_data.data,
                                borderColor: '#a78bfa',
                                fill: false
                            }]
                        },
                        options: {
                            responsive: true,
                            scales: { y: { beginAtZero: true } }
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Charts fetch error:', error);
                document.getElementById('personalEarnings').textContent = '0 CDF';
                document.getElementById('enterpriseEarnings').textContent = `0 CDF (${translations[currentLang].enterprise})`;
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
            if (!data.line_data.labels.length) return { predictions: [], advice: [], warnings: [], behaviors: [] };
            const x = data.line_data.labels.map((_, i) => i);
            const y = data.line_data.data.map(Number);
            const { slope, intercept } = linearRegression(x, y);
            const predictions = [];
            for (let i = x.length; i < x.length + 30; i++) {
                predictions.push(Math.max(0, slope * i + intercept));
            }
            const growthRate = slope > 0 ? (slope / y[y.length - 1] * 100).toFixed(2) : 0;
            const advice = [
                growthRate > 0 ? `${translations[currentLang].growthAdvice}: ${growthRate}%` : translations[currentLang].noData,
                translations[currentLang].noData
            ];
            const warnings = [];
            const buyerContacts = data.transactions?.map(t => t.buyer_contact) || [];
            const contactCounts = buyerContacts.reduce((acc, c) => ({ ...acc, [c]: (acc[c] || 0) + 1 }), {});
            for (const [contact, count] of Object.entries(contactCounts)) {
                if (count > 3) warnings.push(`Multiple transactions (${count}) from ${contact}.`);
            }
            const behaviors = [];
            const hours = data.transactions?.map(t => new Date(t.created_at).getHours()) || [];
            const peakHour = hours.sort((a, b) => hours.filter(v => v === b).length - hours.filter(v => v === a).length)[0];
            if (peakHour) behaviors.push(`Peak transaction time: ${peakHour} - ${peakHour + 1} ${translations[currentLang].hours}`);
return { predictions, advice, warnings, behaviors };
}

document.getElementById('viewInsights').addEventListener('click', () => {
fetch('/admin.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'get_earnings_data' })
})
.then(response => response.json())
.then(data => {
if (data.success) {
const { predictions, advice, warnings, behaviors } = predictFuture(data);
const content = `

${translations[currentLang].growthAdvice}
${advice.map(a => `
${a}
`).join('')}
${translations[currentLang].securityWarnings}
${warnings.length ? warnings.map(w => `
${w}
`).join('') : `
${translations[currentLang].noData}
`}
${translations[currentLang].behavioralInsights}
${behaviors.length ? behaviors.map(b => `
${b}
`).join('') : `
${translations[currentLang].noData}
`}
`; showModal(translations[currentLang].insightsTitle, '', content); } else { showModal('Error', translations[currentLang].activationFailed + ': ' + data.debug); } }) .catch(error => { console.error('Insights fetch error:', error); showModal('Error', translations[currentLang].serverError); }); });
function exportToCSV(data, filename, headers, labels) {
const csv = [
headers.join(','),
...data.map(row => Object.values(row).map(val => "${val}").join(','))
].join('\n');
const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
const link = document.createElement('a');
link.href = URL.createObjectURL(blob);
link.download = filename;
link.click();
}

document.getElementById('exportTransactions').addEventListener('click', () => {
fetch('/admin.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'get_transactions' })
})
.then(response => response.json())
.then(data => {
if (data.success && data.transactions.length > 0) {
exportToCSV(
data.transactions,
'transactions.csv',
[
translations[currentLang].thId,
translations[currentLang].thReseller,
translations[currentLang].thSubscription,
translations[currentLang].thBuyerContact,
translations[currentLang].thCode,
translations[currentLang].thAmount,
translations[currentLang].thCommission,
translations[currentLang].thDate
]
);
} else {
showModal('Error', translations[currentLang].noData);
}
})
.catch(error => {
console.error('Export transactions error:', error);
showModal('Error', translations[currentLang].serverError);
});
});

document.getElementById('exportResellers').addEventListener('click', () => {
fetch('/admin.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'get_resellers' })
})
.then(response => response.json())
.then(data => {
if (data.success && data.resellers.length > 0) {
exportToCSV(
data.resellers,
'resellers.csv',
[
translations[currentLang].thResellerId,
translations[currentLang].thName,
translations[currentLang].thPhone,
translations[currentLang].thEmail,
translations[currentLang].thAddress,
translations[currentLang].thStatus,
translations[currentLang].thCreated
]
);
} else {
showModal('Error', translations[currentLang].noResellers);
}
})
.catch(error => {
console.error('Export resellers error:', error);
showModal('Error', translations[currentLang].serverError);
});
});

document.getElementById('exportEarnings').addEventListener('click', () => {
fetch('/admin.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'get_earnings_data' })
})
.then(response => response.json())
.then(data => {
if (data.success) {
const earningsData = [
{
Type: translations[currentLang].earnings,
Amount: data.personal_earnings || 0,
Currency: 'CDF'
},
{
Type: translations[currentLang].enterprise,
Amount: data.global_earnings || 0,
Currency: 'CDF'
}
];
exportToCSV(
earningsData,
'earnings.csv',
['Type', 'Amount', 'Currency']
);
} else {
showModal('Error', translations[currentLang].noData);
}
})
.catch(error => {
console.error('Export earnings error:', error);
showModal('Error', translations[currentLang].serverError);
});
});

document.addEventListener('DOMContentLoaded', () => {
updateLanguage();
loadCharts();
loadTransactions();
loadResellers();
});
</script>
</body>
</html>

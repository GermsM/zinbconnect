<?php
header('Content-Type: application/json');
require_once 'index.php'; // Include DB functions

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = isset($_POST['accessCode']) ? $_POST['accessCode'] : '';
    $result = validateAccessCode($code);
    
    if ($result['valid']) {
        session_start();
        $_SESSION['accessCode'] = $code;
        $_SESSION['codeExpiry'] = time() + 24 * 3600; // Example: 24 hours
        echo json_encode(['success' => true, 'message' => 'Connected successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired access code.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
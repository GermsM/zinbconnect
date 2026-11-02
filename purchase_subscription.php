<?php
header('Content-Type: application/json');
require_once 'index.php'; // Include DB functions

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subscription_id = isset($_POST['subscription_id']) ? $_POST['subscription_id'] : '';
    $contact = isset($_POST['contact']) ? $_POST['contact'] : '';
    $contact_type = isset($_POST['contact_type']) ? $_POST['contact_type'] : '';
    
    if (empty($subscription_id) || empty($contact) || !in_array($contact_type, ['phone', 'email'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }
    
    $result = purchaseSubscription($subscription_id, $contact, $contact_type);
    echo json_encode($result);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
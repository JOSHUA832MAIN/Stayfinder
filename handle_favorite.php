<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['email'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once 'connectiondatabase/main_connection.php';

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_email = $_SESSION['email'];
$house_id = intval($_POST['house_id'] ?? 0);
$action = $_POST['action'] ?? ''; // 'add' or 'remove'

if ($house_id <= 0 || !in_array($action, ['add', 'remove'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

try {
    if ($action === 'add') {
        // Check if already favorited
        $check_stmt = $conn->prepare("SELECT id FROM favorites WHERE user_email = ? AND house_id = ?");
        $check_stmt->bind_param("si", $user_email, $house_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            $stmt = $conn->prepare("INSERT INTO favorites (user_email, house_id, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param("si", $user_email, $house_id);
            $stmt->execute();
            $stmt->close();
        }
        $check_stmt->close();
        
    } else if ($action === 'remove') {
        $stmt = $conn->prepare("DELETE FROM favorites WHERE user_email = ? AND house_id = ?");
        $stmt->bind_param("si", $user_email, $house_id);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
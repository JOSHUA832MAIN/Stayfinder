<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../connectiondatabase/main_connection.php';

$response = ['success' => false, 'message' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode($response);
    exit;
}

$house_id = isset($_POST['house_id']) ? intval($_POST['house_id']) : 0;
$access_code = isset($_POST['access_code']) ? trim($_POST['access_code']) : '';

if ($house_id <= 0 || $access_code === '') {
    $response['message'] = 'Missing parameters';
    echo json_encode($response);
    exit;
}

// Fetch stored hashed dashboard password for the house
$stmt = $conn->prepare("SELECT dashboard_password, owner_id FROM boarding_houses WHERE id = ? LIMIT 1");
if (!$stmt) {
    $response['message'] = 'DB error';
    echo json_encode($response);
    exit;
}
$stmt->bind_param('i', $house_id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) {
    $response['message'] = 'House not found';
    echo json_encode($response);
    exit;
}
$row = $result->fetch_assoc();
$stmt->close();

$stored_hash = $row['dashboard_password'];
$owner_id = $row['owner_id'];

// Optional: require owner session to match house owner for extra safety
if (!isset($_SESSION['owner_id']) || intval($_SESSION['owner_id']) !== intval($owner_id)) {
    $response['message'] = 'Not authorized';
    echo json_encode($response);
    exit;
}

// Validate code length (6 digits) and numeric
if (!preg_match('/^\d{6}$/', $access_code)) {
    $response['message'] = 'Invalid code format';
    echo json_encode($response);
    exit;
}

if (empty($stored_hash)) {
    $response['message'] = 'No access code set for this house';
    echo json_encode($response);
    exit;
}

// Verify using password_verify (stored as hash)
if (password_verify($access_code, $stored_hash)) {
    $response['success'] = true;
    $response['message'] = 'Code verified';
} else {
    $response['message'] = 'Invalid access code';
}

echo json_encode($response);
exit;

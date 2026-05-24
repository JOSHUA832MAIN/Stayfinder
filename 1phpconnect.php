<?php
session_start();
// ✅ CONNECT TO MAIN CONNECTION FILE
require_once 'main_connection.php';
// Check if user is logged in, redirect if not
if (empty($_SESSION['email'])) {
    echo "<script>alert('Login first before booking!'); window.location.href='login.php';</script>";
    exit;
}
// Get the user's email from session
$email = $conn->real_escape_string($_SESSION['email']);
$data = [];
// Loop through all form fields and collect data
foreach (['fullName', 'age', 'gender', 'address', 'phone', 'startDate', 'boardingHouse', 'price', 'distance', 'availableSlots', 'image'] as $field) {
    $data[$field] = isset($_POST[$field]) ? $conn->real_escape_string($_POST[$field]) : ($field == 'age' || $field == 'price' || $field == 'distance' || $field == 'availableSlots' ? 0 : '');
}
// Clean up price data (remove non-digits)
$data['price'] = preg_replace('/\D/', '', $data['price']);
$data['distance'] = preg_replace('/\D/', '', $data['distance']);
$data['availableSlots'] = strlen($data['availableSlots']);
// Check if booking already exists
if ($conn->query("SELECT 1 FROM yourbook WHERE email='$email' AND boardingHouse='{$data['boardingHouse']}'")->num_rows) {
    echo "<script>alert('Booking exists! Check Yourbook page.'); window.location.href='dashboard.php';</script>";
    exit;
}

// Create SQL query to insert booking data
$stmt = $conn->prepare("INSERT INTO yourbook (fullName, age, gender, address, phone, email, startDate, boardingHouse, price, distance, availableSlots, imagePath) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sissssssiiis", $data['fullName'], $data['age'], $data['gender'], $data['address'], $data['phone'], $email, $data['startDate'], $data['boardingHouse'], $data['price'], $data['distance'], $data['availableSlots'], $data['image']);
echo $stmt->execute() ? "<script>alert('Booking successful!'); window.location.href='yourbook.php';</script>" : "Error: " . $stmt->error;
$stmt->close();
// ✅ CONNECTION WILL BE CLOSED AUTOMATICALLY

?>

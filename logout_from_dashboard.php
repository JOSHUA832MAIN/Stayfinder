<?php
session_start();

// Store owner session data before clearing anything
$owner_logged_in = $_SESSION['owner_logged_in'] ?? false;
$owner_id = $_SESSION['owner_id'] ?? null;
$owner_name = $_SESSION['owner_name'] ?? null;
$owner_email = $_SESSION['owner_email'] ?? null;

// Clear ONLY the boarding house session variables
unset($_SESSION['house_owner_logged_in']);
unset($_SESSION['house_owner_id']);
unset($_SESSION['house_owner_name']);
unset($_SESSION['house_data']);
unset($_SESSION['login_method']);

// IMPORTANT: Restore the owner session data
$_SESSION['owner_logged_in'] = $owner_logged_in;
$_SESSION['owner_id'] = $owner_id;
if ($owner_name) $_SESSION['owner_name'] = $owner_name;
if ($owner_email) $_SESSION['owner_email'] = $owner_email;

// Redirect back to createboardinghouse.php
header("Location: createboardinghouse.php");
exit();
?>
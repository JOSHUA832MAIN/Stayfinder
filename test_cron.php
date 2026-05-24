<?php
echo "<h2>🔧 StayFinder Cron Job Test</h2>";
echo "<p>Testing cron job functionality...</p>";

// Include the cron job
include 'cron.php';

echo "<hr>";
echo "<h3>📋 Recent Log Entries:</h3>";

// Show recent log entries
if (file_exists(__DIR__ . "/cron_log.txt")) {
    $logs = file_get_contents(__DIR__ . "/cron_log.txt");
    $logLines = explode("\n", $logs);
    $recentLogs = array_slice($logLines, -20); // Last 20 lines
    
    echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; font-family: monospace; white-space: pre-wrap;'>";
    echo implode("\n", $recentLogs);
    echo "</div>";
} else {
    echo "<p>No log file found yet.</p>";
}

echo "<hr>";
echo "<h3>❌ Recent Error Entries:</h3>";

// Show recent error entries
if (file_exists(__DIR__ . "/cron_errors.txt")) {
    $errors = file_get_contents(__DIR__ . "/cron_errors.txt");
    $errorLines = explode("\n", $errors);
    $recentErrors = array_slice($errorLines, -10); // Last 10 lines
    
    echo "<div style='background: #fee; padding: 15px; border-radius: 5px; font-family: monospace; white-space: pre-wrap; color: #c53030;'>";
    echo implode("\n", $recentErrors);
    echo "</div>";
} else {
    echo "<p>No error file found - that's good!</p>";
}

echo "<hr>";
echo "<h3>📊 Database Status:</h3>";

// Check pending requests
require_once __DIR__ . '/connectiondatabase/main_connection.php';

$pendingQuery = "SELECT COUNT(*) as pending_count FROM tenant_requests WHERE status = 'pending' AND email_sent = 0";
$result = $conn->query($pendingQuery);
$pendingCount = $result->fetch_assoc()['pending_count'];

echo "<p><strong>Pending requests waiting for notification:</strong> $pendingCount</p>";

if ($pendingCount > 0) {
    echo "<p style='color: orange;'>⚠️ There are $pendingCount pending requests that need owner notification.</p>";
} else {
    echo "<p style='color: green;'>✅ All pending requests have been notified.</p>";
}
?>

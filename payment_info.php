<?php
session_start();

// Secure DB connection
require_once __DIR__ . '/../connectiondatabase/main_connection.php';

// Get parameters
$house_id = isset($_GET['house_id']) ? intval($_GET['house_id']) : (isset($_SESSION['house_owner_id']) ? intval($_SESSION['house_owner_id']) : 0);
$tenant_name = isset($_GET['tenant_name']) ? trim($_GET['tenant_name']) : '';

if ($house_id <= 0 || empty($tenant_name)) {
    die('Invalid request.');
}

// Prepare and fetch payment history for tenant
$stmt = $conn->prepare("SELECT payment_month, payment_date, next_due_date, status, created_at FROM payment_history WHERE house_id = ? AND tenant_name = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param('is', $house_id, $tenant_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $payments = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
} else {
    $payments = [];
}

// Count payments for badge
$payments_count = is_array($payments) ? count($payments) : 0;

// Helper: fetch tenant profile image by email or fullname (best-effort)
function getTenantProfileImageByIdentifier($conn, $identifier) {
    if (empty($identifier)) return null;
    // registerusers table has 'email' and 'fullname' columns; avoid referencing unknown columns
    $sql = "SELECT profile_img, email FROM registerusers WHERE email = ? OR fullname = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $res = $stmt->get_result();
    $img = null;
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $img = $row['profile_img'] ?? null;
    }
    $stmt->close();
    return $img;
}

$tenant_profile_img = getTenantProfileImageByIdentifier($conn, $tenant_name);
$display_profile_img = null;
if (!empty($tenant_profile_img)) {
    if (strpos($tenant_profile_img, '../') === 0 || strpos($tenant_profile_img, '/') === 0) {
        $display_profile_img = $tenant_profile_img;
    } else {
        $display_profile_img = (strpos($tenant_profile_img, 'profile_picture/') === 0 || strpos($tenant_profile_img, 'uploads/') === 0) ? '../' . $tenant_profile_img : $tenant_profile_img;
    }
}

// Get house name for header
$house_name = '';
$hstmt = $conn->prepare('SELECT name FROM boarding_houses WHERE id = ? LIMIT 1');
if ($hstmt) {
    $hstmt->bind_param('i', $house_id);
    $hstmt->execute();
    $hr = $hstmt->get_result()->fetch_assoc();
    $house_name = $hr['name'] ?? '';
    $hstmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <title>Payment Info - <?php echo htmlspecialchars($tenant_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; display: flex; flex-direction: column; min-height: 100vh; margin: 0; padding: 0; }
        main { flex: 1; }
        .header { background-color: #FFD700; color: #333; padding: 18px; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .header .container { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 18px; }
        .back { display: inline-block; background: #666; color: #fff; padding: 8px 14px; border-radius: 6px; text-decoration: none; margin-right: 12px; }

        /* Circular back button style */
        .back-btn-circle {
            width: 50px;
            height: 50px;
            background: #ffd700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 28px;
            font-weight: bold;
            color: #000;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
            border: none;
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 1001;
        }

        .back-btn-circle:hover {
            background: #ffed4e;
            transform: translateY(-50%) scale(1.12);
            box-shadow: 0 6px 16px rgba(255, 215, 0, 0.4);
            color: #000;
            text-decoration: none;
        }
        
        .back-btn-circle i.fa-arrow-left {
            font-size: 24px;
            font-weight: 900;
        }

        /* Tenant name styling */
        .tenant-name {
            display: block;
            font-weight: 800;
            font-size: 2.2rem;
            margin: 0;
            color: #111;
            line-height: 1.05;
        }
        .tenant-email { font-size: .95rem; color: #4b5563; margin-top:6px; }
        .profile-card { display:flex; align-items:center; gap:16px; background: #fff; padding: 10px 14px; border-radius: 12px; box-shadow: 0 6px 18px rgba(16,24,40,0.06); }
        .tenant-meta { display:flex; flex-direction:column; }
        .payments-badge { background:#FFD700; color:#111; font-weight:700; padding:8px 12px; border-radius:999px; border:2px solid #DAA520; }
        .container { max-width: 980px; margin: 20px auto; }
        .tenant-profile-img { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 3px solid #DAA520; box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
        .tenant-profile-placeholder { width: 96px; height: 96px; border-radius: 50%; background: linear-gradient(180deg,#FFD700,#FFCF33); display:flex; align-items:center; justify-content:center; font-size:34px; font-weight:800; color:#000; border:3px solid #DAA520; box-shadow:0 8px 24px rgba(0,0,0,0.12); }
        .card { border-radius: 10px; }
        .no-records { text-align: center; padding: 40px; background: white; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <a href="view_bookings.php?house_id=<?php echo $house_id; ?>" class="back-btn-circle" title="Back to Bookings"><i class="fas fa-arrow-left"></i></a>
            <div style="width:100%; text-align:center;">
                <strong>Payment Transactions for</strong>
                <div style="margin-top:6px; font-weight:800; font-size:1.4rem; color:#111;"><?php echo htmlspecialchars($tenant_name); ?></div>
            </div>
        </div>
    </div>

    <main>
        <div class="container mt-4">

        <!-- Profile card moved here (above payments table) -->
        <div class="profile-card" style="margin-bottom:16px; align-items:center; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:14px;">
                <?php if (!empty($display_profile_img) && file_exists($display_profile_img)): ?>
                    <img src="<?php echo htmlspecialchars($display_profile_img); ?>" alt="Profile" class="tenant-profile-img" />
                <?php else: ?>
                    <div class="tenant-profile-placeholder"><?php echo strtoupper(substr($tenant_name, 0, 1)); ?></div>
                <?php endif; ?>
                <div class="tenant-meta">
                    <strong class="tenant-name"><?php echo htmlspecialchars($tenant_name); ?></strong>
                    <span class="tenant-email"><?php echo htmlspecialchars($house_name); ?></span>
                    <div style="margin-top:6px; color:#6b7280;"><?php echo $payments_count; ?> payment<?php echo $payments_count !== 1 ? 's' : ''; ?> recorded</div>
                </div>
            </div>
            <div class="payments-badge">Payments: <?php echo $payments_count; ?></div>
        </div>

        <?php if (empty($payments)): ?>
            <div class="no-records">No payment transactions found for this tenant.</div>
        <?php else: ?>
            <div class="card p-3">
                <h5>Payment History</h5>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Payment Month</th>
                                <th>Payment Date</th>
                                <th>Next Due</th>
                                <th>Status</th>
                                <th>Recorded At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['payment_month']); ?></td>
                                <td><?php echo $p['payment_date'] ? date('M j, Y', strtotime($p['payment_date'])) : '—'; ?></td>
                                <td><?php echo $p['next_due_date'] ? date('M j, Y', strtotime($p['next_due_date'])) : '—'; ?></td>
                                <td><?php echo htmlspecialchars(strtoupper($p['status'])); ?></td>
                                <td><?php echo date('M j, Y h:i A', strtotime($p['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </main>

    <footer class="footer" style="background-color:#111;color:#fff;padding:10px 0;text-align:center;margin-top:auto;">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <p class="mb-0" style="font-size:13px;">
                        StayFinder: Boarding Locator and Management System
                    </p>
                    <p class="mt-1 mb-0" style="font-size:13px;">
                        <a href="../terms.php" class="text-warning text-decoration-none fw-bold">Terms &amp; Conditions</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>

<?php mysqli_close($conn); ?>

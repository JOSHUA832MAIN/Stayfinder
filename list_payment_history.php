<?php

session_start();

$envPath = dirname(__DIR__, 2) . '/.env';

if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
} else {
    die("❌ Missing .env file at: " . $envPath);
}

// ✅ Database connection
$conn = new mysqli(
    $env['DB_HOST'],
    $env['DB_USER'],
    $env['DB_PASS'],
    $env['DB_NAME']
);

if ($conn->connect_error) {
    die("❌ Database connection failed: " . $conn->connect_error);
}

// ✅ Get house_id from GET or SESSION
if (isset($_GET['house_id'])) {
    $house_id = intval($_GET['house_id']);
    $_SESSION['owner_house_id'] = $house_id;
} elseif (isset($_SESSION['owner_house_id'])) {
    $house_id = $_SESSION['owner_house_id'];
} else {
    die("❌ No house ID provided.");
}

// ✅ Get House Name
$house_sql = "SELECT id, name FROM boarding_houses WHERE id = $house_id LIMIT 1";
$house_result = mysqli_query($conn, $house_sql);
if (!$house_result) {
    die("❌ SQL Error (House Name): " . mysqli_error($conn));
}
if (mysqli_num_rows($house_result) == 0) {
    die("❌ Invalid house ID.");
}
$house = mysqli_fetch_assoc($house_result);
$house_name = $house['name'];

// ✅ Handle Month/Year Filter
$search_month = isset($_GET['search_month']) ? $_GET['search_month'] : '';
$search_year = isset($_GET['search_year']) ? $_GET['search_year'] : '';

// ✅ Build SQL Query with Optional Month/Year Filter - Join with tenant data to get profile, email, phone
$sql = "SELECT DISTINCT
            ph.tenant_name,
            ph.payment_month,
            ph.payment_date,
            ph.next_due_date,
            ph.status,
            ph.created_at,
            COALESCE(ru.profile_img, tr.email) as profile_img,
            COALESCE(ru.email, tr.email, yb.email) as email,
            COALESCE(ru.phone, tr.phone, yb.phone) as phone,
            COALESCE(rp.price, 'Not Set') as room_price
        FROM payment_history ph
        LEFT JOIN registerusers ru ON ph.tenant_name = ru.fullname
        LEFT JOIN tenant_requests tr ON (ph.tenant_name = tr.full_name AND tr.house_id = $house_id)
        LEFT JOIN room_prices rp ON (tr.room_number = rp.room_number AND rp.house_id = $house_id)
        LEFT JOIN yourbook yb ON (ph.tenant_name = yb.fullName AND yb.boardingHouse IN (SELECT name FROM boarding_houses WHERE id = $house_id))
        WHERE ph.house_id = $house_id";

if (!empty($search_month) && !empty($search_year)) {
    $search_month_safe = mysqli_real_escape_string($conn, $search_month);
    $search_year_safe = mysqli_real_escape_string($conn, $search_year);
    $sql .= " AND MONTH(ph.payment_date) = '$search_month_safe' AND YEAR(ph.payment_date) = '$search_year_safe'";
} elseif (!empty($search_year)) {
    $search_year_safe = mysqli_real_escape_string($conn, $search_year);
    $sql .= " AND YEAR(ph.payment_date) = '$search_year_safe'";
}

$sql .= " ORDER BY ph.created_at DESC";

$result = mysqli_query($conn, $sql);
if (!$result) {
    die("❌ SQL Error (Payment History): " . mysqli_error($conn));
}

// ✅ Generate Year Options (last 5 years + current + next year)
$current_year = date('Y');
$years = range($current_year - 5, $current_year + 1);

// ✅ Month Names
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
<link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - <?php echo htmlspecialchars($house_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        main {
            flex: 1;
        }

        .header-bar {
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            color: #333;
            padding: 15px 0;
            margin-bottom: 20px;
        }

        .back-btn {
            width: 50px;
            height: 50px;
            background: #ffd700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            margin-right: 15px;
            flex-shrink: 0;
            font-size: 28px;
            font-weight: bold;
            color: #000;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
            border: none;
        }

        .back-btn:hover {
            background: #ffed4e;
            transform: scale(1.12);
            box-shadow: 0 6px 16px rgba(255, 215, 0, 0.4);
            color: #000;
            text-decoration: none;
        }
        
        .back-btn i.fa-arrow-left {
            font-size: 24px;
            font-weight: 900;
        }

        .header-title {
            font-size: 1.5rem;
            margin-bottom: 0;
            font-weight: bold;
        }

        .card {
            background-color: #fff;
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        h3 {
            color: #000;
            font-weight: bold;
        }

        .btn-primary {
            background-color: #FFD700;
            border: none;
            color: #000;
            font-weight: bold;
        }

        .btn-primary:hover {
            background-color: #000;
            color: #FFD700;
        }

        .btn-secondary {
            background-color: #fff;
            border: 2px solid #FFD700;
            color: #000;
            font-weight: bold;
        }

        .btn-secondary:hover {
            background-color: #FFD700;
            color: #000;
        }

        .table {
            background-color: #fff;
            color: #000;
            margin-bottom: 0;
        }

        .table thead {
            background-color: #f8f9fa;
            color: #000;
        }

        .table thead th {
            border: none;
            padding: 15px;
            font-weight: 600;
            font-size: 14px;
        }

        .table tbody td {
            padding: 15px;
            border-top: 1px solid #e9ecef;
            vertical-align: middle;
            font-size: 14px;
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .badge {
            font-size: 0.9rem;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
        }

        .alert-secondary {
            background-color: #FFD700;
            color: #000;
            font-weight: bold;
        }

        .alert-info {
            background-color: #ffd700;
            color: #000;
            font-weight: bold;
            border: none;
        }

        .search-box {
            background-color: #fff;
            border: 2px solid #FFD700;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .form-label {
            font-weight: bold;
            color: #000;
        }

        .form-select, .form-control {
            border: 2px solid #FFD700;
        }

        .form-select:focus, .form-control:focus {
            border-color: #000;
            box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
        }

        .footer {
            background-color: #111;
            color: #fff;
            padding: 10px 0;
            margin-top: 40px;
            text-align: center;
        }

        .footer p {
            margin: 6px 0;
            font-size: 13px;
        }

        .footer a {
            color: #ffd700;
            text-decoration: none;
            font-weight: 600;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        .footer-links {
            margin-top: 6px;
        }

        .footer-links a {
            margin: 0 10px;
        }

        .profile-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #ffd700;
        }

        .profile-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #333;
            font-size: 16px;
        }

        @media (max-width: 768px) {
            .header-bar {
                padding: 12px 0;
                margin-bottom: 15px;
            }

            .header-title {
                font-size: 1.2rem;
            }

            .back-btn {
                width: 45px;
                height: 45px;
                margin-right: 10px;
                font-size: 24px;
            }
            
            .back-btn i.fa-arrow-left {
                font-size: 20px;
            }

            .search-box {
                padding: 15px;
            }

            .table {
                font-size: 13px;
            }

            .table thead th,
            .table tbody td {
                padding: 10px 8px;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding-left: 10px;
                padding-right: 10px;
            }

            .header-title {
                font-size: 1rem;
            }

            .btn-primary,
            .btn-secondary {
                font-size: 13px;
                padding: 8px 15px;
            }
        }
    </style>
</head>

<body>

<div class="header-bar">
    <div class="container">
        <div class="d-flex align-items-center">
            <a href="owner_dashboard.php?house_id=<?php echo $house_id; ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h2 class="header-title">List of Payment History</h2>
        </div>
    </div>
</div>

<main>
    <div class="container my-4">
        <div class="card shadow">
            <div class="card-body">
                <h3 class="text-center mb-4">💰 Payment History - <?php echo htmlspecialchars($house_name); ?></h3>

                <!-- 🔍 Search Filter Section -->
                <div class="search-box">
                    <form method="GET" action="" class="row g-3 align-items-end">
                        <input type="hidden" name="house_id" value="<?php echo $house_id; ?>">
                        
                        <div class="col-md-4">
                            <label for="search_month" class="form-label">📅 Select Month</label>
                            <select class="form-select" id="search_month" name="search_month">
                                <option value="">All Months</option>
                                <?php foreach ($months as $num => $name): ?>
                                    <option value="<?php echo $num; ?>" 
                                        <?php echo ($search_month == $num) ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="search_year" class="form-label">📅 Select Year</label>
                            <select class="form-select" id="search_year" name="search_year">
                                <option value="">All Years</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year; ?>" 
                                        <?php echo ($search_year == $year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">🔍 Search</button>
                        </div>
                        
                        <div class="col-md-2">
                            <a href="?house_id=<?php echo $house_id; ?>" class="btn btn-secondary w-100">
                                🔄 Clear
                            </a>
                        </div>
                    </form>
                </div>

                <?php if (!empty($search_month) || !empty($search_year)): ?>
                    <div class="alert alert-info text-center">
                        🔎 Showing results for 
                        <?php if (!empty($search_month)): ?>
                            <strong><?php echo $months[$search_month]; ?></strong>
                        <?php endif; ?>
                        <?php if (!empty($search_year)): ?>
                            <strong><?php echo $search_year; ?></strong>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (mysqli_num_rows($result) == 0): ?>
                    <div class="alert alert-secondary text-center">
                        🔭 No payment records found<?php echo (!empty($search_month) || !empty($search_year)) ? ' for the selected period' : ''; ?>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover text-center align-middle">
                            <thead>
                                <tr>
                                    <th>Profile</th>
                                    <th>Renter's Name</th>
                                    <th>Email</th>
                                    <th>Contact No.</th>
                                    <th>Month</th>
                                    <th>Payment Date</th>
                                    <th>Next Due</th>
                                    <th>Payment Amount</th>
                                    <th>Status</th>
                                    <th>Recorded At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($row['profile_img']) && file_exists('../' . $row['profile_img'])): ?>
                                                <img src="../<?php echo htmlspecialchars($row['profile_img']); ?>" alt="Profile" class="profile-img">
                                            <?php else: ?>
                                                <div class="profile-placeholder">
                                                    <?php echo strtoupper(substr($row['tenant_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($row['tenant_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['payment_month']); ?></td>
                                        <td><?php echo $row['payment_date'] ? date("M d, Y", strtotime($row['payment_date'])) : "—"; ?></td>
                                        <td><?php echo $row['next_due_date'] ? date("M d, Y", strtotime($row['next_due_date'])) : "—"; ?></td>
                           <td style="color: #ffc107;"> <strong>₱<?php echo htmlspecialchars($row['room_price']); ?></strong>
</td>
                                        <td>
                                            <?php if ($row['status'] == "paid"): ?>
                                                <span class="badge bg-success">PAID</span>
                                            <?php elseif ($row['status'] == "unpaid"): ?>
                                                <span class="badge bg-danger">UNPAID</span>
                                            <?php elseif ($row['status'] == "near_due"): ?>
                                                <span class="badge bg-warning text-dark">NEAR DUE</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php echo strtoupper($row['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date("M d, Y H:i", strtotime($row['created_at'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<footer class="footer">
    <div class="container">
        <p style="margin-top: 8px; font-size: 13px;">StayFinder: Boarding Locator and Management System</p>
        <div class="footer-links">
            <a href="../terms.php" target="_blank" rel="noopener">Terms &amp; Conditions</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php mysqli_close($conn); ?>

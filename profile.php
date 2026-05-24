<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment variables
$envPath = dirname(__FILE__) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Check if user is logged in
if (!isset($_SESSION['email'])) {
    header("Location: auseregisterlogform/loginseeker.php");
    exit();
}

require_once 'connectiondatabase/main_connection.php';

$session_email = $_SESSION['email'];

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $age = trim($_POST['age'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $update_sql = "UPDATE registerusers SET age = ?, gender = ?, address = ? WHERE email = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ssss", $age, $gender, $address, $session_email);
    
    if ($update_stmt->execute()) {
        $_SESSION['profile_updated'] = true;
        header("Location: profile.php");
        exit();
    }
    $update_stmt->close();
}

// Fetch user information
$user_sql = "SELECT id, fullname, email, profile_img, age, gender, address, phone FROM registerusers WHERE email = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("s", $session_email);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$user_stmt->close();

if (!$user) {
    header("Location: auseregisterlogform/loginseeker.php");
    exit();
}

$user_id = $user['id'];
$user_name = $user['fullname'] ?? 'User';
$user_profile_img = $user['profile_img'] ?? null;
$update_success = isset($_SESSION['profile_updated']);
if ($update_success) {
    unset($_SESSION['profile_updated']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - StayFinder</title>
    <link rel="icon" href="img/stayfinder.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #ffffff;
            min-height: 100vh;
            padding: 20px;
        }

        .profile-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            border-top: 5px solid #FFD700;
        }

        .profile-header {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            padding: 40px 20px;
            text-align: center;
            color: #000;
        }

        .profile-header h1 {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .profile-header p {
            font-size: 14px;
            opacity: 0.8;
        }

        .profile-avatar-section {
            text-align: center;
            padding: 30px 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }

        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #FFD700;
            box-shadow: 0 5px 20px rgba(255, 215, 0, 0.3);
            margin-bottom: 15px;
        }

        .profile-avatar-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 60px;
            margin: 0 auto 15px;
            border: 5px solid #FFD700;
        }

        .avatar-name {
            font-size: 22px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }

        .avatar-email {
            font-size: 14px;
            color: #666;
        }

        .profile-content {
            padding: 40px;
        }

        .profile-section {
            margin-bottom: 35px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #FFD700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #FFD700;
            font-size: 20px;
        }

        .profile-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .profile-info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #555;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-label i {
            color: #FFD700;
            width: 20px;
        }

        .info-value {
            color: #333;
            font-weight: 500;
            text-align: right;
            max-width: 50%;
            word-break: break-word;
        }

        .profile-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #dee2e6;
        }

        .action-btn {
            flex: 1;
            padding: 14px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-back {
            background: #FFD700;
            color: #000;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            padding: 0;
            position: fixed;
            top: 30px;
            left: 30px;
            flex: none;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
        }

        .btn-back:hover {
            background: #FFA500;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(255, 215, 0, 0.4);
        }

        .btn-back i {
            font-size: 24px;
        }

        .btn-edit {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #000;
            font-weight: 700;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, #FFA500, #FF8C00);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 215, 0, 0.3);
        }

        .btn-save {
            background: linear-gradient(135deg, #27ae60, #229954);
            color: white;
            font-weight: 700;
        }

        .btn-save:hover {
            background: linear-gradient(135deg, #229954, #1e8449);
            transform: translateY(-2px);
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .btn-cancel:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .no-data {
            color: #999;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }

        /* Edit mode styles */
        .edit-input {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #FFD700;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .edit-input:focus {
            outline: none;
            border-color: #FFA500;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
        }

        .edit-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #FFD700;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .edit-select:focus {
            outline: none;
            border-color: #FFA500;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
        }

        .profile-info-item.edit-mode {
            padding: 20px 0;
            flex-direction: column;
            align-items: flex-start;
        }

        .profile-info-item.edit-mode .info-label {
            margin-bottom: 10px;
        }

        .profile-info-item.edit-mode .edit-input,
        .profile-info-item.edit-mode .edit-select {
            width: 100%;
            max-width: 400px;
        }

        .profile-actions.edit-mode {
            flex-direction: row;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            border: 2px solid #c3e6cb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-message i {
            color: #28a745;
        }

        @media (max-width: 768px) {
            .profile-container {
                margin: 0;
                border-radius: 10px;
            }

            .profile-header h1 {
                font-size: 22px;
            }

            .profile-content {
                padding: 20px;
            }

            .profile-avatar {
                width: 120px;
                height: 120px;
            }

            .profile-avatar-placeholder {
                width: 120px;
                height: 120px;
                font-size: 50px;
            }

            .profile-info-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .info-value {
                text-align: left;
                max-width: 100%;
            }

            .profile-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <button class="action-btn btn-back" onclick="window.history.back()" title="Go Back">
        <i class="fas fa-arrow-left"></i>
    </button>

    <div class="profile-container">
        <!-- Header -->
        <div class="profile-header">
            <h1><i class="fas fa-user-circle"></i> My Profile</h1>
        </div>

        <!-- Avatar Section -->
        <div class="profile-avatar-section">
            <?php if (!empty($user_profile_img) && file_exists($user_profile_img)): ?>
                <img src="<?php echo htmlspecialchars($user_profile_img); ?>" alt="Profile" class="profile-avatar">
            <?php else: ?>
                <div class="profile-avatar-placeholder">
                    <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                </div>
            <?php endif; ?>
            <div class="avatar-name"><?php echo htmlspecialchars($user_name); ?></div>
            <div class="avatar-email"><?php echo htmlspecialchars($session_email); ?></div>
        </div>

        <!-- Profile Content -->
        <div class="profile-content">
            <?php if ($update_success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <span>Your profile has been updated successfully!</span>
                </div>
            <?php endif; ?>

            <!-- Personal Information Section -->
            <div class="profile-section">
                <div class="section-title">
                    <i class="fas fa-id-card"></i>
                    Personal Information
                </div>
                
                <div class="profile-info-item">
                    <span class="info-label">
                        <i class="fas fa-user"></i>
                        Full Name
                    </span>
                    <span class="info-value"><?php echo htmlspecialchars($user['fullname'] ?? 'N/A'); ?></span>
                </div>

                <div class="profile-info-item">
                    <span class="info-label">
                        <i class="fas fa-envelope"></i>
                        Email Address
                    </span>
                    <span class="info-value"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
                </div>

                <div class="profile-info-item">
                    <span class="info-label">
                        <i class="fas fa-phone"></i>
                        Phone Number
                    </span>
                    <span class="info-value"><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></span>
                </div>
            </div>

            <!-- Additional Information Section - EDITABLE -->
            <div class="profile-section" style="display: none;">
                <div class="section-title">
                    <i class="fas fa-info-circle"></i>
                    Additional Information
                    <button type="button" class="btn btn-sm btn-link" id="editBtn" onclick="toggleEditMode()">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                </div>

                <form id="profileForm" method="POST" style="display: none;">
                    <input type="hidden" name="update_profile" value="1">

                    <div class="profile-info-item edit-mode">
                        <span class="info-label">
                            <i class="fas fa-birthday-cake"></i>
                            Age
                        </span>
                        <input type="number" name="age" id="ageInput" class="edit-input" placeholder="Enter your age" min="0" max="120" value="<?php echo htmlspecialchars($user['age'] ?? ''); ?>">
                    </div>

                    <div class="profile-info-item edit-mode">
                        <span class="info-label">
                            <i class="fas fa-venus-mars"></i>
                            Gender
                        </span>
                        <select name="gender" id="genderInput" class="edit-select">
                            <option value="">Select Gender</option>
                            <option value="male" <?php echo ($user['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($user['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($user['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="profile-info-item edit-mode">
                        <span class="info-label">
                            <i class="fas fa-map-marker-alt"></i>
                            Address
                        </span>
                        <input type="text" name="address" id="addressInput" class="edit-input" placeholder="Enter your address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                    </div>

                    <div class="profile-actions edit-mode" style="margin-top: 20px;">
                        <button type="submit" class="action-btn btn-save">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <button type="button" class="action-btn btn-cancel" onclick="toggleEditMode()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>

                <div id="viewMode">
                    <div class="profile-info-item">
                        <span class="info-label">
                            <i class="fas fa-birthday-cake"></i>
                            Age
                        </span>
                        <span class="info-value"><?php echo htmlspecialchars($user['age'] ?? 'N/A'); ?></span>
                    </div>

                    <div class="profile-info-item">
                        <span class="info-label">
                            <i class="fas fa-venus-mars"></i>
                            Gender
                        </span>
                        <span class="info-value">
                            <?php 
                            $gender = $user['gender'] ?? null;
                            if ($gender) {
                                echo htmlspecialchars(ucfirst($gender));
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </span>
                    </div>

                    <div class="profile-info-item">
                        <span class="info-label">
                            <i class="fas fa-map-marker-alt"></i>
                            Address
                        </span>
                        <span class="info-value"><?php echo htmlspecialchars($user['address'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'auseregisterlogform/loginseeker.php?logout=1';
            }
        }

        function toggleEditMode() {
            const form = document.getElementById('profileForm');
            const viewMode = document.getElementById('viewMode');
            const editBtn = document.getElementById('editBtn');
            
            if (form.style.display === 'none') {
                // Switch to edit mode
                form.style.display = 'block';
                viewMode.style.display = 'none';
                editBtn.innerHTML = '<i class="fas fa-check"></i> Editing...';
                editBtn.disabled = true;
            } else {
                // Switch back to view mode
                form.style.display = 'none';
                viewMode.style.display = 'block';
                editBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
                editBtn.disabled = false;
            }
        }

        // Auto-hide success message after 5 seconds
        window.addEventListener('load', function() {
            const successMsg = document.querySelector('.success-message');
            if (successMsg) {
                setTimeout(() => {
                    successMsg.style.display = 'none';
                }, 5000);
            }
        });
    </script>
</body>
</html>

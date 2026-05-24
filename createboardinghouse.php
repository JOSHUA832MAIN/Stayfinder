<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$envPath = dirname(__DIR__, 2) . '/.env';

if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
} else {
    die("❌ Missing .env file at: " . $envPath);
}
if (!isset($_SESSION['owner_logged_in'])) {
    header('Location: ../owneregister/loginowner.php');
    exit();
}

require_once __DIR__ . '/../connectiondatabase/main_connection.php';

if (!$conn) {
    die("Database connection failed.");
}

if (!isset($_SESSION['owner_id'])) {
    echo "<script>alert('Session expired. Please login again.'); window.location.href='../owneregister/loginowner.php';</script>";
    exit();
}

$stmt = $conn->prepare("SELECT * FROM ownerregister WHERE user_id = ?");
if (!$stmt) {
    die("Prepare failed: " . $conn->connect_error);
}
$stmt->bind_param("i", $_SESSION['owner_id']);
$stmt->execute();
$result = $stmt->get_result();
$owner = $result->fetch_assoc();

if (!$owner) {
    echo "<script>alert('Owner not found. Please login again.'); window.location.href='../owneregister/loginowner.php';</script>";
    exit();
}

$owner_email = $owner['user_email'];

$houses_stmt = $conn->prepare("SELECT * FROM boarding_houses WHERE description LIKE ? ORDER BY created_at DESC");
if (!$houses_stmt) {
    die("Prepare failed: " . $conn->connect_error);
}
$email_filter = "%[OWNER:" . $owner_email . "]%";
$houses_stmt->bind_param("s", $email_filter);
$houses_stmt->execute();
$houses = $houses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['house_name'])) {

    $required_fields = [
        'custom_id',
        'house_name',
        'location',
        'latitude',
        'longitude',
        'description',
        'owner_email',
        'owner_phone',
        'business_permit_number'
    ];

    $missing_fields = [];

    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            $missing_fields[] = $field;
        }
    }

    if (!empty($missing_fields)) {
        echo "<script>alert('Missing required fields: " . implode(', ', $missing_fields) . "');</script>";
    } else {
        $custom_id = $_POST['custom_id'];
        $id_check = $conn->prepare("SELECT id FROM boarding_houses WHERE id = ?");
        if (!$id_check) {
            die("Prepare failed: " . $conn->connect_error);
        }
        $id_check->bind_param("i", $custom_id);
        $id_check->execute();
        $id_result = $id_check->get_result();

        if ($id_result->num_rows > 0) {
            echo "<script>alert('ID $custom_id already exists! Please refresh the form.');</script>";
        } else {

            $front_image = '';
            $side_image = '';
            $back_image = '';
            $business_permit_image = '';

            $upload_dir = "../uploads/boarding_houses/";
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            if (isset($_FILES['side_image']) && $_FILES['side_image']['error'] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['side_image']['name'], PATHINFO_EXTENSION);
                $new_filename = time() . "_side." . $file_extension;
                $target_path = $upload_dir . $new_filename;
                if (move_uploaded_file($_FILES['side_image']['tmp_name'], $target_path)) {
                    $side_image = "uploads/boarding_houses/" . $new_filename;
                }
            }

            if (isset($_FILES['back_image']) && $_FILES['back_image']['error'] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['back_image']['name'], PATHINFO_EXTENSION);
                $new_filename = time() . "_back." . $file_extension;
                $target_path = $upload_dir . $new_filename;
                if (move_uploaded_file($_FILES['back_image']['tmp_name'], $target_path)) {
                    $back_image = "uploads/boarding_houses/" . $new_filename;
                }
            }

            if (isset($_FILES['business_permit_image']) && $_FILES['business_permit_image']['error'] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['business_permit_image']['name'], PATHINFO_EXTENSION);
                $new_filename = time() . "_permit." . $file_extension;
                $target_path = $upload_dir . $new_filename;
                if (move_uploaded_file($_FILES['business_permit_image']['tmp_name'], $target_path)) {
                    $business_permit_image = "uploads/boarding_houses/" . $new_filename;
                }
            }

            $images_array = array_filter([$front_image, $side_image, $back_image]);
            $images_string = implode(',', $images_array);

            $panorama_images = [];
            $panorama_dir = "../uploads/panorama_images/";
            if (!file_exists($panorama_dir)) {
                mkdir($panorama_dir, 0777, true);
            }

            if (isset($_FILES['panorama_images']) && is_array($_FILES['panorama_images']['name'])) {
                $file_count = count($_FILES['panorama_images']['name']);
                for ($i = 0; $i < min(7, $file_count); $i++) {
                    if (
                        isset($_FILES['panorama_images']['name'][$i]) &&
                        $_FILES['panorama_images']['error'][$i] === UPLOAD_ERR_OK &&
                        !empty($_FILES['panorama_images']['name'][$i])
                    ) {
                        $file_extension = pathinfo($_FILES['panorama_images']['name'][$i], PATHINFO_EXTENSION);
                        $new_filename = time() . "_panorama_" . $i . "." . $file_extension;
                        $target_path = $panorama_dir . $new_filename;

                        if (move_uploaded_file($_FILES['panorama_images']['tmp_name'][$i], $target_path)) {
                            $panorama_images[] = "uploads/panorama_images/" . $new_filename;
                        }
                    }
                }
            }

            $panorama_string = implode(',', $panorama_images);

            $owner_id = $_SESSION['owner_id'];
            $house_name = trim($_POST['house_name']);
            $price = "Contact Owner";
            $distance = "Not specified";
            $description_with_owner = trim($_POST['description']) . " [OWNER:" . $owner_email . "]";

            $location = trim($_POST['location']);
            $latitude = floatval($_POST['latitude']);
            $longitude = floatval($_POST['longitude']);
            $owner_email_input = trim($_POST['owner_email']);
            $owner_phone_input = trim($_POST['owner_phone']);
            $business_permit_number = trim($_POST['business_permit_number']);
            $dashboard_password = password_hash($_POST['dashboard_password'], PASSWORD_DEFAULT);

            $insert = $conn->prepare("
                INSERT INTO boarding_houses (
                    id,
                    owner_id,
                    name,
                    price,
                    description,
                    distance,
                    images,
                    panorama_url,
                    full_location,
                    map_lat,
                    map_lng,
                    owner_email,
                    owner_phone,
                    business_permit,
                    business_permit_number,
                    dashboard_password,
                    created_at,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
            ");

            if (!$insert) {
                die('Prepare failed: ' . $conn->error);
            }

            $insert->bind_param(
                "iisssssssddsssss",
                $custom_id,
                $owner_id,
                $house_name,
                $price,
                $description_with_owner,
                $distance,
                $images_string,
                $panorama_string,
                $location,
                $latitude,
                $longitude,
                $owner_email_input,
                $owner_phone_input,
                $business_permit_image,
                $business_permit_number,
                $dashboard_password
            );
           
            if ($insert->execute()) {
                echo "<script>alert('✅ Boarding house registered successfully with ID: $custom_id!'); window.location.href='';</script>";
            } else {
                echo "<script>alert('❌ Error: " . $conn->error . "');</script>";
            }
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../index.php');
    exit();
}

function generateRandomUniqueID($conn) {
    $max_attempts = 100;
    $attempt = 0;

    do {
        $random_id = rand(100000, 999999);
        $check_query = $conn->prepare("SELECT id FROM boarding_houses WHERE id = ?");
        if (!$check_query) {
            die("Prepare failed: " . $conn->connect_error);
        }
        $check_query->bind_param("i", $random_id);
        $check_query->execute();
        $exists = $check_query->get_result()->num_rows > 0;
        $check_query->close();
        $attempt++;

        if ($attempt >= $max_attempts) {
            die("Unable to generate unique ID. Please contact administrator.");
        }
    } while ($exists);

    return $random_id;
}

$generated_random_id = generateRandomUniqueID($conn);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register Boarding House - StayFinder</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <script src="https://aframe.io/releases/1.4.0/aframe.min.js"></script>
  <link rel="stylesheet" href="create.css">
  <style>
/* ===================================
   FIXED HEADER STYLES
   =================================== */
.top-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 70px;
    background: linear-gradient(135deg, #FFD700, #FFA500);
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
  z-index: 10050;
    display: flex;
    align-items: center;
    padding: 0 20px;
}

.header-content {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

.menu-toggle {
    background: white;
    border: 2px solid #B8860B;
    border-radius: 8px;
    padding: 8px 15px;
    cursor: pointer;
    font-size: 20px;
    color: #B8860B;
    transition: all 0.3s ease;
}

.menu-toggle:hover {
    background: #B8860B;
    color: white;
    transform: scale(1.05);
}

.header-title {
    font-size: 24px;
    font-weight: bold;
    color: #000;
    margin: 0;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 15px;
}

.header-user-info {
    display: flex;
    align-items: center;
    gap: 10px;
    background: white;
    padding: 8px 15px;
    border-radius: 25px;
    border: 2px solid #B8860B;
}

.header-user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #B8860B;
}

.header-user-name {
    font-weight: bold;
    color: #B8860B;
    font-size: 14px;
}


.sidebar {
    position: fixed;
    top: 70px; /* Below header */
    left: -300px; /* Hidden by default on mobile */
    width: 300px;
    height: calc(100vh - 70px);
    background: white;
    transition: left 0.3s ease;
    z-index: 1000;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    overflow-y: auto;
}

/* Notification bell inside sidebar (no animation) */
.sidebar .profile-area {
  position: relative;
}
.sidebar .notification-bell {
  position: absolute;
  top: 14px;
  right: 18px;
  background: #ffd700;
  color: #000;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #000;
  text-decoration: none;
}
.sidebar .notification-bell i {
  font-size: 18px;
}

.sidebar.show {
    left: 0;
}

.sidebar-overlay {
    position: fixed;
    top: 70px;
    left: 0;
    width: 100%;
    height: calc(100vh - 70px);
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
    display: none;
}

.sidebar-overlay.show {
    display: block;
}

/* ===================================
   MAIN CONTENT - ADJUSTED FOR HEADER
   =================================== */
.main-content {
    margin-top: 70px; /* Account for fixed header */
    margin-left: 0;
    padding: 20px;
  padding-bottom: 70px; /* Account for fixed footer */
    transition: margin-left 0.3s ease;
    min-height: calc(100vh - 70px);
}

/* ===================================
   MAP STYLES
   =================================== */
#locationMap {
    height: 700px;
    width: 98%;
    max-width: 1600px;
    margin: 0 auto;
    border-radius: 12px;
    border: 3px solid #FFD700;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}

.map-instructions {
    background: linear-gradient(135deg, #FFD700, #FFA500);
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: #000;
    font-weight: 600;
}

.map-instructions i {
    font-size: 1.2rem;
    margin-right: 10px;
}

.coordinates-display {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    border: 2px solid #FFD700;
    margin-top: 15px;
    display: none;
}

.coordinates-display.active {
    display: block;
}

.coordinate-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #dee2e6;
}

.coordinate-item:last-child {
    border-bottom: none;
}

.coordinate-label {
    font-weight: 600;
    color: #B8860B;
}

.coordinate-value {
    font-family: 'Courier New', monospace;
    color: #000;
    background: white;
    padding: 5px 10px;
    border-radius: 4px;
}

.search-control {
    position: absolute;
    top: 10px;
    left: 10px;
    z-index: 1000;
    background: white;
    border-radius: 8px;
    padding: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    max-width: 300px;
}

.search-control input {
    width: 100%;
    border: 2px solid #ddd;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 13px;
}

.search-control input:focus {
    outline: none;
    border-color: #FFD700;
}

/* ===================================
   RESPONSIVE STYLES
   =================================== */
/* Desktop styles (768px and up) */
@media (min-width: 768px) {
    .sidebar {
        left: 0; /* Always visible on desktop */
    }
    
    .main-content {
        margin-left: 300px; /* Account for sidebar */
    }
    
    .sidebar-overlay {
        display: none !important; /* Never show overlay on desktop */
    }
    
    .menu-toggle {
        display: none; /* Hide menu button on desktop */
    }
}

/* Mobile styles (below 768px) */
@media (max-width: 767px) {
    .top-header {
        height: 60px;
        padding: 0 10px;
    }
    
    .header-title {
        font-size: 18px;
    }
    
    .header-user-info {
        padding: 5px 10px;
    }
    
    .header-user-avatar {
        width: 35px;
        height: 35px;
    }
    
    .header-user-name {
        display: none; /* Hide name on very small screens */
    }
    
    .sidebar {
        top: 60px;
        width: 280px;
        left: -280px;
    }
    
    .main-content {
        margin-top: 60px;
        margin-left: 0;
        padding: 10px;
    }
    
    #locationMap {
        height: 400px;
        width: 100% !important;
        border-radius: 8px;
        border: 2px solid #FFD700;
    }
    
    .search-control {
        top: 50px !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        width: 95% !important;
        max-width: none !important;
    }
}

/* Very small screens */
@media (max-width: 480px) {
    .header-title {
        font-size: 16px;
    }
    
    #locationMap {
        height: 350px;
    }
}

/* Large screens */
@media (min-width: 1200px) {
    #locationMap {
        height: 800px;
        max-width: 1800px;
    }
}
/* 6-Digit Input Styling */
.digit-input-container {
    position: relative;
}

.digit-input {
    font-size: 1.5rem !important;
    font-weight: bold !important;
    letter-spacing: 8px !important;
    padding: 15px 20px !important;
    background: #f8f9fa !important;
    border: 3px solid #FFD700 !important;
    border-radius: 12px !important;
    text-align: center !important;
}

.digit-input:focus {
    border-color: #B8860B !important;
    box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25) !important;
    background: white !important;
}

.digit-input::placeholder {
    letter-spacing: normal !important;
    font-size: 1rem !important;
    color: #6c757d !important;
}
  </style>
</head>

<body style="background: #FFFAF0;">

<!-- FIXED HEADER -->
<header class="top-header">
  <div class="header-content">
    <div class="header-left">
      <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
      </button>
      <h1 class="header-title">
        <i class="fas fa-home me-2"></i>Owner Boarding Houses
      </h1>
    </div>
</header>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
  <div class="p-4">
    <div class="text-center mb-4 pb-4 border-bottom profile-area" style="border-color: rgba(51, 51, 51, 0.25) !important;">
      <?php
      $profile_path = '';
      if (!empty($owner['user_profile'])) {
        if (strpos($owner['user_profile'], 'uploads/') === 0) {
          $profile_path = '../owneregister/' . $owner['user_profile'];
        } else {
          $profile_path = $owner['user_profile'];
        }
      }
      ?>

      <?php if (!empty($profile_path) && file_exists($profile_path)): ?>
        <img src="<?= htmlspecialchars($profile_path) ?>" class="rounded-circle border border-3" style="width: 80px; height: 80px; object-fit: cover; border-color: #333 !important;">
      <?php else: ?>
        <div class="bg-white bg-opacity-25 rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width: 80px; height: 80px; background: rgba(51, 51, 51, 0.25) !important;">
          <i class="fas fa-user fa-2x" style="color: #333;"></i>
        </div>
      <?php endif; ?>
      <a href="../notif.php" class="notification-bell" title="Notifications">
        <i class="fas fa-bell"></i>
      </a>
      <h6 class="mt-2" style="color: #333;"><?= htmlspecialchars($owner['user_fullname']) ?></h6>
      <span class="badge bg-<?= $owner['user_status'] === 'approved' ? 'success' : 'warning' ?>"><?= ucfirst($owner['user_status']) ?></span>
    </div>

    <div class="small mb-4" style="color: #333;">
      <br>
      <h6 style="white-space: nowrap; font-weight: bold;">Business Contact Information</h6>
      <div class="mb-2 d-flex align-items-center">
        <i class="fas fa-envelope me-2"></i>
        <span style="color: #FFD700;"><?= htmlspecialchars($owner['user_email']) ?></span>
      </div>
      <div class="mb-2 d-flex align-items-center">
        <i class="fas fa-phone me-2"></i>
        <span style="color: #FFD700;"><?= htmlspecialchars($owner['user_contact']) ?></span>
      </div>
      <br>
    </div>

    <a href="?logout=1" class="btn btn-golden w-100" onclick="return confirm('Logout?')" style="background: #FFD700; color: #000; font-weight: bold; border: 2px solid #B8860B;">
      <i class="fas fa-sign-out-alt me-2"></i>Logout
    </a>
  </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content" id="content">
  <div class="container-fluid">
    <div class="row g-4">
        
    
      
      <!-- EXISTING BOARDING HOUSES FIRST -->
      <?php foreach ($houses as $index => $house): ?>
        <?php
        $clean_description = preg_replace('/\s*\[OWNER:.*?\]/', '', $house['description']);
        $images = !empty($house['images']) ? explode(',', $house['images']) : [];
        $panorama_images = !empty($house['panorama_url']) ? explode(',', $house['panorama_url']) : [];
        ?>
        <div class="col-md-6 col-lg-4">
          <div class="card house-card shadow h-100" style="border: 2px solid #FFD700; border-radius: 12px;">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h5 class="card-title mb-0" style="color: #B8860B; font-weight: bold;"><?= htmlspecialchars($house['name']) ?></h5>
                <span class="badge" style="background: #FFD700; color: #000;">ID: <?= htmlspecialchars($house['id']) ?></span>
              </div>

              <p class="card-text text-muted small mb-2">
                <i class="fas fa-map-marker-alt me-1"></i>
                <?= htmlspecialchars(!empty($house['full_location']) ? $house['full_location'] : 'Location not specified') ?>
              </p>

              <?php if (!empty($images)): ?>
                <div class="image-carousel" id="carousel-<?= $index ?>" style="margin-bottom: 15px;">
                  <div class="carousel-container" style="position: relative; width: 100%; height: 200px; overflow: hidden; border-radius: 8px;">
                    <?php foreach ($images as $img_index => $image): ?>
                      <div class="carousel-slide <?= $img_index === 0 ? 'active' : '' ?>" style="display: <?= $img_index === 0 ? 'block' : 'none' ?>; position: absolute; width: 100%; height: 100%;">
                        <img src="../<?= htmlspecialchars(trim($image)) ?>" alt="Boarding House Image" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.src='../img/default.jpg'">
                      </div>
                    <?php endforeach; ?>

                    <?php if (count($images) > 1): ?>
                      <button class="carousel-nav carousel-prev" onclick="changeSlide(<?= $index ?>, -1)" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); background: rgba(255, 215, 0, 0.8); border: none; border-radius: 50%; width: 40px; height: 40px; cursor: pointer;">
                        <i class="fas fa-chevron-left" style="color: #000;"></i>
                      </button>
                      <button class="carousel-nav carousel-next" onclick="changeSlide(<?= $index ?>, 1)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: rgba(255, 215, 0, 0.8); border: none; border-radius: 50%; width: 40px; height: 40px; cursor: pointer;">
                        <i class="fas fa-chevron-right" style="color: #000;"></i>
                      </button>

                      <div class="carousel-indicators" style="position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); display: flex; gap: 5px;">
                        <?php for ($i = 0; $i < count($images); $i++): ?>
                          <div class="carousel-dot <?= $i === 0 ? 'active' : '' ?>" onclick="goToSlide(<?= $index ?>, <?= $i ?>)" style="width: 10px; height: 10px; border-radius: 50%; background: <?= $i === 0 ? '#FFD700' : 'rgba(255, 255, 255, 0.5)' ?>; cursor: pointer; border: 2px solid #fff;"></div>
                        <?php endfor; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php else: ?>
                <div class="no-images" style="height: 200px; background: #f0f0f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 15px;">
                  <div class="text-center text-muted">
                    <i class="fas fa-image fa-2x mb-2"></i>
                    <p class="mb-0">No images uploaded</p>
                  </div>
                </div>
              <?php endif; ?>

              <h6 style="color: black; font-weight: bold;">Amenities:</h6>
              <p class="card-text small"><?= htmlspecialchars(substr($clean_description, 0, 100)) ?>...</p>

              <div class="mt-auto">
                <?php if ($house['status'] === 'approved'): ?>
                  <a href="ownerlog.php?house_id=<?= $house['id'] ?>" class="btn w-100 text-center px-4" style="background: #FFD700; color: #000; font-weight: bold; border: 2px solid #B8860B;">
                    <i class="fas fa-cog me-2"></i>Manage
                  </a>
                <?php elseif ($house['status'] === 'declined'): ?>
                  <div class="alert alert-danger w-100 text-center mb-0">
                    <i class="fas fa-times-circle me-2"></i>
                    <strong>DECLINED</strong>
                  </div>
                <?php else: ?>
                  <div class="alert alert-warning w-100 text-center mb-0">
                    <i class="fas fa-clock me-2"></i>
                    <strong>PENDING</strong><br>
                    <small>Wait for admin approval</small>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- ADD NEW BOARDING HOUSE CARD - NOW LAST -->
      <div class="col-md-6 col-lg-4">
        <div class="card add-card h-100 d-flex align-items-center justify-content-center" onclick="openForm()" style="border: 3px dashed #FFD700; border-radius: 12px; cursor: pointer; min-height: 300px; background: linear-gradient(135deg, #FFFEF7, #FFF8DC); transition: all 0.3s ease;">
          <div class="text-center p-4">
            <i class="fas fa-plus fa-4x mb-3" style="color: #FFD700;"></i>
            <h5 style="color: #B8860B; font-weight: bold;">Add New Boarding House</h5>
            <p class="text-muted">Click to register a new property</p>
          </div>
        </div>
      </div>

      <?php if (empty($houses)): ?>
        <div class="col-12">
          <div class="text-center py-5">
            <i class="fas fa-home fa-3x text-muted mb-3"></i>
            <h4 class="text-muted">No boarding houses yet</h4>
            <p class="text-muted">Click the "Add New" card to register your first property</p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- 360 PANORAMA VIEWER MODAL -->
<div class="panorama-viewer-modal" id="panoramaViewer" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); z-index: 9999;">
  <div class="panorama-viewer-content" style="width: 100%; height: 100%; position: relative;">
    <div class="panorama-controls" style="position: absolute; top: 20px; left: 50%; transform: translateX(-50%); z-index: 10000; display: flex; gap: 10px;">
      <button onclick="close360Viewer()" style="background: #FFD700; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold;">
        <i class="fas fa-times"></i> Close
      </button>
      <button onclick="resetView()" style="background: #FFD700; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold;">
        <i class="fas fa-home"></i> Reset View
      </button>
      <button onclick="toggleFullscreen()" style="background: #FFD700; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold;">
        <i class="fas fa-expand"></i> Fullscreen
      </button>
    </div>

    <a-scene id="panoramaScene" embedded style="height: 100%; width: 100%;" vr-mode-ui="enabled: false">
      <a-sky id="panoramaSky" src="/placeholder.svg" rotation="0 0 0"></a-sky>
      <a-camera look-controls wasd-controls position="0 0 0">
        <a-cursor color="white" opacity="0.5"></a-cursor>
      </a-camera>
    </a-scene>

    <div class="panorama-navigation" id="panoramaNavigation" style="position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 10000; display: flex; gap: 10px;"></div>
  </div>
</div>

<!-- REGISTRATION FORM MODAL -->
<div class="modal fade" id="formModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="background: #FFFEF7; border: 3px solid #FFD700;">
      <div class="modal-header text-center py-4" style="background: linear-gradient(135deg, #FFD700, #FFA500);">
        <h1 style="color: #000; font-weight: bold;"><i class="fas fa-home me-3"></i>Register Your Boarding House</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-3 p-md-5">
        <form method="post" enctype="multipart/form-data" id="form">

          <h4 class="mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h4>
          <div class="row mb-4">
            <div class="col-md-3 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">
                Boarding House ID
                <span class="badge bg-success">Auto-Generated</span>
              </label>
              <input
                type="number"
                name="custom_id"
                id="custom_id"
                class="form-control form-control-lg"
                required
                value="<?= $generated_random_id ?>"
                readonly
                style="background-color: #f0f0f0; font-weight: bold; color: #B8860B; cursor: not-allowed;"
              >
              <small class="text-muted">
                <i class="fas fa-check-circle text-success"></i>
                Random ID: <strong style="color: #B8860B;"><?= $generated_random_id ?></strong>
              </small>
              <br>
              <button type="button" class="btn btn-sm btn-warning mt-2" onclick="generateNewID()">
                <i class="fas fa-sync-alt me-1"></i> Generate New ID
              </button>
            </div>

            <div class="col-md-3 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Boarding House Name <span class="badge bg-danger">Required</span></label>
              <input type="text" name="house_name" class="form-control form-control-lg" required placeholder="Enter boarding house name">
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Owner <span class="badge bg-success">Auto-filled</span></label>
              <input type="text" value="<?= htmlspecialchars($owner['user_fullname']) ?>" class="form-control form-control-lg" readonly>
              <small class="text-muted">Contact: <?= htmlspecialchars($owner['user_contact']) ?></small>
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Owner Email <span class="badge bg-danger">Required</span></label>
              <input type="email" name="owner_email" class="form-control form-control-lg" required placeholder="Enter owner email" value="<?= htmlspecialchars($owner['user_email']) ?>">
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Owner Phone <span class="badge bg-danger">Required</span></label>
              <input type="tel" name="owner_phone" class="form-control form-control-lg" required placeholder="Enter owner phone" value="<?= htmlspecialchars($owner['user_contact']) ?>">
            </div>
          </div>

          <h4 class="mb-3"><i class="fas fa-map-marker-alt me-2"></i>Location Information</h4>
          <div class="row mb-4">
            <div class="col-md-12 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Complete Location <span class="badge bg-danger">Required</span></label>
              <textarea name="location" class="form-control" rows="3" required placeholder="Complete address/location"></textarea>
            </div>
          </div>

          <div class="row mb-4">
            <div class="col-12">
              <div class="map-instructions">
                <i class="fas fa-info-circle"></i>
                <strong>Automatic Location Detection:</strong> We'll try to detect your location automatically. 
                If not detected, click the "📍 Use My Location" button or manually click on the map to set your boarding house location.
              </div>

              <div style="position: relative; width: 100%; overflow: hidden;">
                <div id="locationMap"></div>
              </div>
              
              <div id="coordinatesDisplay" class="coordinates-display">
                <h6 class="mb-3" style="color: #B8860B; font-weight: bold;">
                  <i class="fas fa-check-circle text-success me-2"></i>Location Selected
                </h6>
                <div class="coordinate-item">
                  <span class="coordinate-label">
                    <i class="fas fa-map-pin me-2"></i>Latitude:
                  </span>
                  <span class="coordinate-value" id="latitudeDisplay">-</span>
                </div>
                <div class="coordinate-item">
                  <span class="coordinate-label">
                    <i class="fas fa-map-pin me-2"></i>Longitude:
                  </span>
                  <span class="coordinate-value" id="longitudeDisplay">-</span>
                </div>
                <button type="button" class="btn btn-sm btn-warning mt-3" onclick="clearMapLocation()">
                  <i class="fas fa-times me-2"></i>Clear Location
                </button>
              </div>

              <input type="hidden" name="latitude" id="latitude" required>
              <input type="hidden" name="longitude" id="longitude" required>
            </div>
          </div>

          <h4 class="mb-3"><i class="fas fa-certificate me-2"></i>Business Permit Information</h4>
          <div class="row mb-4">
            <div class="col-md-6 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Business Permit Number <span class="badge bg-danger">Required</span></label>
              <input type="text" name="business_permit_number" class="form-control form-control-lg" required placeholder="Enter your business permit number">
              <small class="text-muted">Example: BP-2025-001234</small>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Business Permit Image <span class="badge bg-danger">Required</span></label>
              <div class="upload-area small" onclick="document.getElementById('business_permit_image').click()" style="border: 2px dashed #FFD700; padding: 30px; text-align: center; border-radius: 8px; cursor: pointer; background: #FFFEF7;">
                <i class="fas fa-file-pdf fa-2x mb-2" style="color: #FFD700;"></i>
                <p class="mb-0 small">Click to upload permit image</p>
              </div>
              <input type="file" id="business_permit_image" name="business_permit_image" accept="image/*,.pdf" style="display: none;" onchange="previewImage(this, 'permit_preview')" required>
              <img id="permit_preview" class="image-preview" style="display: none; max-width: 100%; margin-top: 10px; border-radius: 8px;">
            </div>
          </div>

          <h4 class="mb-3"><i class="fas fa-images me-2"></i>Property Images</h4>
          <div class="row mb-4">
            <div class="col-md-4 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Front Image <span class="badge bg-success">Optional</span></label>
              <div class="upload-area small" onclick="document.getElementById('front_image').click()" style="border: 2px dashed #FFD700; padding: 30px; text-align: center; border-radius: 8px; cursor: pointer; background: #FFFEF7;">
                <i class="fas fa-camera fa-2x mb-2" style="color: #FFD700;"></i>
                <p class="mb-0 small">Click to upload front view</p>
              </div>
              <input type="file" id="front_image" name="front_image" accept="image/*" style="display: none;" onchange="previewImage(this, 'front_preview')">
              <img id="front_preview" class="image-preview" style="display: none; max-width: 100%; margin-top: 10px; border-radius: 8px;">
            </div>

            <div class="col-md-4 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Side Image <span class="badge bg-success">Optional</span></label>
              <div class="upload-area small" onclick="document.getElementById('side_image').click()" style="border: 2px dashed #FFD700; padding: 30px; text-align: center; border-radius: 8px; cursor: pointer; background: #FFFEF7;">
                <i class="fas fa-camera fa-2x mb-2" style="color: #FFD700;"></i>
                <p class="mb-0 small">Click to upload side view</p>
              </div>
              <input type="file" id="side_image" name="side_image" accept="image/*" style="display: none;" onchange="previewImage(this, 'side_preview')">
              <img id="side_preview" class="image-preview" style="display: none; max-width: 100%; margin-top: 10px; border-radius: 8px;">
            </div>

            <div class="col-md-4 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">Other Image<span class="badge bg-success">Optional</span></label>
              <div class="upload-area small" onclick="document.getElementById('back_image').click()" style="border: 2px dashed #FFD700; padding: 30px; text-align: center; border-radius: 8px; cursor: pointer; background: #FFFEF7;">
                <i class="fas fa-camera fa-2x mb-2" style="color: #FFD700;"></i>
                <p class="mb-0 small">Click to upload back view</p>
              </div>
              <input type="file" id="back_image" name="back_image" accept="image/*" style="display: none;" onchange="previewImage(this, 'back_preview')">
              <img id="back_preview" class="image-preview" style="display: none; max-width: 100%; margin-top: 10px; border-radius: 8px;">
            </div>
          </div>

          <div class="row mb-4">
            <div class="col-md-12 mb-3">
              <label class="form-label fw-bold" style="color: #B8860B;">360° Panorama Images <span class="badge bg-success">Optional</span></label>
              <div class="panorama-upload-container" onclick="document.getElementById('panorama_images').click()" style="border: 3px dashed #FFD700; padding: 40px; text-align: center; border-radius: 12px; cursor: pointer; background: linear-gradient(135deg, #FFFEF7, #FFF8DC);">
                <div class="text-center">
                  <i class="fas fa-globe fa-4x mb-3" style="color: #FFD700;"></i>
                  <h5 style="color: #FFD700;">Click to upload 360° Panoramic Images</h5>
                  <p class="text-muted mb-2">Upload up to 7 equirectangular spherical panorama images</p>
                  <p class="small text-muted">
                    <strong>Requirements:</strong><br>
                    • Better cameras capture more detail, resulting in better images<br>
                    • JPG, PNG, JPEG formats supported<br>

                  </p>
                  <span class="panorama-counter" id="panoramaCounter" style="display: inline-block; background: #FFD700; padding: 8px 15px; border-radius: 20px; font-weight: bold; color: #000;">0 / 7 images selected</span>
                </div>
              </div>
              <input type="file" id="panorama_images" name="panorama_images[]" accept="image/*" multiple style="display: none;" onchange="handlePanoramaFiles(this)">
              <div id="panorama_preview_grid" class="panorama-preview-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 15px;"></div>
            </div>
          </div>

  <h4 class="mb-3"><i class="fas fa-edit me-2"></i>Property Description</h4>
<div class="row mb-4">
    <div class="col-md-12 mb-3">
        <label class="form-label fw-bold" style="color: #B8860B;">Description <span class="badge bg-danger">Required</span></label>
        <textarea name="description" class="form-control" rows="6" placeholder="Describe your boarding house Rules or Policy" required></textarea>
    </div>
</div>

<h4 class="mb-3"><i class="fas fa-lock me-2"></i>Dashboard Access Code</h4>
<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <label class="form-label fw-bold" style="color: #B8860B;">6-Digit Access Code <span class="badge bg-danger">Required</span></label>
        <div class="digit-input-container">
            <input type="text" 
                   name="dashboard_password" 
                   id="dashboard_password" 
                   class="form-control form-control-lg text-center digit-input" 
                   required 
                   maxlength="6" 
                   pattern="[0-9]{6}"
                   placeholder="Enter 6-digit code"
                   oninput="validateDigitInput(this)">
        </div>
        <small class="text-muted">
            <i class="fas fa-key me-1"></i>
            Enter a 6-digit code to access your boarding house dashboard
        </small>
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label fw-bold" style="color: #B8860B;">Confirm 6-Digit Code <span class="badge bg-danger">Required</span></label>
        <div class="digit-input-container">
            <input type="text" 
                   name="confirm_password" 
                   id="confirm_password" 
                   class="form-control form-control-lg text-center digit-input" 
                   required 
                   maxlength="6" 
                   pattern="[0-9]{6}"
                   placeholder="Confirm 6-digit code"
                   oninput="validateDigitInput(this)">
        </div>
        <small class="text-muted">
            <i class="fas fa-shield-alt me-1"></i>
            Re-enter the 6-digit code to confirm
        </small>
        <div id="passwordMatchMessage" class="mt-1" style="font-size: 0.875rem;"></div>
    </div>
</div>

          <div class="text-center">
            <button type="submit" class="btn btn-lg px-5" id="submitBtn" style="background: linear-gradient(135deg, #FFD700, #FFA500); color: #000; font-weight: bold; border: 3px solid #B8860B; font-size: 18px;">
              <i class="fas fa-home me-2"></i>Register Boarding House
            </button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($env['GOOGLE_MAPS_API_KEY'] ?? ''); ?>&libraries=places&callback=initLocationMap" async defer></script>

<script>
let locationMap, locationMarker, geocoder;

// SIDEBAR TOGGLE FUNCTION
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}
// 6-DIGIT CODE VALIDATION
document.addEventListener('DOMContentLoaded', function() {
    const passwordField = document.getElementById('dashboard_password');
    const confirmPasswordField = document.getElementById('confirm_password');
    const messageDiv = document.getElementById('passwordMatchMessage');
    
    function validateDigitInput(input) {
        // Remove any non-digit characters
        input.value = input.value.replace(/\D/g, '');
        
        // Limit to 6 digits
        if (input.value.length > 6) {
            input.value = input.value.slice(0, 6);
        }
        
        checkCodeMatch();
    }
    
    function checkCodeMatch() {
        const code = passwordField.value;
        const confirmCode = confirmPasswordField.value;
        
        if (confirmCode === '') {
            messageDiv.innerHTML = '';
            messageDiv.className = '';
            return;
        }
        
        if (code === confirmCode) {
            messageDiv.innerHTML = '<i class="fas fa-check-circle text-success me-1"></i> Codes match';
            messageDiv.className = 'text-success';
        } else {
            messageDiv.innerHTML = '<i class="fas fa-times-circle text-danger me-1"></i> Codes do not match';
            messageDiv.className = 'text-danger';
        }
    }
    
    passwordField.addEventListener('input', checkCodeMatch);
    confirmPasswordField.addEventListener('input', checkCodeMatch);
    
    document.getElementById('form').addEventListener('submit', function(e) {
        const code = passwordField.value;
        const confirmCode = confirmPasswordField.value;
        
        if (code.length !== 6) {
            e.preventDefault();
            alert('Access code must be exactly 6 digits!');
            passwordField.focus();
            return false;
        }
        
        if (code !== confirmCode) {
            e.preventDefault();
            alert('6-digit codes do not match! Please make sure both codes are identical.');
            passwordField.focus();
            return false;
        }
    });
});

// GOOGLE MAPS INITIALIZATION
function initLocationMap() {
    const defaultCenter = { lat: 8.359995345948724, lng: 123.84327331569628 };
    
    locationMap = new google.maps.Map(document.getElementById('locationMap'), {
        center: defaultCenter,
        zoom: 17,
        mapTypeId: google.maps.MapTypeId.HYBRID,
        mapTypeControl: true,
        mapTypeControlOptions: {
            style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
            position: google.maps.ControlPosition.TOP_CENTER,
            mapTypeIds: [
                google.maps.MapTypeId.ROADMAP,
                google.maps.MapTypeId.SATELLITE,
                google.maps.MapTypeId.HYBRID,
                google.maps.MapTypeId.TERRAIN
            ]
        },
        zoomControl: true,
        zoomControlOptions: {
            position: google.maps.ControlPosition.RIGHT_CENTER
        },
        streetViewControl: true,
        fullscreenControl: true
    });

    geocoder = new google.maps.Geocoder();
    addCurrentLocationButton();

    locationMap.addListener('click', function(e) {
        const lat = e.latLng.lat();
        const lng = e.latLng.lng();
        setMapLocation(lat, lng);
    });
}

function addCurrentLocationButton() {
    const locationButton = document.createElement('button');
    locationButton.textContent = '📍 Use My Location';
    locationButton.style.cssText = `
        background-color: #fff;
        border: 2px solid #FFD700;
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        cursor: pointer;
        margin: 10px;
        padding: 10px 15px;
        font-size: 14px;
        font-weight: bold;
        color: #B8860B;
    `;

    locationButton.addEventListener('click', getCurrentLocation);
    locationMap.controls[google.maps.ControlPosition.TOP_RIGHT].push(locationButton);
}

function getCurrentLocation() {
    if (navigator.geolocation) {
        const locationButton = document.querySelector('[style*="Use My Location"]');
        
        if (locationButton) {
            locationButton.textContent = '📍 Getting Location...';
            locationButton.disabled = true;
            locationButton.style.opacity = '0.7';
        }

        navigator.geolocation.getCurrentPosition(
            function(position) {
                const pos = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };

                locationMap.setCenter(pos);
                locationMap.setZoom(19);
                setMapLocation(pos.lat, pos.lng);

                if (locationButton) {
                    locationButton.textContent = '📍 Use My Location';
                    locationButton.disabled = false;
                    locationButton.style.opacity = '1';
                }

                alert('✅ Location set successfully!');
            },
            function(error) {
                if (locationButton) {
                    locationButton.textContent = '📍 Use My Location';
                    locationButton.disabled = false;
                    locationButton.style.opacity = '1';
                }

                alert('Unable to get your location. Please click on the map to set location manually.');
            },
            {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 0
            }
        );
    } else {
        alert('Geolocation not supported by your browser.');
    }
}

function setMapLocation(lat, lng) {
    if (locationMarker) {
        locationMarker.setMap(null);
    }

    locationMarker = new google.maps.Marker({
        position: { lat: lat, lng: lng },
        map: locationMap,
        icon: {
            url: '../img/icons/house_9408891.png',
            scaledSize: new google.maps.Size(50, 50),
            anchor: new google.maps.Point(25, 50)
        },
        title: 'Your Boarding House Location',
        animation: google.maps.Animation.DROP
    });

    document.getElementById('latitude').value = lat.toFixed(6);
    document.getElementById('longitude').value = lng.toFixed(6);
    document.getElementById('latitudeDisplay').textContent = lat.toFixed(6);
    document.getElementById('longitudeDisplay').textContent = lng.toFixed(6);
    document.getElementById('coordinatesDisplay').classList.add('active');

    geocoder.geocode({ location: { lat: lat, lng: lng } }, function(results, status) {
        if (status === 'OK' && results[0]) {
            document.querySelector('textarea[name="location"]').value = results[0].formatted_address;
        }
    });
}

function clearMapLocation() {
    if (locationMarker) {
        locationMarker.setMap(null);
        locationMarker = null;
    }

    document.getElementById('latitude').value = '';
    document.getElementById('longitude').value = '';
    document.getElementById('latitudeDisplay').textContent = '-';
    document.getElementById('longitudeDisplay').textContent = '-';
    document.getElementById('coordinatesDisplay').classList.remove('active');
}

function openForm() {
    const modal = new bootstrap.Modal(document.getElementById('formModal'));
    modal.show();

    setTimeout(() => {
        if (locationMap) {
            google.maps.event.trigger(locationMap, 'resize');
        }
    }, 300);
}

function generateNewID() {
    const idField = document.getElementById('custom_id');
    const randomID = Math.floor(Math.random() * (999999 - 100000 + 1)) + 100000;
    idField.value = randomID;

    const smallTag = document.querySelector('#custom_id + small strong');
    if (smallTag) {
        smallTag.textContent = randomID;
    }

    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i> Generated!';
    btn.classList.remove('btn-warning');
    btn.classList.add('btn-success');

    setTimeout(() => {
        btn.innerHTML = originalHTML;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-warning');
    }, 1500);
}

// IMAGE CAROUSEL
let currentSlides = {};

function changeSlide(carouselIndex, direction) {
    const carousel = document.getElementById(`carousel-${carouselIndex}`);
    const slides = carousel.querySelectorAll('.carousel-slide');
    const dots = carousel.querySelectorAll('.carousel-dot');

    if (!currentSlides[carouselIndex]) currentSlides[carouselIndex] = 0;

    slides[currentSlides[carouselIndex]].style.display = 'none';
    if (dots[currentSlides[carouselIndex]]) {
        dots[currentSlides[carouselIndex]].style.background = 'rgba(255, 255, 255, 0.5)';
    }

    currentSlides[carouselIndex] += direction;
    if (currentSlides[carouselIndex] >= slides.length) currentSlides[carouselIndex] = 0;
    if (currentSlides[carouselIndex] < 0) currentSlides[carouselIndex] = slides.length - 1;

    slides[currentSlides[carouselIndex]].style.display = 'block';
    if (dots[currentSlides[carouselIndex]]) {
        dots[currentSlides[carouselIndex]].style.background = '#FFD700';
    }
}

function goToSlide(carouselIndex, slideIndex) {
    const carousel = document.getElementById(`carousel-${carouselIndex}`);
    const slides = carousel.querySelectorAll('.carousel-slide');
    const dots = carousel.querySelectorAll('.carousel-dot');

    if (currentSlides[carouselIndex] !== undefined) {
        slides[currentSlides[carouselIndex]].style.display = 'none';
        if (dots[currentSlides[carouselIndex]]) {
            dots[currentSlides[carouselIndex]].style.background = 'rgba(255, 255, 255, 0.5)';
        }
    }

    currentSlides[carouselIndex] = slideIndex;
    slides[slideIndex].style.display = 'block';
    if (dots[slideIndex]) {
        dots[slideIndex].style.background = '#FFD700';
    }
}

// IMAGE PREVIEW
function previewImage(input, previewId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById(previewId);
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// PANORAMA HANDLING
let selectedPanoramaFiles = [];

function handlePanoramaFiles(input) {
    const files = Array.from(input.files);
    const maxFiles = 7;

    if (files.length > maxFiles) {
        alert(`You can only upload up to ${maxFiles} panorama images.`);
        selectedPanoramaFiles = files.slice(0, maxFiles);
    } else {
        selectedPanoramaFiles = [...selectedPanoramaFiles, ...files].slice(0, maxFiles);
    }

    updatePanoramaPreview();
    updatePanoramaCounter();
    updateFileInput();
}

function updatePanoramaPreview() {
    const previewGrid = document.getElementById('panorama_preview_grid');
    previewGrid.innerHTML = '';

    selectedPanoramaFiles.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewItem = document.createElement('div');
            previewItem.className = 'panorama-preview-item';
            previewItem.style.cssText = 'position: relative; border-radius: 8px; overflow: hidden; border: 2px solid #FFD700;';
            previewItem.innerHTML = `
                <img src="${e.target.result}" alt="Panorama ${index + 1}" style="width: 100%; height: 150px; object-fit: cover;">
                <button type="button" class="remove-btn" onclick="removePanoramaImage(${index})" style="position: absolute; top: 5px; right: 5px; background: #ff4444; color: white; border: none; border-radius: 50%; width: 30px; height: 30px; cursor: pointer; font-weight: bold;">
                    <i class="fas fa-times"></i>
                </button>
            `;
            previewGrid.appendChild(previewItem);
        };
        reader.readAsDataURL(file);
    });
}

function removePanoramaImage(index) {
    selectedPanoramaFiles.splice(index, 1);
    updatePanoramaPreview();
    updatePanoramaCounter();
    updateFileInput();
}

function updatePanoramaCounter() {
    const counter = document.getElementById('panoramaCounter');
    counter.textContent = `${selectedPanoramaFiles.length} / 7 images selected`;
}

function updateFileInput() {
    const input = document.getElementById('panorama_images');
    const dt = new DataTransfer();
    selectedPanoramaFiles.forEach(file => dt.items.add(file));
    input.files = dt.files;
}

// 360 VIEWER FUNCTIONS
let currentPanoramaImages = [];
let currentPanoramaIndex = 0;

function open360Viewer(panoramaImages) {
    currentPanoramaImages = panoramaImages;
    currentPanoramaIndex = 0;

    const viewer = document.getElementById('panoramaViewer');
    const navigation = document.getElementById('panoramaNavigation');

    navigation.innerHTML = '';
    if (panoramaImages.length > 1) {
        panoramaImages.forEach((image, index) => {
            const btn = document.createElement('button');
            btn.className = `panorama-nav-btn ${index === 0 ? 'active' : ''}`;
            btn.textContent = `View ${index + 1}`;
            btn.style.cssText = 'background: #FFD700; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: bold; margin: 0 5px;';
            btn.onclick = () => switchPanorama(index);
            navigation.appendChild(btn);
        });
    }

    viewer.style.display = 'block';

    setTimeout(() => {
        loadPanorama(0);
    }, 200);
}

function switchPanorama(index) {
    currentPanoramaIndex = index;
    loadPanorama(index);

    const navButtons = document.querySelectorAll('.panorama-nav-btn');
    navButtons.forEach((btn, i) => {
        btn.style.background = i === index ? '#FFA500' : '#FFD700';
    });
}

function loadPanorama(index) {
    const sky = document.getElementById('panoramaSky');
    if (!sky || !currentPanoramaImages[index]) {
        console.error('Sky element or image not found');
        return;
    }

    const imagePath = '../' + currentPanoramaImages[index];

    sky.setAttribute('src', imagePath);
    sky.setAttribute('geometry', {
        primitive: 'sphere',
        radius: 500,
        segmentsWidth: 64,
        segmentsHeight: 32
    });
    sky.setAttribute('material', {
        shader: 'standard',
        side: 'back',
        roughness: 1,
        metalness: 0
    });
    sky.setAttribute('scale', '-1 1 1');
    sky.setAttribute('rotation', '0 0 0');
}

function close360Viewer() {
    document.getElementById('panoramaViewer').style.display = 'none';
}

function resetView() {
    const camera = document.querySelector('a-camera');
    if (camera) {
        camera.setAttribute('rotation', '0 0 0');
        camera.setAttribute('position', '0 0 0');
    }
}

function toggleFullscreen() {
    const viewer = document.getElementById('panoramaViewer');
    if (!document.fullscreenElement) {
        viewer.requestFullscreen().catch(err => {
            console.error('Error attempting to enable fullscreen:', err);
        });
    } else {
        document.exitFullscreen();
    }
}

// FORM VALIDATION
document.getElementById('form').addEventListener('submit', function(e) {
    const latitude = document.getElementById('latitude').value;
    const longitude = document.getElementById('longitude').value;
    const permitImage = document.getElementById('business_permit_image').files[0];

    const requiredFields = ['custom_id', 'house_name', 'location', 'description', 'business_permit_number'];
    let missingFields = [];

    requiredFields.forEach(field => {
        const input = document.querySelector(`[name="${field}"]`);
        if (!input || !input.value.trim()) {
            missingFields.push(field);
        }
    });

    if (!latitude || !longitude) {
        e.preventDefault();
        alert('Please select a location on the map by clicking on it!');
        return;
    }

    if (missingFields.length > 0) {
        e.preventDefault();
        alert('Please fill in all required fields: ' + missingFields.join(', '));
        return;
    }

    if (!permitImage) {
        e.preventDefault();
        alert('Please upload your business permit image!');
        return;
    }

    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Registering...';
    document.getElementById('submitBtn').disabled = true;
});

// CLOSE SIDEBAR WHEN CLICKING OUTSIDE ON MOBILE
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    const overlay = document.getElementById('overlay');
    
    // Check if click is outside sidebar and not on menu toggle
    if (sidebar.classList.contains('show') && 
        !sidebar.contains(event.target) && 
        !menuToggle.contains(event.target)) {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    }
});
</script>

  <footer class="footer" style="position:fixed;left:0;right:0;bottom:0;z-index:10060;background-color:#111;color:#fff;padding:8px 0;text-align:center;">
    <div class="container">
      <div class="row">
        <div class="col-12">
          <p class="mb-0" style="font-size:13px;margin:0;">
            StayFinder: Boarding Locator and Management System
          </p>
          <div style="margin-top:4px;">
            <a href="../terms.php" style="color:#ffd700;text-decoration:none;font-weight:600;font-size:13px;">Terms &amp; Conditions</a>
          </div>
        </div>
      </div>
    </div>
  </footer>

</body>
</html>
<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['email']) || empty($_SESSION['email'])) {
    header('Location: auseregisterlogform/login.php');
    exit();
}

$is_logged_in = true;
$name = $_SESSION['name'] ?? 'User';
$email = $_SESSION['email'];
$profile_img = $_SESSION['profile_img'] ?? '';

require_once __DIR__ . '/connectiondatabase/main_connection.php';


if (!$conn) {
    die("Database connection failed.");
}

// Handle remove from favorites
if (isset($_POST['action']) && $_POST['action'] === 'remove_favorite') {
    $house_id = intval($_POST['house_id']);
    $stmt = $conn->prepare("DELETE FROM favorites WHERE user_email = ? AND house_id = ?");
    $stmt->bind_param("si", $email, $house_id);
    $stmt->execute();
    $stmt->close();
    header('Location: favorites.php?removed=1');
    exit();
}

// Get user's favorite boarding houses with location from owner_address
$query = "
    SELECT bh.*,
           f.created_at as favorited_at,
           COALESCE(owner.user_address, 'Not specified') as full_location
    FROM boarding_houses bh
    INNER JOIN favorites f ON bh.id = f.house_id
    LEFT JOIN ownerregister owner ON bh.owner_id = owner.user_id
    WHERE f.user_email = ?
    ORDER BY f.created_at DESC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$favorite_houses = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get profile image if not set
if (empty($profile_img)) {
    $stmt = $conn->prepare("SELECT profile_img FROM registerusers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $profile_img = $row['profile_img'];
        $_SESSION['profile_img'] = $profile_img;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorites - Stay Finder</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            /* CHANGED: Background from #FFF8DC to White */
            background: #FFFFFF;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
        }

        /* --- STYLES REMOVED: SIDEBAR STYLES (sideMenu, closeButton, userHeader, profile-section, menuList, etc.) --- */
        /* --- STYLES ADJUSTED: mainWrapper no longer handles sidebar shifting --- */

        .mainWrapper {
            margin-left: 0;
            /* CHANGED: Background from #FFF8DC to White */
            min-height: 100vh;
            background: #FFFFFF;
            /* REMOVED: transition property */
        }
        
        /* REMOVED: .mainWrapper.shifted style */

        .topBar {
            background: #FFFFFF;
            border-bottom: 3px solid #FFD700;
            padding: 20px 30px;
            display: flex;
            align-items: center;
            gap: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        /* FIX: New styles for the back button (yellow circle, black chevron, uses solid color) */
        #openMenu {
            width: 44px;
            height: 44px;
            /* Yellow Circle */
            background: #FFD700; 
            color: #000;
            border-radius: 50%; /* Circle shape */
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2); /* Simple shadow, no gradient */
            text-decoration: none;
            border: none; /* Make it look like a button */
        }

        #openMenu i {
            /* Black < icon (fas fa-chevron-left) */
            color: #000;
            font-size: 18px;
        }
        
        #openMenu:hover {
            background: #FFC107;
            transform: translateY(-1px) scale(1.03);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }
        /* END FIX */

        .titleNgSite {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: #FFD700;
            letter-spacing: -0.5px;
        }

        .pageHeader {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 36px;
            font-weight: 700;
            color: #333;
            margin: 40px 30px 30px;
            position: relative;
        }

        .pageHeader::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 80px;
            height: 4px;
            background: #FFD700;
            border-radius: 2px;
        }

        .pageHeader i {
            color: #FFD700;
            margin-right: 15px;
        }

        .stats-section {
            padding: 0 30px 30px;
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #FFFFFF;
            border: 2px solid #FFD700;
            border-radius: 16px;
            padding: 20px;
            flex: 1;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .stat-number {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #FFD700;
            margin-bottom: 8px;
        }

        .stat-label {
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 14px;
            letter-spacing: 1px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 30px;
            max-width: 500px;
            margin: 0 auto;
            /* Using solid #FFD700 */
            background: #FFD700;
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border: 2px solid #FFC107;
        }

        .empty-state i {
            font-size: 80px;
            /* CHANGED: Icon color for contrast */
            color: #FFF; 
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
             /* CHANGED: Text color for contrast */
            color: #000;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px;
            margin-bottom: 10px;
        }

        .empty-state p {
             /* CHANGED: Text color for contrast */
            color: #333;
            font-size: 16px;
            margin-bottom: 30px;
        }
        
        /* ADJUSTING EMPTY STATE BUTTON FOR CONTRAST */
        .browse-btn {
            display: inline-block;
            padding: 12px 24px;
            background: #FFFFFF; /* White button on yellow background */
            color: #000;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .browse-btn:hover {
            background: #eee;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }


        .favorites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 24px;
            padding: 0 30px 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .favorite-card {
            /* Using solid #FFD700 */
            background: #FFD700;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            /* CHANGED: Border color to a darker yellow for contrast */
            border: 2px solid #FFC107;
            position: relative;
        }

        .favorite-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            border-color: #FFC107;
        }

        .favorite-card img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .favorite-card:hover img {
            transform: scale(1.05);
        }

        .favorite-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            /* ADJUSTED: Badge background for contrast on card */
            background: #FFFFFF;
            color: #000;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .remove-favorite {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }

        .remove-favorite:hover {
            background: #ff4444;
            color: white;
            transform: scale(1.1);
        }

        .favorite-card .content {
            padding: 24px;
        }

        .favorite-card h4 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px;
            font-weight: 600;
            /* ADJUSTED: Text color for contrast on yellow background */
            color: #000;
            margin-bottom: 8px;
            line-height: 1.3;
        }

        .location-info {
            display: flex;
            align-items: flex-start;
            margin-bottom: 16px;
            font-size: 14px;
            /* ADJUSTED: Text color for contrast on yellow background */
            color: #333;
            font-weight: 500;
        }

        .location-info i {
            /* ADJUSTED: Icon color for contrast on yellow background */
            color: #000;
            margin-right: 8px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .amenities-section {
            margin-bottom: 16px;
        }

        .amenities-section h5 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 16px;
            font-weight: 600;
            /* ADJUSTED: Text color for contrast on yellow background */
            color: #000;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }

        .amenities-section h5 i {
            /* ADJUSTED: Icon color for contrast on yellow background */
            color: #000;
            margin-right: 8px;
        }

        .contact-info {
            margin-bottom: 16px;
            font-size: 15px;
        }

        .contact-info i {
            /* ADJUSTED: Icon color for contrast on yellow background */
            color: #000;
            margin-right: 8px;
        }

        .contact-info a {
            /* ADJUSTED: Link color for contrast on yellow background */
            color: #000;
            text-decoration: underline;
            transition: color 0.2s ease;
        }

        .contact-info a:hover {
            color: #333;
        }

        .favorite-date {
            font-size: 12px;
            /* ADJUSTED: Text color for contrast on yellow background */
            color: #333;
            margin-bottom: 16px;
            font-style: italic;
        }

        .book-btn {
            display: inline-block;
            padding: 14px 28px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            /* ADJUSTED: Button color for contrast on yellow card */
            background: #FFFFFF;
            color: #000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            width: 100%;
            text-align: center;
        }

        .book-btn:hover {
            background: #eee;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #4CAF50;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            z-index: 9999;
            transform: translateX(100%);
            transition: transform 0.3s ease;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.removed {
            background: #FF9800;
        }

        @media (max-width: 768px) {
            /* REMOVED: sidebar-related media queries */
            
            .topBar {
                padding: 16px 20px;
            }

            .titleNgSite {
                font-size: 20px;
            }

            .pageHeader {
                font-size: 28px;
                margin: 30px 20px 20px;
            }

            .favorites-grid {
                grid-template-columns: 1fr;
                padding: 0 20px 30px;
                gap: 20px;
            }

            .favorite-card .content {
                padding: 20px;
            }

            .stats-section {
                padding: 0 20px 20px;
                flex-direction: column;
                gap: 15px;
            }

            .empty-state {
                margin: 0 20px;
                padding: 40px 20px;
            }
        }

        .favorite-card {
            animation: fadeInUp 0.6s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }

        .favorite-card:nth-child(1) { animation-delay: 0.1s; }
        .favorite-card:nth-child(2) { animation-delay: 0.2s; }
        .favorite-card:nth-child(3) { animation-delay: 0.3s; }
        .favorite-card:nth-child(4) { animation-delay: 0.4s; }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="mainWrapper" id="mainWrapper">
        <div class="topBar">
            <button id="openMenu" title="Go back to previous page" onclick="goBack()">
                <i class="fas fa-arrow-left"></i> 
            </button>
            <div class="titleNgSite">STAY FINDER</div>
        </div>

        <h2 class="pageHeader">
            <i class="fas fa-heart"></i>
            My Wislist
        </h2>

        <div class="stats-section">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($favorite_houses); ?></div>
                <div class="stat-label">Favorites</div>
            </div>
        </div>

        <?php if (empty($favorite_houses)): ?>
            <div class="empty-state">
                <i class="fas fa-heart-broken"></i>
                <h3>No Favorites Yet</h3>
                <p>You haven't saved any boarding houses to your favorites. Start browsing and click the heart icon on any property you like!</p>
                <a href="dashboard.php" class="browse-btn">
                    <i class="fas fa-search"></i> Browse Boarding Houses
                </a>
            </div>
        <?php else: ?>
            <div class="favorites-grid">
                <?php foreach ($favorite_houses as $house):
                    $images = explode(',', $house['images']);
                    $first_image = !empty($images[0]) ? trim($images[0]) : 'img/default.jpg';
                    $favorited_date = new DateTime($house['favorited_at']);
                ?>
                    <div class="favorite-card">
                        <img src="<?php echo htmlspecialchars($first_image); ?>" alt="<?php echo htmlspecialchars($house['name']); ?>">

                        <div class="favorite-badge">
                            <i class="fas fa-heart"></i> Favorite
                        </div>

                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="remove_favorite">
                            <input type="hidden" name="house_id" value="<?php echo $house['id']; ?>">
                            <button type="submit" class="remove-favorite" title="Remove from favorites">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>

                        <div class="content">
                            <h4><?php echo strtoupper(htmlspecialchars($house['name'])); ?></h4>

                            <div class="favorite-date">
                                <i class="fas fa-calendar-plus"></i> Added on <?php echo $favorited_date->format('M d, Y'); ?>
                            </div>

                            <div class="location-info">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($house['full_location']); ?></span>
                            </div>

                            <div class="amenities-section">
                                <h5><i class="fas fa-star"></i>RULES:</h5>
                                <div><?php echo htmlspecialchars(preg_replace('/\[OWNER:[^\]]+\]/', '', $house['description'])); ?></div>
                            </div>

                            <div class="contact-info">
                                <?php if (!empty($house['owner_email'])): ?>
                                    <div style="margin-bottom: 4px;">
                                        <i class="fas fa-envelope"></i>
                                        <strong>Email:</strong>
                                        <a href="mailto:<?= htmlspecialchars($house['owner_email']) ?>">
                                            <?= htmlspecialchars($house['owner_email']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($house['owner_phone'])): ?>
                                    <div>
                                        <i class="fas fa-phone"></i>
                                        <strong>Phone:</strong>
                                        <a href="tel:<?= htmlspecialchars($house['owner_phone']) ?>">
                                            <?= htmlspecialchars($house['owner_phone']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <a href="bookform/book_house.php?house_id=<?php echo $house['id']; ?>" class="book-btn">
                                <i class="fas fa-calendar-plus"></i> Check Availability
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['removed'])): ?>
        <div id="toast" class="toast removed show">Removed from favorites</div>
        <script>
            setTimeout(() => {
                document.getElementById('toast').classList.remove('show');
            }, 3000);
        </script>
    <?php endif; ?>

    <script>
        // FIX: Function to go back to the previous page in history
        function goBack() {
            window.history.back();
        }

        document.querySelectorAll('.remove-favorite').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to remove this from your favorites?')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
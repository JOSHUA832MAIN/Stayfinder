<?php
session_start();

// Check if user is logged in and has accepted booking
$has_accepted_booking = false;
if (!empty($_SESSION['email'])) {
    // Get .env configuration
    $envPath = dirname(__DIR__) . '/.env';
    if (file_exists($envPath)) {
        $env = parse_ini_file($envPath);
        
        // Connect to database
        $conn = new mysqli(
            $env['DB_HOST'],
            $env['DB_USER'],
            $env['DB_PASS'],
            $env['DB_NAME']
        );
        
        if (!$conn->connect_error) {
            // Check if user has any accepted bookings
            $session_email = trim($_SESSION['email']);
            $check_sql = "SELECT COUNT(*) AS booking_count FROM tenant_requests 
                         WHERE email = ? AND status IN ('accepted','pending') LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("s", $session_email);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result && $check_result->num_rows > 0) {
                    $row = $check_result->fetch_assoc();
                    $has_accepted_booking = ($row['booking_count'] > 0);
                }
                $check_stmt->close();
            }
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <title>StayFinder - Choose Your Role</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: white;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .selection-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 100%;
            text-align: center;
            position: relative;
            border: 2px solid #f0f0f0;
        }

        .header-text {
            position: absolute;
            top: 20px;
            left: 80px;
            font-weight: bold;
            font-size: 1.5rem;
            color: #000;
            margin: 0;
        }

        .back-button {
            position: absolute;
            top: 15px;
            left: 20px;
            /* Increased size to match intendeduser (5).php */
            width: 50px;
            height: 50px;
            background: #ffd700; /* YELLOW INSIDE THE CIRCLE */
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            /* Increased font size to match intendeduser (5).php base for the arrow */
            font-size: 28px;
            font-weight: bold;
            color: #000; /* BLACK < symbol */
            transition: all 0.3s ease;
            /* Added box shadow and border to match intendeduser (5).php */
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
            border: none;
            /* line-height: 1; is removed as per intendeduser (5).php */
        }

        .back-button:hover {
            background: #ffed4e; /* Lighter yellow on hover */
            /* Increased scale to match intendeduser (5).php */
            transform: scale(1.12);
            /* Updated box shadow to match intendeduser (5).php */
            box-shadow: 0 6px 16px rgba(255, 215, 0, 0.4);
        }
        
        /* Added specific style to make the Font Awesome arrow thick */
        .back-button i.fa-arrow-left {
            font-size: 24px; /* Size adjustment */
            font-weight: 900; /* Make it thick (Solid icon weight) */
        }


        .buttons-container {
            margin-top: 80px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .role-btn {
            background: white;
            border: 3px solid #ffd700;
            color: #000;
            padding: 20px 30px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .role-btn:hover {
            background: #fff9e6;
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(255, 215, 0, 0.3);
            color: #000;
        }

        .role-btn:active {
            transform: translateY(-1px);
        }

        /* Link Style Button - No background, no border, just text */
        .link-btn {
            background: transparent;
            border: none;
            color: #000;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .link-btn:hover {
            color: #000;
            text-decoration: underline;
            transform: translateY(-2px);
        }

        .icon {
            font-size: 1.5rem;
        }

        .boarding-house-owner .icon {
            color: #ff6b6b;
        }

        .boarding-house-seeker .icon {
            color: #4ecdc4;
        }

        .link-btn .icon {
            color: #000;
            font-size: 1.3rem;
        }

        /* Added footer styles matching index.php */
        .footer {
            background-color: #111;
            color: #fff;
            padding: 10px 0;
            text-align: center;
            margin-top: auto;
        }

        .footer p {
            font-size: 13px;
        }

        .footer .text-warning {
            color: #ffc107 !important;
        }

        .footer .text-decoration-none {
            text-decoration: none;
        }

        .footer .fw-bold {
            font-weight: bold;
        }

        @media (max-width: 576px) {
            .selection-container {
                padding: 30px 20px;
            }
            
            .header-text {
                position: static;
                text-align: center;
                margin-bottom: 30px;
                margin-left: 40px;
            }
            
            .back-button {
                top: 20px;
                left: 20px;
            }
            
            .buttons-container {
                margin-top: 40px;
            }
            
            .role-btn {
                padding: 15px 20px;
                font-size: 1.1rem;
            }
            
            .link-btn {
                padding: 8px 15px;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="selection-container">
           <a href="../index.php" class="back-button">
        <i class="fas fa-arrow-left"></i> 
    </a>
            <h1 class="header-text">Are you ?</h1>
            
            <div class="buttons-container">
                <a href="owneregister/loginowner.php" class="role-btn boarding-house-owner">
                    Boarding House Owner
                </a>
                
                <a href="auseregisterlogform/loginseeker.php" class="role-btn boarding-house-seeker">
                    Boarding House Seeker
                </a>

                <a href="auseregisterlogform/login.php" class="link-btn">
                    <i class="fas fa-user icon"></i>
                    Access Renter Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Added footer section matching index.php -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <p class="mb-0">
                        StayFinder: Boarding Locator and Management System
                    </p>
                    <p class="mt-1 mb-0">
                        <a href="terms.php" class="text-warning text-decoration-none fw-bold">Terms &amp; Conditions</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

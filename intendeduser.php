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
        /* Ensure the body takes full height to push footer to bottom if needed */
        html, body {
            height: 100%;
            margin: 0;
        }
        
        body {
            background: white;
            display: flex;
            flex-direction: column;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-content {
            flex: 1 0 auto;
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
        }

        .back-button:hover {
            background: #ffed4e; 
            transform: scale(1.12);
            box-shadow: 0 6px 16px rgba(255, 215, 0, 0.4);
        }
        
        .back-button i.fa-arrow-left {
            font-size: 24px; 
            font-weight: 900; 
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

        /* Footer Styles from index.php */
        .footer {
            background-color: #111;
            color: #fff;
            padding: 15px 0;
            text-align: center;
            width: 100%;
            flex-shrink: 0;
        }

        .footer a {
            color: #ffd700;
            text-decoration: none;
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
                <a href="owneregister/registerowner.php" class="role-btn boarding-house-owner">
                    Boarding House Owner
                </a>
                
                <a href="auseregisterlogform/register.php" class="role-btn boarding-house-seeker">
                    Boarding House Seeker
                </a>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <p class="mb-0" style="font-size:13px;">
                        StayFinder: Boarding Locator and Management System
                    </p>
                    <p class="mt-1 mb-0" style="font-size:13px;">
                        <a href="terms.php">Terms &amp; Conditions</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
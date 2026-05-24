<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code</title>
    <link rel="icon" href="../img/logoicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Flexbox body to push footer down */
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #f8f9fa;
        }

        /* Main content grows to fill space */
        .content-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card { 
            position: relative; 
            max-width: 500px; 
            width: 100%;
        }

        /* Yellow circular back button */
        .back-circle {
            position: absolute;
            top: 12px;
            left: 12px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #ffd700; 
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.12);
            border: 2px solid rgba(0,0,0,0.08);
            z-index: 10;
        }
        .back-circle i { color: #000; font-size: 18px; }

        /* Yellow submit button */
        .btn-yellow {
            background: #ffd700;
            border-color: #d4a500;
            color: #111;
            font-weight: 700;
        }
        .btn-yellow:hover, .btn-yellow:focus {
            background: #ffcc33;
            color: #111;
        }

        footer {
            background: #fff;
            padding: 20px 0;
            border-top: 1px solid #dee2e6;
            text-align: center;
        }
    </style>
</head>

<body>

    <div class="content-wrapper">
        <div class="card shadow-lg border-0 rounded-4">
            <a href="register.php" class="back-circle" aria-label="Go back">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
            </a>
            <div class="card-body p-5">
                <h3 class="text-center mb-4">📧 Enter Verification Code</h3>
                <p class="text-muted text-center small mb-4">Please check your email for the 6-digit verification code.</p>

                <form action="../connectiondatabase/verfycons.php" method="POST">
                    <div class="mb-4">
                        <label class="form-label fw-bold">6-digit Code</label>
                        <input type="text" name="code" class="form-control form-control-lg text-center" 
                               placeholder="000000" maxlength="6" pattern="\d{6}" required>
                    </div>
                    <button type="submit" class="btn btn-yellow w-100 py-2">Verify Code</button>
                </form>
            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <p class="text-muted mb-0">&copy; <?php echo date("Y"); ?> StayFinder. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
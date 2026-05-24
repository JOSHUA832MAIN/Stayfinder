<?php session_start(); ?>
<!DOCTYPE html>
<link rel="icon" href="../img/logoicon.ico" type="image/x-icon">
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Owner - Forgot Password</title>
    <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Yellow button style */
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

        /* Back circle button */
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
            color: #000;
        }
        .back-circle i { color: #000; font-size: 18px; }

        /* make card relative for absolute back button */
        .card { position: relative; }
    </style>
</head>

<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">

    <div class="card shadow-lg border-0 rounded-4" style="max-width: 500px; width: 100%;">
        <div class="card-body p-4">
            <a href="javascript:history.back()" class="back-circle" aria-label="Go back">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
            </a>
            <h3 class="text-center mb-4"> Owner Forgot Password</h3>

                <form action="../connectiondatabase/forgotowner.php" method="POST">
                <div class="mb-3">
                    <label class="form-label">Enter your Email</label>
                    <input type="email" class="form-control" name="email" placeholder="owner@example.com" required>
                </div>
                    <button type="submit" class="btn btn-yellow w-100">Send Verification Code</button>
            </form>
        </div>
    </div>

</body>

</html>

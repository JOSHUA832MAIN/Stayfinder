<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="icon" href="../img/logoicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        .card-body {
            padding: 40px;
        }
        h3 {
            font-weight: 600;
            color: #333;
            margin-bottom: 30px;
        }
        .form-control {
            background: #f5f5f5;
            border: none;
            border-radius: 8px;
            padding: 15px;
            font-size: 16px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            background: #fff;
            box-shadow: 0 0 0 2px #ffd700;
            border: none;
        }
        .btn-primary {
            background: #ffd700;
            border: none;
            border-radius: 8px;
            padding: 15px;
            font-size: 16px;
            font-weight: 600;
            color: #333;
            width: 100%;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: #e6c200;
            color: #333;
            transform: translateY(-1px);
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
            color: #000;
        }
        
        .back-button i.fa-arrow-left {
            font-size: 24px;
            font-weight: 900;
        }
        .form-label {
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }
        @media (max-width: 576px) {
            .card-body {
                padding: 30px 20px;
            }
            .back-button {
                top: 15px;
                left: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="card shadow-lg" style="max-width: 500px; width: 100%;">
        <a href="login.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
        </a>
        
        <div class="card-body">
            <h3 class="text-center">🔒 Forgot Password</h3>

            <form action="../connectiondatabase/password-reset.php" method="POST">
                <div class="mb-3">
                    <label class="form-label">Enter your Email</label>
                    <input type="email" class="form-control" name="email" placeholder="Enter your email address" required>
                </div>
                <button type="submit" class="btn btn-primary">Send Verification Code</button>
            </form>
        </div>
    </div>
</body>
</html>
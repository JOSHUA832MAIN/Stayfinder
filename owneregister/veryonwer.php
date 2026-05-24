<?php
session_start();
?>
<!DOCTYPE html>
<link rel="icon" href="../img/logoicon.ico" type="image/x-icon">

<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Owner - Verify Code</title>
    <link rel="icon" href="img/494819260_1450448046102632_2947403985636425514_n (1).jpg" type="image/jpeg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #fffbea, #ffd700);
            font-family: 'Segoe UI', sans-serif;
        }

        .card {
            background: white;
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        h3 {
            color: #d4af37; /* gold */
            font-weight: bold;
        }

        .btn-primary {
            background-color: #d4af37;
            border: none;
            font-weight: bold;
            color: white;
        }

        .btn-primary:hover {
            background-color: #b7950b;
            color: white;
        }

        .btn-outline-secondary {
            border-color: #d4af37;
            color: #d4af37;
        }

        .btn-outline-secondary:hover {
            background-color: #d4af37;
            color: white;
        }
    </style>
</head>

<body class="d-flex align-items-center justify-content-center min-vh-100">

    <div class="card" style="max-width: 500px; width: 100%;">
        <div class="card-body p-4">
            <h3 class="text-center mb-4">📧 Owner Verification</h3>

            <form action="../connectiondatabase/verfyowner.php" method="POST">
                <div class="mb-3">
                    <label class="form-label">Enter 6-digit Code</label>
                    <input type="text" name="code" class="form-control" maxlength="6" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-2">Verify</button>
                <a href="loginowner.php" class="btn btn-outline-secondary w-100">⬅ Back</a>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>

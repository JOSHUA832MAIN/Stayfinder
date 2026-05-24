<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login - StayFinder</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    html, body {
      height: 100%;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: #FFFFFF;
      display: flex;
      flex-direction: column;
      position: relative;
      overflow-x: hidden;
    }

    /* Decorative Background Elements */
    body::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -50%;
      width: 100%;
      height: 100%;
      background: radial-gradient(circle, rgba(255, 215, 0, 0.05) 0%, transparent 70%);
      border-radius: 50%;
      z-index: -1;
    }

    /* Main Content Wrapper */
    .main-wrapper {
      flex: 1 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 15px;
    }

    .login-container {
      max-width: 480px;
      width: 100%;
    }

    .login-card {
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
      overflow: hidden;
      border: 3px solid #FFD700;
    }

    .login-header {
      background: linear-gradient(135deg, #FFD700 0%, #FFC107 100%);
      padding: 35px 25px 30px;
      text-align: center;
      position: relative;
    }

    .logo {
      width: 90px;
      height: 90px;
      border-radius: 50%;
      border: 4px solid white;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
      object-fit: cover;
      background: white;
      padding: 5px;
      margin-bottom: 15px;
    }

    .system-title {
      font-size: 28px;
      font-weight: 900;
      color: white;
      text-transform: uppercase;
      letter-spacing: 2px;
      margin-bottom: 5px;
      text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
    }

    .admin-subtitle {
      font-size: 14px;
      color: rgba(255, 255, 255, 0.95);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1.5px;
    }

    .login-body {
      padding: 35px 30px;
    }

    .welcome-text h3 {
      font-size: 22px;
      font-weight: 700;
      color: #2C3E50;
      margin-bottom: 8px;
      text-align: center;
    }

    .welcome-text p {
      color: #7F8C8D;
      font-size: 14px;
      text-align: center;
      margin-bottom: 30px;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-label {
      font-weight: 700;
      color: #2C3E50;
      margin-bottom: 8px;
      font-size: 13px;
      text-transform: uppercase;
      display: block;
    }

    .input-wrapper {
      position: relative;
    }

    .input-icon {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #FFD700;
      z-index: 2;
    }

    .form-control {
      height: 50px;
      border: 2px solid #E8E8E8;
      border-radius: 12px;
      padding-left: 45px;
      background: #F8F9FA;
      transition: all 0.3s ease;
    }

    .form-control:focus {
      border-color: #FFD700;
      box-shadow: 0 0 0 4px rgba(255, 215, 0, 0.1);
      background: white;
    }

    .btn-login {
      width: 100%;
      height: 50px;
      background: linear-gradient(135deg, #FFD700 0%, #FFC107 100%);
      border: none;
      border-radius: 12px;
      color: white;
      font-size: 15px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      box-shadow: 0 8px 20px rgba(255, 215, 0, 0.3);
      margin-top: 25px;
      transition: all 0.3s ease;
    }

    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 30px rgba(255, 215, 0, 0.4);
    }

    /* Footer - Matches Index.php style exactly */
    .footer {
      flex-shrink: 0;
      background-color: #111;
      color: #fff;
      padding: 20px 0;
      text-align: center;
    }

    .footer a {
      color: #FFD700;
      text-decoration: none;
      font-weight: bold;
    }

    .footer a:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>

  <div class="main-wrapper">
    <div class="login-container">
      <div class="login-card">
        <div class="login-header">
          <img src="../img/stayfinder.ico" 
               alt="StayFinder Logo" 
               class="logo"
               onerror="this.style.display='none'; this.parentElement.innerHTML+='<div style=\'width:90px;height:90px;border-radius:50%;background:#fff;border:4px solid white;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;font-size:36px;font-weight:900;color:#FFD700;\'>SF</div>'">
          
          <h1 class="system-title">StayFinder</h1>
          <p class="admin-subtitle">Admin Portal</p>
        </div>

        <div class="login-body">
          <div class="welcome-text">
            <h3>Welcome Back!</h3>
            <p>Please login to access the admin dashboard</p>
          </div>

          <form action="conectadmin.php" method="POST">
            <div class="form-group">
              <label for="username" class="form-label">Username</label>
              <div class="input-wrapper">
                <i class="fas fa-user input-icon"></i>
                <input type="text" class="form-control" id="username" name="username" placeholder="Enter username" required autocomplete="username">
              </div>
            </div>

            <div class="form-group">
              <label for="password" class="form-label">Password</label>
              <div class="input-wrapper">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required autocomplete="current-password">
              </div>
            </div>

            <button type="submit" class="btn-login">
              <i class="fas fa-sign-in-alt"></i> Login to Dashboard
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <footer class="footer">
    <div class="container">
      <p class="mb-0" style="font-size: 14px;">StayFinder: Boarding Locator and Management System</p>
      <p class="mt-1 mb-0" style="font-size: 14px;">
        <a href="terms.php">Terms &amp; Conditions</a>
      </p>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
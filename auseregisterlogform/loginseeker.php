<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Stay Finder - Login</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <style>
    html, body {
        height: 100%;
        margin: 0;
        padding: 0;
    }

    body {
        background-color: #f7f7f7; /* Matches owner login background */
        font-family: 'Segoe UI', sans-serif;
        display: flex;
        flex-direction: column;
    }

    body > :not(.footer) {
        flex: 1;
    }

    .footer {
        margin-top: auto;
    }

    .logo-container {
        text-align: center;
        margin: 20px 0 0 0;
    }

    .logo {
        width: 310px;
        height: 310px;
        object-fit: cover;
        border-radius: 15px;
    }

    .container {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        margin-top: -70px; /* Matches owner container alignment */
        min-height: auto;
    }

    .card {
        background: white;
        border: none;
        border-radius: 20px; /* Matches owner rounded corners */
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        padding: 2.5rem;
        width: 450px; /* Matches owner box width */
        max-width: 90%;
        position: relative;
    }

    .form-heading {
        font-size: 2rem;
        font-weight: 500;
        margin-bottom: 2rem;
        text-align: center;
        color: #333;
    }

    .form-control {
        border-radius: 10px;
        padding: 0.75rem 1.25rem;
        background-color: #f2f2f2;
        border: 1px solid #e0e0e0;
    }

    .form-control:focus {
        background-color: #fff;
        box-shadow: 0 0 0 0.25rem rgba(255, 193, 7, 0.25);
        border-color: #ffc107;
    }

    .form-control::placeholder {
        color: #999;
    }

    .btn-primary {
        background-color: #ffc107; /* Matches owner yellow */
        border: none;
        font-weight: 600;
        color: #fff;
        padding: 0.75rem 0;
        border-radius: 10px;
        width: 100%;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }

    .btn-primary:hover {
        background-color: #e0a800;
        color: #fff;
        transform: translateY(-1px);
    }

    .back-button {
        position: absolute;
        top: 15px;
        left: 15px;
        width: 50px; 
        height: 50px;
        background: #ffc107;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-size: 28px;
        font-weight: bold;
        color: #000;
        transition: all 0.3s ease;
        z-index: 10;
        box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
    }

    .back-button:hover {
        background: #ffed4e;
        transform: scale(1.12);
        color: #000;
        box-shadow: 0 6px 16px rgba(255, 193, 7, 0.4);
    }

    .back-button i.fa-arrow-left {
        font-size: 24px;
        font-weight: 900;
    }

    .btn-outline-secondary {
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
        color: #6c757d;
        border-color: #6c757d;
        border-radius: 10px;
    }

    .btn-outline-secondary:hover {
        background-color: #6c757d;
        color: white;
    }
  </style>
</head>
<body>

  <div class="logo-container">
    <img src="/img/rt" alt="StayFinder Logo" class="logo">
  </div>

  <div class="container">
    <div class="card">
      <a href="../index.php" class="back-button">
        <i class="fas fa-arrow-left"></i>
      </a>

      <h2 class="form-heading">Seeker Login</h2>

      <form id="loginForm" action="../connectiondatabase/USERconnectloginseeker.php" method="post" onsubmit="return validateForm()">
        
        <div class="mb-3">
          <input type="email" name="email" class="form-control" placeholder="Email Address" required>
        </div>
        
        <div class="mb-3 position-relative">
          <input type="password" id="password" name="password" class="form-control" placeholder="Password" required>
          <button type="button" class="btn btn-outline-secondary position-absolute top-50 end-0 translate-middle-y me-2" onclick="togglePassword()">
            <i id="eyeIcon" class="fas fa-eye"></i>
          </button>
        </div>

        <button type="submit" name="login_btn" class="btn btn-primary">Login</button>

        <div class="text-center mb-2">
          Don't have an account? <a href="register.php" class="fw-bold" style="color: #ffc107; text-decoration: none;">Register here</a>
        </div>

        <div class="text-center mb-3">
          <a href="forgot-password.php" class="fw-bold" style="color: #333; text-decoration: none;">🔑 Forgot Password?</a>
        </div>
      </form>
    </div>
  </div>

  <script>
    function togglePassword() {
      const pwd = document.getElementById("password");
      const eyeIcon = document.getElementById("eyeIcon");
      
      if (pwd.type === "password") {
        pwd.type = "text";
        eyeIcon.classList.remove("fa-eye");
        eyeIcon.classList.add("fa-eye-slash");
      } else {
        pwd.type = "password";
        eyeIcon.classList.remove("fa-eye-slash");
        eyeIcon.classList.add("fa-eye");
      }
    }

    function validateForm() {
      const email = document.querySelector('input[name="email"]').value.trim();
      const password = document.querySelector('input[name="password"]').value;

      if (!email || !password) {
        alert('❌ Please fill in all fields.');
        return false;
      }
      return true;
    }
  </script>

  <footer class="footer" style="background-color:#111 !important; color:#fff !important; padding:8px 0; margin-top:20px; text-align:center; width:100vw; margin-left:calc(-50vw + 50%); z-index:800;">
      <div class="container-fluid" style="width:100%; padding-left:15px; padding-right:15px;">
          <p style="margin:6px 0;font-size:13px;">StayFinder: Boarding Locator and Management System</p>
          <div class="footer-links" style="margin-top:4px;">
              <a href="../terms.php" target="_blank" rel="noopener" style="color:yellow !important; text-decoration:none !important; font-weight:600;">Terms &amp; Conditions</a>
          </div>
      </div>
  </footer>

</body>
</html>
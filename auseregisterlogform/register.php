<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Stay Finder - Register</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <style>
    /* Sticky Footer Logic */
    html, body {
      height: 100%;
      margin: 0;
      padding: 0;
    }

    body { 
      background-color: #f7f7f7; /* Matches owner design background */
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    .main-content {
      flex: 1 0 auto;
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
      max-width: 90%; 
    }

    .container { 
      display: flex;
      justify-content: center;
      align-items: flex-start;
      margin-top: -70px; /* Aligns with the owner container placement */
      padding-bottom: 40px;
    }

    .card { 
      background: white; 
      padding: 40px; 
      border-radius: 20px; /* Matches owner rounded corners */
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08); 
      width: 700px; /* Matches registerowner width */
      max-width: 95%;
      position: relative;
      border: none;
    }

    .form-title { 
      font-size: 2.2rem; 
      font-weight: 500; 
      color: #333; 
      text-align: center; 
      margin-bottom: 2rem; 
    }

    .form-control { 
      background: #f2f2f2; /* Matches owner input background */
      border: 1px solid #e0e0e0; 
      border-radius: 10px; 
      padding: 12px 15px; 
      font-size: 16px; 
      margin-bottom: 15px; 
      transition: all 0.3s ease; 
    }

    .form-control:focus { 
      background: #fff; 
      box-shadow: 0 0 0 0.25rem rgba(255, 193, 7, 0.25); 
      border-color: #ffc107; 
    }

    .btn-primary { 
      background: #ffc107; 
      border: none; 
      border-radius: 10px; 
      padding: 14px; 
      font-size: 17px; 
      font-weight: 600; 
      color: #fff; 
      width: 100%; 
      margin-top: 10px;
      margin-bottom: 20px; 
      transition: all 0.3s ease; 
    }

    .btn-primary:hover { 
      background: #e0a800; 
      transform: translateY(-1px); 
    }

    /* Back Button - Same as Seeker/Owner Login */
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
      color: #000; 
      z-index: 10; 
      box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3); 
      transition: all 0.3s ease;
    }

    .back-button:hover {
      background: #ffed4e;
      transform: scale(1.1);
      color: #000;
    }

    .back-button i {
        font-size: 24px;
    }

    .error-text { color: #dc3545; font-size: 13px; margin-bottom: 10px; margin-top: -10px; }
    .strength { font-size: 13px; margin-bottom: 15px; }
    
    .password-container { position: relative; }
    .password-toggle { 
      position: absolute; 
      right: 15px; 
      top: 25px; 
      transform: translateY(-50%); 
      background: none; 
      border: none; 
      color: #6c757d; 
      cursor: pointer; 
    }

    #imagePreview { 
      border: 3px solid #ffc107; 
      border-radius: 50%; 
      margin: 15px auto; 
      display: block; 
      object-fit: cover;
    }

    .file-input-wrapper { 
      position: relative; 
      margin-bottom: 20px; 
      width: 100%; 
    }

    .file-input-display { 
      background: #f2f2f2; 
      border-radius: 10px; 
      padding: 15px; 
      text-align: center; 
      color: #666; 
      cursor: pointer;
      border: 1px dashed #ccc;
    }

    .login-link { text-align: center; margin-top: 10px; }
    .login-link a { color: #ffc107; font-weight: bold; text-decoration: none; }

    /* Unified Footer */
    .footer {
      flex-shrink: 0;
      background-color: #111 !important;
      color: #fff !important;
      padding: 8px 0;
      text-align: center;
      width: 100%;
    }
  </style>
</head>
<body>
  <div class="main-content">
    <div class="logo-container">
      <img src="/img/rt" alt="StayFinder Logo" class="logo">
    </div>

    <div class="container">
      <div class="card">
        <a href="loginseeker.php" class="back-button"><i class="fas fa-arrow-left"></i></a>

        <?php if (isset($_GET['show_otp'])): ?>
          <div class="text-center">
            <h1 class="form-title">Verify Your Email</h1>
            <p class="text-muted mb-4">A 6-digit code was sent to your email.</p>
            <form action="../connectiondatabase/USERconnectregister.php" method="post">
              <input type="text" name="otp_code" class="form-control text-center" maxlength="6" placeholder="000000" style="font-size: 24px; letter-spacing: 5px;" required>
              <button type="submit" name="otp_submit" class="btn btn-primary">Confirm OTP</button>
            </form>
          </div>
        <?php else: ?>
          <h1 class="form-title">Create Seeker Account</h1>

          <form id="registerForm" action="../connectiondatabase/USERconnectregister.php" method="post" enctype="multipart/form-data" onsubmit="return validateForm()">
            
            <div class="file-input-wrapper">
              <div class="file-input-display"><i class="fas fa-camera"></i> Click to upload Profile Picture</div>
              <input type="file" name="profile_picture" id="profile_picture" accept="image/*" required onchange="previewImage(this)" style="position:absolute; top:0; left:0; width:100%; height:100%; opacity:0; cursor:pointer;">
              <img id="imagePreview" width="100" height="100" alt="Preview" style="display:none;">
              <div id="fileError" class="error-text d-none">❌ Please select a valid image under 5MB.</div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <input type="text" name="name" class="form-control" placeholder="Full Name" required>
                </div>
                <div class="col-md-6">
                    <input type="email" name="email" class="form-control" placeholder="Email Address" required>
                </div>
            </div>
            
            <input type="tel" name="number" id="number" class="form-control" placeholder="Phone Number (e.g. 09123456789)" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,'')" required>
            <div id="phoneError" class="error-text d-none">❌ Please enter a valid 11-digit phone number.</div>

            <div class="password-container">
              <input type="password" id="password" name="password" class="form-control" placeholder="Password" required>
              <button type="button" class="password-toggle" onclick="togglePassword()">
                <i id="eyeIcon" class="fas fa-eye"></i>
              </button>
            </div>
            <div id="strengthMessage" class="strength"></div>

            <button type="submit" name="submit_btn" class="btn btn-primary">Sign up</button>
            
            <div class="login-link">
                Already have an account? <a href="loginseeker.php">Login here</a>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <footer class="footer">
      <div class="container-fluid">
          <p style="margin:6px 0;font-size:13px;">StayFinder: Boarding Locator and Management System</p>
          <div class="footer-links" style="margin-top:4px;">
              <a href="../terms.php" target="_blank" rel="noopener" style="color:yellow !important; text-decoration:none !important; font-weight:600;">Terms &amp; Conditions</a>
          </div>
      </div>
  </footer>

  <script>
    function togglePassword() {
      const pwd = document.getElementById("password");
      const eyeIcon = document.getElementById("eyeIcon");
      pwd.type = pwd.type === "password" ? "text" : "password";
      eyeIcon.classList.toggle("fa-eye");
      eyeIcon.classList.toggle("fa-eye-slash");
    }

    document.getElementById("password").addEventListener("input", function () {
      const strengthMessage = document.getElementById("strengthMessage");
      const val = this.value || "";
      const length = val.length;
      if (length === 0) { strengthMessage.textContent = ""; return; }

      const hasSpecial = /[^a-zA-Z0-9]/.test(val);
      if (length < 8) {
        strengthMessage.textContent = "⚠️ Min. 8 characters";
        strengthMessage.style.color = "#dc3545";
      } else if (!hasSpecial) {
        strengthMessage.textContent = "⚠️ Add a special character";
        strengthMessage.style.color = "#dc3545";
      } else {
        strengthMessage.textContent = "Strong Password";
        strengthMessage.style.color = "#198754";
      }
    });

    function validateForm() {
      const number = document.getElementById('number').value.trim();
      if (number.length !== 11) {
        document.getElementById('phoneError').classList.remove("d-none");
        return false; 
      } 
      return true;
    }

    function previewImage(input) {
      const preview = document.getElementById('imagePreview');
      const displayText = document.querySelector('.file-input-display');
      if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
          preview.src = e.target.result;
          preview.style.display = 'block';
          displayText.innerHTML = '<i class="fas fa-check"></i> Image Selected';
        }
        reader.readAsDataURL(input.files[0]);
      }
    }
  </script>
</body>
</html>
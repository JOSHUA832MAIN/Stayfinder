<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Boarding House Owner Login</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: #f4f4f4; /* Solid light background instead of gradient */
      margin: 0;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
    }

    .container {
      max-width: 900px;
      width: 100%;
      background: white;
      padding: 50px;
      border-radius: 20px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
      text-align: center;
    }

    .heading1 {
      font-size: 2.2rem;
      font-weight: 600;
      color: #f39c12;
      margin-bottom: 30px;
    }

    .welcome-text {
      font-size: 1rem;
      color: #555;
      margin-bottom: 25px;
    }

    .field {
      margin-bottom: 25px;
      text-align: left;
      position: relative;
    }

    label {
      font-weight: 600;
      color: #333;
      margin-bottom: 8px;
      display: block;
    }

    .required {
      color: #e67e22;
    }

    .inputpassword {
      width: 100%;
      padding: 14px;
      border: 2px solid #f1c40f;
      border-radius: 10px;
      font-size: 1rem;
      transition: border-color 0.3s;
    }

    .inputpassword:focus {
      border-color: #f39c12;
      outline: none;
    }

    /* UPDATED: Style for the gray toggle icon */
    .togglepassword {
      position: absolute;
      right: 15px;
      top: 68%;
      transform: translateY(-50%);
      cursor: pointer;
      font-size: 1.2rem;
      color: #6c757d; /* Gray color for the icon */
      user-select: none;
    }

    .submitbutton {
      width: 100%;
      background: #FFD400; /* Solid yellow button */
      color: black; /* ACCESS text should be black */
      padding: 15px;
      border: none;
      border-radius: 10px;
      margin-top: 20px;
      font-weight: bold;
      font-size: 1.1rem;
      cursor: pointer;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .submitbutton:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 15px rgba(0,0,0,0.12);
    }
/* UPDATED CSS for Centered Arrow */
.back-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 20px;
  background: #FFD400; /* Bright yellow circle */
  color: black; /* Black arrow */
  padding: 10px; /* Controls the size of the circle */
  border-radius: 50%; /* Perfect circle */
  text-decoration: none;
  font-weight: bold;
  font-size: 1.5rem;
  transition: all 0.2s;
  float: left;
  border: 2px solid #FFD400; /* Match border to yellow background */
}

.back-link:hover {
  background: #FFC107; /* Slightly darker yellow on hover */
  border-color: #FFC107;
  color: black; /* Keep arrow black on hover */
}

    /* Removed .back-link i rule as it's no longer necessary with the Font Awesome icon */

    @media (max-width: 600px) {
      .container {
        padding: 30px 20px;
      }

      .heading1 {
        font-size: 1.7rem;
      }
      
      .back-link {
        float: none;
        display: inline-flex;
        margin-bottom: 20px;
      }
    }
  .digit-input {
    font-size: 1.5rem !important;
    font-weight: bold !important;
    letter-spacing: 8px !important;
    text-align: center !important;
    padding: 15px 20px !important;
}

.digit-input::placeholder {
    letter-spacing: normal !important;
    font-size: 1rem !important;
}
  </style>
</head>
<body>

  <div class="container">
    <?php
    // Database connection and data retrieval
    require_once '../connectiondatabase/main_connection.php';
    
    $house_id = isset($_GET['house_id']) ? intval($_GET['house_id']) : 0;
    $house_name = "Boarding House Owner Login";
    $owner_name = "Owner";
    
    if ($house_id > 0 && $conn) {
        // Get boarding house and owner information
        $stmt = $conn->prepare("
            SELECT bh.name as house_name, bh.owner_email, orr.user_fullname 
            FROM boarding_houses bh 
            LEFT JOIN ownerregister orr ON bh.owner_email = orr.user_email 
            WHERE bh.id = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param("i", $house_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $row = $result->fetch_assoc()) {
                $house_name = htmlspecialchars($row['house_name']);
                $owner_name = htmlspecialchars($row['user_fullname'] ?? 'Owner');
            }
            $stmt->close();
        }
    }
    ?>
    
<a href="createboardinghouse.php" class="back-link">
  <i class="fas fa-arrow-left"></i>
</a>
    
    <div style="clear: both;"></div>
    
    <h1 class="heading1"><?php echo $house_name; ?></h1>

    <form action="../connectiondatabase/ownerconnection.php" method="post" id="loginform">
      <input type="hidden" name="email_or_id" value="<?php echo $house_id; ?>">
      
     <div class="field">
    <label for="password">6-Digit Access Code <span class="required">*</span></label>
    <input type="text" 
           id="password" 
           name="password" 
           placeholder="Enter 6-digit code" 
           required 
           class="inputpassword digit-input"
           maxlength="6"
           pattern="[0-9]{6}"
           oninput="this.value = this.value.replace(/\D/g, '').slice(0, 6)">
</div>

      <button type="submit" class="submitbutton">ACCESS </button>
    </form>
  </div>


</body>
</html>
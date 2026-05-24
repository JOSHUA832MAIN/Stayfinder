<?php
session_start();

// Include database connection
include_once('main_connection.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Get form data
    $house_name = $_POST['house_name'];
    $location = $_POST['location'];
    $price = $_POST['price'];
    $distance = $_POST['distance'];
    $amenities = $_POST['amenities'];
    $description = $_POST['description'];
    $contact = $_POST['contact'];
    
    // Get owner info from session
    $owner_email = isset($_SESSION['email']) ? $_SESSION['email'] : '';
    $owner_name = isset($_SESSION['name']) ? $_SESSION['name'] : '';
    
    if (empty($owner_email)) {
        echo "<script>alert('Please login first!'); window.location.href='../auseregisterlogform/login.php';</script>";
        exit;
    }
    
    // Create upload directories
    $imageDir = "../boarding_house_images/";
    $videoDir = "../boarding_house_videos/";
    
    if (!file_exists($imageDir)) {
        mkdir($imageDir, 0777, true);
    }
    if (!file_exists($videoDir)) {
        mkdir($videoDir, 0777, true);
    }
    
    // Handle multiple image uploads
    $uploadedImages = [];
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $imageCount = count($_FILES['images']['name']);
        
        for ($i = 0; $i < $imageCount; $i++) {
            if ($_FILES['images']['error'][$i] == 0) {
                $imageName = $_FILES['images']['name'][$i];
                $imageTmp = $_FILES['images']['tmp_name'][$i];
                $imageSize = $_FILES['images']['size'][$i];
                
                // Check file size (max 5MB per image)
                if ($imageSize > 5000000) {
                    echo "<script>alert('Image " . ($i+1) . " is too large. Max 5MB per image.'); 
                    window.location.href='../baordinghouseOWNER/register-boarding-house.php';</script>";
                    exit;
                }
                
                // Generate unique filename
                $imageExt = pathinfo($imageName, PATHINFO_EXTENSION);
                $newImageName = time() . "_" . $i . "_" . uniqid() . "." . $imageExt;
                $imageTarget = $imageDir . $newImageName;
                
                // Move uploaded file
                if (move_uploaded_file($imageTmp, $imageTarget)) {
                    $uploadedImages[] = "boarding_house_images/" . $newImageName;
                }
            }
        }
    }
    
    // Handle panorama video upload (optional)
    $panoramaPath = '';
    if (isset($_FILES['panorama_video']) && $_FILES['panorama_video']['error'] == 0) {
        $videoName = $_FILES['panorama_video']['name'];
        $videoTmp = $_FILES['panorama_video']['tmp_name'];
        $videoSize = $_FILES['panorama_video']['size'];
        
        // Check file size (max 50MB for video)
        if ($videoSize > 50000000) {
            echo "<script>alert('Video file is too large. Max 50MB allowed.'); 
            window.location.href='../baordinghouseOWNER/register-boarding-house.php';</script>";
            exit;
        }
        
        // Generate unique filename for video
        $videoExt = pathinfo($videoName, PATHINFO_EXTENSION);
        $newVideoName = time() . "_panorama_" . uniqid() . "." . $videoExt;
        $videoTarget = $videoDir . $newVideoName;
        
        // Move uploaded video
        if (move_uploaded_file($videoTmp, $videoTarget)) {
            $panoramaPath = "boarding_house_videos/" . $newVideoName;
        }
    }
    
    // Check if at least one image was uploaded
    if (empty($uploadedImages)) {
        echo "<script>alert('Please upload at least one image!'); 
        window.location.href='../baordinghouseOWNER/createboardinghouse.php";
        exit;
    }
    
    // Convert images array to comma-separated string
    $imagesString = implode(',', $uploadedImages);
    
    // Insert into database
$stmt = $conn->prepare("INSERT INTO boarding_houses (name, location, price, distance, amenities, description, contact, images, panorama_video, owner_email, owner_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("ssddssssss", $house_name, $location, $price, $distance, $amenities, $description, $contact, $imagesString, $panoramaPath, $owner_email, $owner_name);
$stmt->execute();

    
    if (mysqli_query($conn, $sql)) {
        echo "<script>
        alert('Boarding house registered successfully!'); 
        window.location.href='boardinghouseOWNER/ownerlog.php';
        </script>";
    } else {
        echo "<script>
        alert('Error: " . mysqli_error($conn) . "'); 
        window.location.href='../baordinghouseOWNER/createboardinghouse.php';
        </script>";
    }
    
} else {
    // Redirect if not POST request
    header("Location:../baordinghouseOWNER/createboardinghouse.php");
    exit;
}
?>
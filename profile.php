<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

// Connect to database
$serverName = "SatanaelLG\MSSQLSERVER01";
$connectionOptions = ["Database" => "ANIMEALS", "Uid" => "", "PWD" => ""];
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Fetch logged-in user's data
$stmt = sqlsrv_query($conn, "SELECT * FROM USER_DETAILS WHERE userEMAIL = ?", [$_SESSION['email']]);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

// Determine back button destination based on role
$backPage = match($user['userROLE']) {
    'seller' => 'seller.php',
    'admin'  => 'admin.php',
    default  => 'student.php'
};

// Reusable profile picture fallback
$profilePic = !empty($user['userPROFILEPIC'])
    ? htmlspecialchars($user['userPROFILEPIC'])
    : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS | Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f3f7f5;
        }

        /* ── MAIN CONTAINER ── */
        .container {
            max-width: 900px;
            margin: auto;
            padding: 30px;
        }

        /* ── PROFILE HEADER CARD ── */
        .profile-header {
            background: white;
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            position: relative;
        }

        /* Top green banner */
        .profile-banner {
            height: 140px;
            background: linear-gradient(135deg, #0f7b55, #1dbf73);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 22px;
        }

        /* Back arrow button */
        .back-btn {
            position: absolute;
            left: 20px;
            top: 20px;
            color: white;
            font-size: 22px;
            cursor: pointer;
            z-index: 10;
        }

        /* ── PROFILE PICTURE ── */
        .profile-picture {
            position: absolute;
            left: 50%;
            top: 90px;
            transform: translateX(-50%);
        }

        .profile-picture img {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            border: 6px solid white;
            object-fit: cover;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Edit photo button overlay */
        .edit-photo {
            position: absolute;
            right: 5px;
            bottom: 5px;
            background: #1dbf73;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        /* ── PROFILE INFO ── */
        .profile-info {
            padding-top: 90px;
            padding-bottom: 35px;
            text-align: center;
        }

        .profile-info h2  { font-size: 22px; }
        .profile-info span { font-size: 14px; color: #666; }

        /* Stats row (college, orders, posts) */
        .stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-top: 20px;
            font-size: 14px;
            color: #444;
        }

        .stats div {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Edit profile button */
        .edit-btn {
            position: absolute;
            right: 30px;
            bottom: 25px;
            padding: 8px 18px;
            border-radius: 20px;
            border: none;
            background: #1dbf73;
            color: white;
            cursor: pointer;
            font-weight: 500;
        }

        /* ── POST BOX ── */
        .post-box {
            background: white;
            border-radius: 20px;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .post-box input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 14px;
            background: #f1f4f3;
            padding: 10px 15px;
            border-radius: 20px;
        }

        .post-icons {
            display: flex;
            gap: 12px;
            font-size: 20px;
            color: #555;
            cursor: pointer;
        }

        /* ── POSTS LIST ── */
        .posts {
            background: white;
            border-radius: 25px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .post {
            margin-bottom: 25px;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 15px;
        }

        .post-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .post-user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Small avatar in post */
        .post-user-info img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
        }

        .user-details b {
            display: block;
            font-size: 15px;
            line-height: 1.2;
        }

        .user-details small {
            color: #777;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .post-content p   { font-size: 14px; margin-bottom: 10px; }
        .post-content img { width: 100%; border-radius: 15px; margin-top: 5px; }

        .post-actions {
            margin-top: 12px;
            display: flex;
            gap: 20px;
            font-size: 18px;
            color: #555;
            cursor: pointer;
        }
    </style>
</head>
<body>

<div class="container">

    <!-- ── PROFILE HEADER ── -->
    <div class="profile-header">

        <!-- Back button navigates to the correct dashboard -->
        <div class="back-btn" onclick="location.href='<?= $backPage ?>'" style="cursor:pointer;">
            <i class="bi bi-arrow-left"></i>
        </div>

        <div class="profile-banner">
            Animeals Logo
        </div>

        <!-- Profile picture with edit overlay -->
        <div class="profile-picture">
            <img src="<?= $profilePic ?>" alt="Profile">
            <div class="edit-photo">
                <i class="bi bi-pencil"></i>
            </div>
        </div>

        <!-- Name, identifier, and stats -->
        <div class="profile-info">
            <h2><?= htmlspecialchars($user['userNAME']) ?></h2>
            <span><?= htmlspecialchars($user['userSTUDENTNUM'] ?? $user['userEMAIL']) ?></span>

            <div class="stats">
                <div><i class="bi bi-geo"></i> <?= htmlspecialchars($user['userCOLLEGE'] ?? 'N/A') ?></div>
                <div><i class="bi bi-basket"></i> Total Orders: 0</div>
                <div><i class="bi bi-file-post"></i> 0 Posts</div>
            </div>
        </div>

        <button class="edit-btn">Edit</button>
    </div>

    <!-- ── CREATE POST BOX ── -->
    <div class="post-box">
        <img src="<?= $profilePic ?>" alt="Profile" style="width:40px; height:40px; border-radius:50%;">
        <input type="text" placeholder="What's on your mind">
        <div class="post-icons">
            <i class="bi bi-camera"></i>
            <i class="bi bi-image"></i>
        </div>
    </div>

    <!-- ── POSTS LIST ── -->
    <div class="posts">
        <h3 style="margin-bottom:15px;">Posts</h3>

        <!-- Single post -->
        <div class="post">
            <div class="post-header">
                <div class="post-user-info">
                    <img src="<?= $profilePic ?>" alt="Profile">
                    <div class="user-details">
                        <b><?= htmlspecialchars($user['userNAME']) ?></b>
                        <small>March 12 at 5:18 PM · <i class="bi bi-lock-fill"></i></small>
                    </div>
                </div>
                <div class="post-menu">
                    <i class="bi bi-three-dots"></i>
                </div>
            </div>

            <div class="post-content">
                <p>Ang sarap ng sisig sa Hiraya! Must try this, guys!!!.</p>
                <img src="https://www.ajinomoto.com.ph/ajinomoto-static/web/wp-content/uploads/2018/11/Pork-Sisig-2.jpg" alt="Post image">
            </div>

            <div class="post-actions">
                <i class="bi bi-heart"></i>
                <i class="bi bi-chat"></i>
                <i class="bi bi-share"></i>
            </div>
        </div>

    </div><!-- end .posts -->

</div><!-- end .container -->

</body>
</html>
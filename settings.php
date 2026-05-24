<?php
// THIS FILE STORES APP SETTINGS LIKE PAYMENT KEYS AND SHARED CONFIG VALUES.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'], $_SESSION['email'])) {
    header("Location: index.php");
    exit();
}

$serverName = "SatanaelLG\MSSQLSERVER01";
$connOptions = ["Database" => "ANIMEALS", "Uid" => "", "PWD" => ""];
$conn = mysqlsrv_connect($serverName, $connOptions);

if ($conn === false) {
    die("Database connection failed.");
}

$stmt = mysqlsrv_query($conn, "SELECT * FROM USER_DETAILS WHERE userEMAIL = ?", [$_SESSION['email']]);
$user = $stmt ? mysqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;

if (!$user || ($user['userROLE'] ?? '') !== 'student') {
    header("Location: student.php");
    exit();
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$studentID = (int) $user['userID'];
$profilePic = !empty($user['userPROFILEPIC'])
    ? h($user['userPROFILEPIC'])
    : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';

$flash = '';
$flashType = 'success';

if (isset($_GET['saved'])) {
    $flash = 'Profile updated successfully!';
    $flashType = 'success';
}

// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'updateProfile') {
    $userName = trim((string) ($_POST['userNAME'] ?? ''));
    $userPhone = trim((string) ($_POST['userPHONE'] ?? ''));
    $userAddress = trim((string) ($_POST['userADDRESS'] ?? ''));
    $userCity = trim((string) ($_POST['userCITY'] ?? ''));

    if (empty($userName)) {
        $flash = 'Name is required.';
        $flashType = 'error';
    } else {
        $updateResult = mysqlsrv_query(
            $conn,
            "UPDATE USER_DETAILS SET userNAME = ?, userPHONE = ?, userADDRESS = ?, userCITY = ? WHERE userID = ?",
            [
                $userName,
                $userPhone !== '' ? $userPhone : null,
                $userAddress !== '' ? $userAddress : null,
                $userCity !== '' ? $userCity : null,
                $studentID
            ]
        );

        if ($updateResult === false) {
            $flash = 'Failed to update profile.';
            $flashType = 'error';
        } else {
            header("Location: settings.php?saved=profile");
            exit();
        }
    }
}

// Handle password changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'updatePassword') {
    $oldPassword = (string) ($_POST['oldPassword'] ?? '');
    $newPassword = (string) ($_POST['newPassword'] ?? '');
    $confirmPassword = (string) ($_POST['confirmPassword'] ?? '');

    if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
        $flash = 'All password fields are required.';
        $flashType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $flash = 'New passwords do not match.';
        $flashType = 'error';
    } elseif (strlen($newPassword) < 6) {
        $flash = 'New password must be at least 6 characters.';
        $flashType = 'error';
    } elseif (!password_verify($oldPassword, $user['userPASSWORD'] ?? '')) {
        $flash = 'Old password is incorrect.';
        $flashType = 'error';
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateResult = mysqlsrv_query(
            $conn,
            "UPDATE USER_DETAILS SET userPASSWORD = ? WHERE userID = ?",
            [$hashedPassword, $studentID]
        );

        if ($updateResult === false) {
            $flash = 'Failed to update password.';
            $flashType = 'error';
        } else {
            $flash = 'Password updated successfully!';
            $flashType = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS | Student Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #fdfaf7; min-height: 100vh; }

        .settings-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: 100vh;
        }

        /* ========== SIDEBAR ========== */
        .sidebar {
            background: linear-gradient(180deg, #6730ff, #8153ff);
            color: #fff;
            padding: 25px 18px;
            display: flex;
            flex-direction: column;
            border-top-right-radius: 35px;
            border-bottom-right-radius: 35px;
            box-shadow: 5px 0 15px rgba(0,0,0,0.1);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 24px;
            font-weight: 800;
            padding-left: 10px;
            margin-bottom: 20px;
        }
        .brand img {
            width: 38px;
            height: 38px;
            object-fit: contain;
            border-radius: 0;
            background: transparent;
            padding: 0;
        }

        .profile-section { text-align: center; padding: 10px 0; margin-bottom: 20px; }
        .profile-section img { width: 80px; height: 80px; border-radius: 50%; border: 3px solid #fff; margin-bottom: 10px; object-fit: cover; }
        .profile-section h4 { font-size: 14px; margin: 0; color: #fff; }
        .profile-section small { font-size: 12px; color: rgba(255,255,255,0.8); display: block; margin-top: 4px; }

        .menu { display: flex; flex-direction: column; gap: 5px; margin-top: 15px; }
        .menu a {
            text-decoration: none;
            color: #fff;
            padding: 12px 15px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            font-size: 14px;
            transition: .25s;
            cursor: pointer;
        }
        .menu a:hover, .menu a.active { background: rgba(255,255,255,0.25); }
        .menu a i { margin-right: 12px; font-size: 18px; }

        .logout-btn {
            margin-top: auto;
            background: rgba(0,0,0,0.1);
            text-align: center;
            padding: 12px;
            border-radius: 15px;
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }
        .logout-btn:hover { background: #ff4d4d; }

        /* ========== MAIN CONTENT ========== */
        .main { padding: 40px; display: flex; flex-direction: column; gap: 30px; }

        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .topbar h1 { font-size: 28px; font-weight: 800; color: #333; }
        .back-btn { background: #fff; border: 1px solid #ddd; padding: 10px 20px; border-radius: 12px; text-decoration: none; color: #6730ff; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .back-btn:hover { background: #f5f5f5; }

        .flash-message {
            padding: 14px 18px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .flash-message.success { background: linear-gradient(135deg,#2ecc71,#0e6e36); color: #fff; }
        .flash-message.error { background: linear-gradient(135deg,#ff6b6b,#d32f2f); color: #fff; }
        .flash-message button { background: rgba(255,255,255,0.25); border: none; color: #fff; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; font-size: 16px; }

        /* Settings layout */
        .settings-layout {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 0;
            background: #fff;
            border-radius: 20px;
            border: 1px solid #eee;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            overflow: hidden;
            min-height: 500px;
        }

        .settings-nav {
            background: #fafafa;
            border-right: 1px solid #eee;
            display: flex;
            flex-direction: column;
            padding: 8px 0;
            overflow-y: auto;
        }

        .settings-nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 20px;
            font-size: 14px;
            font-weight: 600;
            color: #777;
            cursor: pointer;
            transition: 0.25s;
            border-left: 3px solid transparent;
        }

        .settings-nav-item i { font-size: 18px; color: #aaa; transition: 0.25s; }
        .settings-nav-item:hover { background: #f0f0f0; color: #6730ff; }
        .settings-nav-item:hover i { color: #6730ff; }
        .settings-nav-item.active {
            background: #f0f0f0;
            color: #6730ff;
            font-weight: 700;
            border-left: 3px solid #6730ff;
        }
        .settings-nav-item.active i { color: #6730ff; }

        .settings-content {
            overflow-y: auto;
            padding: 28px 32px;
            background: #fff;
        }

        .settings-panel { display: none; flex-direction: column; gap: 20px; }
        .settings-panel.active { display: flex; }

        .settings-panel-title {
            font-size: 22px;
            font-weight: 800;
            color: #222;
            margin-bottom: 2px;
        }

        .settings-panel-subtitle {
            font-size: 13px;
            color: #999;
            margin-bottom: 20px;
        }

        .settings-section { background: #fafafa; border-radius: 18px; padding: 22px; border: 1px solid #eee; display: flex; flex-direction: column; gap: 16px; }
        .settings-section-title { font-size: 13px; font-weight: 800; color: #6730ff; text-transform: uppercase; letter-spacing: 1px; }

        .settings-field { display: flex; flex-direction: column; gap: 6px; }
        .settings-field label { font-size: 12px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
        .settings-field input, .settings-field select, .settings-field textarea {
            width: 100%; padding: 10px 14px; border-radius: 12px; border: 1.5px solid #ddd;
            font-size: 14px; color: #333; outline: none; transition: 0.3s; background: #fff;
        }
        .settings-field input:focus, .settings-field select:focus, .settings-field textarea:focus {
            border-color: #6730ff; box-shadow: 0 0 0 3px rgba(103,48,255,0.1);
        }

        .settings-field textarea { resize: vertical; min-height: 70px; }

        .save-btn {
            background: linear-gradient(135deg,#6730ff,#8153ff); color: #fff; border: none;
            padding: 12px; border-radius: 14px; font-weight: 800; font-size: 14px; cursor: pointer;
            transition: 0.3s; width: 100%; box-shadow: 0 6px 18px rgba(103,48,255,0.3);
        }
        .save-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(103,48,255,0.4); }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #ddd; border-radius: 10px; }
    </style>
</head>
<body>

<div class="settings-container">
    <!-- ========== SIDEBAR ========== -->
    <div class="sidebar">
        <div class="brand"><img src="logo.png?v=transparent" alt="ANIMEALS Logo"> ANIMEALS</div>

        <div class="profile-section">
            <img src="<?= $profilePic ?>" alt="Profile" style="cursor:pointer;">
            <h4><?= h($user['userNAME']) ?></h4>
            <small><?= h($user['userSTUDENTNUM'] ?? $user['userEMAIL']) ?></small>
        </div>

        <div class="menu">
            <a href="student.php"><i class="bi bi-grid"></i> Dashboard</a>
            <a href="settings.php" class="active"><i class="bi bi-gear"></i> Settings</a>
            <a href="feed.php"><i class="bi bi-rss"></i> Food Feed</a>
        </div>

        <a href="logout.php" class="logout-btn">
            <i class="bi bi-box-arrow-right"></i> Log out
        </a>
    </div>

    <!-- ========== MAIN CONTENT ========== -->
    <div class="main">
        <div class="topbar">
            <h1>Settings</h1>
            <a href="student.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
        </div>

        <?php if (!empty($flash)): ?>
        <div class="flash-message <?= $flashType ?>">
            <span><?php echo ($flashType === 'success' ? '✓ ' : '✗ ') . h($flash); ?></span>
            <button type="button" onclick="this.parentElement.remove()"></button>
        </div>
        <?php endif; ?>

        <!-- Settings Layout -->
        <div class="settings-layout">
            <!-- Left Navigation -->
            <div class="settings-nav">
                <div class="settings-nav-item active" onclick="setSettingsPanel(this, 'panel-account')">
                    <i class="bi bi-person-circle"></i> Account
                </div>
                <div class="settings-nav-item" onclick="setSettingsPanel(this, 'panel-password')">
                    <i class="bi bi-lock"></i> Password
                </div>
            </div>

            <!-- Right Content -->
            <div class="settings-content">
                <!-- Account Panel -->
                <div class="settings-panel active" id="panel-account">
                    <div>
                        <h2 class="settings-panel-title">Account Settings</h2>
                        <p class="settings-panel-subtitle">Update your personal information</p>
                    </div>

                    <form method="POST" style="display: flex; flex-direction: column; gap: 20px;">
                        <input type="hidden" name="action" value="updateProfile">

                        <div class="settings-section">
                            <div class="settings-field">
                                <label>Full Name</label>
                                <input type="text" name="userNAME" value="<?= h($user['userNAME']) ?>" required>
                            </div>

                            <div class="settings-field">
                                <label>Email Address</label>
                                <input type="email" value="<?= h($user['userEMAIL']) ?>" disabled style="background: #f5f5f5; color: #999;">
                                <small style="color: #999;">Email cannot be changed</small>
                            </div>

                            <div class="settings-field">
                                <label>Student ID</label>
                                <input type="text" value="<?= h($user['userSTUDENTNUM'] ?? 'N/A') ?>" disabled style="background: #f5f5f5; color: #999;">
                            </div>
                        </div>

                        <div class="settings-section">
                            <div class="settings-field">
                                <label>Phone Number</label>
                                <input type="tel" name="userPHONE" value="<?= h($user['userPHONE'] ?? '') ?>" placeholder="Enter phone number">
                            </div>

                            <div class="settings-field">
                                <label>Address</label>
                                <input type="text" name="userADDRESS" value="<?= h($user['userADDRESS'] ?? '') ?>" placeholder="Enter address">
                            </div>

                            <div class="settings-field">
                                <label>City</label>
                                <input type="text" name="userCITY" value="<?= h($user['userCITY'] ?? '') ?>" placeholder="Enter city">
                            </div>
                        </div>

                        <button type="submit" class="save-btn">Save Changes</button>
                    </form>
                </div>

                <!-- Password Panel -->
                <div class="settings-panel" id="panel-password">
                    <div>
                        <h2 class="settings-panel-title">Change Password</h2>
                        <p class="settings-panel-subtitle">Update your password to keep your account secure</p>
                    </div>

                    <form method="POST" style="display: flex; flex-direction: column; gap: 20px;">
                        <input type="hidden" name="action" value="updatePassword">

                        <div class="settings-section">
                            <div class="settings-field">
                                <label>Current Password</label>
                                <input type="password" name="oldPassword" placeholder="Enter current password" required>
                            </div>

                            <div class="settings-field">
                                <label>New Password</label>
                                <input type="password" name="newPassword" placeholder="Enter new password" required>
                            </div>

                            <div class="settings-field">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirmPassword" placeholder="Confirm new password" required>
                            </div>
                        </div>

                        <button type="submit" class="save-btn">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function setSettingsPanel(element, panelId) {
        // Remove active from all nav items
        document.querySelectorAll('.settings-nav-item').forEach(el => el.classList.remove('active'));
        // Add active to clicked item
        element.classList.add('active');

        // Hide all panels
        document.querySelectorAll('.settings-panel').forEach(el => el.classList.remove('active'));
        // Show selected panel
        document.getElementById(panelId).classList.add('active');
    }
</script>

</body>
</html>

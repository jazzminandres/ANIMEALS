<?php
session_start();


$serverName = "SatanaelLG\MSSQLSERVER01";
$connectionOptions = [
    "Database" => "ANIMEALS",
    "Uid" => "",
    "PWD" => ""
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}

$googleName    = $_SESSION['google_name']    ?? '';
$googleEmail   = $_SESSION['google_email']   ?? '';
$googlePicture = $_SESSION['google_picture'] ?? '';

$message = '';
$messageType = 'danger';

/* ---------- SIGNUP + PROFILE SETUP SUBMIT ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setupBtn'])) {

    $fullname   = trim($_POST['signupName']   ?? '');
    $emailuser  = trim($_POST['signupEmail']  ?? '');
    $password   = $_POST['signupPass']        ?? '';
    $role       = trim($_POST['role']         ?? '');
    $phone      = trim($_POST['phone']        ?? '');
    $gender     = trim($_POST['gender']       ?? '');

    // Role-specific
    $college    = trim($_POST['college']      ?? '');
    $studentNum = trim($_POST['studentNum']   ?? '');
    $shopName   = trim($_POST['shopName']     ?? '');
    $adminDoc   = '';

    if ($fullname && $emailuser && $password && $role) {

        /* -- Profile picture upload -- */
        $profilePicPath = '';
        if (!empty($_FILES['profilePic']['name'])) {
            $uploadDir = 'uploads/profiles/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['profilePic']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('prof_') . '.' . $ext;
            if (move_uploaded_file($_FILES['profilePic']['tmp_name'], $uploadDir . $filename)) {
                $profilePicPath = $uploadDir . $filename;
            }
        }

        /* -- Seller: Business Permit upload -- */
        $businessPermitPath = '';
        if (!empty($_FILES['businessPermit']['name'])) {
            $uploadDir = 'uploads/documents/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['businessPermit']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('permit_') . '.' . $ext;
            if (move_uploaded_file($_FILES['businessPermit']['tmp_name'], $uploadDir . $filename)) {
                $businessPermitPath = $uploadDir . $filename;
            }
        }

        /* -- Seller: Valid ID upload -- */
        $validIdPath = '';
        if (!empty($_FILES['validId']['name'])) {
            $uploadDir = 'uploads/documents/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['validId']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('validid_') . '.' . $ext;
            if (move_uploaded_file($_FILES['validId']['tmp_name'], $uploadDir . $filename)) {
                $validIdPath = $uploadDir . $filename;
            }
        }

        /* -- Admin: Admin ID Document upload -- */
        $adminDocPath = '';
        if (!empty($_FILES['adminDoc']['name'])) {
            $uploadDir = 'uploads/documents/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['adminDoc']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('admindoc_') . '.' . $ext;
            if (move_uploaded_file($_FILES['adminDoc']['tmp_name'], $uploadDir . $filename)) {
                $adminDocPath = $uploadDir . $filename;
            }
        }
        /* -- Insert into USER_DETAILS -- */
        $sql    = "INSERT INTO USER_DETAILS (userNAME, userPASSWORD, userEMAIL, userROLE, userPHONE, userGENDER, userCOLLEGE, userSTUDENTNUM, userSHOPNAME, userPROFILEPIC, userBUSINESSPERMIT, userVALIDID, userADMINDOC)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [
            $fullname, $password, $emailuser, $role,
            $phone, $gender, $college, $studentNum,
            $shopName, $profilePicPath,
            $businessPermitPath, $validIdPath, $adminDocPath
        ];

        $result = sqlsrv_query($conn, $sql, $params);

        if ($result) {
            $_SESSION['user']  = $fullname;
            $_SESSION['email'] = $emailuser;
            // Redirect to dashboard after successful signup
            unset($_SESSION['google_name'], $_SESSION['google_email'], $_SESSION['google_picture']);

$_SESSION['role'] = $role;

if ($role === 'admin') {
    header("Location: admin.php");
} elseif ($role === 'seller') {
    header("Location: seller.php");
} else {
    header("Location: student.php");
}
exit();
        } else {
            $message     = "Error: That email or username may already exist.";
            $messageType = 'danger';
        }

    } else {
        $message     = "Please fill in all required fields (Name, Email, Password, and Role).";
        $messageType = 'warning';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS | Profile Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; font-family:'Outfit',sans-serif; }

        :root {
            --green: #10b981;
            --dark-green: #0f7a4a;
            --light-bg: #fdfaf7;
            --card-bg: #fff;
            --border: #eee;
            --muted: #999;
            --text: #2d2d2d;
        }

        body {
            background: var(--light-bg);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 90px 20px 40px;
            color: var(--text);
        }

        /* ── CARD ── */
        .setup-box {
            background: var(--card-bg);
            padding: 35px;
            border-radius: 35px;
            box-shadow: 0 15px 35px rgba(0,0,0,.07);
            width: 100%;
            max-width: 500px;
            position: relative;
            margin-top: 60px;
        }

        .upload-success {
    font-size: 11px;
    color: var(--green);
    font-weight: 700;
    margin-top: 6px;
    display: none;
}
.upload-success.show { display: block; }
.upload-btn.uploaded {
    border-color: var(--green);
    background: #d1fae5;
    color: var(--dark-green);
}

        /* ── AVATAR ── */
        .profile-header {
            position: absolute;
            top: -60px;
            left: 50%;
            transform: translateX(-50%);
            width: 120px;
            height: 120px;
        }

        .profile-img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 6px solid #fff;
            background: #eee;
            box-shadow: 0 5px 15px rgba(0,0,0,.12);
        }

        .edit-icon {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: var(--green);
            color: white;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid #fff;
            font-size: 14px;
        }

        /* ── TITLE ── */
        .title-area { text-align: center; margin-top: 50px; margin-bottom: 22px; }
        .title-area h2 { color: var(--dark-green); font-weight: 800; }
        .title-area p  { font-size: 13px; color: var(--muted); }

        /* ── ALERTS ── */
        .alert {
            padding: 10px 15px;
            border-radius: 14px;
            font-size: 13px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        .alert-danger  { background: #ffeaea; color: #c0392b; border: 1.5px solid #f5b7b1; }
        .alert-warning { background: #fff8e1; color: #b7950b; border: 1.5px solid #f9e79f; }
        .alert-success { background: #eafaf1; color: #1e8449; border: 1.5px solid #a9dfbf; }

        /* ── ROLE CARDS ── */
        .role-row {
            display: grid;
            grid-template-columns: repeat(3,1fr);
            gap: 10px;
            margin-bottom: 22px;
        }

        .role-card {
            background: #f9f9f9;
            border: 2px solid var(--border);
            padding: 12px;
            border-radius: 18px;
            text-align: center;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            transition: .25s;
            user-select: none;
        }

        .role-card.active { background: var(--green); color: #fff; border-color: var(--green); }

        /* ── FORM ELEMENTS ── */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
        .form-full  { width: 100%; margin-bottom: 14px; }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 800;
            font-size: 11px;
            color: #b0b0b0;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        input[type=text],
        input[type=email],
        input[type=tel],
        input[type=password],
        select {
            width: 100%;
            padding: 12px;
            background: #fcfcfc;
            border: 1.5px solid var(--border);
            border-radius: 15px;
            outline: none;
            font-size: 14px;
            transition: border-color .2s;
        }

        input:focus, select:focus { border-color: var(--green); }

        /* password wrapper */
        .pass-wrapper { position: relative; }
        .eye-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            font-size: 17px;
        }

        /* ── GENDER ── */
        .gender-row { display: flex; gap: 10px; }
        .gender-item {
            flex: 1;
            border: 1.5px solid var(--border);
            border-radius: 15px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            background: #fdfdfd;
            transition: .2s;
        }
        .gender-item svg   { width: 24px; height: 24px; fill: #ccc; margin-bottom: 4px; }
        .gender-item span  { font-size: 10px; font-weight: 800; color: #ccc; }
        .gender-item.active { border-color: var(--green); background: #f0faf7; }
        .gender-item.active svg  { fill: var(--green); }
        .gender-item.active span { color: var(--green); }

        /* ── FILE UPLOAD ── */
        .upload-wrapper { position: relative; overflow: hidden; }
        .upload-btn {
            border: 1.5px dashed var(--green);
            color: var(--green);
            background: #f0faf7;
            padding: 10px;
            border-radius: 15px;
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            display: block;
            cursor: pointer;
        }
        .upload-wrapper input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

        /* ── SUBMIT ── */
        .submit-btn {
            width: 100%;
            background: var(--green);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 18px;
            font-weight: 800;
            font-size: 15px;
            cursor: pointer;
            margin-top: 10px;
            transition: .3s;
        }
        .submit-btn:hover { background: var(--dark-green); transform: translateY(-2px); }
        .submit-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        /* ── HIDDEN ROLE INPUT ── */
        #roleInput { display: none; }

        /* ── GENDER HIDDEN INPUT ── */
        #genderInput { display: none; }

        /* ── MODAL ── */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            display: none;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
            z-index: 100;
        }
        .popup-card {
            background: #fff;
            padding: 35px;
            border-radius: 30px;
            text-align: center;
            width: 300px;
            animation: popIn .4s cubic-bezier(.175,.885,.32,1.275);
        }
        @keyframes popIn {
            from { opacity:0; transform:scale(.8); }
            to   { opacity:1; transform:scale(1);  }
        }

        /* ── DYNAMIC FIELDS ANIMATION ── */
        #dynamicFields { transition: opacity .3s; }
        #dynamicFields.loading { opacity: 0; }
    </style>
</head>
<body>

<div class="setup-box">

    <!-- Avatar -->
    <div class="profile-header">
        <img src="<?= $googlePicture ?: 'https://cdn-icons-png.flaticon.com/512/149/149071.png' ?>"
     id="avatarPreview" class="profile-img" alt="Profile">        <label class="edit-icon" title="Change photo">
            <i class="bi bi-pencil-fill"></i>
            <input type="file" name="profilePicPreview" hidden accept="image/*" onchange="previewAvatar(event)">
        </label>
    </div>

    <div class="title-area">
        <h2>Profile Setup</h2>
        <p>Create your account to continue</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Role Selector -->
    <div class="role-row">
        <div class="role-card" onclick="setRole('student', this)">Student</div>
        <div class="role-card" onclick="setRole('seller',  this)">Seller</div>
        <div class="role-card" onclick="setRole('admin',   this)">Admin</div>
    </div>

    <!-- Main Form -->
    <form method="POST" enctype="multipart/form-data" id="setupForm">

        <!-- Hidden: real profile pic file input -->
        <input type="file" name="profilePic" id="profilePicReal" accept="image/*" style="display:none">

        <!-- Hidden role & gender values -->
        <input type="hidden" name="role"   id="roleInput">
        <input type="hidden" name="gender" id="genderInput">

        <!-- Signup fields (always present) -->
        <!-- NEW -->
<div class="form-grid">
    <div>
        <label>Full Name</label>
        <input type="text" name="signupName" placeholder="Your Name" required
               value="<?= htmlspecialchars($googleName) ?>">
    </div>
    <div>
        <label>Email</label>
        <input type="email" name="signupEmail" placeholder="email@sample.com" required
               value="<?= htmlspecialchars($googleEmail) ?>"
               <?= $googleEmail ? 'readonly style="background:#f0faf7;color:#888;"' : '' ?>>
    </div>
</div>

        <!-- NEW -->
<div class="form-full" id="passwordField" <?= $googleEmail ? 'style="display:none"' : '' ?>>
    <label>Password</label>
    <div class="pass-wrapper">
        <input type="password" name="signupPass" id="signupPass"
               placeholder="Min. 9 characters"
               <?= $googleEmail ? '' : 'required' ?>
               value="<?= $googleEmail ? bin2hex(random_bytes(5)) : '' ?>">
        <i class="bi bi-eye-slash eye-icon" onclick="togglePass('signupPass', this)"></i>
    </div>
</div>

        <div class="form-full">
            <label>Phone Number</label>
            <input type="tel" name="phone" placeholder="09xxxxxxxxx" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,'')">


        </div>

        <!-- Gender picker -->
        <div class="form-full">
            <label>Gender</label>
            <div class="gender-row">
                <div class="gender-item" onclick="pickGender('Male', this)">
                    <svg viewBox="0 0 24 24"><path d="M12,2C10.34,2 9,3.34 9,5C9,6.66 10.34,8 12,8C13.66,8 15,6.66 15,5C15,3.34 13.66,2 12,2M10.5,22H13.5V15H14.5L14.75,10.23C14.89,7.66 13.11,5.5 10.5,5.5C7.89,5.5 6.11,7.66 6.25,10.23L6.5,15H7.5V22H10.5Z"/></svg>
                    <span>MALE</span>
                </div>
                <div class="gender-item" onclick="pickGender('Female', this)">
                    <svg viewBox="0 0 24 24"><path d="M12,2C10.34,2 9,3.34 9,5C9,6.66 10.34,8 12,8C13.66,8 15,6.66 15,5C15,3.34 13.66,2 12,2M18,10.5C18,8.57 16.43,7 14.5,7H9.5C7.57,7 6,8.57 6,10.5L7,16H9V22H15V16H17L18,10.5Z"/></svg>
                    <span>FEMALE</span>
                </div>
            </div>
        </div>

        <!-- Dynamic role-specific fields -->
        <div id="dynamicFields"></div>

        <button type="submit" name="setupBtn" class="submit-btn" id="doneBtn" style="display:none">
            CREATE ACCOUNT
        </button>
    </form>
</div>

<!-- Loading / Success Modal -->
<div class="overlay" id="modalOverlay">
    <div class="popup-card" id="popupInner">
        <div class="bi bi-hourglass-split" style="color:var(--green);font-size:40px;margin-bottom:10px;"></div>
        <h3>Creating account…</h3>
        <p style="color:#999;font-size:13px;">Please wait.</p>
    </div>
</div>

<script>
    function markUploaded(input, btnId, statusId) {
    const btn    = document.getElementById(btnId);
    const status = document.getElementById(statusId);
    if (input.files && input.files[0]) {
        const name = input.files[0].name;
        btn.classList.add('uploaded');
        btn.innerHTML = '<i class="bi bi-check-lg"></i> ' + name;
        status.querySelector('span').textContent = name + ' uploaded successfully';
        status.classList.add('show');
    }
}
    /* ── Avatar preview ── */
    function previewAvatar(e) {
        const file = e.target.files[0];
        if (!file) return;
        document.getElementById('avatarPreview').src = URL.createObjectURL(file);
        // Mirror to the real file input so PHP receives it
        const dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('profilePicReal').files = dt.files;
    }

    /* ── Password toggle ── */
    function togglePass(id, icon) {
        const inp = document.getElementById(id);
        if (inp.type === 'password') {
            inp.type = 'text';
            icon.classList.replace('bi-eye-slash','bi-eye');
        } else {
            inp.type = 'password';
            icon.classList.replace('bi-eye','bi-eye-slash');
        }
    }

    /* ── Gender picker ── */
    function pickGender(value, el) {
        document.querySelectorAll('.gender-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('genderInput').value = value;
    }

    /* ── Role picker + dynamic fields ── */
    function setRole(role, btn) {
        document.querySelectorAll('.role-card').forEach(r => r.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('roleInput').value = role;
        document.getElementById('doneBtn').style.display = 'block';

        let extra = '';
        if (role === 'student') {
            extra = `
                <div class="form-grid">
                    <div>
                        <label>College</label>
                        <select name="college">
                            <option value="">Select…</option>
                            <option>CEAT</option>
                            <option>CBAA</option>
                            <option>CTHM</option>
                            <option>CSCS</option>
                        </select>
                    </div>
                    <div>
                        <label>Student No.</label>
                        <input type="text" name="studentNum" placeholder="2024XXXX">
                    </div>
                </div>`;
        } else if (role === 'seller') {
    extra = `
        <div class="form-full">
            <label>Shop Name</label>
            <input type="text" name="shopName" placeholder="Green Store">
        </div>
        <div class="form-grid">
            <div>
                <label>Business Permit</label>
                <div class="upload-wrapper">
                    <span class="upload-btn" id="permitBtn"><i class="bi bi-upload"></i> Upload</span>
                    <input type="file" name="businessPermit" accept=".pdf,image/*" onchange="markUploaded(this, 'permitBtn', 'permitStatus')">
                </div>
                <div class="upload-success" id="permitStatus"><i class="bi bi-check-circle-fill"></i> <span></span></div>
            </div>
            <div>
                <label>Valid ID</label>
                <div class="upload-wrapper">
                    <span class="upload-btn" id="validIdBtn"><i class="bi bi-upload"></i> Upload</span>
                    <input type="file" name="validId" accept=".pdf,image/*" onchange="markUploaded(this, 'validIdBtn', 'validIdStatus')">
                </div>
                <div class="upload-success" id="validIdStatus"><i class="bi bi-check-circle-fill"></i> <span></span></div>
            </div>
        </div>`;
        } else if (role === 'admin') {
    extra = `
        <div class="form-full">
            <label>Admin ID Document</label>
            <div class="upload-wrapper">
                <span class="upload-btn" id="adminDocBtn"><i class="bi bi-upload"></i> Choose File</span>
                <input type="file" name="adminDoc" accept=".pdf,image/*" onchange="markUploaded(this, 'adminDocBtn', 'adminDocStatus')">
            </div>
            <div class="upload-success" id="adminDocStatus"><i class="bi bi-check-circle-fill"></i> <span></span></div>
        </div>`;
        }

        const df = document.getElementById('dynamicFields');
        df.classList.add('loading');
        setTimeout(() => {
            df.innerHTML = extra;
            df.classList.remove('loading');
        }, 150);
    }

    /* ── Show loading modal on submit ── */
document.getElementById('setupForm').addEventListener('submit', function(e) {
    const role = document.getElementById('roleInput').value;

    if (!role) {
        e.preventDefault();
        alert('Please select a role (Student, Seller, or Admin).');
        return;
    }

    if (role === 'seller') {
        const permit = document.querySelector('input[name="businessPermit"]');
        const validId = document.querySelector('input[name="validId"]');
        if (!permit || !permit.files[0]) {
            e.preventDefault();
            alert('Please upload your Business Permit.');
            return;
        }
        if (!validId || !validId.files[0]) {
            e.preventDefault();
            alert('Please upload your Valid ID.');
            return;
        }
    }

    if (role === 'admin') {
        const adminDoc = document.querySelector('input[name="adminDoc"]');
        if (!adminDoc || !adminDoc.files[0]) {
            e.preventDefault();
            alert('Please upload your Admin ID Document.');
            return;
        }
    }

    document.getElementById('modalOverlay').style.display = 'flex';
});
</script>

</body>
</html>
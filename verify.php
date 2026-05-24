<?php
// THIS FILE VERIFIES SIGNUP CODES AND FINALIZES NEW USER ACCOUNTS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

$conn = db_connect();
require_once __DIR__ . '/schema_bootstrap.php';
animeals_ensure_extensions($conn);

function createPendingTable(mysqli $conn): void
{
    // CREATE THE TEMPORARY SIGNUP TABLE IF THE DEPLOYED DATABASE DOES NOT HAVE IT YET.
    $conn->query(
        "CREATE TABLE IF NOT EXISTS signup_pending (
            pendingEMAIL VARCHAR(255) NOT NULL,
            pendingNAME VARCHAR(255) DEFAULT NULL,
            pendingPASS VARCHAR(255) DEFAULT NULL,
            pendingCODE VARCHAR(10) DEFAULT NULL,
            expiresAt DATETIME DEFAULT NULL,
            createdAt DATETIME DEFAULT NULL,
            PRIMARY KEY (pendingEMAIL)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}
createPendingTable($conn);

$message = '';
// ACCEPT THE EMAIL FROM EITHER THE URL OR THE FORM POST SO REFRESHES STILL WORK.
$email = trim($_GET['email'] ?? $_POST['verifyEmail'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verifyBtn'])) {
    // COMPARE THE ENTERED CODE AGAINST THE SAVED PENDING SIGNUP RECORD.
    $code = trim($_POST['verifyCode']);

    if ($email && $code) {
        $stmt = db_query($conn, "SELECT * FROM signup_pending WHERE pendingEMAIL = ?", [$email]);
        $row = $stmt ? db_fetch_assoc($stmt) : null;

        if ($row) {
            // EXPIRED CODES ARE REJECTED SO OLD EMAIL LINKS CANNOT CREATE ACCOUNTS LATER.
            $expiresAt = trim((string) ($row['expiresAt'] ?? ''));
            $expectedCode = trim((string) ($row['pendingCODE'] ?? ''));
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $expires = $expiresAt ? new DateTime($expiresAt, new DateTimeZone('UTC')) : null;

            if ($expires && $now > $expires) {
                $message = 'Verification code has expired. Please sign up again.';
            } elseif ($code === $expectedCode) {
                // MERGE DATABASE FALLBACK VALUES WITH THE RICH SESSION DATA COLLECTED DURING SETUP.
                $pendingData = $_SESSION['pending_signup'] ?? [];
                $name = $pendingData['userNAME'] ?? $row['pendingNAME'];
                $password = $pendingData['userPASSWORD'] ?? $row['pendingPASS'];
                $role = $pendingData['userROLE'] ?? 'student';
                $phone = $pendingData['userPHONE'] ?? '';
                $gender = $pendingData['userGENDER'] ?? '';
                $college = $pendingData['userCOLLEGE'] ?? '';
                $studentNum = $pendingData['userSTUDENTNUM'] ?? '';
                $shopName = $pendingData['userSHOPNAME'] ?? '';
                $profilePicPath = $pendingData['userPROFILEPIC'] ?? '';
                $businessPermitPath = $pendingData['userBUSINESSPERMIT'] ?? '';
                $validIdPath = $pendingData['userVALIDID'] ?? '';
                $adminDocPath = $pendingData['userADMINDOC'] ?? '';
                $sellerApprovalStatus = $role === 'seller'
                    ? ($pendingData['sellerApprovalStatus'] ?? 'pending')
                    : 'approved';

                // CREATE THE MAIN ANIMEALS ACCOUNT ONLY AFTER THE EMAIL CODE MATCHES.
                $insertSql = "INSERT INTO user_details (userNAME, userPASSWORD, userEMAIL, userROLE, userPHONE, userGENDER, userCOLLEGE, userSTUDENTNUM, userSHOPNAME, userPROFILEPIC, userBUSINESSPERMIT, userVALIDID, userADMINDOC, sellerApprovalStatus) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $insertParams = [
                    $name,
                    $password,
                    $row['pendingEMAIL'],
                    $role,
                    $phone,
                    $gender,
                    $college,
                    $studentNum,
                    $shopName,
                    $profilePicPath,
                    $businessPermitPath,
                    $validIdPath,
                    $adminDocPath,
                    $sellerApprovalStatus
                ];
                $insertResult = db_query($conn, $insertSql, $insertParams);

                if ($insertResult) {
                    if ($role === 'seller') {
                        // MIRROR SELLER ACCOUNTS INTO SELLER_DATA SO THE SELLER DASHBOARD CAN READ THEM.
                        $sellerConn = db_connect(DB_NAME_SELLER_DATA);
                        $newUserID = $conn->insert_id;
                        db_query(
                            $sellerConn,
                            "INSERT INTO user_details (
                                userID, userNAME, userPASSWORD, userEMAIL, userROLE, userPHONE, userGENDER,
                                userCOLLEGE, userSTUDENTNUM, userSHOPNAME, userPROFILEPIC, userBUSINESSPERMIT,
                                userVALIDID, userADMINDOC
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                userNAME = VALUES(userNAME),
                                userPASSWORD = VALUES(userPASSWORD),
                                userROLE = VALUES(userROLE),
                                userPHONE = VALUES(userPHONE),
                                userGENDER = VALUES(userGENDER),
                                userCOLLEGE = VALUES(userCOLLEGE),
                                userSTUDENTNUM = VALUES(userSTUDENTNUM),
                                userSHOPNAME = VALUES(userSHOPNAME),
                                userPROFILEPIC = VALUES(userPROFILEPIC),
                                userBUSINESSPERMIT = VALUES(userBUSINESSPERMIT),
                                userVALIDID = VALUES(userVALIDID),
                                userADMINDOC = VALUES(userADMINDOC)",
                            [
                                (int) $newUserID,
                                $name,
                                $password,
                                $row['pendingEMAIL'],
                                $role,
                                $phone,
                                $gender,
                                $college,
                                $studentNum,
                                $shopName,
                                $profilePicPath,
                                $businessPermitPath,
                                $validIdPath,
                                $adminDocPath,
                            ]
                        );
                    }

                    // CLEAN UP THE ONE-TIME PENDING RECORD AND START THE NORMAL LOGIN SESSION.
                    db_query($conn, "DELETE FROM signup_pending WHERE pendingEMAIL = ?", [$email]);
                    unset($_SESSION['pending_signup']);
                    $_SESSION['user'] = $name;
                    $_SESSION['email'] = $row['pendingEMAIL'];
                    $_SESSION['role'] = $role;
                    // RECORD THE SIGNUP COMPLETION FOR ADMIN AUDITING.
                    db_query(
                        $conn,
                        "INSERT INTO user_audit_log (actorID, actorEmail, targetUserID, targetEmail, action, details) VALUES (NULL, NULL, ?, ?, ?, ?)",
                        [(int) $conn->insert_id, $row['pendingEMAIL'], 'signup_verified', $role === 'seller' ? 'Seller email verified; awaiting admin approval.' : 'Email verified.']
                    );
                    if ($role === 'admin') {
                        // SEND EACH ROLE TO THE FIRST PAGE THEY SHOULD SEE AFTER VERIFICATION.
                        header('Location: admin.php');
                    } elseif ($role === 'seller') {
                        header('Location: seller_pending.php');
                    } else {
                        header('Location: student.php');
                    }
                    exit();
                }

                $message = 'Unable to complete verification. Please try again later.';
            } else {
                $message = 'The verification code is incorrect. Please check your email and try again.';
            }
        } else {
            $message = 'No pending signup found for this email. Please sign up again.';
        }
    } else {
        $message = 'Please enter your email and verification code.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Your Email</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #e8fff4; margin: 0; font-family: 'Segoe UI', sans-serif; }
.verify-box { width: 100%; max-width: 420px; padding: 32px; background: #fff; border-radius: 24px; box-shadow: 0 16px 50px rgba(0,0,0,.12); }
</style>
</head>
<body>
<div class="verify-box">
    <h3 class="mb-3">Verify your account</h3>
    <p class="text-muted mb-4">Enter the code sent to your email and finish account setup.</p>

    <?php if (!empty($message)): ?>
        <div class="alert alert-warning"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="POST" action="verify.php">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="verifyEmail" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="mb-4">
            <label class="form-label">Verification Code</label>
            <input type="text" class="form-control" name="verifyCode" placeholder="Enter code" required>
        </div>
        <button type="submit" name="verifyBtn" class="btn btn-success w-100">Verify Account</button>
    </form>
    <div class="mt-3 text-center">
        <a href="index.php">Back to login</a>
    </div>
</div>
</body>
</html>

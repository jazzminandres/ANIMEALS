<?php
session_start();
$serverName = "SatanaelLG\MSSQLSERVER01";
$connectionOptions = [
    "Database" => "Anne",
    "Uid" => "",
    "PWD" => ""
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) { 
    die(print_r(sqlsrv_errors(), true));
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $fullname = trim($_POST['fullname']);

    if ($username && $password && $role) {

        $sql = "INSERT INTO USERS (USERNAME, PASSHASH, RANK, FULLNAME) VALUES ('$username', '$password', '$role', '$fullname')";
        $result = sqlsrv_query($conn, $sql);

        if ($result) {
            $message = "Account created successfully!";
        } else {
            $message = "Error: Username may already exist.";
        }

    } else {
        $message = "Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register Account • Annie's Café</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
  --bg: #fff8f0;              /* soft cream background */
  --glass: rgba(255, 255, 255, 0.5); /* translucent glass card */
  --glass-hover: rgba(255, 255, 255, 0.65);
  --accent: #f2c77f;          /* warm accent */
  --accent-hover: #eab66b;
  --text: #6b4f3c;            /* soft brown text */
  --border: rgba(235, 200, 160, 0.5);
  --shadow: rgba(0,0,0,0.08);
}

body {
  background: var(--bg);
    font-family: 'Quicksand', sans-serif;
  color: var(--text);
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  margin: 0;
}

.card {
  background: var(--glass);
  backdrop-filter: blur(15px) saturate(150%);
  border: 1px solid var(--border);
  border-radius: 25px;
  padding: 35px 30px;
  box-shadow: 0 20px 50px var(--shadow);
  transition: transform 0.25s ease, background 0.25s ease;
}

.card:hover {
  background: var(--glass-hover);
  transform: translateY(-4px);
}

h2 {
  text-align: center;
  margin-bottom: 25px;
  color: var(--text);
  font-weight: 600;
}

.form-control {
  border-radius: 12px;
  border: 2px solid var(--border);
  background: rgba(255,255,255,0.6);
  color: var(--text);
  padding: 10px;
  margin-bottom: 15px;
  transition: all 0.3s;
}

.form-control:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(242,199,127,0.3);
  background: rgba(255,255,255,0.8);
  color: var(--text);
}

.btn-anne {
  background: linear-gradient(135deg, var(--accent), var(--accent-hover));
  color: #fff;
  font-weight: bold;
  border-radius: 999px;
  width: 100%;
  padding: 12px;
  margin-top: 10px;
  transition: all 0.3s ease;
  border: none;
}

.btn-anne:hover {
  background: var(--accent-hover);
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(242,199,127,0.3);
}

.text-center a {
  color: var(--accent);
  text-decoration: none;
  transition: color 0.3s;
}

.text-center a:hover {
  color: var(--accent-hover);
}
</style>
</head>
<body>
<div class="container d-flex justify-content-center align-items-center" style="min-height:100vh;">
  <div class="col-md-5">
    <div class="card">
      <h2>Create New Account</h2>

      <?php if ($message): ?>
        <div class="alert alert-warning text-center"><?= $message ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="mb-3">
          <label>Full Name</label>
          <input type="text" name="fullname" class="form-control" required>
        </div>
        <div class="mb-3">
          <label>Username</label>
          <input type="text" name="username" class="form-control" required>
        </div>
        <div class="mb-3">
          <label>Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <div class="mb-3">
          <label>Role</label>
          <select name="role" class="form-control" required>
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <button class="btn btn-anne w-100">Register</button>
      </form>

      <div class="text-center mt-3">
        <a href="login.php">← Back to Login</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>

<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP OAuth Lab</title>
</head>
<body>
    <h1>PHP OAuth Lab</h1>
    <?php if (isset($_SESSION['user'])): ?>
        <p> Hello, <?php echo $_SESSION['user']['name']; ?>!</p>
        <p> Email: <?php echo $_SESSION['user']['email']; ?>!</p>    
        <a href="logout.php">Logout</a>
    <?php else: ?>
        <a href="login.php">Login with Google</a>
    <?php endif; ?>
</body>
</html>
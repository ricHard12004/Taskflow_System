<?php
// logout.php
require_once 'config/database.php';

if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    logActivity($pdo, $user_id, 'LOGOUT', 'User logged out');
    
    // Clear remember me cookie
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/');
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
    }
}

// Destroy session
session_destroy();

displayFlashMessage('success', 'You have been logged out successfully.');
redirect('login.php');
?>
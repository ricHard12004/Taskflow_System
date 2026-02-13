<?php
// ajax/update_theme.php
require_once '../config/database.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    $theme = $_POST['theme'];
    $user_id = $_SESSION['user_id'];
    
    // Validate theme
    if (!in_array($theme, ['light', 'dark', 'auto'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid theme']);
        exit;
    }
    
    try {
        // Update user settings
        $stmt = $pdo->prepare("UPDATE user_settings SET theme = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$theme, $user_id]);
        
        // Update session
        $_SESSION['user_theme'] = $theme;
        $_SESSION['user_settings']['theme'] = $theme;
        
        echo json_encode(['success' => true, 'theme' => $theme]);
    } catch (PDOException $e) {
        error_log("Theme update error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
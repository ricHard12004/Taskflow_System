<?php
// ajax/update_setting.php
require_once '../config/database.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user_id = $_SESSION['user_id'];
$allowed_settings = [
    'theme', 'sidebar_position', 'sidebar_size', 'notifications_enabled',
    'email_notifications', 'task_reminders', 'due_date_reminder_days',
    'language', 'date_format', 'time_format', 'first_day_of_week', 'items_per_page'
];

$success = false;
$updated = [];

foreach ($_POST as $key => $value) {
    if (in_array($key, $allowed_settings)) {
        try {
            // Sanitize based on setting type
            if (in_array($key, ['notifications_enabled', 'email_notifications', 'task_reminders'])) {
                $value = $value ? 1 : 0;
            } elseif (in_array($key, ['due_date_reminder_days', 'items_per_page'])) {
                $value = (int)$value;
            } else {
                $value = sanitize($value);
            }
            
            $stmt = $pdo->prepare("UPDATE user_settings SET $key = ?, updated_at = NOW() WHERE user_id = ?");
            $stmt->execute([$value, $user_id]);
            
            // Update session
            $_SESSION['user_settings'][$key] = $value;
            if ($key === 'theme') {
                $_SESSION['user_theme'] = $value;
            }
            
            $updated[] = $key;
            $success = true;
        } catch (PDOException $e) {
            error_log("Update setting error: $key - " . $e->getMessage());
        }
    }
}

echo json_encode([
    'success' => $success,
    'updated' => $updated,
    'settings' => $_SESSION['user_settings'] ?? []
]);
?>
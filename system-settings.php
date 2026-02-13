<?php
// settings.php
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access settings.');
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Create user_settings table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL UNIQUE,
        `theme` ENUM('light', 'dark', 'auto') DEFAULT 'light',
        `sidebar_position` ENUM('left', 'right') DEFAULT 'left',
        `sidebar_size` ENUM('default', 'compact', 'wide') DEFAULT 'default',
        `notifications_enabled` BOOLEAN DEFAULT TRUE,
        `email_notifications` BOOLEAN DEFAULT TRUE,
        `task_reminders` BOOLEAN DEFAULT TRUE,
        `due_date_reminder_days` INT DEFAULT 2,
        `language` VARCHAR(10) DEFAULT 'en',
        `date_format` VARCHAR(20) DEFAULT 'M d, Y',
        `time_format` ENUM('12', '24') DEFAULT '12',
        `first_day_of_week` ENUM('0', '1') DEFAULT '1', -- 0=Sunday, 1=Monday
        `items_per_page` INT DEFAULT 20,
        `dashboard_widgets` JSON,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    // Table might already exist
    error_log("Settings table creation: " . $e->getMessage());
}

// Create system_settings table for global settings
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` TEXT,
        `setting_type` ENUM('text', 'number', 'boolean', 'json') DEFAULT 'text',
        `description` TEXT,
        `updated_by` INT,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Insert default system settings if not exists
    $default_settings = [
        ['site_name', 'TaskFlow Pro', 'text', 'System name displayed throughout the application'],
        ['company_name', 'TaskFlow Inc.', 'text', 'Company name for reports and emails'],
        ['admin_email', 'admin@taskflow.com', 'text', 'System administrator email'],
        ['allow_registration', 'true', 'boolean', 'Allow new user registrations'],
        ['require_approval', 'true', 'boolean', 'Require admin approval for new accounts'],
        ['maintenance_mode', 'false', 'boolean', 'Put system in maintenance mode'],
        ['maintenance_message', 'System is under scheduled maintenance.', 'text', 'Message shown during maintenance'],
        ['max_login_attempts', '5', 'number', 'Maximum failed login attempts before lockout'],
        ['lockout_duration', '15', 'number', 'Account lockout duration in minutes'],
        ['session_timeout', '30', 'number', 'Session timeout in minutes'],
        ['password_min_length', '8', 'number', 'Minimum password length'],
        ['password_require_uppercase', 'true', 'boolean', 'Require uppercase letters in passwords'],
        ['password_require_lowercase', 'true', 'boolean', 'Require lowercase letters in passwords'],
        ['password_require_numbers', 'true', 'boolean', 'Require numbers in passwords'],
        ['password_require_special', 'false', 'boolean', 'Require special characters in passwords'],
        ['default_user_role', 'member', 'text', 'Default role for new users'],
        ['enable_notifications', 'true', 'boolean', 'Enable system notifications'],
        ['notification_sound', 'true', 'boolean', 'Play sound for new notifications'],
        ['email_smtp_host', '', 'text', 'SMTP server hostname'],
        ['email_smtp_port', '587', 'number', 'SMTP server port'],
        ['email_smtp_encryption', 'tls', 'text', 'SMTP encryption (tls/ssl)'],
        ['email_smtp_username', '', 'text', 'SMTP username'],
        ['email_smtp_password', '', 'text', 'SMTP password'],
        ['email_from_address', 'noreply@taskflow.com', 'text', 'Default from email address'],
        ['email_from_name', 'TaskFlow Pro', 'text', 'Default from name for emails'],
        ['backup_enabled', 'false', 'boolean', 'Enable automatic database backups'],
        ['backup_frequency', 'daily', 'text', 'Backup frequency (daily/weekly/monthly)'],
        ['backup_retention', '30', 'number', 'Number of days to keep backups'],
        ['audit_log_retention', '90', 'number', 'Number of days to keep audit logs'],
    ];
    
    foreach ($default_settings as $setting) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, ?, ?)");
        $stmt->execute($setting);
    }
} catch (PDOException $e) {
    error_log("System settings table: " . $e->getMessage());
}

// Get or create user settings
try {
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_settings = $stmt->fetch();
    
    if (!$user_settings) {
        // Create default settings for user
        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, theme, sidebar_position, sidebar_size, notifications_enabled, email_notifications, task_reminders, due_date_reminder_days, language, date_format, time_format, first_day_of_week, items_per_page, dashboard_widgets) 
                              VALUES (?, 'light', 'left', 'default', 1, 1, 1, 2, 'en', 'M d, Y', '12', '1', 20, NULL)");
        $stmt->execute([$user_id]);
        
        // Fetch newly created settings
        $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_settings = $stmt->fetch();
    }
} catch (PDOException $e) {
    error_log("User settings error: " . $e->getMessage());
    $user_settings = [
        'theme' => 'light',
        'sidebar_position' => 'left',
        'sidebar_size' => 'default',
        'notifications_enabled' => 1,
        'email_notifications' => 1,
        'task_reminders' => 1,
        'due_date_reminder_days' => 2,
        'language' => 'en',
        'date_format' => 'M d, Y',
        'time_format' => '12',
        'first_day_of_week' => '1',
        'items_per_page' => 20
    ];
}

// Get system settings (admin only)
$system_settings = [];
if ($user_role === 'admin') {
    try {
        $stmt = $pdo->query("SELECT * FROM system_settings ORDER BY setting_key");
        $system_settings = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("System settings fetch error: " . $e->getMessage());
    }
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings_action = $_POST['settings_action'] ?? '';
    
    // Update user preferences
    if ($settings_action === 'update_preferences') {
        try {
            $theme = $_POST['theme'] ?? 'light';
            $sidebar_position = $_POST['sidebar_position'] ?? 'left';
            $sidebar_size = $_POST['sidebar_size'] ?? 'default';
            $notifications_enabled = isset($_POST['notifications_enabled']) ? 1 : 0;
            $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
            $task_reminders = isset($_POST['task_reminders']) ? 1 : 0;
            $due_date_reminder_days = (int)($_POST['due_date_reminder_days'] ?? 2);
            $language = $_POST['language'] ?? 'en';
            $date_format = $_POST['date_format'] ?? 'M d, Y';
            $time_format = $_POST['time_format'] ?? '12';
            $first_day_of_week = $_POST['first_day_of_week'] ?? '1';
            $items_per_page = (int)($_POST['items_per_page'] ?? 20);
            
            $stmt = $pdo->prepare("UPDATE user_settings SET 
                                  theme = ?, sidebar_position = ?, sidebar_size = ?,
                                  notifications_enabled = ?, email_notifications = ?, task_reminders = ?,
                                  due_date_reminder_days = ?, language = ?, date_format = ?,
                                  time_format = ?, first_day_of_week = ?, items_per_page = ?,
                                  updated_at = NOW()
                                  WHERE user_id = ?");
            $stmt->execute([$theme, $sidebar_position, $sidebar_size, 
                           $notifications_enabled, $email_notifications, $task_reminders,
                           $due_date_reminder_days, $language, $date_format,
                           $time_format, $first_day_of_week, $items_per_page, $user_id]);
            
            // Set session preference for theme
            $_SESSION['user_theme'] = $theme;
            
            logActivity($pdo, $user_id, 'SETTINGS_UPDATE', 'Updated user preferences');
            displayFlashMessage('success', 'Preferences updated successfully!');
            
        } catch (PDOException $e) {
            error_log("Preferences update error: " . $e->getMessage());
            displayFlashMessage('error', 'Unable to update preferences.');
        }
        redirect('settings.php');
    }
    
    // Update profile (simplified version from profile.php)
    elseif ($settings_action === 'update_profile') {
        $full_name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        
        $errors = [];
        
        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        }
        
        if (empty($email)) {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if (empty($errors)) {
            try {
                // Check if email exists for other users
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL");
                $stmt->execute([$email, $user_id]);
                if ($stmt->rowCount() > 0) {
                    displayFlashMessage('error', 'Email address is already in use.');
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$full_name, $email, $user_id]);
                    
                    $_SESSION['user_name'] = $full_name;
                    $_SESSION['user_email'] = $email;
                    
                    logActivity($pdo, $user_id, 'PROFILE_UPDATE', 'Updated profile information');
                    displayFlashMessage('success', 'Profile updated successfully!');
                }
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                displayFlashMessage('error', 'Unable to update profile.');
            }
        } else {
            displayFlashMessage('error', implode(' ', $errors));
        }
        redirect('settings.php');
    }
    
    // Change password
    elseif ($settings_action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Get current password hash
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $current_hash = $stmt->fetch()['password'];
        
        if (!password_verify($current_password, $current_hash)) {
            displayFlashMessage('error', 'Current password is incorrect.');
        } elseif (strlen($new_password) < PASSWORD_MIN_LENGTH) {
            displayFlashMessage('error', 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.');
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            displayFlashMessage('error', 'Password must contain at least one uppercase letter.');
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            displayFlashMessage('error', 'Password must contain at least one lowercase letter.');
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            displayFlashMessage('error', 'Password must contain at least one number.');
        } elseif ($new_password !== $confirm_password) {
            displayFlashMessage('error', 'New passwords do not match.');
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                logActivity($pdo, $user_id, 'PASSWORD_CHANGE', 'Changed password');
                displayFlashMessage('success', 'Password changed successfully!');
                
            } catch (PDOException $e) {
                error_log("Password change error: " . $e->getMessage());
                displayFlashMessage('error', 'Unable to change password.');
            }
        }
        redirect('settings.php');
    }
    
    // Update system settings (admin only)
    elseif ($settings_action === 'update_system' && $user_role === 'admin') {
        try {
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'setting_') === 0) {
                    $setting_key = substr($key, 8);
                    $setting_value = sanitize($value);
                    
                    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
                    $stmt->execute([$setting_value, $user_id, $setting_key]);
                }
            }
            
            logActivity($pdo, $user_id, 'SYSTEM_SETTINGS', 'Updated system settings');
            displayFlashMessage('success', 'System settings updated successfully!');
            
        } catch (PDOException $e) {
            error_log("System settings update error: " . $e->getMessage());
            displayFlashMessage('error', 'Unable to update system settings.');
        }
        redirect('settings.php');
    }
    
    // Reset to defaults
    elseif ($settings_action === 'reset_defaults') {
        try {
            $stmt = $pdo->prepare("UPDATE user_settings SET 
                                  theme = 'light', sidebar_position = 'left', sidebar_size = 'default',
                                  notifications_enabled = 1, email_notifications = 1, task_reminders = 1,
                                  due_date_reminder_days = 2, language = 'en', date_format = 'M d, Y',
                                  time_format = '12', first_day_of_week = '1', items_per_page = 20,
                                  updated_at = NOW()
                                  WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            $_SESSION['user_theme'] = 'light';
            
            logActivity($pdo, $user_id, 'SETTINGS_RESET', 'Reset settings to defaults');
            displayFlashMessage('success', 'Settings reset to defaults!');
            
        } catch (PDOException $e) {
            error_log("Settings reset error: " . $e->getMessage());
            displayFlashMessage('error', 'Unable to reset settings.');
        }
        redirect('settings.php');
    }
    
    // Upload avatar
    elseif ($settings_action === 'upload_avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = $_FILES['avatar']['type'];
            $file_size = $_FILES['avatar']['size'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($file_type, $allowed_types)) {
                displayFlashMessage('error', 'Only JPG, PNG, and GIF images are allowed.');
            } elseif ($file_size > $max_size) {
                displayFlashMessage('error', 'File size must be less than 2MB.');
            } else {
                $upload_dir = 'uploads/avatars/';
                $file_extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                $file_name = 'user_' . $user_id . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $file_path)) {
                    // Delete old avatar
                    $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $old_avatar = $stmt->fetch()['avatar'];
                    
                    if ($old_avatar && file_exists($old_avatar)) {
                        unlink($old_avatar);
                    }
                    
                    $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                    $stmt->execute([$file_path, $user_id]);
                    
                    logActivity($pdo, $user_id, 'AVATAR_UPDATE', 'Updated profile picture');
                    displayFlashMessage('success', 'Profile picture updated successfully!');
                } else {
                    displayFlashMessage('error', 'Failed to upload file.');
                }
            }
        } else {
            displayFlashMessage('error', 'Please select a file to upload.');
        }
        redirect('settings.php');
    }
    
    // Remove avatar
    elseif ($settings_action === 'remove_avatar') {
        try {
            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $avatar = $stmt->fetch()['avatar'];
            
            if ($avatar && file_exists($avatar)) {
                unlink($avatar);
            }
            
            $stmt = $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
            $stmt->execute([$user_id]);
            
            displayFlashMessage('success', 'Profile picture removed.');
            
        } catch (PDOException $e) {
            error_log("Avatar removal error: " . $e->getMessage());
            displayFlashMessage('error', 'Unable to remove profile picture.');
        }
        redirect('settings.php');
    }
}

// Format system settings for easy access
$sys_settings = [];
foreach ($system_settings as $setting) {
    $sys_settings[$setting['setting_key']] = $setting['setting_value'];
}

$title = 'Settings';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $user_settings['theme'] ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($title) ?> - TaskFlow</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <style id="theme-styles">
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            
            /* Light theme (default) */
            --bg-color: #f8f9fc;
            --card-bg: #ffffff;
            --text-color: #333333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --sidebar-bg: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            --sidebar-text: rgba(255,255,255,0.8);
            --sidebar-hover: rgba(255,255,255,0.1);
            --header-bg: #ffffff;
            --input-bg: #ffffff;
            --input-border: #dee2e6;
            --shadow-color: rgba(0,0,0,0.05);
        }
        
        /* Dark theme */
        [data-theme="dark"] {
            --bg-color: #1a1e21;
            --card-bg: #2c3034;
            --text-color: #e9ecef;
            --text-muted: #adb5bd;
            --border-color: #495057;
            --sidebar-bg: #212529;
            --sidebar-text: #e9ecef;
            --sidebar-hover: rgba(255,255,255,0.05);
            --header-bg: #2c3034;
            --input-bg: #1e1e1e;
            --input-border: #495057;
            --shadow-color: rgba(0,0,0,0.2);
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .sidebar {
            min-height: 100vh;
            background: var(--sidebar-bg);
            transition: all 0.3s ease;
        }
        
        .sidebar[data-position="right"] {
            order: 2;
        }
        
        .sidebar[data-size="compact"] {
            max-width: 80px;
        }
        
        .sidebar[data-size="compact"] .sidebar-brand h4,
        .sidebar[data-size="compact"] .sidebar-brand p,
        .sidebar[data-size="compact"] .nav-link span {
            display: none;
        }
        
        .sidebar[data-size="compact"] .nav-link i {
            margin-right: 0;
            font-size: 1.2rem;
        }
        
        .sidebar[data-size="wide"] {
            max-width: 280px;
        }
        
        .sidebar-brand {
            padding: 1.5rem 1rem;
            text-align: center;
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .sidebar-nav .nav-link {
            color: var(--sidebar-text);
            padding: 0.8rem 1.5rem;
            margin: 0.2rem 0;
            transition: all 0.3s;
        }
        
        .sidebar-nav .nav-link:hover,
        .sidebar-nav .nav-link.active {
            color: white;
            background: var(--sidebar-hover);
            border-left: 4px solid white;
        }
        
        .sidebar-nav .nav-link i {
            margin-right: 10px;
            width: 20px;
        }
        
        .main-content {
            padding: 2rem;
            background-color: var(--bg-color);
        }
        
        .settings-header {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px var(--shadow-color);
            border: 1px solid var(--border-color);
        }
        
        .settings-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 5px var(--shadow-color);
            border: 1px solid var(--border-color);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px var(--shadow-color);
        }
        
        .settings-card .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 0 0 1rem 0;
            margin-bottom: 1rem;
        }
        
        .form-control, .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            border-color: var(--primary-color);
            color: var(--text-color);
            box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25);
        }
        
        .form-check-input {
            background-color: var(--input-bg);
            border-color: var(--input-border);
        }
        
        .text-muted {
            color: var(--text-muted) !important;
        }
        
        .border {
            border-color: var(--border-color) !important;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .profile-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid var(--primary-color);
            object-fit: cover;
        }
        
        .nav-tabs {
            border-bottom-color: var(--border-color);
        }
        
        .nav-tabs .nav-link {
            color: var(--text-muted);
            border: none;
            padding: 0.8rem 1.5rem;
        }
        
        .nav-tabs .nav-link:hover {
            border-color: transparent;
            color: var(--primary-color);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background: transparent;
            border-bottom: 2px solid var(--primary-color);
        }
        
        .preview-box {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }
        
        .theme-preview {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .theme-option {
            flex: 1;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .theme-option:hover,
        .theme-option.active {
            border-color: var(--primary-color);
            background: rgba(102,126,234,0.05);
        }
        
        .theme-option.light {
            background: white;
            color: #333;
        }
        
        .theme-option.dark {
            background: #1a1e21;
            color: white;
        }
        
        .theme-option.auto {
            background: linear-gradient(45deg, white 50%, #1a1e21 50%);
            color: #333;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .main-content {
                padding: 1rem;
            }
            .sidebar[data-position="right"] {
                order: 0;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 sidebar" 
                 data-position="<?= $user_settings['sidebar_position'] ?>"
                 data-size="<?= $user_settings['sidebar_size'] ?>">
                <div class="sidebar-brand">
                    <h4>TaskFlow Pro</h4>
                    <p>v3.0.0</p>
                </div>
                
                <div class="sidebar-nav">
                    <div class="nav flex-column">
                        <a href="dashboard.php" class="nav-link">
                            <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
                        </a>
                        
                        <?php if ($user_role === 'admin'): ?>
                            <a href="users.php" class="nav-link">
                                <i class="bi bi-people"></i> <span>User Management</span>
                            </a>
                            <a href="approvals.php" class="nav-link">
                                <i class="bi bi-person-check"></i> <span>Approvals</span>
                            </a>
                        <?php endif; ?>
                        
                        <?php if (in_array($user_role, ['admin', 'manager'])): ?>
                            <a href="projects.php" class="nav-link">
                                <i class="bi bi-kanban"></i> <span>Projects</span>
                            </a>
                        <?php endif; ?>
                        
                        <a href="tasks.php" class="nav-link">
                            <i class="bi bi-check2-square"></i> <span>Tasks</span>
                        </a>
                        
                        <a href="reports.php" class="nav-link">
                            <i class="bi bi-bar-chart"></i> <span>Reports</span>
                        </a>
                        
                        <a href="calendar.php" class="nav-link">
                            <i class="bi bi-calendar"></i> <span>Calendar</span>
                        </a>
                        
                        <a href="notifications.php" class="nav-link">
                            <i class="bi bi-bell"></i> <span>Notifications</span>
                        </a>
                        
                        <a href="profile.php" class="nav-link">
                            <i class="bi bi-person"></i> <span>Profile</span>
                        </a>
                        
                        <a href="settings.php" class="nav-link active">
                            <i class="bi bi-gear"></i> <span>Settings</span>
                        </a>
                        
                        <a href="logout.php" class="nav-link text-danger">
                            <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 ms-sm-auto px-0">
                <!-- Top Navigation -->
                <nav class="navbar navbar-light px-4 py-3 shadow-sm" style="background: var(--header-bg); border-bottom: 1px solid var(--border-color);">
                    <div class="container-fluid">
                        <h5 class="mb-0">
                            <i class="bi bi-gear me-2 text-primary"></i>Settings
                        </h5>
                        <div class="d-flex align-items-center">
                            <span class="me-3">
                                <i class="bi bi-person-circle me-1"></i> <?= sanitize($user['full_name']) ?>
                            </span>
                            <span class="badge bg-<?= 
                                $user_role == 'admin' ? 'danger' : 
                                ($user_role == 'manager' ? 'primary' : 
                                ($user_role == 'member' ? 'info' : 'secondary')) 
                            ?>">
                                <?= ucfirst($user_role) ?>
                            </span>
                        </div>
                    </div>
                </nav>
                
                <!-- Main Content Area -->
                <div class="main-content">
                    <!-- Flash Messages -->
                    <?php 
                    $flash_success = getFlashMessage('success');
                    $flash_error = getFlashMessage('error');
                    ?>
                    
                    <?php if ($flash_success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i><?= sanitize($flash_success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($flash_error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= sanitize($flash_error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Settings Header -->
                    <div class="settings-header">
                        <div class="row align-items-center">
                            <div class="col">
                                <h4 class="mb-1">Settings & Preferences</h4>
                                <p class="text-muted mb-0">Customize your TaskFlow experience</p>
                            </div>
                            <div class="col-auto">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Reset all settings to default values?')">
                                    <input type="hidden" name="settings_action" value="reset_defaults">
                                    <button type="submit" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to Defaults
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Settings Tabs -->
                    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="preferences-tab" data-bs-toggle="tab" data-bs-target="#preferences" type="button">
                                <i class="bi bi-palette me-2"></i>Preferences
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button">
                                <i class="bi bi-person me-2"></i>Profile
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button">
                                <i class="bi bi-shield-lock me-2"></i>Security
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications" type="button">
                                <i class="bi bi-bell me-2"></i>Notifications
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="appearance-tab" data-bs-toggle="tab" data-bs-target="#appearance" type="button">
                                <i class="bi bi-display me-2"></i>Appearance
                            </button>
                        </li>
                        <?php if ($user_role === 'admin'): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="system-tab" data-bs-toggle="tab" data-bs-target="#system" type="button">
                                    <i class="bi bi-gear-wide-connected me-2"></i>System
                                </button>
                            </li>
                        <?php endif; ?>
                    </ul>
                    
                    <!-- Tab Content -->
                    <div class="tab-content">
                        <!-- PREFERENCES TAB -->
                        <div class="tab-pane fade show active" id="preferences" role="tabpanel">
                            <form method="POST">
                                <input type="hidden" name="settings_action" value="update_preferences">
                                
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="settings-card">
                                            <div class="card-header">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bi bi-display me-2 text-primary"></i>Display Settings
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Theme</label>
                                                    <div class="theme-preview">
                                                        <div class="theme-option light <?= $user_settings['theme'] == 'light' ? 'active' : '' ?>" onclick="selectTheme('light', this)">
                                                            <i class="bi bi-sun-fill me-2"></i>Light
                                                        </div>
                                                        <div class="theme-option dark <?= $user_settings['theme'] == 'dark' ? 'active' : '' ?>" onclick="selectTheme('dark', this)">
                                                            <i class="bi bi-moon-fill me-2"></i>Dark
                                                        </div>
                                                        <div class="theme-option auto <?= $user_settings['theme'] == 'auto' ? 'active' : '' ?>" onclick="selectTheme('auto', this)">
                                                            <i class="bi bi-circle-half me-2"></i>Auto
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="theme" id="theme_input" value="<?= $user_settings['theme'] ?>">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Sidebar Position</label>
                                                    <select class="form-select" name="sidebar_position">
                                                        <option value="left" <?= $user_settings['sidebar_position'] == 'left' ? 'selected' : '' ?>>Left</option>
                                                        <option value="right" <?= $user_settings['sidebar_position'] == 'right' ? 'selected' : '' ?>>Right</option>
                                                    </select>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Sidebar Size</label>
                                                    <select class="form-select" name="sidebar_size">
                                                        <option value="compact" <?= $user_settings['sidebar_size'] == 'compact' ? 'selected' : '' ?>>Compact</option>
                                                        <option value="default" <?= $user_settings['sidebar_size'] == 'default' ? 'selected' : '' ?>>Default</option>
                                                        <option value="wide" <?= $user_settings['sidebar_size'] == 'wide' ? 'selected' : '' ?>>Wide</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="settings-card">
                                            <div class="card-header">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bi bi-calendar me-2 text-primary"></i>Date & Time
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Date Format</label>
                                                    <select class="form-select" name="date_format">
                                                        <option value="M d, Y" <?= $user_settings['date_format'] == 'M d, Y' ? 'selected' : '' ?>>Jan 15, 2024</option>
                                                        <option value="d M Y" <?= $user_settings['date_format'] == 'd M Y' ? 'selected' : '' ?>>15 Jan 2024</option>
                                                        <option value="Y-m-d" <?= $user_settings['date_format'] == 'Y-m-d' ? 'selected' : '' ?>>2024-01-15</option>
                                                        <option value="d/m/Y" <?= $user_settings['date_format'] == 'd/m/Y' ? 'selected' : '' ?>>15/01/2024</option>
                                                        <option value="m/d/Y" <?= $user_settings['date_format'] == 'm/d/Y' ? 'selected' : '' ?>>01/15/2024</option>
                                                    </select>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Time Format</label>
                                                    <div class="d-flex gap-3">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="time_format" id="time12" value="12" <?= $user_settings['time_format'] == '12' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="time12">12-hour (02:30 PM)</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="time_format" id="time24" value="24" <?= $user_settings['time_format'] == '24' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="time24">24-hour (14:30)</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">First Day of Week</label>
                                                    <select class="form-select" name="first_day_of_week">
                                                        <option value="0" <?= $user_settings['first_day_of_week'] == '0' ? 'selected' : '' ?>>Sunday</option>
                                                        <option value="1" <?= $user_settings['first_day_of_week'] == '1' ? 'selected' : '' ?>>Monday</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-lg-6">
                                        <div class="settings-card">
                                            <div class="card-header">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bi bi-translate me-2 text-primary"></i>Language & Region
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Language</label>
                                                    <select class="form-select" name="language">
                                                        <option value="en" <?= $user_settings['language'] == 'en' ? 'selected' : '' ?>>English</option>
                                                        <option value="es" <?= $user_settings['language'] == 'es' ? 'selected' : '' ?>>Español</option>
                                                        <option value="fr" <?= $user_settings['language'] == 'fr' ? 'selected' : '' ?>>Français</option>
                                                        <option value="de" <?= $user_settings['language'] == 'de' ? 'selected' : '' ?>>Deutsch</option>
                                                        <option value="zh" <?= $user_settings['language'] == 'zh' ? 'selected' : '' ?>>中文</option>
                                                        <option value="ja" <?= $user_settings['language'] == 'ja' ? 'selected' : '' ?>>日本語</option>
                                                    </select>
                                                    <small class="text-muted">More languages coming soon</small>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Items Per Page</label>
                                                    <select class="form-select" name="items_per_page">
                                                        <option value="10" <?= $user_settings['items_per_page'] == 10 ? 'selected' : '' ?>>10</option>
                                                        <option value="20" <?= $user_settings['items_per_page'] == 20 ? 'selected' : '' ?>>20</option>
                                                        <option value="50" <?= $user_settings['items_per_page'] == 50 ? 'selected' : '' ?>>50</option>
                                                        <option value="100" <?= $user_settings['items_per_page'] == 100 ? 'selected' : '' ?>>100</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="settings-card">
                                            <div class="card-header">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bi bi-eye me-2 text-primary"></i>Preview
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="preview-box">
                                                    <strong>Current Settings Preview</strong>
                                                    <div class="mt-2" id="datePreview"></div>
                                                    <div id="timePreview"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-end mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-1"></i>Save Preferences
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- PROFILE TAB -->
                        <div class="tab-pane fade" id="profile" role="tabpanel">
                            <div class="row">
                                <div class="col-lg-4">
                                    <div class="settings-card text-center">
                                        <div class="card-header">
                                            <h6 class="mb-0 fw-bold">
                                                <i class="bi bi-camera me-2 text-primary"></i>Profile Picture
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                                                <img src="<?= $user['avatar'] ?>" alt="Avatar" class="profile-avatar-large mb-3">
                                            <?php else: ?>
                                                <div class="profile-avatar-large d-flex align-items-center justify-content-center mx-auto mb-3" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));">
                                                    <span style="font-size: 2.5rem; color: white;">
                                                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <form method="POST" enctype="multipart/form-data">
                                                <input type="hidden" name="settings_action" value="upload_avatar">
                                                <div class="mb-3">
                                                    <input type="file" class="form-control form-control-sm" name="avatar" accept="image/*" required>
                                                    <small class="text-muted">Max size: 2MB (JPG, PNG, GIF)</small>
                                                </div>
                                                <div class="d-grid gap-2">
                                                    <button type="submit" class="btn btn-primary btn-sm">
                                                        <i class="bi bi-upload me-1"></i>Upload New Picture
                                                    </button>
                                                    <?php if (!empty($user['avatar'])): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="settings_action" value="remove_avatar">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                                <i class="bi bi-trash me-1"></i>Remove Picture
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-lg-8">
                                    <div class="settings-card">
                                        <div class="card-header">
                                            <h6 class="mb-0 fw-bold">
                                                <i class="bi bi-pencil-square me-2 text-primary"></i>Edit Profile
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST">
                                                <input type="hidden" name="settings_action" value="update_profile">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Full Name</label>
                                                    <input type="text" class="form-control" name="full_name" value="<?= sanitize($user['full_name']) ?>" required>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Email Address</label>
                                                    <input type="email" class="form-control" name="email" value="<?= sanitize($user['email']) ?>" required>
                                                    <small class="text-muted">Changing email will affect your login credentials</small>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Role</label>
                                                    <input type="text" class="form-control" value="<?= ucfirst($user['role']) ?>" readonly disabled>
                                                    <small class="text-muted">Role cannot be changed here</small>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-check-circle me-1"></i>Update Profile
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- SECURITY TAB -->
                        <div class="tab-pane fade" id="security" role="tabpanel">
                            <div class="row">
                                <div class="col-lg-6">
                                    <div class="settings-card">
                                        <div class="card-header">
                                            <h6 class="mb-0 fw-bold">
                                                <i class="bi bi-key me-2 text-primary"></i>Change Password
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST">
                                                <input type="hidden" name="settings_action" value="change_password">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Current Password</label>
                                                    <input type="password" class="form-control" name="current_password" required>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">New Password</label>
                                                    <input type="password" class="form-control" name="new_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
                                                    <small class="text-muted">Min <?= PASSWORD_MIN_LENGTH ?> chars, 1 uppercase, 1 lowercase, 1 number</small>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Confirm New Password</label>
                                                    <input type="password" class="form-control" name="confirm_password" required>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-shield-lock me-1"></i>Update Password
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-lg-6">
                                    <div class="settings-card">
                                        <div class="card-header">
                                            <h6 class="mb-0 fw-bold">
                                                <i class="bi bi-shield-check me-2 text-primary"></i>Security Status
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="flex-shrink-0">
                                                    <i class="bi bi-shield-lock text-success fs-4"></i>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-0">Password Strength</h6>
                                                    <div class="progress mt-2" style="height: 6px;">
                                                        <div class="progress-bar bg-success" style="width: 80%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="flex-shrink-0">
                                                    <i class="bi bi-clock-history text-warning fs-4"></i>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-0">Last Password Change</h6>
                                                    <small class="text-muted"><?= date('M d, Y', strtotime($user['updated_at'])) ?></small>
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex align-items-center">
                                                <div class="flex-shrink-0">
                                                    <i class="bi bi-shield text-info fs-4"></i>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-0">Two-Factor Authentication</h6>
                                                    <small class="text-muted">Not enabled</small>
                                                </div>
                                                <button class="btn btn-sm btn-outline-primary" disabled>Coming Soon</button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="settings-card mt-4">
                                        <div class="card-header">
                                            <h6 class="mb-0 fw-bold">
                                                <i class="bi bi-clock me-2 text-primary"></i>Recent Sessions
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-laptop fs-4 me-3"></i>
                                                <div>
                                                    <h6 class="mb-0">Current Session</h6>
                                                    <small class="text-muted">Started at <?= date('h:i A') ?></small>
                                                </div>
                                                <span class="badge bg-success ms-auto">Active</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- NOTIFICATIONS TAB -->
                        <div class="tab-pane fade" id="notifications" role="tabpanel">
                            <form method="POST">
                                <input type="hidden" name="settings_action" value="update_preferences">
                                
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="settings-card">
                                            <div class="card-header">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bi bi-bell me-2 text-primary"></i>Notification Settings
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" name="notifications_enabled" id="notificationsEnabled" value="1" <?= $user_settings['notifications_enabled'] ? 'checked' : '' ?>>
                                                        <label class="form-check-label fw-semibold" for="notificationsEnabled">Enable Notifications</label>
                                                    </div>
                                                    <small class="text-muted ms-4">Receive in-app notifications</small>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" name="email_notifications" id="emailNotifications" value="1" <?= $user_settings['email_notifications'] ? 'checked' : '' ?>>
                                                        <label class="form-check-label fw-semibold" for="emailNotifications">Email Notifications</label>
                                                    </div>
                                                    <small class="text-muted ms-4">Receive email alerts</small>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" name="task_reminders" id="taskReminders" value="1" <?= $user_settings['task_reminders'] ? 'checked' : '' ?>>
                                                        <label class="form-check-label fw-semibold" for="taskReminders">Task Reminders</label>
                                                    </div>
                                                    <small class="text-muted ms-4">Get reminded about upcoming tasks</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-lg-6">
                                        <div class="settings-card">
                                            <div class="card-header">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bi bi-bell-slash me-2 text-primary"></i>Reminder Settings
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Due Date Reminder</label>
                                                    <select class="form-select" name="due_date_reminder_days">
                                                        <option value="1" <?= $user_settings['due_date_reminder_days'] == 1 ? 'selected' : '' ?>>1 day before</option>
                                                        <option value="2" <?= $user_settings['due_date_reminder_days'] == 2 ? 'selected' : '' ?>>2 days before</option>
                                                        <option value="3" <?= $user_settings['due_date_reminder_days'] == 3 ? 'selected' : '' ?>>3 days before</option>
                                                        <option value="7" <?= $user_settings['due_date_reminder_days'] == 7 ? 'selected' : '' ?>>1 week before</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-end mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-1"></i>Save Notification Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- APPEARANCE TAB -->
                        <div class="tab-pane fade" id="appearance" role="tabpanel">
                            <div class="settings-card">
                                <div class="card-header">
                                    <h6 class="mb-0 fw-bold">
                                        <i class="bi bi-brush me-2 text-primary"></i>Customize Appearance
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="fw-semibold mb-3">Color Scheme</h6>
                                            <div class="mb-3">
                                                <label class="form-label">Primary Color</label>
                                                <div class="d-flex gap-2">
                                                    <button class="btn btn-sm" style="background: #667eea; width: 40px; height: 40px; border-radius: 8px;" onclick="setPrimaryColor('#667eea')"></button>
                                                    <button class="btn btn-sm" style="background: #764ba2; width: 40px; height: 40px; border-radius: 8px;" onclick="setPrimaryColor('#764ba2')"></button>
                                                    <button class="btn btn-sm" style="background: #28a745; width: 40px; height: 40px; border-radius: 8px;" onclick="setPrimaryColor('#28a745')"></button>
                                                    <button class="btn btn-sm" style="background: #dc3545; width: 40px; height: 40px; border-radius: 8px;" onclick="setPrimaryColor('#dc3545')"></button>
                                                    <button class="btn btn-sm" style="background: #17a2b8; width: 40px; height: 40px; border-radius: 8px;" onclick="setPrimaryColor('#17a2b8')"></button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="fw-semibold mb-3">Layout Density</h6>
                                            <div class="mb-3">
                                                <select class="form-select">
                                                    <option>Comfortable</option>
                                                    <option selected>Default</option>
                                                    <option>Compact</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($user_role === 'admin'): ?>
                            <!-- SYSTEM SETTINGS TAB (ADMIN ONLY) -->
                            <div class="tab-pane fade" id="system" role="tabpanel">
                                <form method="POST">
                                    <input type="hidden" name="settings_action" value="update_system">
                                    
                                    <div class="row">
                                        <div class="col-lg-6">
                                            <div class="settings-card">
                                                <div class="card-header">
                                                    <h6 class="mb-0 fw-bold">
                                                        <i class="bi bi-building me-2 text-primary"></i>General Settings
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Site Name</label>
                                                        <input type="text" class="form-control" name="setting_site_name" value="<?= $sys_settings['site_name'] ?? 'TaskFlow Pro' ?>">
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Company Name</label>
                                                        <input type="text" class="form-control" name="setting_company_name" value="<?= $sys_settings['company_name'] ?? 'TaskFlow Inc.' ?>">
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Admin Email</label>
                                                        <input type="email" class="form-control" name="setting_admin_email" value="<?= $sys_settings['admin_email'] ?? 'admin@taskflow.com' ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="settings-card">
                                                <div class="card-header">
                                                    <h6 class="mb-0 fw-bold">
                                                        <i class="bi bi-person-plus me-2 text-primary"></i>Registration Settings
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" name="setting_allow_registration" id="allowRegistration" value="true" <?= ($sys_settings['allow_registration'] ?? 'true') == 'true' ? 'checked' : '' ?>>
                                                            <label class="form-check-label fw-semibold" for="allowRegistration">Allow User Registration</label>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" name="setting_require_approval" id="requireApproval" value="true" <?= ($sys_settings['require_approval'] ?? 'true') == 'true' ? 'checked' : '' ?>>
                                                            <label class="form-check-label fw-semibold" for="requireApproval">Require Admin Approval</label>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Default User Role</label>
                                                        <select class="form-select" name="setting_default_user_role">
                                                            <option value="member" <?= ($sys_settings['default_user_role'] ?? 'member') == 'member' ? 'selected' : '' ?>>Team Member</option>
                                                            <option value="client" <?= ($sys_settings['default_user_role'] ?? 'member') == 'client' ? 'selected' : '' ?>>Client</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-lg-6">
                                            <div class="settings-card">
                                                <div class="card-header">
                                                    <h6 class="mb-0 fw-bold">
                                                        <i class="bi bi-shield-lock me-2 text-primary"></i>Security Settings
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Max Login Attempts</label>
                                                        <input type="number" class="form-control" name="setting_max_login_attempts" value="<?= $sys_settings['max_login_attempts'] ?? '5' ?>" min="1" max="10">
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Lockout Duration (minutes)</label>
                                                        <input type="number" class="form-control" name="setting_lockout_duration" value="<?= $sys_settings['lockout_duration'] ?? '15' ?>" min="1" max="120">
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Session Timeout (minutes)</label>
                                                        <input type="number" class="form-control" name="setting_session_timeout" value="<?= $sys_settings['session_timeout'] ?? '30' ?>" min="5" max="480">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="settings-card">
                                                <div class="card-header">
                                                    <h6 class="mb-0 fw-bold">
                                                        <i class="bi bi-envelope me-2 text-primary"></i>Email Settings
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">SMTP Host</label>
                                                        <input type="text" class="form-control" name="setting_email_smtp_host" value="<?= $sys_settings['email_smtp_host'] ?? '' ?>">
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">SMTP Port</label>
                                                        <input type="number" class="form-control" name="setting_email_smtp_port" value="<?= $sys_settings['email_smtp_port'] ?? '587' ?>">
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">From Email</label>
                                                        <input type="email" class="form-control" name="setting_email_from_address" value="<?= $sys_settings['email_from_address'] ?? 'noreply@taskflow.com' ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="settings-card">
                                        <div class="card-header">
                                            <h6 class="mb-0 fw-bold">
                                                <i class="bi bi-exclamation-triangle me-2 text-danger"></i>Maintenance Mode
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" name="setting_maintenance_mode" id="maintenanceMode" value="true" <?= ($sys_settings['maintenance_mode'] ?? 'false') == 'true' ? 'checked' : '' ?>>
                                                        <label class="form-check-label fw-semibold" for="maintenanceMode">Enable Maintenance Mode</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-8">
                                                    <input type="text" class="form-control" name="setting_maintenance_message" placeholder="Maintenance message" value="<?= $sys_settings['maintenance_message'] ?? 'System is under scheduled maintenance.' ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-end mt-3">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>Save System Settings
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Theme selector
        function selectTheme(theme, element) {
            document.querySelectorAll('.theme-option').forEach(el => {
                el.classList.remove('active');
            });
            element.classList.add('active');
            document.getElementById('theme_input').value = theme;
            
            // Preview theme immediately
            document.documentElement.setAttribute('data-theme', theme);
            
            // Save to localStorage for persistence
            localStorage.setItem('preview_theme', theme);
        }
        
        // Set primary color
        function setPrimaryColor(color) {
            document.documentElement.style.setProperty('--primary-color', color);
            
            // Also update secondary color to a complementary shade
            if (color === '#667eea') {
                document.documentElement.style.setProperty('--secondary-color', '#764ba2');
            } else if (color === '#764ba2') {
                document.documentElement.style.setProperty('--secondary-color', '#553c9a');
            } else if (color === '#28a745') {
                document.documentElement.style.setProperty('--secondary-color', '#1e7e34');
            } else if (color === '#dc3545') {
                document.documentElement.style.setProperty('--secondary-color', '#a71d2a');
            } else if (color === '#17a2b8') {
                document.documentElement.style.setProperty('--secondary-color', '#117a8b');
            }
        }
        
        // Update date/time preview
        function updatePreview() {
            const now = new Date();
            const dateFormat = document.querySelector('select[name="date_format"]').value;
            const timeFormat = document.querySelector('input[name="time_format"]:checked')?.value || '12';
            
            let dateStr = now.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
            
            let timeStr = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: timeFormat === '12'
            });
            
            document.getElementById('datePreview').innerHTML = `<strong>Date:</strong> ${dateStr}`;
            document.getElementById('timePreview').innerHTML = `<strong>Time:</strong> ${timeStr}`;
        }
        
        // Sidebar position preview
        document.querySelector('select[name="sidebar_position"]').addEventListener('change', function() {
            document.querySelector('.sidebar').setAttribute('data-position', this.value);
        });
        
        // Sidebar size preview
        document.querySelector('select[name="sidebar_size"]').addEventListener('change', function() {
            document.querySelector('.sidebar').setAttribute('data-size', this.value);
        });
        
        // Initialize preview
        document.addEventListener('DOMContentLoaded', function() {
            updatePreview();
            
            document.querySelectorAll('select[name="date_format"], input[name="time_format"]').forEach(el => {
                el.addEventListener('change', updatePreview);
            });
            
            // Load saved theme preview from localStorage
            const savedTheme = localStorage.getItem('preview_theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
        });
        
        // Auto theme based on system preference
        function setAutoTheme() {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        }
        
        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            if (document.getElementById('theme_input').value === 'auto') {
                setAutoTheme();
            }
        });
        
        // Keyboard shortcut: Alt+S to save settings
        document.addEventListener('keydown', function(e) {
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                document.querySelector('button[type="submit"]')?.click();
            }
        });
        
        // Confirm before leaving with unsaved changes
        let formChanged = false;
        document.querySelectorAll('form input, form select, form textarea').forEach(el => {
            el.addEventListener('change', () => formChanged = true);
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        // Save settings via AJAX
        function saveSettingsAjax(formData) {
            fetch('settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                formChanged = false;
                // Show toast notification
                const toast = document.createElement('div');
                toast.className = 'position-fixed bottom-0 end-0 p-3';
                toast.style.zIndex = '11';
                toast.innerHTML = `
                    <div class="toast show" role="alert">
                        <div class="toast-header bg-success text-white">
                            <i class="bi bi-check-circle me-2"></i>
                            <strong class="me-auto">Success</strong>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                        </div>
                        <div class="toast-body">
                            Settings saved successfully!
                        </div>
                    </div>
                `;
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 3000);
            });
        }
    </script>
</body>
</html>
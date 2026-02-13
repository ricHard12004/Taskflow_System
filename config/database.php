<?php
// config/database.php
session_start();

// Database configuration - CHANGE THESE VALUES TO MATCH YOUR SETUP
define('DB_HOST', 'localhost');
define('DB_NAME', 'taskflow_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Base URL - CHANGE THIS TO YOUR ACTUAL URL
define('BASE_URL', 'http://localhost/Taskflow_System');
define('SITE_NAME', 'TaskFlow Pro');

// Security settings
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 15); // minutes

// Create uploads directory if it doesn't exist
$upload_dir = __DIR__ . '/../uploads/avatars/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Establish database connection
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("
        <div style='font-family: Arial; padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;'>
            <h2>🔴 Database Connection Failed</h2>
            <p><strong>Error:</strong> " . $e->getMessage() . "</p>
            <p><strong>Please check:</strong></p>
            <ul>
                <li>MySQL is running</li>
                <li>Database 'taskflow_db' exists</li>
                <li>Username/password in config/database.php is correct</li>
            </ul>
            <p><a href='setup.php' style='color: #721c24; font-weight: bold;'>Click here to run setup</a></p>
        </div>
    ");
}

// Helper functions
function base_url($path = '') {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function redirect($url) {
    header("Location: " . base_url($url));
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function logActivity($pdo, $user_id, $action, $description) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $description, $ip, $user_agent]);
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

function displayFlashMessage($type, $message) {
    $_SESSION['flash_' . $type] = $message;
}

function getFlashMessage($type) {
    if (isset($_SESSION['flash_' . $type])) {
        $message = $_SESSION['flash_' . $type];
        unset($_SESSION['flash_' . $type]);
        return $message;
    }
    return null;
}

// ============== GLOBAL USER SETTINGS LOADER ==============
// This loads user preferences for EVERY page automatically

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

// Load user settings if logged in
if (isLoggedIn()) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $db_settings = $stmt->fetch();
        
        if ($db_settings) {
            $user_settings = array_merge($user_settings, $db_settings);
            // Store in session for quick access
            $_SESSION['user_settings'] = $user_settings;
            $_SESSION['user_theme'] = $user_settings['theme'];
        }
    } catch (PDOException $e) {
        error_log("Failed to load user settings: " . $e->getMessage());
    }
}

// Auto redirect if not logged in (except for allowed pages)
$allowed_pages = ['login.php', 'register.php', 'forgot_password.php', 'reset_password.php', 'setup.php'];
$current_page = basename($_SERVER['PHP_SELF']);

if (!isLoggedIn() && !in_array($current_page, $allowed_pages)) {
    redirect('login.php');
}

// Global CSS theme injector - This will be used in the header of every page
function getThemeStyles() {
    global $user_settings;
    $theme = $user_settings['theme'];
    
    if ($theme === 'auto') {
        // Auto theme - follows system preference
        return '
        <style id="global-theme-styles">
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
            
            @media (prefers-color-scheme: dark) {
                :root {
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
            }
        </style>';
    } elseif ($theme === 'dark') {
        return '
        <style id="global-theme-styles">
            :root {
                --primary-color: #667eea;
                --secondary-color: #764ba2;
                --success-color: #28a745;
                --danger-color: #dc3545;
                --warning-color: #ffc107;
                --info-color: #17a2b8;
                
                /* Dark theme */
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
        </style>';
    } else {
        // Light theme (default)
        return '
        <style id="global-theme-styles">
            :root {
                --primary-color: #667eea;
                --secondary-color: #764ba2;
                --success-color: #28a745;
                --danger-color: #dc3545;
                --warning-color: #ffc107;
                --info-color: #17a2b8;
                
                /* Light theme */
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
        </style>';
    }
}

// Global sidebar attributes
function getSidebarAttributes() {
    global $user_settings;
    return 'data-position="' . $user_settings['sidebar_position'] . '" data-size="' . $user_settings['sidebar_size'] . '"';
}

// Format date according to user preferences
function formatDate($date, $user_settings) {
    if (!$date) return 'N/A';
    $timestamp = strtotime($date);
    $format = $user_settings['date_format'] ?? 'M d, Y';
    return date($format, $timestamp);
}

// Format time according to user preferences
function formatTime($time, $user_settings) {
    if (!$time) return 'N/A';
    $timestamp = strtotime($time);
    $format = ($user_settings['time_format'] ?? '12') == '12' ? 'h:i A' : 'H:i';
    return date($format, $timestamp);
}

// Format datetime according to user preferences
function formatDateTime($datetime, $user_settings) {
    if (!$datetime) return 'N/A';
    return formatDate($datetime, $user_settings) . ' at ' . formatTime($datetime, $user_settings);
}
?>
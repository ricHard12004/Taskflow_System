<?php
// setup.php - Run this first to check your installation
require_once 'config/database.php';

// Check PHP version
$php_version = phpversion();
$php_ok = version_compare($php_version, '7.4.0', '>=');

// Check required extensions
$extensions = ['pdo_mysql', 'mysqli', 'gd', 'json', 'session'];
$ext_status = [];
foreach ($extensions as $ext) {
    $ext_status[$ext] = extension_loaded($ext);
}

// Check folders
$folders = [
    'uploads' => __DIR__ . '/uploads',
    'uploads/avatars' => __DIR__ . '/uploads/avatars'
];
$folder_status = [];
foreach ($folders as $name => $path) {
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
    $folder_status[$name] = is_writable($path);
}

// Check database tables
$tables = ['users', 'projects', 'tasks', 'task_comments', 'notifications', 'activity_logs'];
$table_status = [];
try {
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $table_status[$table] = $stmt->rowCount() > 0;
    }
    $db_connected = true;
} catch (Exception $e) {
    $db_connected = false;
}

// Count users
$user_count = 0;
if ($db_connected) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $user_count = $stmt->fetch()['count'];
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskFlow - System Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fc; padding: 40px 20px; font-family: 'Segoe UI', sans-serif; }
        .setup-card { background: white; border-radius: 15px; padding: 30px; max-width: 800px; margin: 0 auto; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .status-icon { font-size: 1.5rem; margin-right: 10px; }
        .status-pass { color: #28a745; }
        .status-fail { color: #dc3545; }
        .status-warning { color: #ffc107; }
        .step { padding: 15px; border-bottom: 1px solid #eee; }
        .step:last-child { border-bottom: none; }
        .btn-demo { background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; }
        .btn-demo:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); color: white; }
    </style>
</head>
<body>
    <div class="setup-card">
        <div class="text-center mb-4">
            <h1 class="h2 mb-3">🚀 TaskFlow Pro Setup</h1>
            <p class="text-muted">System Installation Checker</p>
        </div>
        
        <!-- PHP Version -->
        <div class="step d-flex align-items-center">
            <div class="status-icon">
                <i class="bi bi-<?= $php_ok ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-warning' ?>"></i>
            </div>
            <div class="flex-grow-1">
                <strong>PHP Version:</strong> <?= $php_version ?>
                <br><small class="text-muted">Required: PHP 7.4 or higher</small>
            </div>
            <span class="badge bg-<?= $php_ok ? 'success' : 'warning' ?>"><?= $php_ok ? 'OK' : 'Update Required' ?></span>
        </div>
        
        <!-- Extensions -->
        <div class="step">
            <strong>PHP Extensions:</strong>
            <?php foreach ($ext_status as $ext => $loaded): ?>
                <div class="d-flex align-items-center mt-2">
                    <i class="bi bi-<?= $loaded ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?> me-2"></i>
                    <?= strtoupper($ext) ?>
                    <?php if (!$loaded): ?>
                        <span class="badge bg-danger ms-2">Missing</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Folders -->
        <div class="step">
            <strong>Write Permissions:</strong>
            <?php foreach ($folder_status as $folder => $writable): ?>
                <div class="d-flex align-items-center mt-2">
                    <i class="bi bi-<?= $writable ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?> me-2"></i>
                    /<?= $folder ?>/
                    <?php if (!$writable): ?>
                        <span class="badge bg-danger ms-2">Not Writable</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Database -->
        <div class="step">
            <div class="d-flex align-items-center">
                <i class="bi bi-<?= $db_connected ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?> status-icon"></i>
                <strong>Database Connection:</strong>
                <span class="badge bg-<?= $db_connected ? 'success' : 'danger' ?> ms-2">
                    <?= $db_connected ? 'Connected' : 'Failed' ?>
                </span>
            </div>
            
            <?php if ($db_connected): ?>
                <div class="mt-3">
                    <strong>Tables:</strong>
                    <?php foreach ($table_status as $table => $exists): ?>
                        <div class="d-flex align-items-center mt-2">
                            <i class="bi bi-<?= $exists ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?> me-2"></i>
                            <?= $table ?>
                            <?php if (!$exists): ?>
                                <span class="badge bg-danger ms-2">Missing</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-3">
                    <strong>Users in database:</strong> <?= $user_count ?>
                    <?php if ($user_count == 0): ?>
                        <span class="badge bg-warning ms-2">No users - run SQL setup</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Base URL -->
        <div class="step">
            <div class="d-flex align-items-center">
                <i class="bi bi-info-circle-fill text-primary status-icon"></i>
                <strong>Base URL:</strong>
            </div>
            <code class="d-block mt-2 p-2 bg-light rounded"><?= BASE_URL ?></code>
            <small class="text-muted">Make sure this matches your actual URL</small>
        </div>
        
        <!-- Actions -->
        <div class="text-center mt-4">
            <?php if ($db_connected && $user_count > 0): ?>
                <a href="login.php" class="btn btn-demo btn-lg px-5">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login Page
                </a>
                <div class="mt-3">
                    <small class="text-muted">
                        <strong>Demo Accounts:</strong><br>
                        admin@taskflow.com / Admin123!<br>
                        manager@taskflow.com / Password123!<br>
                        member@taskflow.com / Password123!<br>
                        client@taskflow.com / Password123!
                    </small>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Please run the SQL setup script first
                </div>
                <button onclick="copySQL()" class="btn btn-primary">
                    <i class="bi bi-files me-2"></i>Copy SQL Setup
                </button>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function copySQL() {
            const sql = `DROP DATABASE IF EXISTS \`taskflow_db\`;
CREATE DATABASE \`taskflow_db\`;
USE \`taskflow_db\`;

-- [PASTE THE FULL SQL FROM STEP 1 HERE]`;
            
            navigator.clipboard.writeText(sql).then(() => {
                alert('SQL copied to clipboard! Paste it in phpMyAdmin and run it.');
            });
        }
    </script>
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
</body>
</html>
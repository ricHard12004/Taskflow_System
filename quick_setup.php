<?php
// quick_setup.php - Run this to automatically fix your database
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskFlow - Quick Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea, #764ba2); font-family: 'Segoe UI', sans-serif; padding: 40px 20px; }
        .setup-box { background: white; border-radius: 15px; padding: 30px; max-width: 600px; margin: 0 auto; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .btn-fix { background: linear-gradient(135deg, #28a745, #20c997); color: white; border: none; padding: 12px 30px; font-weight: 600; }
        .btn-fix:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(40,167,69,0.3); color: white; }
        .step { padding: 15px; border-bottom: 1px solid #eee; }
        .step:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="setup-box">
        <div class="text-center mb-4">
            <h1 class="h2 mb-3">🚀 TaskFlow Quick Setup</h1>
            <p class="text-muted">One-click database setup</p>
        </div>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                // Connect to MySQL without database
                $pdo = new PDO("mysql:host=localhost", "root", "");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Create database
                $pdo->exec("DROP DATABASE IF EXISTS `taskflow_db`");
                $pdo->exec("CREATE DATABASE `taskflow_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `taskflow_db`");
                
                // Create users table
                $pdo->exec("CREATE TABLE `users` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `full_name` VARCHAR(100) NOT NULL,
                    `email` VARCHAR(100) NOT NULL UNIQUE,
                    `password` VARCHAR(255) NOT NULL,
                    `role` ENUM('admin', 'manager', 'member', 'client') NOT NULL DEFAULT 'member',
                    `status` ENUM('pending', 'active', 'inactive', 'locked') NOT NULL DEFAULT 'pending',
                    `failed_attempts` INT DEFAULT 0,
                    `locked_until` DATETIME NULL,
                    `remember_token` VARCHAR(255) NULL,
                    `avatar` VARCHAR(500) NULL,
                    `reset_token` VARCHAR(255) NULL,
                    `reset_expires` DATETIME NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `deleted_at` TIMESTAMP NULL,
                    `deleted_by` INT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                
                // Create other tables
                $pdo->exec("CREATE TABLE `projects` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `project_name` VARCHAR(150) NOT NULL,
                    `description` TEXT,
                    `category` VARCHAR(50) DEFAULT 'General',
                    `status` ENUM('planning', 'active', 'on_hold', 'completed', 'cancelled') DEFAULT 'planning',
                    `priority` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                    `start_date` DATE,
                    `due_date` DATE,
                    `completed_date` DATE NULL,
                    `created_by` INT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `deleted_at` TIMESTAMP NULL,
                    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                
                $pdo->exec("CREATE TABLE `tasks` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `task_title` VARCHAR(200) NOT NULL,
                    `description` TEXT,
                    `project_id` INT NULL,
                    `assigned_to` INT NULL,
                    `assigned_by` INT NOT NULL,
                    `status` ENUM('pending', 'in_progress', 'review', 'completed', 'overdue') DEFAULT 'pending',
                    `priority` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                    `start_date` DATE,
                    `due_date` DATE NOT NULL,
                    `completed_date` DATETIME NULL,
                    `completion_notes` TEXT,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `deleted_at` TIMESTAMP NULL,
                    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL,
                    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
                    FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                
                $pdo->exec("CREATE TABLE `notifications` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT NOT NULL,
                    `type` VARCHAR(50) NOT NULL,
                    `title` VARCHAR(200) NOT NULL,
                    `message` TEXT NOT NULL,
                    `link` VARCHAR(500),
                    `is_read` BOOLEAN DEFAULT FALSE,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                
                $pdo->exec("CREATE TABLE `activity_logs` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT NOT NULL,
                    `action` VARCHAR(50) NOT NULL,
                    `description` TEXT NOT NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `user_agent` TEXT,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                
                // Insert admin user (password: Admin123!)
                $admin_password = password_hash('Admin123!', PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?)")
                    ->execute(['System Administrator', 'admin@taskflow.com', $admin_password, 'admin', 'active']);
                
                // Insert other users (password: Password123!)
                $user_password = password_hash('Password123!', PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?)")
                    ->execute(['John Manager', 'manager@taskflow.com', $user_password, 'manager', 'active']);
                $pdo->prepare("INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?)")
                    ->execute(['Sarah Member', 'member@taskflow.com', $user_password, 'member', 'active']);
                $pdo->prepare("INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?)")
                    ->execute(['Mike Client', 'client@taskflow.com', $user_password, 'client', 'active']);
                $pdo->prepare("INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?)")
                    ->execute(['Pending User', 'pending@taskflow.com', $user_password, 'member', 'pending']);
                
                echo '<div class="alert alert-success">✅ Database created successfully!<br>You can now login with the demo accounts.</div>';
                
            } catch (PDOException $e) {
                echo '<div class="alert alert-danger">❌ Error: ' . $e->getMessage() . '</div>';
            }
        }
        ?>

        <div class="step">
            <h5>📋 Your Information:</h5>
            <p><strong>Project Folder:</strong> <?= basename(__DIR__) ?></p>
            <p><strong>URL to access:</strong> <code>http://localhost/<?= basename(__DIR__) ?>/</code></p>
        </div>

        <div class="step">
            <h5>🔧 Step 1: Fix Base URL</h5>
            <p>Open <code>config/database.php</code> and set:</p>
            <div class="bg-light p-2 rounded">
                <code>define('BASE_URL', 'http://localhost/<?= basename(__DIR__) ?>');</code>
            </div>
        </div>

        <div class="step">
            <h5>💾 Step 2: Create Database</h5>
            <form method="POST">
                <button type="submit" class="btn btn-fix w-100">
                    <i class="bi bi-database"></i> Click Here to Auto-Create Database
                </button>
            </form>
        </div>

        <div class="step">
            <h5>🚪 Step 3: Login</h5>
            <p><strong>Demo Credentials:</strong></p>
            <ul>
                <li><strong>Admin:</strong> admin@taskflow.com / Admin123!</li>
                <li><strong>Manager:</strong> manager@taskflow.com / Password123!</li>
                <li><strong>Member:</strong> member@taskflow.com / Password123!</li>
                <li><strong>Client:</strong> client@taskflow.com / Password123!</li>
            </ul>
            <a href="login.php" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right"></i> Go to Login Page
            </a>
        </div>
    </div>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
</body>
</html>
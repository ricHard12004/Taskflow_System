<?php
// forgot_password.php
require_once 'config/database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    
    if (empty($email)) {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } else {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = ? AND deleted_at IS NULL");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Generate reset token
                $token = generateToken();
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Save token to database
                $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $stmt->execute([$token, $expires, $user['id']]);
                
                // Log activity
                logActivity($pdo, $user['id'], 'PASSWORD_RESET_REQUEST', 'Password reset link requested');
                
                // In a real application, send email here
                // For demo purposes, we'll just show the link
                $reset_link = base_url('reset_password.php?token=' . $token);
                
                // Set success message
                displayFlashMessage('success', 'Password reset link has been sent to your email address.');
                
                // For development - show the link
                if (strpos(BASE_URL, 'localhost') !== false) {
                    displayFlashMessage('info', 'Debug: <a href="' . $reset_link . '">Click here to reset password</a>');
                }
            } else {
                // Don't reveal that email doesn't exist for security
                $success = true;
                displayFlashMessage('success', 'If your email is registered, you will receive a password reset link.');
            }
            
            $success = true;
            
        } catch (PDOException $e) {
            error_log("Forgot password error: " . $e->getMessage());
            $errors['general'] = 'An error occurred. Please try again later.';
        }
    }
}

$title = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($title) ?> - TaskFlow</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Copy the same styling from reset_password.php */
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
        }
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .auth-wrapper {
            width: 100%;
            max-width: 450px;
        }
        .auth-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
        .auth-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            padding: 25px 20px;
            text-align: center;
            color: white;
        }
        .auth-body {
            padding: 30px;
        }
        .btn-submit {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: none;
            color: white;
            padding: 12px;
            width: 100%;
            border-radius: 8px;
            font-weight: 600;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-header">
                <h2><i class="bi bi-key me-2"></i> Forgot Password</h2>
                <p>We'll send you a reset link</p>
            </div>
            <div class="auth-body">
                <?php 
                $flash_error = getFlashMessage('error');
                $flash_success = getFlashMessage('success');
                $flash_info = getFlashMessage('info');
                
                if ($flash_error): ?>
                    <div class="alert alert-danger"><?= $flash_error ?></div>
                <?php endif; ?>
                
                <?php if ($flash_success): ?>
                    <div class="alert alert-success"><?= $flash_success ?></div>
                <?php endif; ?>
                
                <?php if ($flash_info): ?>
                    <div class="alert alert-info"><?= $flash_info ?></div>
                <?php endif; ?>
                
                <?php if (!$success): ?>
                <form method="POST" action="<?= base_url('forgot_password.php') ?>">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Email Address</label>
                        <input type="email" name="email" class="form-control" required 
                               placeholder="Enter your registered email">
                        <?php if (isset($errors['email'])): ?>
                            <div class="text-danger small mt-1"><?= $errors['email'] ?></div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-submit">
                        <i class="bi bi-send me-2"></i>Send Reset Link
                    </button>
                </form>
                <?php endif; ?>
                
                <div class="text-center mt-4">
                    <a href="<?= base_url('login.php') ?>" class="text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i>Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
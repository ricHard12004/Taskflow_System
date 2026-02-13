<?php
// login.php
require_once 'config/database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';
$warning = '';
$success = '';
$account_locked = false;
$remaining_attempts = MAX_LOGIN_ATTEMPTS;
$failed_attempts = 0;

// Check for flash messages
$error = getFlashMessage('error');
$success = getFlashMessage('success');
$warning = getFlashMessage('warning');

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Check if account is locked
                if ($user['status'] === 'locked' && $user['locked_until'] > date('Y-m-d H:i:s')) {
                    $lock_time = strtotime($user['locked_until']);
                    $remaining_minutes = ceil(($lock_time - time()) / 60);
                    $account_locked = true;
                    $warning = "Account locked. Please try again after {$remaining_minutes} minutes.";
                } else {
                    // Reset lock if expired
                    if ($user['status'] === 'locked' && $user['locked_until'] <= date('Y-m-d H:i:s')) {
                        $stmt = $pdo->prepare("UPDATE users SET status = 'active', failed_attempts = 0, locked_until = NULL WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        $user['status'] = 'active';
                        $user['failed_attempts'] = 0;
                    }
                    
                    // Check if account is pending
                    if ($user['status'] === 'pending') {
                        $warning = 'Your account is pending approval. Please wait for administrator approval.';
                    } elseif ($user['status'] === 'inactive') {
                        $warning = 'Your account is inactive. Please contact administrator.';
                    } elseif ($user['status'] === 'active' && password_verify($password, $user['password'])) {
                        // Successful login
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['full_name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['logged_in'] = true;
                        
                        // Reset failed attempts
                        $stmt = $pdo->prepare("UPDATE users SET failed_attempts = 0 WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        
                        // Log activity
                        logActivity($pdo, $user['id'], 'LOGIN', 'User logged in successfully');
                        
                        // Set remember me cookie if checked
                        if ($remember) {
                            $token = generateToken();
                            $expires = time() + (30 * 24 * 60 * 60); // 30 days
                            
                            setcookie('remember_token', $token, $expires, '/', '', false, true);
                            
                            $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                            $stmt->execute([$token, $user['id']]);
                        }
                        
                        // Redirect based on role
                        redirect('dashboard.php');
                    } else {
                        // Failed login
                        $failed_attempts = $user['failed_attempts'] + 1;
                        $remaining_attempts = MAX_LOGIN_ATTEMPTS - $failed_attempts;
                        
                        if ($failed_attempts >= MAX_LOGIN_ATTEMPTS) {
                            // Lock account
                            $locked_until = date('Y-m-d H:i:s', strtotime('+' . LOCKOUT_TIME . ' minutes'));
                            $stmt = $pdo->prepare("UPDATE users SET status = 'locked', failed_attempts = ?, locked_until = ? WHERE id = ?");
                            $stmt->execute([$failed_attempts, $locked_until, $user['id']]);
                            $account_locked = true;
                            $warning = "Too many failed attempts. Account locked for " . LOCKOUT_TIME . " minutes.";
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?");
                            $stmt->execute([$failed_attempts, $user['id']]);
                            $error = 'Invalid email or password.';
                        }
                        
                        logActivity($pdo, $user['id'], 'LOGIN_FAILED', "Failed login attempt #{$failed_attempts}");
                    }
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'An error occurred. Please try again later.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

$title = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($title ?? 'Login') ?> - TaskFlow</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.1"><path d="M20 20 L80 20 L80 80 L20 80 Z" fill="none" stroke="white" stroke-width="2"/><circle cx="50" cy="50" r="10" fill="white"/></svg>');
            background-size: 50px 50px;
            animation: moveBackground 20s linear infinite;
            pointer-events: none;
        }
        
        @keyframes moveBackground {
            from { transform: translateY(0) rotate(0deg); }
            to { transform: translateY(-50px) rotate(10deg); }
        }
        
        .login-wrapper {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            width: 100%;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            padding: 30px 20px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .login-header::after {
            content: '🔐';
            position: absolute;
            bottom: -20px;
            right: -20px;
            font-size: 100px;
            opacity: 0.1;
            transform: rotate(15deg);
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-control {
            border-radius: 8px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            font-size: 16px;
            transition: all 0.3s;
            width: 100%;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            outline: none;
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 5px;
            font-size: 18px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: none;
            border-radius: 8px;
            padding: 14px;
            font-weight: 600;
            font-size: 16px;
            color: white;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 20px;
        }
        
        .login-footer {
            text-align: center;
            padding: 20px;
            border-top: 1px solid #eee;
            font-size: 14px;
            color: #666;
            background: #f9f9f9;
        }
        
        .attempts-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .attempts-warning .warning-icon {
            color: #ffc107;
            font-size: 18px;
            margin-right: 8px;
        }
        
        @media (max-width: 768px) {
            .login-wrapper {
                padding: 15px;
                max-width: 400px;
            }
            .login-body {
                padding: 25px;
            }
            .login-header {
                padding: 25px 15px;
            }
            .login-header h2 {
                font-size: 1.8rem;
            }
        }
        
        @media (max-width: 576px) {
            body {
                padding: 15px;
            }
            .login-wrapper {
                padding: 10px;
                max-width: 100%;
            }
            .login-card {
                border-radius: 12px;
            }
            .login-body {
                padding: 20px;
            }
            .login-header {
                padding: 20px 15px;
            }
            .login-header h2 {
                font-size: 1.5rem;
            }
            .login-header .logo i {
                font-size: 2.5rem !important;
            }
            .form-control {
                padding: 10px 12px;
                font-size: 15px;
            }
            .btn-login {
                padding: 12px;
                font-size: 15px;
            }
        }
        
        .quick-access {
            background: linear-gradient(135deg, #ffd89b, #19547b);
            position: relative;
            overflow: hidden;
        }
        
        .quick-access::before {
            content: '⚡';
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 40px;
            opacity: 0.3;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.3; }
            50% { transform: scale(1.1); opacity: 0.5; }
        }
        
        .security-message {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <!-- Header -->
            <div class="login-header">
                <div class="logo mb-3">
                    <i class="bi bi-check2-circle" style="font-size: 3rem;"></i>
                </div>
                <h2 class="mb-2">TaskFlow Pro</h2>
                <p class="mb-0 opacity-75">Workflow Management System</p>
            </div>
            
            <!-- Login Body -->
            <div class="login-body">
                <!-- Display warning for pending approval -->
                <?php if ($warning): ?>
                    <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-hourglass-split me-2"></i>
                        <div><?= sanitize($warning) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Display security warning for failed attempts -->
                <?php if ($failed_attempts > 0 && $failed_attempts < MAX_LOGIN_ATTEMPTS && !$account_locked): ?>
                    <div class="attempts-warning">
                        <i class="bi bi-exclamation-triangle warning-icon"></i>
                        <strong>Security Alert:</strong> 
                        <?= $failed_attempts ?> failed login attempt<?= $failed_attempts > 1 ? 's' : '' ?>.
                        <?php if ($remaining_attempts > 0): ?>
                            Account will be locked after <?= $remaining_attempts ?> more failed attempt<?= $remaining_attempts > 1 ? 's' : '' ?>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Display account locked message -->
                <?php if ($account_locked): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-shield-lock me-2"></i>
                        <strong>Account Locked:</strong> 
                        Too many failed login attempts. Please try again after <?= LOCKOUT_TIME ?> minutes or contact administrator.
                    </div>
                <?php endif; ?>
                
                <!-- Regular error/success messages -->
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?= sanitize($error) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <div><?= sanitize($success) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Login Form -->
                <form method="POST" action="<?= base_url('login.php') ?>">
                    <!-- Email Input -->
                    <div class="mb-4">
                        <label for="email" class="form-label">
                            <i class="bi bi-envelope me-1"></i> Email Address
                        </label>
                        <input type="email" 
                               class="form-control" 
                               id="email" 
                               name="email" 
                               value="<?= sanitize($_POST['email'] ?? '') ?>" 
                               required
                               autocomplete="email"
                               placeholder="Enter your email address"
                               <?= $account_locked ? 'disabled' : '' ?>>
                        <div class="form-text">Enter your registered email address</div>
                    </div>
                    
                    <!-- Password Input -->
                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <i class="bi bi-lock me-1"></i> Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   required
                                   autocomplete="current-password"
                                   placeholder="Enter your password"
                                   minlength="6"
                                   <?= $account_locked ? 'disabled' : '' ?>>
                            <button type="button" class="password-toggle" id="togglePassword" tabindex="-1">
                                <i class="bi bi-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <div class="form-text">Minimum 6 characters</div>
                            <a href="<?= base_url('forgot_password.php') ?>">
                                Forgot Password?
                            </a>
                        </div>
                    </div>
                    
                    <!-- Remember Me -->
                    <div class="mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember"
                                   <?= $account_locked ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="remember">
                                Remember me on this device
                            </label>
                        </div>
                        <div class="security-message">
                            <i class="bi bi-shield-check"></i>
                            <span>Your login is secured with encryption</span>
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="d-grid mb-4">
                        <button type="submit" class="btn btn-login" id="loginButton"
                                <?= $account_locked ? 'disabled' : '' ?>>
                            <i class="bi bi-box-arrow-in-right me-2"></i> 
                            <span id="buttonText">Sign In</span>
                            <span class="spinner-border spinner-border-sm d-none" id="loginSpinner"></span>
                        </button>
                    </div>
                    
                    <!-- Registration Link -->
                    <div class="text-center mb-3">
                        <p class="mb-2">Don't have an account?</p>
                        <a href="<?= base_url('register.php') ?>" class="btn btn-outline-primary">
                            <i class="bi bi-person-plus me-1"></i> Create Account
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Footer -->
            <div class="login-footer">
                <p class="mb-1">&copy; <?= date('Y') ?> TaskFlow Pro. All rights reserved.</p>
                <p class="small mb-0">
                    <a href="<?= base_url('terms_of_service.php') ?>" class="me-2">Privacy Policy</a> • 
                    <a href="<?= base_url('terms_of_service.php') ?>" class="ms-2">Terms of Service</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle Password Visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
                this.setAttribute('aria-label', 'Hide password');
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'bi bi-eye';
                this.setAttribute('aria-label', 'Show password');
            }
        });
        
        // Form Submission Loading State
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!document.getElementById('email').disabled) {
                const button = document.getElementById('loginButton');
                const buttonText = document.getElementById('buttonText');
                const spinner = document.getElementById('loginSpinner');
                
                button.disabled = true;
                buttonText.textContent = 'Authenticating...';
                spinner.classList.remove('d-none');
                return true;
            }
            e.preventDefault();
            return false;
        });
        
        // Email validation on blur
        document.getElementById('email').addEventListener('blur', function() {
            const email = this.value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (email && !emailRegex.test(email)) {
                this.classList.add('is-invalid');
                let errorDiv = this.parentNode.querySelector('.text-danger');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'text-danger small mt-1';
                    errorDiv.innerHTML = '<i class="bi bi-x-circle me-1"></i>Please enter a valid email address';
                    this.parentNode.appendChild(errorDiv);
                }
            } else {
                this.classList.remove('is-invalid');
                const errorDiv = this.parentNode.querySelector('.text-danger');
                if (errorDiv) errorDiv.remove();
            }
        });
        
        // Auto-hide alerts after 8 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                setTimeout(() => bsAlert.close(), 8000);
            });
        }, 8000);
        
        // Focus on email field on page load
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            if (!emailField.disabled) {
                emailField.focus();
            }
        });
    </script>
</body>
</html>
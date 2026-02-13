<?php
// reset_password.php
require_once 'config/database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$token = $_GET['token'] ?? '';
$errors = [];
$success = false;

// Validate token if provided
if ($token) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_expires > NOW() AND deleted_at IS NULL");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $errors['token'] = 'Invalid or expired reset token. Please request a new password reset link.';
            $token = '';
        }
    } catch (PDOException $e) {
        error_log("Token validation error: " . $e->getMessage());
        $errors['token'] = 'An error occurred. Please try again.';
        $token = '';
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors['password'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors['password'] = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors['password'] = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain at least one number.';
    }
    
    if ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }
    
    // If no errors, update password
    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Update user password and clear reset token
            $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed_password, $user['id']]);
            
            // Log activity
            logActivity($pdo, $user['id'], 'PASSWORD_RESET', 'Password reset successfully');
            
            // Set success message
            displayFlashMessage('success', 'Your password has been reset successfully! You can now login with your new password.');
            
            // Redirect to login page
            redirect('login.php');
            
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $errors['general'] = 'An error occurred. Please try again later.';
        }
    }
}

$title = 'Reset Password';
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
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --danger-color: #dc3545;
            --success-color: #28a745;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
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
        
        .auth-wrapper {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
        }
        
        .auth-card {
            background: white;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            width: 100%;
        }
        
        .auth-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            padding: 25px 20px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .auth-header::after {
            content: '🔑';
            position: absolute;
            bottom: -20px;
            right: -20px;
            font-size: 100px;
            opacity: 0.1;
            transform: rotate(15deg);
        }
        
        .auth-header h2 {
            margin: 0 0 8px 0;
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .auth-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .auth-body {
            padding: 30px;
        }
        
        .form-control {
            border-radius: 8px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            font-size: 15px;
            transition: all 0.3s;
            width: 100%;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            outline: none;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: none;
            border-radius: 8px;
            padding: 14px;
            font-weight: 600;
            font-size: 16px;
            color: white;
            transition: all 0.3s;
            width: 100%;
            cursor: pointer;
        }
        
        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 5px;
            font-size: 18px;
        }
        
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 5px;
            transition: all 0.3s;
            background-color: #e9ecef;
        }
        
        .strength-weak { background-color: var(--danger-color); width: 25%; }
        .strength-fair { background-color: #ffc107; width: 50%; }
        .strength-good { background-color: #17a2b8; width: 75%; }
        .strength-strong { background-color: var(--success-color); width: 100%; }
        
        .requirement {
            font-size: 0.85rem;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .requirement.met {
            color: var(--success-color);
        }
        
        .requirement.unmet {
            color: #6c757d;
        }
        
        .error-message {
            font-size: 0.85rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--danger-color);
        }
        
        .back-to-login {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
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
        
        @media (max-width: 576px) {
            body {
                padding: 15px;
            }
            .auth-wrapper {
                max-width: 100%;
            }
            .auth-card {
                border-radius: 12px;
            }
            .auth-header {
                padding: 20px 15px;
            }
            .auth-body {
                padding: 20px;
            }
            .auth-header h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <!-- Header -->
            <div class="auth-header">
                <h2>
                    <i class="bi bi-shield-lock me-2"></i> Reset Password
                </h2>
                <p>Create a new password for your account</p>
            </div>
            
            <!-- Body -->
            <div class="auth-body">
                <!-- Error/Success Messages -->
                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?= sanitize($errors['general']) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors['token'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?= sanitize($errors['token']) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php 
                $flash_success = getFlashMessage('success');
                if ($flash_success): 
                ?>
                    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <div><?= sanitize($flash_success) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($token): ?>
                    <!-- Reset Password Form -->
                    <form method="POST" action="<?= base_url('reset_password.php?token=' . urlencode($token)) ?>" id="resetPasswordForm">
                        <input type="hidden" name="token" value="<?= sanitize($token) ?>">
                        
                        <!-- New Password -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password *</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                       name="password" id="newPassword" required
                                       placeholder="Enter new password (min. <?= PASSWORD_MIN_LENGTH ?> characters)"
                                       minlength="<?= PASSWORD_MIN_LENGTH ?>">
                                <button type="button" class="password-toggle" id="toggleNewPassword">
                                    <i class="bi bi-eye" id="toggleNewIcon"></i>
                                </button>
                            </div>
                            <div class="password-strength mt-2" id="passwordStrength"></div>
                            <div class="password-requirements mt-2">
                                <div class="requirement unmet" id="reqLength">
                                    <i class="bi bi-circle"></i> At least <?= PASSWORD_MIN_LENGTH ?> characters
                                </div>
                                <div class="requirement unmet" id="reqUppercase">
                                    <i class="bi bi-circle"></i> One uppercase letter
                                </div>
                                <div class="requirement unmet" id="reqLowercase">
                                    <i class="bi bi-circle"></i> One lowercase letter
                                </div>
                                <div class="requirement unmet" id="reqNumber">
                                    <i class="bi bi-circle"></i> One number
                                </div>
                            </div>
                            <?php if (isset($errors['password'])): ?>
                                <div class="error-message">
                                    <i class="bi bi-x-circle me-1"></i><?= sanitize($errors['password']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Confirm Password -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Confirm New Password *</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                       name="confirm_password" id="confirmPassword" required
                                       placeholder="Re-enter new password">
                                <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                    <i class="bi bi-eye" id="toggleConfirmIcon"></i>
                                </button>
                            </div>
                            <div class="text-danger small mt-1" id="passwordMatchError"></div>
                            <?php if (isset($errors['confirm_password'])): ?>
                                <div class="error-message">
                                    <i class="bi bi-x-circle me-1"></i><?= sanitize($errors['confirm_password']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="mb-3">
                            <button type="submit" class="btn btn-submit" id="submitBtn" disabled>
                                <span id="submitText">Reset Password</span>
                                <span id="submitSpinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <!-- Invalid/Expired Token Message -->
                    <div class="text-center py-4">
                        <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                        <h4 class="mt-3">Invalid or Expired Link</h4>
                        <p class="text-muted">The password reset link is invalid or has expired.</p>
                        <a href="<?= base_url('forgot_password.php') ?>" class="btn btn-primary mt-3">
                            <i class="bi bi-envelope me-2"></i>Request New Reset Link
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Back to Login -->
                <div class="back-to-login">
                    <p class="mb-0">
                        <a href="<?= base_url('login.php') ?>" class="text-decoration-none fw-semibold">
                            <i class="bi bi-arrow-left me-1"></i>Back to Login
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($token): ?>
            // DOM Elements
            const form = document.getElementById('resetPasswordForm');
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            const submitSpinner = document.getElementById('submitSpinner');
            const newPassword = document.getElementById('newPassword');
            const confirmPassword = document.getElementById('confirmPassword');
            const passwordMatchError = document.getElementById('passwordMatchError');
            const toggleNewPassword = document.getElementById('toggleNewPassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const toggleNewIcon = document.getElementById('toggleNewIcon');
            const toggleConfirmIcon = document.getElementById('toggleConfirmIcon');
            const passwordStrength = document.getElementById('passwordStrength');
            
            // Password requirements elements
            const reqLength = document.getElementById('reqLength');
            const reqUppercase = document.getElementById('reqUppercase');
            const reqLowercase = document.getElementById('reqLowercase');
            const reqNumber = document.getElementById('reqNumber');
            
            // Password Strength Checker
            newPassword.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                const hasLength = password.length >= <?= PASSWORD_MIN_LENGTH ?>;
                const hasUppercase = /[A-Z]/.test(password);
                const hasLowercase = /[a-z]/.test(password);
                const hasNumber = /[0-9]/.test(password);
                
                updateRequirement(reqLength, hasLength);
                updateRequirement(reqUppercase, hasUppercase);
                updateRequirement(reqLowercase, hasLowercase);
                updateRequirement(reqNumber, hasNumber);
                
                if (hasLength) strength++;
                if (hasUppercase) strength++;
                if (hasLowercase) strength++;
                if (hasNumber) strength++;
                
                updateStrengthBar(strength);
                checkPasswordMatch();
                validateForm();
            });
            
            confirmPassword.addEventListener('input', function() {
                checkPasswordMatch();
                validateForm();
            });
            
            function checkPasswordMatch() {
                const password = newPassword.value;
                const confirm = confirmPassword.value;
                
                if (confirm === '') {
                    passwordMatchError.textContent = '';
                    return;
                }
                
                if (password === confirm) {
                    passwordMatchError.textContent = '';
                    passwordMatchError.className = 'text-success small mt-1';
                    passwordMatchError.innerHTML = '<i class="bi bi-check-circle me-1"></i>Passwords match';
                } else {
                    passwordMatchError.className = 'text-danger small mt-1';
                    passwordMatchError.innerHTML = '<i class="bi bi-x-circle me-1"></i>Passwords do not match';
                }
            }
            
            function updateRequirement(element, condition) {
                if (condition) {
                    element.classList.remove('unmet');
                    element.classList.add('met');
                    element.innerHTML = '<i class="bi bi-check-circle"></i>' + element.textContent.replace(/^[^A-Za-z]+/, '');
                } else {
                    element.classList.remove('met');
                    element.classList.add('unmet');
                    element.innerHTML = '<i class="bi bi-circle"></i>' + element.textContent.replace(/^[^A-Za-z]+/, '');
                }
            }
            
            function updateStrengthBar(strength) {
                passwordStrength.className = 'password-strength mt-2';
                
                if (strength === 0 || strength === 1) {
                    passwordStrength.classList.add('strength-weak');
                } else if (strength === 2) {
                    passwordStrength.classList.add('strength-fair');
                } else if (strength === 3) {
                    passwordStrength.classList.add('strength-good');
                } else if (strength === 4) {
                    passwordStrength.classList.add('strength-strong');
                }
            }
            
            // Toggle Password Visibility
            toggleNewPassword.addEventListener('click', function() {
                const type = newPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                newPassword.setAttribute('type', type);
                toggleNewIcon.classList.toggle('bi-eye');
                toggleNewIcon.classList.toggle('bi-eye-slash');
            });
            
            toggleConfirmPassword.addEventListener('click', function() {
                const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPassword.setAttribute('type', type);
                toggleConfirmIcon.classList.toggle('bi-eye');
                toggleConfirmIcon.classList.toggle('bi-eye-slash');
            });
            
            function validateForm() {
                const password = newPassword.value;
                const confirm = confirmPassword.value;
                
                const hasLength = password.length >= <?= PASSWORD_MIN_LENGTH ?>;
                const hasUppercase = /[A-Z]/.test(password);
                const hasLowercase = /[a-z]/.test(password);
                const hasNumber = /[0-9]/.test(password);
                
                const passwordValid = hasLength && hasUppercase && hasLowercase && hasNumber;
                const passwordsMatch = password === confirm && confirm !== '';
                
                const isValid = passwordValid && passwordsMatch;
                
                submitBtn.disabled = !isValid;
                
                return isValid;
            }
            
            form.addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return;
                }
                
                submitBtn.disabled = true;
                submitText.textContent = 'Resetting...';
                submitSpinner.classList.remove('d-none');
            });
            
            newPassword.addEventListener('input', validateForm);
            confirmPassword.addEventListener('input', validateForm);
            
            validateForm();
            <?php endif; ?>
        });
    </script>
</body>
</html>
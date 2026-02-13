<?php
// register.php
require_once 'config/database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errors = [];
$form_data = $_POST;

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? '';
    $terms = isset($_POST['terms']);
    
    // Validation
    if (empty($full_name)) {
        $errors['full_name'] = 'Full name is required.';
    } elseif (strlen($full_name) < 2) {
        $errors['full_name'] = 'Full name must be at least 2 characters.';
    }
    
    if (empty($email)) {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } else {
        // Check if email already exists
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $errors['email'] = 'This email is already registered. Please use a different email or login.';
            }
        } catch (PDOException $e) {
            error_log("Email check error: " . $e->getMessage());
        }
    }
    
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
    
    if (empty($role)) {
        $errors['role'] = 'Please select a role.';
    } elseif (!in_array($role, ['admin', 'manager', 'member', 'client'])) {
        $errors['role'] = 'Invalid role selected.';
    }
    
    if (!$terms) {
        $errors['terms'] = 'You must agree to the Terms of Service and Privacy Policy.';
    }
    
    // If no errors, create account
    if (empty($errors)) {
        try {
            // Determine account status based on role
            $status = ($role === 'client') ? 'active' : 'pending';
            
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$full_name, $email, $hashed_password, $role, $status]);
            
            $user_id = $pdo->lastInsertId();
            
            // Log activity
            logActivity($pdo, $user_id, 'REGISTER', "New account created with role: {$role}");
            
            // Set success message
            if ($status === 'pending') {
                displayFlashMessage('success', 'Your account has been created and is pending approval. You will receive an email once your account is activated.');
            } else {
                displayFlashMessage('success', 'Your account has been created successfully! You can now login.');
            }
            
            // Redirect to login page
            redirect('login.php');
            
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            displayFlashMessage('error', 'An error occurred during registration. Please try again later.');
        }
    }
}

$title = 'Register';
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
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-gray: #f8f9fa;
            --border-color: #dee2e6;
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
        
        .register-wrapper {
            width: 100%;
            max-width: 550px;
            margin: 0 auto;
            padding: 0;
        }
        
        .register-card {
            background: white;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            width: 100%;
        }
        
        .register-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            padding: 25px 20px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .register-header::after {
            content: '📝';
            position: absolute;
            bottom: -20px;
            right: -20px;
            font-size: 100px;
            opacity: 0.1;
            transform: rotate(15deg);
        }
        
        .register-header h2 {
            margin: 0 0 8px 0;
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .register-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .register-body {
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
            width: 100%;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
        }
        
        .form-control {
            border-radius: 8px;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            font-size: 15px;
            transition: all 0.3s;
            width: 100%;
            background-color: white;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            outline: none;
        }
        
        .form-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
            display: block;
        }
        
        .btn-register {
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
            margin-top: 5px;
        }
        
        .btn-register:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-register:disabled {
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
            display: flex;
            align-items: flex-start;
        }
        
        .alert i {
            margin-right: 10px;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        
        .alert-content {
            flex: 1;
        }
        
        .alert-dismissible {
            padding-right: 45px;
            position: relative;
        }
        
        .alert-dismissible .btn-close {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            padding: 10px;
        }
        
        .register-footer {
            text-align: center;
            padding: 20px;
            border-top: 1px solid var(--border-color);
            font-size: 14px;
            color: #666;
            background: var(--light-gray);
        }
        
        .role-selection-container {
            margin-bottom: 20px;
        }
        
        .role-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .role-card {
            border: 2px solid #dee2e6;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s;
            background: white;
            height: 100%;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }
        
        .role-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .role-card.selected {
            border-color: var(--primary-color);
            background: rgba(102, 126, 234, 0.03);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .role-card-header {
            padding: 20px 20px 15px;
            border-bottom: 1px solid #dee2e6;
            background: #f8f9fa;
        }
        
        .role-icon-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .role-icon {
            font-size: 1.5rem;
            color: var(--primary-color);
        }
        
        .role-title {
            margin: 0 0 10px 0;
            font-size: 1.25rem;
            color: #333;
        }
        
        .role-status {
            margin-top: 5px;
        }
        
        .role-card-body {
            padding: 20px;
            flex: 1;
        }
        
        .role-description {
            margin-bottom: 20px;
        }
        
        .role-description p {
            color: #666;
            margin-bottom: 0;
        }
        
        .section-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .permissions-list {
            list-style: none;
            padding: 0;
            margin: 0 0 20px 0;
        }
        
        .permissions-list li {
            font-size: 0.85rem;
            padding: 6px 0;
            display: flex;
            align-items: flex-start;
            color: #555;
        }
        
        .permission-icon {
            margin-right: 10px;
            font-size: 14px;
        }
        
        .permission-allowed {
            color: var(--success-color);
        }
        
        .permission-denied {
            color: var(--danger-color);
        }
        
        .suitable-for {
            margin-top: 20px;
        }
        
        .role-card-footer {
            padding: 15px 20px;
            border-top: 1px solid #dee2e6;
            background: #f8f9fa;
        }
        
        .select-role-btn {
            width: 100%;
        }
        
        .password-wrapper {
            position: relative;
            width: 100%;
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
            z-index: 2;
            line-height: 1;
        }
        
        .password-toggle:hover {
            color: #333;
        }
        
        .password-strength-container {
            margin-top: 10px;
        }
        
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 5px;
            transition: all 0.3s;
            background-color: #e9ecef;
        }
        
        .strength-weak { background-color: var(--danger-color); width: 25%; }
        .strength-fair { background-color: var(--warning-color); width: 50%; }
        .strength-good { background-color: var(--info-color); width: 75%; }
        .strength-strong { background-color: var(--success-color); width: 100%; }
        
        .password-requirements {
            margin-top: 10px;
        }
        
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
        
        .required-field {
            color: #dc3545;
            margin-left: 3px;
        }
        
        @media (max-width: 576px) {
            .register-wrapper {
                padding: 10px;
                max-width: 100%;
            }
            .register-card {
                border-radius: 12px;
            }
            .register-body {
                padding: 20px;
            }
            .register-header {
                padding: 20px 15px;
            }
            .register-header h2 {
                font-size: 1.5rem;
            }
            .role-cards-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="register-wrapper">
        <div class="register-card">
            <!-- Header -->
            <div class="register-header">
                <h2 class="mb-2">
                    <i class="bi bi-person-plus me-2"></i> Create Account
                </h2>
                <p class="mb-0 opacity-75">Join TaskFlow Pro Management System</p>
            </div>
            
            <!-- Registration Body -->
            <div class="register-body">
                <!-- Error/Success Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div>
                            <strong>Please fix the following errors:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= sanitize($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php 
                $flash_error = getFlashMessage('error');
                if ($flash_error): 
                ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?= sanitize($flash_error) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Registration Form -->
                <form method="POST" action="<?= base_url('register.php') ?>">
                    <!-- Full Name -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="required-field">*</span></label>
                        <input type="text" class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" 
                               name="full_name" required
                               value="<?= sanitize($form_data['full_name'] ?? '') ?>"
                               placeholder="Enter your full name">
                        <?php if (isset($errors['full_name'])): ?>
                            <div class="error-message">
                                <i class="bi bi-x-circle me-1"></i><?= sanitize($errors['full_name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Email -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email Address <span class="required-field">*</span></label>
                        <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                               name="email" required
                               value="<?= sanitize($form_data['email'] ?? '') ?>"
                               placeholder="Enter your email">
                        <div class="form-text">We'll never share your email with anyone else.</div>
                        <?php if (isset($errors['email'])): ?>
                            <div class="error-message">
                                <i class="bi bi-x-circle me-1"></i><?= sanitize($errors['email']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Password -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="required-field">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                   name="password" id="regPassword" required
                                   placeholder="Create a password (min. <?= PASSWORD_MIN_LENGTH ?> characters)"
                                   minlength="<?= PASSWORD_MIN_LENGTH ?>">
                            <button type="button" class="password-toggle" id="toggleRegPassword">
                                <i class="bi bi-eye" id="toggleRegIcon"></i>
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
                        <label class="form-label fw-semibold">Confirm Password <span class="required-field">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                   name="confirm_password" id="confirmPassword" required
                                   placeholder="Re-enter your password">
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
                    
                    <!-- Role Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Select Your Role <span class="required-field">*</span></label>
                        
                        <!-- Role Comparison Table -->
                        <div class="role-comparison mb-3">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Role</th>
                                            <th>Access Time</th>
                                            <th>Primary Function</th>
                                            <th>Permissions Level</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong>Team Member</strong></td>
                                            <td><span class="badge bg-warning">After Approval</span></td>
                                            <td>Execute tasks</td>
                                            <td>Limited</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Manager</strong></td>
                                            <td><span class="badge bg-warning">After Approval</span></td>
                                            <td>Manage team & tasks</td>
                                            <td>Elevated</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Client</strong></td>
                                            <td><span class="badge bg-success">Immediate</span></td>
                                            <td>Track projects</td>
                                            <td>Read-only</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="role-cards-container">
                            <!-- Team Member Card -->
                            <div class="role-card <?= ($form_data['role'] ?? '') === 'member' ? 'selected' : '' ?>" data-role="member">
                                <div class="role-card-header">
                                    <div class="role-icon-wrapper">
                                        <i class="bi bi-people-fill role-icon"></i>
                                        <span class="badge bg-primary">Staff</span>
                                    </div>
                                    <h5 class="role-title">Team Member</h5>
                                    <div class="role-status">
                                        <span class="badge bg-warning">
                                            <i class="bi bi-hourglass-split me-1"></i>Pending Approval
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="role-card-body">
                                    <div class="role-description">
                                        <p class="small mb-2">Join as working staff to execute assigned tasks and collaborate with team.</p>
                                    </div>
                                    
                                    <div class="permissions-section">
                                        <h6 class="section-title">
                                            <i class="bi bi-shield-check me-1"></i>Permissions
                                        </h6>
                                        <ul class="permissions-list">
                                            <li class="permission-item">
                                                <span class="permission-icon permission-allowed"><i class="bi bi-check-circle-fill"></i></span>
                                                <span>View & update assigned tasks</span>
                                            </li>
                                            <li class="permission-item">
                                                <span class="permission-icon permission-allowed"><i class="bi bi-check-circle-fill"></i></span>
                                                <span>Submit completed work</span>
                                            </li>
                                            <li class="permission-item">
                                                <span class="permission-icon permission-denied"><i class="bi bi-x-circle-fill"></i></span>
                                                <span>Cannot assign tasks to others</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="role-card-footer">
                                    <button type="button" class="btn btn-outline-primary btn-sm select-role-btn" data-role="member">
                                        <i class="bi bi-check-lg me-1"></i>Select Team Member
                                    </button>
                                </div>
                                <input type="radio" name="role" value="member" <?= ($form_data['role'] ?? '') === 'member' ? 'checked' : '' ?> required style="display: none;">
                            </div>
                            
                            <!-- Manager Card -->
                            <div class="role-card <?= ($form_data['role'] ?? '') === 'manager' ? 'selected' : '' ?>" data-role="manager">
                                <div class="role-card-header">
                                    <div class="role-icon-wrapper">
                                        <i class="bi bi-person-workspace role-icon"></i>
                                        <span class="badge bg-primary">Management</span>
                                    </div>
                                    <h5 class="role-title">Manager</h5>
                                    <div class="role-status">
                                        <span class="badge bg-warning">
                                            <i class="bi bi-hourglass-split me-1"></i>Pending Approval
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="role-card-body">
                                    <div class="role-description">
                                        <p class="small mb-2">Lead teams, assign tasks, and monitor project progress.</p>
                                    </div>
                                    
                                    <div class="permissions-section">
                                        <h6 class="section-title">
                                            <i class="bi bi-shield-check me-1"></i>Permissions
                                        </h6>
                                        <ul class="permissions-list">
                                            <li class="permission-item">
                                                <span class="permission-icon permission-allowed"><i class="bi bi-check-circle-fill"></i></span>
                                                <span>Create & assign tasks</span>
                                            </li>
                                            <li class="permission-item">
                                                <span class="permission-icon permission-allowed"><i class="bi bi-check-circle-fill"></i></span>
                                                <span>Manage team members</span>
                                            </li>
                                            <li class="permission-item">
                                                <span class="permission-icon permission-allowed"><i class="bi bi-check-circle-fill"></i></span>
                                                <span>View all team tasks</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="role-card-footer">
                                    <button type="button" class="btn btn-outline-primary btn-sm select-role-btn" data-role="manager">
                                        <i class="bi bi-check-lg me-1"></i>Select Manager
                                    </button>
                                </div>
                                <input type="radio" name="role" value="manager" <?= ($form_data['role'] ?? '') === 'manager' ? 'checked' : '' ?> required style="display: none;">
                            </div>
                            
                            <!-- Client Card -->
                            <div class="role-card <?= ($form_data['role'] ?? '') === 'client' ? 'selected' : '' ?>" data-role="client">
                                <div class="role-card-header">
                                    <div class="role-icon-wrapper">
                                        <i class="bi bi-briefcase-fill role-icon"></i>
                                        <span class="badge bg-info">External</span>
                                    </div>
                                    <h5 class="role-title">Client / Customer</h5>
                                    <div class="role-status">
                                        <span class="badge bg-success">
                                            <i class="bi bi-lightning-charge me-1"></i>Immediate Access
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="role-card-body">
                                    <div class="role-description">
                                        <p class="small mb-2">External customer to submit requests, track progress, and communicate with team.</p>
                                    </div>
                                    
                                    <div class="permissions-section">
                                        <h6 class="section-title">
                                            <i class="bi bi-shield-check me-1"></i>Permissions
                                        </h6>
                                        <ul class="permissions-list">
                                            <li class="permission-item">
                                                <span class="permission-icon permission-allowed"><i class="bi bi-check-circle-fill"></i></span>
                                                <span>Submit service requests</span>
                                            </li>
                                            <li class="permission-item">
                                                <span class="permission-icon permission-allowed"><i class="bi bi-check-circle-fill"></i></span>
                                                <span>Track project progress</span>
                                            </li>
                                            <li class="permission-item">
                                                <span class="permission-icon permission-denied"><i class="bi bi-x-circle-fill"></i></span>
                                                <span>Read-only access</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="role-card-footer">
                                    <button type="button" class="btn btn-outline-primary btn-sm select-role-btn" data-role="client">
                                        <i class="bi bi-check-lg me-1"></i>Select Client
                                    </button>
                                </div>
                                <input type="radio" name="role" value="client" <?= ($form_data['role'] ?? '') === 'client' ? 'checked' : '' ?> required style="display: none;">
                            </div>
                        </div>
                        
                        <?php if (isset($errors['role'])): ?>
                            <div class="error-message mt-2">
                                <i class="bi bi-x-circle me-1"></i><?= sanitize($errors['role']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Terms & Conditions -->
                    <div class="mb-4 form-check">
                        <input type="checkbox" class="form-check-input" id="termsCheck" name="terms" <?= isset($form_data['terms']) ? 'checked' : '' ?> required>
                        <label class="form-check-label" for="termsCheck">
                            I agree to the <a href="<?= base_url('terms_of_service.php') ?>" target="_blank" class="text-decoration-none">Terms of Service</a> 
                            and <a href="<?= base_url('privacy_policy.php') ?>" target="_blank" class="text-decoration-none">Privacy Policy</a> <span class="required-field">*</span>
                        </label>
                        <?php if (isset($errors['terms'])): ?>
                            <div class="error-message">
                                <i class="bi bi-x-circle me-1"></i><?= sanitize($errors['terms']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Admin Note (Hidden) -->
                    <input type="hidden" name="role_admin" value="admin" disabled>
                    
                    <!-- Submit Button -->
                    <div class="mb-3">
                        <button type="submit" class="btn btn-register" id="registerBtn" disabled>
                            <span id="registerText">Create Account</span>
                            <span id="registerSpinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                        </button>
                    </div>
                    
                    <!-- Login Link -->
                    <div class="text-center mt-4">
                        <p class="mb-0">
                            Already have an account? 
                            <a href="<?= base_url('login.php') ?>" class="text-decoration-none fw-semibold">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
                            </a>
                        </p>
                    </div>
                </form>
            </div>
            
            <!-- Footer -->
            <div class="register-footer">
                <p class="mb-0">
                    &copy; <?= date('Y') ?> TaskFlow Pro. All rights reserved.<br>
                    <small>v3.0.0</small>
                </p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // DOM Elements
            const registerForm = document.getElementById('registerForm');
            const registerBtn = document.getElementById('registerBtn');
            const termsCheck = document.getElementById('termsCheck');
            const roleCards = document.querySelectorAll('.role-card');
            const regPassword = document.getElementById('regPassword');
            const confirmPassword = document.getElementById('confirmPassword');
            const passwordMatchError = document.getElementById('passwordMatchError');
            const toggleRegPassword = document.getElementById('toggleRegPassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const toggleRegIcon = document.getElementById('toggleRegIcon');
            const toggleConfirmIcon = document.getElementById('toggleConfirmIcon');
            const passwordStrength = document.getElementById('passwordStrength');
            
            // Password requirements elements
            const reqLength = document.getElementById('reqLength');
            const reqUppercase = document.getElementById('reqUppercase');
            const reqLowercase = document.getElementById('reqLowercase');
            const reqNumber = document.getElementById('reqNumber');
            
            let selectedRole = null;
            
            // Function to select a role
            function selectRole(role) {
                roleCards.forEach(card => {
                    card.classList.remove('selected');
                    const radioInput = card.querySelector('input[type="radio"]');
                    if (radioInput) radioInput.checked = false;
                    
                    const button = card.querySelector('.select-role-btn');
                    if (button) {
                        button.classList.remove('btn-primary');
                        button.classList.add('btn-outline-primary');
                    }
                });
                
                const selectedCard = document.querySelector(`[data-role="${role}"]`);
                if (selectedCard) {
                    selectedCard.classList.add('selected');
                    const radioInput = selectedCard.querySelector('input[type="radio"]');
                    if (radioInput) {
                        radioInput.checked = true;
                        selectedRole = role;
                    }
                    
                    const button = selectedCard.querySelector('.select-role-btn');
                    if (button) {
                        button.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Selected';
                        button.classList.remove('btn-outline-primary');
                        button.classList.add('btn-primary');
                    }
                }
                
                validateForm();
            }
            
            // Role Selection for cards
            roleCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    if (e.target.closest('.select-role-btn')) return;
                    const role = this.getAttribute('data-role');
                    selectRole(role);
                });
            });
            
            // Role selection buttons
            document.querySelectorAll('.select-role-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const role = this.getAttribute('data-role');
                    selectRole(role);
                });
            });
            
            // Auto-select role if already selected
            const checkedRole = document.querySelector('input[name="role"]:checked');
            if (checkedRole) {
                selectRole(checkedRole.value);
            }
            
            // Password Strength Checker
            function checkPasswordStrength(password) {
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
                
                return { strength, hasLength, hasUppercase, hasLowercase, hasNumber };
            }
            
            regPassword.addEventListener('input', function() {
                const password = this.value;
                const result = checkPasswordStrength(password);
                updateStrengthBar(result.strength);
                checkPasswordMatch();
                validateForm();
            });
            
            confirmPassword.addEventListener('input', function() {
                checkPasswordMatch();
                validateForm();
            });
            
            function checkPasswordMatch() {
                const password = regPassword.value;
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
            toggleRegPassword.addEventListener('click', function() {
                const type = regPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                regPassword.setAttribute('type', type);
                toggleRegIcon.classList.toggle('bi-eye');
                toggleRegIcon.classList.toggle('bi-eye-slash');
            });
            
            toggleConfirmPassword.addEventListener('click', function() {
                const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPassword.setAttribute('type', type);
                toggleConfirmIcon.classList.toggle('bi-eye');
                toggleConfirmIcon.classList.toggle('bi-eye-slash');
            });
            
            termsCheck.addEventListener('change', validateForm);
            
            function validateForm() {
                const password = regPassword.value;
                const confirm = confirmPassword.value;
                const termsAccepted = termsCheck.checked;
                
                const passwordResult = checkPasswordStrength(password);
                const passwordValid = passwordResult.hasLength && 
                                     passwordResult.hasUppercase && 
                                     passwordResult.hasLowercase && 
                                     passwordResult.hasNumber;
                
                const passwordsMatch = password === confirm && confirm !== '';
                
                let isValid = true;
                
                const requiredFields = registerForm.querySelectorAll('[required]');
                requiredFields.forEach(field => {
                    if (field.type === 'checkbox') {
                        if (!field.checked) isValid = false;
                    } else if (field.type === 'radio') {
                        const radioName = field.name;
                        const radioChecked = document.querySelector(`input[name="${radioName}"]:checked`);
                        if (!radioChecked) isValid = false;
                    } else if (!field.value.trim()) {
                        isValid = false;
                    }
                });
                
                if (!selectedRole && !document.querySelector('input[name="role"]:checked')) isValid = false;
                if (!passwordValid) isValid = false;
                if (!passwordsMatch) isValid = false;
                if (!termsAccepted) isValid = false;
                
                registerBtn.disabled = !isValid;
                
                return isValid;
            }
            
            registerForm.addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    alert('Please fill all required fields correctly.');
                    return;
                }
                
                const registerText = document.getElementById('registerText');
                const registerSpinner = document.getElementById('registerSpinner');
                
                registerBtn.disabled = true;
                registerText.textContent = 'Creating Account...';
                registerSpinner.classList.remove('d-none');
            });
            
            const formInputs = registerForm.querySelectorAll('input');
            formInputs.forEach(input => {
                input.addEventListener('input', validateForm);
                input.addEventListener('change', validateForm);
                input.addEventListener('blur', validateForm);
            });
            
            validateForm();
        });
    </script>
</body>
</html>
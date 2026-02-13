<?php
// profile.php
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access your profile.');
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Get user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        redirect('login.php');
    }
} catch (PDOException $e) {
    error_log("Profile fetch error: " . $e->getMessage());
    $error_message = 'Unable to load profile data.';
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Update profile information
    if ($action === 'update_profile') {
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
        
        // Check if email already exists (excluding current user)
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL");
                $stmt->execute([$email, $user_id]);
                if ($stmt->rowCount() > 0) {
                    $errors[] = 'Email address is already in use by another account.';
                }
            } catch (PDOException $e) {
                error_log("Email check error: " . $e->getMessage());
                $errors[] = 'Unable to verify email availability.';
            }
        }
        
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$full_name, $email, $user_id]);
                
                // Update session
                $_SESSION['user_name'] = $full_name;
                $_SESSION['user_email'] = $email;
                
                // Log activity
                logActivity($pdo, $user_id, 'PROFILE_UPDATE', 'Updated profile information');
                
                $success_message = 'Profile updated successfully!';
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                $error_message = 'Unable to update profile. Please try again.';
            }
        } else {
            $error_message = implode(' ', $errors);
        }
    }
    
    // Change password
    elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $errors = [];
        
        // Verify current password
        if (!password_verify($current_password, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        }
        
        // Validate new password
        if (empty($new_password)) {
            $errors[] = 'New password is required.';
        } elseif (strlen($new_password) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match.';
        }
        
        if (empty($errors)) {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                logActivity($pdo, $user_id, 'PASSWORD_CHANGE', 'Changed account password');
                
                $success_message = 'Password changed successfully!';
                
            } catch (PDOException $e) {
                error_log("Password change error: " . $e->getMessage());
                $error_message = 'Unable to change password. Please try again.';
            }
        } else {
            $error_message = implode(' ', $errors);
        }
    }
    
    // Upload profile picture
    elseif ($action === 'upload_avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = $_FILES['avatar']['type'];
            $file_size = $_FILES['avatar']['size'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($file_type, $allowed_types)) {
                $error_message = 'Only JPG, PNG, and GIF images are allowed.';
            } elseif ($file_size > $max_size) {
                $error_message = 'File size must be less than 2MB.';
            } else {
                $upload_dir = 'uploads/avatars/';
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                $file_name = 'user_' . $user_id . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $file_path)) {
                    // Delete old avatar if exists
                    if (!empty($user['avatar']) && file_exists($user['avatar'])) {
                        unlink($user['avatar']);
                    }
                    
                    // Update database with avatar path
                    $stmt = $pdo->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$file_path, $user_id]);
                    
                    logActivity($pdo, $user_id, 'AVATAR_UPDATE', 'Updated profile picture');
                    
                    $success_message = 'Profile picture uploaded successfully!';
                    
                    // Refresh user data
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                } else {
                    $error_message = 'Failed to upload file.';
                }
            }
        } else {
            $error_message = 'Please select a file to upload.';
        }
    }
}

// Get user statistics
try {
    // Task statistics
    $stmt = $pdo->prepare("SELECT 
                          COUNT(*) as total_tasks,
                          SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                          SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                          SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks
                          FROM tasks 
                          WHERE assigned_to = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    $task_stats = $stmt->fetch();
    
    // Projects count (for managers)
    if ($user['role'] === 'manager') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_projects FROM projects WHERE created_by = ? AND deleted_at IS NULL");
        $stmt->execute([$user_id]);
        $project_stats = $stmt->fetch();
    }
    
    // Recent activity
    $stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $recent_activities = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Stats fetch error: " . $e->getMessage());
}

$title = 'My Profile';
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
        }
        
        body {
            background-color: #f8f9fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
            color: rgba(255,255,255,0.8);
            padding: 0.8rem 1.5rem;
            margin: 0.2rem 0;
            transition: all 0.3s;
        }
        
        .sidebar-nav .nav-link:hover,
        .sidebar-nav .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left: 4px solid white;
        }
        
        .sidebar-nav .nav-link i {
            margin-right: 10px;
            width: 20px;
        }
        
        .main-content {
            padding: 2rem;
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 15px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::after {
            content: '👤';
            position: absolute;
            bottom: -20px;
            right: -20px;
            font-size: 100px;
            opacity: 0.1;
            transform: rotate(15deg);
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            object-fit: cover;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1rem;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eef2f7;
            padding: 1.2rem 1.5rem;
            font-weight: 600;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 0.8rem 1.5rem;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            background: none;
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
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .main-content {
                padding: 1rem;
            }
            .profile-header {
                padding: 1.5rem;
            }
            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 sidebar">
                <div class="sidebar-brand">
                    <h4>TaskFlow Pro</h4>
                    <p>v3.0.0</p>
                </div>
                
                <div class="sidebar-nav">
                    <div class="nav flex-column">
                        <a href="dashboard.php" class="nav-link">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        
                        <?php if ($user['role'] === 'admin'): ?>
                            <a href="users.php" class="nav-link">
                                <i class="bi bi-people"></i> User Management
                            </a>
                            <a href="approvals.php" class="nav-link">
                                <i class="bi bi-person-check"></i> Approvals
                            </a>
                        <?php elseif ($user['role'] === 'manager'): ?>
                            <a href="projects.php" class="nav-link">
                                <i class="bi bi-kanban"></i> Projects
                            </a>
                            <a href="tasks.php" class="nav-link">
                                <i class="bi bi-check2-square"></i> Tasks
                            </a>
                            <a href="team.php" class="nav-link">
                                <i class="bi bi-people"></i> My Team
                            </a>
                        <?php elseif ($user['role'] === 'member'): ?>
                            <a href="my-tasks.php" class="nav-link">
                                <i class="bi bi-list-task"></i> My Tasks
                            </a>
                            <a href="calendar.php" class="nav-link">
                                <i class="bi bi-calendar"></i> Calendar
                            </a>
                        <?php elseif ($user['role'] === 'client'): ?>
                            <a href="my-projects.php" class="nav-link">
                                <i class="bi bi-folder"></i> My Projects
                            </a>
                            <a href="requests.php" class="nav-link">
                                <i class="bi bi-envelope"></i> Requests
                            </a>
                        <?php endif; ?>
                        
                        <a href="profile.php" class="nav-link active">
                            <i class="bi bi-person"></i> Profile
                        </a>
                        <a href="logout.php" class="nav-link text-danger">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 ms-sm-auto px-0">
                <!-- Top Navigation -->
                <nav class="navbar navbar-light bg-white px-4 py-3 shadow-sm">
                    <div class="container-fluid">
                        <h5 class="mb-0">My Profile</h5>
                        <div class="d-flex align-items-center">
                            <span class="me-3">
                                <i class="bi bi-briefcase me-1"></i> <?= ucfirst($user['role']) ?>
                            </span>
                            <div class="user-avatar">
                                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                            </div>
                        </div>
                    </div>
                </nav>
                
                <!-- Main Content Area -->
                <div class="main-content">
                    <!-- Alert Messages -->
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?= sanitize($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?= sanitize($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <?php if (!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                                    <img src="<?= $user['avatar'] ?>" alt="Avatar" class="profile-avatar">
                                <?php else: ?>
                                    <div class="profile-avatar">
                                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col">
                                <h2 class="mb-1"><?= sanitize($user['full_name']) ?></h2>
                                <p class="mb-2">
                                    <i class="bi bi-envelope me-2"></i><?= sanitize($user['email']) ?>
                                </p>
                                <div>
                                    <span class="badge bg-white text-primary">
                                        <i class="bi bi-shield me-1"></i><?= ucfirst($user['role']) ?>
                                    </span>
                                    <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'warning' ?> ms-2">
                                        <i class="bi bi-circle-fill me-1" style="font-size: 8px;"></i>
                                        <?= ucfirst($user['status']) ?>
                                    </span>
                                    <span class="badge bg-light text-dark ms-2">
                                        <i class="bi bi-calendar me-1"></i>Joined: <?= date('M d, Y', strtotime($user['created_at'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile Tabs -->
                    <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button">
                                <i class="bi bi-house-door me-2"></i>Overview
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button">
                                <i class="bi bi-person-gear me-2"></i>Edit Profile
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button">
                                <i class="bi bi-shield-lock me-2"></i>Security
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button">
                                <i class="bi bi-clock-history me-2"></i>Activity Log
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tab Content -->
                    <div class="tab-content">
                        <!-- Overview Tab -->
                        <div class="tab-pane fade show active" id="overview" role="tabpanel">
                            <div class="row">
                                <div class="col-lg-8">
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <i class="bi bi-grid me-2"></i>Account Overview
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="text-muted small mb-1">Full Name</label>
                                                    <p class="h6"><?= sanitize($user['full_name']) ?></p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="text-muted small mb-1">Email Address</label>
                                                    <p class="h6"><?= sanitize($user['email']) ?></p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="text-muted small mb-1">Role</label>
                                                    <p class="h6"><span class="badge bg-primary"><?= ucfirst($user['role']) ?></span></p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="text-muted small mb-1">Account Status</label>
                                                    <p class="h6">
                                                        <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'warning' ?>">
                                                            <?= ucfirst($user['status']) ?>
                                                        </span>
                                                    </p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="text-muted small mb-1">Member Since</label>
                                                    <p class="h6"><?= date('F d, Y', strtotime($user['created_at'])) ?></p>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="text-muted small mb-1">Last Updated</label>
                                                    <p class="h6"><?= date('F d, Y', strtotime($user['updated_at'])) ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Statistics -->
                                    <div class="row">
                                        <?php if ($user['role'] === 'member'): ?>
                                        <div class="col-md-4 mb-4">
                                            <div class="stat-card">
                                                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                                    <i class="bi bi-list-task"></i>
                                                </div>
                                                <div class="stat-value h3"><?= $task_stats['total_tasks'] ?? 0 ?></div>
                                                <div class="stat-label text-muted">Total Tasks</div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-4">
                                            <div class="stat-card">
                                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                                    <i class="bi bi-check-circle"></i>
                                                </div>
                                                <div class="stat-value h3"><?= $task_stats['completed_tasks'] ?? 0 ?></div>
                                                <div class="stat-label text-muted">Completed</div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-4">
                                            <div class="stat-card">
                                                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                                    <i class="bi bi-hourglass"></i>
                                                </div>
                                                <div class="stat-value h3"><?= ($task_stats['pending_tasks'] ?? 0) + ($task_stats['in_progress_tasks'] ?? 0) ?></div>
                                                <div class="stat-label text-muted">In Progress</div>
                                            </div>
                                        </div>
                                        <?php elseif ($user['role'] === 'manager'): ?>
                                        <div class="col-md-6 mb-4">
                                            <div class="stat-card">
                                                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                                    <i class="bi bi-kanban"></i>
                                                </div>
                                                <div class="stat-value h3"><?= $project_stats['total_projects'] ?? 0 ?></div>
                                                <div class="stat-label text-muted">Projects Managed</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <div class="stat-card">
                                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                                    <i class="bi bi-people"></i>
                                                </div>
                                                <div class="stat-value h3"><?= $team_count ?? 0 ?></div>
                                                <div class="stat-label text-muted">Team Members</div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-lg-4">
                                    <!-- Profile Picture -->
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <i class="bi bi-camera me-2"></i>Profile Picture
                                        </div>
                                        <div class="card-body text-center">
                                            <?php if (!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                                                <img src="<?= $user['avatar'] ?>" alt="Avatar" class="rounded-circle mb-3" style="width: 150px; height: 150px; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 150px; height: 150px;">
                                                    <span style="font-size: 4rem; color: var(--primary-color);">
                                                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <form method="POST" enctype="multipart/form-data" class="mt-3">
                                                <input type="hidden" name="action" value="upload_avatar">
                                                <div class="mb-3">
                                                    <input type="file" class="form-control form-control-sm" name="avatar" accept="image/*" required>
                                                    <small class="text-muted">Max size: 2MB (JPG, PNG, GIF)</small>
                                                </div>
                                                <button type="submit" class="btn btn-primary btn-sm w-100">
                                                    <i class="bi bi-upload me-2"></i>Upload New Picture
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    
                                    <!-- Account Status -->
                                    <div class="card">
                                        <div class="card-header">
                                            <i class="bi bi-shield-check me-2"></i>Account Security
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="flex-shrink-0">
                                                    <i class="bi bi-shield-lock text-success fs-4"></i>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-0">Password Last Changed</h6>
                                                    <small class="text-muted"><?= date('M d, Y', strtotime($user['updated_at'])) ?></small>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <div class="flex-shrink-0">
                                                    <i class="bi bi-shield text-primary fs-4"></i>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-0">2-Factor Authentication</h6>
                                                    <small class="text-muted">Not enabled</small>
                                                </div>
                                                <a href="#" class="btn btn-sm btn-outline-primary">Enable</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Edit Profile Tab -->
                        <div class="tab-pane fade" id="profile" role="tabpanel">
                            <div class="card">
                                <div class="card-header">
                                    <i class="bi bi-pencil-square me-2"></i>Edit Profile Information
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_profile">
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label fw-semibold">Full Name</label>
                                                <input type="text" class="form-control" name="full_name" value="<?= sanitize($user['full_name']) ?>" required>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label fw-semibold">Email Address</label>
                                                <input type="email" class="form-control" name="email" value="<?= sanitize($user['email']) ?>" required>
                                                <small class="text-muted">Changing email will affect your login credentials</small>
                                            </div>
                                            
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-check-circle me-2"></i>Save Changes
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security Tab -->
                        <div class="tab-pane fade" id="security" role="tabpanel">
                            <div class="card">
                                <div class="card-header">
                                    <i class="bi bi-key me-2"></i>Change Password
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="change_password">
                                        
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label fw-semibold">Current Password</label>
                                                <input type="password" class="form-control" name="current_password" required>
                                            </div>
                                            
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label fw-semibold">New Password</label>
                                                <input type="password" class="form-control" name="new_password" required 
                                                       minlength="<?= PASSWORD_MIN_LENGTH ?>">
                                                <small class="text-muted">Min <?= PASSWORD_MIN_LENGTH ?> chars, 1 uppercase, 1 lowercase, 1 number</small>
                                            </div>
                                            
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label fw-semibold">Confirm Password</label>
                                                <input type="password" class="form-control" name="confirm_password" required>
                                            </div>
                                            
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-shield-lock me-2"></i>Update Password
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Activity Log Tab -->
                        <div class="tab-pane fade" id="activity" role="tabpanel">
                            <div class="card">
                                <div class="card-header">
                                    <i class="bi bi-clock-history me-2"></i>Recent Activity
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_activities)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-activity text-muted" style="font-size: 3rem;"></i>
                                            <h6 class="mt-3">No activity recorded yet</h6>
                                            <p class="text-muted">Your recent actions will appear here</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="timeline">
                                            <?php foreach ($recent_activities as $activity): ?>
                                                <div class="d-flex align-items-start mb-3 pb-3 border-bottom">
                                                    <div class="me-3">
                                                        <?php
                                                        $icon = 'bi-info-circle';
                                                        $color = 'primary';
                                                        
                                                        if (strpos($activity['action'], 'LOGIN') !== false) {
                                                            $icon = 'bi-box-arrow-in-right';
                                                            $color = 'success';
                                                        } elseif (strpos($activity['action'], 'PROFILE') !== false) {
                                                            $icon = 'bi-person';
                                                            $color = 'info';
                                                        } elseif (strpos($activity['action'], 'PASSWORD') !== false) {
                                                            $icon = 'bi-key';
                                                            $color = 'warning';
                                                        } elseif (strpos($activity['action'], 'TASK') !== false) {
                                                            $icon = 'bi-check2-square';
                                                            $color = 'primary';
                                                        }
                                                        ?>
                                                        <i class="bi <?= $icon ?> text-<?= $color ?> fs-5"></i>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <p class="mb-0"><?= sanitize($activity['description']) ?></p>
                                                        <small class="text-muted">
                                                            <i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', strtotime($activity['created_at'])) ?>
                                                            <span class="ms-2"><i class="bi bi-globe"></i> <?= $activity['ip_address'] ?></span>
                                                        </small>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
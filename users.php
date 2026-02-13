<?php
// users.php
require_once 'config/database.php';

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access user management.');
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Only admin can access this page
if ($user_role !== 'admin') {
    displayFlashMessage('error', 'You do not have permission to access user management.');
    redirect('dashboard.php');
}

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$admin = $stmt->fetch();

$action = $_GET['action'] ?? 'list';
$user_id_param = $_GET['id'] ?? 0;
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';

$success_message = '';
$error_message = '';

// Get flash messages
$flash_success = getFlashMessage('success');
if ($flash_success) $success_message = $flash_success;
$flash_error = getFlashMessage('error');
if ($flash_error) $error_message = $flash_error;

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_action = $_POST['user_action'] ?? '';
    
    // Update user status (approve, suspend, activate)
    if ($user_action === 'update_status') {
        $target_user_id = $_POST['user_id'] ?? 0;
        $new_status = $_POST['status'] ?? '';
        
        if ($target_user_id && $new_status && $target_user_id != $user_id) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $target_user_id]);
                
                // Get user details for notification
                $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $target_user = $stmt->fetch();
                
                // Log activity
                logActivity($pdo, $user_id, 'USER_STATUS', "Changed user {$target_user['full_name']} status to {$new_status}");
                
                // Create notification for the user
                $status_message = $new_status === 'active' ? 'approved and activated' : $new_status;
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) 
                                      VALUES (?, 'account_update', 'Account Status Updated', ?)");
                $stmt->execute([$target_user_id, "Your account has been {$status_message}."]);
                
                displayFlashMessage('success', "User status updated successfully!");
                
            } catch (PDOException $e) {
                error_log("User status update error: " . $e->getMessage());
                $error_message = 'Unable to update user status.';
            }
        }
        redirect('users.php');
    }
    
    // Create new user (admin only)
    elseif ($user_action === 'create_user') {
        $full_name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'member';
        $status = $_POST['status'] ?? 'active';
        
        $errors = [];
        
        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        }
        
        if (empty($email)) {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if (empty($password)) {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        }
        
        // Check if email already exists
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
                $stmt->execute([$email]);
                if ($stmt->rowCount() > 0) {
                    $errors[] = 'Email address is already registered.';
                }
            } catch (PDOException $e) {
                error_log("Email check error: " . $e->getMessage());
                $errors[] = 'Unable to verify email availability.';
            }
        }
        
        if (empty($errors)) {
            try {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role, status, created_at) 
                                      VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$full_name, $email, $hashed_password, $role, $status]);
                
                $new_user_id = $pdo->lastInsertId();
                
                logActivity($pdo, $user_id, 'USER_CREATE', "Created new user: {$full_name} ({$role})");
                
                displayFlashMessage('success', "User created successfully!");
                redirect('users.php');
                
            } catch (PDOException $e) {
                error_log("User create error: " . $e->getMessage());
                $error_message = 'Unable to create user. Please try again.';
            }
        } else {
            $error_message = implode(' ', $errors);
        }
    }
    
    // Soft delete user
    elseif ($user_action === 'delete_user') {
        $target_user_id = $_POST['user_id'] ?? 0;
        
        if ($target_user_id && $target_user_id != $user_id) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
                $stmt->execute([$user_id, $target_user_id]);
                
                // Get user details
                $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $target_user = $stmt->fetch();
                
                logActivity($pdo, $user_id, 'USER_DELETE', "Deleted user: {$target_user['full_name']}");
                
                displayFlashMessage('success', "User deleted successfully!");
                
            } catch (PDOException $e) {
                error_log("User delete error: " . $e->getMessage());
                $error_message = 'Unable to delete user.';
            }
        }
        redirect('users.php');
    }
}

// Get single user details
if ($action === 'view' && $user_id_param > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$user_id_param]);
        $view_user = $stmt->fetch();
        
        if (!$view_user) {
            displayFlashMessage('error', 'User not found.');
            redirect('users.php');
        }
        
        // Get user activity
        $stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$user_id_param]);
        $user_activities = $stmt->fetchAll();
        
        // Get user tasks
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_tasks,
                              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                              SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks
                              FROM tasks 
                              WHERE assigned_to = ? AND deleted_at IS NULL");
        $stmt->execute([$user_id_param]);
        $user_task_stats = $stmt->fetch();
        
    } catch (PDOException $e) {
        error_log("User fetch error: " . $e->getMessage());
        $error_message = 'Unable to load user details.';
    }
}

// Get list of users
if ($action === 'list') {
    try {
        $sql = "SELECT * FROM users WHERE deleted_at IS NULL";
        $params = [];
        
        if ($role_filter) {
            $sql .= " AND role = ?";
            $params[] = $role_filter;
        }
        
        if ($status_filter) {
            $sql .= " AND status = ?";
            $params[] = $status_filter;
        }
        
        $sql .= " ORDER BY 
                  CASE status 
                    WHEN 'pending' THEN 1 
                    WHEN 'active' THEN 2 
                    WHEN 'locked' THEN 3 
                    ELSE 4 
                  END,
                  created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        // Get statistics
        $stmt = $pdo->query("SELECT 
                            COUNT(*) as total_users,
                            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_count,
                            SUM(CASE WHEN role = 'manager' THEN 1 ELSE 0 END) as manager_count,
                            SUM(CASE WHEN role = 'member' THEN 1 ELSE 0 END) as member_count,
                            SUM(CASE WHEN role = 'client' THEN 1 ELSE 0 END) as client_count,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count
                            FROM users WHERE deleted_at IS NULL");
        $stats = $stmt->fetch();
        
    } catch (PDOException $e) {
        error_log("Users fetch error: " . $e->getMessage());
        $users = [];
    }
}

$title = $action === 'view' ? 'User Details' : 'User Management';
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
        }
        
        .sidebar-nav .nav-link:hover,
        .sidebar-nav .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left: 4px solid white;
        }
        
        .main-content {
            padding: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            height: 100%;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
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
        
        .user-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .user-card.status-active { border-left-color: #28a745; }
        .user-card.status-pending { border-left-color: #ffc107; }
        .user-card.status-locked { border-left-color: #dc3545; }
        .user-card.status-inactive { border-left-color: #6c757d; }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .main-content {
                padding: 1rem;
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
                        
                        <a href="users.php" class="nav-link active">
                            <i class="bi bi-people"></i> User Management
                        </a>
                        
                        <a href="projects.php" class="nav-link">
                            <i class="bi bi-kanban"></i> Projects
                        </a>
                        
                        <a href="tasks.php" class="nav-link">
                            <i class="bi bi-check2-square"></i> Tasks
                        </a>
                        
                        <a href="profile.php" class="nav-link">
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
                        <h5 class="mb-0">
                            <?php if ($action === 'view'): ?>
                                <a href="users.php" class="text-decoration-none text-dark">
                                    <i class="bi bi-arrow-left me-2"></i>Back to User List
                                </a>
                            <?php elseif ($action === 'create'): ?>
                                Create New User
                            <?php else: ?>
                                User Management
                            <?php endif; ?>
                        </h5>
                        <div class="d-flex align-items-center">
                            <span class="me-3">
                                <i class="bi bi-person-circle me-1"></i> <?= sanitize($admin['full_name']) ?>
                            </span>
                            <span class="badge bg-danger">Admin</span>
                        </div>
                    </div>
                </nav>
                
                <!-- Main Content Area -->
                <div class="main-content">
                    <!-- Error/Success Messages -->
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= sanitize($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i><?= sanitize($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- User Statistics Dashboard -->
                    <?php if ($action === 'list'): ?>
                        <div class="row mb-4">
                            <div class="col-xl-2 col-md-4 mb-3">
                                <div class="stat-card">
                                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                        <i class="bi bi-people"></i>
                                    </div>
                                    <h4 class="mb-0"><?= $stats['total_users'] ?? 0 ?></h4>
                                    <span class="text-muted">Total Users</span>
                                </div>
                            </div>
                            <div class="col-xl-2 col-md-4 mb-3">
                                <div class="stat-card">
                                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                                        <i class="bi bi-check-circle"></i>
                                    </div>
                                    <h4 class="mb-0"><?= $stats['active_count'] ?? 0 ?></h4>
                                    <span class="text-muted">Active</span>
                                </div>
                            </div>
                            <div class="col-xl-2 col-md-4 mb-3">
                                <div class="stat-card">
                                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                        <i class="bi bi-hourglass"></i>
                                    </div>
                                    <h4 class="mb-0"><?= $stats['pending_count'] ?? 0 ?></h4>
                                    <span class="text-muted">Pending</span>
                                </div>
                            </div>
                            <div class="col-xl-2 col-md-4 mb-3">
                                <div class="stat-card">
                                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                                        <i class="bi bi-person-badge"></i>
                                    </div>
                                    <h4 class="mb-0"><?= $stats['manager_count'] ?? 0 ?></h4>
                                    <span class="text-muted">Managers</span>
                                </div>
                            </div>
                            <div class="col-xl-2 col-md-4 mb-3">
                                <div class="stat-card">
                                    <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                                        <i class="bi bi-people"></i>
                                    </div>
                                    <h4 class="mb-0"><?= $stats['member_count'] ?? 0 ?></h4>
                                    <span class="text-muted">Members</span>
                                </div>
                            </div>
                            <div class="col-xl-2 col-md-4 mb-3">
                                <div class="stat-card">
                                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                        <i class="bi bi-briefcase"></i>
                                    </div>
                                    <h4 class="mb-0"><?= $stats['client_count'] ?? 0 ?></h4>
                                    <span class="text-muted">Clients</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filter and Actions -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <div class="btn-group">
                                    <a href="users.php" class="btn btn-sm <?= !$role_filter && !$status_filter ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        All Users
                                    </a>
                                    <a href="users.php?status=pending" class="btn btn-sm <?= $status_filter == 'pending' ? 'btn-warning' : 'btn-outline-warning' ?>">
                                        <i class="bi bi-hourglass me-1"></i>Pending Approval
                                    </a>
                                    <a href="users.php?role=manager" class="btn btn-sm <?= $role_filter == 'manager' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        Managers
                                    </a>
                                    <a href="users.php?role=member" class="btn btn-sm <?= $role_filter == 'member' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        Members
                                    </a>
                                    <a href="users.php?role=client" class="btn btn-sm <?= $role_filter == 'client' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        Clients
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                                    <i class="bi bi-person-plus me-1"></i>Add New User
                                </button>
                            </div>
                        </div>
                        
                        <!-- Users List -->
                        <?php if (empty($users)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-people text-muted" style="font-size: 4rem;"></i>
                                <h5 class="mt-3">No users found</h5>
                                <p class="text-muted">There are no users matching your criteria.</p>
                                <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#createUserModal">
                                    <i class="bi bi-person-plus me-1"></i>Add Your First User
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>User</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Joined</th>
                                            <th>Last Activity</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user_item): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="user-avatar me-2">
                                                            <?= strtoupper(substr($user_item['full_name'], 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <a href="users.php?action=view&id=<?= $user_item['id'] ?>" class="text-decoration-none fw-semibold text-dark">
                                                                <?= sanitize($user_item['full_name']) ?>
                                                            </a>
                                                            <br>
                                                            <small class="text-muted"><?= sanitize($user_item['email']) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $user_item['role'] == 'admin' ? 'danger' : 
                                                        ($user_item['role'] == 'manager' ? 'primary' : 
                                                        ($user_item['role'] == 'member' ? 'info' : 'secondary')) 
                                                    ?>">
                                                        <?= ucfirst($user_item['role']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $user_item['status'] == 'active' ? 'success' : 
                                                        ($user_item['status'] == 'pending' ? 'warning' : 
                                                        ($user_item['status'] == 'locked' ? 'danger' : 'secondary')) 
                                                    ?>">
                                                        <?= ucfirst($user_item['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?= date('M d, Y', strtotime($user_item['created_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <small><?= date('M d, Y', strtotime($user_item['updated_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="users.php?action=view&id=<?= $user_item['id'] ?>" class="btn btn-outline-primary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        
                                                        <?php if ($user_item['id'] != $user_id): ?>
                                                            <?php if ($user_item['status'] == 'pending'): ?>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="user_action" value="update_status">
                                                                    <input type="hidden" name="user_id" value="<?= $user_item['id'] ?>">
                                                                    <input type="hidden" name="status" value="active">
                                                                    <button type="submit" class="btn btn-outline-success" title="Approve">
                                                                        <i class="bi bi-check-lg"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($user_item['status'] == 'active'): ?>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="user_action" value="update_status">
                                                                    <input type="hidden" name="user_id" value="<?= $user_item['id'] ?>">
                                                                    <input type="hidden" name="status" value="inactive">
                                                                    <button type="submit" class="btn btn-outline-warning" title="Suspend">
                                                                        <i class="bi bi-pause"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($user_item['status'] == 'inactive'): ?>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="user_action" value="update_status">
                                                                    <input type="hidden" name="user_id" value="<?= $user_item['id'] ?>">
                                                                    <input type="hidden" name="status" value="active">
                                                                    <button type="submit" class="btn btn-outline-success" title="Activate">
                                                                        <i class="bi bi-play"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this user? This action can be reversed by an administrator.')">
                                                                <input type="hidden" name="user_action" value="delete_user">
                                                                <input type="hidden" name="user_id" value="<?= $user_item['id'] ?>">
                                                                <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Create User Modal -->
                        <div class="modal fade" id="createUserModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">
                                            <i class="bi bi-person-plus me-2 text-primary"></i>Create New User
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="user_action" value="create_user">
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Full Name</label>
                                                <input type="text" class="form-control" name="full_name" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Email Address</label>
                                                <input type="email" class="form-control" name="email" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Password</label>
                                                <input type="password" class="form-control" name="password" required 
                                                       minlength="<?= PASSWORD_MIN_LENGTH ?>">
                                                <small class="text-muted">Min <?= PASSWORD_MIN_LENGTH ?> characters</small>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Role</label>
                                                <select class="form-select" name="role" required>
                                                    <option value="member">Team Member</option>
                                                    <option value="manager">Manager</option>
                                                    <option value="client">Client</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Status</label>
                                                <select class="form-select" name="status">
                                                    <option value="active">Active</option>
                                                    <option value="pending">Pending Approval</option>
                                                    <option value="inactive">Inactive</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Create User</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- User Detail View -->
                    <?php if ($action === 'view' && isset($view_user)): ?>
                        <div class="row">
                            <div class="col-lg-4">
                                <div class="card mb-4">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0"><i class="bi bi-person me-2 text-primary"></i>User Profile</h6>
                                    </div>
                                    <div class="card-body text-center">
                                        <div class="user-avatar mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                                            <?= strtoupper(substr($view_user['full_name'], 0, 1)) ?>
                                        </div>
                                        <h5><?= sanitize($view_user['full_name']) ?></h5>
                                        <p class="text-muted mb-2"><?= sanitize($view_user['email']) ?></p>
                                        
                                        <div class="mb-3">
                                            <span class="badge bg-<?= 
                                                $view_user['role'] == 'admin' ? 'danger' : 
                                                ($view_user['role'] == 'manager' ? 'primary' : 
                                                ($view_user['role'] == 'member' ? 'info' : 'secondary')) 
                                            ?> me-1">
                                                <?= ucfirst($view_user['role']) ?>
                                            </span>
                                            <span class="badge bg-<?= 
                                                $view_user['status'] == 'active' ? 'success' : 
                                                ($view_user['status'] == 'pending' ? 'warning' : 
                                                ($view_user['status'] == 'locked' ? 'danger' : 'secondary')) 
                                            ?>">
                                                <?= ucfirst($view_user['status']) ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($view_user['id'] != $user_id): ?>
                                            <div class="d-grid gap-2">
                                                <?php if ($view_user['status'] == 'pending'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="user_action" value="update_status">
                                                        <input type="hidden" name="user_id" value="<?= $view_user['id'] ?>">
                                                        <input type="hidden" name="status" value="active">
                                                        <button type="submit" class="btn btn-success w-100">
                                                            <i class="bi bi-check-circle me-1"></i>Approve Account
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <?php if ($view_user['status'] == 'active'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="user_action" value="update_status">
                                                        <input type="hidden" name="user_id" value="<?= $view_user['id'] ?>">
                                                        <input type="hidden" name="status" value="inactive">
                                                        <button type="submit" class="btn btn-warning w-100">
                                                            <i class="bi bi-pause-circle me-1"></i>Suspend Account
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <?php if ($view_user['status'] == 'inactive'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="user_action" value="update_status">
                                                        <input type="hidden" name="user_id" value="<?= $view_user['id'] ?>">
                                                        <input type="hidden" name="status" value="active">
                                                        <button type="submit" class="btn btn-success w-100">
                                                            <i class="bi bi-play-circle me-1"></i>Activate Account
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="card mb-4">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>Account Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td class="text-muted">User ID:</td>
                                                <td class="fw-semibold">#<?= $view_user['id'] ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Member Since:</td>
                                                <td><?= date('F d, Y', strtotime($view_user['created_at'])) ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Last Updated:</td>
                                                <td><?= date('M d, Y h:i A', strtotime($view_user['updated_at'])) ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Failed Attempts:</td>
                                                <td>
                                                    <span class="badge bg-<?= $view_user['failed_attempts'] > 3 ? 'danger' : 'warning' ?>">
                                                        <?= $view_user['failed_attempts'] ?? 0 ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-8">
                                <!-- User Statistics -->
                                <div class="row mb-4">
                                    <div class="col-md-4 mb-3">
                                        <div class="stat-card">
                                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                                <i class="bi bi-list-task"></i>
                                            </div>
                                            <h4 class="mb-0"><?= $user_task_stats['total_tasks'] ?? 0 ?></h4>
                                            <span class="text-muted">Total Tasks</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="stat-card">
                                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                                <i class="bi bi-check-circle"></i>
                                            </div>
                                            <h4 class="mb-0"><?= $user_task_stats['completed_tasks'] ?? 0 ?></h4>
                                            <span class="text-muted">Completed</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="stat-card">
                                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                                <i class="bi bi-hourglass"></i>
                                            </div>
                                            <h4 class="mb-0"><?= ($user_task_stats['pending_tasks'] ?? 0) + ($user_task_stats['in_progress_tasks'] ?? 0) ?></h4>
                                            <span class="text-muted">In Progress</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Recent Activity -->
                                <div class="card">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Activity</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($user_activities)): ?>
                                            <div class="text-center py-4">
                                                <i class="bi bi-activity text-muted" style="font-size: 2rem;"></i>
                                                <p class="text-muted mt-2">No activity recorded yet.</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="timeline">
                                                <?php foreach ($user_activities as $activity): ?>
                                                    <div class="d-flex align-items-start mb-3 pb-3 border-bottom">
                                                        <div class="me-3">
                                                            <i class="bi bi-<?= 
                                                                strpos($activity['action'], 'LOGIN') !== false ? 'box-arrow-in-right' : 
                                                                (strpos($activity['action'], 'TASK') !== false ? 'check2-square' : 
                                                                (strpos($activity['action'], 'PROJECT') !== false ? 'kanban' : 'info-circle')) 
                                                            ?> text-primary"></i>
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
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
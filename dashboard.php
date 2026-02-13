<?php
// dashboard.php
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access the dashboard.');
    redirect('login.php');
}

// Get user data
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    displayFlashMessage('error', 'User account not found.');
    redirect('login.php');
}

// Check account status
if ($user['status'] !== 'active') {
    session_destroy();
    displayFlashMessage('error', 'Your account is not active. Status: ' . $user['status'] . '. Please contact administrator.');
    redirect('login.php');
}

// Get role-based dashboard data
$role = $user['role'];

// Common data for all roles
$notifications = [];
$pending_tasks = [];
$recent_activities = [];

try {
    // Get unread notifications
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();
    
    // Get recent activities
    $stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $recent_activities = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Dashboard data error: " . $e->getMessage());
}

// Role-specific data
switch ($role) {
    case 'admin':
        // Admin dashboard data - Full system overview
        try {
            // Total users count by role
            $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY role");
            $user_stats = $stmt->fetchAll();
            
            // Pending approvals
            $stmt = $pdo->prepare("SELECT * FROM users WHERE status = 'pending' AND deleted_at IS NULL ORDER BY created_at DESC");
            $stmt->execute();
            $pending_approvals = $stmt->fetchAll();
            
            // System statistics
            $stmt = $pdo->query("SELECT COUNT(*) as total_tasks FROM tasks WHERE deleted_at IS NULL");
            $total_tasks = $stmt->fetch()['total_tasks'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total_projects FROM projects WHERE deleted_at IS NULL");
            $total_projects = $stmt->fetch()['total_projects'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as active_tasks FROM tasks WHERE status IN ('pending', 'in_progress') AND deleted_at IS NULL");
            $active_tasks = $stmt->fetch()['active_tasks'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as overdue_tasks FROM tasks WHERE due_date < CURDATE() AND status != 'completed' AND deleted_at IS NULL");
            $overdue_tasks = $stmt->fetch()['overdue_tasks'];
            
        } catch (PDOException $e) {
            error_log("Admin dashboard error: " . $e->getMessage());
        }
        break;
        
    case 'manager':
        // Manager dashboard data - Team and project management
        try {
            // Team members under this manager
            $stmt = $pdo->prepare("SELECT * FROM users WHERE role IN ('member') AND status = 'active' AND deleted_at IS NULL ORDER BY full_name");
            $stmt->execute();
            $team_members = $stmt->fetchAll();
            
            // Projects created by manager
            $stmt = $pdo->prepare("SELECT * FROM projects WHERE created_by = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$user_id]);
            $projects = $stmt->fetchAll();
            
            // Tasks assigned by manager
            $stmt = $pdo->prepare("SELECT t.*, u.full_name as assigned_to_name, p.project_name 
                                  FROM tasks t 
                                  LEFT JOIN users u ON t.assigned_to = u.id 
                                  LEFT JOIN projects p ON t.project_id = p.id 
                                  WHERE t.assigned_by = ? AND t.deleted_at IS NULL 
                                  ORDER BY t.due_date ASC LIMIT 20");
            $stmt->execute([$user_id]);
            $assigned_tasks = $stmt->fetchAll();
            
            // Team workload
            $stmt = $pdo->prepare("SELECT u.id, u.full_name, COUNT(t.id) as task_count 
                                  FROM users u 
                                  LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status NOT IN ('completed') AND t.deleted_at IS NULL 
                                  WHERE u.role = 'member' AND u.status = 'active' AND u.deleted_at IS NULL 
                                  GROUP BY u.id");
            $stmt->execute();
            $team_workload = $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Manager dashboard error: " . $e->getMessage());
        }
        break;
        
    case 'member':
        // Member dashboard data - Personal tasks and assignments
        try {
            // Tasks assigned to this member
            $stmt = $pdo->prepare("SELECT t.*, p.project_name, 
                                  (SELECT COUNT(*) FROM task_comments WHERE task_id = t.id) as comment_count 
                                  FROM tasks t 
                                  LEFT JOIN projects p ON t.project_id = p.id 
                                  WHERE t.assigned_to = ? AND t.deleted_at IS NULL 
                                  ORDER BY 
                                      CASE t.status 
                                          WHEN 'pending' THEN 1 
                                          WHEN 'in_progress' THEN 2 
                                          WHEN 'review' THEN 3 
                                          ELSE 4 
                                      END,
                                      t.due_date ASC");
            $stmt->execute([$user_id]);
            $my_tasks = $stmt->fetchAll();
            
            // Task statistics
            $stmt = $pdo->prepare("SELECT 
                                  COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_tasks,
                                  COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_tasks,
                                  COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_tasks,
                                  COUNT(CASE WHEN due_date < CURDATE() AND status != 'completed' THEN 1 END) as overdue_tasks
                                  FROM tasks 
                                  WHERE assigned_to = ? AND deleted_at IS NULL");
            $stmt->execute([$user_id]);
            $task_stats = $stmt->fetch();
            
            // Recent completed tasks
            $stmt = $pdo->prepare("SELECT * FROM tasks 
                                  WHERE assigned_to = ? AND status = 'completed' AND deleted_at IS NULL 
                                  ORDER BY completed_date DESC LIMIT 5");
            $stmt->execute([$user_id]);
            $completed_tasks = $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Member dashboard error: " . $e->getMessage());
        }
        break;
        
    case 'client':
        // Client dashboard data - Project tracking
        try {
            // Projects client can view
            $stmt = $pdo->prepare("SELECT * FROM projects 
                                  WHERE status != 'cancelled' AND deleted_at IS NULL 
                                  ORDER BY created_at DESC LIMIT 10");
            $stmt->execute();
            $client_projects = $stmt->fetchAll();
            
            // Project progress statistics
            $stmt = $pdo->query("SELECT 
                                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_projects,
                                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_projects,
                                COUNT(CASE WHEN status = 'planning' THEN 1 END) as planning_projects
                                FROM projects WHERE deleted_at IS NULL");
            $project_stats = $stmt->fetch();
            
            // Recent tasks related to client projects
            $stmt = $pdo->prepare("SELECT t.*, p.project_name, u.full_name as assigned_to_name 
                                  FROM tasks t 
                                  JOIN projects p ON t.project_id = p.id 
                                  LEFT JOIN users u ON t.assigned_to = u.id 
                                  WHERE p.deleted_at IS NULL AND t.deleted_at IS NULL 
                                  ORDER BY t.updated_at DESC LIMIT 10");
            $stmt->execute();
            $recent_tasks = $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Client dashboard error: " . $e->getMessage());
        }
        break;
}

$title = ucfirst($role) . ' Dashboard';
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
    <!-- Chart.js for analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
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
        
        .sidebar-brand h4 {
            margin: 0;
            font-weight: 600;
        }
        
        .sidebar-brand p {
            margin: 0;
            opacity: 0.8;
            font-size: 0.85rem;
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
        
        .sidebar-nav .nav-link:hover {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        
        .sidebar-nav .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.2);
            border-left: 4px solid white;
        }
        
        .sidebar-nav .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            padding: 2rem;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
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
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .task-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .task-card.priority-high { border-left-color: var(--danger-color); }
        .task-card.priority-medium { border-left-color: var(--warning-color); }
        .task-card.priority-low { border-left-color: var(--info-color); }
        
        .task-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .task-meta {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .badge-status {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .notification-badge {
            position: relative;
        }
        
        .notification-badge[data-count]:after {
            content: attr(data-count);
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger-color);
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .quick-access-card {
            background: linear-gradient(135deg, #ffd89b, #19547b);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .quick-access-card::before {
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
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .main-content {
                padding: 1rem;
            }
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .navbar-top {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 1rem 2rem;
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
                        <a href="dashboard.php" class="nav-link active">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        
                        <?php if ($role === 'admin'): ?>
                            <a href="users.php" class="nav-link">
                                <i class="bi bi-people"></i> User Management
                            </a>
                            <a href="approvals.php" class="nav-link notification-badge" data-count="<?= count($pending_approvals ?? []) ?>">
                                <i class="bi bi-person-check"></i> Approvals
                            </a>
                            <a href="system-logs.php" class="nav-link">
                                <i class="bi bi-journal-text"></i> System Logs
                            </a>
                            
                        <?php elseif ($role === 'manager'): ?>
                            <a href="projects.php" class="nav-link">
                                <i class="bi bi-kanban"></i> Projects
                            </a>
                            <a href="tasks.php" class="nav-link">
                                <i class="bi bi-check2-square"></i> All Tasks
                            </a>
                            <a href="team.php" class="nav-link">
                                <i class="bi bi-people"></i> My Team
                            </a>
                            <a href="reports.php" class="nav-link">
                                <i class="bi bi-bar-chart"></i> Reports
                            </a>
                            
                        <?php elseif ($role === 'member'): ?>
                            <a href="my-tasks.php" class="nav-link">
                                <i class="bi bi-list-task"></i> My Tasks
                            </a>
                            <a href="calendar.php" class="nav-link">
                                <i class="bi bi-calendar"></i> Calendar
                            </a>
                            <a href="time-tracker.php" class="nav-link">
                                <i class="bi bi-clock"></i> Time Tracker
                            </a>
                            
                        <?php elseif ($role === 'client'): ?>
                            <a href="my-projects.php" class="nav-link">
                                <i class="bi bi-folder"></i> My Projects
                            </a>
                            <a href="requests.php" class="nav-link">
                                <i class="bi bi-envelope"></i> Service Requests
                            </a>
                            <a href="progress.php" class="nav-link">
                                <i class="bi bi-graph-up"></i> Progress Reports
                            </a>
                        <?php endif; ?>
                        
                        <a href="profile.php" class="nav-link">
                            <i class="bi bi-person"></i> Profile
                        </a>
                        <a href="system-settings.php" class="nav-link">
                            <i class="bi bi-gear"></i> Settings
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
                <nav class="navbar navbar-top navbar-light px-4 py-3 shadow-sm">
        <div class="container-fluid">
            <h5 class="mb-0">
                <i class="bi bi-speedometer2 me-2 text-primary"></i><?= sanitize($title) ?>
            </h5>
            <div class="d-flex align-items-center">
                        <!-- Notifications -->
                        <div class="dropdown me-3">
                            <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-bell"></i>
                                <?php if (count($notifications) > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        <?= count($notifications) ?>
                                    </span>
                                <?php endif; ?>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end" style="width: 300px;">
                                <div class="dropdown-header d-flex justify-content-between">
                                    <strong>Notifications</strong>
                                    <a href="#" class="text-decoration-none small">Mark all as read</a>
                                </div>
                                <div class="dropdown-divider"></div>
                                <?php if (empty($notifications)): ?>
                                    <div class="text-center p-3 text-muted">
                                        <i class="bi bi-check-circle"></i>
                                        <p class="mb-0 small">No new notifications</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notif): ?>
                                        <a class="dropdown-item" href="<?= $notif['link'] ?? '#' ?>">
                                            <div class="d-flex">
                                                <div class="me-2">
                                                    <?php if ($notif['type'] === 'task_assigned'): ?>
                                                        <i class="bi bi-plus-circle text-primary"></i>
                                                    <?php elseif ($notif['type'] === 'task_due'): ?>
                                                        <i class="bi bi-exclamation-triangle text-warning"></i>
                                                    <?php elseif ($notif['type'] === 'task_review'): ?>
                                                        <i class="bi bi-check-circle text-success"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-info-circle text-info"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <strong><?= sanitize($notif['title']) ?></strong>
                                                    <p class="small text-muted mb-0"><?= sanitize($notif['message']) ?></p>
                                                    <small class="text-muted"><?= date('M d, H:i', strtotime($notif['created_at'])) ?></small>
                                                </div>
                                            </div>
                                        </a>
                                        <div class="dropdown-divider"></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="text-center p-2">
                                    <a href="notifications.php" class="text-decoration-none small">View All</a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- User Menu -->
                        <div class="dropdown">
                            <button class="btn btn-light d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                                <div class="user-avatar me-2">
                                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                                </div>
                                <span><?= sanitize($user['full_name']) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                                <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </nav>
                
                <!-- Main Content Area -->
                <div class="main-content">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h2><?= ucfirst($role) ?> Dashboard</h2>
                        <p class="text-muted">
                            <?php
                            switch($role) {
                                case 'admin':
                                    echo 'System overview and user management';
                                    break;
                                case 'manager':
                                    echo 'Manage your team and projects';
                                    break;
                                case 'member':
                                    echo 'Track your tasks and productivity';
                                    break;
                                case 'client':
                                    echo 'Monitor your project progress';
                                    break;
                            }
                            ?>
                        </p>
                    </div>
                    
                    <!-- ROLE-SPECIFIC DASHBOARD CONTENT -->
                    
                    <?php if ($role === 'admin'): ?>
                    <!-- ADMIN DASHBOARD -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div class="stat-value"><?= $total_users ?? 0 ?></div>
                                <div class="stat-label">Total Users</div>
                                <div class="mt-2">
                                    <?php foreach ($user_stats ?? [] as $stat): ?>
                                        <span class="badge bg-light text-dark me-1">
                                            <?= ucfirst($stat['role']) ?>: <?= $stat['count'] ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                    <i class="bi bi-kanban"></i>
                                </div>
                                <div class="stat-value"><?= $total_projects ?? 0 ?></div>
                                <div class="stat-label">Total Projects</div>
                                <div class="mt-2">
                                    <span class="badge bg-success">Active: <?= $active_projects ?? 0 ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-info bg-opacity-10 text-info">
                                    <i class="bi bi-check2-square"></i>
                                </div>
                                <div class="stat-value"><?= $total_tasks ?? 0 ?></div>
                                <div class="stat-label">Total Tasks</div>
                                <div class="mt-2">
                                    <span class="badge bg-warning">Active: <?= $active_tasks ?? 0 ?></span>
                                    <span class="badge bg-danger">Overdue: <?= $overdue_tasks ?? 0 ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                                <div class="stat-value"><?= count($pending_approvals ?? []) ?></div>
                                <div class="stat-label">Pending Approvals</div>
                                <?php if (count($pending_approvals ?? []) > 0): ?>
                                    <a href="approvals.php" class="btn btn-warning btn-sm mt-2">Review Now</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-8 mb-4">
                            <div class="card shadow">
                                <div class="card-header bg-white py-3">
                                    <h6 class="m-0 font-weight-bold">Recent Activity</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_activities)): ?>
                                        <p class="text-muted text-center py-3">No recent activities</p>
                                    <?php else: ?>
                                        <?php foreach ($recent_activities as $activity): ?>
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="me-3">
                                                    <i class="bi bi-circle-fill text-primary" style="font-size: 8px;"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <p class="mb-0"><?= sanitize($activity['description']) ?></p>
                                                    <small class="text-muted"><?= date('M d, H:i', strtotime($activity['created_at'])) ?></small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="quick-access-card">
                                <h6>Quick Actions</h6>
                                <div class="mt-3">
                                    <a href="users.php?action=add" class="btn btn-light btn-sm w-100 mb-2">
                                        <i class="bi bi-person-plus me-2"></i>Add New User
                                    </a>
                                    <a href="projects.php?action=add" class="btn btn-light btn-sm w-100 mb-2">
                                        <i class="bi bi-plus-circle me-2"></i>Create Project
                                    </a>
                                    <a href="system-settings.php" class="btn btn-light btn-sm w-100">
                                        <i class="bi bi-gear me-2"></i>System Settings
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($role === 'manager'): ?>
                    <!-- MANAGER DASHBOARD -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div class="stat-value"><?= count($team_members ?? []) ?></div>
                                <div class="stat-label">Team Members</div>
                                <a href="team.php" class="btn btn-sm btn-outline-primary mt-2">Manage Team</a>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                    <i class="bi bi-kanban"></i>
                                </div>
                                <div class="stat-value"><?= count($projects ?? []) ?></div>
                                <div class="stat-label">Active Projects</div>
                                <a href="projects.php" class="btn btn-sm btn-outline-success mt-2">View All</a>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-info bg-opacity-10 text-info">
                                    <i class="bi bi-check2-square"></i>
                                </div>
                                <div class="stat-value"><?= count($assigned_tasks ?? []) ?></div>
                                <div class="stat-label">Assigned Tasks</div>
                                <div class="mt-2">
                                    <span class="badge bg-warning">Pending: 8</span>
                                    <span class="badge bg-success">Completed: 12</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </div>
                                <div class="stat-value">
                                    <?php 
                                    $overdue_count = 0;
                                    foreach ($assigned_tasks ?? [] as $task) {
                                        if (strtotime($task['due_date']) < time() && $task['status'] != 'completed') {
                                            $overdue_count++;
                                        }
                                    }
                                    echo $overdue_count;
                                    ?>
                                </div>
                                <div class="stat-label">Overdue Tasks</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-7 mb-4">
                            <div class="card shadow">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="m-0 font-weight-bold">Pending Tasks</h6>
                                    <a href="tasks.php" class="btn btn-sm btn-primary">View All</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($assigned_tasks)): ?>
                                        <p class="text-muted text-center py-3">No tasks assigned yet</p>
                                    <?php else: ?>
                                        <?php foreach (array_slice($assigned_tasks, 0, 5) as $task): ?>
                                            <div class="task-card priority-<?= $task['priority'] ?>">
                                                <div class="d-flex justify-content-between">
                                                    <h6 class="task-title"><?= sanitize($task['task_title']) ?></h6>
                                                    <span class="badge-status bg-<?= 
                                                        $task['status'] == 'pending' ? 'warning' : 
                                                        ($task['status'] == 'in_progress' ? 'info' : 
                                                        ($task['status'] == 'review' ? 'primary' : 
                                                        ($task['status'] == 'completed' ? 'success' : 'danger'))) 
                                                    ?> bg-opacity-10 text-<?= 
                                                        $task['status'] == 'pending' ? 'warning' : 
                                                        ($task['status'] == 'in_progress' ? 'info' : 
                                                        ($task['status'] == 'review' ? 'primary' : 
                                                        ($task['status'] == 'completed' ? 'success' : 'danger'))) 
                                                    ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                                    </span>
                                                </div>
                                                <p class="task-meta mb-1">
                                                    <i class="bi bi-person me-1"></i> Assigned to: <?= sanitize($task['assigned_to_name'] ?? 'Unassigned') ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="task-meta">
                                                        <i class="bi bi-calendar me-1"></i> Due: <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                        <?php if (strtotime($task['due_date']) < time() && $task['status'] != 'completed'): ?>
                                                            <span class="badge bg-danger ms-2">Overdue</span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <a href="tasks.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-5 mb-4">
                            <div class="card shadow">
                                <div class="card-header bg-white py-3">
                                    <h6 class="m-0 font-weight-bold">Team Workload</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($team_workload)): ?>
                                        <p class="text-muted text-center py-3">No team members found</p>
                                    <?php else: ?>
                                        <?php foreach ($team_workload as $member): ?>
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="flex-shrink-0">
                                                    <div class="user-avatar bg-primary bg-opacity-10 text-primary">
                                                        <?= strtoupper(substr($member['full_name'], 0, 1)) ?>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <h6 class="mb-1"><?= sanitize($member['full_name']) ?></h6>
                                                    <div class="progress" style="height: 8px;">
                                                        <?php 
                                                        $percentage = min(100, ($member['task_count'] * 10));
                                                        $color = $percentage > 70 ? 'danger' : ($percentage > 40 ? 'warning' : 'success');
                                                        ?>
                                                        <div class="progress-bar bg-<?= $color ?>" style="width: <?= $percentage ?>%"></div>
                                                    </div>
                                                    <small class="text-muted"><?= $member['task_count'] ?> active tasks</small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="quick-access-card mt-4">
                                <h6>Quick Actions</h6>
                                <div class="mt-3">
                                    <a href="tasks.php?action=assign" class="btn btn-light btn-sm w-100 mb-2">
                                        <i class="bi bi-plus-circle me-2"></i>Assign New Task
                                    </a>
                                    <a href="projects.php?action=create" class="btn btn-light btn-sm w-100 mb-2">
                                        <i class="bi bi-folder-plus me-2"></i>Create Project
                                    </a>
                                    <a href="reports.php" class="btn btn-light btn-sm w-100">
                                        <i class="bi bi-file-text me-2"></i>Generate Report
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($role === 'member'): ?>
                    <!-- MEMBER DASHBOARD -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                    <i class="bi bi-list-task"></i>
                                </div>
                                <div class="stat-value"><?= $task_stats['pending_tasks'] ?? 0 ?></div>
                                <div class="stat-label">Pending Tasks</div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-info bg-opacity-10 text-info">
                                    <i class="bi bi-play-circle"></i>
                                </div>
                                <div class="stat-value"><?= $task_stats['in_progress_tasks'] ?? 0 ?></div>
                                <div class="stat-label">In Progress</div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div class="stat-value"><?= $task_stats['completed_tasks'] ?? 0 ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </div>
                                <div class="stat-value"><?= $task_stats['overdue_tasks'] ?? 0 ?></div>
                                <div class="stat-label">Overdue</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-8 mb-4">
                            <div class="card shadow">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="m-0 font-weight-bold">My Tasks</h6>
                                    <div>
                                        <a href="my-tasks.php?filter=all" class="btn btn-sm btn-outline-secondary me-2">All</a>
                                        <a href="my-tasks.php?filter=pending" class="btn btn-sm btn-outline-warning">Pending</a>
                                        <a href="my-tasks.php?filter=completed" class="btn btn-sm btn-outline-success ms-2">Completed</a>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($my_tasks)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-check2-circle text-success" style="font-size: 3rem;"></i>
                                            <h6 class="mt-3">No tasks assigned</h6>
                                            <p class="text-muted">You don't have any tasks at the moment.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach (array_slice($my_tasks, 0, 5) as $task): ?>
                                            <div class="task-card priority-<?= $task['priority'] ?>">
                                                <div class="d-flex justify-content-between">
                                                    <h6 class="task-title"><?= sanitize($task['task_title']) ?></h6>
                                                    <span class="badge-status bg-<?= 
                                                        $task['status'] == 'pending' ? 'warning' : 
                                                        ($task['status'] == 'in_progress' ? 'info' : 
                                                        ($task['status'] == 'review' ? 'primary' : 
                                                        ($task['status'] == 'completed' ? 'success' : 'danger'))) 
                                                    ?> bg-opacity-10 text-<?= 
                                                        $task['status'] == 'pending' ? 'warning' : 
                                                        ($task['status'] == 'in_progress' ? 'info' : 
                                                        ($task['status'] == 'review' ? 'primary' : 
                                                        ($task['status'] == 'completed' ? 'success' : 'danger'))) 
                                                    ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                                    </span>
                                                </div>
                                                <p class="text-muted small mb-2"><?= sanitize(substr($task['description'] ?? '', 0, 100)) ?>...</p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <span class="badge bg-light text-dark me-2">
                                                            <i class="bi bi-folder me-1"></i><?= sanitize($task['project_name'] ?? 'No Project') ?>
                                                        </span>
                                                        <span class="badge bg-light text-dark">
                                                            <i class="bi bi-chat me-1"></i><?= $task['comment_count'] ?? 0 ?>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <?php if ($task['status'] != 'completed'): ?>
                                                            <a href="update-task-status.php?id=<?= $task['id'] ?>&status=in_progress" class="btn btn-sm btn-outline-info me-1">
                                                                <i class="bi bi-play"></i>
                                                            </a>
                                                            <a href="update-task-status.php?id=<?= $task['id'] ?>&status=review" class="btn btn-sm btn-outline-primary me-1">
                                                                <i class="bi bi-check"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="tasks.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                                <div class="mt-2 d-flex justify-content-between">
                                                    <small class="text-muted">
                                                        <i class="bi bi-calendar"></i> Due: <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                        <?php if (strtotime($task['due_date']) < time() && $task['status'] != 'completed'): ?>
                                                            <span class="badge bg-danger ms-2">Overdue</span>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if (count($my_tasks) > 5): ?>
                                            <div class="text-center mt-3">
                                                <a href="my-tasks.php" class="btn btn-link">View all <?= count($my_tasks) ?> tasks</a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="card shadow mb-4">
                                <div class="card-header bg-white py-3">
                                    <h6 class="m-0 font-weight-bold">Productivity Overview</h6>
                                </div>
                                <div class="card-body">
                                    <div style="height: 200px;">
                                        <canvas id="productivityChart"></canvas>
                                    </div>
                                    <script>
                                        document.addEventListener('DOMContentLoaded', function() {
                                            const ctx = document.getElementById('productivityChart').getContext('2d');
                                            new Chart(ctx, {
                                                type: 'doughnut',
                                                data: {
                                                    labels: ['Completed', 'In Progress', 'Pending', 'Overdue'],
                                                    datasets: [{
                                                        data: [
                                                            <?= $task_stats['completed_tasks'] ?? 0 ?>,
                                                            <?= $task_stats['in_progress_tasks'] ?? 0 ?>,
                                                            <?= $task_stats['pending_tasks'] ?? 0 ?>,
                                                            <?= $task_stats['overdue_tasks'] ?? 0 ?>
                                                        ],
                                                        backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545']
                                                    }]
                                                },
                                                options: {
                                                    responsive: true,
                                                    maintainAspectRatio: false,
                                                    plugins: {
                                                        legend: {
                                                            position: 'bottom'
                                                        }
                                                    }
                                                }
                                            });
                                        });
                                    </script>
                                </div>
                            </div>
                            
                            <div class="quick-access-card">
                                <h6>Quick Actions</h6>
                                <div class="mt-3">
                                    <a href="my-tasks.php" class="btn btn-light btn-sm w-100 mb-2">
                                        <i class="bi bi-list-task me-2"></i>View All Tasks
                                    </a>
                                    <a href="calendar.php" class="btn btn-light btn-sm w-100 mb-2">
                                        <i class="bi bi-calendar me-2"></i>My Calendar
                                    </a>
                                    <a href="time-tracker.php" class="btn btn-light btn-sm w-100">
                                        <i class="bi bi-stopwatch me-2"></i>Start Timer
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($role === 'client'): ?>
                    <!-- CLIENT DASHBOARD -->
                    <div class="row mb-4">
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                    <i class="bi bi-kanban"></i>
                                </div>
                                <div class="stat-value"><?= count($client_projects ?? []) ?></div>
                                <div class="stat-label">My Projects</div>
                            </div>
                        </div>
                        
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div class="stat-value"><?= $project_stats['completed_projects'] ?? 0 ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                        </div>
                        
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                                <div class="stat-value"><?= $project_stats['active_projects'] ?? 0 ?></div>
                                <div class="stat-label">In Progress</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-7 mb-4">
                            <div class="card shadow">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="m-0 font-weight-bold">Your Projects</h6>
                                    <a href="my-projects.php" class="btn btn-sm btn-primary">View All</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($client_projects)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-folder text-muted" style="font-size: 3rem;"></i>
                                            <h6 class="mt-3">No projects yet</h6>
                                            <p class="text-muted">You don't have any projects at the moment.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($client_projects as $project): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-3 p-3 border rounded">
                                                <div>
                                                    <h6 class="mb-1"><?= sanitize($project['project_name']) ?></h6>
                                                    <p class="text-muted small mb-1"><?= sanitize(substr($project['description'] ?? '', 0, 100)) ?>...</p>
                                                    <div>
                                                        <span class="badge bg-<?= 
                                                            $project['status'] == 'completed' ? 'success' : 
                                                            ($project['status'] == 'active' ? 'primary' : 
                                                            ($project['status'] == 'planning' ? 'info' : 
                                                            ($project['status'] == 'on_hold' ? 'warning' : 'secondary'))) 
                                                        ?>">
                                                            <?= ucfirst($project['status']) ?>
                                                        </span>
                                                        <span class="badge bg-light text-dark ms-2">
                                                            <i class="bi bi-calendar"></i> Due: <?= date('M d, Y', strtotime($project['due_date'] ?? $project['created_at'])) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <a href="projects.php?id=<?= $project['id'] ?>" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-arrow-right"></i>
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-5 mb-4">
                            <div class="card shadow">
                                <div class="card-header bg-white py-3">
                                    <h6 class="m-0 font-weight-bold">Recent Updates</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_tasks)): ?>
                                        <p class="text-muted text-center py-3">No recent updates</p>
                                    <?php else: ?>
                                        <?php foreach (array_slice($recent_tasks, 0, 5) as $task): ?>
                                            <div class="d-flex mb-3">
                                                <div class="me-3">
                                                    <?php if ($task['status'] == 'completed'): ?>
                                                        <i class="bi bi-check-circle-fill text-success"></i>
                                                    <?php elseif ($task['status'] == 'in_progress'): ?>
                                                        <i class="bi bi-play-circle-fill text-info"></i>
                                                    <?php elseif ($task['status'] == 'review'): ?>
                                                        <i class="bi bi-question-circle-fill text-primary"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-clock-fill text-warning"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <p class="mb-0"><strong><?= sanitize($task['task_title']) ?></strong></p>
                                                    <small class="text-muted">
                                                        <?= sanitize($task['project_name']) ?> • 
                                                        Updated: <?= date('M d, H:i', strtotime($task['updated_at'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="quick-access-card mt-4">
                                <h6>Need Assistance?</h6>
                                <div class="mt-3">
                                    <a href="requests.php?action=new" class="btn btn-light btn-sm w-100 mb-2">
                                        <i class="bi bi-envelope-plus me-2"></i>Submit Service Request
                                    </a>
                                    <a href="progress.php" class="btn btn-light btn-sm w-100">
                                        <i class="bi bi-file-earmark-text me-2"></i>View Progress Reports
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Initialize tooltips -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>
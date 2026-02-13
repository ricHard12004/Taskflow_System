<?php
// projects.php
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access projects.');
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    redirect('logout.php');
}

// Check if user has permission to manage projects
$can_manage = in_array($user_role, ['admin', 'manager']);

$action = $_GET['action'] ?? 'list';
$project_id = $_GET['id'] ?? 0;
$status_filter = $_GET['status'] ?? '';

$success_message = '';
$error_message = '';

// Get flash messages
$flash_success = getFlashMessage('success');
if ($flash_success) $success_message = $flash_success;
$flash_error = getFlashMessage('error');
if ($flash_error) $error_message = $flash_error;

// Handle project actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_action = $_POST['project_action'] ?? '';
    
    // Create/Update project
    if ($project_action === 'save_project' && $can_manage) {
        $project_id = $_POST['project_id'] ?? 0;
        $project_name = sanitize($_POST['project_name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $category = sanitize($_POST['category'] ?? 'General');
        $priority = $_POST['priority'] ?? 'medium';
        $start_date = $_POST['start_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? '';
        $status = $_POST['status'] ?? 'planning';
        
        $errors = [];
        
        if (empty($project_name)) {
            $errors[] = 'Project name is required.';
        }
        
        if (empty($due_date)) {
            $errors[] = 'Due date is required.';
        }
        
        if (empty($errors)) {
            try {
                if ($project_id > 0) {
                    // Check if user has permission to edit this project
                    $check_stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND (created_by = ? OR ? IN ('admin', 'manager'))");
                    $check_stmt->execute([$project_id, $user_id, $user_role]);
                    if (!$check_stmt->fetch()) {
                        displayFlashMessage('error', 'You do not have permission to edit this project.');
                        redirect('projects.php');
                    }
                    
                    // Update existing project
                    $stmt = $pdo->prepare("UPDATE projects SET 
                                          project_name = ?, description = ?, category = ?,
                                          priority = ?, start_date = ?, due_date = ?, status = ?,
                                          updated_at = NOW()
                                          WHERE id = ?");
                    $stmt->execute([$project_name, $description, $category, $priority, $start_date, $due_date, $status, $project_id]);
                    
                    logActivity($pdo, $user_id, 'PROJECT_UPDATE', "Updated project: {$project_name}");
                    displayFlashMessage('success', 'Project updated successfully!');
                } else {
                    // Create new project
                    $stmt = $pdo->prepare("INSERT INTO projects (project_name, description, category, priority, start_date, due_date, status, created_by, created_at) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$project_name, $description, $category, $priority, $start_date, $due_date, $status, $user_id]);
                    
                    logActivity($pdo, $user_id, 'PROJECT_CREATE', "Created project: {$project_name}");
                    displayFlashMessage('success', 'Project created successfully!');
                }
                
                redirect('projects.php');
                
            } catch (PDOException $e) {
                error_log("Project save error: " . $e->getMessage());
                $error_message = 'Unable to save project. Please try again.';
            }
        } else {
            $error_message = implode(' ', $errors);
        }
    }
}

// Get project details if viewing single project
if ($action === 'view' && $project_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT p.*, u.full_name as created_by_name 
                              FROM projects p 
                              LEFT JOIN users u ON p.created_by = u.id 
                              WHERE p.id = ? AND p.deleted_at IS NULL");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();
        
        if (!$project) {
            displayFlashMessage('error', 'Project not found.');
            redirect('projects.php');
        }
        
        // Check if user has access to this project
        if ($user_role === 'member') {
            $check_stmt = $pdo->prepare("SELECT id FROM tasks WHERE project_id = ? AND assigned_to = ?");
            $check_stmt->execute([$project_id, $user_id]);
            if (!$check_stmt->fetch() && $project['created_by'] != $user_id) {
                displayFlashMessage('error', 'You do not have access to this project.');
                redirect('projects.php');
            }
        } elseif ($user_role === 'manager' && $project['created_by'] != $user_id && $user_role !== 'admin') {
            $check_stmt = $pdo->prepare("SELECT id FROM tasks WHERE project_id = ? AND assigned_by = ?");
            $check_stmt->execute([$project_id, $user_id]);
            if (!$check_stmt->fetch()) {
                displayFlashMessage('error', 'You do not have access to this project.');
                redirect('projects.php');
            }
        }
        
        // Get tasks for this project
        $task_sql = "SELECT t.*, 
                     u.full_name as assigned_to_name,
                     u.email as assigned_to_email
                     FROM tasks t 
                     LEFT JOIN users u ON t.assigned_to = u.id 
                     WHERE t.project_id = ? AND t.deleted_at IS NULL 
                     ORDER BY 
                         CASE t.status 
                           WHEN 'pending' THEN 1 
                           WHEN 'in_progress' THEN 2 
                           WHEN 'review' THEN 3 
                           WHEN 'completed' THEN 4 
                           ELSE 5 
                         END,
                         t.due_date ASC";
        $stmt = $pdo->prepare($task_sql);
        $stmt->execute([$project_id]);
        $project_tasks = $stmt->fetchAll();
        
        // Calculate project progress
        $total_tasks = count($project_tasks);
        $completed_tasks = 0;
        foreach ($project_tasks as $task) {
            if ($task['status'] === 'completed') {
                $completed_tasks++;
            }
        }
        $progress_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
        
    } catch (PDOException $e) {
        error_log("Project fetch error: " . $e->getMessage());
        $error_message = 'Unable to load project details.';
    }
}

// Get list of projects
if ($action === 'list') {
    try {
        $sql = "SELECT p.*, 
                u.full_name as created_by_name,
                (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND deleted_at IS NULL) as task_count,
                (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = 'completed' AND deleted_at IS NULL) as completed_task_count
                FROM projects p 
                LEFT JOIN users u ON p.created_by = u.id 
                WHERE p.deleted_at IS NULL";
        
        $params = [];
        
        // Role-based filtering
        if ($user_role === 'member') {
            $sql .= " AND (p.created_by = ? OR p.id IN (SELECT DISTINCT project_id FROM tasks WHERE assigned_to = ?))";
            $params[] = $user_id;
            $params[] = $user_id;
        } elseif ($user_role === 'manager') {
            $sql .= " AND (p.created_by = ? OR p.id IN (SELECT DISTINCT project_id FROM tasks WHERE assigned_by = ?))";
            $params[] = $user_id;
            $params[] = $user_id;
        } elseif ($user_role === 'client') {
            $sql .= " AND p.status IN ('active', 'completed')";
        }
        
        // Status filter
        if (!empty($status_filter)) {
            $sql .= " AND p.status = ?";
            $params[] = $status_filter;
        }
        
        $sql .= " ORDER BY 
                  CASE p.status 
                    WHEN 'active' THEN 1 
                    WHEN 'planning' THEN 2 
                    WHEN 'on_hold' THEN 3 
                    WHEN 'completed' THEN 4 
                    WHEN 'cancelled' THEN 5
                    ELSE 6 
                  END,
                  p.due_date ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $projects = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Projects fetch error: " . $e->getMessage());
        $projects = [];
    }
}

$title = $action === 'view' ? 'Project Details' : 'Projects';
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
        }
        
        .main-content {
            padding: 2rem;
        }
        
        .project-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .project-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .project-card.status-active { border-left-color: #28a745; }
        .project-card.status-planning { border-left-color: #17a2b8; }
        .project-card.status-on_hold { border-left-color: #ffc107; }
        .project-card.status-completed { border-left-color: #6c757d; }
        .project-card.status-cancelled { border-left-color: #dc3545; }
        
        .progress {
            height: 8px;
            border-radius: 4px;
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
                        
                        <?php if ($user_role === 'admin'): ?>
                            <a href="users.php" class="nav-link">
                                <i class="bi bi-people"></i> User Management
                            </a>
                        <?php endif; ?>
                        
                        <a href="projects.php" class="nav-link active">
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
                                <a href="projects.php" class="text-decoration-none text-dark">
                                    <i class="bi bi-arrow-left me-2"></i>Back to Projects
                                </a>
                            <?php elseif ($action === 'create'): ?>
                                Create New Project
                            <?php elseif ($action === 'edit'): ?>
                                Edit Project
                            <?php else: ?>
                                Project Management
                            <?php endif; ?>
                        </h5>
                        <div class="d-flex align-items-center">
                            <span class="me-3">
                                <i class="bi bi-person-circle me-1"></i> <?= sanitize($user['full_name']) ?>
                            </span>
                            <span class="badge bg-primary"><?= ucfirst($user_role) ?></span>
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
                    
                    <!-- Project View -->
                    <?php if ($action === 'view' && isset($project)): ?>
                        <div class="row">
                            <div class="col-lg-8">
                                <!-- Project Details Card -->
                                <div class="card mb-4">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="bi bi-kanban me-2 text-primary"></i><?= sanitize($project['project_name']) ?>
                                        </h5>
                                        <div>
                                            <?php if ($can_manage || $project['created_by'] == $user_id): ?>
                                                <a href="projects.php?action=edit&id=<?= $project['id'] ?>" class="btn btn-sm btn-outline-primary me-2">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            <?php endif; ?>
                                            <span class="badge bg-<?= 
                                                $project['status'] == 'completed' ? 'success' : 
                                                ($project['status'] == 'active' ? 'primary' : 
                                                ($project['status'] == 'planning' ? 'info' : 
                                                ($project['status'] == 'on_hold' ? 'warning' : 
                                                ($project['status'] == 'cancelled' ? 'danger' : 'secondary')))) 
                                            ?>">
                                                <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <p class="text-muted mb-4"><?= nl2br(sanitize($project['description'] ?? 'No description provided.')) ?></p>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h6 class="fw-semibold mb-3">Project Information</h6>
                                                <table class="table table-borderless">
                                                    <tr>
                                                        <td class="text-muted">Category:</td>
                                                        <td class="fw-semibold"><?= sanitize($project['category']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted">Priority:</td>
                                                        <td>
                                                            <span class="badge bg-<?= 
                                                                $project['priority'] == 'critical' ? 'danger' : 
                                                                ($project['priority'] == 'high' ? 'warning' : 
                                                                ($project['priority'] == 'medium' ? 'info' : 'secondary')) 
                                                            ?>">
                                                                <?= ucfirst($project['priority']) ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted">Timeline:</td>
                                                        <td class="fw-semibold">
                                                            <?= date('M d, Y', strtotime($project['start_date'])) ?> - 
                                                            <?= date('M d, Y', strtotime($project['due_date'])) ?>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted">Created By:</td>
                                                        <td class="fw-semibold"><?= sanitize($project['created_by_name'] ?? 'Unknown') ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="fw-semibold mb-3">Progress</h6>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-muted">Overall Progress</span>
                                                        <span class="fw-semibold"><?= $progress_percentage ?>%</span>
                                                    </div>
                                                    <div class="progress mt-1">
                                                        <div class="progress-bar bg-success" style="width: <?= $progress_percentage ?>%"></div>
                                                    </div>
                                                </div>
                                                <div class="mt-3">
                                                    <span class="text-muted">Tasks:</span>
                                                    <span class="fw-semibold ms-2">
                                                        <?= $completed_tasks ?>/<?= $total_tasks ?> completed
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <hr>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="fw-semibold mb-0">Project Tasks</h6>
                                            <?php if ($can_manage || $project['created_by'] == $user_id): ?>
                                                <a href="tasks.php?action=create&project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-plus-circle me-1"></i>Add Task
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Tasks List -->
                                <?php if (empty($project_tasks)): ?>
                                    <div class="text-center py-5">
                                        <i class="bi bi-list-task text-muted" style="font-size: 3rem;"></i>
                                        <h6 class="mt-3">No tasks yet</h6>
                                        <p class="text-muted">This project doesn't have any tasks assigned.</p>
                                        <?php if ($can_manage || $project['created_by'] == $user_id): ?>
                                            <a href="tasks.php?action=create&project_id=<?= $project['id'] ?>" class="btn btn-primary btn-sm">
                                                <i class="bi bi-plus-circle me-1"></i>Create First Task
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($project_tasks as $task): ?>
                                        <div class="card mb-3">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1">
                                                            <a href="tasks.php?action=view&id=<?= $task['id'] ?>" class="text-decoration-none text-dark">
                                                                <?= sanitize($task['task_title']) ?>
                                                            </a>
                                                        </h6>
                                                        <div class="d-flex flex-wrap gap-3 mt-2">
                                                            <span class="text-muted small">
                                                                <i class="bi bi-person me-1"></i>
                                                                <?= sanitize($task['assigned_to_name'] ?? 'Unassigned') ?>
                                                            </span>
                                                            <span class="text-muted small">
                                                                <i class="bi bi-calendar me-1"></i>
                                                                Due: <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                            </span>
                                                            <span class="badge bg-<?= 
                                                                $task['priority'] == 'critical' ? 'danger' : 
                                                                ($task['priority'] == 'high' ? 'warning' : 
                                                                ($task['priority'] == 'medium' ? 'info' : 'secondary')) 
                                                            ?>">
                                                                <?= ucfirst($task['priority']) ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <span class="badge bg-<?= 
                                                        $task['status'] == 'completed' ? 'success' : 
                                                        ($task['status'] == 'in_progress' ? 'info' : 
                                                        ($task['status'] == 'review' ? 'primary' : 
                                                        ($task['status'] == 'overdue' ? 'danger' : 'warning'))) 
                                                    ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-lg-4">
                                <!-- Project Stats -->
                                <div class="card mb-4">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>Project Statistics</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="stat-item mb-3">
                                            <span class="text-muted">Created Date:</span>
                                            <p class="fw-semibold mb-0"><?= date('M d, Y', strtotime($project['created_at'])) ?></p>
                                        </div>
                                        <div class="stat-item mb-3">
                                            <span class="text-muted">Last Updated:</span>
                                            <p class="fw-semibold mb-0"><?= date('M d, Y', strtotime($project['updated_at'])) ?></p>
                                        </div>
                                        <?php if ($project['completed_date']): ?>
                                        <div class="stat-item mb-3">
                                            <span class="text-muted">Completed Date:</span>
                                            <p class="fw-semibold mb-0 text-success"><?= date('M d, Y', strtotime($project['completed_date'])) ?></p>
                                        </div>
                                        <?php endif; ?>
                                        <hr>
                                        <div class="stat-item">
                                            <span class="text-muted">Task Completion:</span>
                                            <h3 class="fw-bold text-success mb-0"><?= $progress_percentage ?>%</h3>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Quick Actions -->
                                <div class="card">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0"><i class="bi bi-lightning me-2 text-warning"></i>Quick Actions</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($can_manage || $project['created_by'] == $user_id): ?>
                                            <a href="tasks.php?action=create&project_id=<?= $project['id'] ?>" class="btn btn-outline-primary btn-sm w-100 mb-2">
                                                <i class="bi bi-plus-circle me-1"></i>Add New Task
                                            </a>
                                            <a href="projects.php?action=edit&id=<?= $project['id'] ?>" class="btn btn-outline-secondary btn-sm w-100 mb-2">
                                                <i class="bi bi-pencil me-1"></i>Edit Project
                                            </a>
                                        <?php endif; ?>
                                        <a href="tasks.php?project_id=<?= $project['id'] ?>" class="btn btn-outline-info btn-sm w-100">
                                            <i class="bi bi-list-task me-1"></i>View All Tasks
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    
                    <!-- Create/Edit Project Form -->
                    <?php elseif ($action === 'create' || $action === 'edit'): ?>
                        <?php if (!$can_manage): ?>
                            <?php displayFlashMessage('error', 'You do not have permission to manage projects.'); ?>
                            <?php redirect('projects.php'); ?>
                        <?php endif; ?>
                        
                        <?php
                        $edit_project = null;
                        if ($action === 'edit' && $project_id > 0) {
                            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND (created_by = ? OR ? = 'admin')");
                            $stmt->execute([$project_id, $user_id, $user_role]);
                            $edit_project = $stmt->fetch();
                            
                            if (!$edit_project) {
                                displayFlashMessage('error', 'Project not found or you do not have permission to edit.');
                                redirect('projects.php');
                            }
                        }
                        ?>
                        
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-<?= $action === 'create' ? 'plus-circle' : 'pencil-square' ?> me-2 text-primary"></i>
                                    <?= $action === 'create' ? 'Create New Project' : 'Edit Project' ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="project_action" value="save_project">
                                    <?php if ($action === 'edit' && $edit_project): ?>
                                        <input type="hidden" name="project_id" value="<?= $edit_project['id'] ?>">
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Project Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="project_name" 
                                                       value="<?= $action === 'edit' && $edit_project ? sanitize($edit_project['project_name']) : '' ?>" 
                                                       placeholder="Enter project name" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Description</label>
                                                <textarea class="form-control" name="description" rows="5" 
                                                          placeholder="Enter project description"><?= $action === 'edit' && $edit_project ? sanitize($edit_project['description']) : '' ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Category</label>
                                                <input type="text" class="form-control" name="category" 
                                                       value="<?= $action === 'edit' && $edit_project ? sanitize($edit_project['category']) : 'General' ?>"
                                                       placeholder="e.g., IT, Marketing, Development">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Priority</label>
                                                <select class="form-select" name="priority">
                                                    <option value="low" <?= ($action === 'edit' && $edit_project && $edit_project['priority'] == 'low') ? 'selected' : '' ?>>Low</option>
                                                    <option value="medium" <?= ($action === 'edit' && $edit_project && $edit_project['priority'] == 'medium') ? 'selected' : '' ?>>Medium</option>
                                                    <option value="high" <?= ($action === 'edit' && $edit_project && $edit_project['priority'] == 'high') ? 'selected' : '' ?>>High</option>
                                                    <option value="critical" <?= ($action === 'edit' && $edit_project && $edit_project['priority'] == 'critical') ? 'selected' : '' ?>>Critical</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Start Date</label>
                                                <input type="date" class="form-control" name="start_date" 
                                                       value="<?= $action === 'edit' && $edit_project ? $edit_project['start_date'] : date('Y-m-d') ?>">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" name="due_date" 
                                                       value="<?= $action === 'edit' && $edit_project ? $edit_project['due_date'] : date('Y-m-d', strtotime('+30 days')) ?>" 
                                                       required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Status</label>
                                                <select class="form-select" name="status">
                                                    <option value="planning" <?= ($action === 'edit' && $edit_project && $edit_project['status'] == 'planning') ? 'selected' : '' ?>>Planning</option>
                                                    <option value="active" <?= ($action === 'edit' && $edit_project && $edit_project['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                                    <option value="on_hold" <?= ($action === 'edit' && $edit_project && $edit_project['status'] == 'on_hold') ? 'selected' : '' ?>>On Hold</option>
                                                    <option value="completed" <?= ($action === 'edit' && $edit_project && $edit_project['status'] == 'completed') ? 'selected' : '' ?>>Completed</option>
                                                    <option value="cancelled" <?= ($action === 'edit' && $edit_project && $edit_project['status'] == 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <a href="projects.php" class="btn btn-secondary">
                                            <i class="bi bi-x-circle me-1"></i>Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>
                                            <?= $action === 'create' ? 'Create Project' : 'Update Project' ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    
                    <!-- Project List -->
                    <?php else: ?>
                        <!-- Filter Section -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <div class="btn-group" role="group">
                                    <a href="projects.php" class="btn btn-sm <?= !$status_filter ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        All Projects
                                    </a>
                                    <a href="projects.php?status=active" class="btn btn-sm <?= $status_filter == 'active' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        Active
                                    </a>
                                    <a href="projects.php?status=planning" class="btn btn-sm <?= $status_filter == 'planning' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        Planning
                                    </a>
                                    <a href="projects.php?status=completed" class="btn btn-sm <?= $status_filter == 'completed' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        Completed
                                    </a>
                                    <a href="projects.php?status=on_hold" class="btn btn-sm <?= $status_filter == 'on_hold' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                        On Hold
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <?php if ($can_manage): ?>
                                    <a href="projects.php?action=create" class="btn btn-primary">
                                        <i class="bi bi-plus-circle me-1"></i>New Project
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Projects Grid -->
                        <?php if (empty($projects)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-kanban text-muted" style="font-size: 4rem;"></i>
                                <h5 class="mt-3">No projects found</h5>
                                <p class="text-muted">There are no projects matching your criteria.</p>
                                <?php if ($can_manage): ?>
                                    <a href="projects.php?action=create" class="btn btn-primary mt-2">
                                        <i class="bi bi-plus-circle me-1"></i>Create Your First Project
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($projects as $project): ?>
                                    <?php 
                                    $progress = $project['task_count'] > 0 
                                        ? round(($project['completed_task_count'] / $project['task_count']) * 100) 
                                        : 0;
                                    ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="project-card status-<?= $project['status'] ?>">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h5 class="mb-0">
                                                    <a href="projects.php?action=view&id=<?= $project['id'] ?>" class="text-decoration-none text-dark">
                                                        <?= sanitize($project['project_name']) ?>
                                                    </a>
                                                </h5>
                                                <span class="badge bg-<?= 
                                                    $project['status'] == 'completed' ? 'success' : 
                                                    ($project['status'] == 'active' ? 'primary' : 
                                                    ($project['status'] == 'planning' ? 'info' : 
                                                    ($project['status'] == 'on_hold' ? 'warning' : 
                                                    ($project['status'] == 'cancelled' ? 'danger' : 'secondary')))) 
                                                ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                                                </span>
                                            </div>
                                            
                                            <p class="text-muted small mb-2">
                                                <?= sanitize(substr($project['description'] ?? '', 0, 100)) ?><?= strlen($project['description'] ?? '') > 100 ? '...' : '' ?>
                                            </p>
                                            
                                            <div class="mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span class="text-muted small">Progress</span>
                                                    <span class="small fw-semibold"><?= $progress ?>%</span>
                                                </div>
                                                <div class="progress mt-1">
                                                    <div class="progress-bar bg-success" style="width: <?= $progress ?>%"></div>
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between align-items-center mt-3">
                                                <div>
                                                    <span class="badge bg-light text-dark me-1">
                                                        <i class="bi bi-list-task me-1"></i><?= $project['task_count'] ?? 0 ?> tasks
                                                    </span>
                                                    <span class="badge bg-light text-dark">
                                                        <i class="bi bi-calendar me-1"></i><?= date('M d', strtotime($project['due_date'])) ?>
                                                    </span>
                                                </div>
                                                <a href="projects.php?action=view&id=<?= $project['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    View
                                                </a>
                                            </div>
                                            
                                            <div class="mt-2 text-muted small">
                                                <i class="bi bi-person me-1"></i><?= sanitize($project['created_by_name'] ?? 'Unknown') ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
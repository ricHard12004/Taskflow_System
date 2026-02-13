<?php
// tasks.php
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access tasks.');
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

// Check if user has permission to manage tasks
$can_manage = in_array($user_role, ['admin', 'manager']);

$action = $_GET['action'] ?? 'list';
$task_id = $_GET['id'] ?? 0;
$project_id = $_GET['project_id'] ?? 0;
$filter = $_GET['filter'] ?? 'all';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';

$success_message = '';
$error_message = '';

// Get flash messages
$flash_success = getFlashMessage('success');
if ($flash_success) $success_message = $flash_success;
$flash_error = getFlashMessage('error');
if ($flash_error) $error_message = $flash_error;

// Handle task actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_action = $_POST['task_action'] ?? '';
    
    // Create/Update task
    if ($task_action === 'save_task') {
        $task_id = $_POST['task_id'] ?? 0;
        $task_title = sanitize($_POST['task_title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $project_id = $_POST['project_id'] ?: null;
        $assigned_to = $_POST['assigned_to'] ?: null;
        $priority = $_POST['priority'] ?? 'medium';
        $due_date = $_POST['due_date'] ?? '';
        $status = $_POST['status'] ?? 'pending';
        
        $errors = [];
        
        if (empty($task_title)) {
            $errors[] = 'Task title is required.';
        }
        
        if (empty($due_date)) {
            $errors[] = 'Due date is required.';
        }
        
        if (empty($errors)) {
            try {
                if ($task_id > 0) {
                    // Check if user has permission to edit this task
                    $check_stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND (assigned_by = ? OR ? IN ('admin', 'manager'))");
                    $check_stmt->execute([$task_id, $user_id, $user_role]);
                    if (!$check_stmt->fetch()) {
                        displayFlashMessage('error', 'You do not have permission to edit this task.');
                        redirect('tasks.php');
                    }
                    
                    // Update existing task
                    $stmt = $pdo->prepare("UPDATE tasks SET 
                                          task_title = ?, description = ?, project_id = ?, 
                                          assigned_to = ?, priority = ?, due_date = ?, status = ?,
                                          updated_at = NOW()
                                          WHERE id = ?");
                    $stmt->execute([$task_title, $description, $project_id, $assigned_to, $priority, $due_date, $status, $task_id]);
                    
                    logActivity($pdo, $user_id, 'TASK_UPDATE', "Updated task: {$task_title}");
                    displayFlashMessage('success', 'Task updated successfully!');
                } else {
                    // Create new task
                    $stmt = $pdo->prepare("INSERT INTO tasks (task_title, description, project_id, assigned_to, assigned_by, priority, due_date, status, created_at) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$task_title, $description, $project_id, $assigned_to, $user_id, $priority, $due_date, $status]);
                    $task_id = $pdo->lastInsertId();
                    
                    // Create notification for assigned user
                    if ($assigned_to) {
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) 
                                              VALUES (?, 'task_assigned', 'New Task Assigned', ?, 'tasks.php?action=view&id={$task_id}')");
                        $stmt->execute([$assigned_to, "You have been assigned to task: {$task_title}"]);
                    }
                    
                    logActivity($pdo, $user_id, 'TASK_CREATE', "Created task: {$task_title}");
                    displayFlashMessage('success', 'Task created successfully!');
                }
                
                redirect('tasks.php');
                
            } catch (PDOException $e) {
                error_log("Task save error: " . $e->getMessage());
                $error_message = 'Unable to save task. Please try again.';
            }
        } else {
            $error_message = implode(' ', $errors);
        }
    }
    
    // Update task status
    elseif ($task_action === 'update_status') {
        $task_id = $_POST['task_id'] ?? 0;
        $new_status = $_POST['status'] ?? '';
        
        try {
            // Check if user has permission to update this task
            $check_stmt = $pdo->prepare("SELECT id, task_title, assigned_by, assigned_to FROM tasks WHERE id = ?");
            $check_stmt->execute([$task_id]);
            $task = $check_stmt->fetch();
            
            if (!$task) {
                displayFlashMessage('error', 'Task not found.');
                redirect('tasks.php');
            }
            
            $can_update = ($user_role === 'admin' || 
                          $user_role === 'manager' || 
                          $task['assigned_by'] == $user_id || 
                          $task['assigned_to'] == $user_id);
            
            if (!$can_update) {
                displayFlashMessage('error', 'You do not have permission to update this task.');
                redirect('tasks.php');
            }
            
            $stmt = $pdo->prepare("UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $task_id]);
            
            if ($stmt->rowCount() > 0) {
                if ($new_status === 'completed') {
                    $stmt = $pdo->prepare("UPDATE tasks SET completed_date = NOW() WHERE id = ?");
                    $stmt->execute([$task_id]);
                    
                    // Notify the assigner
                    if ($task['assigned_by'] != $user_id) {
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) 
                                              VALUES (?, 'task_completed', 'Task Completed', ?, 'tasks.php?action=view&id={$task_id}')");
                        $stmt->execute([$task['assigned_by'], "Task '{$task['task_title']}' has been completed"]);
                    }
                }
                
                logActivity($pdo, $user_id, 'TASK_STATUS', "Updated task #{$task_id} status to {$new_status}");
                displayFlashMessage('success', 'Task status updated successfully!');
            }
            
            redirect('tasks.php?action=view&id=' . $task_id);
            
        } catch (PDOException $e) {
            error_log("Status update error: " . $e->getMessage());
            $error_message = 'Unable to update task status.';
        }
    }
    
    // Add comment
    elseif ($task_action === 'add_comment') {
        $task_id = $_POST['task_id'] ?? 0;
        $comment = sanitize($_POST['comment'] ?? '');
        
        if (!empty($comment)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO task_comments (task_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$task_id, $user_id, $comment]);
                
                // Get task details for notification
                $stmt = $pdo->prepare("SELECT t.task_title, t.assigned_to, t.assigned_by FROM tasks t WHERE t.id = ?");
                $stmt->execute([$task_id]);
                $task = $stmt->fetch();
                
                // Notify relevant users
                $notify_users = [];
                if ($task['assigned_to'] && $task['assigned_to'] != $user_id) {
                    $notify_users[] = $task['assigned_to'];
                }
                if ($task['assigned_by'] && $task['assigned_by'] != $user_id) {
                    $notify_users[] = $task['assigned_by'];
                }
                
                foreach (array_unique($notify_users) as $uid) {
                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) 
                                          VALUES (?, 'task_comment', 'New Comment', ?, 'tasks.php?action=view&id={$task_id}')");
                    $stmt->execute([$uid, "New comment on task: {$task['task_title']}"]);
                }
                
                logActivity($pdo, $user_id, 'TASK_COMMENT', "Added comment to task #{$task_id}");
                displayFlashMessage('success', 'Comment added successfully!');
                
            } catch (PDOException $e) {
                error_log("Comment error: " . $e->getMessage());
                $error_message = 'Unable to add comment.';
            }
        }
        
        redirect('tasks.php?action=view&id=' . $task_id);
    }
}

// Get task details if viewing single task
if ($action === 'view' && $task_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT t.*, 
                              p.project_name,
                              p.status as project_status,
                              u_assigned.full_name as assigned_to_name,
                              u_assigned.email as assigned_to_email,
                              u_created.full_name as created_by_name,
                              (SELECT COUNT(*) FROM task_comments WHERE task_id = t.id) as comment_count
                              FROM tasks t 
                              LEFT JOIN projects p ON t.project_id = p.id 
                              LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id 
                              LEFT JOIN users u_created ON t.assigned_by = u_created.id 
                              WHERE t.id = ? AND t.deleted_at IS NULL");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch();
        
        if (!$task) {
            displayFlashMessage('error', 'Task not found.');
            redirect('tasks.php');
        }
        
        // Check permission
        $can_edit = ($user_role === 'admin' || 
                    $user_role === 'manager' || 
                    $task['assigned_by'] == $user_id || 
                    $task['assigned_to'] == $user_id);
        
        // Get task comments
        $stmt = $pdo->prepare("SELECT c.*, u.full_name, u.email, u.avatar 
                              FROM task_comments c 
                              JOIN users u ON c.user_id = u.id 
                              WHERE c.task_id = ? 
                              ORDER BY c.created_at DESC");
        $stmt->execute([$task_id]);
        $comments = $stmt->fetchAll();
        
        // Mark notifications as read for this task
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE 
                              WHERE user_id = ? AND link = ? AND is_read = FALSE");
        $stmt->execute([$user_id, 'tasks.php?action=view&id=' . $task_id]);
        
    } catch (PDOException $e) {
        error_log("Task fetch error: " . $e->getMessage());
        $error_message = 'Unable to load task details.';
    }
}

// Get list of tasks
if ($action === 'list') {
    try {
        $sql = "SELECT t.*, 
                p.project_name,
                u_assigned.full_name as assigned_to_name,
                u_created.full_name as created_by_name
                FROM tasks t 
                LEFT JOIN projects p ON t.project_id = p.id 
                LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id 
                LEFT JOIN users u_created ON t.assigned_by = u_created.id 
                WHERE t.deleted_at IS NULL";
        
        $params = [];
        
        // Role-based filtering
        if ($user_role === 'member') {
            $sql .= " AND t.assigned_to = ?";
            $params[] = $user_id;
        } elseif ($user_role === 'manager') {
            $sql .= " AND (t.assigned_by = ? OR t.assigned_to IS NOT NULL)";
            $params[] = $user_id;
        } elseif ($user_role === 'client') {
            $sql .= " AND p.status IN ('active', 'completed')";
        }
        
        // Project filter
        if ($project_id > 0) {
            $sql .= " AND t.project_id = ?";
            $params[] = $project_id;
        }
        
        // Status filter
        if (!empty($status_filter)) {
            $sql .= " AND t.status = ?";
            $params[] = $status_filter;
        }
        
        // Priority filter
        if (!empty($priority_filter)) {
            $sql .= " AND t.priority = ?";
            $params[] = $priority_filter;
        }
        
        // Special filters
        if ($filter === 'my') {
            $sql .= " AND t.assigned_to = ?";
            $params[] = $user_id;
        } elseif ($filter === 'created') {
            $sql .= " AND t.assigned_by = ?";
            $params[] = $user_id;
        } elseif ($filter === 'overdue') {
            $sql .= " AND t.due_date < CURDATE() AND t.status != 'completed'";
        } elseif ($filter === 'today') {
            $sql .= " AND DATE(t.due_date) = CURDATE() AND t.status != 'completed'";
        } elseif ($filter === 'week') {
            $sql .= " AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND t.status != 'completed'";
        }
        
        $sql .= " ORDER BY 
                  CASE 
                    WHEN t.due_date < CURDATE() AND t.status != 'completed' THEN 1
                    WHEN t.status = 'pending' THEN 2 
                    WHEN t.status = 'in_progress' THEN 3 
                    WHEN t.status = 'review' THEN 4 
                    WHEN t.status = 'completed' THEN 5 
                    ELSE 6 
                  END,
                  t.due_date ASC,
                  t.priority DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Tasks fetch error: " . $e->getMessage());
        $tasks = [];
    }
}

// Get users for assignment (managers and admins only)
if (in_array($user_role, ['admin', 'manager'])) {
    try {
        // Get assignable users
        $stmt = $pdo->prepare("SELECT id, full_name, email, role FROM users 
                              WHERE status = 'active' AND deleted_at IS NULL 
                              AND role IN ('member', 'manager') 
                              ORDER BY full_name");
        $stmt->execute();
        $assignable_users = $stmt->fetchAll();
        
        // Get projects - with proper permissions
        $sql = "SELECT id, project_name, status, created_by FROM projects WHERE deleted_at IS NULL";
        $params = [];
        
        if ($user_role === 'manager') {
            $sql .= " AND (created_by = ? OR id IN (SELECT DISTINCT project_id FROM tasks WHERE assigned_by = ?))";
            $params[] = $user_id;
            $params[] = $user_id;
        }
        
        $sql .= " ORDER BY project_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $projects_list = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Users fetch error: " . $e->getMessage());
        $assignable_users = [];
        $projects_list = [];
    }
}

$title = $action === 'view' ? 'Task Details' : 'Tasks';
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
        
        .task-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .task-card.priority-critical { border-left-color: #dc3545; }
        .task-card.priority-high { border-left-color: #fd7e14; }
        .task-card.priority-medium { border-left-color: #ffc107; }
        .task-card.priority-low { border-left-color: #17a2b8; }
        
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .comment-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
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
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .task-meta {
            font-size: 0.85rem;
        }
        
        .overdue {
            color: #dc3545;
            font-weight: 600;
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
                        
                        <a href="projects.php" class="nav-link">
                            <i class="bi bi-kanban"></i> Projects
                        </a>
                        
                        <a href="tasks.php" class="nav-link active">
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
                                <a href="tasks.php<?= $project_id > 0 ? '?project_id=' . $project_id : '' ?>" class="text-decoration-none text-dark">
                                    <i class="bi bi-arrow-left me-2"></i>Back to Tasks
                                </a>
                            <?php elseif ($action === 'create'): ?>
                                Create New Task
                            <?php elseif ($action === 'edit'): ?>
                                Edit Task
                            <?php else: ?>
                                Task Management
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
                    
                    <!-- Task View -->
                    <?php if ($action === 'view' && isset($task)): ?>
                        <div class="row">
                            <div class="col-lg-8">
                                <!-- Task Details Card -->
                                <div class="card mb-4">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="bi bi-check2-square me-2 text-primary"></i>Task Details
                                        </h5>
                                        <div>
                                            <?php if ($can_edit): ?>
                                                <a href="tasks.php?action=edit&id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary me-2">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            <?php endif; ?>
                                            <span class="status-badge bg-<?= 
                                                $task['status'] == 'completed' ? 'success' : 
                                                ($task['status'] == 'in_progress' ? 'info' : 
                                                ($task['status'] == 'review' ? 'primary' : 
                                                ($task['status'] == 'overdue' ? 'danger' : 'warning'))) 
                                            ?> text-white">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <h4><?= sanitize($task['task_title']) ?></h4>
                                        <p class="text-muted mb-4"><?= nl2br(sanitize($task['description'] ?? 'No description provided.')) ?></p>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <table class="table table-borderless">
                                                    <tr>
                                                        <td class="text-muted">Project:</td>
                                                        <td class="fw-semibold">
                                                            <?php if ($task['project_id']): ?>
                                                                <a href="projects.php?action=view&id=<?= $task['project_id'] ?>" class="text-decoration-none">
                                                                    <?= sanitize($task['project_name'] ?? 'Unnamed Project') ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="text-muted">No Project</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted">Priority:</td>
                                                        <td>
                                                            <span class="badge bg-<?= 
                                                                $task['priority'] == 'critical' ? 'danger' : 
                                                                ($task['priority'] == 'high' ? 'warning' : 
                                                                ($task['priority'] == 'medium' ? 'info' : 'secondary')) 
                                                            ?>">
                                                                <?= ucfirst($task['priority']) ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted">Due Date:</td>
                                                        <td class="fw-semibold <?= strtotime($task['due_date']) < time() && $task['status'] != 'completed' ? 'overdue' : '' ?>">
                                                            <i class="bi bi-calendar me-1"></i><?= date('F d, Y', strtotime($task['due_date'])) ?>
                                                            <?php if (strtotime($task['due_date']) < time() && $task['status'] != 'completed'): ?>
                                                                <span class="badge bg-danger ms-2">Overdue</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="col-md-6">
                                                <table class="table table-borderless">
                                                    <tr>
                                                        <td class="text-muted">Assigned To:</td>
                                                        <td class="fw-semibold">
                                                            <?php if ($task['assigned_to']): ?>
                                                                <i class="bi bi-person me-1"></i><?= sanitize($task['assigned_to_name'] ?? 'Unassigned') ?>
                                                                <br><small class="text-muted"><?= sanitize($task['assigned_to_email'] ?? '') ?></small>
                                                            <?php else: ?>
                                                                <span class="text-muted">Unassigned</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted">Created By:</td>
                                                        <td class="fw-semibold">
                                                            <i class="bi bi-person me-1"></i><?= sanitize($task['created_by_name'] ?? 'System') ?>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted">Created Date:</td>
                                                        <td class="fw-semibold">
                                                            <i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', strtotime($task['created_at'])) ?>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                        
                                        <?php if ($task['status'] != 'completed' && ($task['assigned_to'] == $user_id || $user_role === 'admin' || $user_role === 'manager' || $task['assigned_by'] == $user_id)): ?>
                                            <hr>
                                            <h6 class="fw-semibold mb-3">Update Status</h6>
                                            <form method="POST" class="row g-2 align-items-center">
                                                <input type="hidden" name="task_action" value="update_status">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <div class="col-auto">
                                                    <select name="status" class="form-select form-select-sm">
                                                        <option value="pending" <?= $task['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                        <option value="in_progress" <?= $task['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                        <option value="review" <?= $task['status'] == 'review' ? 'selected' : '' ?>>Review</option>
                                                        <option value="completed" <?= $task['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                                    </select>
                                                </div>
                                                <div class="col-auto">
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-check-circle me-1"></i>Update
                                                    </button>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Comments Section -->
                                <div class="card">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0">
                                            <i class="bi bi-chat-dots me-2 text-primary"></i>
                                            Comments (<?= count($comments) ?>)
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <!-- Add Comment Form -->
                                        <form method="POST" class="mb-4">
                                            <input type="hidden" name="task_action" value="add_comment">
                                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                            <div class="d-flex">
                                                <div class="flex-shrink-0">
                                                    <div class="user-avatar me-2">
                                                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <textarea class="form-control" name="comment" rows="2" placeholder="Add a comment..." required></textarea>
                                                    <button type="submit" class="btn btn-primary btn-sm mt-2">
                                                        <i class="bi bi-send me-1"></i>Post Comment
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                        
                                        <!-- Comments List -->
                                        <?php if (empty($comments)): ?>
                                            <div class="text-center py-4">
                                                <i class="bi bi-chat-dots text-muted" style="font-size: 2rem;"></i>
                                                <p class="text-muted mt-2">No comments yet. Be the first to comment!</p>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($comments as $comment): ?>
                                                <div class="comment-card">
                                                    <div class="d-flex">
                                                        <div class="flex-shrink-0">
                                                            <div class="user-avatar" style="background: <?= $comment['user_id'] == $user_id ? 'var(--primary-color)' : 'var(--secondary-color)' ?>">
                                                                <?= strtoupper(substr($comment['full_name'], 0, 1)) ?>
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1 ms-3">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <h6 class="mb-0"><?= sanitize($comment['full_name']) ?></h6>
                                                                <small class="text-muted">
                                                                    <i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', strtotime($comment['created_at'])) ?>
                                                                </small>
                                                            </div>
                                                            <p class="mt-2 mb-0"><?= nl2br(sanitize($comment['comment'])) ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-4">
                                <!-- Task Info Card -->
                                <div class="card mb-4">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>Task Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between mb-3">
                                            <span class="text-muted">Task ID:</span>
                                            <span class="fw-semibold">#<?= $task['id'] ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-3">
                                            <span class="text-muted">Created:</span>
                                            <span><?= date('M d, Y', strtotime($task['created_at'])) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-3">
                                            <span class="text-muted">Last Updated:</span>
                                            <span><?= date('M d, Y', strtotime($task['updated_at'])) ?></span>
                                        </div>
                                        <?php if ($task['completed_date']): ?>
                                            <div class="d-flex justify-content-between mb-3">
                                                <span class="text-muted">Completed:</span>
                                                <span class="text-success"><?= date('M d, Y', strtotime($task['completed_date'])) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between mb-3">
                                            <span class="text-muted">Comments:</span>
                                            <span class="badge bg-info"><?= count($comments) ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Quick Actions -->
                                <div class="card">
                                    <div class="card-header bg-white">
                                        <h6 class="mb-0"><i class="bi bi-lightning me-2 text-warning"></i>Quick Actions</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($task['assigned_to'] == $user_id && $task['status'] != 'completed'): ?>
                                            <?php if ($task['status'] == 'pending'): ?>
                                            <form method="POST" class="mb-2">
                                                <input type="hidden" name="task_action" value="update_status">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <input type="hidden" name="status" value="in_progress">
                                                <button type="submit" class="btn btn-outline-info btn-sm w-100 mb-2">
                                                    <i class="bi bi-play-circle me-1"></i>Start Working
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            
                                            <?php if ($task['status'] == 'in_progress'): ?>
                                            <form method="POST" class="mb-2">
                                                <input type="hidden" name="task_action" value="update_status">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <input type="hidden" name="status" value="review">
                                                <button type="submit" class="btn btn-outline-primary btn-sm w-100 mb-2">
                                                    <i class="bi bi-check-circle me-1"></i>Mark for Review
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            
                                            <?php if ($task['status'] == 'review' && ($task['assigned_by'] == $user_id || $user_role === 'admin' || $user_role === 'manager')): ?>
                                            <form method="POST" class="mb-2">
                                                <input type="hidden" name="task_action" value="update_status">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <button type="submit" class="btn btn-outline-success btn-sm w-100 mb-2">
                                                    <i class="bi bi-check2-all me-1"></i>Approve & Complete
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_edit): ?>
                                            <a href="tasks.php?action=edit&id=<?= $task['id'] ?>" class="btn btn-outline-secondary btn-sm w-100">
                                                <i class="bi bi-pencil me-1"></i>Edit Task
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($task['project_id']): ?>
                                            <a href="projects.php?action=view&id=<?= $task['project_id'] ?>" class="btn btn-outline-info btn-sm w-100 mt-2">
                                                <i class="bi bi-kanban me-1"></i>View Project
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    
                    <!-- Create/Edit Task Form -->
                    <?php elseif ($action === 'create' || $action === 'edit'): ?>
                        <?php if (!in_array($user_role, ['admin', 'manager'])): ?>
                            <?php displayFlashMessage('error', 'You do not have permission to create or edit tasks.'); ?>
                            <?php redirect('tasks.php'); ?>
                        <?php endif; ?>
                        
                        <?php
                        $edit_task = null;
                        if ($action === 'edit' && $task_id > 0) {
                            $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND (assigned_by = ? OR ? IN ('admin', 'manager'))");
                            $stmt->execute([$task_id, $user_id, $user_role]);
                            $edit_task = $stmt->fetch();
                            
                            if (!$edit_task) {
                                displayFlashMessage('error', 'Task not found or you do not have permission to edit.');
                                redirect('tasks.php');
                            }
                        }
                        
                        // Set default values
                        $selected_project_id = 0;
                        $selected_assigned_to = 0;
                        $selected_priority = 'medium';
                        $selected_status = 'pending';
                        $task_title = '';
                        $description = '';
                        $due_date = date('Y-m-d', strtotime('+7 days'));
                        
                        if ($action === 'edit' && $edit_task) {
                            $selected_project_id = $edit_task['project_id'];
                            $selected_assigned_to = $edit_task['assigned_to'];
                            $selected_priority = $edit_task['priority'];
                            $selected_status = $edit_task['status'];
                            $task_title = $edit_task['task_title'];
                            $description = $edit_task['description'];
                            $due_date = $edit_task['due_date'];
                        } elseif ($action === 'create' && $project_id > 0) {
                            $selected_project_id = $project_id;
                        }
                        ?>
                        
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-<?= $action === 'create' ? 'plus-circle' : 'pencil-square' ?> me-2 text-primary"></i>
                                    <?= $action === 'create' ? 'Create New Task' : 'Edit Task' ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="task_action" value="save_task">
                                    <?php if ($action === 'edit' && $edit_task): ?>
                                        <input type="hidden" name="task_id" value="<?= $edit_task['id'] ?>">
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Task Title <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="task_title" 
                                                       value="<?= sanitize($task_title) ?>" 
                                                       placeholder="Enter task title" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Description</label>
                                                <textarea class="form-control" name="description" rows="5" 
                                                          placeholder="Enter task description"><?= sanitize($description) ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Project</label>
                                                <select class="form-select" name="project_id">
                                                    <option value="">-- No Project --</option>
                                                    <?php if (!empty($projects_list)): ?>
                                                        <?php foreach ($projects_list as $project): ?>
                                                            <option value="<?= $project['id'] ?>" 
                                                                <?= ($selected_project_id == $project['id']) ? 'selected' : '' ?>>
                                                                <?= sanitize($project['project_name']) ?>
                                                                <?php if ($project['status'] != 'active'): ?>
                                                                    (<?= ucfirst($project['status']) ?>)
                                                                <?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Assign To</label>
                                                <select class="form-select" name="assigned_to">
                                                    <option value="">-- Unassigned --</option>
                                                    <?php if (!empty($assignable_users)): ?>
                                                        <?php foreach ($assignable_users as $user_option): ?>
                                                            <option value="<?= $user_option['id'] ?>" 
                                                                <?= ($selected_assigned_to == $user_option['id']) ? 'selected' : '' ?>>
                                                                <?= sanitize($user_option['full_name']) ?> (<?= ucfirst($user_option['role']) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Priority</label>
                                                <select class="form-select" name="priority">
                                                    <option value="low" <?= $selected_priority == 'low' ? 'selected' : '' ?>>Low</option>
                                                    <option value="medium" <?= $selected_priority == 'medium' ? 'selected' : '' ?>>Medium</option>
                                                    <option value="high" <?= $selected_priority == 'high' ? 'selected' : '' ?>>High</option>
                                                    <option value="critical" <?= $selected_priority == 'critical' ? 'selected' : '' ?>>Critical</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" name="due_date" 
                                                       value="<?= $due_date ?>" 
                                                       min="<?= date('Y-m-d') ?>" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Status</label>
                                                <select class="form-select" name="status">
                                                    <option value="pending" <?= $selected_status == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="in_progress" <?= $selected_status == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                    <option value="review" <?= $selected_status == 'review' ? 'selected' : '' ?>>Review</option>
                                                    <option value="completed" <?= $selected_status == 'completed' ? 'selected' : '' ?>>Completed</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <a href="tasks.php<?= $project_id > 0 ? '?project_id=' . $project_id : '' ?>" class="btn btn-secondary">
                                            <i class="bi bi-x-circle me-1"></i>Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>
                                            <?= $action === 'create' ? 'Create Task' : 'Update Task' ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    
                    <!-- Task List -->
                    <?php else: ?>
                        <!-- Filter Section -->
                        <div class="filter-section">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h6 class="mb-2"><i class="bi bi-funnel me-2"></i>Filter Tasks</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="tasks.php<?= $project_id > 0 ? '?project_id=' . $project_id : '' ?>" 
                                           class="btn btn-sm <?= !$filter || $filter == 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                            All Tasks
                                        </a>
                                        <a href="tasks.php?filter=my<?= $project_id > 0 ? '&project_id=' . $project_id : '' ?>" 
                                           class="btn btn-sm <?= $filter == 'my' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                            <i class="bi bi-person me-1"></i>My Tasks
                                        </a>
                                        <?php if ($user_role === 'manager' || $user_role === 'admin'): ?>
                                            <a href="tasks.php?filter=created<?= $project_id > 0 ? '&project_id=' . $project_id : '' ?>" 
                                               class="btn btn-sm <?= $filter == 'created' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                                <i class="bi bi-pencil me-1"></i>Created by Me
                                            </a>
                                        <?php endif; ?>
                                        <a href="tasks.php?filter=overdue<?= $project_id > 0 ? '&project_id=' . $project_id : '' ?>" 
                                           class="btn btn-sm <?= $filter == 'overdue' ? 'btn-danger' : 'btn-outline-danger' ?>">
                                            <i class="bi bi-exclamation-triangle me-1"></i>Overdue
                                        </a>
                                        <a href="tasks.php?filter=today<?= $project_id > 0 ? '&project_id=' . $project_id : '' ?>" 
                                           class="btn btn-sm <?= $filter == 'today' ? 'btn-warning' : 'btn-outline-warning' ?>">
                                            <i class="bi bi-calendar-day me-1"></i>Due Today
                                        </a>
                                        <a href="tasks.php?filter=week<?= $project_id > 0 ? '&project_id=' . $project_id : '' ?>" 
                                           class="btn btn-sm <?= $filter == 'week' ? 'btn-info' : 'btn-outline-info' ?>">
                                            <i class="bi bi-calendar-week me-1"></i>This Week
                                        </a>
                                    </div>
                                    
                                    <?php if ($project_id > 0): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-primary">
                                                <i class="bi bi-folder me-1"></i>Filtered by Project
                                                <a href="tasks.php" class="text-white text-decoration-none ms-1">
                                                    <i class="bi bi-x-circle"></i>
                                                </a>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                    <?php if (in_array($user_role, ['admin', 'manager'])): ?>
                                        <a href="tasks.php?action=create<?= $project_id > 0 ? '&project_id=' . $project_id : '' ?>" class="btn btn-primary">
                                            <i class="bi bi-plus-circle me-1"></i>New Task
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tasks List -->
                        <?php if (empty($tasks)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-check2-square text-muted" style="font-size: 4rem;"></i>
                                <h5 class="mt-3">No tasks found</h5>
                                <p class="text-muted">There are no tasks matching your criteria.</p>
                                <?php if (in_array($user_role, ['admin', 'manager'])): ?>
                                    <a href="tasks.php?action=create<?= $project_id > 0 ? '&project_id=' . $project_id : '' ?>" class="btn btn-primary mt-2">
                                        <i class="bi bi-plus-circle me-1"></i>Create Your First Task
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($tasks as $task): ?>
                                <div class="task-card priority-<?= $task['priority'] ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="mb-1">
                                                <a href="tasks.php?action=view&id=<?= $task['id'] ?>" class="text-decoration-none text-dark">
                                                    <?= sanitize($task['task_title']) ?>
                                                </a>
                                            </h5>
                                            <p class="text-muted small mb-2">
                                                <?= sanitize(substr($task['description'] ?? '', 0, 150)) ?><?= strlen($task['description'] ?? '') > 150 ? '...' : '' ?>
                                            </p>
                                        </div>
                                        <span class="status-badge bg-<?= 
                                            $task['status'] == 'completed' ? 'success' : 
                                            ($task['status'] == 'in_progress' ? 'info' : 
                                            ($task['status'] == 'review' ? 'primary' : 
                                            ($task['status'] == 'overdue' ? 'danger' : 'warning'))) 
                                        ?> text-white">
                                            <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="row mt-2 task-meta">
                                        <div class="col-md-8">
                                            <div class="d-flex flex-wrap gap-3">
                                                <?php if ($task['project_name']): ?>
                                                    <span class="text-muted">
                                                        <i class="bi bi-folder me-1"></i>
                                                        <a href="projects.php?action=view&id=<?= $task['project_id'] ?>" class="text-decoration-none">
                                                            <?= sanitize($task['project_name']) ?>
                                                        </a>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($task['assigned_to_name']): ?>
                                                    <span class="text-muted">
                                                        <i class="bi bi-person me-1"></i><?= sanitize($task['assigned_to_name']) ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <span class="<?= strtotime($task['due_date']) < time() && $task['status'] != 'completed' ? 'overdue' : 'text-muted' ?>">
                                                    <i class="bi bi-calendar me-1"></i>Due: <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                    <?php if (strtotime($task['due_date']) < time() && $task['status'] != 'completed'): ?>
                                                        <span class="badge bg-danger ms-1">Overdue</span>
                                                    <?php endif; ?>
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
                                        <div class="col-md-4 text-md-end mt-2 mt-md-0">
                                            <a href="tasks.php?action=view&id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                            <?php if ($task['assigned_to'] == $user_id && $task['status'] != 'completed'): ?>
                                                <?php if ($task['status'] == 'pending'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="task_action" value="update_status">
                                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                    <input type="hidden" name="status" value="in_progress">
                                                    <button type="submit" class="btn btn-sm btn-outline-info" title="Start Working">
                                                        <i class="bi bi-play-fill"></i>
                                                    </button>
                                                </form>
                                                <?php elseif ($task['status'] == 'in_progress'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="task_action" value="update_status">
                                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                    <input type="hidden" name="status" value="review">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Mark for Review">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (($task['assigned_by'] == $user_id || $user_role === 'admin' || $user_role === 'manager') && $task['status'] != 'completed'): ?>
                                                <a href="tasks.php?action=edit&id=<?= $task['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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
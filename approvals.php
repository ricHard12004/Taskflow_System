<?php
// approvals.php
require_once 'config/database.php';

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access approvals.');
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Only admin can access this page
if ($user_role !== 'admin') {
    displayFlashMessage('error', 'You do not have permission to access approvals.');
    redirect('dashboard.php');
}

// Get admin data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$admin = $stmt->fetch();

$action = $_GET['action'] ?? 'list';
$approval_id = $_GET['id'] ?? 0;
$filter = $_GET['filter'] ?? 'pending';

$success_message = '';
$error_message = '';

// Get flash messages
$flash_success = getFlashMessage('success');
if ($flash_success) $success_message = $flash_success;
$flash_error = getFlashMessage('error');
if ($flash_error) $error_message = $flash_error;

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $approval_action = $_POST['approval_action'] ?? '';
    
    // Approve single user
    if ($approval_action === 'approve_user') {
        $target_user_id = $_POST['user_id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->execute([$target_user_id]);
            
            if ($stmt->rowCount() > 0) {
                // Get user details
                $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $user = $stmt->fetch();
                
                // Log activity
                logActivity($pdo, $user_id, 'APPROVAL', "Approved user: {$user['full_name']}");
                
                // Create notification for the user
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) 
                                      VALUES (?, 'account_approved', 'Account Approved', ?, 'login.php')");
                $stmt->execute([$target_user_id, "Your account has been approved! You can now login."]);
                
                displayFlashMessage('success', "User {$user['full_name']} approved successfully!");
            }
            
        } catch (PDOException $e) {
            error_log("Approval error: " . $e->getMessage());
            $error_message = 'Unable to approve user.';
        }
        redirect('approvals.php');
    }
    
    // Reject/Deny user
    elseif ($approval_action === 'reject_user') {
        $target_user_id = $_POST['user_id'] ?? 0;
        $rejection_reason = sanitize($_POST['rejection_reason'] ?? 'Your account request was not approved.');
        
        try {
            // Soft delete the user (reject)
            $stmt = $pdo->prepare("UPDATE users SET status = 'inactive', deleted_at = NOW(), deleted_by = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$user_id, $target_user_id]);
            
            if ($stmt->rowCount() > 0) {
                // Get user details
                $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $user = $stmt->fetch();
                
                // Log activity
                logActivity($pdo, $user_id, 'REJECTION', "Rejected user: {$user['full_name']}");
                
                // Create notification for the user
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) 
                                      VALUES (?, 'account_rejected', 'Account Request Update', ?)");
                $stmt->execute([$target_user_id, $rejection_reason]);
                
                displayFlashMessage('success', "User {$user['full_name']} has been rejected.");
            }
            
        } catch (PDOException $e) {
            error_log("Rejection error: " . $e->getMessage());
            $error_message = 'Unable to reject user.';
        }
        redirect('approvals.php');
    }
    
    // Bulk approve users
    elseif ($approval_action === 'bulk_approve') {
        $user_ids = $_POST['user_ids'] ?? [];
        
        if (!empty($user_ids)) {
            try {
                $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                $stmt = $pdo->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id IN ($placeholders) AND status = 'pending'");
                $stmt->execute($user_ids);
                
                $approved_count = $stmt->rowCount();
                
                // Create notifications for approved users
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) 
                                      SELECT id, 'account_approved', 'Account Approved', 'Your account has been approved! You can now login.', 'login.php' 
                                      FROM users WHERE id IN ($placeholders)");
                $stmt->execute($user_ids);
                
                logActivity($pdo, $user_id, 'BULK_APPROVAL', "Bulk approved {$approved_count} users");
                displayFlashMessage('success', "{$approved_count} users approved successfully!");
                
            } catch (PDOException $e) {
                error_log("Bulk approval error: " . $e->getMessage());
                $error_message = 'Unable to process bulk approval.';
            }
        }
        redirect('approvals.php');
    }
}

// Get pending approvals
try {
    // Pending user accounts
    $stmt = $pdo->prepare("SELECT * FROM users WHERE status = 'pending' AND deleted_at IS NULL ORDER BY created_at DESC");
    $stmt->execute();
    $pending_users = $stmt->fetchAll();
    
    // Recently approved users (last 7 days)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND created_by IS NULL ORDER BY created_at DESC");
    $stmt->execute();
    $recent_approved = $stmt->fetchAll();
    
    // Approval statistics
    $stmt = $pdo->query("SELECT 
                        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                        COUNT(CASE WHEN status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as approved_week,
                        COUNT(CASE WHEN status = 'inactive' AND deleted_at IS NOT NULL AND deleted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as rejected_week
                        FROM users WHERE deleted_at IS NULL OR (deleted_at IS NOT NULL AND deleted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))");
    $stats = $stmt->fetch();
    
    // Approval history
    $stmt = $pdo->prepare("SELECT a.*, u.full_name as admin_name, 
                          (SELECT full_name FROM users WHERE id = a.user_id) as user_name
                          FROM activity_logs a 
                          LEFT JOIN users u ON a.user_id = u.id 
                          WHERE a.action IN ('APPROVAL', 'REJECTION', 'BULK_APPROVAL') 
                          ORDER BY a.created_at DESC LIMIT 50");
    $stmt->execute();
    $approval_history = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Approvals fetch error: " . $e->getMessage());
    $pending_users = [];
    $recent_approved = [];
    $approval_history = [];
}

$title = 'Account Approvals';
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
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    
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
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .approval-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
            border-left: 4px solid #ffc107;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .approval-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .badge-pending {
            background: #fff3cd;
            color: #856404;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .timeline {
            position: relative;
            padding-left: 1.5rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 0.25rem;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background: var(--primary-color);
            border: 2px solid white;
        }
        
        .timeline-item.approved::before {
            background: #28a745;
        }
        
        .timeline-item.rejected::before {
            background: #dc3545;
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
                        
                        <a href="users.php" class="nav-link">
                            <i class="bi bi-people"></i> User Management
                        </a>
                        
                        <a href="approvals.php" class="nav-link active">
                            <i class="bi bi-person-check"></i> Approvals
                            <?php if (count($pending_users) > 0): ?>
                                <span class="badge bg-danger ms-2"><?= count($pending_users) ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <a href="projects.php" class="nav-link">
                            <i class="bi bi-kanban"></i> Projects
                        </a>
                        
                        <a href="tasks.php" class="nav-link">
                            <i class="bi bi-check2-square"></i> Tasks
                        </a>
                        
                        <a href="reports.php" class="nav-link">
                            <i class="bi bi-bar-chart"></i> Reports
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
                            <i class="bi bi-person-check me-2 text-primary"></i>Account Approvals
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
                    <!-- Alert Messages -->
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
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                                <h2 class="mb-0"><?= count($pending_users) ?></h2>
                                <span class="text-muted">Pending Approvals</span>
                                <?php if (count($pending_users) > 0): ?>
                                    <button class="btn btn-warning btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#bulkApproveModal">
                                        <i class="bi bi-check-all me-1"></i>Bulk Approve
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-success bg-opacity-10 text-success">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <h2 class="mb-0"><?= $stats['approved_week'] ?? 0 ?></h2>
                                <span class="text-muted">Approved (7 days)</span>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                                <h2 class="mb-0"><?= $stats['rejected_week'] ?? 0 ?></h2>
                                <span class="text-muted">Rejected (7 days)</span>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stat-card">
                                <div class="stat-icon bg-info bg-opacity-10 text-info">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                                <h2 class="mb-0"><?= count($approval_history) ?></h2>
                                <span class="text-muted">Total Actions</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pending Approvals Section -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="bi bi-hourglass-split me-2 text-warning"></i>
                                        Pending Approvals
                                        <?php if (count($pending_users) > 0): ?>
                                            <span class="badge bg-warning ms-2"><?= count($pending_users) ?></span>
                                        <?php endif; ?>
                                    </h6>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary" onclick="$('#selectAll').click()">
                                            <i class="bi bi-check-all"></i> Select All
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($pending_users)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                                            <h6 class="mt-3">No Pending Approvals</h6>
                                            <p class="text-muted">All user accounts have been processed.</p>
                                        </div>
                                    <?php else: ?>
                                        <form id="bulkApprovalForm" method="POST">
                                            <input type="hidden" name="approval_action" value="bulk_approve">
                                            
                                            <?php foreach ($pending_users as $pending): ?>
                                                <div class="approval-card">
                                                    <div class="d-flex">
                                                        <div class="flex-shrink-0">
                                                            <div class="user-avatar">
                                                                <?= strtoupper(substr($pending['full_name'], 0, 1)) ?>
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1 ms-3">
                                                            <div class="d-flex justify-content-between align-items-start">
                                                                <div>
                                                                    <h6 class="mb-1"><?= sanitize($pending['full_name']) ?></h6>
                                                                    <p class="text-muted small mb-1">
                                                                        <i class="bi bi-envelope me-1"></i><?= sanitize($pending['email']) ?>
                                                                    </p>
                                                                    <div class="d-flex flex-wrap gap-2">
                                                                        <span class="badge bg-<?= 
                                                                            $pending['role'] == 'manager' ? 'primary' : 
                                                                            ($pending['role'] == 'member' ? 'info' : 'secondary') 
                                                                        ?>">
                                                                            <i class="bi bi-person-badge me-1"></i><?= ucfirst($pending['role']) ?>
                                                                        </span>
                                                                        <span class="badge bg-light text-dark">
                                                                            <i class="bi bi-calendar me-1"></i>Requested: <?= date('M d, Y', strtotime($pending['created_at'])) ?>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                                <div class="d-flex gap-2">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" name="user_ids[]" 
                                                                               value="<?= $pending['id'] ?>" id="user_<?= $pending['id'] ?>">
                                                                    </div>
                                                                    <div class="btn-group btn-group-sm">
                                                                        <form method="POST" class="d-inline">
                                                                            <input type="hidden" name="approval_action" value="approve_user">
                                                                            <input type="hidden" name="user_id" value="<?= $pending['id'] ?>">
                                                                            <button type="submit" class="btn btn-success" title="Approve">
                                                                                <i class="bi bi-check-lg"></i>
                                                                            </button>
                                                                        </form>
                                                                        <button type="button" class="btn btn-danger" 
                                                                                data-bs-toggle="modal" 
                                                                                data-bs-target="#rejectModal"
                                                                                data-user-id="<?= $pending['id'] ?>"
                                                                                data-user-name="<?= sanitize($pending['full_name']) ?>">
                                                                            <i class="bi bi-x-lg"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </form>
                                        
                                        <?php if (count($pending_users) > 1): ?>
                                            <div class="mt-3 text-end">
                                                <button type="submit" form="bulkApprovalForm" class="btn btn-warning">
                                                    <i class="bi bi-check-all me-1"></i>
                                                    Approve Selected (<?= count($pending_users) ?>)
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Recently Approved -->
                            <?php if (!empty($recent_approved)): ?>
                                <div class="card shadow">
                                    <div class="card-header bg-white py-3">
                                        <h6 class="m-0 font-weight-bold">
                                            <i class="bi bi-clock-history me-2 text-primary"></i>
                                            Recently Approved (Last 7 Days)
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>User</th>
                                                        <th>Role</th>
                                                        <th>Approved Date</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_approved as $approved): ?>
                                                        <tr>
                                                            <td>
                                                                <div class="d-flex align-items-center">
                                                                    <div class="user-avatar me-2" style="width: 35px; height: 35px; font-size: 0.9rem;">
                                                                        <?= strtoupper(substr($approved['full_name'], 0, 1)) ?>
                                                                    </div>
                                                                    <div>
                                                                        <?= sanitize($approved['full_name']) ?>
                                                                        <br><small class="text-muted"><?= sanitize($approved['email']) ?></small>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?= 
                                                                    $approved['role'] == 'manager' ? 'primary' : 
                                                                    ($approved['role'] == 'member' ? 'info' : 'secondary') 
                                                                ?>">
                                                                    <?= ucfirst($approved['role']) ?>
                                                                </span>
                                                            </td>
                                                            <td><?= date('M d, Y h:i A', strtotime($approved['updated_at'])) ?></td>
                                                            <td>
                                                                <span class="badge bg-success">Active</span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Approval History Timeline -->
                        <div class="col-lg-4">
                            <div class="card shadow">
                                <div class="card-header bg-white py-3">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="bi bi-clock-history me-2 text-primary"></i>
                                        Approval History
                                    </h6>
                                </div>
                                <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                                    <?php if (empty($approval_history)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-activity text-muted" style="font-size: 2rem;"></i>
                                            <p class="text-muted mt-2">No approval history yet.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="timeline">
                                            <?php foreach ($approval_history as $history): ?>
                                                <div class="timeline-item <?= strtolower($history['action']) ?>">
                                                    <div class="mb-1">
                                                        <span class="badge bg-<?= 
                                                            $history['action'] == 'APPROVAL' ? 'success' : 
                                                            ($history['action'] == 'BULK_APPROVAL' ? 'warning' : 'danger') 
                                                        ?>">
                                                            <?= $history['action'] ?>
                                                        </span>
                                                    </div>
                                                    <p class="mb-0 small">
                                                        <?= sanitize($history['description']) ?>
                                                    </p>
                                                    <small class="text-muted">
                                                        <i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', strtotime($history['created_at'])) ?>
                                                        <br>
                                                        <i class="bi bi-person me-1"></i><?= sanitize($history['admin_name'] ?? 'System') ?>
                                                    </small>
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
    
    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-x-circle text-danger me-2"></i>Reject Account Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="approval_action" value="reject_user">
                    <input type="hidden" name="user_id" id="reject_user_id">
                    <div class="modal-body">
                        <p>You are about to reject the account request for <strong id="reject_user_name"></strong>.</p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reason for Rejection</label>
                            <textarea class="form-control" name="rejection_reason" rows="3" 
                                      placeholder="Please provide a reason for rejection..."></textarea>
                            <small class="text-muted">This will be sent to the user via notification.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-x-circle me-1"></i>Reject Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bulk Approve Modal -->
    <div class="modal fade" id="bulkApproveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-check-all text-success me-2"></i>Bulk Approve Accounts
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>You are about to approve <strong><?= count($pending_users) ?></strong> pending account requests.</p>
                    <p class="text-muted">All selected users will receive notifications that their accounts have been approved.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="bulkApprovalForm" class="btn btn-success">
                        <i class="bi bi-check-all me-1"></i>Approve All
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTable for recent approvals
            $('.table').DataTable({
                pageLength: 5,
                lengthMenu: [[5, 10, 25, -1], [5, 10, 25, "All"]],
                order: [[2, 'desc']]
            });
            
            // Select All functionality
            let selectAll = false;
            $('#selectAll').click(function() {
                selectAll = !selectAll;
                $('input[name="user_ids[]"]').prop('checked', selectAll);
                $(this).text(selectAll ? 'Deselect All' : 'Select All');
            });
            
            // Reject modal data
            $('#rejectModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                var userId = button.data('user-id');
                var userName = button.data('user-name');
                
                var modal = $(this);
                modal.find('#reject_user_id').val(userId);
                modal.find('#reject_user_name').text(userName);
            });
            
            // Auto-refresh pending approvals every 30 seconds
            function refreshPendingCount() {
                $.ajax({
                    url: 'ajax/get_pending_count.php',
                    method: 'GET',
                    success: function(data) {
                        if (data.count !== undefined) {
                            $('.badge.bg-danger').text(data.count);
                            if (data.count > 0) {
                                $('.badge.bg-warning.ms-2').text(data.count);
                            }
                        }
                    }
                });
            }
            
            // Uncomment to enable auto-refresh
            // setInterval(refreshPendingCount, 30000);
        });
    </script>
</body>
</html>
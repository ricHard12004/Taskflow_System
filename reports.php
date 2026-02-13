<?php
// reports.php
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access reports.');
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$report_type = $_GET['type'] ?? 'dashboard';
$date_range = $_GET['range'] ?? 'week';
$export_format = $_GET['export'] ?? '';
$chart_data = [];

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_action = $_POST['report_action'] ?? '';
    
    if ($report_action === 'generate_report') {
        $report_type = $_POST['report_type'] ?? 'tasks';
        $date_range = $_POST['date_range'] ?? 'week';
        $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $end_date = $_POST['end_date'] ?? date('Y-m-d');
        
        // Redirect with parameters
        redirect("reports.php?type={$report_type}&range={$date_range}&start={$start_date}&end={$end_date}");
    }
    
    if ($report_action === 'export_report') {
        $export_type = $_POST['export_type'] ?? 'pdf';
        $report_data = $_POST['report_data'] ?? '';
        
        // Handle export based on type
        if ($export_type === 'csv') {
            exportToCSV($_POST);
        } elseif ($export_type === 'pdf') {
            exportToPDF($_POST);
        }
    }
}

// Get report data based on type
try {
    // Task Completion Report
    if ($report_type === 'tasks' || $report_type === 'dashboard') {
        // Overall task statistics
        $sql = "SELECT 
                COUNT(*) as total_tasks,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN status = 'review' THEN 1 ELSE 0 END) as review_tasks,
                SUM(CASE WHEN status = 'overdue' OR (due_date < CURDATE() AND status != 'completed') THEN 1 ELSE 0 END) as overdue_tasks,
                AVG(CASE WHEN status = 'completed' THEN DATEDIFF(completed_date, created_at) ELSE NULL END) as avg_completion_days
                FROM tasks WHERE deleted_at IS NULL";
        
        // Role-based filtering
        if ($user_role === 'member') {
            $sql .= " AND assigned_to = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
        } elseif ($user_role === 'manager') {
            $sql .= " AND (assigned_by = ? OR assigned_to IN (SELECT id FROM users WHERE role = 'member'))";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        }
        
        $task_stats = $stmt->fetch();
        
        // Tasks by priority
        $sql = "SELECT priority, COUNT(*) as count 
                FROM tasks WHERE deleted_at IS NULL";
        if ($user_role === 'member') {
            $sql .= " AND assigned_to = ?";
            $stmt = $pdo->prepare($sql . " GROUP BY priority");
            $stmt->execute([$user_id]);
        } elseif ($user_role === 'manager') {
            $sql .= " AND (assigned_by = ? OR assigned_to IN (SELECT id FROM users WHERE role = 'member'))";
            $stmt = $pdo->prepare($sql . " GROUP BY priority");
            $stmt->execute([$user_id]);
        } else {
            $stmt = $pdo->prepare($sql . " GROUP BY priority");
            $stmt->execute();
        }
        $tasks_by_priority = $stmt->fetchAll();
        
        // Daily task completion trend (last 30 days)
        $sql = "SELECT DATE(created_at) as date, COUNT(*) as created_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
                FROM tasks 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL";
        if ($user_role === 'member') {
            $sql .= " AND assigned_to = ?";
            $stmt = $pdo->prepare($sql . " GROUP BY DATE(created_at) ORDER BY date");
            $stmt->execute([$user_id]);
        } elseif ($user_role === 'manager') {
            $sql .= " AND (assigned_by = ? OR assigned_to IN (SELECT id FROM users WHERE role = 'member'))";
            $stmt = $pdo->prepare($sql . " GROUP BY DATE(created_at) ORDER BY date");
            $stmt->execute([$user_id]);
        } else {
            $stmt = $pdo->prepare($sql . " GROUP BY DATE(created_at) ORDER BY date");
            $stmt->execute();
        }
        $daily_tasks = $stmt->fetchAll();
        
        // Prepare chart data
        $chart_data['tasks'] = [
            'labels' => array_column($daily_tasks, 'date'),
            'created' => array_column($daily_tasks, 'created_count'),
            'completed' => array_column($daily_tasks, 'completed_count')
        ];
    }
    
    // Employee Performance Report
    if ($report_type === 'performance' && in_array($user_role, ['admin', 'manager'])) {
        $sql = "SELECT 
                u.id, u.full_name, u.email, u.role,
                COUNT(t.id) as total_tasks,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN t.due_date < CURDATE() AND t.status != 'completed' THEN 1 ELSE 0 END) as overdue_tasks,
                AVG(CASE WHEN t.status = 'completed' THEN DATEDIFF(t.completed_date, t.created_at) ELSE NULL END) as avg_completion_time,
                MAX(t.completed_date) as last_completed
                FROM users u
                LEFT JOIN tasks t ON u.id = t.assigned_to AND t.deleted_at IS NULL
                WHERE u.role IN ('member', 'manager') AND u.status = 'active' AND u.deleted_at IS NULL
                GROUP BY u.id
                ORDER BY completed_tasks DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $employee_performance = $stmt->fetchAll();
        
        // Prepare chart data
        $chart_data['performance'] = [
            'labels' => array_column(array_slice($employee_performance, 0, 10), 'full_name'),
            'completed' => array_column(array_slice($employee_performance, 0, 10), 'completed_tasks'),
            'pending' => array_column(array_slice($employee_performance, 0, 10), 'pending_tasks')
        ];
    }
    
    // Project Progress Report
    if ($report_type === 'projects' && in_array($user_role, ['admin', 'manager'])) {
        $sql = "SELECT 
                p.*,
                COUNT(t.id) as total_tasks,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                ROUND(COUNT(t.id) > 0 ? (SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) / COUNT(t.id) * 100) : 0, 1) as progress_percentage,
                u.full_name as manager_name
                FROM projects p
                LEFT JOIN tasks t ON p.id = t.project_id AND t.deleted_at IS NULL
                LEFT JOIN users u ON p.created_by = u.id
                WHERE p.deleted_at IS NULL
                GROUP BY p.id
                ORDER BY p.due_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $project_reports = $stmt->fetchAll();
        
        // Prepare chart data
        $chart_data['projects'] = [
            'labels' => array_column(array_slice($project_reports, 0, 10), 'project_name'),
            'progress' => array_column(array_slice($project_reports, 0, 10), 'progress_percentage')
        ];
    }
    
    // Deadline Compliance Report
    if ($report_type === 'deadline') {
        $sql = "SELECT 
                t.*,
                p.project_name,
                u.full_name as assigned_to_name,
                u2.full_name as assigned_by_name,
                DATEDIFF(t.completed_date, t.due_date) as days_overdue,
                CASE 
                    WHEN t.status = 'completed' AND t.completed_date <= t.due_date THEN 'On Time'
                    WHEN t.status = 'completed' AND t.completed_date > t.due_date THEN 'Late'
                    WHEN t.status != 'completed' AND t.due_date < CURDATE() THEN 'Overdue'
                    ELSE 'In Progress'
                END as compliance_status
                FROM tasks t
                LEFT JOIN projects p ON t.project_id = p.id
                LEFT JOIN users u ON t.assigned_to = u.id
                LEFT JOIN users u2 ON t.assigned_by = u2.id
                WHERE t.deleted_at IS NULL";
        
        if ($user_role === 'member') {
            $sql .= " AND t.assigned_to = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
        } elseif ($user_role === 'manager') {
            $sql .= " AND (t.assigned_by = ? OR t.assigned_to IN (SELECT id FROM users WHERE role = 'member'))";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        }
        $deadline_tasks = $stmt->fetchAll();
        
        // Calculate compliance statistics
        $total_tasks = count($deadline_tasks);
        $on_time = 0;
        $late = 0;
        $overdue = 0;
        $in_progress = 0;
        
        foreach ($deadline_tasks as $task) {
            if ($task['compliance_status'] === 'On Time') $on_time++;
            elseif ($task['compliance_status'] === 'Late') $late++;
            elseif ($task['compliance_status'] === 'Overdue') $overdue++;
            elseif ($task['compliance_status'] === 'In Progress') $in_progress++;
        }
        
        $compliance_rate = $total_tasks > 0 ? round(($on_time / $total_tasks) * 100, 1) : 0;
        
        // Prepare chart data
        $chart_data['deadline'] = [
            'labels' => ['On Time', 'Late', 'Overdue', 'In Progress'],
            'data' => [$on_time, $late, $overdue, $in_progress],
            'colors' => ['#28a745', '#ffc107', '#dc3545', '#17a2b8']
        ];
    }
    
} catch (PDOException $e) {
    error_log("Reports error: " . $e->getMessage());
}

// Export functions
function exportToCSV($data) {
    $filename = 'report_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers based on report type
    if ($data['report_type'] === 'tasks') {
        fputcsv($output, ['Task Title', 'Status', 'Priority', 'Assigned To', 'Due Date', 'Completed Date']);
        // Add data rows...
    }
    
    fclose($output);
    exit;
}

function exportToPDF($data) {
    // In a real implementation, use a library like TCPDF or Dompdf
    // For now, we'll just set headers for download
    $filename = 'report_' . date('Y-m-d') . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Simple HTML to PDF - in production use a proper library
    echo "PDF Export - This would contain the report data";
    exit;
}

$title = ucfirst($report_type) . ' Reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($title) ?> - TaskFlow Reports</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <!-- Date Range Picker -->
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
    
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
        
        .report-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-big {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .report-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .main-content {
                padding: 1rem;
            }
            .chart-container {
                height: 250px;
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
                            <a href="approvals.php" class="nav-link">
                                <i class="bi bi-person-check"></i> Approvals
                            </a>
                        <?php endif; ?>
                        
                        <a href="projects.php" class="nav-link">
                            <i class="bi bi-kanban"></i> Projects
                        </a>
                        
                        <a href="tasks.php" class="nav-link">
                            <i class="bi bi-check2-square"></i> Tasks
                        </a>
                        
                        <a href="reports.php" class="nav-link active">
                            <i class="bi bi-bar-chart"></i> Reports
                        </a>
                        
                        <a href="calendar.php" class="nav-link">
                            <i class="bi bi-calendar"></i> Calendar
                        </a>
                        
                        <a href="notifications.php" class="nav-link">
                            <i class="bi bi-bell"></i> Notifications
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
                            <i class="bi bi-bar-chart me-2 text-primary"></i>Reports & Analytics
                        </h5>
                        <div class="d-flex align-items-center">
                            <span class="me-3">
                                <i class="bi bi-person-circle me-1"></i> <?= sanitize($user['full_name']) ?>
                            </span>
                            <span class="badge bg-<?= 
                                $user_role == 'admin' ? 'danger' : 
                                ($user_role == 'manager' ? 'primary' : 
                                ($user_role == 'member' ? 'info' : 'secondary')) 
                            ?>">
                                <?= ucfirst($user_role) ?>
                            </span>
                        </div>
                    </div>
                </nav>
                
                <!-- Main Content Area -->
                <div class="main-content">
                    <!-- Report Header -->
                    <div class="report-header">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h2 class="text-white mb-2">
                                    <?php if ($report_type == 'dashboard'): ?>
                                        Analytics Dashboard
                                    <?php elseif ($report_type == 'tasks'): ?>
                                        Task Performance Report
                                    <?php elseif ($report_type == 'performance'): ?>
                                        Employee Performance Report
                                    <?php elseif ($report_type == 'projects'): ?>
                                        Project Progress Report
                                    <?php elseif ($report_type == 'deadline'): ?>
                                        Deadline Compliance Report
                                    <?php endif; ?>
                                </h2>
                                <p class="text-white opacity-75 mb-0">
                                    <i class="bi bi-calendar me-2"></i>Generated: <?= date('F d, Y h:i A') ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <button class="btn btn-light me-2" onclick="window.print()">
                                    <i class="bi bi-printer me-1"></i>Print
                                </button>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="bi bi-download me-1"></i>Export
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="report_action" value="export_report">
                                                <input type="hidden" name="export_type" value="pdf">
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi bi-file-pdf me-2 text-danger"></i>PDF
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="report_action" value="export_report">
                                                <input type="hidden" name="export_type" value="csv">
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi bi-file-excel me-2 text-success"></i>CSV
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Report Filters -->
                    <div class="filter-section">
                        <form method="POST" class="row g-3 align-items-end">
                            <input type="hidden" name="report_action" value="generate_report">
                            
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Report Type</label>
                                <select class="form-select" name="report_type">
                                    <option value="tasks" <?= $report_type == 'tasks' ? 'selected' : '' ?>>Task Performance</option>
                                    <?php if (in_array($user_role, ['admin', 'manager'])): ?>
                                        <option value="performance" <?= $report_type == 'performance' ? 'selected' : '' ?>>Employee Performance</option>
                                        <option value="projects" <?= $report_type == 'projects' ? 'selected' : '' ?>>Project Progress</option>
                                    <?php endif; ?>
                                    <option value="deadline" <?= $report_type == 'deadline' ? 'selected' : '' ?>>Deadline Compliance</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Date Range</label>
                                <select class="form-select" name="date_range">
                                    <option value="week" <?= $date_range == 'week' ? 'selected' : '' ?>>Last 7 Days</option>
                                    <option value="month" <?= $date_range == 'month' ? 'selected' : '' ?>>Last 30 Days</option>
                                    <option value="quarter" <?= $date_range == 'quarter' ? 'selected' : '' ?>>Last 90 Days</option>
                                    <option value="year" <?= $date_range == 'year' ? 'selected' : '' ?>>Last Year</option>
                                    <option value="custom">Custom Range</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3 custom-date-range" style="display: none;">
                                <label class="form-label fw-semibold">Start Date</label>
                                <input type="date" class="form-control" name="start_date" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                            </div>
                            
                            <div class="col-md-3 custom-date-range" style="display: none;">
                                <label class="form-label fw-semibold">End Date</label>
                                <input type="date" class="form-control" name="end_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search me-1"></i>Generate Report
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Report Content -->
                    <?php if ($report_type == 'dashboard' || $report_type == 'tasks'): ?>
                        <!-- Task Performance Dashboard -->
                        <div class="row">
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="report-card">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                                                <i class="bi bi-list-task fs-3"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="text-muted mb-1">Total Tasks</h6>
                                            <h3 class="mb-0"><?= $task_stats['total_tasks'] ?? 0 ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="report-card">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="stat-icon bg-success bg-opacity-10 text-success rounded-circle p-3">
                                                <i class="bi bi-check-circle fs-3"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="text-muted mb-1">Completed</h6>
                                            <h3 class="mb-0"><?= $task_stats['completed_tasks'] ?? 0 ?></h3>
                                            <small class="text-success">
                                                <?= $task_stats['total_tasks'] > 0 ? round(($task_stats['completed_tasks'] / $task_stats['total_tasks']) * 100, 1) : 0 ?>%
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="report-card">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                                                <i class="bi bi-hourglass-split fs-3"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="text-muted mb-1">In Progress</h6>
                                            <h3 class="mb-0"><?= ($task_stats['pending_tasks'] ?? 0) + ($task_stats['in_progress_tasks'] ?? 0) ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="report-card">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="stat-icon bg-danger bg-opacity-10 text-danger rounded-circle p-3">
                                                <i class="bi bi-exclamation-triangle fs-3"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="text-muted mb-1">Overdue</h6>
                                            <h3 class="mb-0"><?= $task_stats['overdue_tasks'] ?? 0 ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-lg-8 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-graph-up me-2 text-primary"></i>Daily Task Trend (Last 30 Days)
                                    </h6>
                                    <div class="chart-container">
                                        <canvas id="taskTrendChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-4 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-pie-chart me-2 text-primary"></i>Tasks by Priority
                                    </h6>
                                    <div class="chart-container">
                                        <canvas id="priorityChart"></canvas>
                                    </div>
                                    <div class="mt-3">
                                        <?php foreach ($tasks_by_priority as $priority): ?>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="text-capitalize">
                                                    <span class="badge bg-<?= 
                                                        $priority['priority'] == 'critical' ? 'danger' : 
                                                        ($priority['priority'] == 'high' ? 'warning' : 
                                                        ($priority['priority'] == 'medium' ? 'info' : 'secondary')) 
                                                    ?> me-2">●</span>
                                                    <?= ucfirst($priority['priority']) ?>
                                                </span>
                                                <span class="fw-semibold"><?= $priority['count'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-clock-history me-2 text-primary"></i>Average Completion Time
                                    </h6>
                                    <div class="stat-big">
                                        <?= $task_stats['avg_completion_days'] ? round($task_stats['avg_completion_days'], 1) : 0 ?> days
                                    </div>
                                    <p class="text-muted mb-0">Average time from creation to completion</p>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-check2-circle me-2 text-primary"></i>Completion Rate
                                    </h6>
                                    <div class="stat-big">
                                        <?= $task_stats['total_tasks'] > 0 ? round(($task_stats['completed_tasks'] / $task_stats['total_tasks']) * 100, 1) : 0 ?>%
                                    </div>
                                    <div class="progress mt-2" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?= $task_stats['total_tasks'] > 0 ? ($task_stats['completed_tasks'] / $task_stats['total_tasks']) * 100 : 0 ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    <?php elseif ($report_type == 'performance' && isset($employee_performance)): ?>
                        <!-- Employee Performance Report -->
                        <div class="row">
                            <div class="col-lg-6 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-trophy me-2 text-primary"></i>Top Performers
                                    </h6>
                                    <div class="chart-container">
                                        <canvas id="performanceChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-6 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-exclamation-triangle me-2 text-warning"></i>Overdue Tasks by Employee
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Employee</th>
                                                    <th>Total Tasks</th>
                                                    <th>Completed</th>
                                                    <th>Overdue</th>
                                                    <th>Rate</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($employee_performance as $emp): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="user-avatar me-2" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                                                    <?= strtoupper(substr($emp['full_name'], 0, 1)) ?>
                                                                </div>
                                                                <?= sanitize($emp['full_name']) ?>
                                                            </div>
                                                        </td>
                                                        <td><?= $emp['total_tasks'] ?></td>
                                                        <td class="text-success"><?= $emp['completed_tasks'] ?></td>
                                                        <td class="text-danger"><?= $emp['overdue_tasks'] ?></td>
                                                        <td>
                                                            <?= $emp['total_tasks'] > 0 ? round(($emp['completed_tasks'] / $emp['total_tasks']) * 100, 1) : 0 ?>%
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    <?php elseif ($report_type == 'projects' && isset($project_reports)): ?>
                        <!-- Project Progress Report -->
                        <div class="row">
                            <div class="col-lg-8 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-kanban me-2 text-primary"></i>Project Progress
                                    </h6>
                                    <div class="chart-container">
                                        <canvas id="projectProgressChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-4 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-folder me-2 text-primary"></i>Project Summary
                                    </h6>
                                    <div class="stat-big"><?= count($project_reports) ?></div>
                                    <p class="text-muted">Total Active Projects</p>
                                    <hr>
                                    <?php 
                                    $total_tasks_all = 0;
                                    $completed_tasks_all = 0;
                                    foreach ($project_reports as $project) {
                                        $total_tasks_all += $project['total_tasks'];
                                        $completed_tasks_all += $project['completed_tasks'];
                                    }
                                    ?>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Overall Progress:</span>
                                        <span class="fw-semibold"><?= $total_tasks_all > 0 ? round(($completed_tasks_all / $total_tasks_all) * 100, 1) : 0 ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-success" style="width: <?= $total_tasks_all > 0 ? ($completed_tasks_all / $total_tasks_all) * 100 : 0 ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-12">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-table me-2 text-primary"></i>Detailed Project Report
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="projectsTable">
                                            <thead>
                                                <tr>
                                                    <th>Project Name</th>
                                                    <th>Manager</th>
                                                    <th>Status</th>
                                                    <th>Due Date</th>
                                                    <th>Tasks</th>
                                                    <th>Completed</th>
                                                    <th>Progress</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($project_reports as $project): ?>
                                                    <tr>
                                                        <td>
                                                            <a href="projects.php?action=view&id=<?= $project['id'] ?>" class="text-decoration-none">
                                                                <?= sanitize($project['project_name']) ?>
                                                            </a>
                                                        </td>
                                                        <td><?= sanitize($project['manager_name'] ?? 'Unassigned') ?></td>
                                                        <td>
                                                            <span class="badge bg-<?= 
                                                                $project['status'] == 'completed' ? 'success' : 
                                                                ($project['status'] == 'active' ? 'primary' : 
                                                                ($project['status'] == 'planning' ? 'info' : 
                                                                ($project['status'] == 'on_hold' ? 'warning' : 'secondary'))) 
                                                            ?>">
                                                                <?= ucfirst($project['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td><?= date('M d, Y', strtotime($project['due_date'])) ?></td>
                                                        <td><?= $project['total_tasks'] ?></td>
                                                        <td><?= $project['completed_tasks'] ?></td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                                                    <div class="progress-bar bg-success" style="width: <?= $project['progress_percentage'] ?>%"></div>
                                                                </div>
                                                                <small><?= $project['progress_percentage'] ?>%</small>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    <?php elseif ($report_type == 'deadline'): ?>
                        <!-- Deadline Compliance Report -->
                        <div class="row">
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="report-card">
                                    <h3 class="text-success mb-2"><?= $compliance_rate ?>%</h3>
                                    <h6 class="text-muted mb-0">Deadline Compliance Rate</h6>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="report-card">
                                    <h3 class="text-success mb-2"><?= $on_time ?></h3>
                                    <h6 class="text-muted mb-0">Completed On Time</h6>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="report-card">
                                    <h3 class="text-warning mb-2"><?= $late ?></h3>
                                    <h6 class="text-muted mb-0">Completed Late</h6>
                                </div>
                            </div>
                            
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="report-card">
                                    <h3 class="text-danger mb-2"><?= $overdue ?></h3>
                                    <h6 class="text-muted mb-0">Currently Overdue</h6>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-lg-5 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-pie-chart me-2 text-primary"></i>Compliance Distribution
                                    </h6>
                                    <div class="chart-container">
                                        <canvas id="complianceChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-7 mb-4">
                                <div class="report-card">
                                    <h6 class="fw-semibold mb-3">
                                        <i class="bi bi-exclamation-triangle me-2 text-danger"></i>Overdue Tasks
                                    </h6>
                                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Task</th>
                                                    <th>Assigned To</th>
                                                    <th>Due Date</th>
                                                    <th>Days Overdue</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $overdue_shown = 0;
                                                foreach ($deadline_tasks as $task): 
                                                    if ($task['compliance_status'] === 'Overdue' && $overdue_shown < 10):
                                                        $overdue_shown++;
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <a href="tasks.php?action=view&id=<?= $task['id'] ?>" class="text-decoration-none">
                                                                <?= sanitize($task['task_title']) ?>
                                                            </a>
                                                        </td>
                                                        <td><?= sanitize($task['assigned_to_name'] ?? 'Unassigned') ?></td>
                                                        <td class="text-danger"><?= date('M d, Y', strtotime($task['due_date'])) ?></td>
                                                        <td>
                                                            <span class="badge bg-danger">
                                                                <?= abs($task['days_overdue'] ?? 0) ?> days
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                                <?php if ($overdue_shown == 0): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center py-3">
                                                            <i class="bi bi-check-circle text-success me-2"></i>No overdue tasks!
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script type="text/javascript" src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTables
            $('.table').DataTable({
                pageLength: 10,
                responsive: true
            });
            
            // Show/hide custom date range
            $('select[name="date_range"]').change(function() {
                if ($(this).val() === 'custom') {
                    $('.custom-date-range').show();
                } else {
                    $('.custom-date-range').hide();
                }
            });
            
            // Task Trend Chart
            <?php if (isset($chart_data['tasks'])): ?>
            const ctx1 = document.getElementById('taskTrendChart').getContext('2d');
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_data['tasks']['labels']) ?>,
                    datasets: [{
                        label: 'Tasks Created',
                        data: <?= json_encode($chart_data['tasks']['created']) ?>,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4
                    }, {
                        label: 'Tasks Completed',
                        data: <?= json_encode($chart_data['tasks']['completed']) ?>,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4
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
            <?php endif; ?>
            
            // Priority Chart
            <?php if (isset($tasks_by_priority)): ?>
            const ctx2 = document.getElementById('priorityChart').getContext('2d');
            new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_column($tasks_by_priority, 'priority')) ?>,
                    datasets: [{
                        data: <?= json_encode(array_column($tasks_by_priority, 'count')) ?>,
                        backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#17a2b8']
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
            <?php endif; ?>
            
            // Performance Chart
            <?php if (isset($chart_data['performance'])): ?>
            const ctx3 = document.getElementById('performanceChart').getContext('2d');
            new Chart(ctx3, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_data['performance']['labels']) ?>,
                    datasets: [{
                        label: 'Completed Tasks',
                        data: <?= json_encode($chart_data['performance']['completed']) ?>,
                        backgroundColor: '#28a745'
                    }, {
                        label: 'Pending Tasks',
                        data: <?= json_encode($chart_data['performance']['pending']) ?>,
                        backgroundColor: '#ffc107'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
            <?php endif; ?>
            
            // Project Progress Chart
            <?php if (isset($chart_data['projects'])): ?>
            const ctx4 = document.getElementById('projectProgressChart').getContext('2d');
            new Chart(ctx4, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_data['projects']['labels']) ?>,
                    datasets: [{
                        label: 'Progress %',
                        data: <?= json_encode($chart_data['projects']['progress']) ?>,
                        backgroundColor: '#667eea'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });
            <?php endif; ?>
            
            // Compliance Chart
            <?php if (isset($chart_data['deadline'])): ?>
            const ctx5 = document.getElementById('complianceChart').getContext('2d');
            new Chart(ctx5, {
                type: 'pie',
                data: {
                    labels: <?= json_encode($chart_data['deadline']['labels']) ?>,
                    datasets: [{
                        data: <?= json_encode($chart_data['deadline']['data']) ?>,
                        backgroundColor: <?= json_encode($chart_data['deadline']['colors']) ?>
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
            <?php endif; ?>
        });
    </script>
</body>
</html>
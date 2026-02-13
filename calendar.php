<?php
// calendar.php
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to access calendar.');
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get calendar view type
$view = $_GET['view'] ?? 'month';
$date = $_GET['date'] ?? date('Y-m-d');
$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

// Fetch tasks for calendar
try {
    // Build query based on role
    $sql = "SELECT 
            t.id, t.task_title as title, t.description, t.due_date as start,
            t.status, t.priority, t.project_id,
            p.project_name,
            u.full_name as assigned_to_name,
            CASE 
                WHEN t.status = 'completed' THEN '#28a745'
                WHEN t.status = 'overdue' OR (t.due_date < CURDATE() AND t.status != 'completed') THEN '#dc3545'
                WHEN t.priority = 'critical' THEN '#dc3545'
                WHEN t.priority = 'high' THEN '#fd7e14'
                WHEN t.priority = 'medium' THEN '#ffc107'
                ELSE '#17a2b8'
            END as color,
            CASE 
                WHEN t.status = 'completed' THEN 'rgba(40, 167, 69, 0.1)'
                ELSE 'transparent'
            END as backgroundColor
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.deleted_at IS NULL";
    
    $params = [];
    
    if ($user_role === 'member') {
        $sql .= " AND t.assigned_to = ?";
        $params[] = $user_id;
    } elseif ($user_role === 'manager') {
        $sql .= " AND (t.assigned_by = ? OR t.assigned_to IN (SELECT id FROM users WHERE role = 'member'))";
        $params[] = $user_id;
    } elseif ($user_role === 'client') {
        $sql .= " AND p.id IN (SELECT id FROM projects WHERE status IN ('active', 'completed'))";
    }
    
    // Filter by month/year for month view
    if ($view === 'month') {
        $sql .= " AND MONTH(t.due_date) = ? AND YEAR(t.due_date) = ?";
        $params[] = $month;
        $params[] = $year;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
    
    // Format tasks for FullCalendar
    $events = [];
    foreach ($tasks as $task) {
        $events[] = [
            'id' => $task['id'],
            'title' => $task['title'],
            'start' => $task['start'],
            'end' => $task['start'],
            'color' => $task['color'],
            'textColor' => '#ffffff',
            'extendedProps' => [
                'status' => $task['status'],
                'priority' => $task['priority'],
                'project' => $task['project_name'],
                'assigned_to' => $task['assigned_to_name'],
                'description' => $task['description']
            ]
        ];
    }
    
    // Get upcoming deadlines
    $sql = "SELECT t.*, p.project_name, u.full_name as assigned_to_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND t.status != 'completed' AND t.deleted_at IS NULL";
    
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
    $upcoming_tasks = $stmt->fetchAll();
    
    // Get overdue tasks
    $sql = "SELECT t.*, p.project_name, u.full_name as assigned_to_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.due_date < CURDATE() 
            AND t.status != 'completed' AND t.deleted_at IS NULL";
    
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
    $overdue_tasks = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Calendar error: " . $e->getMessage());
    $events = [];
    $upcoming_tasks = [];
    $overdue_tasks = [];
}

$title = 'Calendar';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($title) ?> - TaskFlow Calendar</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.css" rel="stylesheet">
    
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
        
        .calendar-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        #calendar {
            min-height: 600px;
        }
        
        .task-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .task-card.overdue {
            border-left-color: #dc3545;
        }
        
        .task-card.upcoming {
            border-left-color: #ffc107;
        }
        
        .status-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .fc-event {
            cursor: pointer;
            border-radius: 6px;
            padding: 4px 8px;
            margin-bottom: 2px;
            border: none !important;
        }
        
        .fc-event-title {
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .fc-day-today {
            background-color: rgba(102, 126, 234, 0.05) !important;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .main-content {
                padding: 1rem;
            }
            #calendar {
                min-height: 400px;
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
                        
                        <?php if (in_array($user_role, ['admin', 'manager'])): ?>
                            <a href="projects.php" class="nav-link">
                                <i class="bi bi-kanban"></i> Projects
                            </a>
                        <?php endif; ?>
                        
                        <a href="tasks.php" class="nav-link">
                            <i class="bi bi-check2-square"></i> Tasks
                        </a>
                        
                        <a href="reports.php" class="nav-link">
                            <i class="bi bi-bar-chart"></i> Reports
                        </a>
                        
                        <a href="calendar.php" class="nav-link active">
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
                            <i class="bi bi-calendar me-2 text-primary"></i>Task Calendar
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
                    <div class="row">
                        <!-- Calendar Column -->
                        <div class="col-lg-8 mb-4">
                            <div class="calendar-container">
                                <div id="calendar"></div>
                            </div>
                        </div>
                        
                        <!-- Sidebar Column -->
                        <div class="col-lg-4">
                            <!-- Upcoming Deadlines -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-white py-3">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="bi bi-clock-history me-2 text-warning"></i>
                                        Upcoming Deadlines (7 Days)
                                        <?php if (count($upcoming_tasks) > 0): ?>
                                            <span class="badge bg-warning ms-2"><?= count($upcoming_tasks) ?></span>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                    <?php if (empty($upcoming_tasks)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                                            <p class="text-muted mt-2">No upcoming deadlines</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($upcoming_tasks as $task): ?>
                                            <div class="task-card upcoming">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <h6 class="mb-1">
                                                        <a href="tasks.php?action=view&id=<?= $task['id'] ?>" class="text-decoration-none text-dark">
                                                            <?= sanitize($task['task_title']) ?>
                                                        </a>
                                                    </h6>
                                                    <span class="status-badge bg-<?= 
                                                        $task['priority'] == 'critical' ? 'danger' : 
                                                        ($task['priority'] == 'high' ? 'warning' : 
                                                        ($task['priority'] == 'medium' ? 'info' : 'secondary')) 
                                                    ?> text-white">
                                                        <?= ucfirst($task['priority']) ?>
                                                    </span>
                                                </div>
                                                <p class="text-muted small mb-1">
                                                    <i class="bi bi-person me-1"></i><?= sanitize($task['assigned_to_name'] ?? 'Unassigned') ?>
                                                    <?php if ($task['project_name']): ?>
                                                        <br><i class="bi bi-folder me-1"></i><?= sanitize($task['project_name']) ?>
                                                    <?php endif; ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="text-danger small">
                                                        <i class="bi bi-calendar me-1"></i><?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                    </span>
                                                    <?php
                                                    $days_left = ceil((strtotime($task['due_date']) - time()) / 86400);
                                                    ?>
                                                    <span class="badge bg-<?= $days_left <= 2 ? 'danger' : 'warning' ?>">
                                                        <?= $days_left ?> day<?= $days_left > 1 ? 's' : '' ?> left
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Overdue Tasks -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-white py-3">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="bi bi-exclamation-triangle me-2 text-danger"></i>
                                        Overdue Tasks
                                        <?php if (count($overdue_tasks) > 0): ?>
                                            <span class="badge bg-danger ms-2"><?= count($overdue_tasks) ?></span>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                    <?php if (empty($overdue_tasks)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-emoji-smile text-success" style="font-size: 2rem;"></i>
                                            <p class="text-muted mt-2">No overdue tasks!</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($overdue_tasks as $task): ?>
                                            <div class="task-card overdue">
                                                <h6 class="mb-1">
                                                    <a href="tasks.php?action=view&id=<?= $task['id'] ?>" class="text-decoration-none text-dark">
                                                        <?= sanitize($task['task_title']) ?>
                                                    </a>
                                                </h6>
                                                <p class="text-muted small mb-1">
                                                    <i class="bi bi-person me-1"></i><?= sanitize($task['assigned_to_name'] ?? 'Unassigned') ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="text-danger small">
                                                        <i class="bi bi-calendar me-1"></i>Due: <?= date('M d, Y', strtotime($task['due_date'])) ?>
                                                    </span>
                                                    <?php
                                                    $days_overdue = abs(ceil((time() - strtotime($task['due_date'])) / 86400));
                                                    ?>
                                                    <span class="badge bg-danger">
                                                        <?= $days_overdue ?> day<?= $days_overdue > 1 ? 's' : '' ?> overdue
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Legend -->
                            <div class="card shadow">
                                <div class="card-header bg-white py-3">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="bi bi-info-circle me-2 text-primary"></i>Legend
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-2">
                                        <span style="display: inline-block; width: 20px; height: 20px; background: #dc3545; border-radius: 4px; margin-right: 10px;"></span>
                                        <span>Critical / Overdue</span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2">
                                        <span style="display: inline-block; width: 20px; height: 20px; background: #fd7e14; border-radius: 4px; margin-right: 10px;"></span>
                                        <span>High Priority</span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2">
                                        <span style="display: inline-block; width: 20px; height: 20px; background: #ffc107; border-radius: 4px; margin-right: 10px;"></span>
                                        <span>Medium Priority</span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2">
                                        <span style="display: inline-block; width: 20px; height: 20px; background: #17a2b8; border-radius: 4px; margin-right: 10px;"></span>
                                        <span>Low Priority</span>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <span style="display: inline-block; width: 20px; height: 20px; background: #28a745; border-radius: 4px; margin-right: 10px;"></span>
                                        <span>Completed</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Task Details Modal -->
    <div class="modal fade" id="taskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-info-circle me-2 text-primary"></i>Task Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 id="modalTaskTitle" class="fw-semibold"></h6>
                    <p id="modalTaskDescription" class="text-muted"></p>
                    
                    <div class="row mt-3">
                        <div class="col-6">
                            <span class="text-muted">Status:</span><br>
                            <span id="modalTaskStatus" class="badge"></span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Priority:</span><br>
                            <span id="modalTaskPriority" class="badge"></span>
                        </div>
                    </div>
                    
                    <div class="row mt-2">
                        <div class="col-6">
                            <span class="text-muted">Project:</span><br>
                            <span id="modalTaskProject"></span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Assigned To:</span><br>
                            <span id="modalTaskAssignee"></span>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <span class="text-muted">Due Date:</span><br>
                        <span id="modalTaskDate"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="modalTaskLink" class="btn btn-primary">View Task</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: <?= json_encode($events) ?>,
                eventClick: function(info) {
                    // Populate modal with event details
                    document.getElementById('modalTaskTitle').textContent = info.event.title;
                    document.getElementById('modalTaskDescription').textContent = info.event.extendedProps.description || 'No description provided.';
                    document.getElementById('modalTaskDate').textContent = new Date(info.event.start).toLocaleDateString('en-US', {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    
                    // Status badge
                    var statusEl = document.getElementById('modalTaskStatus');
                    var status = info.event.extendedProps.status || 'pending';
                    statusEl.textContent = status.replace('_', ' ').toUpperCase();
                    statusEl.className = 'badge';
                    
                    if (status === 'completed') {
                        statusEl.classList.add('bg-success');
                    } else if (status === 'in_progress') {
                        statusEl.classList.add('bg-info');
                    } else if (status === 'review') {
                        statusEl.classList.add('bg-primary');
                    } else if (status === 'overdue' || (new Date(info.event.start) < new Date() && status !== 'completed')) {
                        statusEl.classList.add('bg-danger');
                    } else {
                        statusEl.classList.add('bg-warning');
                    }
                    
                    // Priority badge
                    var priorityEl = document.getElementById('modalTaskPriority');
                    var priority = info.event.extendedProps.priority || 'medium';
                    priorityEl.textContent = priority.toUpperCase();
                    priorityEl.className = 'badge';
                    
                    if (priority === 'critical' || priority === 'high') {
                        priorityEl.classList.add('bg-danger');
                    } else if (priority === 'medium') {
                        priorityEl.classList.add('bg-warning');
                    } else {
                        priorityEl.classList.add('bg-info');
                    }
                    
                    // Other details
                    document.getElementById('modalTaskProject').textContent = info.event.extendedProps.project || 'No Project';
                    document.getElementById('modalTaskAssignee').textContent = info.event.extendedProps.assigned_to || 'Unassigned';
                    document.getElementById('modalTaskLink').href = 'tasks.php?action=view&id=' + info.event.id;
                    
                    // Show modal
                    var taskModal = new bootstrap.Modal(document.getElementById('taskModal'));
                    taskModal.show();
                },
                dateClick: function(info) {
                    // Redirect to create task on this date
                    window.location.href = 'tasks.php?action=create&due_date=' + info.dateStr;
                },
                height: 'auto',
                aspectRatio: 1.8,
                slotMinTime: '08:00:00',
                slotMaxTime: '20:00:00',
                nowIndicator: true,
                locale: 'en',
                firstDay: 1, // Monday
                buttonText: {
                    today: 'Today',
                    month: 'Month',
                    week: 'Week',
                    day: 'Day'
                }
            });
            
            calendar.render();
            
            // Auto-refresh calendar every 5 minutes
            setInterval(function() {
                calendar.refetchEvents();
            }, 300000);
        });
    </script>
</body>
</html>
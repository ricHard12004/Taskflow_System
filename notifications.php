<?php
// notifications.php
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    displayFlashMessage('error', 'Please login to view notifications.');
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$action = $_GET['action'] ?? 'list';
$notification_id = $_GET['id'] ?? 0;
$filter = $_GET['filter'] ?? 'all';

$success_message = '';
$error_message = '';

// Get flash messages
$flash_success = getFlashMessage('success');
if ($flash_success) $success_message = $flash_success;
$flash_error = getFlashMessage('error');
if ($flash_error) $error_message = $flash_error;

// Handle notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notif_action = $_POST['notif_action'] ?? '';
    
    // Mark notification as read
    if ($notif_action === 'mark_read') {
        $notif_id = $_POST['notification_id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
            $stmt->execute([$notif_id, $user_id]);
            
            echo json_encode(['success' => true]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Mark read error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    
    // Mark all as read
    elseif ($notif_action === 'mark_all_read') {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
            $stmt->execute([$user_id]);
            
            displayFlashMessage('success', 'All notifications marked as read.');
            
        } catch (PDOException $e) {
            error_log("Mark all read error: " . $e->getMessage());
            displayFlashMessage('error', 'Unable to mark notifications as read.');
        }
        redirect('notifications.php');
    }
    
    // Delete notification
    elseif ($notif_action === 'delete') {
        $notif_id = $_POST['notification_id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$notif_id, $user_id]);
            
            echo json_encode(['success' => true]);
            exit;
            
        } catch (PDOException $e) {
            error_log("Delete notification error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    
    // Clear all notifications
    elseif ($notif_action === 'clear_all') {
        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            displayFlashMessage('success', 'All notifications cleared.');
            
        } catch (PDOException $e) {
            error_log("Clear all error: " . $e->getMessage());
            displayFlashMessage('error', 'Unable to clear notifications.');
        }
        redirect('notifications.php');
    }
}

// Handle AJAX requests for single notification actions
if (isset($_GET['ajax']) && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'mark_read' && isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
            $stmt->execute([$_GET['id'], $user_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_GET['action'] === 'get_unread_count') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            echo json_encode(['count' => $result['count']]);
        } catch (PDOException $e) {
            echo json_encode(['count' => 0]);
        }
        exit;
    }
}

// Get notifications
try {
    $sql = "SELECT * FROM notifications WHERE user_id = ?";
    $params = [$user_id];
    
    if ($filter === 'unread') {
        $sql .= " AND is_read = FALSE";
    } elseif ($filter === 'read') {
        $sql .= " AND is_read = TRUE";
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
    
    // Get unread count
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetch()['count'];
    
    // Group notifications by date
    $grouped_notifications = [];
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    foreach ($notifications as $notif) {
        $date = date('Y-m-d', strtotime($notif['created_at']));
        
        if ($date === $today) {
            $group = 'Today';
        } elseif ($date === $yesterday) {
            $group = 'Yesterday';
        } elseif (date('Y', strtotime($date)) == date('Y')) {
            $group = date('F', strtotime($date));
        } else {
            $group = date('F Y', strtotime($date));
        }
        
        $grouped_notifications[$group][] = $notif;
    }
    
} catch (PDOException $e) {
    error_log("Notifications fetch error: " . $e->getMessage());
    $notifications = [];
    $grouped_notifications = [];
    $unread_count = 0;
}

$title = 'Notifications';
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
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
        
        .notifications-header {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .notification-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.3s;
            position: relative;
        }
        
        .notification-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .notification-card.unread {
            border-left-color: var(--primary-color);
            background-color: rgba(102, 126, 234, 0.02);
        }
        
        .notification-card.read {
            border-left-color: #6c757d;
            opacity: 0.8;
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        .notification-actions {
            position: absolute;
            top: 1rem;
            right: 1rem;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .notification-card:hover .notification-actions {
            opacity: 1;
        }
        
        .date-separator {
            display: flex;
            align-items: center;
            margin: 2rem 0 1rem;
        }
        
        .date-separator hr {
            flex: 1;
            margin: 0 1rem;
            border-top: 1px dashed #dee2e6;
        }
        
        .date-separator span {
            background: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .filter-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .filter-badge:hover,
        .filter-badge.active {
            background: var(--primary-color);
            color: white;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .main-content {
                padding: 1rem;
            }
            .notification-actions {
                opacity: 1;
                position: static;
                margin-top: 0.5rem;
            }
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 15px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
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
                        
                        <a href="reports.php" class="nav-link">
                            <i class="bi bi-bar-chart"></i> Reports
                        </a>
                        
                        <a href="calendar.php" class="nav-link">
                            <i class="bi bi-calendar"></i> Calendar
                        </a>
                        
                        <a href="notifications.php" class="nav-link active">
                            <i class="bi bi-bell"></i> Notifications
                            <?php if ($unread_count > 0): ?>
                                <span class="badge bg-danger ms-2" id="sidebarUnreadCount"><?= $unread_count ?></span>
                            <?php endif; ?>
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
                            <i class="bi bi-bell me-2 text-primary"></i>
                            Notification Center
                            <?php if ($unread_count > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $unread_count ?> unread</span>
                            <?php endif; ?>
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
                    
                    <!-- Notifications Header -->
                    <div class="notifications-header">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center">
                                    <h6 class="mb-0 me-3">Filter:</h6>
                                    <div class="d-flex gap-2">
                                        <a href="notifications.php" class="filter-badge <?= $filter == 'all' ? 'active btn-primary' : 'bg-light' ?>">
                                            All
                                        </a>
                                        <a href="notifications.php?filter=unread" class="filter-badge <?= $filter == 'unread' ? 'active btn-primary' : 'bg-light' ?>">
                                            Unread <?php if ($unread_count > 0): ?><span class="badge bg-danger ms-1"><?= $unread_count ?></span><?php endif; ?>
                                        </a>
                                        <a href="notifications.php?filter=read" class="filter-badge <?= $filter == 'read' ? 'active btn-primary' : 'bg-light' ?>">
                                            Read
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                <?php if ($unread_count > 0): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="notif_action" value="mark_all_read">
                                        <button type="submit" class="btn btn-outline-primary btn-sm me-2">
                                            <i class="bi bi-check-all me-1"></i>Mark All Read
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($notifications)): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to clear all notifications? This action cannot be undone.')">
                                        <input type="hidden" name="notif_action" value="clear_all">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-trash me-1"></i>Clear All
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notifications List -->
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <i class="bi bi-bell-slash"></i>
                            <h6 class="mt-3">No Notifications</h6>
                            <p class="text-muted">You don't have any notifications at the moment.</p>
                            <?php if ($filter !== 'all'): ?>
                                <a href="notifications.php" class="btn btn-primary btn-sm mt-2">
                                    View All Notifications
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($grouped_notifications as $group => $group_notifications): ?>
                            <div class="date-separator">
                                <span><?= $group ?></span>
                                <hr>
                            </div>
                            
                            <?php foreach ($group_notifications as $notification): ?>
                                <div class="notification-card <?= $notification['is_read'] ? 'read' : 'unread' ?>" id="notification-<?= $notification['id'] ?>">
                                    <div class="d-flex">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="notification-icon bg-<?= 
                                                $notification['type'] == 'task_assigned' ? 'primary' : 
                                                ($notification['type'] == 'task_completed' ? 'success' : 
                                                ($notification['type'] == 'task_comment' ? 'info' : 
                                                ($notification['type'] == 'task_due' ? 'warning' : 
                                                ($notification['type'] == 'account_approved' ? 'success' : 
                                                ($notification['type'] == 'account_rejected' ? 'danger' : 'secondary'))))) 
                                            ?> bg-opacity-10 text-<?= 
                                                $notification['type'] == 'task_assigned' ? 'primary' : 
                                                ($notification['type'] == 'task_completed' ? 'success' : 
                                                ($notification['type'] == 'task_comment' ? 'info' : 
                                                ($notification['type'] == 'task_due' ? 'warning' : 
                                                ($notification['type'] == 'account_approved' ? 'success' : 
                                                ($notification['type'] == 'account_rejected' ? 'danger' : 'secondary'))))) 
                                            ?>">
                                                <i class="bi bi-<?= 
                                                    $notification['type'] == 'task_assigned' ? 'person-plus' : 
                                                    ($notification['type'] == 'task_completed' ? 'check-circle' : 
                                                    ($notification['type'] == 'task_comment' ? 'chat' : 
                                                    ($notification['type'] == 'task_due' ? 'exclamation-triangle' : 
                                                    ($notification['type'] == 'account_approved' ? 'check-circle' : 
                                                    ($notification['type'] == 'account_rejected' ? 'x-circle' : 'bell'))))) 
                                                ?>"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1 <?= !$notification['is_read'] ? 'fw-bold' : '' ?>">
                                                        <?= sanitize($notification['title']) ?>
                                                    </h6>
                                                    <p class="mb-1 text-muted small">
                                                        <?= sanitize($notification['message']) ?>
                                                    </p>
                                                    <div class="d-flex align-items-center">
                                                        <span class="notification-time">
                                                            <i class="bi bi-clock me-1"></i>
                                                            <?= date('h:i A', strtotime($notification['created_at'])) ?>
                                                        </span>
                                                        <?php if (!$notification['is_read']): ?>
                                                            <span class="badge bg-primary ms-2">New</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if ($notification['link']): ?>
                                                    <a href="<?= $notification['link'] ?>" class="btn btn-sm btn-outline-primary ms-3">
                                                        View
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="notification-actions">
                                        <?php if (!$notification['is_read']): ?>
                                            <button class="btn btn-sm btn-outline-secondary me-1" onclick="markAsRead(<?= $notification['id'] ?>)">
                                                <i class="bi bi-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteNotification(<?= $notification['id'] ?>)">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Real-time Notification Sound (Optional) -->
    <audio id="notificationSound" preload="auto">
        <source src="assets/sounds/notification.mp3" type="audio/mpeg">
    </audio>

    <script>
        // Mark notification as read
        function markAsRead(notificationId) {
            fetch(`notifications.php?ajax=1&action=mark_read&id=${notificationId}`, {
                method: 'GET'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const notification = document.getElementById(`notification-${notificationId}`);
                    notification.classList.remove('unread');
                    notification.classList.add('read');
                    
                    // Remove new badge if exists
                    const newBadge = notification.querySelector('.badge.bg-primary');
                    if (newBadge) newBadge.remove();
                    
                    // Update unread counts
                    updateUnreadCounts();
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // Delete notification
        function deleteNotification(notificationId) {
            Swal.fire({
                title: 'Delete Notification?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(`notifications.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `notif_action=delete&notification_id=${notificationId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const notification = document.getElementById(`notification-${notificationId}`);
                            notification.style.animation = 'fadeOut 0.3s ease';
                            setTimeout(() => {
                                notification.remove();
                                updateUnreadCounts();
                                
                                // Check if no more notifications
                                if (document.querySelectorAll('.notification-card').length === 0) {
                                    location.reload();
                                }
                            }, 300);
                            
                            Swal.fire(
                                'Deleted!',
                                'Notification has been deleted.',
                                'success'
                            );
                        }
                    })
                    .catch(error => console.error('Error:', error));
                }
            });
        }
        
        // Update unread counts in real-time
        function updateUnreadCounts() {
            fetch('notifications.php?ajax=1&action=get_unread_count')
            .then(response => response.json())
            .then(data => {
                const sidebarBadge = document.getElementById('sidebarUnreadCount');
                const headerBadge = document.querySelector('.notifications-header .badge.bg-danger');
                
                if (data.count > 0) {
                    if (sidebarBadge) {
                        sidebarBadge.textContent = data.count;
                    } else {
                        const sidebarLink = document.querySelector('.sidebar-nav a[href="notifications.php"]');
                        if (sidebarLink) {
                            const badge = document.createElement('span');
                            badge.className = 'badge bg-danger ms-2';
                            badge.id = 'sidebarUnreadCount';
                            badge.textContent = data.count;
                            sidebarLink.appendChild(badge);
                        }
                    }
                    
                    if (headerBadge) {
                        headerBadge.textContent = data.count;
                    }
                } else {
                    if (sidebarBadge) sidebarBadge.remove();
                    if (headerBadge) headerBadge.remove();
                }
            });
        }
        
        // Real-time notification checking
        let lastNotificationCount = <?= $unread_count ?>;
        
        function checkNewNotifications() {
            fetch('notifications.php?ajax=1&action=get_unread_count')
            .then(response => response.json())
            .then(data => {
                if (data.count > lastNotificationCount) {
                    // New notification arrived
                    const sound = document.getElementById('notificationSound');
                    if (sound) sound.play().catch(e => console.log('Sound play failed:', e));
                    
                    // Show toast notification
                    Swal.fire({
                        icon: 'info',
                        title: 'New Notification',
                        text: 'You have a new notification',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                }
                lastNotificationCount = data.count;
            });
        }
        
        // Check for new notifications every 10 seconds
        setInterval(checkNewNotifications, 10000);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Press 'M' to mark all as read
            if (e.key === 'm' && e.altKey) {
                e.preventDefault();
                document.querySelector('form input[name="notif_action"][value="mark_all_read"]')?.form?.submit();
            }
            
            // Press 'C' to clear all
            if (e.key === 'c' && e.altKey) {
                e.preventDefault();
                document.querySelector('form input[name="notif_action"][value="clear_all"]')?.form?.submit();
            }
        });
        
        // Mark notification as read when clicked
        document.querySelectorAll('.notification-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't trigger if clicking on button or link
                if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('button') || e.target.closest('a')) {
                    return;
                }
                
                const notificationId = this.id.replace('notification-', '');
                if (!this.classList.contains('read')) {
                    markAsRead(notificationId);
                }
                
                // Navigate to link if exists
                const link = this.querySelector('a[href]');
                if (link) {
                    window.location.href = link.href;
                }
            });
        });
        
        // Add fade animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeOut {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(20px); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
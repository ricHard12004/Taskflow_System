<?php
// sidebar.php - Global sidebar for all pages
global $user_role, $user_settings, $user, $unread_count;
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Sidebar -->
<div class="col-md-3 col-lg-2 px-0 sidebar" <?= getSidebarAttributes() ?>>
    <div class="sidebar-brand">
        <h4>TaskFlow Pro</h4>
        <p>v3.0.0</p>
    </div>
    
    <div class="sidebar-nav">
        <div class="nav flex-column">
            <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
            </a>
            
            <?php if ($user_role === 'admin'): ?>
                <a href="users.php" class="nav-link <?= $current_page == 'users.php' ? 'active' : '' ?>">
                    <i class="bi bi-people"></i> <span>User Management</span>
                </a>
                <a href="approvals.php" class="nav-link <?= $current_page == 'approvals.php' ? 'active' : '' ?>">
                    <i class="bi bi-person-check"></i> <span>Approvals</span>
                </a>
            <?php endif; ?>
            
            <?php if (in_array($user_role, ['admin', 'manager'])): ?>
                <a href="projects.php" class="nav-link <?= $current_page == 'projects.php' ? 'active' : '' ?>">
                    <i class="bi bi-kanban"></i> <span>Projects</span>
                </a>
            <?php endif; ?>
            
            <a href="tasks.php" class="nav-link <?= $current_page == 'tasks.php' ? 'active' : '' ?>">
                <i class="bi bi-check2-square"></i> <span>Tasks</span>
            </a>
            
            <a href="reports.php" class="nav-link <?= $current_page == 'reports.php' ? 'active' : '' ?>">
                <i class="bi bi-bar-chart"></i> <span>Reports</span>
            </a>
            
            <a href="calendar.php" class="nav-link <?= $current_page == 'calendar.php' ? 'active' : '' ?>">
                <i class="bi bi-calendar"></i> <span>Calendar</span>
            </a>
            
            <a href="notifications.php" class="nav-link <?= $current_page == 'notifications.php' ? 'active' : '' ?>">
                <i class="bi bi-bell"></i> <span>Notifications</span>
                <?php if (isset($unread_count) && $unread_count > 0): ?>
                    <span class="badge bg-danger ms-2" id="sidebarUnreadCount"><?= $unread_count ?></span>
                <?php endif; ?>
            </a>
            
            <a href="profile.php" class="nav-link <?= $current_page == 'profile.php' ? 'active' : '' ?>">
                <i class="bi bi-person"></i> <span>Profile</span>
            </a>
            
            <a href="settings.php" class="nav-link <?= $current_page == 'settings.php' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i> <span>Settings</span>
            </a>
            
            <a href="logout.php" class="nav-link text-danger">
                <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
            </a>
        </div>
    </div>
</div>
<?php
// header.php - Global header for all pages
if (!isset($user_settings)) {
    global $user_settings;
}

// Get current page for active navigation
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $user_settings['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?= isset($title) ? sanitize($title) . ' - ' . SITE_NAME : SITE_NAME ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <!-- Global Theme Styles -->
    <?= getThemeStyles() ?>
    
    <!-- Global Custom Styles -->
    <style>
        * {
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            min-height: 100vh;
            background: var(--sidebar-bg);
            transition: all 0.3s ease;
        }
        
        .sidebar[data-position="right"] {
            order: 2;
        }
        
        .sidebar[data-size="compact"] {
            max-width: 80px;
        }
        
        .sidebar[data-size="compact"] .sidebar-brand h4,
        .sidebar[data-size="compact"] .sidebar-brand p,
        .sidebar[data-size="compact"] .nav-link span {
            display: none;
        }
        
        .sidebar[data-size="compact"] .nav-link i {
            margin-right: 0;
            font-size: 1.2rem;
        }
        
        .sidebar[data-size="wide"] {
            max-width: 280px;
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
            color: var(--sidebar-text);
            padding: 0.8rem 1.5rem;
            margin: 0.2rem 0;
            transition: all 0.3s;
        }
        
        .sidebar-nav .nav-link:hover,
        .sidebar-nav .nav-link.active {
            color: white;
            background: var(--sidebar-hover);
            border-left: 4px solid white;
        }
        
        .sidebar-nav .nav-link i {
            margin-right: 10px;
            width: 20px;
        }
        
        .main-content {
            padding: 2rem;
            background-color: var(--bg-color);
        }
        
        .card, .settings-card, .report-card, .task-card, .project-card, .stat-card, .approval-card, .notification-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .navbar-top {
            background: var(--header-bg) !important;
            border-bottom: 1px solid var(--border-color);
        }
        
        .form-control, .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            border-color: var(--primary-color);
            color: var(--text-color);
            box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25);
        }
        
        .form-check-input {
            background-color: var(--input-bg);
            border-color: var(--input-border);
        }
        
        .text-muted {
            color: var(--text-muted) !important;
        }
        
        .border, .border-top, .border-bottom, .border-start, .border-end {
            border-color: var(--border-color) !important;
        }
        
        .table {
            color: var(--text-color);
        }
        
        .table thead th {
            border-bottom-color: var(--border-color);
        }
        
        .table td, .table th {
            border-top-color: var(--border-color);
        }
        
        .bg-white {
            background-color: var(--card-bg) !important;
        }
        
        .bg-light {
            background-color: var(--bg-color) !important;
        }
        
        .dropdown-menu {
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .dropdown-item {
            color: var(--text-color);
        }
        
        .dropdown-item:hover {
            background-color: var(--sidebar-hover);
            color: white;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .modal-header, .modal-footer {
            border-color: var(--border-color);
        }
        
        .btn-close {
            filter: <?= ($user_settings['theme'] ?? 'light') == 'dark' ? 'invert(1)' : 'none' ?>;
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
        
        .nav-tabs {
            border-bottom-color: var(--border-color);
        }
        
        .nav-tabs .nav-link {
            color: var(--text-muted);
            border: none;
        }
        
        .nav-tabs .nav-link:hover {
            border-color: transparent;
            color: var(--primary-color);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background: transparent;
            border-bottom: 2px solid var(--primary-color);
        }
        
        .list-group-item {
            background-color: var(--card-bg);
            border-color: var(--border-color);
            color: var(--text-color);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .main-content {
                padding: 1rem;
            }
            .sidebar[data-position="right"] {
                order: 0;
            }
        }
        
        /* Scrollbar styling for dark mode */
        <?php if (($user_settings['theme'] ?? 'light') == 'dark'): ?>
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: #1e1e1e;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #555;
            border-radius: 5px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #777;
        }
        <?php endif; ?>
    </style>
    
    <!-- Additional page-specific styles -->
    <?= isset($extra_styles) ? $extra_styles : '' ?>
</head>
<body>
    <div class="container-fluid">
        <div class="row"></div>
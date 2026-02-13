<?php
// terms_of_service.php
require_once 'config/database.php';
$title = 'Terms of Service';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($title) ?> - TaskFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 800px;
            margin: 0 auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>
    <div class="content-card">
        <h2 class="mb-4">Terms of Service</h2>
        <p class="text-muted">Last updated: <?= date('F d, Y') ?></p>
        
        <h5 class="mt-4">1. Acceptance of Terms</h5>
        <p>By accessing and using TaskFlow Pro, you agree to be bound by these Terms of Service.</p>
        
        <h5 class="mt-4">2. User Accounts</h5>
        <p>You are responsible for maintaining the security of your account. Account approval may be required for certain roles.</p>
        
        <h5 class="mt-4">3. Privacy</h5>
        <p>Your use of TaskFlow Pro is also governed by our Privacy Policy.</p>
        
        <div class="text-center mt-5">
            <a href="<?= base_url('register.php') ?>" class="btn btn-primary">
                <i class="bi bi-arrow-left me-2"></i>Back to Registration
            </a>
        </div>
    </div>
</body>
</html>
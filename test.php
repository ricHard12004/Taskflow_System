<?php
// test.php - Run this first to debug your URL issues
echo "<h1>🔍 TaskFlow System Debug</h1>";
echo "<hr>";

echo "<h2>Your Current Settings:</h2>";
echo "<strong>Current Script:</strong> " . $_SERVER['PHP_SELF'] . "<br>";
echo "<strong>Current Folder:</strong> " . __DIR__ . "<br>";
echo "<strong>Server Name:</strong> " . $_SERVER['SERVER_NAME'] . "<br>";
echo "<strong>Port:</strong> " . $_SERVER['SERVER_PORT'] . "<br>";
echo "<strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "<hr>";

echo "<h2>Your Correct Base URL should be:</h2>";
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script = $_SERVER['SCRIPT_NAME'];
$folder = dirname($script);

echo "<div style='background: #e8f4f8; padding: 15px; border-left: 4px solid #3498db; margin: 10px 0;'>";
echo "<strong style='font-size: 1.2em;'>➡️ http://" . $host . $folder . "/</strong>";
echo "</div>";

echo "<h2>Try these URLs:</h2>";
echo "<ul style='font-size: 1.1em; line-height: 2;'>";
echo "<li>🔗 <a href='http://" . $host . $folder . "/setup.php' target='_blank'>http://" . $host . $folder . "/setup.php</a> - RUN THIS FIRST!</li>";
echo "<li>🔗 <a href='http://" . $host . $folder . "/login.php' target='_blank'>http://" . $host . $folder . "/login.php</a> - Login Page</li>";
echo "<li>🔗 <a href='http://" . $host . $folder . "/register.php' target='_blank'>http://" . $host . $folder . "/register.php</a> - Register Page</li>";
echo "</ul>";

echo "<hr>";
echo "<h2>Quick Fix Instructions:</h2>";
echo "<ol style='background: #fff3cd; padding: 20px; border-left: 4px solid #ffc107;'>";
echo "<li><strong>Open config/database.php</strong></li>";
echo "<li><strong>Find this line:</strong> <code>define('BASE_URL', 'http://localhost/taskflow');</code></li>";
echo "<li><strong>Change it to:</strong> <code>define('BASE_URL', 'http://" . $host . $folder . "');</code></li>";
echo "<li><strong>Save the file</strong></li>";
echo "<li><strong>Run setup.php again</strong></li>";
echo "</ol>";

// Check if config file exists
echo "<hr>";
echo "<h2>File Structure Check:</h2>";
$required_files = [
    'config/database.php',
    'login.php',
    'register.php', 
    'dashboard.php',
    'setup.php'
];

foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo "✅ <strong>$file</strong> - Found<br>";
    } else {
        echo "❌ <strong>$file</strong> - MISSING!<br>";
    }
}
?>
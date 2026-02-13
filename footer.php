<?php
// footer.php - Global footer for all pages
?>
            </div>
        </div>
    </div>

    <!-- Global Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Global Theme Persistence Script -->
    <script>
        // This ensures theme persists across all pages
        document.addEventListener('DOMContentLoaded', function() {
            // Apply sidebar attributes
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.setAttribute('data-position', '<?= $user_settings['sidebar_position'] ?>');
                sidebar.setAttribute('data-size', '<?= $user_settings['sidebar_size'] ?>');
            }
            
            // Initialize all tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize all popovers
            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.map(function(popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                document.querySelectorAll('.alert').forEach(function(alert) {
                    if (alert && !alert.classList.contains('alert-permanent')) {
                        alert.classList.add('fade');
                        setTimeout(function() {
                            if (alert.parentNode) alert.remove();
                        }, 500);
                    }
                });
            }, 5000);
        });
        
        // Function to format dates according to user preferences
        function formatUserDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            const format = '<?= $user_settings['date_format'] ?>';
            const timeFormat = '<?= $user_settings['time_format'] ?>';
            
            // Basic formatting - you can enhance this
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        // Function to toggle theme (for quick theme switching)
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            // Save to database via AJAX
            fetch('ajax/update_theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'theme=' + newTheme
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        // Listen for system theme changes if in auto mode
        <?php if (($user_settings['theme'] ?? 'light') == 'auto'): ?>
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
            location.reload();
        });
        <?php endif; ?>
    </script>
    
    <!-- Page-specific scripts -->
    <?= isset($extra_scripts) ? $extra_scripts : '' ?>
</body>
</html>
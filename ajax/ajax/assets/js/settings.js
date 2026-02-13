// assets/js/settings.js
// Global settings manager

class UserSettings {
    constructor() {
        this.settings = {};
        this.loadSettings();
    }
    
    loadSettings() {
        // Load from meta tags or AJAX
        fetch('ajax/get_settings.php')
            .then(response => response.json())
            .then(data => {
                this.settings = data;
                this.applySettings();
            });
    }
    
    applySettings() {
        // Apply theme
        document.documentElement.setAttribute('data-theme', this.settings.theme);
        
        // Apply sidebar position
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.setAttribute('data-position', this.settings.sidebar_position);
            sidebar.setAttribute('data-size', this.settings.sidebar_size);
        }
        
        // Dispatch event for other scripts
        document.dispatchEvent(new CustomEvent('settingsApplied', { detail: this.settings }));
    }
    
    updateSetting(key, value) {
        this.settings[key] = value;
        
        // Save to database
        fetch('ajax/update_setting.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `${key}=${encodeURIComponent(value)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.applySettings();
            }
        });
    }
}

// Initialize global settings
const userSettings = new UserSettings();
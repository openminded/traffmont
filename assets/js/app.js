/**
 * TraffMont Frontend Application JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
    // Auto Refresh for Dashboard
    const autoRefreshToggle = document.getElementById('autoRefreshToggle');
    if (autoRefreshToggle) {
        // Load saved state
        const savedState = localStorage.getItem('traffmont_auto_refresh');
        if (savedState !== null) {
            autoRefreshToggle.checked = (savedState === 'true');
        }

        let refreshTimer = null;

        function setupTimer() {
            if (refreshTimer) clearInterval(refreshTimer);
            if (autoRefreshToggle.checked) {
                refreshTimer = setInterval(() => {
                    // Only reload if no modal is currently open to avoid UX interruption
                    const openModal = document.querySelector('.modal.show');
                    if (!openModal) {
                        window.location.reload();
                    }
                }, 30000); // 30 seconds
            }
        }

        autoRefreshToggle.addEventListener('change', function () {
            localStorage.setItem('traffmont_auto_refresh', autoRefreshToggle.checked);
            setupTimer();
        });

        setupTimer();
    }
});


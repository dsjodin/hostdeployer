<?php
/**
 * Admin Dashboard UI Components - Bootstrap Version
 * 
 * Contains all UI rendering functions for the admin dashboard
 */

// Ensure this file is included from admin_dashboard.php, not accessed directly
if (!defined('ADMIN_DASHBOARD')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

/**
 * Render the header and common CSS
 */
function renderHeader() {
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ESXi Auto-deployment Admin Dashboard</title>
    <!-- Bootstrap must load first: the whole dashboard markup uses Bootstrap 5
         classes (cards, grid, tabs, modals) but the stylesheet was never
         linked, so the layout rendered unstyled. -->
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="admin_styles.css">
    <!-- Include font-awesome icons from local path -->
    <link rel="stylesheet" href="css/all.min.css">
    <link rel="stylesheet" href="css/bootstrap-icons.css">
    <!-- Carries the template editor styles, which templates.php used to emit
         as a <style> block from inside the page. -->
    <link rel="stylesheet" href="css/admin-custom.css">
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->

<nav id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <h3>ESXi AutoDeploy</h3>
        <div class="mini-logo">EA</div>
    </div>

    <ul class="list-unstyled components">
        <li>
            <a href="?tab=dashboard" class="nav-link <?php echo !isset($_GET['tab']) || $_GET['tab'] === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li>
            <a href="?tab=hosts" class="nav-link <?php echo isset($_GET['tab']) && $_GET['tab'] === 'hosts' ? 'active' : ''; ?>">
                <i class="fas fa-server"></i>
                <span>Manage Hosts</span>
            </a>
        </li>
        <li>
            <a href="?tab=templates" class="nav-link <?php echo isset($_GET['tab']) && $_GET['tab'] === 'templates' ? 'active' : ''; ?>">
                <i class="fas fa-file-code"></i>
                <span>Templates</span>
            </a>
        </li>
        <li>
            <a href="?tab=scan" class="nav-link <?php echo isset($_GET['tab']) && $_GET['tab'] === 'scan' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i>
                <span>Hardware Scan</span>
            </a>
        </li>
        <li>
            <a href="?tab=settings" class="nav-link <?php echo isset($_GET['tab']) && $_GET['tab'] === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cogs"></i>
                <span>System Settings</span>
            </a>
        </li>
    </ul>
</nav>

        <!-- Page Content -->
        <div id="content" class="content">
            <!-- Top navigation bar -->
            <div class="content-header">
                <button type="button" id="sidebarCollapse" class="btn-toggle-sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="user-controls">
                    <span class="user-info">
                        <i class="fas fa-user-circle"></i>
                        <?php echo h($_SESSION['username'] ?? ''); ?>
                    </span>
                    <form method="post" style="display: inline;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </div>
            </div>

            <!-- Main content area -->
            <div class="content-body">
                <div class="container-fluid">
    <?php
}

/**
 * Render the footer and common JS
 */
function renderFooter() {
    ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap and jQuery JS -->
    <script src="js/jquery.min.js"></script>
   <script src="js/bootstrap.bundle.min.js"></script>
    <!-- Template editor behaviour, previously two <script> blocks emitted from
         inside renderTemplatesContent(). It guards on the elements it needs,
         so it does nothing on the other tabs. -->
    <script src="js/template-editor.js" defer></script>
    
<script>
    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize all tabs with pure JavaScript
        initTabs('hostsTab', 'hostsTabContent', 'approved-tab');
        initTabs('settingsTab', 'settingsTabContent', 'general-tab');
        
        // Function to initialize any tab interface
        function initTabs(tabsId, contentId, defaultTabId) {
            const tabsContainer = document.getElementById(tabsId);
            
            if (!tabsContainer) return; // Skip if this tab container doesn't exist on this page
            
            const tabButtons = tabsContainer.querySelectorAll('button[role="tab"]');
            const tabPanes = document.getElementById(contentId)?.querySelectorAll('.tab-pane');
            
            if (!tabPanes || !tabPanes.length) return;
            
            // Add click handlers to all tab buttons
            tabButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Get target tab content id
                    const targetId = this.getAttribute('data-bs-target');
                    
                    // Deactivate all tabs
                    tabButtons.forEach(function(btn) {
                        btn.classList.remove('active');
                        btn.setAttribute('aria-selected', 'false');
                    });
                    
                    // Activate clicked tab
                    this.classList.add('active');
                    this.setAttribute('aria-selected', 'true');
                    
                    // Hide all tab panes
                    tabPanes.forEach(function(pane) {
                        pane.classList.remove('show', 'active');
                    });
                    
                    // Show target tab pane
                    const targetPane = document.querySelector(targetId);
                    if (targetPane) {
                        targetPane.classList.add('show', 'active');
                    }
                });
            });
            
            // Check URL hash - if it matches a tab, activate that tab
            const hash = window.location.hash;
            if (hash) {
                const matchingTab = tabsContainer.querySelector(`button[data-bs-target="${hash}"]`);
                if (matchingTab) {
                    matchingTab.click();
                    return;
                }
            }
            
            // Activate default tab if no hash match
            const defaultTab = document.getElementById(defaultTabId);
            if (defaultTab) {
                defaultTab.click();
            } else if (tabButtons.length > 0) {
                // Fallback to first tab if default not found
                tabButtons[0].click();
            }
        }
        
        // Toggle vMotion fields based on deployment type
        const deploymentTypeSelect = document.getElementById('deployment_type');
        if (deploymentTypeSelect) {
            function updateVmotionVisibility() {
                const vmotionSection = document.getElementById('vmotion_config_section');
                if (vmotionSection) {
                    if (deploymentTypeSelect.value === 'vcf') {
                        vmotionSection.style.display = 'none';
                    } else {
                        vmotionSection.style.display = 'block';
                    }
                }
            }
            
            deploymentTypeSelect.addEventListener('change', updateVmotionVisibility);
            // Initial update
            updateVmotionVisibility();
        }
        
        // Handle approve modal deployment type toggle
        const approveDeploymentType = document.getElementById('approve-deployment-type');
        if (approveDeploymentType) {
            function updateApproveVmotionVisibility() {
                const approveVmotionSection = document.getElementById('approve-vmotion-section');
                if (approveVmotionSection) {
                    if (approveDeploymentType.value === 'vcf') {
                        approveVmotionSection.style.display = 'none';
                    } else {
                        approveVmotionSection.style.display = 'block';
                    }
                }
            }
            
            approveDeploymentType.addEventListener('change', updateApproveVmotionVisibility);
            // Initial update
            updateApproveVmotionVisibility();
        }
        
        // Toggle host form visibility
        const toggleHostFormBtn = document.getElementById('toggle-host-form');
        if (toggleHostFormBtn) {
            toggleHostFormBtn.addEventListener('click', function() {
                const formCard = document.getElementById('add-host-card');
                if (formCard) {
                    formCard.style.display = 'block';
                    document.getElementById('host-form-title').textContent = 'Add New Host';
                    document.getElementById('add-host-form').reset();
                    document.getElementById('mac').focus();
                }
            });
        }
        
        // Cancel button for host form
        const cancelHostFormBtn = document.getElementById('cancel-host-form');
        if (cancelHostFormBtn) {
            cancelHostFormBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const formCard = document.getElementById('add-host-card');
                if (formCard) {
                    formCard.style.display = 'none';
                }
            });
        }
        
        // Extract hostname from FQDN
        const fqdnInput = document.getElementById('fqdn');
        if (fqdnInput) {
            fqdnInput.addEventListener('input', function() {
                const fqdn = this.value.trim();
                const hostnameInput = document.getElementById('hostname');
                if (hostnameInput) {
                    if (fqdn.includes('.')) {
                        const hostname = fqdn.split('.')[0];
                        hostnameInput.value = hostname;
                    } else {
                        hostnameInput.value = fqdn;
                    }
                }
            });
        }
        
        // Confirmation for critical actions
        const confirmButtons = document.querySelectorAll('[data-confirm]');
        confirmButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                const message = this.getAttribute('data-confirm');
                if (!confirm(message)) {
                    e.preventDefault();
                }
            });
        });
        
        // Toggle sidebar
        const sidebarCollapseBtn = document.getElementById('sidebarCollapse');
        if (sidebarCollapseBtn) {
            sidebarCollapseBtn.addEventListener('click', function() {
                const sidebar = document.getElementById('sidebar');
                const content = document.getElementById('content');
                if (sidebar) sidebar.classList.toggle('collapsed');
                if (content) content.classList.toggle('expanded');
            });
        }
        
        // Log viewer functionality
        setupLogViewer();
        
        function setupLogViewer() {
            // Log file selection
            const logFileSelect = document.getElementById('log-file');
            if (logFileSelect) {
                logFileSelect.addEventListener('change', function() {
                    loadLogFile(this.value);
                });
            }
            
            // Log filter events
            const logFilterInput = document.getElementById('log-filter');
            if (logFilterInput) {
                logFilterInput.addEventListener('input', applyLogFilters);
            }
            
            const logLevelSelect = document.getElementById('log-level');
            if (logLevelSelect) {
                logLevelSelect.addEventListener('change', applyLogFilters);
            }
            
            const logLinesSelect = document.getElementById('log-lines');
            if (logLinesSelect) {
                logLinesSelect.addEventListener('change', applyLogFilters);
            }
            
            // Clear filters button
            const clearFilterBtn = document.getElementById('clear-log-filter');
            if (clearFilterBtn) {
                clearFilterBtn.addEventListener('click', function() {
                    if (logFilterInput) logFilterInput.value = '';
                    if (logLevelSelect) logLevelSelect.value = '';
                    applyLogFilters();
                });
            }
            
            // Download log button
            const downloadLogBtn = document.getElementById('download-log');
            if (downloadLogBtn) {
                downloadLogBtn.addEventListener('click', downloadCurrentLog);
            }
            
            // Auto-refresh toggle
            const autoRefreshCheck = document.getElementById('auto-refresh-logs');
            if (autoRefreshCheck) {
                autoRefreshCheck.addEventListener('change', function() {
                    toggleAutoRefresh(this.checked);
                });
            }
        }
        
        // Update recent activity on dashboard when page loads
        updateRecentActivity();
        
        // Refresh button for dashboard activity
        const refreshActivityBtn = document.getElementById('refresh-activity');
        if (refreshActivityBtn) {
            refreshActivityBtn.addEventListener('click', updateRecentActivity);
        }
    });

    // Global functions (outside DOMContentLoaded)

    // Host data now arrives through data-* attributes as JSON rather than
    // being interpolated into inline onclick handlers, so a hostname
    // containing a quote can no longer break out into script context.
    document.addEventListener('click', function (event) {
        const editButton = event.target.closest('[data-edit-host]');
        if (editButton) {
            event.preventDefault();
            editHost(JSON.parse(editButton.getAttribute('data-edit-host')));
            return;
        }

        const deleteButton = event.target.closest('[data-delete-host]');
        if (deleteButton) {
            event.preventDefault();
            const host = JSON.parse(deleteButton.getAttribute('data-delete-host'));
            confirmDeleteHost(host.mac, host.hostname);
            return;
        }

        const approveButton = event.target.closest('[data-approve-host]');
        if (approveButton) {
            event.preventDefault();
            const host = JSON.parse(approveButton.getAttribute('data-approve-host'));
            showApproveForm(host.mac, host.hostname, host.serial);
        }
    });

    // Function to show host delete confirmation
    function confirmDeleteHost(mac, hostname) {
        if (confirm(`Are you sure you want to delete host ${hostname} (${mac})?`)) {
            document.getElementById('delete-mac').value = mac;
            document.getElementById('delete-host-form').submit();
        }
    }

    // Function to edit a host
    function editHost(host) {
        const form = document.getElementById('add-host-form');
        const formCard = document.getElementById('add-host-card');
        if (!form || !formCard) return;

        const set = function (name, value) {
            const field = form.querySelector('[name="' + name + '"]');
            if (field) field.value = value;
        };

        const fqdn = host.fqdn || '';

        set('mac', host.mac || '');
        set('fqdn', fqdn);
        set('hostname', fqdn ? fqdn.split('.')[0] : '');
        set('serial', host.serial || '');
        set('ilo_ip', host.iloIp || '');
        set('management_ip', host.mgmtIp || '');
        set('management_netmask', host.mgmtNetmask || '255.255.255.0');
        set('management_gateway', host.mgmtGateway || '');
        set('vlan_mgmt', host.vlanMgmt || '0');
        set('vlan_vmotion', host.vlanVmotion || '0');
        set('vmotion_ip', host.vmotionIp || '');
        set('vmotion_netmask', host.vmotionNetmask || '255.255.255.0');
        set('deployment_type', host.deploymentType || 'standard');
        if (host.esxiVersion) set('esxi_version', host.esxiVersion);

        // Trigger change event to update field visibility
        const deploymentTypeField = form.querySelector('[name="deployment_type"]');
        if (deploymentTypeField) {
            deploymentTypeField.dispatchEvent(new Event('change'));
        }

        formCard.style.display = 'block';
        document.getElementById('host-form-title').textContent = 'Edit Host';

        const fqdnField = form.querySelector('[name="fqdn"]');
        if (fqdnField) fqdnField.focus();

        formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Function to display the approval modal for pending hosts
function showApproveForm(mac, hostname, serial) {
    // Get the modal element
    const approveModal = document.getElementById('approveModal');
    if (!approveModal) {
        console.error('Modal element not found!');
        return;
    }
    
    // Get the form elements
    const macInput = document.getElementById('approve-mac');
    const macDisplay = document.getElementById('approve-mac-display');
    const hostnameInput = document.getElementById('approve-hostname');
    const serialDisplay = document.getElementById('approve-serial');
    const fqdnInput = document.getElementById('approve-fqdn');
    const deploymentType = document.getElementById('approve-deployment-type');
    
    // Set form values
    if (macInput) macInput.value = mac;
    if (macDisplay) macDisplay.textContent = mac;
    if (hostnameInput) hostnameInput.value = hostname || ('esxi-' + mac.replace(/:/g, '').substr(-6));
    if (serialDisplay) serialDisplay.textContent = serial || 'Unknown';
    
    // Set FQDN based on hostname
    if (hostnameInput && fqdnInput) {
        fqdnInput.value = hostnameInput.value + '.local';
    }
    
    // Reset form validation
    const form = document.getElementById('approve-host-form');
    if (form) {
        form.classList.remove('was-validated');
    }
    
    // Set deployment type and trigger change event for vMotion visibility
    if (deploymentType) {
        deploymentType.value = 'standard';
        const event = new Event('change');
        deploymentType.dispatchEvent(event);
    }
    
    // Initialize and show the Bootstrap modal
    try {
        const modal = new bootstrap.Modal(approveModal);
        modal.show();
        
        // Focus the hostname field when modal is shown
        approveModal.addEventListener('shown.bs.modal', function() {
            if (hostnameInput) hostnameInput.focus();
        }, {once: true});
    } catch (error) {
        console.error('Error showing modal:', error);
        // Fallback approach if Bootstrap modal fails
        approveModal.style.display = 'block';
        approveModal.classList.add('show');
        document.body.classList.add('modal-open');
        
        // Create backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(backdrop);
    }
}
    // Enhanced function to load log file content
    function loadLogFile(file) {
        if (!file) {
            document.getElementById('log-content').textContent = '';
            return;
        }
        
        // Show loading indicator
        document.getElementById('log-content').innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><div>Loading log file...</div></div>';
        
        // Get filter parameters
        const filter = document.getElementById('log-filter')?.value || '';
        const level = document.getElementById('log-level')?.value || '';
        const lines = document.getElementById('log-lines')?.value || '100';
        
        // Create a URL with query parameters
        const url = `get_log.php?file=${encodeURIComponent(file)}&filter=${encodeURIComponent(filter)}&level=${encodeURIComponent(level)}&lines=${encodeURIComponent(lines)}`;
        
        // Fetch the log data
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(data => {
                const logContent = document.getElementById('log-content');
                renderLogLines(logContent, data.split('\n'));

                // Scroll to bottom (most recent logs)
                logContent.scrollTop = logContent.scrollHeight;
            })
            .catch(error => {
                document.getElementById('log-content').textContent = 'Error loading log file: ' + error.message;
            });
    }

    // Auto-refresh log interval
    let logRefreshInterval = null;

    // Toggle auto-refresh for logs
    function toggleAutoRefresh(enabled) {
        if (enabled) {
            // Refresh every 5 seconds
            logRefreshInterval = setInterval(function() {
                const logFileSelect = document.getElementById('log-file');
                if (logFileSelect && logFileSelect.value) {
                    loadLogFile(logFileSelect.value);
                }
            }, 5000);
            
            const refreshIndicator = document.getElementById('refresh-indicator');
            if (refreshIndicator) refreshIndicator.style.display = 'inline-block';
        } else {
            // Clear the interval
            if (logRefreshInterval) {
                clearInterval(logRefreshInterval);
                logRefreshInterval = null;
            }
            
            const refreshIndicator = document.getElementById('refresh-indicator');
            if (refreshIndicator) refreshIndicator.style.display = 'none';
        }
    }

    // Function to apply log filters
    function applyLogFilters() {
        const logFileSelect = document.getElementById('log-file');
        if (logFileSelect && logFileSelect.value) {
            loadLogFile(logFileSelect.value);
        }
    }

    // Download current log file
    function downloadCurrentLog() {
        const logFileSelect = document.getElementById('log-file');
        if (!logFileSelect || !logFileSelect.value) {
            alert('Please select a log file first');
            return;
        }
        
        window.location.href = 'get_log.php?file=' + encodeURIComponent(logFileSelect.value) + '&download=1';
    }


    // Render log lines with level highlighting without ever handing raw log
    // text to innerHTML: log files contain values supplied by PXE clients.
    function renderLogLines(container, lines) {
        container.textContent = '';
        const pattern = /(\[(?:INFO|WARNING|ERROR|DEBUG)\])|(\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/g;

        lines.forEach(function (line, index) {
            if (index > 0) {
                container.appendChild(document.createElement('br'));
            }

            let lastIndex = 0;
            let match;
            pattern.lastIndex = 0;

            while ((match = pattern.exec(line)) !== null) {
                if (match.index > lastIndex) {
                    container.appendChild(document.createTextNode(line.slice(lastIndex, match.index)));
                }

                const span = document.createElement('span');
                if (match[1]) {
                    span.className = 'log-' + match[1].slice(1, -1).toLowerCase();
                } else {
                    span.className = 'log-timestamp';
                }
                span.textContent = match[0];
                container.appendChild(span);

                lastIndex = match.index + match[0].length;
            }

            if (lastIndex < line.length) {
                container.appendChild(document.createTextNode(line.slice(lastIndex)));
            }
        });
    }

    // Update the recent activity display on the dashboard
    function updateRecentActivity() {
        const recentActivityElement = document.querySelector('.log-preview');
        if (recentActivityElement && (window.location.search.includes('tab=dashboard') || !window.location.search.includes('tab='))) {
            // Show loading indicator
            recentActivityElement.innerHTML = '<div class="text-center"><div class="spinner-border text-primary spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div><div>Loading recent activity...</div></div>';
            
            // Fetch recent logs
            fetch('get_log.php?file=admin_dashboard.log&lines=15')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(data => {
                    // Process and display the logs
                    const logLines = data.split('\n').filter(line => line.trim() !== '');
                    
                    // Take the last 15 lines and format them
                    const recentLogs = logLines.slice(-15);
                    
                    if (recentLogs.length === 0) {
                        recentActivityElement.textContent = 'No recent activity found.';
                    } else {
                        renderLogLines(recentActivityElement, recentLogs);
                    }
                })
                .catch(error => {
                    recentActivityElement.textContent = 'Error loading recent activity: ' + error.message;
                });
        }
    }
    </script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check if Modal class is available
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal === 'undefined') {
        console.warn('Bootstrap Modal class is not available. Trying to load it dynamically.');
        
        // Try to load Bootstrap JavaScript
        const script = document.createElement('script');
        script.src = 'js/bootstrap.bundle.min.js';
        script.onload = function() {
            console.log('Bootstrap loaded dynamically');
        };
        script.onerror = function() {
            console.error('Failed to load Bootstrap dynamically');
        };
        document.head.appendChild(script);
    }
    
    // Add global click handler for the modal close button
    document.addEventListener('click', function(event) {
        if (event.target.hasAttribute('data-bs-dismiss') && event.target.getAttribute('data-bs-dismiss') === 'modal') {
            const modal = event.target.closest('.modal');
            if (modal) {
                try {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) {
                        bsModal.hide();
                    } else {
                        // Fallback
                        modal.style.display = 'none';
                        modal.classList.remove('show');
                        document.body.classList.remove('modal-open');
                        
                        // Remove backdrop
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) {
                            backdrop.remove();
                        }
                    }
                } catch (e) {
                    console.error('Error closing modal:', e);
                    
                    // Fallback
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    document.body.classList.remove('modal-open');
                    
                    // Remove backdrop
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
            }
        }
    });
});
</script>
<script>
// Live progress for hosts that are installing.
//
// Polling rather than server-sent events: php-fpm holds a worker for the whole
// life of an SSE connection, so twenty operators watching twenty installs would
// exhaust the pool and take the boot chain down with it. A request every few
// seconds costs nothing by comparison.
(function () {
    'use strict';

    var INTERVAL = 3000;
    var timer = null;

    function bars() {
        return document.querySelectorAll('[data-progress-for]');
    }

    function apply(mac, state) {
        var wrapper = document.querySelector('[data-progress-for="' + mac + '"]');
        if (wrapper) {
            var bar = wrapper.querySelector('.progress-bar');
            if (bar) {
                bar.style.width = state.progress + '%';
                bar.setAttribute('aria-valuenow', state.progress);
                bar.textContent = state.progress + '%';
            }
        }

        var label = document.querySelector('[data-progress-text-for="' + mac + '"]');
        if (label) {
            label.textContent = state.text || '';
        }
    }

    function poll() {
        if (bars().length === 0) {
            // Nothing is installing; stop asking until the page is reloaded.
            clearInterval(timer);
            return;
        }

        fetch('host_status.php', { credentials: 'same-origin' })
            .then(function (response) {
                if (response.status === 401) {
                    // The session went away. Reloading lands on the login page
                    // rather than leaving a dashboard that quietly stopped
                    // updating.
                    window.location.reload();
                    return null;
                }
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data || !data.hosts) {
                    return;
                }

                var stillDeploying = false;

                bars().forEach(function (wrapper) {
                    var mac = wrapper.getAttribute('data-progress-for');
                    var state = data.hosts[mac];

                    if (state) {
                        apply(mac, state);
                        stillDeploying = true;
                    }
                });

                // A host that finished is no longer in the response; the row
                // needs re-rendering server side to show its new state.
                if (!stillDeploying) {
                    window.location.reload();
                }
            })
            .catch(function () {
                // A failed poll is not worth reporting: the next one is three
                // seconds away, and the dashboard is still usable meanwhile.
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (bars().length > 0) {
            timer = setInterval(poll, INTERVAL);
        }
    });
}());
</script>
</body>
</html>
    <?php
}

/**
 * Render alert message with Bootstrap styles
 *
 * @param string $message Message to display
 * @param string $type Alert type (success, danger, warning, info)
 */
function renderAlert($message, $type = 'success') {
    ?>
    <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php
}

/**
 * Render navigation tabs - Now handled by sidebar
 *
 * @param string $activeTab Currently active tab
 */
function renderTabsNav($activeTab) {
    // Left empty as navigation is now handled by the sidebar
}
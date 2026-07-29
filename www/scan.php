<?php
/**
 * ESXi Auto-deployment Admin - Hardware Scan Tab
 * Redesigned with Bootstrap
 */

// Ensure this file is included from admin_dashboard.php, not accessed directly
if (!defined('ADMIN_DASHBOARD')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

/**
 * Render the Hardware Scan Tab
 * 
 * @param array $globalConfig Global configuration
 * @param string $scanOutput Output from the iLO scanner
 */
function renderScanContent($globalConfig, $scanOutput) {
    ?>
    <div id="scan-content">
        <h2 class="mb-4">Hardware Discovery</h2>
        
        <div class="row">
            <!-- iLO Network Scan Card -->
            <div class="col-lg-7 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-search me-2"></i>iLO Network Scan
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">Scan the iLO network to discover HPE servers and retrieve their MAC addresses and serial numbers.</p>
                        
                        <div class="card bg-light mb-4">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="d-flex">
                                            <div class="fw-bold me-2">iLO IP Range:</div>
                                            <div><?php echo htmlspecialchars($globalConfig['ilo']['scan_range_start']); ?> - <?php echo htmlspecialchars($globalConfig['ilo']['scan_range_end']); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex">
                                            <div class="fw-bold me-2">Credentials:</div>
                                            <div><?php echo htmlspecialchars($globalConfig['ilo']['admin_user']); ?> / ********</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <form method="post" class="mb-4">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="scan_ilo">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search me-2"></i>Start iLO Scan
                            </button>
                        </form>
                        
                        <?php if ($scanOutput): ?>
                        <h6 class="border-bottom pb-2 mb-3">Scan Results:</h6>
                        <div class="scan-output bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 0.85rem;">
                            <?php echo nl2br(htmlspecialchars($scanOutput)); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Manual MAC Registration Card -->
            <div class="col-lg-5 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-plus-circle me-2"></i>Manual MAC Registration
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">If you know the MAC address of a server you want to deploy, you can manually register it here.</p>
                        
                        <form method="post" class="needs-validation" novalidate>
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="add_host">
                            <input type="hidden" name="fqdn" id="fqdn-manual" value="">

                            <div class="mb-3">
                                <label for="mac-manual" class="form-label required-label">MAC Address</label>
                                <input type="text" class="form-control" id="mac-manual" name="mac" required placeholder="00:11:22:33:44:55">
                                <div class="invalid-feedback">Please provide a valid MAC address.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="hostname-manual" class="form-label required-label">Hostname</label>
                                <input type="text" class="form-control" id="hostname-manual" name="hostname" required>
                                <div class="invalid-feedback">Please provide a hostname.</div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label for="management_ip-manual" class="form-label required-label">Management IP</label>
                                    <input type="text" class="form-control" id="management_ip-manual" name="management_ip" required>
                                    <div class="invalid-feedback">Please provide a valid IP address.</div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="management_netmask-manual" class="form-label required-label">Netmask</label>
                                    <input type="text" class="form-control" id="management_netmask-manual" name="management_netmask" value="255.255.255.0" required>
                                    <div class="invalid-feedback">Please provide a netmask.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="management_gateway-manual" class="form-label required-label">Gateway</label>
                                    <input type="text" class="form-control" id="management_gateway-manual" name="management_gateway" required>
                                    <div class="invalid-feedback">Please provide a gateway.</div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-plus-circle me-2"></i>Register Host
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recently Discovered Hosts (only shown if scan has been performed) -->
        <?php if ($scanOutput): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="bi bi-hdd-rack me-2"></i>Recently Discovered Hosts
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead>
                            <tr>
                                <th>MAC Address</th>
                                <th>Serial Number</th>
                                <th>Model</th>
                                <th>iLO IP</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- This would be populated dynamically from scan results if available -->
                            <tr>
                                <td colspan="5" class="text-center p-3">
                                    Use the <strong>iLO Network Scan</strong> feature to discover servers and populate this list.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Form validation script -->
    <script>
    (function () {
        'use strict';

        // Bootstrap-style client side validation.
        document.querySelectorAll('.needs-validation').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });

        // processAddHostAction() requires an FQDN; derive one from the
        // hostname so the manual registration form does not fail validation
        // server side.
        var hostnameInput = document.getElementById('hostname-manual');
        var fqdnInput = document.getElementById('fqdn-manual');
        if (hostnameInput && fqdnInput) {
            var syncFqdn = function () {
                var value = hostnameInput.value.trim();
                fqdnInput.value = (value === '' || value.indexOf('.') !== -1) ? value : value + '.local';
            };
            hostnameInput.addEventListener('input', syncFqdn);
            syncFqdn();
        }
    })();
    </script>
    <?php
}

/**
 * Process actions submitted from the Hardware Scan tab.
 *
 * This handler was referenced by the dashboard router but never defined, so
 * both buttons on this tab produced a fatal "undefined function" error.
 *
 * @param string $action   Requested action
 * @param array  $postData POST data
 * @return array{message: string, error: string, scanOutput: string}
 */
function processScanActions($action, $postData) {
    $result = ['message' => '', 'error' => '', 'scanOutput' => ''];

    switch ($action) {
        case 'scan_ilo':
            logMessage('Starting iLO network scan');
            $scan = runIloScanner();

            $result['scanOutput'] = $scan['output'];

            if ($scan['success']) {
                $result['message'] = 'iLO scan completed. Discovered servers appear under Manage Hosts > Pending.';
            } else {
                $result['error'] = 'The iLO scan failed. See the scan output and the ilo_scanner log for details.';
            }
            break;

        case 'add_host':
            // The manual registration form lives on this tab but is handled
            // by the shared host editor logic.
            $hostResult = processAddHostAction($postData);
            $result['message'] = $hostResult['message'];
            $result['error'] = $hostResult['error'];
            break;
    }

    return $result;
}
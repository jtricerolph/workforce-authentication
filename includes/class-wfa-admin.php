<?php
/**
 * Admin pages and setup wizard.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class WFA_Admin {

    private $api;
    private $sync;

    public function __construct($api, $sync) {
        $this->api = $api;
        $this->sync = $sync;

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX handlers
        add_action('wp_ajax_wfa_get_token', array($this, 'ajax_get_token'));
        add_action('wp_ajax_wfa_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_wfa_get_locations', array($this, 'ajax_get_locations'));
        add_action('wp_ajax_wfa_save_locations', array($this, 'ajax_save_locations'));
        add_action('wp_ajax_wfa_sync_departments', array($this, 'ajax_sync_departments'));
        add_action('wp_ajax_wfa_get_team_users', array($this, 'ajax_get_team_users'));
        add_action('wp_ajax_wfa_save_auto_sync', array($this, 'ajax_save_auto_sync'));
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_menu_page(
            'Workforce Auth',
            'Workforce Auth',
            'manage_options',
            'workforce-auth',
            array($this, 'render_setup_page'),
            'dashicons-groups',
            30
        );

        add_submenu_page(
            'workforce-auth',
            'Setup',
            'Setup',
            'manage_options',
            'workforce-auth',
            array($this, 'render_setup_page')
        );

        add_submenu_page(
            'workforce-auth',
            'Teams',
            'Teams',
            'manage_options',
            'workforce-auth-teams',
            array($this, 'render_teams_page')
        );
    }

    /**
     * Enqueue admin scripts.
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'workforce-auth') === false) {
            return;
        }

        wp_enqueue_style('wfa-admin', WFA_PLUGIN_URL . 'assets/admin.css', array(), WFA_VERSION);
        wp_enqueue_script('wfa-admin', WFA_PLUGIN_URL . 'assets/admin.js', array('jquery'), WFA_VERSION, true);

        wp_localize_script('wfa-admin', 'wfaAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wfa_admin_nonce'),
        ));
    }

    /**
     * Render setup page.
     */
    public function render_setup_page() {
        $setup_complete = get_option('wfa_setup_complete', false);
        $has_token = !empty(get_option('wfa_access_token'));
        $has_locations = !empty(get_option('wfa_selected_locations'));

        ?>
        <div class="wrap wfa-setup-page">
            <h1>Workforce Authentication Setup</h1>

            <?php if ($setup_complete): ?>
                <div class="notice notice-success">
                    <p>Setup complete! Your Workforce integration is configured.</p>
                </div>
                <?php $this->render_settings_overview(); ?>
            <?php else: ?>
                <div class="wfa-setup-wizard">
                    <?php $this->render_setup_steps($has_token, $has_locations); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render setup steps.
     */
    private function render_setup_steps($has_token, $has_locations) {
        ?>
        <div class="wfa-steps">
            <div class="wfa-step <?php echo !$has_token ? 'active' : 'completed'; ?>">
                <span class="step-number">1</span>
                <span class="step-title">API Token</span>
            </div>
            <div class="wfa-step <?php echo $has_token && !$has_locations ? 'active' : ($has_locations ? 'completed' : ''); ?>">
                <span class="step-number">2</span>
                <span class="step-title">Select Locations</span>
            </div>
            <div class="wfa-step <?php echo $has_locations ? 'active' : ''; ?>">
                <span class="step-number">3</span>
                <span class="step-title">Sync Departments</span>
            </div>
        </div>

        <?php if (!$has_token): ?>
            <?php $this->render_token_setup(); ?>
        <?php elseif (!$has_locations): ?>
            <?php $this->render_location_selection(); ?>
        <?php else: ?>
            <?php $this->render_department_sync(); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Render token setup form.
     */
    private function render_token_setup() {
        $scopes = array(
            'me' => 'Access information about the current user',
            'roster' => 'Manage roster and schedule information',
            'timesheet' => 'Manage timesheet and shift information',
            'department' => 'Manage location and department information',
            'user' => 'Manage employee personal information',
            'cost' => 'Access wage and cost information',
            'leave' => 'Manage leave requests and balances',
            'unavailability' => 'Manage unavailability',
            'datastream' => 'Manage data streams and store stats',
            'device' => 'Manage timeclock information',
            'qualifications' => 'Manage qualifications',
            'settings' => 'Manage account settings',
            'sms' => 'Send SMS',
            'personal' => 'Manage personal details',
            'financial' => 'Access financial data',
            'platform' => 'Get platform data',
        );

        $default_scopes = array('me', 'user', 'department');

        ?>
        <div class="wfa-setup-section">
            <h2>Step 1: Get API Access Token</h2>
            <p>Enter your Workforce credentials to generate an access token.</p>

            <form id="wfa-token-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wfa_email">Email</label></th>
                        <td>
                            <input type="email" id="wfa_email" name="email" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wfa_password">Password</label></th>
                        <td>
                            <input type="password" id="wfa_password" name="password" class="regular-text" required>
                            <p class="description">Your password is used only to request the token and is not stored.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Scopes</th>
                        <td>
                            <fieldset>
                                <?php foreach ($scopes as $scope => $description): ?>
                                    <label>
                                        <input type="checkbox" name="scopes[]" value="<?php echo esc_attr($scope); ?>"
                                            <?php checked(in_array($scope, $default_scopes)); ?>>
                                        <strong><?php echo esc_html($scope); ?></strong> - <?php echo esc_html($description); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">Get Access Token</button>
                    <span class="spinner"></span>
                </p>

                <div id="wfa-token-result"></div>
            </form>
        </div>
        <?php
    }

    /**
     * Render location selection.
     */
    private function render_location_selection() {
        ?>
        <div class="wfa-setup-section">
            <h2>Step 2: Select Locations</h2>
            <p>Choose which locations you want to include in this integration.</p>

            <div id="wfa-locations-loading">
                <p>Loading locations... <span class="spinner is-active"></span></p>
            </div>

            <div id="wfa-locations-list" style="display: none;">
                <form id="wfa-locations-form">
                    <div id="wfa-locations-container"></div>

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">Save Locations &amp; Continue</button>
                        <span class="spinner"></span>
                    </p>
                </form>
            </div>

            <div id="wfa-locations-result"></div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Auto-load locations
            $.ajax({
                url: wfaAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wfa_get_locations',
                    nonce: wfaAdmin.nonce
                },
                success: function(response) {
                    $('#wfa-locations-loading').hide();
                    if (response.success) {
                        var html = '';
                        $.each(response.data, function(i, location) {
                            html += '<label style="display: block; margin: 10px 0;">';
                            html += '<input type="checkbox" name="locations[]" value="' + location.id + '"> ';
                            html += '<strong>' + location.name + '</strong>';
                            if (location.address) {
                                html += ' - ' + location.address;
                            }
                            html += '</label>';
                        });
                        $('#wfa-locations-container').html(html);
                        $('#wfa-locations-list').show();
                    } else {
                        $('#wfa-locations-result').html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $('#wfa-locations-loading').hide();
                    $('#wfa-locations-result').html('<div class="notice notice-error"><p>Failed to load locations</p></div>');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render department sync.
     */
    private function render_department_sync() {
        $selected_locations = get_option('wfa_selected_locations', array());
        $last_sync = get_option('wfa_last_sync', '');

        ?>
        <div class="wfa-setup-section">
            <h2>Step 3: Sync Departments</h2>
            <p>Sync department and staff information from your selected locations.</p>

            <table class="form-table">
                <tr>
                    <th scope="row">Selected Locations</th>
                    <td><?php echo count($selected_locations); ?> location(s)</td>
                </tr>
                <?php if ($last_sync): ?>
                <tr>
                    <th scope="row">Last Sync</th>
                    <td><?php echo esc_html($last_sync); ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <p class="submit">
                <button type="button" id="wfa-sync-departments" class="button button-primary button-large">Sync Departments Now</button>
                <span class="spinner"></span>
            </p>

            <div id="wfa-sync-result"></div>
        </div>
        <?php
    }

    /**
     * Render settings overview (after setup complete).
     */
    private function render_settings_overview() {
        $token = get_option('wfa_access_token');
        $scopes = get_option('wfa_token_scopes', array());
        $locations = get_option('wfa_selected_locations', array());
        $last_sync = get_option('wfa_last_sync', 'Never');
        $auto_sync_enabled = get_option('wfa_auto_sync_enabled', false);
        $auto_sync_frequency = get_option('wfa_auto_sync_frequency', 'daily');

        ?>
        <div class="wfa-settings-overview">
            <h2>Current Configuration</h2>

            <table class="form-table">
                <tr>
                    <th scope="row">API Token</th>
                    <td>
                        <code><?php echo substr($token, 0, 20); ?>...</code>
                        <button type="button" class="button" id="wfa-test-token">Test Connection</button>
                        <span class="spinner"></span>
                        <div id="wfa-test-result"></div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Scopes</th>
                    <td><?php echo implode(', ', $scopes); ?></td>
                </tr>
                <tr>
                    <th scope="row">Selected Locations</th>
                    <td><?php echo count($locations); ?> location(s)</td>
                </tr>
                <tr>
                    <th scope="row">Last Sync</th>
                    <td><?php echo esc_html($last_sync); ?></td>
                </tr>
            </table>

            <h3 style="margin-top: 30px;">Auto-Sync Settings</h3>
            <form id="wfa-auto-sync-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Auto-Sync</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_sync_enabled" id="wfa-auto-sync-enabled" value="1" <?php checked($auto_sync_enabled, true); ?>>
                                Automatically sync departments on a schedule
                            </label>
                        </td>
                    </tr>
                    <tr id="wfa-sync-frequency-row" style="<?php echo $auto_sync_enabled ? '' : 'display: none;'; ?>">
                        <th scope="row">Sync Frequency</th>
                        <td>
                            <select name="auto_sync_frequency" id="wfa-auto-sync-frequency">
                                <option value="hourly" <?php selected($auto_sync_frequency, 'hourly'); ?>>Hourly</option>
                                <option value="twicedaily" <?php selected($auto_sync_frequency, 'twicedaily'); ?>>Twice Daily</option>
                                <option value="daily" <?php selected($auto_sync_frequency, 'daily'); ?>>Daily</option>
                                <option value="weekly" <?php selected($auto_sync_frequency, 'weekly'); ?>>Weekly</option>
                            </select>
                            <p class="description">How often to automatically sync departments from Workforce</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Auto-Sync Settings</button>
                    <span class="spinner"></span>
                </p>
                <div id="wfa-auto-sync-result"></div>
            </form>

            <h3 style="margin-top: 30px;">Manual Actions</h3>
            <p>
                <button type="button" id="wfa-resync-departments" class="button button-secondary">Re-sync Departments Now</button>
                <button type="button" id="wfa-reset-setup" class="button button-link-delete">Reset Setup</button>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Toggle frequency field based on checkbox
            $('#wfa-auto-sync-enabled').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#wfa-sync-frequency-row').show();
                } else {
                    $('#wfa-sync-frequency-row').hide();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Get token.
     */
    public function ajax_get_token() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $scopes = $_POST['scopes'] ?? array();

        if (empty($email) || empty($password)) {
            wp_send_json_error('Email and password are required');
        }

        $result = $this->api->get_token($email, $password, $scopes);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Save token
        update_option('wfa_access_token', $result['access_token']);
        update_option('wfa_token_scopes', $scopes);
        update_option('wfa_token_created', current_time('mysql'));

        // Reload token in API instance
        $this->api->reload_token();

        wp_send_json_success(array(
            'message' => 'Token retrieved successfully',
            'token' => substr($result['access_token'], 0, 20) . '...',
        ));
    }

    /**
     * AJAX: Test connection.
     */
    public function ajax_test_connection() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $result = $this->api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('Connection successful!');
    }

    /**
     * AJAX: Get locations.
     */
    public function ajax_get_locations() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $locations = $this->api->get_locations();

        if (is_wp_error($locations)) {
            wp_send_json_error($locations->get_error_message());
        }

        wp_send_json_success($locations);
    }

    /**
     * AJAX: Save locations.
     */
    public function ajax_save_locations() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $locations = $_POST['locations'] ?? array();

        if (empty($locations)) {
            wp_send_json_error('Please select at least one location');
        }

        $locations = array_map('intval', $locations);
        update_option('wfa_selected_locations', $locations);

        wp_send_json_success('Locations saved successfully');
    }

    /**
     * AJAX: Sync departments.
     */
    public function ajax_sync_departments() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $result = $this->sync->sync_departments();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Mark setup as complete
        update_option('wfa_setup_complete', true);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Get team users.
     */
    public function ajax_get_team_users() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $dept_id = intval($_POST['dept_id'] ?? 0);

        if (!$dept_id) {
            wp_send_json_error('Invalid department ID');
        }

        $users = $this->sync->get_department_users($dept_id);

        wp_send_json_success($users);
    }

    /**
     * AJAX: Save auto-sync settings.
     */
    public function ajax_save_auto_sync() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'daily');

        // Validate frequency
        $valid_frequencies = array('hourly', 'twicedaily', 'daily', 'weekly');
        if (!in_array($frequency, $valid_frequencies)) {
            $frequency = 'daily';
        }

        // Save settings
        update_option('wfa_auto_sync_enabled', $enabled);
        update_option('wfa_auto_sync_frequency', $frequency);

        // Clear existing cron
        $timestamp = wp_next_scheduled('wfa_scheduled_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wfa_scheduled_sync');
        }

        // Schedule new cron if enabled
        if ($enabled) {
            wp_schedule_event(time(), $frequency, 'wfa_scheduled_sync');
            $message = 'Auto-sync enabled. Departments will sync ' . $frequency . '.';
        } else {
            $message = 'Auto-sync disabled.';
        }

        wp_send_json_success($message);
    }

    /**
     * Render teams page.
     */
    public function render_teams_page() {
        $departments = $this->sync->get_departments();
        $selected_locations = get_option('wfa_selected_locations', array());
        $last_sync = get_option('wfa_last_sync', 'Never');

        ?>
        <div class="wrap">
            <h1>Synced Teams/Departments</h1>

            <div class="wfa-teams-header" style="background: #fff; padding: 15px 20px; border: 1px solid #ddd; border-radius: 4px; margin: 20px 0;">
                <p>
                    <strong>Selected Locations:</strong> <?php echo count($selected_locations); ?> location(s) |
                    <strong>Last Sync:</strong> <?php echo esc_html($last_sync); ?>
                </p>
                <p>
                    <button type="button" id="wfa-resync-departments" class="button button-secondary">Re-sync Departments</button>
                    <span class="spinner"></span>
                    <span id="wfa-sync-result"></span>
                </p>
            </div>

            <?php if (empty($departments)): ?>
                <div class="notice notice-warning">
                    <p>No teams/departments synced yet. Please complete the setup or click "Re-sync Departments" above.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 50px;">ID</th>
                            <th scope="col">Team Name</th>
                            <th scope="col" style="width: 100px;">Location ID</th>
                            <th scope="col" style="width: 100px;">Colour</th>
                            <th scope="col" style="width: 120px;">Staff Count</th>
                            <th scope="col" style="width: 120px;">Managers</th>
                            <th scope="col" style="width: 180px;">Last Synced</th>
                            <th scope="col" style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $dept):
                            $users = $this->sync->get_department_users($dept->id);
                            $staff_count = count($users);
                            $managers_count = count(array_filter($users, function($u) { return $u->is_manager == 1; }));
                        ?>
                            <tr>
                                <td><?php echo esc_html($dept->workforce_id); ?></td>
                                <td>
                                    <strong><?php echo esc_html($dept->name); ?></strong>
                                    <?php if ($dept->export_name): ?>
                                        <br><small>Export: <?php echo esc_html($dept->export_name); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($dept->location_id); ?></td>
                                <td>
                                    <?php if ($dept->colour): ?>
                                        <span style="display: inline-block; width: 20px; height: 20px; background: <?php echo esc_attr($dept->colour); ?>; border: 1px solid #ddd; border-radius: 3px; vertical-align: middle;"></span>
                                        <code style="margin-left: 5px;"><?php echo esc_html($dept->colour); ?></code>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $staff_count; ?></td>
                                <td><?php echo $managers_count; ?></td>
                                <td><?php echo $dept->last_synced ? esc_html($dept->last_synced) : '<span style="color: #999;">—</span>'; ?></td>
                                <td>
                                    <button type="button" class="button button-small wfa-view-team-users" data-dept-id="<?php echo esc_attr($dept->id); ?>" data-dept-name="<?php echo esc_attr($dept->name); ?>">
                                        View Staff
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div id="wfa-team-users-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; justify-content: center; align-items: center;">
                    <div style="background: #fff; padding: 30px; border-radius: 4px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                        <h2 id="wfa-modal-title">Team Staff</h2>
                        <div id="wfa-modal-content"></div>
                        <p style="text-align: right; margin-top: 20px;">
                            <button type="button" class="button button-primary wfa-close-modal">Close</button>
                        </p>
                    </div>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    $('.wfa-view-team-users').on('click', function() {
                        var deptId = $(this).data('dept-id');
                        var deptName = $(this).data('dept-name');

                        $('#wfa-modal-title').text('Staff in "' + deptName + '"');
                        $('#wfa-modal-content').html('<p>Loading... <span class="spinner is-active"></span></p>');
                        $('#wfa-team-users-modal').css('display', 'flex');

                        $.ajax({
                            url: wfaAdmin.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'wfa_get_team_users',
                                nonce: wfaAdmin.nonce,
                                dept_id: deptId
                            },
                            success: function(response) {
                                if (response.success) {
                                    var html = '<table class="wp-list-table widefat fixed striped"><thead><tr><th>User ID</th><th>Role</th></tr></thead><tbody>';
                                    $.each(response.data, function(i, user) {
                                        html += '<tr><td>' + user.workforce_user_id + '</td><td>' + (user.is_manager == 1 ? '<strong>Manager</strong>' : 'Staff') + '</td></tr>';
                                    });
                                    html += '</tbody></table>';
                                    $('#wfa-modal-content').html(html);
                                } else {
                                    $('#wfa-modal-content').html('<p style="color: red;">Error: ' + response.data + '</p>');
                                }
                            },
                            error: function() {
                                $('#wfa-modal-content').html('<p style="color: red;">Failed to load staff.</p>');
                            }
                        });
                    });

                    $('.wfa-close-modal, #wfa-team-users-modal').on('click', function(e) {
                        if (e.target === this) {
                            $('#wfa-team-users-modal').hide();
                        }
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}

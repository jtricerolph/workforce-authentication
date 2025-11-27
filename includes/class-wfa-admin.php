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
        add_action('wp_ajax_wfa_save_registration_settings', array($this, 'ajax_save_registration_settings'));
        add_action('wp_ajax_wfa_approve_registration', array($this, 'ajax_approve_registration'));
        add_action('wp_ajax_wfa_reject_registration', array($this, 'ajax_reject_registration'));
        add_action('wp_ajax_wfa_get_department_permissions', array($this, 'ajax_get_department_permissions'));
        add_action('wp_ajax_wfa_grant_permission', array($this, 'ajax_grant_permission'));
        add_action('wp_ajax_wfa_revoke_permission', array($this, 'ajax_revoke_permission'));
        add_action('wp_ajax_wfa_unlink_user', array($this, 'ajax_unlink_user'));
        add_action('wp_ajax_wfa_delete_user', array($this, 'ajax_delete_user'));
        add_action('wp_ajax_wfa_deactivate_user', array($this, 'ajax_deactivate_user'));
        add_action('wp_ajax_wfa_resync_user', array($this, 'ajax_resync_user'));
        add_action('wp_ajax_wfa_get_user_permissions', array($this, 'ajax_get_user_permissions'));
        add_action('wp_ajax_wfa_grant_user_permission', array($this, 'ajax_grant_user_permission'));
        add_action('wp_ajax_wfa_revoke_user_permission', array($this, 'ajax_revoke_user_permission'));
        add_action('wp_ajax_wfa_get_selected_locations', array($this, 'ajax_get_selected_locations'));
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

        add_submenu_page(
            'workforce-auth',
            'Registration Settings',
            'Registration',
            'manage_options',
            'workforce-auth-registration',
            array($this, 'render_registration_page')
        );

        // Conditional pending registrations page
        $pending_count = $this->get_pending_registrations_count();
        $pending_menu_title = $pending_count > 0 ? "Pending ($pending_count)" : 'Pending';

        add_submenu_page(
            'workforce-auth',
            'Pending Registrations',
            $pending_menu_title,
            'manage_options',
            'workforce-auth-registrations',
            array($this, 'render_pending_registrations_page')
        );

        add_submenu_page(
            'workforce-auth',
            'Registered Users',
            'Registered Users',
            'manage_options',
            'workforce-auth-users',
            array($this, 'render_users_page')
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
     * Enqueue frontend registration scripts.
     */
    public function enqueue_registration_scripts() {
        wp_enqueue_style('wfa-registration', WFA_PLUGIN_URL . 'assets/registration.css', array(), WFA_VERSION);
        wp_enqueue_script('wfa-registration', WFA_PLUGIN_URL . 'assets/registration.js', array('jquery'), WFA_VERSION, true);

        wp_localize_script('wfa-registration', 'wfaRegistration', array(
            'ajax_url' => admin_url('admin-ajax.php'),
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
                    <td>
                        <div id="wfa-current-locations">
                            <?php echo count($locations); ?> location(s) selected
                        </div>
                        <button type="button" class="button" id="wfa-update-locations" style="margin-top: 10px;">Update Locations</button>
                    </td>
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

        <!-- Update Locations Modal -->
        <div id="wfa-locations-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; justify-content: center; align-items: center;">
            <div style="background: #fff; padding: 30px; border-radius: 4px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                <h2>Update Location Selection</h2>
                <p>Select which locations to include in this integration:</p>

                <div id="wfa-locations-loading" style="margin: 20px 0;">
                    <p>Loading locations... <span class="spinner is-active" style="float: none;"></span></p>
                </div>

                <form id="wfa-update-locations-form" style="display: none;">
                    <div id="wfa-update-locations-container" style="max-height: 400px; overflow-y: auto; margin: 20px 0;"></div>

                    <div id="wfa-update-locations-result"></div>

                    <p style="text-align: right; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                        <button type="button" class="button wfa-close-locations-modal" style="margin-right: 10px;">Cancel</button>
                        <button type="submit" class="button button-primary">Save Locations</button>
                        <span class="spinner"></span>
                    </p>
                </form>
            </div>
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

            // Update locations button
            $('#wfa-update-locations').on('click', function() {
                $('#wfa-locations-modal').css('display', 'flex');
                $('#wfa-locations-loading').show();
                $('#wfa-update-locations-form').hide();
                $('#wfa-update-locations-result').html('');

                // Fetch locations from API
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
                            // Get currently selected locations
                            $.ajax({
                                url: wfaAdmin.ajax_url,
                                type: 'POST',
                                data: {
                                    action: 'wfa_get_selected_locations',
                                    nonce: wfaAdmin.nonce
                                },
                                success: function(selectedResponse) {
                                    var selectedIds = selectedResponse.success ? selectedResponse.data : [];
                                    var html = '';

                                    $.each(response.data, function(i, location) {
                                        var isChecked = selectedIds.indexOf(location.id) !== -1 ? ' checked' : '';
                                        html += '<label style="display: block; padding: 10px; margin-bottom: 5px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px; cursor: pointer;">';
                                        html += '<input type="checkbox" name="locations[]" value="' + location.id + '"' + isChecked + '> ';
                                        html += '<strong>' + location.name + '</strong>';
                                        if (location.address) {
                                            html += '<br><small style="color: #666; margin-left: 20px;">' + location.address + '</small>';
                                        }
                                        html += '</label>';
                                    });

                                    $('#wfa-update-locations-container').html(html);
                                    $('#wfa-update-locations-form').show();
                                }
                            });
                        } else {
                            $('#wfa-update-locations-result').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                            $('#wfa-update-locations-form').show();
                        }
                    },
                    error: function() {
                        $('#wfa-locations-loading').hide();
                        $('#wfa-update-locations-result').html('<div class="notice notice-error inline"><p>Failed to load locations</p></div>');
                        $('#wfa-update-locations-form').show();
                    }
                });
            });

            // Save updated locations
            $('#wfa-update-locations-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $form.find('button[type="submit"]');
                var $spinner = $form.find('.spinner');
                var locations = [];

                $form.find('input[name="locations[]"]:checked').each(function() {
                    locations.push($(this).val());
                });

                if (locations.length === 0) {
                    $('#wfa-update-locations-result').html('<div class="notice notice-error inline"><p>Please select at least one location</p></div>');
                    return;
                }

                $button.prop('disabled', true);
                $spinner.addClass('is-active');

                $.ajax({
                    url: wfaAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wfa_save_locations',
                        nonce: wfaAdmin.nonce,
                        locations: locations
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#wfa-update-locations-result').html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');

                            // Update the display
                            $('#wfa-current-locations').html(locations.length + ' location(s) selected');

                            // Close modal after 1.5 seconds
                            setTimeout(function() {
                                $('#wfa-locations-modal').hide();

                                // Show message to re-sync
                                if (confirm('Locations updated! Would you like to re-sync departments now?')) {
                                    $('#wfa-resync-departments').trigger('click');
                                }
                            }, 1500);
                        } else {
                            $('#wfa-update-locations-result').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#wfa-update-locations-result').html('<div class="notice notice-error inline"><p>Failed to save locations</p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });

            // Close modal
            $('.wfa-close-locations-modal, #wfa-locations-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#wfa-locations-modal').hide();
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
                                    <button type="button" class="button button-small wfa-manage-permissions" data-dept-id="<?php echo esc_attr($dept->id); ?>" data-dept-name="<?php echo esc_attr($dept->name); ?>">
                                        Permissions
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

                <div id="wfa-permissions-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; justify-content: center; align-items: center;">
                    <div style="background: #fff; padding: 30px; border-radius: 4px; max-width: 700px; width: 90%; max-height: 80vh; overflow-y: auto;">
                        <h2 id="wfa-permissions-title">Manage Permissions</h2>
                        <p id="wfa-permissions-description">Select which permissions this department should have. Users in this department will inherit these permissions.</p>
                        <div id="wfa-permissions-content"></div>
                        <p style="text-align: right; margin-top: 20px;">
                            <button type="button" class="button button-primary wfa-close-permissions-modal">Close</button>
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

                    // Permissions modal handlers
                    var currentDeptId = null;

                    $('.wfa-manage-permissions').on('click', function() {
                        currentDeptId = $(this).data('dept-id');
                        var deptName = $(this).data('dept-name');

                        $('#wfa-permissions-title').text('Permissions for "' + deptName + '"');
                        $('#wfa-permissions-content').html('<p>Loading... <span class="spinner is-active"></span></p>');
                        $('#wfa-permissions-modal').css('display', 'flex');

                        $.ajax({
                            url: wfaAdmin.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'wfa_get_department_permissions',
                                nonce: wfaAdmin.nonce,
                                dept_id: currentDeptId
                            },
                            success: function(response) {
                                if (response.success) {
                                    renderPermissions(response.data.all_permissions, response.data.granted_permissions);
                                } else {
                                    $('#wfa-permissions-content').html('<p style="color: red;">Error: ' + response.data + '</p>');
                                }
                            },
                            error: function() {
                                $('#wfa-permissions-content').html('<p style="color: red;">Failed to load permissions.</p>');
                            }
                        });
                    });

                    function renderPermissions(allPermissions, grantedPermissions) {
                        if (allPermissions.length === 0) {
                            $('#wfa-permissions-content').html('<p style="color: #666; font-style: italic;">No permissions available. Apps can register permissions using the <code>wfa_register_permissions</code> action hook.</p>');
                            return;
                        }

                        var html = '<div style="max-height: 400px; overflow-y: auto;">';
                        var groupedByApp = {};

                        // Group permissions by app
                        $.each(allPermissions, function(i, perm) {
                            if (!groupedByApp[perm.app_name]) {
                                groupedByApp[perm.app_name] = [];
                            }
                            groupedByApp[perm.app_name].push(perm);
                        });

                        // Render grouped permissions
                        $.each(groupedByApp, function(appName, permissions) {
                            html += '<h3 style="margin-top: 20px; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid #ddd;">' + (appName || 'General') + '</h3>';
                            $.each(permissions, function(i, perm) {
                                var checked = grantedPermissions.indexOf(perm.permission_key) !== -1 ? ' checked' : '';
                                html += '<label style="display: block; padding: 8px; background: ' + (checked ? '#f0f6f0' : '#fff') + '; margin-bottom: 5px; border: 1px solid #ddd; border-radius: 3px; cursor: pointer;">';
                                html += '<input type="checkbox" class="wfa-permission-toggle" data-permission-key="' + perm.permission_key + '"' + checked + '> ';
                                html += '<strong>' + perm.permission_name + '</strong>';
                                if (perm.permission_description) {
                                    html += '<br><small style="color: #666;">' + perm.permission_description + '</small>';
                                }
                                html += '</label>';
                            });
                        });

                        html += '</div>';
                        $('#wfa-permissions-content').html(html);

                        // Attach checkbox handlers
                        $('.wfa-permission-toggle').on('change', function() {
                            var checkbox = $(this);
                            var permissionKey = checkbox.data('permission-key');
                            var action = checkbox.is(':checked') ? 'wfa_grant_permission' : 'wfa_revoke_permission';
                            var label = checkbox.closest('label');

                            // Visual feedback
                            checkbox.prop('disabled', true);
                            label.css('opacity', '0.5');

                            $.ajax({
                                url: wfaAdmin.ajax_url,
                                type: 'POST',
                                data: {
                                    action: action,
                                    nonce: wfaAdmin.nonce,
                                    dept_id: currentDeptId,
                                    permission_key: permissionKey
                                },
                                success: function(response) {
                                    checkbox.prop('disabled', false);
                                    label.css('opacity', '1');
                                    if (response.success) {
                                        label.css('background', checkbox.is(':checked') ? '#f0f6f0' : '#fff');
                                    } else {
                                        // Revert checkbox on error
                                        checkbox.prop('checked', !checkbox.is(':checked'));
                                        alert('Error: ' + response.data);
                                    }
                                },
                                error: function() {
                                    checkbox.prop('disabled', false);
                                    checkbox.prop('checked', !checkbox.is(':checked'));
                                    label.css('opacity', '1');
                                    alert('Failed to update permission.');
                                }
                            });
                        });
                    }

                    $('.wfa-close-permissions-modal, #wfa-permissions-modal').on('click', function(e) {
                        if (e.target === this) {
                            $('#wfa-permissions-modal').hide();
                        }
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render registration settings page.
     */
    public function render_registration_page() {
        $registration_enabled = get_option('wfa_registration_enabled', false);
        $auto_approve = get_option('wfa_registration_auto_approve', false);
        $notification_email = get_option('wfa_registration_notification_email', get_option('admin_email'));
        $rate_limit = get_option('wfa_registration_rate_limit', 50);
        $require_login = get_option('wfa_require_login', false);
        $login_page = get_option('wfa_login_page', '');
        $register_page = get_option('wfa_register_page', '');

        ?>
        <div class="wrap">
            <h1>Registration & Access Settings</h1>

            <form method="post" action="">
                <?php wp_nonce_field('wfa_registration_settings', 'wfa_registration_settings_nonce'); ?>

                <h2>Access Control</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Require Login</th>
                        <td>
                            <label>
                                <input type="checkbox" name="require_login" value="1" <?php checked($require_login, true); ?>>
                                Require users to log in to view the website
                            </label>
                            <p class="description">
                                When enabled, all frontend pages will require login. The registration page will be automatically whitelisted.<br>
                                <strong>Note:</strong> You can disable the "Private Website" plugin if you enable this option.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Custom Login Page</th>
                        <td>
                            <input type="text" name="login_page" value="<?php echo esc_attr($login_page); ?>" class="regular-text" placeholder="/login/">
                            <p class="description">
                                Custom page to redirect to for login. Leave empty to use WordPress default (wp-login.php).<br>
                                Example: <code>/login/</code> or <code>/my-custom-login/</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Custom Registration Page</th>
                        <td>
                            <input type="text" name="register_page" value="<?php echo esc_attr($register_page); ?>" class="regular-text" placeholder="/register/">
                            <p class="description">
                                Custom page to link to for registration. Leave empty to use default (/register/).<br>
                                Example: <code>/register/</code> or <code>/sign-up/</code>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;">Registration Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Registration</th>
                        <td>
                            <label>
                                <input type="checkbox" name="registration_enabled" value="1" <?php checked($registration_enabled, true); ?>>
                                Allow users to register using Workforce credentials
                            </label>
                            <p class="description">
                                Registration form will be available at: <code><?php echo home_url('/register/'); ?></code><br>
                                Use shortcode: <code>[wfa_register]</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Approval Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_approve" value="1" <?php checked($auto_approve, true); ?>>
                                Automatically approve new registrations
                            </label>
                            <p class="description">
                                If unchecked, new registrations will require manual approval.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Notification Email</th>
                        <td>
                            <input type="email" name="notification_email" value="<?php echo esc_attr($notification_email); ?>" class="regular-text">
                            <p class="description">
                                Email address to receive notifications for pending registrations.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rate Limit (per hour)</th>
                        <td>
                            <input type="number" name="rate_limit" value="<?php echo esc_attr($rate_limit); ?>" min="1" max="1000" class="small-text">
                            <p class="description">
                                Maximum registration attempts allowed per IP address per hour.<br>
                                <strong>Recommended:</strong> Set to 50-100 during initial rollout when multiple employees may register from the same workplace IP.<br>
                                Can be reduced to 5-10 once most staff have registered for better security.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="wfa_save_registration_settings" class="button button-primary">Save Settings</button>
                </p>
            </form>

            <?php
            if (isset($_POST['wfa_save_registration_settings']) && check_admin_referer('wfa_registration_settings', 'wfa_registration_settings_nonce')) {
                update_option('wfa_require_login', isset($_POST['require_login']) ? 1 : 0);
                update_option('wfa_login_page', sanitize_text_field($_POST['login_page']));
                update_option('wfa_register_page', sanitize_text_field($_POST['register_page']));
                update_option('wfa_registration_enabled', isset($_POST['registration_enabled']) ? 1 : 0);
                update_option('wfa_registration_auto_approve', isset($_POST['auto_approve']) ? 1 : 0);
                update_option('wfa_registration_notification_email', sanitize_email($_POST['notification_email']));

                // Sanitize and validate rate limit (1-1000)
                $rate_limit = max(1, min(1000, intval($_POST['rate_limit'] ?? 50)));
                update_option('wfa_registration_rate_limit', $rate_limit);

                echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
            }
            ?>

            <h2 style="margin-top: 40px;">Setup Instructions</h2>
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Creating the Registration Page</h3>
                <ol>
                    <li>Go to <strong>Pages → Add New</strong></li>
                    <li>Set the title to "Register"</li>
                    <li>Set the permalink/slug to "register"</li>
                    <li>Add the shortcode: <code>[wfa_register]</code></li>
                    <li>Publish the page</li>
                </ol>

                <h3 style="margin-top: 25px;">Require Login Feature</h3>
                <p>If you enable the "Require Login" option above, you can disable the "Private Website" plugin. The registration page is automatically whitelisted along with:</p>
                <ul>
                    <li>wp-login.php and login endpoints</li>
                    <li>AJAX requests</li>
                    <li>REST API endpoints</li>
                    <li>WordPress cron jobs</li>
                </ul>

                <h3 style="margin-top: 25px;">Login Page</h3>
                <p>A registration link will automatically appear on the wp-login.php page when registration is enabled.</p>
                <p>You can also create a custom login page using the <code>[wfa_login]</code> shortcode.</p>
            </div>
        </div>
        <?php
    }

    /**
     * Render pending registrations page.
     */
    public function render_pending_registrations_page() {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        $pending_users = $wpdb->get_results("SELECT * FROM $table WHERE pending_approval = 1 ORDER BY created_at DESC");

        ?>
        <div class="wrap">
            <h1>Pending Registrations</h1>

            <?php if (empty($pending_users)): ?>
                <div class="notice notice-info">
                    <p>No pending registrations.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">Email</th>
                            <th scope="col">Last Name</th>
                            <th scope="col">Employee ID</th>
                            <th scope="col">Registered</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_users as $user): ?>
                            <tr>
                                <td><?php echo esc_html($user->email); ?></td>
                                <td><?php echo esc_html($user->last_name); ?></td>
                                <td><?php echo esc_html($user->employee_id); ?></td>
                                <td><?php echo esc_html($user->created_at); ?></td>
                                <td>
                                    <button type="button" class="button button-small button-primary wfa-approve-user" data-user-id="<?php echo esc_attr($user->workforce_id); ?>">
                                        Approve
                                    </button>
                                    <button type="button" class="button button-small button-link-delete wfa-reject-user" data-user-id="<?php echo esc_attr($user->workforce_id); ?>">
                                        Reject
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <script>
                jQuery(document).ready(function($) {
                    $('.wfa-approve-user').on('click', function() {
                        var button = $(this);
                        var userId = button.data('user-id');

                        if (!confirm('Approve this registration?')) {
                            return;
                        }

                        $.ajax({
                            url: wfaAdmin.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'wfa_approve_registration',
                                nonce: wfaAdmin.nonce,
                                user_id: userId
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert(response.data);
                                    location.reload();
                                } else {
                                    alert('Error: ' + response.data);
                                }
                            }
                        });
                    });

                    $('.wfa-reject-user').on('click', function() {
                        var button = $(this);
                        var userId = button.data('user-id');

                        if (!confirm('Reject and delete this registration?')) {
                            return;
                        }

                        $.ajax({
                            url: wfaAdmin.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'wfa_reject_registration',
                                nonce: wfaAdmin.nonce,
                                user_id: userId
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert(response.data);
                                    location.reload();
                                } else {
                                    alert('Error: ' + response.data);
                                }
                            }
                        });
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get count of pending registrations.
     */
    private function get_pending_registrations_count() {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE pending_approval = 1");
    }

    /**
     * AJAX: Approve registration.
     */
    public function ajax_approve_registration() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $workforce_user_id = intval($_POST['user_id'] ?? 0);

        if (!$workforce_user_id) {
            wp_send_json_error('Invalid user ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE workforce_id = %d", $workforce_user_id));

        if (!$user) {
            wp_send_json_error('User not found');
        }

        // Find the WordPress user by email
        $wp_user = get_user_by('email', $user->email);

        if (!$wp_user) {
            wp_send_json_error('WordPress user not found');
        }

        // Activate the user
        wp_update_user(array(
            'ID' => $wp_user->ID,
            'user_status' => 0 // Active
        ));

        // Update workforce_users table
        $wpdb->update(
            $table,
            array(
                'wp_user_id' => $wp_user->ID,
                'pending_approval' => 0
            ),
            array('workforce_id' => $workforce_user_id),
            array('%d', '%d'),
            array('%d')
        );

        // Send approval email
        wp_mail(
            $user->email,
            'Registration Approved',
            sprintf(
                "Your registration has been approved! You can now log in at: %s",
                wp_login_url()
            )
        );

        wp_send_json_success('Registration approved successfully');
    }

    /**
     * AJAX: Reject registration.
     */
    public function ajax_reject_registration() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $workforce_user_id = intval($_POST['user_id'] ?? 0);

        if (!$workforce_user_id) {
            wp_send_json_error('Invalid user ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE workforce_id = %d", $workforce_user_id));

        if (!$user) {
            wp_send_json_error('User not found');
        }

        // Delete from workforce_users table
        $wpdb->delete($table, array('workforce_id' => $workforce_user_id), array('%d'));

        // Delete WordPress user if exists
        $wp_user = get_user_by('email', $user->email);
        if ($wp_user) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($wp_user->ID);
        }

        wp_send_json_success('Registration rejected and deleted');
    }

    /**
     * AJAX: Get department permissions.
     */
    public function ajax_get_department_permissions() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $dept_id = intval($_POST['dept_id'] ?? 0);

        if (!$dept_id) {
            wp_send_json_error('Invalid department ID');
        }

        $permissions = wfa()->permissions->get_permissions();
        $dept_permissions = wfa()->permissions->get_department_permissions($dept_id);

        wp_send_json_success(array(
            'all_permissions' => $permissions,
            'granted_permissions' => $dept_permissions
        ));
    }

    /**
     * AJAX: Grant permission to department.
     */
    public function ajax_grant_permission() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $dept_id = intval($_POST['dept_id'] ?? 0);
        $permission_key = sanitize_text_field($_POST['permission_key'] ?? '');

        if (!$dept_id || !$permission_key) {
            wp_send_json_error('Invalid parameters');
        }

        $result = wfa()->permissions->grant_permission($dept_id, $permission_key);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('Permission granted');
    }

    /**
     * AJAX: Revoke permission from department.
     */
    public function ajax_revoke_permission() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $dept_id = intval($_POST['dept_id'] ?? 0);
        $permission_key = sanitize_text_field($_POST['permission_key'] ?? '');

        if (!$dept_id || !$permission_key) {
            wp_send_json_error('Invalid parameters');
        }

        $result = wfa()->permissions->revoke_permission($dept_id, $permission_key);

        if (!$result) {
            wp_send_json_error('Failed to revoke permission');
        }

        wp_send_json_success('Permission revoked');
    }

    /**
     * Render registered users page.
     */
    public function render_users_page() {
        global $wpdb;
        $workforce_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';
        $dept_users_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_users';
        $dept_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'departments';

        // Handle search
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Build query
        $sql = "SELECT wu.*, u.user_login, u.user_status
                FROM $workforce_table wu
                LEFT JOIN {$wpdb->users} u ON wu.wp_user_id = u.ID
                WHERE wu.pending_approval = 0";

        if (!empty($search)) {
            $sql .= $wpdb->prepare(" AND (wu.name LIKE %s OR wu.employee_id LIKE %s OR u.user_login LIKE %s OR wu.email LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $sql .= " ORDER BY wu.created_at DESC";

        $users = $wpdb->get_results($sql);

        ?>
        <div class="wrap">
            <h1>Registered Users</h1>

            <form method="get" action="">
                <input type="hidden" name="page" value="workforce-auth-users">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search users...">
                    <button type="submit" class="button">Search</button>
                    <?php if ($search): ?>
                        <a href="?page=workforce-auth-users" class="button">Clear</a>
                    <?php endif; ?>
                </p>
            </form>

            <?php if (empty($users)): ?>
                <div class="notice notice-info">
                    <p>No registered users found.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 200px;">Name</th>
                            <th scope="col" style="width: 100px;">Employee ID</th>
                            <th scope="col" style="width: 150px;">WordPress User</th>
                            <th scope="col" style="width: 150px;">Registration Date</th>
                            <th scope="col" style="width: 100px;">Status</th>
                            <th scope="col">Departments</th>
                            <th scope="col" style="width: 250px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user):
                            // Get user departments
                            $departments = $wpdb->get_results($wpdb->prepare(
                                "SELECT d.name FROM $dept_users_table du
                                 JOIN $dept_table d ON du.department_id = d.id
                                 WHERE du.workforce_user_id = %d",
                                $user->workforce_id
                            ));
                            $dept_names = array_map(function($d) { return $d->name; }, $departments);

                            $status = isset($user->user_status) && $user->user_status == 1 ? 'Inactive' : 'Active';
                            $status_class = $status === 'Active' ? 'active' : 'inactive';
                        ?>
                            <tr data-user-id="<?php echo esc_attr($user->workforce_id); ?>">
                                <td><strong><?php echo esc_html($user->name); ?></strong></td>
                                <td><?php echo esc_html($user->employee_id); ?></td>
                                <td>
                                    <?php if ($user->wp_user_id): ?>
                                        <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->wp_user_id); ?>">
                                            <?php echo esc_html($user->user_login); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">Not linked</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(mysql2date('M j, Y', $user->created_at)); ?></td>
                                <td>
                                    <span class="wfa-status-<?php echo $status_class; ?>" style="padding: 3px 8px; border-radius: 3px; font-size: 11px; <?php echo $status === 'Active' ? 'background: #d4edda; color: #155724;' : 'background: #f8d7da; color: #721c24;'; ?>">
                                        <?php echo esc_html($status); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($dept_names)): ?>
                                        <?php echo esc_html(implode(', ', $dept_names)); ?>
                                    <?php else: ?>
                                        <span style="color: #999;">No departments</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small wfa-manage-user-permissions"
                                            data-user-id="<?php echo esc_attr($user->workforce_id); ?>"
                                            data-user-name="<?php echo esc_attr($user->name); ?>">
                                        Permissions
                                    </button>
                                    <button type="button" class="button button-small wfa-resync-user"
                                            data-user-id="<?php echo esc_attr($user->workforce_id); ?>">
                                        Resync
                                    </button>
                                    <?php if ($user->wp_user_id): ?>
                                        <button type="button" class="button button-small wfa-deactivate-user"
                                                data-user-id="<?php echo esc_attr($user->workforce_id); ?>"
                                                data-current-status="<?php echo esc_attr($status); ?>">
                                            <?php echo $status === 'Active' ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                        <button type="button" class="button button-small wfa-unlink-user"
                                                data-user-id="<?php echo esc_attr($user->workforce_id); ?>">
                                            Unlink
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small button-link-delete wfa-delete-user"
                                            data-user-id="<?php echo esc_attr($user->workforce_id); ?>">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- User Permissions Modal -->
            <div id="wfa-user-permissions-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; justify-content: center; align-items: center;">
                <div style="background: #fff; padding: 30px; border-radius: 4px; max-width: 800px; width: 90%; max-height: 80vh; overflow-y: auto;">
                    <h2 id="wfa-user-permissions-title">Manage User Permissions</h2>
                    <p id="wfa-user-permissions-description">Override department permissions for this user. Green = granted, Red = explicitly denied, Gray = inherited from department.</p>
                    <div id="wfa-user-permissions-content"></div>
                    <p style="text-align: right; margin-top: 20px;">
                        <button type="button" class="button button-primary wfa-close-user-permissions-modal">Close</button>
                    </p>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var currentUserId = null;

            // Manage user permissions
            $('.wfa-manage-user-permissions').on('click', function() {
                currentUserId = $(this).data('user-id');
                var userName = $(this).data('user-name');

                $('#wfa-user-permissions-title').text('Permissions for "' + userName + '"');
                $('#wfa-user-permissions-content').html('<p>Loading... <span class="spinner is-active"></span></p>');
                $('#wfa-user-permissions-modal').css('display', 'flex');

                $.ajax({
                    url: wfaAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wfa_get_user_permissions',
                        nonce: wfaAdmin.nonce,
                        user_id: currentUserId
                    },
                    success: function(response) {
                        if (response.success) {
                            renderUserPermissions(response.data);
                        } else {
                            $('#wfa-user-permissions-content').html('<p style="color: red;">Error: ' + response.data + '</p>');
                        }
                    },
                    error: function() {
                        $('#wfa-user-permissions-content').html('<p style="color: red;">Failed to load permissions.</p>');
                    }
                });
            });

            function renderUserPermissions(data) {
                var allPermissions = data.all_permissions;
                var deptPermissions = data.department_permissions;
                var userPermissions = data.user_permissions;

                if (allPermissions.length === 0) {
                    $('#wfa-user-permissions-content').html('<p style="color: #666; font-style: italic;">No permissions available.</p>');
                    return;
                }

                var html = '<div style="max-height: 500px; overflow-y: auto;">';
                var groupedByApp = {};

                // Group permissions by app
                $.each(allPermissions, function(i, perm) {
                    if (!groupedByApp[perm.app_name]) {
                        groupedByApp[perm.app_name] = [];
                    }
                    groupedByApp[perm.app_name].push(perm);
                });

                // Render grouped permissions
                $.each(groupedByApp, function(appName, permissions) {
                    html += '<h3 style="margin-top: 20px; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid #ddd;">' + (appName || 'General') + '</h3>';
                    $.each(permissions, function(i, perm) {
                        var userOverride = userPermissions[perm.permission_key];
                        var hasDept = deptPermissions.indexOf(perm.permission_key) !== -1;
                        var status = 'inherited';
                        var bgColor = '#f9f9f9';
                        var statusText = '';

                        if (userOverride === 1) {
                            status = 'granted';
                            bgColor = '#d4edda';
                            statusText = ' <strong style="color: #155724;">[Override: Granted]</strong>';
                        } else if (userOverride === 0) {
                            status = 'denied';
                            bgColor = '#f8d7da';
                            statusText = ' <strong style="color: #721c24;">[Override: Denied]</strong>';
                        } else if (hasDept) {
                            statusText = ' <em style="color: #666;">(from department)</em>';
                            bgColor = '#e7f3ff';
                        }

                        html += '<div style="padding: 12px; background: ' + bgColor + '; margin-bottom: 8px; border: 1px solid #ddd; border-radius: 3px;">';
                        html += '<div style="margin-bottom: 5px;"><strong>' + perm.permission_name + '</strong>' + statusText + '</div>';
                        if (perm.permission_description) {
                            html += '<div style="color: #666; font-size: 12px; margin-bottom: 8px;">' + perm.permission_description + '</div>';
                        }
                        html += '<div>';
                        html += '<button type="button" class="button button-small wfa-grant-user-perm" data-perm-key="' + perm.permission_key + '" ' + (status === 'granted' ? 'disabled' : '') + '>Grant</button> ';
                        html += '<button type="button" class="button button-small wfa-deny-user-perm" data-perm-key="' + perm.permission_key + '" ' + (status === 'denied' ? 'disabled' : '') + '>Deny</button> ';
                        html += '<button type="button" class="button button-small wfa-clear-user-perm" data-perm-key="' + perm.permission_key + '" ' + (status === 'inherited' ? 'disabled' : '') + '>Clear Override</button>';
                        html += '</div>';
                        html += '</div>';
                    });
                });

                html += '</div>';
                $('#wfa-user-permissions-content').html(html);

                // Attach handlers
                $('.wfa-grant-user-perm').on('click', function() {
                    updateUserPermission($(this).data('perm-key'), 1);
                });

                $('.wfa-deny-user-perm').on('click', function() {
                    updateUserPermission($(this).data('perm-key'), 0);
                });

                $('.wfa-clear-user-perm').on('click', function() {
                    updateUserPermission($(this).data('perm-key'), null);
                });
            }

            function updateUserPermission(permKey, action) {
                var ajaxAction = action === 1 ? 'wfa_grant_user_permission' : (action === 0 ? 'wfa_revoke_user_permission' : 'wfa_revoke_user_permission');

                $('#wfa-user-permissions-content button').prop('disabled', true);

                $.ajax({
                    url: wfaAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: ajaxAction,
                        nonce: wfaAdmin.nonce,
                        user_id: currentUserId,
                        permission_key: permKey,
                        is_granted: action
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload permissions
                            $('.wfa-manage-user-permissions[data-user-id="' + currentUserId + '"]').trigger('click');
                        } else {
                            alert('Error: ' + response.data);
                            $('#wfa-user-permissions-content button').prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Failed to update permission.');
                        $('#wfa-user-permissions-content button').prop('disabled', false);
                    }
                });
            }

            $('.wfa-close-user-permissions-modal, #wfa-user-permissions-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#wfa-user-permissions-modal').hide();
                }
            });

            // Resync user
            $('.wfa-resync-user').on('click', function() {
                var button = $(this);
                var userId = button.data('user-id');

                if (!confirm('Resync this user from Workforce API?')) {
                    return;
                }

                button.prop('disabled', true).text('Resyncing...');

                $.ajax({
                    url: wfaAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wfa_resync_user',
                        nonce: wfaAdmin.nonce,
                        user_id: userId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                            button.prop('disabled', false).text('Resync');
                        }
                    },
                    error: function() {
                        alert('Failed to resync user.');
                        button.prop('disabled', false).text('Resync');
                    }
                });
            });

            // Deactivate/Activate user
            $('.wfa-deactivate-user').on('click', function() {
                var button = $(this);
                var userId = button.data('user-id');
                var currentStatus = button.data('current-status');
                var newStatus = currentStatus === 'Active' ? 'deactivate' : 'activate';

                if (!confirm('Are you sure you want to ' + newStatus + ' this user?')) {
                    return;
                }

                button.prop('disabled', true);

                $.ajax({
                    url: wfaAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wfa_deactivate_user',
                        nonce: wfaAdmin.nonce,
                        user_id: userId,
                        new_status: newStatus
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Failed to update user status.');
                        button.prop('disabled', false);
                    }
                });
            });

            // Unlink user
            $('.wfa-unlink-user').on('click', function() {
                var button = $(this);
                var userId = button.data('user-id');

                if (!confirm('Unlink this user from Workforce? The WordPress user will remain but will be standalone.')) {
                    return;
                }

                button.prop('disabled', true);

                $.ajax({
                    url: wfaAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wfa_unlink_user',
                        nonce: wfaAdmin.nonce,
                        user_id: userId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Failed to unlink user.');
                        button.prop('disabled', false);
                    }
                });
            });

            // Delete user
            $('.wfa-delete-user').on('click', function() {
                var button = $(this);
                var userId = button.data('user-id');

                if (!confirm('Delete this user completely? This will remove both the Workforce association and WordPress user account.')) {
                    return;
                }

                button.prop('disabled', true);

                $.ajax({
                    url: wfaAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wfa_delete_user',
                        nonce: wfaAdmin.nonce,
                        user_id: userId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Failed to delete user.');
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Get user permissions (department + user overrides).
     */
    public function ajax_get_user_permissions() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $user_id = intval($_POST['user_id'] ?? 0);

        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        global $wpdb;
        $dept_users_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_users';
        $user_perms_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'user_permissions';

        // Get all permissions
        $all_permissions = wfa()->permissions->get_permissions();

        // Get department permissions for this user
        $dept_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT department_id FROM $dept_users_table WHERE workforce_user_id = %d",
            $user_id
        ));

        $dept_permissions = array();
        foreach ($dept_ids as $dept_id) {
            $dept_perms = wfa()->permissions->get_department_permissions($dept_id);
            $dept_permissions = array_merge($dept_permissions, $dept_perms);
        }
        $dept_permissions = array_unique($dept_permissions);

        // Get user-specific permission overrides
        $user_perms = $wpdb->get_results($wpdb->prepare(
            "SELECT permission_key, is_granted FROM $user_perms_table WHERE workforce_user_id = %d",
            $user_id
        ), OBJECT_K);

        $user_permissions = array();
        foreach ($user_perms as $key => $perm) {
            $user_permissions[$key] = (int)$perm->is_granted;
        }

        wp_send_json_success(array(
            'all_permissions' => $all_permissions,
            'department_permissions' => $dept_permissions,
            'user_permissions' => $user_permissions
        ));
    }

    /**
     * AJAX: Grant permission to user (override).
     */
    public function ajax_grant_user_permission() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        $permission_key = sanitize_text_field($_POST['permission_key'] ?? '');
        $is_granted = isset($_POST['is_granted']) ? intval($_POST['is_granted']) : 1;

        if (!$user_id || !$permission_key) {
            wp_send_json_error('Invalid parameters');
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'user_permissions';

        if ($is_granted === null) {
            // Clear override
            $wpdb->delete($table, array(
                'workforce_user_id' => $user_id,
                'permission_key' => $permission_key
            ), array('%d', '%s'));
            wp_send_json_success('Permission override cleared');
        } else {
            // Insert or update
            $wpdb->replace($table, array(
                'workforce_user_id' => $user_id,
                'permission_key' => $permission_key,
                'is_granted' => $is_granted,
                'granted_at' => current_time('mysql')
            ), array('%d', '%s', '%d', '%s'));

            wp_send_json_success($is_granted === 1 ? 'Permission granted' : 'Permission denied');
        }
    }

    /**
     * AJAX: Revoke permission from user (set as denied or clear override).
     */
    public function ajax_revoke_user_permission() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        $permission_key = sanitize_text_field($_POST['permission_key'] ?? '');
        $is_granted = isset($_POST['is_granted']) ? $_POST['is_granted'] : null;

        if (!$user_id || !$permission_key) {
            wp_send_json_error('Invalid parameters');
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'user_permissions';

        if ($is_granted === null || $is_granted === 'null') {
            // Clear override
            $wpdb->delete($table, array(
                'workforce_user_id' => $user_id,
                'permission_key' => $permission_key
            ), array('%d', '%s'));
            wp_send_json_success('Permission override cleared');
        } else {
            // Set as denied (0) or granted (1)
            $wpdb->replace($table, array(
                'workforce_user_id' => $user_id,
                'permission_key' => $permission_key,
                'is_granted' => intval($is_granted),
                'granted_at' => current_time('mysql')
            ), array('%d', '%s', '%d', '%s'));

            wp_send_json_success(intval($is_granted) === 1 ? 'Permission granted' : 'Permission denied');
        }
    }

    /**
     * AJAX: Unlink user from Workforce.
     */
    public function ajax_unlink_user() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $workforce_user_id = intval($_POST['user_id'] ?? 0);

        if (!$workforce_user_id) {
            wp_send_json_error('Invalid user ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        // Delete from workforce_users table (keeps WP user)
        $wpdb->delete($table, array('workforce_id' => $workforce_user_id), array('%d'));

        wp_send_json_success('User unlinked from Workforce. WordPress user remains.');
    }

    /**
     * AJAX: Delete user completely.
     */
    public function ajax_delete_user() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $workforce_user_id = intval($_POST['user_id'] ?? 0);

        if (!$workforce_user_id) {
            wp_send_json_error('Invalid user ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE workforce_id = %d", $workforce_user_id));

        if (!$user) {
            wp_send_json_error('User not found');
        }

        // Delete WordPress user if exists
        if ($user->wp_user_id) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user->wp_user_id);
        }

        // Delete from workforce_users table
        $wpdb->delete($table, array('workforce_id' => $workforce_user_id), array('%d'));

        wp_send_json_success('User deleted successfully');
    }

    /**
     * AJAX: Deactivate/Activate user.
     */
    public function ajax_deactivate_user() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $workforce_user_id = intval($_POST['user_id'] ?? 0);
        $new_status = sanitize_text_field($_POST['new_status'] ?? '');

        if (!$workforce_user_id || !in_array($new_status, array('activate', 'deactivate'))) {
            wp_send_json_error('Invalid parameters');
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE workforce_id = %d", $workforce_user_id));

        if (!$user || !$user->wp_user_id) {
            wp_send_json_error('User not found');
        }

        $user_status = $new_status === 'deactivate' ? 1 : 0;

        wp_update_user(array(
            'ID' => $user->wp_user_id,
            'user_status' => $user_status
        ));

        wp_send_json_success('User ' . $new_status . 'd successfully');
    }

    /**
     * AJAX: Resync user from Workforce API.
     */
    public function ajax_resync_user() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $workforce_user_id = intval($_POST['user_id'] ?? 0);

        if (!$workforce_user_id) {
            wp_send_json_error('Invalid user ID');
        }

        // Get user data from API
        $user_data = $this->api->request("users/{$workforce_user_id}");

        if (is_wp_error($user_data)) {
            wp_send_json_error($user_data->get_error_message());
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        // Update user data
        $wpdb->update(
            $table,
            array(
                'name' => $user_data['name'] ?? '',
                'employee_id' => $user_data['employee_id'] ?? '',
                'last_synced' => current_time('mysql')
            ),
            array('workforce_id' => $workforce_user_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        wp_send_json_success('User resynced successfully');
    }

    /**
     * AJAX: Get selected locations.
     */
    public function ajax_get_selected_locations() {
        check_ajax_referer('wfa_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $locations = get_option('wfa_selected_locations', array());
        wp_send_json_success($locations);
    }
}

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

            <p>
                <button type="button" id="wfa-resync-departments" class="button button-secondary">Re-sync Departments</button>
                <button type="button" id="wfa-reset-setup" class="button button-link-delete">Reset Setup</button>
            </p>
        </div>
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
}

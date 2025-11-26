<?php
/**
 * Registration handler.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class WFA_Registration {

    private $api;

    public function __construct($api) {
        $this->api = $api;

        // Register shortcode
        add_shortcode('wfa_register', array($this, 'render_registration_form'));

        // AJAX handlers
        add_action('wp_ajax_nopriv_wfa_verify_details', array($this, 'ajax_verify_details'));
        add_action('wp_ajax_nopriv_wfa_complete_registration', array($this, 'ajax_complete_registration'));
    }

    /**
     * Render registration form shortcode.
     */
    public function render_registration_form() {
        if (is_user_logged_in()) {
            return '<p>You are already logged in.</p>';
        }

        if (!get_option('wfa_registration_enabled', false)) {
            return '<p>Registration is currently disabled.</p>';
        }

        ob_start();
        include WFA_PLUGIN_DIR . 'templates/registration-form.php';
        return ob_get_clean();
    }

    /**
     * AJAX: Verify user details (Step 1).
     */
    public function ajax_verify_details() {
        check_ajax_referer('wfa_registration_nonce', 'nonce');

        // Rate limiting
        if (!$this->check_rate_limit()) {
            wp_send_json_error('Too many attempts. Please try again in an hour.');
        }

        // Get and normalize fields
        $email = $this->normalize_email($_POST['email'] ?? '');
        $last_name = $this->normalize_name($_POST['last_name'] ?? '');
        $employee_id = trim($_POST['employee_id'] ?? '');
        $date_of_birth = $this->normalize_date($_POST['date_of_birth'] ?? '');
        $phone = $this->normalize_phone($_POST['phone'] ?? '');
        $passcode = trim($_POST['passcode'] ?? '');
        $postcode = $this->normalize_postcode($_POST['postcode'] ?? '');

        // Count provided optional fields
        $provided_fields = array_filter(array($last_name, $employee_id, $date_of_birth, $phone, $passcode, $postcode));
        if (count($provided_fields) < 3) {
            $this->log_attempt();
            wp_send_json_error('Please provide at least 3 verification fields.');
        }

        if (empty($email)) {
            $this->log_attempt();
            wp_send_json_error('Email is required.');
        }

        // Check if email already registered
        if (email_exists($email)) {
            $this->log_attempt();
            wp_send_json_error('The verification details could not be matched. Please check your information and try again.');
        }

        // Get user from Workforce API by email
        $workforce_user = $this->get_workforce_user_by_email($email);
        if (is_wp_error($workforce_user)) {
            $this->log_attempt();
            error_log('WFA Registration Error: ' . $workforce_user->get_error_message());
            wp_send_json_error('Unable to connect to Workforce. Please try again later.');
        }

        if (!$workforce_user) {
            $this->log_attempt();
            error_log('WFA Registration: No user found for email: ' . $email);
            wp_send_json_error('The verification details could not be matched. Please check your information and try again.');
        }

        error_log('WFA Registration: Found user from API: ' . print_r($workforce_user, true));

        // Verify user against additional fields (need 3+ matches)
        $matched_user = $this->verify_user_fields($workforce_user, $last_name, $employee_id, $date_of_birth, $phone, $passcode, $postcode);

        if (!$matched_user) {
            $this->log_attempt();
            error_log('WFA Registration: No match found for email: ' . $email);
            wp_send_json_error('The verification details could not be matched. Please check your information and try again.');
        }

        // Check if already has pending registration
        $existing = $this->get_workforce_user_by_id($matched_user['id']);
        if ($existing && $existing->wp_user_id) {
            $this->log_attempt();
            wp_send_json_error('The verification details could not be matched. Please check your information and try again.');
        }

        // Generate suggested username from name field
        $suggested_username = $this->generate_suggested_username($matched_user['name'] ?? $email);

        // Create temporary token
        $token = wp_generate_password(32, false);
        set_transient('wfa_reg_' . $token, array(
            'email' => $email,
            'workforce_user' => $matched_user,
            'suggested_username' => $suggested_username,
            'verified_at' => time()
        ), 600); // 10 minutes

        wp_send_json_success(array(
            'token' => $token,
            'suggested_username' => $suggested_username,
            'message' => 'Details verified! Please create your password.'
        ));
    }

    /**
     * AJAX: Complete registration (Step 2).
     */
    public function ajax_complete_registration() {
        check_ajax_referer('wfa_registration_nonce', 'nonce');

        $token = sanitize_text_field($_POST['token'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $username = sanitize_text_field($_POST['username'] ?? '');

        if (empty($token)) {
            wp_send_json_error('Invalid registration session.');
        }

        // Get verification data
        $verification = get_transient('wfa_reg_' . $token);
        if (!$verification) {
            wp_send_json_error('Registration session has expired. Please start again.');
        }

        // Validate username
        if (empty($username)) {
            wp_send_json_error('Username is required.');
        }

        // Sanitize username
        $username = sanitize_user($username, true);
        if (empty($username)) {
            wp_send_json_error('Invalid username. Please use only letters, numbers, spaces, periods, hyphens, and underscores.');
        }

        // Validate passwords
        if (empty($password) || strlen($password) < 8) {
            wp_send_json_error('Password must be at least 8 characters long.');
        }

        if ($password !== $password_confirm) {
            wp_send_json_error('Passwords do not match.');
        }

        $email = $verification['email'];
        $workforce_user = $verification['workforce_user'];

        // Double check email not taken
        if (email_exists($email)) {
            delete_transient('wfa_reg_' . $token);
            wp_send_json_error('This email is already registered.');
        }

        // Create unique username (add suffix if taken)
        $auto_approve = get_option('wfa_registration_auto_approve', false);
        $username = $this->create_unique_username($username);

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_send_json_error('Unable to create account. Please try again.');
        }

        // Set user role
        $user = new WP_User($user_id);
        $user->set_role('subscriber');

        // Store in workforce_users table
        $this->store_workforce_user($workforce_user, $auto_approve ? $user_id : null);

        // Delete transient
        delete_transient('wfa_reg_' . $token);

        // If requires approval, deactivate the WP user for now
        if (!$auto_approve) {
            wp_update_user(array(
                'ID' => $user_id,
                'user_status' => 1 // Inactive
            ));

            // Send notification email
            $this->send_approval_notification($email, $user_id);

            wp_send_json_success(array(
                'message' => 'Registration submitted! Your account is pending administrator approval. You will receive an email once approved.'
            ));
        } else {
            // Auto-approve - link user immediately
            $this->link_workforce_user($workforce_user['id'], $user_id);

            wp_send_json_success(array(
                'message' => 'Registration successful! You can now log in.',
                'redirect' => wp_login_url()
            ));
        }
    }

    /**
     * Get user from Workforce API by email.
     *
     * @param string $email User email to search for.
     * @return array|null|WP_Error User data array, null if not found, or WP_Error on failure.
     */
    private function get_workforce_user_by_email($email) {
        error_log('WFA Registration: Requesting user for email: ' . $email);

        $response = $this->api->request('users', 'GET', array('email' => $email));

        if (is_wp_error($response)) {
            error_log('WFA Registration: API error: ' . $response->get_error_message());
            return $response;
        }

        error_log('WFA Registration: API response: ' . print_r($response, true));

        // API returns array of users directly (not wrapped in 'data' field)
        if (is_array($response) && !empty($response)) {
            // Should be single user since we filtered by email
            $user = $response[0];
            error_log('WFA Registration: Found user in API response');
            return $user;
        }

        error_log('WFA Registration: No user found in API response');
        return null;
    }

    /**
     * Verify user fields against provided verification data.
     * Email is already matched - this verifies at least 3 additional fields.
     *
     * @param array $user Workforce user data from API.
     * @param string $last_name Last name to verify.
     * @param string $employee_id Employee ID to verify.
     * @param string $date_of_birth Date of birth to verify.
     * @param string $phone Phone to verify.
     * @param string $passcode Passcode to verify.
     * @param string $postcode Postcode to verify.
     * @return array|null User data if verified, null otherwise.
     */
    private function verify_user_fields($user, $last_name, $employee_id, $date_of_birth, $phone, $passcode, $postcode) {
        error_log('WFA Registration: Verifying additional fields for user');

        // Count matching optional fields
        $matches = 0;

        if (!empty($last_name)) {
            $user_last_name = $this->normalize_name($user['legal_last_name'] ?? '');
            if ($user_last_name === $last_name) {
                $matches++;
                error_log('WFA Registration: Last name matched');
            }
        }

        if (!empty($employee_id)) {
            $user_employee_id = trim($user['employee_id'] ?? '');
            if ($user_employee_id === $employee_id) {
                $matches++;
                error_log('WFA Registration: Employee ID matched');
            }
        }

        if (!empty($date_of_birth)) {
            $user_dob = $this->normalize_date($user['date_of_birth'] ?? '');
            if ($user_dob === $date_of_birth) {
                $matches++;
                error_log('WFA Registration: Date of birth matched');
            }
        }

        if (!empty($phone)) {
            $user_phone = $this->normalize_phone($user['phone'] ?? '');
            $user_normalized_phone = trim($user['normalised_phone'] ?? ''); // Already normalized by API
            if ($user_phone === $phone || $user_normalized_phone === $phone) {
                $matches++;
                error_log('WFA Registration: Phone matched');
            }
        }

        if (!empty($passcode)) {
            $user_passcode = trim($user['passcode'] ?? '');
            if ($user_passcode === $passcode) {
                $matches++;
                error_log('WFA Registration: Passcode matched');
            }
        }

        if (!empty($postcode)) {
            $user_postcode = $this->normalize_postcode($user['address']['postcode'] ?? '');
            if ($user_postcode === $postcode) {
                $matches++;
                error_log('WFA Registration: Postcode matched');
            }
        }

        // If at least 3 fields match, return this user
        error_log('WFA Registration: Total matches: ' . $matches . ' (need 3)');
        if ($matches >= 3) {
            error_log('WFA Registration: SUCCESS - User verified with ' . $matches . ' matching fields');
            return $user;
        } else {
            error_log('WFA Registration: FAIL - Only ' . $matches . ' fields matched');
            return null;
        }
    }

    /**
     * Store workforce user in database.
     */
    private function store_workforce_user($user, $wp_user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        $wpdb->replace($table, array(
            'workforce_id' => $user['id'],
            'wp_user_id' => $wp_user_id,
            'email' => $this->normalize_email($user['email'] ?? ''),
            'name' => trim($user['name'] ?? ''),
            'employee_id' => trim($user['employee_id'] ?? ''),
            'passcode' => trim($user['passcode'] ?? ''),
            'pending_approval' => $wp_user_id ? 0 : 1,
            'last_synced' => current_time('mysql')
        ), array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s'));
    }

    /**
     * Link workforce user to WordPress user.
     */
    private function link_workforce_user($workforce_id, $wp_user_id) {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';

        $wpdb->update($table, array(
            'wp_user_id' => $wp_user_id,
            'pending_approval' => 0
        ), array(
            'workforce_id' => $workforce_id
        ), array('%d', '%d'), array('%d'));
    }

    /**
     * Get workforce user by workforce ID.
     */
    private function get_workforce_user_by_id($workforce_id) {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE workforce_id = %d", $workforce_id));
    }

    /**
     * Generate suggested username from name.
     * Converts to lowercase and replaces spaces with periods.
     *
     * @param string $name Full name from Workforce API.
     * @return string Suggested username.
     */
    private function generate_suggested_username($name) {
        // Convert to lowercase and replace spaces with periods
        $username = strtolower(trim($name));
        $username = str_replace(' ', '.', $username);

        // Sanitize for WordPress username requirements
        $username = sanitize_user($username, true);

        // If empty after sanitization, fallback to random
        if (empty($username)) {
            $username = 'user_' . wp_rand(1000, 9999);
        }

        return $username;
    }

    /**
     * Create unique username from suggested username.
     * Adds numeric suffix if username already exists.
     *
     * @param string $suggested_username Suggested username.
     * @return string Unique username.
     */
    private function create_unique_username($suggested_username) {
        $username = $suggested_username;

        if (username_exists($username)) {
            $username .= '_' . wp_rand(1000, 9999);

            // Keep trying if still exists (unlikely but possible)
            while (username_exists($username)) {
                $username = $suggested_username . '_' . wp_rand(1000, 9999);
            }
        }

        return $username;
    }

    /**
     * Send notification email to admin for approval.
     */
    private function send_approval_notification($email, $user_id) {
        $to = get_option('wfa_registration_notification_email', get_option('admin_email'));
        $subject = 'New Registration Pending Approval';
        $message = sprintf(
            "A new user has registered and is pending approval.\n\nEmail: %s\n\nApprove at: %s",
            $email,
            admin_url('admin.php?page=workforce-auth-registrations')
        );

        wp_mail($to, $subject, $message);
    }

    /**
     * Check rate limit for current IP.
     */
    private function check_rate_limit() {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'rate_limits';
        $ip = $this->get_client_ip();

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE ip_address = %s",
            $ip
        ));

        if (!$record) {
            return true;
        }

        $hour_ago = date('Y-m-d H:i:s', strtotime('-1 hour'));
        if ($record->last_attempt < $hour_ago) {
            // Reset counter
            $wpdb->update($table, array(
                'attempts' => 0,
                'last_attempt' => current_time('mysql')
            ), array('ip_address' => $ip), array('%d', '%s'), array('%s'));
            return true;
        }

        $limit = get_option('wfa_registration_rate_limit', 50);
        return $record->attempts < $limit;
    }

    /**
     * Log failed attempt.
     */
    private function log_attempt() {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'rate_limits';
        $ip = $this->get_client_ip();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (ip_address, attempts, last_attempt)
             VALUES (%s, 1, %s)
             ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = %s",
            $ip,
            current_time('mysql'),
            current_time('mysql')
        ));
    }

    /**
     * Get client IP address.
     */
    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Normalize email.
     */
    private function normalize_email($email) {
        return strtolower(trim($email));
    }

    /**
     * Normalize name for comparison.
     * Used only for verification matching - last_name is not stored.
     */
    private function normalize_name($name) {
        return strtolower(trim($name));
    }

    /**
     * Normalize phone to E.164 format.
     * Used only for verification matching - phone is not stored.
     */
    private function normalize_phone($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (empty($phone)) {
            return '';
        }

        // If starts with 0, assume UK and add +44
        if (substr($phone, 0, 1) === '0') {
            $phone = '44' . substr($phone, 1);
        }

        // Add + prefix
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Normalize date from DD/MM/YYYY or other formats to YYYY-MM-DD.
     * Used only for verification matching - date_of_birth is not stored.
     */
    private function normalize_date($date) {
        if (empty($date)) {
            return '';
        }

        // Try DD/MM/YYYY format first
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }

        // Try YYYY-MM-DD format
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[1], $matches[2], $matches[3]);
        }

        // Try other formats with strtotime
        $timestamp = strtotime($date);
        if ($timestamp) {
            return date('Y-m-d', $timestamp);
        }

        return '';
    }

    /**
     * Normalize postcode.
     * Used only for verification matching - postcode is not stored.
     */
    private function normalize_postcode($postcode) {
        return strtoupper(str_replace(' ', '', trim($postcode)));
    }
}

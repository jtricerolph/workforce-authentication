<?php
/**
 * Authentication and access control.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class WFA_Auth {

    public function __construct() {
        // Register login shortcode
        add_shortcode('wfa_login', array($this, 'render_login_form'));

        // Add registration link to wp-login.php
        add_action('login_form', array($this, 'add_register_link'));

        // Require login for frontend (if enabled)
        add_action('template_redirect', array($this, 'require_login'));

        // Prevent login if user is pending approval
        add_filter('wp_authenticate_user', array($this, 'check_user_approval'), 10, 2);
    }

    /**
     * Render login form shortcode.
     */
    public function render_login_form() {
        if (is_user_logged_in()) {
            return '<p>You are already logged in.</p>';
        }

        ob_start();
        include WFA_PLUGIN_DIR . 'templates/login-form.php';
        return ob_get_clean();
    }

    /**
     * Add registration link below wp-login.php form.
     */
    public function add_register_link() {
        if (!get_option('wfa_registration_enabled', false)) {
            return;
        }

        // Get custom register page or use default
        $custom_register_page = get_option('wfa_register_page', '');
        if (!empty($custom_register_page)) {
            $register_url = home_url($custom_register_page);
        } else {
            $register_url = home_url('/register/');
        }

        echo '<p class="wfa-register-link" style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #dcdcde;">';
        echo 'Don\'t have an account? <a href="' . esc_url($register_url) . '">Register with Workforce</a>';
        echo '</p>';
    }

    /**
     * Require login for frontend pages (if enabled).
     */
    public function require_login() {
        // Check if require login is enabled
        if (!get_option('wfa_require_login', false)) {
            return;
        }

        // Skip if user is already logged in
        if (is_user_logged_in()) {
            return;
        }

        // Skip for admin area and Customizer preview
        if (is_admin() || is_customize_preview()) {
            return;
        }

        global $pagenow;

        // Allow access to core login/registration endpoints
        $allowed_pages = array(
            'wp-login.php',
            'wp-register.php',
            'wp-signup.php',
            'wp-activate.php'
        );

        if (in_array($pagenow, $allowed_pages, true)) {
            return;
        }

        // Allow access to custom login page
        $custom_login_page = get_option('wfa_login_page', '');
        if (!empty($custom_login_page)) {
            $login_slug = trim($custom_login_page, '/');
            if (is_page($login_slug)) {
                return;
            }
        }

        // Allow access to registration page
        $custom_register_page = get_option('wfa_register_page', '');
        if (!empty($custom_register_page)) {
            // Extract slug from custom register page URL
            $register_slug = trim($custom_register_page, '/');
            if (is_page($register_slug)) {
                return;
            }
        } else {
            // Default registration page
            if (is_page('register')) {
                return;
            }
        }

        // Allow access to AJAX requests
        if (wp_doing_ajax()) {
            return;
        }

        // Allow access to REST API - check URL pattern first (before REST_REQUEST is defined)
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($request_uri, '/wp-json/') !== false || (isset($_GET['rest_route']) && !empty($_GET['rest_route']))) {
            return;
        }

        // Allow access to REST API - constant check as fallback
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        // Allow access to cron jobs
        if (wp_doing_cron()) {
            return;
        }

        // Get current URL for redirect after login
        $current_url = home_url(add_query_arg(null, null));

        // Get custom login page or use default
        $custom_login_page = get_option('wfa_login_page', '');
        if (!empty($custom_login_page)) {
            $login_url = home_url($custom_login_page);
        } else {
            $login_url = wp_login_url($current_url);
        }

        wp_safe_redirect($login_url);
        exit;
    }

    /**
     * Check if user is pending approval before allowing login.
     */
    public function check_user_approval($user, $password) {
        if (is_wp_error($user)) {
            return $user;
        }

        // Check if user has inactive status
        if (isset($user->user_status) && $user->user_status == 1) {
            return new WP_Error('pending_approval', 'Your account is pending administrator approval. Please wait for approval before logging in.');
        }

        return $user;
    }
}

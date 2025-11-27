<?php
/**
 * Plugin activation/deactivation handler.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class WFA_Activator {

    /**
     * Run on plugin activation.
     */
    public static function activate() {
        self::create_tables();
        self::create_default_options();
        self::create_registration_page();
        self::create_login_page();
    }

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate() {
        // Nothing to do on deactivation
    }

    /**
     * Create database tables.
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . WFA_TABLE_PREFIX;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Locations table (cached from API)
        $sql = "CREATE TABLE {$prefix}locations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            workforce_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            address text,
            last_synced datetime,
            PRIMARY KEY (id),
            UNIQUE KEY workforce_id (workforce_id)
        ) $charset_collate;";
        dbDelta($sql);

        // Departments table
        $sql = "CREATE TABLE {$prefix}departments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            workforce_id bigint(20) NOT NULL,
            location_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            colour varchar(20),
            export_name varchar(255),
            updated_at bigint(20),
            record_id bigint(20),
            last_synced datetime,
            PRIMARY KEY (id),
            UNIQUE KEY workforce_id (workforce_id),
            KEY location_id (location_id)
        ) $charset_collate;";
        dbDelta($sql);

        // Department users mapping table
        $sql = "CREATE TABLE {$prefix}department_users (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            department_id bigint(20) NOT NULL,
            workforce_user_id bigint(20) NOT NULL,
            is_manager tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY department_id (department_id),
            KEY workforce_user_id (workforce_user_id),
            UNIQUE KEY dept_user (department_id, workforce_user_id)
        ) $charset_collate;";
        dbDelta($sql);

        // Workforce users table (synced from API)
        $sql = "CREATE TABLE {$prefix}users (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            workforce_id bigint(20) NOT NULL,
            wp_user_id bigint(20) DEFAULT NULL,
            email varchar(255) NOT NULL,
            name varchar(255),
            employee_id varchar(100),
            passcode varchar(20),
            pending_approval tinyint(1) DEFAULT 0,
            last_synced datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY workforce_id (workforce_id),
            UNIQUE KEY email (email),
            KEY wp_user_id (wp_user_id),
            KEY pending_approval (pending_approval)
        ) $charset_collate;";
        dbDelta($sql);

        // Rate limiting table
        $sql = "CREATE TABLE {$prefix}rate_limits (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            attempts int(11) DEFAULT 1,
            last_attempt datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ip_address (ip_address),
            KEY last_attempt (last_attempt)
        ) $charset_collate;";
        dbDelta($sql);

        // Permissions table (for apps/modules to register permissions)
        $sql = "CREATE TABLE {$prefix}permissions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            permission_key varchar(100) NOT NULL,
            permission_name varchar(255) NOT NULL,
            permission_description text,
            app_name varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY permission_key (permission_key),
            KEY app_name (app_name)
        ) $charset_collate;";
        dbDelta($sql);

        // Department permissions mapping table
        $sql = "CREATE TABLE {$prefix}department_permissions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            department_id bigint(20) NOT NULL,
            permission_key varchar(100) NOT NULL,
            granted_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY department_id (department_id),
            KEY permission_key (permission_key),
            UNIQUE KEY dept_permission (department_id, permission_key)
        ) $charset_collate;";
        dbDelta($sql);

        // User permissions override table
        $sql = "CREATE TABLE {$prefix}user_permissions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            workforce_user_id bigint(20) NOT NULL,
            permission_key varchar(100) NOT NULL,
            is_granted tinyint(1) DEFAULT 1,
            granted_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY workforce_user_id (workforce_user_id),
            KEY permission_key (permission_key),
            UNIQUE KEY user_permission (workforce_user_id, permission_key)
        ) $charset_collate;";
        dbDelta($sql);

        update_option('wfa_db_version', WFA_VERSION);
    }

    /**
     * Create default plugin options.
     */
    private static function create_default_options() {
        $defaults = array(
            'wfa_access_token' => '',
            'wfa_token_scopes' => array('me', 'user', 'department'),
            'wfa_token_created' => '',
            'wfa_selected_locations' => array(),
            'wfa_setup_complete' => false,
            'wfa_auto_sync_enabled' => false,
            'wfa_auto_sync_frequency' => 'daily',
            'wfa_registration_enabled' => false,
            'wfa_registration_auto_approve' => false,
            'wfa_registration_notification_email' => get_option('admin_email'),
            'wfa_registration_rate_limit' => 50,
            'wfa_require_login' => false,
            'wfa_login_page' => '',
            'wfa_login_page_id' => 0,
            'wfa_register_page' => '',
            'wfa_register_page_id' => 0,
        );

        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Create registration page with shortcode.
     */
    private static function create_registration_page() {
        // Check if page already exists
        $existing_page_id = get_option('wfa_register_page_id');
        if ($existing_page_id && get_post($existing_page_id)) {
            return; // Page already exists
        }

        // Check if a page with the register slug exists
        $existing_page = get_page_by_path('register');
        if ($existing_page) {
            // Use existing page
            update_option('wfa_register_page_id', $existing_page->ID);
            update_option('wfa_register_page', '/register');
            return;
        }

        // Create new registration page
        $page_data = array(
            'post_title'    => 'Staff Registration',
            'post_content'  => '[wfa_register]',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_name'     => 'register',
            'comment_status' => 'closed',
            'ping_status'   => 'closed',
        );

        $page_id = wp_insert_post($page_data);

        if ($page_id && !is_wp_error($page_id)) {
            update_option('wfa_register_page_id', $page_id);
            update_option('wfa_register_page', '/register');
        }
    }

    /**
     * Create login page with shortcode.
     */
    private static function create_login_page() {
        // Check if page already exists
        $existing_page_id = get_option('wfa_login_page_id');
        if ($existing_page_id && get_post($existing_page_id)) {
            return; // Page already exists
        }

        // Check if a page with the login slug exists
        $existing_page = get_page_by_path('login');
        if ($existing_page) {
            // Use existing page
            update_option('wfa_login_page_id', $existing_page->ID);
            update_option('wfa_login_page', '/login');
            return;
        }

        // Create new login page
        $page_data = array(
            'post_title'    => 'Staff Login',
            'post_content'  => '[wfa_login]',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_name'     => 'login',
            'comment_status' => 'closed',
            'ping_status'   => 'closed',
        );

        $page_id = wp_insert_post($page_data);

        if ($page_id && !is_wp_error($page_id)) {
            update_option('wfa_login_page_id', $page_id);
            update_option('wfa_login_page', '/login');
        }
    }

    /**
     * Drop all plugin tables.
     */
    public static function uninstall() {
        global $wpdb;

        $prefix = $wpdb->prefix . WFA_TABLE_PREFIX;

        $wpdb->query("DROP TABLE IF EXISTS {$prefix}locations");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}departments");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}department_users");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}users");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}rate_limits");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}permissions");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}department_permissions");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}user_permissions");

        // Delete registration page if it was created by the plugin
        $register_page_id = get_option('wfa_register_page_id');
        if ($register_page_id) {
            wp_delete_post($register_page_id, true);
        }

        // Delete login page if it was created by the plugin
        $login_page_id = get_option('wfa_login_page_id');
        if ($login_page_id) {
            wp_delete_post($login_page_id, true);
        }

        // Delete all options
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wfa_%'");

        // Clean up transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wfa_%' OR option_name LIKE '_transient_timeout_wfa_%'");
    }
}

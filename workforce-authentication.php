<?php
/**
 * Plugin Name: Workforce Authentication
 * Plugin URI: https://github.com/JTR/workforce-authentication
 * Description: Integrates Workforce (Tanda) HR system for employee authentication and permissions management.
 * Version: 1.0.8
 * Author: JTR
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: workforce-auth
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WFA_VERSION', '1.0.8');
define('WFA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WFA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WFA_TABLE_PREFIX', 'workforce_');

/**
 * Activation hook.
 */
function wfa_activate() {
    require_once WFA_PLUGIN_DIR . 'includes/class-wfa-activator.php';
    WFA_Activator::activate();
}
register_activation_hook(__FILE__, 'wfa_activate');

/**
 * Deactivation hook.
 */
function wfa_deactivate() {
    require_once WFA_PLUGIN_DIR . 'includes/class-wfa-activator.php';
    WFA_Activator::deactivate();
}
register_deactivation_hook(__FILE__, 'wfa_deactivate');

/**
 * Load plugin classes.
 */
require_once WFA_PLUGIN_DIR . 'includes/class-wfa-activator.php';
require_once WFA_PLUGIN_DIR . 'includes/class-wfa-api.php';
require_once WFA_PLUGIN_DIR . 'includes/class-wfa-sync.php';
require_once WFA_PLUGIN_DIR . 'includes/class-wfa-admin.php';
require_once WFA_PLUGIN_DIR . 'includes/class-wfa-registration.php';
require_once WFA_PLUGIN_DIR . 'includes/class-wfa-auth.php';
require_once WFA_PLUGIN_DIR . 'includes/class-wfa-permissions.php';

/**
 * Main plugin class.
 */
class Workforce_Authentication {

    private static $instance = null;
    public $api;
    public $sync;
    public $admin;
    public $registration;
    public $auth;
    public $permissions;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api = new WFA_API();
        $this->sync = new WFA_Sync($this->api);
        $this->admin = new WFA_Admin($this->api, $this->sync);
        $this->registration = new WFA_Registration($this->api);
        $this->auth = new WFA_Auth();
        $this->permissions = new WFA_Permissions();

        add_action('plugins_loaded', array($this, 'init'));
        add_action('wfa_scheduled_sync', array($this, 'run_scheduled_sync'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        // Fire action for apps to register permissions
        add_action('init', array($this, 'register_permissions_hook'), 5);

        // Check and update database if needed
        add_action('plugins_loaded', array($this, 'check_database_version'));
    }

    /**
     * Check database version and update if needed.
     */
    public function check_database_version() {
        $current_version = get_option('wfa_db_version', '0');

        if (version_compare($current_version, WFA_VERSION, '<')) {
            require_once WFA_PLUGIN_DIR . 'includes/class-wfa-activator.php';
            WFA_Activator::activate();
        }
    }

    /**
     * Hook for apps to register their permissions.
     */
    public function register_permissions_hook() {
        do_action('wfa_register_permissions', $this->permissions);
    }

    public function init() {
        load_plugin_textdomain('workforce-auth', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Enqueue frontend scripts.
     */
    public function enqueue_frontend_scripts() {
        // Only load on pages with shortcodes or registration/login pages
        global $post;
        if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'wfa_register') || has_shortcode($post->post_content, 'wfa_login'))) {
            $this->admin->enqueue_registration_scripts();
        }

        // Also check if we're on the registration page by slug
        $custom_register_page = get_option('wfa_register_page', '');
        if (!empty($custom_register_page)) {
            $register_slug = trim($custom_register_page, '/');
            if (is_page($register_slug)) {
                $this->admin->enqueue_registration_scripts();
            }
        } else {
            if (is_page('register')) {
                $this->admin->enqueue_registration_scripts();
            }
        }
    }

    /**
     * Run scheduled sync via cron.
     */
    public function run_scheduled_sync() {
        if (!get_option('wfa_auto_sync_enabled', false)) {
            return;
        }

        $this->sync->sync_departments();
    }
}

/**
 * Initialize plugin.
 */
function wfa() {
    return Workforce_Authentication::get_instance();
}

/**
 * =============================================================================
 * PERMISSIONS SYSTEM - QUICK START GUIDE
 * =============================================================================
 *
 * The Workforce Authentication plugin provides a flexible permissions system
 * that allows apps/plugins to register custom permissions and check if users
 * have those permissions based on their department membership or user-level overrides.
 *
 * HOW TO REGISTER PERMISSIONS IN YOUR APP/PLUGIN:
 *
 * Step 1: Hook into 'wfa_register_permissions' action
 * ------------------------------------------------
 * Add this to your plugin's main file or functions.php:
 *
 *     add_action('wfa_register_permissions', 'my_app_register_permissions');
 *
 *     function my_app_register_permissions() {
 *         // Register your permissions here
 *         wfa_register_permission(
 *             'my_app_view_reports',           // Unique permission key
 *             'View Reports',                   // Human-readable name
 *             'Allow users to view reports',    // Description
 *             'My App'                          // App name (for grouping)
 *         );
 *
 *         wfa_register_permission(
 *             'my_app_edit_settings',
 *             'Edit Settings',
 *             'Allow users to modify app settings',
 *             'My App'
 *         );
 *     }
 *
 * Step 2: Check permissions in your code
 * ---------------------------------------
 * Use the wfa_user_can() helper function to check if a user has a permission:
 *
 *     // Check current user
 *     if (wfa_user_can('my_app_view_reports')) {
 *         // User has permission - show reports
 *         echo '<div class="reports">...</div>';
 *     }
 *
 *     // Check specific user by ID
 *     if (wfa_user_can('my_app_edit_settings', $user_id)) {
 *         // User can edit settings
 *     }
 *
 * Step 3: Assign permissions via admin interface
 * -----------------------------------------------
 * Once registered, permissions appear in:
 * 1. Workforce Auth → Teams → Click "Permissions" on any department
 *    (Department permissions are inherited by all users in that department)
 *
 * 2. Workforce Auth → Registered Users → Click "Permissions" on any user
 *    (User-level overrides that can grant or deny permissions)
 *
 * PERMISSION HIERARCHY:
 * --------------------
 * 1. User-level DENY override (blocks access even if department grants it)
 * 2. User-level GRANT override (grants access even if department doesn't have it)
 * 3. Department permissions (inherited from user's departments)
 * 4. No permission (default - access denied)
 *
 * ADVANCED: Get all user permissions
 * ----------------------------------
 *     $permissions = wfa_get_user_permissions(); // Current user
 *     $permissions = wfa_get_user_permissions($user_id); // Specific user
 *     // Returns array of permission keys: ['my_app_view_reports', 'my_app_edit_settings']
 *
 * EXAMPLE: Complete PWA app integration
 * --------------------------------------
 *     // In your-pwa-app.php
 *     add_action('wfa_register_permissions', 'my_pwa_register_permissions');
 *
 *     function my_pwa_register_permissions() {
 *         wfa_register_permission('pwa_access_dashboard', 'Access Dashboard', 'View the main dashboard', 'My PWA');
 *         wfa_register_permission('pwa_manage_content', 'Manage Content', 'Create and edit content', 'My PWA');
 *         wfa_register_permission('pwa_view_analytics', 'View Analytics', 'Access analytics and reports', 'My PWA');
 *     }
 *
 *     // In your dashboard page
 *     if (!wfa_user_can('pwa_access_dashboard')) {
 *         wp_die('You do not have permission to access this page.');
 *     }
 *
 *     // Show content management UI only to authorized users
 *     if (wfa_user_can('pwa_manage_content')) {
 *         echo '<button class="edit-content">Edit</button>';
 *     }
 *
 * =============================================================================
 */

/**
 * Helper function to register a permission.
 *
 * @param string $permission_key Unique key for the permission.
 * @param string $permission_name Human-readable name.
 * @param string $permission_description Description of what this permission grants.
 * @param string $app_name Name of the app/module.
 * @return bool|WP_Error
 */
function wfa_register_permission($permission_key, $permission_name, $permission_description = '', $app_name = '') {
    return wfa()->permissions->register_permission($permission_key, $permission_name, $permission_description, $app_name);
}

/**
 * Helper function to check if current user has a permission.
 *
 * @param string $permission_key Permission key to check.
 * @param int $user_id Optional. User ID to check. Defaults to current user.
 * @return bool
 */
function wfa_user_can($permission_key, $user_id = null) {
    if (null === $user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id) {
        return false;
    }

    return wfa()->permissions->user_has_permission($user_id, $permission_key);
}

/**
 * Helper function to get all permissions for a user.
 *
 * @param int $user_id Optional. User ID. Defaults to current user.
 * @return array Array of permission keys.
 */
function wfa_get_user_permissions($user_id = null) {
    if (null === $user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id) {
        return array();
    }

    return wfa()->permissions->get_user_permissions($user_id);
}

wfa();

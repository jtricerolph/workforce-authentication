<?php
/**
 * Plugin Name: Workforce Authentication
 * Plugin URI: https://github.com/JTR/workforce-authentication
 * Description: Integrates Workforce (Tanda) HR system for employee authentication and permissions management.
 * Version: 1.0.0
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

define('WFA_VERSION', '1.0.0');
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

        add_action('plugins_loaded', array($this, 'init'));
        add_action('wfa_scheduled_sync', array($this, 'run_scheduled_sync'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
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
        if (is_page('register')) {
            $this->admin->enqueue_registration_scripts();
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

wfa();

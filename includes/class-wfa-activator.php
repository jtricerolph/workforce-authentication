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
        );

        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Drop all plugin tables.
     */
    public static function uninstall() {
        global $wpdb;

        $prefix = $wpdb->prefix . WFA_TABLE_PREFIX;

        $wpdb->query("DROP TABLE IF EXISTS {$prefix}departments");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}department_users");

        // Delete all options
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wfa_%'");
    }
}

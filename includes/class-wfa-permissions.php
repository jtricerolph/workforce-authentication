<?php
/**
 * Permissions management system.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class WFA_Permissions {

    /**
     * Register a permission for an app/module.
     *
     * @param string $permission_key Unique key for the permission (e.g., 'booking_app.view').
     * @param string $permission_name Human-readable name (e.g., 'View Booking App').
     * @param string $permission_description Description of what this permission grants.
     * @param string $app_name Name of the app/module registering this permission.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function register_permission($permission_key, $permission_name, $permission_description = '', $app_name = '') {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'permissions';

        // Validate inputs
        if (empty($permission_key) || empty($permission_name)) {
            return new WP_Error('invalid_permission', 'Permission key and name are required.');
        }

        // Check if permission already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE permission_key = %s",
            $permission_key
        ));

        if ($exists) {
            // Update existing permission
            $result = $wpdb->update(
                $table,
                array(
                    'permission_name' => $permission_name,
                    'permission_description' => $permission_description,
                    'app_name' => $app_name
                ),
                array('permission_key' => $permission_key),
                array('%s', '%s', '%s'),
                array('%s')
            );
        } else {
            // Insert new permission
            $result = $wpdb->insert(
                $table,
                array(
                    'permission_key' => $permission_key,
                    'permission_name' => $permission_name,
                    'permission_description' => $permission_description,
                    'app_name' => $app_name
                ),
                array('%s', '%s', '%s', '%s')
            );
        }

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to register permission.');
        }

        return true;
    }

    /**
     * Get all registered permissions.
     *
     * @param string $app_name Optional. Filter by app name.
     * @return array Array of permission objects.
     */
    public function get_permissions($app_name = '') {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'permissions';

        if (!empty($app_name)) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE app_name = %s ORDER BY app_name, permission_name",
                $app_name
            ));
        }

        return $wpdb->get_results("SELECT * FROM $table ORDER BY app_name, permission_name");
    }

    /**
     * Grant a permission to a department.
     *
     * @param int $department_id Department ID.
     * @param string $permission_key Permission key.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function grant_permission($department_id, $permission_key) {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_permissions';

        // Check if permission exists
        $permission_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'permissions';
        $permission_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $permission_table WHERE permission_key = %s",
            $permission_key
        ));

        if (!$permission_exists) {
            return new WP_Error('invalid_permission', 'Permission does not exist.');
        }

        // Check if already granted
        $already_granted = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE department_id = %d AND permission_key = %s",
            $department_id,
            $permission_key
        ));

        if ($already_granted) {
            return true; // Already granted
        }

        // Grant permission
        $result = $wpdb->insert(
            $table,
            array(
                'department_id' => $department_id,
                'permission_key' => $permission_key
            ),
            array('%d', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to grant permission.');
        }

        return true;
    }

    /**
     * Revoke a permission from a department.
     *
     * @param int $department_id Department ID.
     * @param string $permission_key Permission key.
     * @return bool True on success, false on failure.
     */
    public function revoke_permission($department_id, $permission_key) {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_permissions';

        $result = $wpdb->delete(
            $table,
            array(
                'department_id' => $department_id,
                'permission_key' => $permission_key
            ),
            array('%d', '%s')
        );

        return $result !== false;
    }

    /**
     * Get all permissions for a department.
     *
     * @param int $department_id Department ID.
     * @return array Array of permission keys.
     */
    public function get_department_permissions($department_id) {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_permissions';

        return $wpdb->get_col($wpdb->prepare(
            "SELECT permission_key FROM $table WHERE department_id = %d",
            $department_id
        ));
    }

    /**
     * Check if a user has a specific permission.
     *
     * @param int $user_id WordPress user ID.
     * @param string $permission_key Permission key to check.
     * @return bool True if user has permission, false otherwise.
     */
    public function user_has_permission($user_id, $permission_key) {
        global $wpdb;

        // WordPress administrators always have all permissions
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // Get workforce user ID from WP user ID
        $workforce_users_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';
        $workforce_user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT workforce_id FROM $workforce_users_table WHERE wp_user_id = %d",
            $user_id
        ));

        if (!$workforce_user_id) {
            return false;
        }

        // Get user's departments
        $dept_users_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_users';
        $department_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT department_id FROM $dept_users_table WHERE workforce_user_id = %d",
            $workforce_user_id
        ));

        if (empty($department_ids)) {
            return false;
        }

        // Check if any of user's departments have the permission
        $dept_permissions_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_permissions';
        $placeholders = implode(',', array_fill(0, count($department_ids), '%d'));

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $dept_permissions_table
             WHERE department_id IN ($placeholders) AND permission_key = %s",
            array_merge($department_ids, array($permission_key))
        );

        $count = $wpdb->get_var($query);

        return $count > 0;
    }

    /**
     * Get all permissions for a user.
     *
     * @param int $user_id WordPress user ID.
     * @return array Array of permission keys.
     */
    public function get_user_permissions($user_id) {
        global $wpdb;

        // WordPress administrators have all permissions
        if (user_can($user_id, 'manage_options')) {
            // Return all registered permission keys
            $permissions_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'permissions';
            return $wpdb->get_col("SELECT permission_key FROM $permissions_table");
        }

        // Get workforce user ID from WP user ID
        $workforce_users_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';
        $workforce_user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT workforce_id FROM $workforce_users_table WHERE wp_user_id = %d",
            $user_id
        ));

        if (!$workforce_user_id) {
            return array();
        }

        // Get user's departments
        $dept_users_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_users';
        $department_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT department_id FROM $dept_users_table WHERE workforce_user_id = %d",
            $workforce_user_id
        ));

        if (empty($department_ids)) {
            return array();
        }

        // Get all permissions from user's departments
        $dept_permissions_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_permissions';
        $placeholders = implode(',', array_fill(0, count($department_ids), '%d'));

        $query = $wpdb->prepare(
            "SELECT DISTINCT permission_key FROM $dept_permissions_table
             WHERE department_id IN ($placeholders)",
            $department_ids
        );

        return $wpdb->get_col($query);
    }

    /**
     * Get departments that have a specific permission.
     *
     * @param string $permission_key Permission key.
     * @return array Array of department IDs.
     */
    public function get_departments_with_permission($permission_key) {
        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_permissions';

        return $wpdb->get_col($wpdb->prepare(
            "SELECT department_id FROM $table WHERE permission_key = %s",
            $permission_key
        ));
    }

    /**
     * Delete a permission and all its assignments.
     *
     * @param string $permission_key Permission key to delete.
     * @return bool True on success, false on failure.
     */
    public function delete_permission($permission_key) {
        global $wpdb;

        // Delete from permissions table
        $permissions_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'permissions';
        $wpdb->delete($permissions_table, array('permission_key' => $permission_key), array('%s'));

        // Delete all department assignments
        $dept_permissions_table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_permissions';
        $wpdb->delete($dept_permissions_table, array('permission_key' => $permission_key), array('%s'));

        return true;
    }
}

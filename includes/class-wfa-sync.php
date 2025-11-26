<?php
/**
 * Workforce data sync handler.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class WFA_Sync {

    private $api;

    public function __construct($api) {
        $this->api = $api;
    }

    /**
     * Sync departments from selected locations.
     *
     * @param array $location_ids Array of location IDs to sync.
     * @return array|WP_Error Sync results or error.
     */
    public function sync_departments($location_ids = null) {
        if (null === $location_ids) {
            $location_ids = get_option('wfa_selected_locations', array());
        }

        if (empty($location_ids)) {
            return new WP_Error('no_locations', 'No locations selected');
        }

        // Get departments from API
        $departments = $this->api->get_departments($location_ids);

        if (is_wp_error($departments)) {
            return $departments;
        }

        if (empty($departments)) {
            return array(
                'success' => true,
                'departments_synced' => 0,
                'users_synced' => 0,
            );
        }

        $departments_synced = 0;
        $users_synced = 0;

        foreach ($departments as $dept_data) {
            $result = $this->sync_single_department($dept_data);
            if ($result) {
                $departments_synced++;
                $users_synced += $result['users_synced'];
            }
        }

        update_option('wfa_last_sync', current_time('mysql'));

        // Also sync user data
        $this->sync_users($location_ids);

        return array(
            'success' => true,
            'departments_synced' => $departments_synced,
            'users_synced' => $users_synced,
        );
    }

    /**
     * Sync user data from Workforce API to workforce_users table.
     *
     * @param array $location_ids Array of location IDs to sync users from.
     * @return int Number of users synced.
     */
    public function sync_users($location_ids = null) {
        if (null === $location_ids) {
            $location_ids = get_option('wfa_selected_locations', array());
        }

        if (empty($location_ids)) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'users';
        $users_synced = 0;

        foreach ($location_ids as $location_id) {
            $response = $this->api->request('GET', '/api/v2/users', array('location_id' => $location_id));

            if (is_wp_error($response)) {
                continue;
            }

            if (!isset($response['data']) || !is_array($response['data'])) {
                continue;
            }

            foreach ($response['data'] as $user) {
                // Check if user exists
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE workforce_id = %d",
                    $user['id']
                ));

                $user_data = array(
                    'workforce_id' => $user['id'],
                    'email' => $this->normalize_email($user['email'] ?? ''),
                    'name' => trim($user['name'] ?? ''),
                    'employee_id' => trim($user['employee_id'] ?? ''),
                    'passcode' => trim($user['passcode'] ?? ''),
                    'last_synced' => current_time('mysql')
                );

                if ($existing) {
                    // Keep existing wp_user_id and pending_approval status
                    $wpdb->update(
                        $table,
                        $user_data,
                        array('workforce_id' => $user['id']),
                        array('%d', '%s', '%s', '%s', '%s', '%s'),
                        array('%d')
                    );
                } else {
                    // Insert new user
                    $wpdb->insert(
                        $table,
                        array_merge($user_data, array(
                            'wp_user_id' => null,
                            'pending_approval' => 0
                        )),
                        array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
                    );
                }

                $users_synced++;
            }
        }

        return $users_synced;
    }

    /**
     * Normalize email.
     */
    private function normalize_email($email) {
        return strtolower(trim($email));
    }

    /**
     * Sync a single department.
     *
     * @param array $dept_data Department data from API.
     * @return array|false Sync result or false on failure.
     */
    private function sync_single_department($dept_data) {
        global $wpdb;

        $table_departments = $wpdb->prefix . WFA_TABLE_PREFIX . 'departments';
        $table_users = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_users';

        // Prepare department data
        $department = array(
            'workforce_id' => $dept_data['id'],
            'location_id' => $dept_data['location_id'],
            'name' => $dept_data['name'],
            'colour' => isset($dept_data['colour']) ? $dept_data['colour'] : null,
            'export_name' => isset($dept_data['export_name']) ? $dept_data['export_name'] : null,
            'updated_at' => isset($dept_data['updated_at']) ? $dept_data['updated_at'] : null,
            'record_id' => isset($dept_data['record_id']) ? $dept_data['record_id'] : null,
            'last_synced' => current_time('mysql'),
        );

        // Check if department exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_departments} WHERE workforce_id = %d",
            $dept_data['id']
        ));

        if ($existing) {
            // Update existing
            $wpdb->update(
                $table_departments,
                $department,
                array('id' => $existing),
                array('%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s'),
                array('%d')
            );
            $dept_id = $existing;
        } else {
            // Insert new
            $wpdb->insert(
                $table_departments,
                $department,
                array('%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s')
            );
            $dept_id = $wpdb->insert_id;
        }

        if (!$dept_id) {
            return false;
        }

        // Clear existing user mappings
        $wpdb->delete($table_users, array('department_id' => $dept_id), array('%d'));

        $users_synced = 0;

        // Add staff members
        if (!empty($dept_data['staff']) && is_array($dept_data['staff'])) {
            foreach ($dept_data['staff'] as $user_id) {
                $is_manager = in_array($user_id, $dept_data['managers'] ?? array()) ? 1 : 0;

                $wpdb->insert(
                    $table_users,
                    array(
                        'department_id' => $dept_id,
                        'workforce_user_id' => $user_id,
                        'is_manager' => $is_manager,
                    ),
                    array('%d', '%d', '%d')
                );

                $users_synced++;
            }
        }

        return array(
            'department_id' => $dept_id,
            'users_synced' => $users_synced,
        );
    }

    /**
     * Get all synced departments.
     *
     * @return array
     */
    public function get_departments() {
        global $wpdb;

        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'departments';

        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");
    }

    /**
     * Get users for a department.
     *
     * @param int $department_id Department ID.
     * @return array
     */
    public function get_department_users($department_id) {
        global $wpdb;

        $table = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_users';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE department_id = %d",
            $department_id
        ));
    }

    /**
     * Get departments for a specific user.
     *
     * @param int $workforce_user_id Workforce user ID.
     * @return array
     */
    public function get_user_departments($workforce_user_id) {
        global $wpdb;

        $table_depts = $wpdb->prefix . WFA_TABLE_PREFIX . 'departments';
        $table_users = $wpdb->prefix . WFA_TABLE_PREFIX . 'department_users';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, du.is_manager
             FROM {$table_depts} d
             INNER JOIN {$table_users} du ON d.id = du.department_id
             WHERE du.workforce_user_id = %d
             ORDER BY d.name ASC",
            $workforce_user_id
        ));
    }
}

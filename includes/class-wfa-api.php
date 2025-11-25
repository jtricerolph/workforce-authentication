<?php
/**
 * Workforce API client.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class WFA_API {

    private $base_url = 'https://my.workforce.com';
    private $api_url = 'https://my.workforce.com/api/v2/';
    private $token;

    public function __construct() {
        $this->token = get_option('wfa_access_token', '');
    }

    /**
     * Get bearer token using password authentication.
     *
     * @param string $email    User email.
     * @param string $password User password.
     * @param array  $scopes   Array of scope strings.
     * @return array|WP_Error Response with access_token or error.
     */
    public function get_token($email, $password, $scopes = array()) {
        $url = $this->base_url . '/api/oauth/token';

        // Format scopes as space-separated string
        $scope_string = implode(' ', $scopes);

        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => array(
                'Cache-Control' => 'no-cache',
            ),
            'body' => array(
                'username' => $email,
                'password' => $password,
                'scope' => $scope_string,
                'grant_type' => 'password',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $message = isset($data['error_description']) ? $data['error_description'] : 'Failed to get token';
            return new WP_Error('token_error', $message);
        }

        return $data;
    }

    /**
     * Make an authenticated API request.
     *
     * @param string $endpoint API endpoint (without base URL).
     * @param string $method   HTTP method.
     * @param array  $data     Request data.
     * @return array|WP_Error
     */
    private function request($endpoint, $method = 'GET', $data = array()) {
        if (empty($this->token)) {
            return new WP_Error('no_token', 'No access token configured');
        }

        $url = $this->api_url . ltrim($endpoint, '/');

        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
        );

        if (!empty($data)) {
            if ($method === 'GET') {
                $url = add_query_arg($data, $url);
            } else {
                $args['body'] = wp_json_encode($data);
            }
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code >= 400) {
            $message = isset($decoded['error']) ? $decoded['error'] : "API error: HTTP {$code}";
            return new WP_Error('api_error', $message, array('status' => $code));
        }

        return $decoded;
    }

    /**
     * Test API connection.
     *
     * @return bool|WP_Error
     */
    public function test_connection() {
        $result = $this->request('users/me');

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Get all locations.
     *
     * @return array|WP_Error
     */
    public function get_locations() {
        return $this->request('locations');
    }

    /**
     * Get departments (teams).
     *
     * @param array $location_ids Optional array of location IDs to filter.
     * @return array|WP_Error
     */
    public function get_departments($location_ids = array()) {
        $params = array();

        if (!empty($location_ids)) {
            $params['location_ids'] = implode(',', $location_ids);
        }

        return $this->request('departments', 'GET', $params);
    }

    /**
     * Get current token.
     *
     * @return string
     */
    public function get_current_token() {
        return $this->token;
    }

    /**
     * Check if API is configured.
     *
     * @return bool
     */
    public function is_configured() {
        return !empty($this->token);
    }

    /**
     * Reload token from options.
     */
    public function reload_token() {
        $this->token = get_option('wfa_access_token', '');
    }
}

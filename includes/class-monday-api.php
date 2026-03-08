<?php
if (!defined('ABSPATH')) exit;

class FS_Monday_API {
    
    private $api_token;
    private $api_version = '2024-10';
    private $api_url = 'https://api.monday.com/v2';
    
    public function __construct() {
        $this->api_token = get_option('fs_monday_token');
        $saved_version = get_option('fs_monday_api_version');
        if ($saved_version) {
            $this->api_version = $saved_version;
        }
    }
    
    /**
     * Check if Monday.com is configured and ready to use
     * 
     * @return bool True if Monday.com integration is configured
     */
    public static function is_configured() {
        $token = get_option('fs_monday_token');
        $board_ids = get_option('fs_board_ids', array());
        
        // Need at least the API token and people board to be configured
        return !empty($token) && !empty($board_ids['people']);
    }
    
    /**
     * Get configuration status for display in admin
     * 
     * @return array Status information with 'configured' boolean and 'message' string
     */
    public static function get_status() {
        $token = get_option('fs_monday_token');
        $board_ids = get_option('fs_board_ids', array());
        
        if (empty($token)) {
            return array(
                'configured' => false,
                'message' => 'Monday.com API token not configured',
                'details' => 'Add your Monday.com API token in settings to enable sync'
            );
        }
        
        if (empty($board_ids['people'])) {
            return array(
                'configured' => false,
                'message' => 'Monday.com board IDs not configured',
                'details' => 'Configure at least the People board ID to enable sync'
            );
        }
        
        // Count configured boards
        $configured_boards = array_filter($board_ids);
        $total_boards = 7; // people, roles, workflows, progress, opportunities, signups, programs
        
        return array(
            'configured' => true,
            'message' => 'Monday.com integration active',
            'details' => count($configured_boards) . ' of ' . $total_boards . ' boards configured'
        );
    }
    
    /**
     * Test the API connection
     * 
     * @return array Result with 'success' boolean and 'message' string
     */
    public function test_connection() {
        if (empty($this->api_token)) {
            return array(
                'success' => false,
                'message' => 'No API token configured'
            );
        }
        
        // Simple query to test connection
        $query = 'query { me { id name } }';
        
        $response = wp_remote_post($this->api_url, array(
            'headers' => array(
                'Authorization' => $this->api_token,
                'Content-Type' => 'application/json',
                'API-Version' => $this->api_version
            ),
            'body' => json_encode(array('query' => $query)),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Connection failed: ' . $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['errors'])) {
            return array(
                'success' => false,
                'message' => 'API error: ' . $data['errors'][0]['message']
            );
        }
        
        if (isset($data['data']['me'])) {
            return array(
                'success' => true,
                'message' => 'Connected successfully as: ' . $data['data']['me']['name']
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Unexpected response from API'
        );
    }
    
    /**
     * Execute a GraphQL query against Monday.com API
     * 
     * @param string $query The GraphQL query to execute
     * @return mixed Query results or false on failure
     */
    public function query_raw($query) {
        if (empty($this->api_token)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Monday.com API token not configured');
            }
            return false;
        }
        
        $response = wp_remote_post($this->api_url, array(
            'headers' => array(
                'Authorization' => $this->api_token,
                'Content-Type' => 'application/json',
                'API-Version' => $this->api_version
            ),
            'body' => json_encode(array('query' => $query)),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Monday API Error: ' . $response->get_error_message());
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['errors'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Monday API Error: ' . print_r($data['errors'], true));
            }
            return false;
        }
        
        return $data['data'] ?? false;
    }
    
    /**
     * Get configured board IDs
     * 
     * @return array Board IDs configuration
     */
    public function get_board_ids() {
        return get_option('fs_board_ids', array());
    }
    
    /**
     * Set board IDs configuration
     * 
     * @param array $board_ids Board IDs to save
     */
    public function set_board_ids($board_ids) {
        update_option('fs_board_ids', $board_ids);
    }
    
    /**
     * Check if a specific board is configured
     * 
     * @param string $board_type Board type (people, roles, workflows, etc.)
     * @return bool True if board is configured
     */
    public function is_board_configured($board_type) {
        $board_ids = $this->get_board_ids();
        return !empty($board_ids[$board_type]);
    }
    
    /**
     * Get list of available board types
     * 
     * @return array Board types with labels
     */
    public static function get_board_types() {
        return array(
            'people' => array(
                'label' => 'People/Volunteers',
                'description' => 'Core board for volunteer records',
                'required' => true
            ),
            'roles' => array(
                'label' => 'Roles',
                'description' => 'Volunteer roles and assignments',
                'required' => false
            ),
            'workflows' => array(
                'label' => 'Workflows',
                'description' => 'Onboarding workflows',
                'required' => false
            ),
            'progress' => array(
                'label' => 'Progress',
                'description' => 'Onboarding progress tracking',
                'required' => false
            ),
            'opportunities' => array(
                'label' => 'Opportunities',
                'description' => 'Volunteer opportunities/shifts',
                'required' => false
            ),
            'signups' => array(
                'label' => 'Signups',
                'description' => 'Opportunity signups',
                'required' => false
            ),
            'programs' => array(
                'label' => 'Programs',
                'description' => 'Volunteer programs',
                'required' => false
            )
        );
    }
}
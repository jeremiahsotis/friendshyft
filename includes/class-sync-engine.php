<?php
if (!defined('ABSPATH')) exit;

class FS_Sync_Engine {
    
    public static function init() {
        add_action('fs_sync_cron', array(__CLASS__, 'run_sync'));
    }
    
    public static function run_sync() {
        // Only run if Monday.com is configured
        if (!FS_Monday_API::is_configured()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Sync: Skipped - Monday.com not configured');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Starting sync process');
        }
        
        self::sync_volunteers();
        self::sync_roles();
        self::sync_workflows();
        self::sync_progress();
        self::sync_opportunities();
        self::sync_signups();
        self::sync_programs();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Sync process completed');
        }
    }
    
    private static function sync_volunteers() {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['people'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Sync: People board ID not configured');
            }
            return;
        }
        
        $query = 'query {
            boards(ids: [' . $board_ids['people'] . ']) {
                items_page(limit: 500) {
                    cursor
                    items {
                        id
                        name
                        column_values {
                            id
                            value
                            text
                        }
                    }
                }
            }
        }';
        
        $result = $api->query_raw($query);
        
        if (!$result || empty($result['boards'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Sync: Failed to fetch volunteers');
            }
            return;
        }
        
        $items = $result['boards'][0]['items_page']['items'];
        
        global $wpdb;
        
        foreach ($items as $item) {
            $volunteer_data = array(
                'monday_id' => $item['id'],
                'name' => $item['name']
            );
            
            foreach ($item['column_values'] as $col) {
                $value = $col['value'] ? json_decode($col['value'], true) : null;
                
                switch ($col['id']) {
                    case 'contact_email':
                        $volunteer_data['email'] = $value['email'] ?? '';
                        break;
                    case 'contact_phone':
                        $volunteer_data['phone'] = $value['phone'] ?? '';
                        break;
                    case 'date_mkxs5njb':
                        $volunteer_data['birthdate'] = $value['date'] ?? null;
                        break;
                    case 'color_mkxsmnr7':
                        $volunteer_data['volunteer_status'] = $col['text'] ?? '';
                        break;
                    case 'dropdown_mkxs87kq':
                        $volunteer_data['types'] = $col['text'] ?? '';
                        break;
                    case 'dropdown_mkxs94l1':
                        $volunteer_data['background_check_status'] = $col['text'] ?? '';
                        break;
                    case 'date_mkxs3gk7':
                        $volunteer_data['background_check_date'] = $value['date'] ?? null;
                        break;
                    case 'text_mkxsjfj4':
                        $volunteer_data['background_check_org'] = $col['text'] ?? '';
                        break;
                    case 'date_mkxskyt3':
                        $volunteer_data['background_check_expiration'] = $value['date'] ?? null;
                        break;
                    case 'long_text_mkxsyq08':
                        $volunteer_data['notes'] = $col['text'] ?? '';
                        break;
                    case 'date_mkxspfdm':
                        $volunteer_data['created_date'] = $value['date'] ?? null;
                        break;
                    case 'numeric_mkxsjwt7':
                        $volunteer_data['wp_user_id'] = $col['text'] ? intval($col['text']) : null;
                        break;
                }
            }
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE monday_id = %s",
                $volunteer_data['monday_id']
            ));
            
            $volunteer_data['last_sync'] = current_time('mysql');
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'fs_volunteers',
                    $volunteer_data,
                    array('id' => $existing)
                );
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'fs_volunteers',
                    $volunteer_data
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Synced ' . count($items) . ' volunteers');
        }
    }

    private static function sync_roles() {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['roles'])) {
            return;
        }
        
        $query = 'query {
            boards(ids: [' . $board_ids['roles'] . ']) {
                items_page(limit: 500) {
                    items {
                        id
                        name
                        column_values {
                            id
                            value
                            text
                        }
                    }
                }
            }
        }';
        
        $result = $api->query_raw($query);
        
        if (!$result || empty($result['boards'])) {
            return;
        }
        
        $items = $result['boards'][0]['items_page']['items'];
        
        global $wpdb;
        
        foreach ($items as $item) {
            $role_data = array(
                'monday_id' => $item['id'],
                'name' => $item['name']
            );
            
            foreach ($item['column_values'] as $col) {
                switch ($col['id']) {
                    case 'long_text_mkxx2hko':
                        $role_data['description'] = $col['text'] ?? '';
                        break;
                    case 'long_text_mkxx3rnw':
                        $role_data['requirements'] = $col['text'] ?? '';
                        break;
                    case 'color_mkxx0sx3':
                        $role_data['status'] = $col['text'] ?? 'Active';
                        break;
                }
            }
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_roles WHERE monday_id = %s",
                $role_data['monday_id']
            ));
            
            $role_data['last_sync'] = current_time('mysql');
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'fs_roles',
                    $role_data,
                    array('id' => $existing)
                );
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'fs_roles',
                    $role_data
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Synced ' . count($items) . ' roles');
        }
    }

    private static function sync_workflows() {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['workflows'])) {
            return;
        }
        
        $query = 'query {
            boards(ids: [' . $board_ids['workflows'] . ']) {
                items_page(limit: 500) {
                    items {
                        id
                        name
                        column_values {
                            id
                            value
                            text
                        }
                    }
                }
            }
        }';
        
        $result = $api->query_raw($query);
        
        if (!$result || empty($result['boards'])) {
            return;
        }
        
        $items = $result['boards'][0]['items_page']['items'];
        
        global $wpdb;
        
        foreach ($items as $item) {
            $workflow_data = array(
                'monday_id' => $item['id'],
                'name' => $item['name']
            );
            
            foreach ($item['column_values'] as $col) {
                switch ($col['id']) {
                    case 'long_text_mkxrpcwg':
                        $workflow_data['description'] = $col['text'] ?? '';
                        break;
                    case 'long_text_mkxrqnf5':
                        $workflow_data['steps'] = $col['text'] ?? '';
                        break;
                    case 'color_mkxrox13':
                        $workflow_data['status'] = $col['text'] ?? 'Active';
                        break;
                }
            }
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_workflows WHERE monday_id = %s",
                $workflow_data['monday_id']
            ));
            
            $workflow_data['last_sync'] = current_time('mysql');
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'fs_workflows',
                    $workflow_data,
                    array('id' => $existing)
                );
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'fs_workflows',
                    $workflow_data
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Synced ' . count($items) . ' workflows');
        }
    }

    private static function sync_progress() {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['progress'])) {
            return;
        }
        
        $query = 'query {
            boards(ids: [' . $board_ids['progress'] . ']) {
                items_page(limit: 500) {
                    items {
                        id
                        name
                        column_values {
                            id
                            value
                            text
                        }
                        subitems {
                            id
                            name
                            column_values {
                                id
                                value
                                text
                            }
                        }
                    }
                }
            }
        }';
        
        $result = $api->query_raw($query);
        
        if (!$result || empty($result['boards'])) {
            return;
        }
        
        $items = $result['boards'][0]['items_page']['items'];
        
        global $wpdb;
        
        foreach ($items as $item) {
            $progress_data = array(
                'monday_id' => $item['id'],
                'workflow_name' => ''
            );
            
            $volunteer_monday_id = null;
            $workflow_monday_id = null;
            
            foreach ($item['column_values'] as $col) {
                $value = $col['value'] ? json_decode($col['value'], true) : null;
                
                switch ($col['id']) {
                    case 'board_relation_mkxse50m':
                        if (!empty($value['linkedPulseIds'])) {
                            $volunteer_monday_id = $value['linkedPulseIds'][0]['linkedPulseId'];
                        }
                        break;
                    case 'board_relation_mkxsbdae':
                        if (!empty($value['linkedPulseIds'])) {
                            $workflow_monday_id = $value['linkedPulseIds'][0]['linkedPulseId'];
                        }
                        break;
                    case 'color_mkxsdjf4':
                        $progress_data['overall_status'] = $col['text'] ?? '';
                        break;
                    case 'numbers_mkxsf7th':
                        $progress_data['progress_percentage'] = $col['text'] ? intval($col['text']) : 0;
                        break;
                }
            }
            
            if ($volunteer_monday_id) {
                $volunteer_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE monday_id = %s",
                    $volunteer_monday_id
                ));
                $progress_data['volunteer_id'] = $volunteer_id;
            }
            
            if ($workflow_monday_id) {
                $workflow = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, name FROM {$wpdb->prefix}fs_workflows WHERE monday_id = %s",
                    $workflow_monday_id
                ));
                if ($workflow) {
                    $progress_data['workflow_id'] = $workflow->id;
                    $progress_data['workflow_name'] = $workflow->name;
                }
            }
            
            $step_completions = array();
            if (!empty($item['subitems'])) {
                foreach ($item['subitems'] as $subitem) {
                    $step = array(
                        'monday_id' => $subitem['id'],
                        'name' => $subitem['name'],
                        'completed' => false,
                        'completed_date' => null,
                        'completed_by' => null
                    );
                    
                    foreach ($subitem['column_values'] as $col) {
                        $value = $col['value'] ? json_decode($col['value'], true) : null;
                        
                        switch ($col['id']) {
                            case 'boolean_mkxs3zj3':
                                $step['completed'] = isset($value['checked']) && $value['checked'];
                                break;
                            case 'date_mkxsxg0a':
                                $step['completed_date'] = $value['date'] ?? null;
                                break;
                            case 'text_mkxsqhb1':
                                $step['completed_by'] = $col['text'] ?? null;
                                break;
                        }
                    }
                    
                    $step_completions[] = $step;
                }
            }
            
            $progress_data['step_completions'] = json_encode($step_completions);
            
            if (empty($progress_data['volunteer_id']) || empty($progress_data['workflow_id'])) {
                continue;
            }
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_progress WHERE monday_id = %s",
                $progress_data['monday_id']
            ));
            
            $progress_data['last_sync'] = current_time('mysql');
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'fs_progress',
                    $progress_data,
                    array('id' => $existing)
                );
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'fs_progress',
                    $progress_data
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Synced ' . count($items) . ' progress records');
        }
    }

    private static function sync_opportunities() {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['opportunities'])) {
            return;
        }
        
        $query = 'query {
            boards(ids: [' . $board_ids['opportunities'] . ']) {
                items_page(limit: 500) {
                    items {
                        id
                        name
                        column_values {
                            id
                            value
                            text
                        }
                    }
                }
            }
        }';
        
        $result = $api->query_raw($query);
        
        if (!$result || empty($result['boards'])) {
            return;
        }
        
        $items = $result['boards'][0]['items_page']['items'];
        
        global $wpdb;
        
        foreach ($items as $item) {
            $opp_data = array(
                'monday_id' => $item['id'],
                'title' => $item['name']
            );
            
            foreach ($item['column_values'] as $col) {
                $value = $col['value'] ? json_decode($col['value'], true) : null;
                
                switch ($col['id']) {
                    case 'long_text_mkxua1mn':
                        $opp_data['description'] = $col['text'] ?? '';
                        break;
                    case 'date_mkxu9uay':
                        $opp_data['datetime_start'] = !empty($value['date']) && !empty($value['time']) 
                            ? $value['date'] . ' ' . $value['time'] 
                            : null;
                        break;
                    case 'date_mkxua40h':
                        $opp_data['datetime_end'] = !empty($value['date']) && !empty($value['time']) 
                            ? $value['date'] . ' ' . $value['time'] 
                            : null;
                        break;
                    case 'location_mkxu9zop':
                        $opp_data['location'] = $value['address'] ?? '';
                        break;
                    case 'dropdown_mkxua6lq':
                        $opp_data['conference'] = $col['text'] ?? '';
                        break;
                    case 'long_text_mkxuabq1':
                        $opp_data['requirements'] = $col['text'] ?? '';
                        break;
                    case 'numbers_mkxua8vg':
                        $opp_data['spots_available'] = $col['text'] ? intval($col['text']) : 0;
                        break;
                    case 'numbers_mkxuacgq':
                        $opp_data['spots_filled'] = $col['text'] ? intval($col['text']) : 0;
                        break;
                    case 'color_mkxua3kn':
                        $opp_data['status'] = $col['text'] ?? 'Open';
                        break;
                }
            }
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_opportunities WHERE monday_id = %s",
                $opp_data['monday_id']
            ));
            
            $opp_data['last_sync'] = current_time('mysql');
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'fs_opportunities',
                    $opp_data,
                    array('id' => $existing)
                );
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'fs_opportunities',
                    $opp_data
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Synced ' . count($items) . ' opportunities');
        }
    }

    private static function sync_signups() {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['signups'])) {
            return;
        }
        
        $query = 'query {
            boards(ids: [' . $board_ids['signups'] . ']) {
                items_page(limit: 500) {
                    items {
                        id
                        name
                        column_values {
                            id
                            value
                            text
                        }
                    }
                }
            }
        }';
        
        $result = $api->query_raw($query);
        
        if (!$result || empty($result['boards'])) {
            return;
        }
        
        $items = $result['boards'][0]['items_page']['items'];
        
        global $wpdb;
        
        foreach ($items as $item) {
            $signup_data = array(
                'monday_id' => $item['id']
            );
            
            $volunteer_monday_id = null;
            $opportunity_monday_id = null;
            
            foreach ($item['column_values'] as $col) {
                $value = $col['value'] ? json_decode($col['value'], true) : null;
                
                switch ($col['id']) {
                    case 'board_relation_mkxug82t':
                        if (!empty($value['linkedPulseIds'])) {
                            $volunteer_monday_id = $value['linkedPulseIds'][0]['linkedPulseId'];
                        }
                        break;
                    case 'board_relation_mkxug9xc':
                        if (!empty($value['linkedPulseIds'])) {
                            $opportunity_monday_id = $value['linkedPulseIds'][0]['linkedPulseId'];
                        }
                        break;
                    case 'color_mkxugb8u':
                        $signup_data['status'] = strtolower($col['text'] ?? 'pending');
                        break;
                    case 'date_mkxugdcu':
                        $signup_data['signup_date'] = $value['date'] ?? null;
                        break;
                }
            }
            
            if ($volunteer_monday_id) {
                $volunteer_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE monday_id = %s",
                    $volunteer_monday_id
                ));
                $signup_data['volunteer_id'] = $volunteer_id;
            }
            
            if ($opportunity_monday_id) {
                $opportunity_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_opportunities WHERE monday_id = %s",
                    $opportunity_monday_id
                ));
                $signup_data['opportunity_id'] = $opportunity_id;
            }
            
            if (empty($signup_data['volunteer_id']) || empty($signup_data['opportunity_id'])) {
                continue;
            }
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_signups WHERE monday_id = %s",
                $signup_data['monday_id']
            ));
            
            $signup_data['last_sync'] = current_time('mysql');
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'fs_signups',
                    $signup_data,
                    array('id' => $existing)
                );
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'fs_signups',
                    $signup_data
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Synced ' . count($items) . ' signups');
        }
    }

    private static function sync_programs() {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['programs'])) {
            return;
        }
        
        $query = 'query {
            boards(ids: [' . $board_ids['programs'] . ']) {
                items_page(limit: 500) {
                    items {
                        id
                        name
                        column_values {
                            id
                            value
                            text
                        }
                    }
                }
            }
        }';
        
        $result = $api->query_raw($query);
        
        if (!$result || empty($result['boards'])) {
            return;
        }
        
        $items = $result['boards'][0]['items_page']['items'];
        
        global $wpdb;
        
        foreach ($items as $item) {
            $program_data = array(
                'monday_id' => $item['id'],
                'name' => $item['name']
            );
            
            foreach ($item['column_values'] as $col) {
                switch ($col['id']) {
                    case 'text_mkxw7h4j':
                        $program_data['short_description'] = $col['text'] ?? '';
                        break;
                    case 'long_text_mkxw80e3':
                        $program_data['long_description'] = $col['text'] ?? '';
                        break;
                    case 'color_mkxw7zrc':
                        $program_data['active_status'] = $col['text'] ?? 'Active';
                        break;
                    case 'numbers_mkxw87t2':
                        $program_data['display_order'] = $col['text'] ? intval($col['text']) : 0;
                        break;
                }
            }
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_programs WHERE monday_id = %s",
                $program_data['monday_id']
            ));
            
            $program_data['last_sync'] = current_time('mysql');
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'fs_programs',
                    $program_data,
                    array('id' => $existing)
                );
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'fs_programs',
                    $program_data
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Sync: Synced ' . count($items) . ' programs');
        }
    }

    /**
     * Sync a single volunteer from Monday.com to local database
     * Used after creating a new volunteer to make them immediately available
     */
    public static function sync_single_volunteer($monday_id) {
        // Only run if Monday.com is configured
        if (!FS_Monday_API::is_configured()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Sync: Single volunteer sync skipped - Monday.com not configured');
            }
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['people'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Sync: People board ID not configured');
            }
            return false;
        }
        
        $query = 'query {
            items(ids: [' . intval($monday_id) . ']) {
                id
                name
                column_values {
                    id
                    value
                    text
                }
            }
        }';
        
        $result = $api->query_raw($query);
        
        if (!$result || empty($result['items'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Sync: Failed to fetch volunteer ' . $monday_id);
            }
            return false;
        }
        
        $item = $result['items'][0];
        
        $volunteer_data = array(
            'monday_id' => $item['id'],
            'name' => $item['name']
        );
        
        foreach ($item['column_values'] as $col) {
            $value = $col['value'] ? json_decode($col['value'], true) : null;
            
            switch ($col['id']) {
                case 'contact_email':
                    $volunteer_data['email'] = $value['email'] ?? '';
                    break;
                case 'contact_phone':
                    $volunteer_data['phone'] = $value['phone'] ?? '';
                    break;
                case 'date_mkxs5njb':
                    $volunteer_data['birthdate'] = $value['date'] ?? null;
                    break;
                case 'color_mkxsmnr7':
                    $volunteer_data['volunteer_status'] = $col['text'] ?? '';
                    break;
                case 'dropdown_mkxs87kq':
                    $volunteer_data['types'] = $col['text'] ?? '';
                    break;
                case 'dropdown_mkxs94l1':
                    $volunteer_data['background_check_status'] = $col['text'] ?? '';
                    break;
                case 'date_mkxs3gk7':
                    $volunteer_data['background_check_date'] = $value['date'] ?? null;
                    break;
                case 'text_mkxsjfj4':
                    $volunteer_data['background_check_org'] = $col['text'] ?? '';
                    break;
                case 'date_mkxskyt3':
                    $volunteer_data['background_check_expiration'] = $value['date'] ?? null;
                    break;
                case 'long_text_mkxsyq08':
                    $volunteer_data['notes'] = $col['text'] ?? '';
                    break;
                case 'date_mkxspfdm':
                    $volunteer_data['created_date'] = $value['date'] ?? null;
                    break;
                case 'numeric_mkxsjwt7':
                    $volunteer_data['wp_user_id'] = $col['text'] ? intval($col['text']) : null;
                    break;
            }
        }
        
        global $wpdb;
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE monday_id = %s",
            $volunteer_data['monday_id']
        ));
        
        $volunteer_data['last_sync'] = current_time('mysql');
        
        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'fs_volunteers',
                $volunteer_data,
                array('id' => $existing)
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Sync: Updated volunteer ' . $volunteer_data['monday_id']);
            }
        } else {
            $wpdb->insert(
                $wpdb->prefix . 'fs_volunteers',
                $volunteer_data
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Sync: Inserted volunteer ' . $volunteer_data['monday_id']);
            }
        }
        
        return true;
    }
}

FS_Sync_Engine::init();
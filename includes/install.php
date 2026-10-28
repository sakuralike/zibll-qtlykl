<?php

if (!defined('ABSPATH')) {
    exit;
}

function qtlykl_default_options()
{
    return array(
        'enabled'          => false,
        'duration'         => 300,
        'max_codes'        => 1,
        'codes'            => array(),
        'background_source'=> 'default',
        'background_image' => 0,
        'background_url'   => '',
        'generation'       => 1,
    );
}

function qtlykl_get_options()
{
    $options = get_option(QTLYKL_OPTIONS, array());
    if (!is_array($options)) {
        $options = array();
    }

    return wp_parse_args($options, qtlykl_default_options());
}

function qtlykl_table_names()
{
    global $wpdb;

    return array(
        'messages' => $wpdb->prefix . 'qtlykl_messages',
        'sessions' => $wpdb->prefix . 'qtlykl_sessions',
    );
}

function qtlykl_install()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $tables = qtlykl_table_names();
    $charset = $wpdb->get_charset_collate();

    $messages_sql = "CREATE TABLE {$tables['messages']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        visitor_hash char(64) NOT NULL,
        request_id varchar(64) NOT NULL,
        content text NOT NULL,
        visibility varchar(16) NOT NULL DEFAULT 'public',
        matched_code_id varchar(64) NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY visitor_request (visitor_hash, request_id),
        KEY visibility_id (visibility, id)
    ) {$charset};";

    $sessions_sql = "CREATE TABLE {$tables['sessions']} (
        visitor_hash char(64) NOT NULL,
        state varchar(16) NOT NULL DEFAULT 'board',
        generation varchar(64) NOT NULL,
        duration_seconds int(10) unsigned NOT NULL DEFAULT 300,
        pending_until datetime NULL,
        started_at datetime NULL,
        expires_at datetime NULL,
        last_submit_at datetime NULL,
        created_at datetime NOT NULL,
        session_expires_at datetime NOT NULL,
        PRIMARY KEY  (visitor_hash),
        KEY state_expiry (state, session_expires_at)
    ) {$charset};";

    dbDelta($messages_sql);
    dbDelta($sessions_sql);

    if (get_option(QTLYKL_OPTIONS, null) === null) {
        add_option(QTLYKL_OPTIONS, qtlykl_default_options(), '', 'no');
    } else {
        update_option(QTLYKL_OPTIONS, qtlykl_get_options(), false);
    }
}

function qtlykl_activate()
{
    qtlykl_install();
}

function qtlykl_deactivate()
{
    wp_clear_scheduled_hook('qtlykl_cleanup_sessions');

    $options = qtlykl_get_options();
    $options['generation'] = max(1, (int) $options['generation']) + 1;
    update_option(QTLYKL_OPTIONS, $options, false);

    qtlykl_expire_current_session();
}

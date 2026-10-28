<?php

if (!defined('ABSPATH')) {
    exit;
}

function qtlykl_save_message($visitor_hash, $request_id, $content, $visibility, $matched_code_id = null)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    $inserted = $wpdb->insert(
        $tables['messages'],
        array(
            'visitor_hash'   => $visitor_hash,
            'request_id'     => $request_id,
            'content'        => $content,
            'visibility'     => $visibility,
            'matched_code_id' => $matched_code_id,
            'created_at'     => qtlykl_session_datetime(qtlykl_session_now()),
        ),
        array('%s', '%s', '%s', '%s', '%s', '%s')
    );

    if ($inserted === false) {
        return false;
    }

    return (int) $wpdb->insert_id;
}

function qtlykl_find_message($visitor_hash, $request_id)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tables['messages']} WHERE visitor_hash = %s AND request_id = %s LIMIT 1",
        $visitor_hash,
        $request_id
    ), ARRAY_A);
}

function qtlykl_get_public_messages($limit = 20)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, content, created_at FROM {$tables['messages']} WHERE visibility = 'public' ORDER BY id DESC LIMIT %d",
        absint($limit)
    ), ARRAY_A);
    $codes = qtlykl_get_effective_codes();
    $messages = array();

    foreach ((array) $rows as $row) {
        if (qtlykl_match_code($row['content'], $codes)) {
            continue;
        }
        $messages[] = $row;
    }

    return $messages;
}

function qtlykl_get_messages_for_admin($page = 1, $per_page = 20)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    $page = max(1, absint($page));
    $per_page = min(100, max(1, absint($per_page)));
    $offset = ($page - 1) * $per_page;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$tables['messages']} ORDER BY id DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ), ARRAY_A);
}

function qtlykl_delete_message($id)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    return $wpdb->delete($tables['messages'], array('id' => absint($id)), array('%d'));
}

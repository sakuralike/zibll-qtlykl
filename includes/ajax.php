<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_qtlykl_submit', 'qtlykl_ajax_submit');
add_action('wp_ajax_nopriv_qtlykl_submit', 'qtlykl_ajax_submit');
add_action('wp_ajax_qtlykl_status', 'qtlykl_ajax_status');
add_action('wp_ajax_nopriv_qtlykl_status', 'qtlykl_ajax_status');

function qtlykl_ajax_no_store()
{
    if (function_exists('nocache_headers')) {
        nocache_headers();
    }
    header('Cache-Control: private, no-store, max-age=0', true);
    header('Vary: Cookie', false);
}

function qtlykl_request_id($value)
{
    $value = sanitize_text_field(wp_unslash((string) $value));
    return preg_match('/^[A-Za-z0-9_-]{16,96}$/', $value) === 1 ? $value : '';
}

function qtlykl_rate_limit_key()
{
    $address = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return 'qtlykl_rate_' . hash_hmac('sha256', $address, wp_salt('nonce'));
}

function qtlykl_rate_limited()
{
    $key = qtlykl_rate_limit_key();
    $rate = get_transient($key);
    $rate = is_array($rate) ? $rate : array('started' => time(), 'count' => 0);

    if ((int) $rate['started'] + 600 <= time()) {
        $rate = array('started' => time(), 'count' => 0);
    }
    if ((int) $rate['count'] >= 30) {
        return true;
    }

    $rate['count']++;
    set_transient($key, $rate, 600);
    return false;
}

function qtlykl_ajax_submit()
{
    qtlykl_ajax_no_store();

    if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
        wp_send_json_error(array('message' => '请求方式无效。'), 405);
    }

    if (empty($_COOKIE[QTLYKL_COOKIE]) || preg_match('/^[A-Za-z0-9_-]{32,96}$/', (string) $_COOKIE[QTLYKL_COOKIE]) !== 1) {
        wp_send_json_error(array('message' => '请允许站点 Cookie 后重试。', 'code' => 'cookies_disabled'), 409);
    }

    $session_data = qtlykl_get_session(true);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'qtlykl_submit_' . $session_data['hash'])) {
        wp_send_json_error(array('message' => '请求已失效，请刷新页面后重试。'), 403);
    }

    if (qtlykl_rate_limited()) {
        wp_send_json_error(array('message' => '提交过于频繁，请稍后再试。'), 429);
    }

    $request_id = qtlykl_request_id(isset($_POST['request_id']) ? $_POST['request_id'] : '');
    if (!$request_id) {
        wp_send_json_error(array('message' => '请求格式无效。'), 400);
    }

    $content = qtlykl_normalize_message(isset($_POST['content']) ? wp_unslash($_POST['content']) : '');
    if (is_wp_error($content)) {
        wp_send_json_error(array('message' => $content->get_error_message()), 422);
    }

    $existing = qtlykl_find_message($session_data['hash'], $request_id);
    if ($existing) {
        $state = qtlykl_session_state($session_data);
        if ($existing['visibility'] === 'private' && in_array($state, array('pending', 'active'), true)) {
            wp_send_json_success(array('redirect' => home_url('/')));
        }
        wp_send_json_success(array('message' => '留言成功。'));
    }

    $session = $session_data['session'];
    $state = qtlykl_session_state($session_data);
    $codes = qtlykl_get_effective_codes();
    $matched = qtlykl_match_code($content, $codes);
    $visibility = $matched ? 'private' : 'public';
    $matched_id = $matched ? $matched['id'] : null;

    global $wpdb;
    $wpdb->query('START TRANSACTION');
    $tables = qtlykl_table_names();
    $locked_session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tables['sessions']} WHERE visitor_hash = %s FOR UPDATE",
        $session_data['hash']
    ), ARRAY_A);
    if (!$locked_session) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(array('message' => '会话暂时无法读取，请稍后再试。'), 500);
    }
    $existing = qtlykl_find_message($session_data['hash'], $request_id);
    if ($existing) {
        $wpdb->query('ROLLBACK');
        $state = qtlykl_session_state(qtlykl_get_session(false));
        if ($existing['visibility'] === 'private' && in_array($state, array('pending', 'active'), true)) {
            wp_send_json_success(array('redirect' => home_url('/')));
        }
        wp_send_json_success(array('message' => '留言成功。'));
    }
    if (!empty($locked_session['last_submit_at']) && strtotime($locked_session['last_submit_at'] . ' UTC') > qtlykl_session_now() - 10) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(array('message' => '提交过于频繁，请稍后再试。'), 429);
    }

    $message_id = qtlykl_save_message($session_data['hash'], $request_id, $content, $visibility, $matched_id);
    if (!$message_id) {
        $wpdb->query('ROLLBACK');
        $existing = qtlykl_find_message($session_data['hash'], $request_id);
        if ($existing) {
            wp_send_json_success(array('message' => '留言成功。'));
        }
        wp_send_json_error(array('message' => '留言暂时无法保存，请稍后再试。'), 500);
    }

    $wpdb->update(
        $tables['sessions'],
        array('last_submit_at' => qtlykl_session_datetime(qtlykl_session_now())),
        array('visitor_hash' => $session_data['hash']),
        array('%s'),
        array('%s')
    );

    $redirect = false;
    if ($matched && (int) qtlykl_get_options()['max_codes'] > 0 && !in_array($state, array('pending', 'active'), true)) {
        $redirect = qtlykl_mark_pending($session_data['hash'], (int) qtlykl_get_options()['duration']);
    }

    $wpdb->query('COMMIT');

    if ($redirect) {
        wp_send_json_success(array('redirect' => home_url('/')));
    }

    wp_send_json_success(array(
        'message' => '留言成功。',
        'entry'   => array(
            'content' => $content,
            'created' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), qtlykl_session_now()),
        ),
    ));
}

function qtlykl_ajax_status()
{
    qtlykl_ajax_no_store();

    if (is_user_logged_in()) {
        qtlykl_expire_current_session();
        wp_send_json_success(array(
            'logged_in' => true,
            'state'     => 'logged_in',
            'server_now'=> qtlykl_session_now(),
            'home_url'  => home_url('/'),
        ));
    }

    $token = isset($_COOKIE[QTLYKL_COOKIE]) ? (string) $_COOKIE[QTLYKL_COOKIE] : '';
    if (!$token || preg_match('/^[A-Za-z0-9_-]{32,96}$/', $token) !== 1) {
        qtlykl_clear_cookie();
        wp_send_json_success(array(
            'logged_in' => false,
            'state'     => 'board',
            'server_now'=> qtlykl_session_now(),
            'expires_at'=> 0,
            'home_url'  => home_url('/'),
        ));
    }

    $session_data = qtlykl_get_session(true);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'qtlykl_status_' . $session_data['hash'])) {
        wp_send_json_error(array('message' => '请求已失效。'), 403);
    }

    $state = qtlykl_session_state($session_data);
    $session = qtlykl_get_session(false);
    $expires_at = 0;
    if ($state === 'active' && !empty($session['session']['expires_at'])) {
        $expires_at = strtotime($session['session']['expires_at'] . ' UTC');
    }

    wp_send_json_success(array(
        'logged_in' => false,
        'state'     => $state,
        'server_now'=> qtlykl_session_now(),
        'expires_at'=> $expires_at,
        'home_url'  => home_url('/'),
    ));
}

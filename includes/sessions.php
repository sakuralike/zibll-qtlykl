<?php

if (!defined('ABSPATH')) {
    exit;
}

function qtlykl_session_now()
{
    return current_time('timestamp', true);
}

function qtlykl_session_hash($token)
{
    return hash_hmac('sha256', $token, wp_salt('auth'));
}

function qtlykl_cookie_path()
{
    if (defined('COOKIEPATH') && COOKIEPATH) {
        return COOKIEPATH;
    }

    $path = wp_parse_url(home_url('/'), PHP_URL_PATH);
    return $path ? trailingslashit($path) : '/';
}

function qtlykl_set_cookie($token, $expires)
{
    $args = array(
        'expires'  => (int) $expires,
        'path'     => qtlykl_cookie_path(),
        'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    );

    if (PHP_VERSION_ID >= 70300) {
        setcookie(QTLYKL_COOKIE, $token, $args);
    } else {
        setcookie(QTLYKL_COOKIE, $token, (int) $expires, $args['path'], $args['domain'], $args['secure'], true);
    }
}

function qtlykl_clear_cookie()
{
    qtlykl_set_cookie('', time() - 3600);
    unset($_COOKIE[QTLYKL_COOKIE]);
}

function qtlykl_get_visitor_token()
{
    $token = isset($_COOKIE[QTLYKL_COOKIE]) ? (string) $_COOKIE[QTLYKL_COOKIE] : '';
    if ($token && preg_match('/^[A-Za-z0-9_-]{32,96}$/', $token)) {
        return $token;
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    qtlykl_set_cookie($token, time() + 172800);
    $_COOKIE[QTLYKL_COOKIE] = $token;

    return $token;
}

function qtlykl_session_datetime($timestamp)
{
    return gmdate('Y-m-d H:i:s', (int) $timestamp);
}

function qtlykl_get_session($create = true)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    $token = qtlykl_get_visitor_token();
    $hash = qtlykl_session_hash($token);
    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['sessions']} WHERE visitor_hash = %s", $hash), ARRAY_A);

    if (!$session && $create) {
        $now = qtlykl_session_now();
        $options = qtlykl_get_options();
        $wpdb->insert(
            $tables['sessions'],
            array(
                'visitor_hash'       => $hash,
                'state'              => 'board',
                'generation'         => (string) $options['generation'],
                'duration_seconds'   => (int) $options['duration'],
                'created_at'         => qtlykl_session_datetime($now),
                'session_expires_at' => qtlykl_session_datetime($now + 172800),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['sessions']} WHERE visitor_hash = %s", $hash), ARRAY_A);
    }

    return array(
        'token'   => $token,
        'hash'    => $hash,
        'session' => $session,
    );
}

function qtlykl_session_state($session_data)
{
    $session = isset($session_data['session']) && is_array($session_data['session']) ? $session_data['session'] : null;
    if (!$session) {
        return 'board';
    }

    $now = qtlykl_session_now();
    $options = qtlykl_get_options();

    if ((string) $session['generation'] !== (string) $options['generation']) {
        qtlykl_update_session_state($session_data['hash'], 'expired');
        return 'expired';
    }

    if (!empty($session['session_expires_at']) && strtotime($session['session_expires_at'] . ' UTC') <= $now) {
        qtlykl_update_session_state($session_data['hash'], 'expired');
        return 'expired';
    }

    if ($session['state'] === 'pending') {
        if (empty($session['pending_until']) || strtotime($session['pending_until'] . ' UTC') <= $now) {
            qtlykl_update_session_state($session_data['hash'], 'expired');
            return 'expired';
        }
        return 'pending';
    }

    if ($session['state'] === 'active') {
        if (empty($session['expires_at']) || strtotime($session['expires_at'] . ' UTC') <= $now) {
            qtlykl_update_session_state($session_data['hash'], 'expired');
            return 'expired';
        }
        return 'active';
    }

    return in_array($session['state'], array('board', 'expired'), true) ? $session['state'] : 'board';
}

function qtlykl_update_session_state($hash, $state)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    return $wpdb->update(
        $tables['sessions'],
        array('state' => sanitize_key($state)),
        array('visitor_hash' => $hash),
        array('%s'),
        array('%s')
    );
}

function qtlykl_mark_pending($hash, $duration)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    $now = qtlykl_session_now();
    $options = qtlykl_get_options();
    $pending_until = $now + 120;
    $result = $wpdb->query($wpdb->prepare(
        "UPDATE {$tables['sessions']}
         SET state = 'pending', generation = %s, duration_seconds = %d, pending_until = %s,
             started_at = NULL, expires_at = NULL
         WHERE visitor_hash = %s AND state IN ('board', 'expired')",
        (string) $options['generation'],
        (int) $duration,
        qtlykl_session_datetime($pending_until),
        $hash
    ));

    return $result !== false && $result > 0;
}

function qtlykl_start_pending($hash)
{
    global $wpdb;

    $tables = qtlykl_table_names();
    $now = qtlykl_session_now();
    $now_sql = qtlykl_session_datetime($now);
    $result = $wpdb->query($wpdb->prepare(
        "UPDATE {$tables['sessions']}
         SET state = 'active', started_at = %s, expires_at = DATE_ADD(%s, INTERVAL duration_seconds SECOND)
         WHERE visitor_hash = %s AND state = 'pending' AND pending_until > %s",
        $now_sql,
        $now_sql,
        $hash,
        $now_sql
    ));

    if ($result === false) {
        return false;
    }

    return qtlykl_get_session(false);
}

function qtlykl_expire_current_session()
{
    global $wpdb;

    $token = isset($_COOKIE[QTLYKL_COOKIE]) ? (string) $_COOKIE[QTLYKL_COOKIE] : '';
    if (!$token) {
        return;
    }

    $hash = qtlykl_session_hash($token);
    $tables = qtlykl_table_names();
    $wpdb->update($tables['sessions'], array('state' => 'expired'), array('visitor_hash' => $hash), array('%s'), array('%s'));
    qtlykl_clear_cookie();
}

function qtlykl_cleanup_sessions()
{
    global $wpdb;

    $tables = qtlykl_table_names();
    $now = qtlykl_session_datetime(qtlykl_session_now());
    $wpdb->query($wpdb->prepare("DELETE FROM {$tables['sessions']} WHERE session_expires_at < %s", $now));
}

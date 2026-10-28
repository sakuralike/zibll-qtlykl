<?php

if (!defined('ABSPATH')) {
    exit;
}

function qtlykl_boot()
{
    add_action('zib_require_end', 'qtlykl_theme_ready', 20);
    add_action('after_setup_theme', 'qtlykl_theme_ready', 20);
    add_action('wp_login', 'qtlykl_on_login', 10, 2);
    add_action('wp_logout', 'qtlykl_on_logout');
    add_action('qtlykl_cleanup_sessions', 'qtlykl_cleanup_sessions');

    if (!wp_next_scheduled('qtlykl_cleanup_sessions')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'qtlykl_cleanup_sessions');
    }
}

function qtlykl_on_login($user_login = '', $user = null)
{
    qtlykl_expire_current_session();
}

function qtlykl_on_logout()
{
    qtlykl_expire_current_session();
}

function qtlykl_is_zibll_theme()
{
    if (defined('ZIB_ROOT_PATH')) {
        return true;
    }

    $theme = wp_get_theme();
    $template = strtolower((string) $theme->get_template());
    $stylesheet = strtolower((string) $theme->get_stylesheet());

    return strpos($template, 'zibll') === 0 || strpos($stylesheet, 'zibll') === 0;
}

function qtlykl_theme_ready()
{
    static $registered = false;

    if ($registered) {
        return;
    }

    if (!qtlykl_is_zibll_theme()) {
        add_action('admin_notices', 'qtlykl_dependency_notice');
        return;
    }

    $registered = true;
    add_action('template_redirect', 'qtlykl_template_redirect', 1);
    add_filter('template_include', 'qtlykl_template_include', 99);
    add_action('wp_enqueue_scripts', 'qtlykl_enqueue_assets', 20);
    add_action('wp_footer', 'qtlykl_render_countdown', 10);
    add_filter('body_class', 'qtlykl_body_class');
    qtlykl_register_settings();
}

function qtlykl_dependency_notice()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="notice notice-warning"><p>' . esc_html__('Zibll QTLYKL 需要 Zibll 主题，当前未启用首页接管。', 'zibll-qtlykl') . '</p></div>';
}

function qtlykl_bypass_request()
{
    if (is_admin() || is_user_logged_in() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
        return true;
    }
    if (function_exists('is_feed') && is_feed()) {
        return true;
    }
    if (function_exists('is_trackback') && is_trackback()) {
        return true;
    }
    if (function_exists('wp_is_json_request') && wp_is_json_request()) {
        return true;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
    if ($request_path && preg_match('/\.(?:css|js|mjs|map|jpg|jpeg|png|gif|webp|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|pdf|zip)$/i', $request_path)) {
        return true;
    }
    $fetch_destination = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? (string) $_SERVER['HTTP_SEC_FETCH_DEST'] : '';
    if (in_array($fetch_destination, array('style', 'script', 'image', 'font', 'audio', 'video', 'manifest'), true)) {
        return true;
    }
    if ($request_path && preg_match('#/(?:wp-login|wp-signup|wp-activate|wp-register)\.php$#i', $request_path)) {
        return true;
    }
    if (function_exists('is_page_template') && is_page_template('pages/user-sign.php')) {
        return true;
    }

    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
    if (!in_array($method, array('GET', 'HEAD'), true)) {
        return true;
    }

    $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
    if ($accept && strpos($accept, 'text/html') === false && strpos($accept, '*/*') === false) {
        return true;
    }

    return false;
}

function qtlykl_is_prefetch_request()
{
    return !empty($_SERVER['HTTP_X_PURPOSE']) || !empty($_SERVER['HTTP_PURPOSE']) || !empty($_SERVER['HTTP_SEC_PURPOSE']);
}

function qtlykl_private_response()
{
    if (function_exists('nocache_headers')) {
        nocache_headers();
    }
    header('Cache-Control: private, no-store, max-age=0', true);
    header('Vary: Cookie', false);
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
}

function qtlykl_template_redirect()
{
    $options = qtlykl_get_options();
    if (empty($options['enabled']) || qtlykl_bypass_request()) {
        return;
    }

    $session_data = qtlykl_get_session(true);
    $state = qtlykl_session_state($session_data);
    $is_home = is_front_page();
    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');

    if ($is_home && $state === 'pending' && $method === 'GET' && !qtlykl_is_prefetch_request()) {
        $session_data = qtlykl_start_pending($session_data['hash']);
        $state = qtlykl_session_state($session_data);
    }

    if ($state === 'active') {
        $GLOBALS['qtlykl_access_state'] = 'active';
        qtlykl_private_response();
        return;
    }

    if ($is_home) {
        $GLOBALS['qtlykl_access_state'] = 'board';
        qtlykl_private_response();
        return;
    }

    if ($state === 'pending') {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    wp_safe_redirect(home_url('/'));
    exit;
}

function qtlykl_template_include($template)
{
    if (isset($GLOBALS['qtlykl_access_state']) && $GLOBALS['qtlykl_access_state'] === 'board') {
        return QTLYKL_DIR . 'templates/board.php';
    }

    return $template;
}

function qtlykl_body_class($classes)
{
    if (isset($GLOBALS['qtlykl_access_state']) && $GLOBALS['qtlykl_access_state'] === 'board') {
        $classes[] = 'qtlykl-board-page';
    }

    return $classes;
}

function qtlykl_enqueue_assets()
{
    if (empty($GLOBALS['qtlykl_access_state'])) {
        return;
    }

    wp_enqueue_style('qtlykl-board', QTLYKL_URL . 'assets/board.css', array(), QTLYKL_VERSION);
    wp_enqueue_script('qtlykl-board', QTLYKL_URL . 'assets/board.js', array(), QTLYKL_VERSION, true);

    $session_data = qtlykl_get_session(true);
    $state = $GLOBALS['qtlykl_access_state'];
    $session = $session_data['session'];
    $expires_at = 0;
    if ($state === 'active' && $session && !empty($session['expires_at'])) {
        $expires_at = strtotime($session['expires_at'] . ' UTC');
    }

    wp_localize_script('qtlykl-board', 'QTLYKL_DATA', array(
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'submitNonce' => wp_create_nonce('qtlykl_submit_' . $session_data['hash']),
        'statusNonce' => wp_create_nonce('qtlykl_status_' . $session_data['hash']),
        'homeUrl'     => home_url('/'),
        'mode'        => $state,
        'serverNow'   => qtlykl_session_now(),
        'expiresAt'   => $expires_at,
        'requestId'   => wp_generate_uuid4(),
    ));
}

function qtlykl_render_countdown()
{
    if (!isset($GLOBALS['qtlykl_access_state']) || $GLOBALS['qtlykl_access_state'] !== 'active' || is_user_logged_in()) {
        return;
    }

    echo '<div class="qtlykl-countdown" data-qtlykl-countdown aria-live="polite"><span>' .
        esc_html__('剩余访问时间', 'zibll-qtlykl') .
        '</span> <strong data-qtlykl-remaining>--:--</strong></div>';
}

function qtlykl_login_url()
{
    $url = function_exists('zib_get_sign_url') ? zib_get_sign_url('signin') : wp_login_url(home_url('/'));
    return add_query_arg('redirect_to', home_url('/'), $url);
}

function qtlykl_background_style()
{
    $options = qtlykl_get_options();
    $url = '';

    if ($options['background_source'] === 'media' && $options['background_image']) {
        if (is_numeric($options['background_image'])) {
            $url = wp_get_attachment_image_url((int) $options['background_image'], 'full');
        } else {
            $url = esc_url_raw((string) $options['background_image']);
        }
    } elseif ($options['background_source'] === 'url' && $options['background_url']) {
        $url = $options['background_url'];
    }

    return $url ? ' style="--qtlykl-bg-image:url(' . esc_attr(wp_json_encode(esc_url($url))) . ');"' : '';
}

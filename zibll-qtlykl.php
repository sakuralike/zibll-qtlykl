<?php
/**
 * Plugin Name: Zibll QTLYKL
 * Description: 为 Zibll 站点提供匿名留言板首页与临时口令通行。
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: MoxAcg
 * Author URI: https://moxacg.moe/
 * Text Domain: zibll-qtlykl
 */

if (!defined('ABSPATH')) {
    exit;
}

define('QTLYKL_VERSION', '1.0.0');
define('QTLYKL_FILE', __FILE__);
define('QTLYKL_DIR', plugin_dir_path(__FILE__));
define('QTLYKL_URL', plugin_dir_url(__FILE__));
define('QTLYKL_OPTIONS', 'qtlykl_options');
define('QTLYKL_COOKIE', 'qtlykl_visitor');

require_once QTLYKL_DIR . 'includes/codes.php';
require_once QTLYKL_DIR . 'includes/install.php';
require_once QTLYKL_DIR . 'includes/sessions.php';
require_once QTLYKL_DIR . 'includes/messages.php';
require_once QTLYKL_DIR . 'includes/admin.php';
require_once QTLYKL_DIR . 'includes/ajax.php';
require_once QTLYKL_DIR . 'includes/frontend.php';

register_activation_hook(QTLYKL_FILE, 'qtlykl_activate');
register_deactivation_hook(QTLYKL_FILE, 'qtlykl_deactivate');

add_action('plugins_loaded', 'qtlykl_boot', 20);

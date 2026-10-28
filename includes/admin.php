<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'qtlykl_register_admin_pages', 99);
add_filter('csf_qtlykl_options_save', 'qtlykl_csf_save_options', 10, 2);

function qtlykl_register_settings()
{
    if (!is_admin() || !class_exists('CSF') || defined('QTLYKL_CSF_READY')) {
        return;
    }

    define('QTLYKL_CSF_READY', true);

    CSF::createOptions(QTLYKL_OPTIONS, array(
        'menu_title'       => '留言板口令',
        'menu_slug'        => 'qtlykl-options',
        'menu_icon'        => 'dashicons-format-status',
        'framework_title'  => '留言板口令 <small>QTLYKL</small>',
        'show_reset_all'   => false,
        'show_reset_section'=> false,
        'database'         => 'options',
        'defaults'         => qtlykl_default_options(),
    ));

    CSF::createSection(QTLYKL_OPTIONS, array(
        'title'  => '基础设置',
        'fields' => array(
            array(
                'id'      => 'enabled',
                'type'    => 'switcher',
                'title'   => '启用首页接管',
                'default' => false,
            ),
            array(
                'id'         => 'duration',
                'type'       => 'number',
                'title'      => '临时访问时长',
                'default'    => 300,
                'unit'       => '秒',
                'attributes' => array('min' => 1, 'max' => 86400),
                'validate'  => 'qtlykl_validate_duration_field',
            ),
            array(
                'id'         => 'max_codes',
                'type'       => 'number',
                'title'      => '同时生效数量',
                'default'    => 1,
                'attributes' => array('min' => 0, 'max' => 100),
                'validate'   => 'qtlykl_validate_max_codes_field',
            ),
        ),
    ));

    CSF::createSection(QTLYKL_OPTIONS, array(
        'title'  => '口令设置',
        'fields' => array(
            array(
                'id'           => 'codes',
                'type'         => 'group',
                'title'        => '口令列表',
                'button_title' => '添加口令',
                'accordion_title' => '口令 {{#}}',
                'sanitize'     => 'qtlykl_sanitize_codes_field',
                'validate'     => 'qtlykl_validate_codes_field',
                'fields'       => array(
                    array('id' => 'id', 'type' => 'hidden'),
                    array('id' => 'enabled', 'type' => 'switcher', 'title' => '启用'),
                    array('id' => 'code', 'type' => 'text', 'title' => '口令正文'),
                    array(
                        'id'         => 'starts_at',
                        'type'       => 'text',
                        'title'      => '生效时间',
                        'attributes' => array('type' => 'datetime-local'),
                    ),
                    array(
                        'id'         => 'ends_at',
                        'type'       => 'text',
                        'title'      => '失效时间',
                        'attributes' => array('type' => 'datetime-local'),
                    ),
                ),
            ),
        ),
    ));

    CSF::createSection(QTLYKL_OPTIONS, array(
        'title'  => '背景设置',
        'fields' => array(
            array(
                'id'      => 'background_source',
                'type'    => 'radio',
                'title'   => '背景来源',
                'default' => 'default',
                'options' => array(
                    'default' => '默认背景',
                    'media'   => '媒体库图片',
                    'url'     => '图片链接',
                ),
            ),
            array(
                'id'      => 'background_image',
                'type'    => 'upload',
                'title'   => '媒体库图片',
                'library' => 'image',
                'validate' => 'qtlykl_validate_background_image_field',
            ),
            array(
                'id'      => 'background_url',
                'type'    => 'text',
                'title'   => '图片链接',
                'validate'=> 'qtlykl_validate_background_url_field',
            ),
        ),
    ));
}

function qtlykl_csf_save_options($data)
{
    $errors = array();
    $options = qtlykl_normalize_options($data, $errors);
    if (!empty($GLOBALS['qtlykl_option_errors']) && is_array($GLOBALS['qtlykl_option_errors'])) {
        $errors = array_merge($errors, $GLOBALS['qtlykl_option_errors']);
    }

    if ($errors) {
        $GLOBALS['qtlykl_option_errors'] = array_values(array_unique($errors));
        return qtlykl_get_options();
    }

    return $options;
}

function qtlykl_register_admin_pages()
{
    if (class_exists('CSF')) {
        add_submenu_page(
            'qtlykl-options',
            '留言管理',
            '留言管理',
            'manage_options',
            'qtlykl-messages',
            'qtlykl_render_messages_page'
        );
    }

    add_action('admin_post_qtlykl_delete_message', 'qtlykl_handle_delete_message');
    add_action('admin_notices', 'qtlykl_render_option_errors');
}

function qtlykl_render_option_errors()
{
    if (!empty($GLOBALS['qtlykl_option_errors']) && current_user_can('manage_options')) {
        foreach ((array) $GLOBALS['qtlykl_option_errors'] as $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }
}

function qtlykl_render_messages_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('你没有权限访问此页面。', 'zibll-qtlykl'));
    }

    $messages = qtlykl_get_messages_for_admin();
    ?>
    <div class="wrap">
        <h1>留言管理</h1>
        <table class="widefat striped">
            <thead><tr><th>ID</th><th>时间</th><th>状态</th><th>正文</th><th>操作</th></tr></thead>
            <tbody>
            <?php if (!$messages) : ?>
                <tr><td colspan="5">暂无留言。</td></tr>
            <?php else : ?>
                <?php foreach ($messages as $message) : ?>
                    <tr>
                        <td><?php echo esc_html($message['id']); ?></td>
                        <td><?php echo esc_html(get_date_from_gmt($message['created_at'], get_option('date_format') . ' ' . get_option('time_format'))); ?></td>
                        <td><?php echo $message['visibility'] === 'private' ? '命中口令' : '公开'; ?></td>
                        <td><pre style="white-space:pre-wrap;max-width:640px"><?php echo esc_html($message['content']); ?></pre></td>
                        <td>
                            <a class="button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=qtlykl_delete_message&id=' . absint($message['id'])), 'qtlykl_delete_message_' . absint($message['id']))); ?>">删除</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function qtlykl_handle_delete_message()
{
    $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    if (!current_user_can('manage_options') || !$id || !check_admin_referer('qtlykl_delete_message_' . $id)) {
        wp_die(esc_html__('请求无效。', 'zibll-qtlykl'));
    }

    qtlykl_delete_message($id);
    wp_safe_redirect(admin_url('admin.php?page=qtlykl-messages'));
    exit;
}

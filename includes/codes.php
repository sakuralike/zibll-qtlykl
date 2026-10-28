<?php

if (!defined('ABSPATH')) {
    exit;
}

function qtlykl_utf8_chars($value)
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        return false;
    }

    preg_match_all('/./us', $value, $matches);

    return isset($matches[0]) ? $matches[0] : array();
}

function qtlykl_trim_text($value)
{
    $value = is_string($value) ? $value : '';
    $trimmed = preg_replace('/^\s+|\s+$/u', '', $value);

    return is_string($trimmed) ? $trimmed : trim($value);
}

function qtlykl_normalize_message($value)
{
    $value = is_string($value) ? $value : '';
    if (preg_match('//u', $value) !== 1) {
        return new WP_Error('qtlykl_invalid_utf8', '留言格式无效。');
    }

    $value = str_replace(array("\r\n", "\r"), "\n", $value);
    $value = qtlykl_trim_text($value);
    $chars = qtlykl_utf8_chars($value);

    if ($chars === false || count($chars) < 1 || count($chars) > 1000) {
        return new WP_Error('qtlykl_invalid_length', '留言长度需要在 1 到 1000 个字符之间。');
    }

    if (strpos($value, "\0") !== false || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\p{Cf}]/u', $value)) {
        return new WP_Error('qtlykl_invalid_control', '留言格式无效。');
    }

    return $value;
}

function qtlykl_validate_code_text($value)
{
    $value = qtlykl_trim_text($value);
    $chars = qtlykl_utf8_chars($value);

    if ($chars === false || count($chars) < 1 || count($chars) > 64) {
        return new WP_Error('qtlykl_invalid_code_length', '口令长度需要在 1 到 64 个字符之间。');
    }

    if (strpos($value, "\0") !== false || preg_match('/[\x00-\x1F\x7F\p{Cf}]/u', $value)) {
        return new WP_Error('qtlykl_invalid_code_control', '口令包含不允许的字符。');
    }

    if (preg_match('/[<>&]/u', $value) || strpos($value, '--') !== false) {
        return new WP_Error('qtlykl_invalid_code_comment', '口令包含不允许的注释字符。');
    }

    return $value;
}

function qtlykl_parse_admin_datetime($value)
{
    if ($value === '' || $value === null) {
        return null;
    }

    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        return (int) $value;
    }

    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());
    $errors = DateTimeImmutable::getLastErrors();

    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return false;
    }

    return $date->getTimestamp();
}

function qtlykl_code_time_value($value)
{
    return qtlykl_parse_admin_datetime($value);
}

function qtlykl_normalize_codes($value, &$errors = array())
{
    $errors = array();
    if (!is_array($value)) {
        if ($value !== '' && $value !== null) {
            $errors[] = '口令列表格式无效。';
        }
        return array();
    }
    if (count($value) > 100) {
        $errors[] = '口令数量不能超过 100 条。';
        return array();
    }

    $codes = array();
    $seen = array();

    foreach (array_slice($value, 0, 100) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = qtlykl_validate_code_text(isset($row['code']) ? $row['code'] : '');
        if (is_wp_error($code)) {
            $errors[] = sprintf('第 %d 条口令无效。', $index + 1);
            continue;
        }

        if (isset($seen[$code])) {
            $errors[] = sprintf('第 %d 条口令与已有口令重复。', $index + 1);
            continue;
        }
        $seen[$code] = true;

        $starts_at = qtlykl_code_time_value(isset($row['starts_at']) ? $row['starts_at'] : '');
        $ends_at = qtlykl_code_time_value(isset($row['ends_at']) ? $row['ends_at'] : '');

        if ($starts_at === false || $ends_at === false || ($starts_at !== null && $ends_at !== null && $ends_at <= $starts_at)) {
            $errors[] = sprintf('第 %d 条口令的时间范围无效。', $index + 1);
            continue;
        }

        $codes[] = array(
            'id'        => !empty($row['id']) ? sanitize_key($row['id']) : wp_generate_uuid4(),
            'enabled'   => !empty($row['enabled']) ? 1 : 0,
            'code'      => $code,
            'starts_at' => isset($row['starts_at']) && $row['starts_at'] !== '' ? sanitize_text_field($row['starts_at']) : '',
            'ends_at'   => isset($row['ends_at']) && $row['ends_at'] !== '' ? sanitize_text_field($row['ends_at']) : '',
        );
    }

    return $codes;
}

function qtlykl_sanitize_codes_field($value)
{
    $errors = array();
    $codes = qtlykl_normalize_codes($value, $errors);

    if ($errors) {
        $GLOBALS['qtlykl_option_errors'] = array_merge(
            isset($GLOBALS['qtlykl_option_errors']) && is_array($GLOBALS['qtlykl_option_errors']) ? $GLOBALS['qtlykl_option_errors'] : array(),
            $errors
        );
    }

    return $errors ? $value : $codes;
}

function qtlykl_validate_duration_field($value)
{
    if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1)) || (int) $value > 86400) {
        return '临时访问时长需要填写 1 到 86400 之间的整数。';
    }

    return '';
}

function qtlykl_validate_max_codes_field($value)
{
    if ((!is_int($value) && (!is_string($value) || preg_match('/^(?:0|[1-9]\d*)$/', $value) !== 1)) || (int) $value > 100) {
        return '同时生效数量需要填写 0 到 100 之间的整数。';
    }

    return '';
}

function qtlykl_validate_codes_field($value)
{
    $errors = array();
    qtlykl_normalize_codes($value, $errors);

    return $errors ? implode(' ', $errors) : '';
}

function qtlykl_validate_background_image_field($value)
{
    if ($value === '' || $value === null || $value === 0 || $value === '0') {
        return '';
    }

    if (is_array($value)) {
        $value = isset($value['url']) ? $value['url'] : (isset($value['id']) ? $value['id'] : '');
    }

    if (is_numeric($value)) {
        $mime = get_post_mime_type((int) $value);
        return $mime && strpos($mime, 'image/') === 0 ? '' : '背景附件必须是图片。';
    }

    return qtlykl_validate_image_url_field($value, '媒体库图片链接');
}

function qtlykl_validate_background_url_field($value)
{
    return $value === '' || $value === null ? '' : qtlykl_validate_image_url_field($value, '背景图片链接');
}

function qtlykl_validate_image_url_field($value, $label)
{
    if (!is_string($value)) {
        return $label . '格式无效。';
    }

    $value = trim((string) $value);
    $scheme = strtolower((string) wp_parse_url($value, PHP_URL_SCHEME));
    $sanitized = esc_url_raw($value);

    if ($sanitized === '' || !in_array($scheme, array('http', 'https'), true)) {
        return $label . '必须使用有效的 HTTP 或 HTTPS 地址。';
    }
    if (is_ssl() && $scheme !== 'https') {
        return 'HTTPS 站点不能使用 HTTP 背景图片。';
    }

    return '';
}

function qtlykl_normalize_options($value, &$errors = array())
{
    $old = qtlykl_get_options();
    $value = is_array($value) ? $value : array();
    $errors = array();

    $options = array_merge($old, $value);
    $options['enabled'] = !empty($options['enabled']);
    $duration_input = isset($options['duration']) ? $options['duration'] : 300;
    $max_codes_input = isset($options['max_codes']) ? $options['max_codes'] : 1;
    $duration_valid = (is_int($duration_input) || (is_string($duration_input) && preg_match('/^[1-9]\d*$/', $duration_input) === 1))
        ? (int) $duration_input
        : 0;
    $max_codes_valid = (is_int($max_codes_input) || (is_string($max_codes_input) && preg_match('/^(?:0|[1-9]\d*)$/', $max_codes_input) === 1))
        ? (int) $max_codes_input
        : -1;
    $options['duration'] = $duration_valid;
    $options['max_codes'] = $max_codes_valid;
    $background_source_input = isset($options['background_source']) ? $options['background_source'] : 'default';
    $options['background_source'] = in_array($background_source_input, array('default', 'media', 'url'), true)
        ? $background_source_input
        : $old['background_source'];
    $background_image = isset($options['background_image']) ? $options['background_image'] : 0;
    if (is_array($background_image)) {
        $background_image = isset($background_image['url']) ? $background_image['url'] : (isset($background_image['id']) ? $background_image['id'] : 0);
    }
    $background_image_input = $background_image;
    $options['background_image'] = is_numeric($background_image)
        ? absint($background_image)
        : esc_url_raw((string) $background_image);
    $background_url_input = isset($options['background_url']) ? trim((string) $options['background_url']) : '';
    $options['background_url'] = esc_url_raw($background_url_input);

    if ($duration_valid < 1 || $duration_valid > 86400) {
        $errors[] = '临时访问时长需要在 1 到 86400 秒之间。';
        $options['duration'] = $old['duration'];
    }

    if ($max_codes_valid < 0 || $max_codes_valid > 100) {
        $errors[] = '同时生效数量需要在 0 到 100 之间。';
        $options['max_codes'] = $old['max_codes'];
    }

    if ($background_source_input !== $options['background_source']) {
        $errors[] = '背景来源无效。';
    }

    if ($background_url_input !== '' && $options['background_url'] === '') {
        $errors[] = '背景图片链接无效。';
        $options['background_url'] = $old['background_url'];
    }

    if ($background_image_input !== '' && $background_image_input !== 0 && $background_image_input !== '0' && !is_numeric($background_image_input) && $options['background_image'] === '') {
        $errors[] = '媒体库图片链接无效。';
        $options['background_image'] = $old['background_image'];
    }

    $code_errors = array();
    $codes = qtlykl_normalize_codes(isset($options['codes']) ? $options['codes'] : array(), $code_errors);
    if ($code_errors) {
        $errors = array_merge($errors, $code_errors);
        $options['codes'] = $old['codes'];
    } else {
        $options['codes'] = $codes;
    }

    if ($options['background_source'] === 'media' && $options['background_image']) {
        if (is_numeric($options['background_image'])) {
            $mime = get_post_mime_type((int) $options['background_image']);
            if (!$mime || strpos($mime, 'image/') !== 0) {
                $errors[] = '背景附件必须是图片。';
                $options['background_image'] = $old['background_image'];
            }
        } else {
            $scheme = wp_parse_url($options['background_image'], PHP_URL_SCHEME);
            if (!in_array(strtolower((string) $scheme), array('http', 'https'), true) || (is_ssl() && strtolower((string) $scheme) !== 'https')) {
                $errors[] = '背景图片链接必须使用有效的 HTTP 或 HTTPS 地址。';
                $options['background_image'] = $old['background_image'];
            }
        }
    }

    if ($options['background_source'] === 'url' && $options['background_url']) {
        $scheme = wp_parse_url($options['background_url'], PHP_URL_SCHEME);
        if (!in_array(strtolower((string) $scheme), array('http', 'https'), true)) {
            $errors[] = '背景图片链接必须使用 HTTP 或 HTTPS。';
            $options['background_url'] = $old['background_url'];
        }
        if (is_ssl() && strtolower((string) $scheme) !== 'https') {
            $errors[] = 'HTTPS 站点不能使用 HTTP 背景图片。';
            $options['background_url'] = $old['background_url'];
        }
    }

    if (!isset($options['generation']) || !$options['generation']) {
        $options['generation'] = 1;
    }

    if ((bool) $old['enabled'] !== (bool) $options['enabled']) {
        $options['generation'] = (int) $old['generation'] + 1;
    }

    return $options;
}

function qtlykl_parse_code_timestamp($value)
{
    return qtlykl_parse_admin_datetime($value);
}

function qtlykl_get_effective_codes($at = null)
{
    $options = qtlykl_get_options();
    $at = $at === null ? time() : (int) $at;
    $candidates = array();

    foreach ((array) $options['codes'] as $code) {
        if (empty($code['enabled']) || empty($code['code'])) {
            continue;
        }

        $starts_at = qtlykl_parse_code_timestamp(isset($code['starts_at']) ? $code['starts_at'] : '');
        $ends_at = qtlykl_parse_code_timestamp(isset($code['ends_at']) ? $code['ends_at'] : '');

        if ($starts_at === false || $ends_at === false) {
            continue;
        }
        if ($starts_at !== null && $starts_at > $at) {
            continue;
        }
        if ($ends_at !== null && $at >= $ends_at) {
            continue;
        }

        $candidates[] = $code;
    }

    return array_slice($candidates, 0, (int) $options['max_codes']);
}

function qtlykl_contains_code_in_order($message, $code)
{
    $message_chars = qtlykl_utf8_chars($message);
    $code_chars = qtlykl_utf8_chars($code);

    if ($message_chars === false || $code_chars === false || !$code_chars) {
        return false;
    }

    $code_index = 0;
    foreach ($message_chars as $char) {
        if ($char === $code_chars[$code_index]) {
            $code_index++;
            if ($code_index === count($code_chars)) {
                return true;
            }
        }
    }

    return false;
}

function qtlykl_match_code($message, $codes = null)
{
    $codes = $codes === null ? qtlykl_get_effective_codes() : $codes;
    foreach ((array) $codes as $code) {
        if (!empty($code['code']) && qtlykl_contains_code_in_order($message, $code['code'])) {
            return $code;
        }
    }

    return false;
}

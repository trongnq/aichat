<?php
/**
 * AI Chat Widget Plugin - Frontend
 * NukeViet Plugin - Khu vực: Trước khi website gửi nội dung tới trình duyệt
 *
 * FIX v3.5: echo trực tiếp thay vì dùng $my_footer (không phụ thuộc theme)
 */

if (! defined('NV_MAINFILE')) {
    exit('Stop!!!');
}

/* Chỉ chạy ở frontend */
if (defined('NV_ADMIN')) {
    return;
}

/* Load hàm helper */
if (! function_exists('nv_aichat_get_config')) {
    $aichat_func_file = NV_ROOTDIR . '/modules/aichat/functions.php';
    if (! file_exists($aichat_func_file)) {
        return;
    }
    require_once $aichat_func_file;
}

$_aichat_cfg = nv_aichat_get_config();

/* Kiểm tra widget có được bật không */
if (empty($_aichat_cfg['widget_enabled']) || $_aichat_cfg['widget_enabled'] != '1') {
    return;
}

/* Chống inject nhiều lần */
if (defined('NV_AICHAT_WIDGET_INJECTED')) {
    return;
}
define('NV_AICHAT_WIDGET_INJECTED', true);

$_aichat_ajax_url    = NV_BASE_SITEURL . 'modules/aichat/ajax.php';
$_aichat_widget_html = nv_aichat_render_widget($_aichat_ajax_url, $_aichat_cfg, false);

/*
 * FIX: echo trực tiếp — hoạt động ở mọi khu vực plugin, không phụ thuộc
 * vào việc theme có dùng $my_footer hay không.
 * Plugin nên đặt ở khu vực: "Trước khi website gửi nội dung tới trình duyệt"
 * để HTML được chèn vào cuối <body>.
 */
echo "\n" . $_aichat_widget_html . "\n";

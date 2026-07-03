<?php
/**
 * AI Chat - funcs/main.php
 * Được load khi truy cập GET: index.php?nv=aichat&op=main
 * Không phải AJAX → redirect về trang chủ.
 */
if (! defined('NV_MAINFILE')) {
    exit('Stop!!!');
}
nv_redirect_location(NV_BASE_SITEURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA);

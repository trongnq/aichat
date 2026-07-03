<?php
/**
 * AI Chat - admin/main.php
 * Trang cấu hình + nút BẬT/TẮT widget
 */
if (! defined('NV_IS_MODADMIN')) {
    exit('Stop!!!');
}

if (! function_exists('nv_aichat_get_config')) {
    require_once NV_ROOTDIR . '/modules/' . $module_file . '/functions.php';
}

$page_title = 'Cấu hình AI Chat';
$config     = nv_aichat_get_config();

$openai_models   = array('gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo');
$gemini_models   = array('gemini-2.5-flash-lite', 'gemini-2.5-flash', 'gemini-2.0-flash');
$deepseek_models = array('deepseek-chat', 'deepseek-coder', 'deepseek-reasoner');
$providers_list  = array('openai', 'gemini', 'local_ai', 'deepseek');

// Lấy danh sách module đang active trên site (không bao gồm sys_mods)
$available_modules = array();
if (function_exists('nv_site_mods')) {
    $site_mods_list = nv_site_mods();
    foreach ($site_mods_list as $mod_title => $mod_info) {
        $available_modules[$mod_title] = ! empty($mod_info['custom_title']) ? $mod_info['custom_title'] : $mod_title;
    }
}
// Bổ sung một số module hệ thống thường có search
$sys_search_mods = array(
    'news'     => 'Tin tức',
    'document' => 'Tài liệu',
    'pages'    => 'Trang nội dung',
    'faq'      => 'Hỏi đáp',
    'product'  => 'Sản phẩm',
    'forum'    => 'Diễn đàn',
    'gallery'  => 'Thư viện ảnh',
    'download' => 'Download',
);
foreach ($sys_search_mods as $k => $v) {
    if (! isset($available_modules[$k])) {
        $available_modules[$k] = $v;
    }
}
ksort($available_modules);

// Danh sách module đang được chọn để search
$selected_search_modules = array();
if (! empty($config['search_modules'])) {
    $selected_search_modules = array_map('trim', explode(',', $config['search_modules']));
    $selected_search_modules = array_filter($selected_search_modules);
}

// ── Toggle nhanh qua AJAX ──────────────────────────────────────────────────
if ($nv_Request->isset_request('toggle_widget', 'post')) {
    $new_val = ($nv_Request->get_int('toggle_widget', 'post', 0) == 1) ? '1' : '0';
    nv_aichat_save_config(array('widget_enabled' => $new_val));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => 1, 'widget_enabled' => $new_val));
    exit;
}

// ── Lưu form đầy đủ ───────────────────────────────────────────────────────
if ($nv_Request->isset_request('submit', 'post')) {
    $openai_m      = $nv_Request->get_title('openai_model',      'post', 'gpt-4o-mini');
    $gemini_m      = $nv_Request->get_title('gemini_model',      'post', 'gemini-2.5-flash-lite');
    $deepseek_m    = $nv_Request->get_title('deepseek_model',    'post', 'deepseek-chat');
    $active_p      = $nv_Request->get_title('active_provider',   'post', 'openai');
    $widget_en     = ($nv_Request->get_int('widget_enabled',     'post', 1) == 1) ? '1' : '0';
    $site_search_en = ($nv_Request->get_int('site_search_enabled', 'post', 0) == 1) ? '1' : '0';

    if (! in_array($openai_m,   $openai_models,   true)) $openai_m   = 'gpt-4o-mini';
    if (! in_array($gemini_m,   $gemini_models,   true)) $gemini_m   = 'gemini-2.5-flash-lite';
    if (! in_array($deepseek_m, $deepseek_models, true)) $deepseek_m = 'deepseek-chat';
    if (! in_array($active_p,   $providers_list,  true)) $active_p   = 'openai';

    // Xử lý search_modules từ checkbox array
    $raw_mods = $nv_Request->get_array('search_modules_check', 'post', array());
    $clean_mods = array();
    foreach ($raw_mods as $m) {
        $m = trim(preg_replace('/[^a-z0-9_\-]/', '', strtolower($m)));
        if ($m !== '') $clean_mods[] = $m;
    }
    $search_modules = implode(',', array_unique($clean_mods));
    if (empty($search_modules)) $search_modules = 'news';

    $max_tok  = min(8000, max(100, $nv_Request->get_int('max_tokens', 'post', 2000)));
    $temp     = min(2.0, max(0.0, round($nv_Request->get_float('temperature', 'post', 0.7), 1)));
    $max_res  = min(20, max(1, $nv_Request->get_int('search_max_results', 'post', 5)));

    $save = array(
        'widget_enabled'      => $widget_en,
        'openai_api_key'      => $nv_Request->get_title('openai_api_key',   'post', ''),
        'openai_model'        => $openai_m,
        'gemini_api_key'      => $nv_Request->get_title('gemini_api_key',   'post', ''),
        'gemini_model'        => $gemini_m,
        'local_ai_url'        => $nv_Request->get_title('local_ai_url',     'post', 'http://localhost:11434/api/generate'),
        'local_ai_model'      => $nv_Request->get_title('local_ai_model',   'post', 'llama2'),
        'deepseek_api_key'    => $nv_Request->get_title('deepseek_api_key', 'post', ''),
        'deepseek_model'      => $deepseek_m,
        'active_provider'     => $active_p,
        'max_tokens'          => $max_tok,
        'temperature'         => $temp,
        'system_prompt'       => $nv_Request->get_textarea('system_prompt', 'post', ''),
        'site_search_enabled' => $site_search_en,
        'search_modules'      => $search_modules,
        'search_max_results'  => $max_res,
        'search_suggest_text' => $nv_Request->get_textarea('search_suggest_text', 'post', ''),
    );
    foreach (array('openai_api_key', 'gemini_api_key', 'deepseek_api_key') as $kf) {
        if (empty($save[$kf]) && ! empty($config[$kf])) $save[$kf] = $config[$kf];
    }
    nv_aichat_save_config($save);
    nv_redirect_location(NV_BASE_ADMINURL . 'index.php?'
        . NV_LANG_VARIABLE . '=' . NV_LANG_DATA
        . '&' . NV_NAME_VARIABLE . '=' . $module_name
        . '&' . NV_OP_VARIABLE . '=main&saved=1');
}

$widget_enabled = isset($config['widget_enabled']) ? (int) $config['widget_enabled'] : 1;
$form_action    = NV_BASE_ADMINURL . 'index.php?'
    . NV_LANG_VARIABLE . '=' . NV_LANG_DATA
    . '&' . NV_NAME_VARIABLE . '=' . $module_name
    . '&' . NV_OP_VARIABLE . '=main';

$xtpl = new XTemplate('admin_main.tpl',
    NV_ROOTDIR . '/themes/' . $global_config['module_theme'] . '/modules/' . $module_name);

$xtpl->assign('WIDGET_ENABLED',       $widget_enabled);
$xtpl->assign('SITE_SEARCH_CHECKED',  (! empty($config['site_search_enabled']) && $config['site_search_enabled'] == '1') ? 'checked="checked"' : '');
$xtpl->assign('FORM_ACTION',          $form_action);
$xtpl->assign('TOGGLE_URL',           $form_action);
$xtpl->assign('DATA',                 $config);
$xtpl->assign('OPENAI_KEY_SET',       ! empty($config['openai_api_key'])    ? '(đã cấu hình ✓)' : '(chưa cấu hình)');
$xtpl->assign('GEMINI_KEY_SET',       ! empty($config['gemini_api_key'])    ? '(đã cấu hình ✓)' : '(chưa cấu hình)');
$xtpl->assign('DEEPSEEK_KEY_SET',     ! empty($config['deepseek_api_key'])  ? '(đã cấu hình ✓)' : '(chưa cấu hình)');
$xtpl->assign('SEARCH_MAX_RESULTS',   isset($config['search_max_results']) ? (int) $config['search_max_results'] : 5);

// Provider dropdown
foreach (array('openai' => 'OpenAI (ChatGPT)', 'gemini' => 'Google Gemini', 'local_ai' => 'AI Local (Ollama)', 'deepseek' => 'DeepSeek') as $pv => $pn) {
    $xtpl->assign('PROVIDER_VALUE',    $pv);
    $xtpl->assign('PROVIDER_NAME',     $pn);
    $xtpl->assign('PROVIDER_SELECTED', (isset($config['active_provider']) && $config['active_provider'] === $pv) ? 'selected="selected"' : '');
    $xtpl->parse('main.provider_option');
}

// Model selects
foreach ($openai_models as $m) {
    $xtpl->assign('SELECTED.openai_' . str_replace(array('-', '.'), '_', $m),
        (isset($config['openai_model']) && $config['openai_model'] === $m) ? 'selected="selected"' : '');
}
foreach ($gemini_models as $m) {
    $xtpl->assign('SELECTED.gemini_' . str_replace(array('-', '.'), '_', $m),
        (isset($config['gemini_model']) && $config['gemini_model'] === $m) ? 'selected="selected"' : '');
}
foreach ($deepseek_models as $m) {
    $xtpl->assign('SELECTED.deepseek_' . str_replace(array('-', '.'), '_', $m),
        (isset($config['deepseek_model']) && $config['deepseek_model'] === $m) ? 'selected="selected"' : '');
}

// Search modules checkboxes
foreach ($available_modules as $mod_key => $mod_label) {
    $checked = in_array($mod_key, $selected_search_modules, true) ? 'checked="checked"' : '';
    $xtpl->assign('MOD_KEY',     htmlspecialchars($mod_key,   ENT_QUOTES, 'UTF-8'));
    $xtpl->assign('MOD_LABEL',   htmlspecialchars($mod_label, ENT_QUOTES, 'UTF-8'));
    $xtpl->assign('MOD_CHECKED', $checked);
    $xtpl->parse('main.search_module_item');
}

if ($nv_Request->isset_request('saved', 'get')) {
    $xtpl->parse('main.saved_notice');
}

$xtpl->parse('main');
$contents = $xtpl->text('main');

include NV_ROOTDIR . '/includes/header.php';
echo nv_admin_theme($contents);
include NV_ROOTDIR . '/includes/footer.php';

<?php
/**
 * AI Chat - ajax.php
 * =====================================================================
 * AJAX endpoint độc lập — KHÔNG load mainfile.php của NukeViet
 * vì mainfile.php render toàn bộ trang HTML rồi exit().
 *
 * Chỉ load: includes/config.php (DB credentials) + PDO connection
 * sau đó tự detect tên bảng và gọi hàm AI.
 * =====================================================================
 */

// Chỉ nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Method Not Allowed'));
    exit;
}

// Validate sớm trước khi load bất cứ thứ gì
$action  = isset($_POST['nv_aichat_action']) ? trim($_POST['nv_aichat_action']) : '';
$message = isset($_POST['message'])          ? trim($_POST['message'])          : '';

if ($action !== 'send') {
    _aichat_json(array('error' => 'Yêu cầu không hợp lệ'));
}
if ($message === '') {
    _aichat_json(array('error' => 'Tin nhắn không được để trống'));
}
if (mb_strlen($message, 'UTF-8') > 4000) {
    _aichat_json(array('error' => 'Tin nhắn quá dài (tối đa 4000 ký tự)'));
}

// ── Root NukeViet ─────────────────────────────────────────────────────────
// File này: {root}/modules/aichat/ajax.php → lên 2 cấp = root
$nv_root = realpath(dirname(__FILE__) . '/../..');

// ── Load DB config của NukeViet ───────────────────────────────────────────
// NukeViet 4 lưu credentials tại includes/config.php (single site)
// hoặc data/{sitename}/config.php (multi-site)
// File đó chỉ assign $db_config array, không execute gì thêm
$db_config = array();
$nv_config_file = '';

$config_candidates = array();
$config_candidates[] = $nv_root . '/config.php';
$config_candidates[] = $nv_root . '/includes/config.php';
$config_candidates[] = $nv_root . '/data/config/config_global.php';

// Multi-site: tìm trong data/*/config.php
$data_dir = $nv_root . '/data';
if (is_dir($data_dir)) {
    foreach (glob($data_dir . '/*/config.php') as $cand) {
        $config_candidates[] = $cand;
    }
    foreach (glob($data_dir . '/config/config_ini.*.php') as $cand) {
        $config_candidates[] = $cand;
    }
    // Một số bản multi-site đặt trực tiếp data/config.php
    $config_candidates[] = $data_dir . '/config.php';
}

foreach ($config_candidates as $cand) {
    if (file_exists($cand)) {
        $nv_config_file = $cand;
        break;
    }
}

if (empty($nv_config_file)) {
    _aichat_json(array('error' => 'Không tìm thấy file config.php (đã thử: ' . implode(', ', $config_candidates) . ')'));
}

// config.php có guard "if (!defined('NV_MAINFILE')) exit('Stop!!!')"
// nên cần define trước khi require
if (! defined('NV_MAINFILE')) {
    define('NV_MAINFILE', true);
}
require $nv_config_file;

// NukeViet 4 (config.php gốc): dbhost, dbuname, dbpass, dbname, dbport, prefix, charset
// Fallback cho các biến thể tên key khác nhau giữa các version
$db_host   = _cfg($db_config, array('dbhost','host'),                'localhost');
$db_user   = _cfg($db_config, array('dbuname','user','username'),   '');
$db_pass   = _cfg($db_config, array('dbpass','pass','password'),    '');
$db_name   = _cfg($db_config, array('dbname','name','db'),          '');
$db_prefix = _cfg($db_config, array('prefix'),                       'nv4');
$db_port   = _cfg($db_config, array('dbport','port'),                '3306');
$db_char   = _cfg($db_config, array('charset','char'),               'utf8mb4');

if (empty($db_port)) $db_port = '3306';

if (empty($db_name)) {
    _aichat_json(array('error' => 'Thiếu thông tin database trong config.php'));
}

// ── Kết nối PDO ───────────────────────────────────────────────────────────
try {
    $charset_pdo = (stripos($db_char, 'utf8mb4') !== false) ? 'utf8mb4' : 'utf8';
    $dsn = 'mysql:host=' . $db_host
         . ';port=' . (int)$db_port
         . ';dbname=' . $db_name
         . ';charset=' . $charset_pdo;
    $db = new PDO($dsn, $db_user, $db_pass, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$charset_pdo}'",
    ));
} catch (PDOException $e) {
    _aichat_json(array('error' => 'Lỗi kết nối DB: ' . $e->getMessage()));
}

// ── Tự động detect NV_PREFIXLANG ─────────────────────────────────────────
// NV_PREFIXLANG = db_prefix + '_' + lang  (ví dụ: nv4_vi)
// Tìm tên bảng aichat_config thực tế bằng cách SHOW TABLES LIKE
// để không phụ thuộc vào việc biết lang là gì
$nv_prefixlang = '';
try {
    $stmt = $db->prepare("SHOW TABLES LIKE ?");
    $stmt->execute(array($db_prefix . '\\_%\\_aichat\\_config'));
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (! empty($tables)) {
        // Lấy bảng đầu tiên tìm được, bỏ phần '_aichat_config' ở cuối
        $tbl = $tables[0];
        $nv_prefixlang = substr($tbl, 0, strlen($tbl) - strlen('_aichat_config'));
    }
} catch (Exception $e) {}

// Nếu không tìm được qua SHOW TABLES, thử các giá trị phổ biến
if (empty($nv_prefixlang)) {
    $candidates = array(
        $db_prefix . '_vi',
        $db_prefix . '_en',
        $db_prefix . '_default',
        $db_prefix,
    );
    foreach ($candidates as $cand) {
        try {
            $chk = $db->query("SELECT 1 FROM `{$cand}_aichat_config` LIMIT 1");
            if ($chk) {
                $nv_prefixlang = $cand;
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }
}

if (empty($nv_prefixlang)) {
    _aichat_json(array('error' => 'Không tìm thấy bảng cấu hình AI Chat. Vui lòng chạy "Thiết lập module" trong Admin.'));
}

// ── Define constants tối thiểu ────────────────────────────────────────────
if (! defined('NV_PREFIXLANG'))   define('NV_PREFIXLANG',  $nv_prefixlang);
if (! defined('NV_CURRENTTIME'))  define('NV_CURRENTTIME', time());
if (! defined('NV_MAINFILE'))     define('NV_MAINFILE',    true);

// NV_LANG_DATA: phần ngôn ngữ trong NV_PREFIXLANG (vd: nv4_vi → vi)
if (! defined('NV_LANG_DATA')) {
    $lang_part = 'vi';
    $pl_parts  = explode('_', $nv_prefixlang);
    if (count($pl_parts) >= 2) {
        $lang_part = end($pl_parts);
    }
    define('NV_LANG_DATA', $lang_part);
}

// NV_BASE_SITEURL: URL gốc của site, dùng để build link bài viết
if (! defined('NV_BASE_SITEURL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    define('NV_BASE_SITEURL', $host !== '' ? ($scheme . '://' . $host . '/') : '');
}

// $global_config tối thiểu (rewrite_exturl) — lấy từ bảng config chính nếu có
if (! isset($GLOBALS['global_config'])) {
    $GLOBALS['global_config'] = array('rewrite_enable' => 0, 'rewrite_exturl' => '.html');
    try {
        $cfg_tbl = $db_prefix . '_config';
        $chk = $db->query("SHOW TABLES LIKE '" . $cfg_tbl . "'");
        if ($chk && $chk->rowCount() > 0) {
            $sth = $db->query("SELECT config_name, config_value FROM `{$cfg_tbl}` WHERE config_name IN ('rewrite_exturl','rewrite_enable','rewrite_optional')");
            while ($r = $sth->fetch()) {
                $GLOBALS['global_config'][$r['config_name']] = $r['config_value'];
            }
        }
    } catch (Exception $e) {}
}

// ── Load functions của module ─────────────────────────────────────────────
$func_file = $nv_root . '/modules/aichat/functions.php';
if (! file_exists($func_file)) {
    _aichat_json(array('error' => 'Không tìm thấy functions.php'));
}
require_once $func_file;

// ── Lấy cấu hình + provider ───────────────────────────────────────────────
$config        = nv_aichat_get_config();
$provider_post = isset($_POST['provider']) ? trim($_POST['provider']) : '';
$valid         = array('openai', 'gemini', 'local_ai', 'deepseek');
$provider      = in_array($provider_post, $valid, true)
    ? $provider_post
    : (isset($config['active_provider']) ? $config['active_provider'] : 'openai');

// ── Session ID (resolve sớm để lấy lịch sử hội thoại) ─────────────────────
$session_id = isset($_POST['session_id']) ? trim($_POST['session_id']) : '';
if ($session_id === '' && isset($_COOKIE['nv_aichat_sid'])) {
    $session_id = trim($_COOKIE['nv_aichat_sid']);
}
if ($session_id === '') {
    $session_id = bin2hex(random_bytes(16));
    setcookie('nv_aichat_sid', $session_id, time() + 86400 * 30, '/');
}
$session_id = substr(preg_replace('/[^a-zA-Z0-9]/', '', $session_id), 0, 100);
$userid = 0;

// ── Lịch sử hội thoại gần đây (giúp AI hiểu ngữ cảnh "bài đó", "tóm tắt bài trên"...) ──
$history = array();
try {
    $history = nv_aichat_get_history($userid, $session_id, 4);
} catch (Exception $e) {
    $history = array();
}

// ── Site search context ───────────────────────────────────────────────────
$site_context = '';
if (! empty($config['site_search_enabled']) && $config['site_search_enabled'] == '1') {
    $search_query = $message;

    // Phát hiện câu hỏi follow-up (tóm tắt / nội dung / bài đó / chi tiết...)
    // Nếu là follow-up, lấy thêm từ khóa từ lịch sử để search đúng bài
    $follow_up_kw = array(
        'tóm tắt', 'tom tat', 'tóm lại', 'tom lai',
        'bài đó', 'bai do', 'bài trên', 'bai tren', 'bài đấy', 'bai day',
        'bài viết đó', 'bài viết trên', 'nội dung bài', 'noi dung bai',
        'nội dung chính', 'noi dung chinh', 'chi tiết bài', 'chi tiet bai',
        'ý chính', 'y chinh', 'điểm chính', 'diem chinh',
        'toàn bộ nội dung', 'toan bo noi dung', 'đọc bài', 'doc bai',
        'phân tích bài', 'phan tich bai', 'giải thích bài', 'giai thich bai',
    );
    $is_follow_up = false;
    $msg_lower = mb_strtolower($message, 'UTF-8');
    foreach ($follow_up_kw as $kw) {
        if (mb_strpos($msg_lower, $kw, 0, 'UTF-8') !== false) { $is_follow_up = true; break; }
    }

    // Đếm số từ "có nghĩa" trong message hiện tại (loại bỏ các từ follow-up keyword)
    // Nếu message đã có đủ từ khóa cụ thể (vd nhắc tên bài), không cần ghép history
    $msg_without_kw = $msg_lower;
    foreach ($follow_up_kw as $kw) {
        $msg_without_kw = str_replace($kw, '', $msg_without_kw);
    }
    $meaningful_words = array_filter(
        preg_split('/\s+/', trim($msg_without_kw)),
        function($w) { return mb_strlen($w, 'UTF-8') >= 3; }
    );
    // Message đã tự đủ thông tin (>= 3 từ có nghĩa, vd nhắc tên/chủ đề bài) → KHÔNG ghép history
    $message_self_sufficient = (count($meaningful_words) >= 3);

    if ($is_follow_up && !empty($history) && !$message_self_sufficient) {
        // Lấy tối đa 3 tin nhắn gần nhất của user để trích từ khóa
        $prev_msgs = array();
        $hist_rev  = array_reverse($history);
        foreach ($hist_rev as $h) {
            if (!empty($h['message'])) {
                $prev_msgs[] = $h['message'];
                if (count($prev_msgs) >= 3) break;
            }
        }
        if (!empty($prev_msgs)) {
            // Ghép từ khóa từ lịch sử + tin nhắn hiện tại để tìm đúng bài
            $search_query = implode(' ', array_reverse($prev_msgs)) . ' ' . $message;
        }
    }

    $site_context = nv_aichat_search_site($search_query, $config);
}

// ── Gọi AI ────────────────────────────────────────────────────────────────
switch ($provider) {
    case 'openai':   $resp = nv_aichat_call_openai($message, $config, $site_context, $history);   break;
    case 'gemini':   $resp = nv_aichat_call_gemini($message, $config, $site_context, $history);   break;
    case 'local_ai': $resp = nv_aichat_call_local($message, $config, $site_context, $history);    break;
    case 'deepseek': $resp = nv_aichat_call_deepseek($message, $config, $site_context, $history); break;
    default:         $resp = array('error' => 'Provider không hợp lệ');
}

// ── Thay placeholder [XEM_BAI_CHINH] bằng URL thật của bài chính (nếu có) ──
// Tránh để AI tự viết/đoán URL (dễ sai/hallucinate)
if (! empty($resp['response']) && strpos($resp['response'], '[XEM_BAI_CHINH]') !== false) {
    $primary_url = '';
    if (!empty($GLOBALS['nv_aichat_url_map'])) {
        foreach ($GLOBALS['nv_aichat_url_map'] as $a) {
            if (!empty($a['primary']) && !empty($a['url'])) { $primary_url = $a['url']; break; }
        }
    }
    if ($primary_url !== '') {
        $resp['response'] = str_replace('[XEM_BAI_CHINH]', $primary_url, $resp['response']);
    } else {
        // Không có URL → xóa cả dòng chứa placeholder để tránh hiển thị rác
        $resp['response'] = preg_replace('/\R?[^\R]*\[XEM_BAI_CHINH\][^\R]*/u', '', $resp['response']);
        $resp['response'] = trim($resp['response']);
    }
}

// ── Lưu lịch sử chat (nếu có phản hồi thành công) ──────────────────────────
if (! empty($resp['response'])) {
    $model_used = '';
    switch ($provider) {
        case 'openai':   $model_used = isset($config['openai_model'])   ? $config['openai_model']   : ''; break;
        case 'gemini':   $model_used = isset($config['gemini_model'])   ? $config['gemini_model']   : ''; break;
        case 'local_ai': $model_used = isset($config['local_ai_model']) ? $config['local_ai_model'] : ''; break;
        case 'deepseek': $model_used = isset($config['deepseek_model']) ? $config['deepseek_model'] : ''; break;
    }

    try {
        nv_aichat_save_history($userid, $session_id, $message, $resp['response'], $provider, $model_used);
    } catch (Exception $e) {
        // Không chặn phản hồi nếu lưu lịch sử lỗi
    }

    $resp['session_id'] = $session_id;

    // Đính kèm danh sách bài viết liên quan (title + url) để frontend render link
    if (!empty($GLOBALS['nv_aichat_url_map'])) {
        $resp['articles'] = $GLOBALS['nv_aichat_url_map'];
    }
}

_aichat_json($resp);

// ═════════════════════════════════════════════════════════════════════════
// HELPERS
// ═════════════════════════════════════════════════════════════════════════

/** Đọc giá trị từ array với nhiều key fallback */
function _cfg(array $arr, array $keys, $default = '')
{
    foreach ($keys as $k) {
        if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
    }
    return $default;
}

/** Trả JSON và exit */
function _aichat_json(array $data)
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

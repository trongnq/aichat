<?php
/**
 * AI Chat - functions.php
 * Hàm dùng chung cho cả frontend, admin và plugin hooks
 */
if (! defined('NV_MAINFILE')) {
    exit('Stop!!!');
}

if (! defined('NV_IS_MOD_AICHAT')) {
    define('NV_IS_MOD_AICHAT', true);
}

/* ══════════════════════════════════════════════════════════════════════
   CẤU HÌNH
══════════════════════════════════════════════════════════════════════ */

function nv_aichat_get_config()
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    global $db;
    $cfg = array();
    try {
        $res = $db->query('SELECT config_name, config_value FROM ' . NV_PREFIXLANG . '_aichat_config');
        while ($r = $res->fetch()) $cfg[$r['config_name']] = $r['config_value'];
    } catch (Exception $e) {}
    return $cfg;
}

function nv_aichat_save_config(array $data)
{
    global $db;
    if (empty($data)) return false;
    $sql = 'INSERT INTO ' . NV_PREFIXLANG . '_aichat_config (config_name,config_value,create_time)
            VALUES (:k,:v,' . NV_CURRENTTIME . ')
            ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';
    $sth = $db->prepare($sql);
    foreach ($data as $k => $v) {
        $sth->bindValue(':k', (string)$k);
        $sth->bindValue(':v', (string)$v);
        $sth->execute();
    }
    return true;
}

/* ══════════════════════════════════════════════════════════════════════
   LỊCH SỬ CHAT
══════════════════════════════════════════════════════════════════════ */

function nv_aichat_save_history($userid, $session_id, $message, $response, $provider, $model)
{
    global $db;
    $sql = 'INSERT INTO ' . NV_PREFIXLANG . '_aichat_chat
                (userid,session_id,message,response,provider,model,create_time)
            VALUES (:uid,:sid,:msg,:resp,:prov,:mdl,' . NV_CURRENTTIME . ')';
    $sth = $db->prepare($sql);
    $sth->bindValue(':uid',  (int)$userid);
    $sth->bindValue(':sid',  (string)$session_id);
    $sth->bindValue(':msg',  (string)$message);
    $sth->bindValue(':resp', (string)$response);
    $sth->bindValue(':prov', (string)$provider);
    $sth->bindValue(':mdl',  (string)$model);
    $sth->execute();
}

function nv_aichat_get_history($userid, $session_id, $limit = 10)
{
    global $db;
    $limit = max(1, min(100, (int)$limit));
    $sql = 'SELECT * FROM ' . NV_PREFIXLANG . '_aichat_chat
            WHERE userid=:uid AND session_id=:sid
            ORDER BY id DESC LIMIT ' . $limit;
    $sth = $db->prepare($sql);
    $sth->bindValue(':uid', (int)$userid);
    $sth->bindValue(':sid', (string)$session_id);
    $sth->execute();
    return array_reverse($sth->fetchAll());
}

/* ══════════════════════════════════════════════════════════════════════
   TÌM KIẾM NỘI DUNG SITE → CONTEXT CHO AI
══════════════════════════════════════════════════════════════════════ */

function nv_aichat_search_site($query, $config)
{
    global $db;

    // ── Static cache: tránh SHOW TABLES/COLUMNS lặp lại trong cùng 1 request ──
    static $schema_cache = array(); // key: "tbl:col" → true/false

    $max_results = !empty($config['search_max_results']) ? (int)$config['search_max_results'] : 5;
    $max_results = max(1, min(20, $max_results));

    $keywords = array_filter(
        preg_split('/\s+/', mb_strtolower(trim($query), 'UTF-8')),
        function($w) { return mb_strlen($w, 'UTF-8') >= 2; }
    );
    if (empty($keywords)) return '';

    $prefix  = NV_PREFIXLANG;
    $results = array();

    // Helper: check table tồn tại (có cache)
    $tbl_exists = function($tbl) use ($db, &$schema_cache) {
        $key = 'tbl:' . $tbl;
        if (!isset($schema_cache[$key])) {
            try {
                $r = $db->query("SHOW TABLES LIKE '" . $tbl . "'");
                $schema_cache[$key] = ($r && $r->rowCount() > 0);
            } catch (Exception $e) { $schema_cache[$key] = false; }
        }
        return $schema_cache[$key];
    };

    // Helper: check column tồn tại (có cache)
    $col_exists = function($tbl, $col) use ($db, &$schema_cache) {
        $key = 'col:' . $tbl . '.' . $col;
        if (!isset($schema_cache[$key])) {
            try {
                $r = $db->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'");
                $schema_cache[$key] = ($r && $r->rowCount() > 0);
            } catch (Exception $e) { $schema_cache[$key] = false; }
        }
        return $schema_cache[$key];
    };

    /*
     * search_tables: array(tbl_suffix, col_title, col_intro, col_full, col_alias, mod_name)
     * col_intro = cột tóm tắt ngắn (hometext / introtext)
     * col_full  = cột nội dung đầy đủ (bodytext / fulltext / content)
     *             để trống ('') nếu bảng không có cột riêng cho full content
     *
     * NukeViet news_rows: title | hometext (intro) | bodytext (full)
     * NukeViet document_rows: title | introtext | fulltext
     * NukeViet pages_rows: title | content (không có cột riêng cho intro)
     * NukeViet faq_rows: question | answer (thường là full)
     */
    $search_tables = array(
        array('news_rows',     'title', 'hometext',  'bodytext',  'alias', 'news'),
        array('document_rows', 'title', 'introtext', 'fulltext',  'alias', 'document'),
        array('pages_rows',    'title', 'content',   '',          'alias', 'pages'),
        array('faq_rows',      'question', 'answer', '',          'alias', 'faq'),
    );

    $allowed_modules = array();
    if (!empty($config['search_modules'])) {
        $allowed_modules = array_map('trim', explode(',', $config['search_modules']));
    }

    foreach ($search_tables as $tbl_def) {
        list($tbl_suffix, $col_title, $col_intro, $col_full, $col_alias, $mod_name) = $tbl_def;

        if (!empty($allowed_modules) && !in_array($mod_name, $allowed_modules)) continue;

        $tbl = $prefix . '_' . $tbl_suffix;

        if (!$tbl_exists($tbl)) continue;

        $has_full_col = (!empty($col_full) && $col_exists($tbl, $col_full));

        $conditions_and = array();
        $conditions_or  = array();
        $params         = array();
        foreach ($keywords as $i => $kw) {
            $pk = ':kw_' . $tbl_suffix . '_' . $i;
            $cond = '(' . $col_title . ' LIKE ' . $pk . ' OR ' . $col_intro . ' LIKE ' . $pk . ')';
            $conditions_and[] = $cond;
            $conditions_or[]  = $cond;
            $params[$pk]      = '%' . $kw . '%';
        }

        if (empty($conditions_and)) continue;

        // Ưu tiên AND (tất cả từ khóa đều khớp) → kết quả liên quan hơn
        // Fallback sang OR nếu AND không ra kết quả
        $where_and = '(' . implode(' AND ', $conditions_and) . ')';
        $where_or  = '(' . implode(' OR ',  $conditions_or)  . ')';

        $status_cond   = $col_exists($tbl, 'status') ? ' AND status = 1' : '';
        $has_id_col    = $col_exists($tbl, 'id');
        $has_catid_col = $col_exists($tbl, 'catid');

        /* SELECT cả intro lẫn full content nếu có */
        $select_cols = $col_title . ' AS title, ' . $col_intro . ' AS intro';
        if ($has_full_col) {
            $select_cols .= ', ' . $col_full . ' AS full_body';
        }
        $select_cols .= (($col_alias !== $col_title) ? ', ' . $col_alias . ' AS alias' : ', "" AS alias');
        if ($has_id_col)    $select_cols .= ', id AS row_id';
        if ($has_catid_col) $select_cols .= ', catid AS row_catid';

        $sql_and = 'SELECT ' . $select_cols
                 . ' FROM `' . $tbl . '`'
                 . ' WHERE ' . $where_and
                 . $status_cond
                 . ' ORDER BY ' . $col_title . ' LIMIT ' . $max_results;

        $sql_or  = 'SELECT ' . $select_cols
                 . ' FROM `' . $tbl . '`'
                 . ' WHERE ' . $where_or
                 . $status_cond
                 . ' ORDER BY ' . $col_title . ' LIMIT ' . $max_results;

        $match_quality = 'and'; // 'and' = khớp chính xác tất cả từ khóa, 'or' = chỉ khớp 1 phần (kém tin cậy hơn)

        try {
            // Thử AND trước
            $sth = $db->prepare($sql_and);
            foreach ($params as $pk => $pv) $sth->bindValue($pk, $pv);
            $sth->execute();
            $rows = $sth->fetchAll();

            // Nếu AND không ra kết quả VÀ có ít nhất 2 từ khóa, thử OR (nhưng chỉ lấy bài có title khớp ít nhất 1 từ dài >= 3 ký tự)
            if (empty($rows) && count($keywords) > 1) {
                $long_kw = array_filter($keywords, function($w){ return mb_strlen($w,'UTF-8') >= 3; });
                if (!empty($long_kw)) {
                    $sth = $db->prepare($sql_or);
                    foreach ($params as $pk => $pv) $sth->bindValue($pk, $pv);
                    $sth->execute();
                    $rows = $sth->fetchAll();
                    $match_quality = 'or';
                }
            }

            foreach ($rows as $row) {
                $title = strip_tags($row['title']);

                /* Ưu tiên full_body nếu có, fallback về intro */
                $intro_text = strip_tags(isset($row['intro'])     ? $row['intro']     : '');
                $full_text  = strip_tags(isset($row['full_body'])  ? $row['full_body'] : '');

                /* Nối intro + full để có nội dung đầy đủ nhất */
                if (!empty($full_text)) {
                    $full_combined = $full_text; /* full_body thường đã chứa cả intro */
                    /* Nếu intro không có trong full_body, ghép thêm vào đầu */
                    if (!empty($intro_text) && mb_strpos($full_text, mb_substr($intro_text, 0, 50, 'UTF-8'), 0, 'UTF-8') === false) {
                        $full_combined = $intro_text . "\n\n" . $full_text;
                    }
                } else {
                    $full_combined = $intro_text;
                }

                /*
                 * Xác định bài "primary" (lấy full content):
                 * Ưu tiên bài có tiêu đề khớp gần nhất với query của người dùng.
                 * Logic: nếu query chứa ít nhất 60% từ trong tiêu đề bài (hoặc ngược lại)
                 *        → coi đây là bài được hỏi trực tiếp → primary.
                 * Nếu không có bài nào khớp tốt → bài đầu tiên trong kết quả là primary.
                 */
                $query_words = array_filter(
                    preg_split('/\s+/', mb_strtolower(trim($query), 'UTF-8')),
                    function($w) { return mb_strlen($w, 'UTF-8') >= 2; }
                );
                $title_words = array_filter(
                    preg_split('/\s+/', mb_strtolower(trim($title), 'UTF-8')),
                    function($w) { return mb_strlen($w, 'UTF-8') >= 2; }
                );
                $match_count = 0;
                foreach ($query_words as $qw) {
                    foreach ($title_words as $tw) {
                        if (mb_strpos($tw, $qw, 0, 'UTF-8') !== false || mb_strpos($qw, $tw, 0, 'UTF-8') !== false) {
                            $match_count++;
                            break;
                        }
                    }
                }
                $title_word_count = max(1, count($title_words));
                $query_word_count = max(1, count($query_words));
                $match_ratio = $match_count / min($title_word_count, $query_word_count);

                /* Bài khớp trực tiếp (≥50% từ trùng) → luôn là primary, dù không phải đầu tiên */
                $is_title_match = ($match_ratio >= 0.5);

                /* Nếu đã có primary từ bài trước, bài này không thể là primary nữa
                   trừ khi nó khớp tiêu đề tốt hơn (title match) */
                $already_has_primary = false;
                foreach ($results as $prev) {
                    if ($prev['primary']) { $already_has_primary = true; break; }
                }

                if ($is_title_match && !$already_has_primary) {
                    $is_primary = true;
                } elseif ($is_title_match && $already_has_primary) {
                    /* Có thể override primary nếu bài này khớp tốt hơn bài đang là primary */
                    $is_primary = true;
                    /* Hạ cấp primary cũ về TRÍCH ĐOẠN */
                    foreach ($results as &$prev_r) {
                        if ($prev_r['primary']) {
                            $prev_r['primary'] = false;
                            $prev_r['label']   = 'TRÍCH ĐOẠN';
                            /* Cắt lại body về 400 ký tự */
                            if (mb_strlen($prev_r['body'], 'UTF-8') > 400) {
                                $prev_r['body'] = mb_substr($prev_r['body'], 0, 400, 'UTF-8') . '…';
                            }
                            break;
                        }
                    }
                    unset($prev_r);
                } else {
                    $is_primary = empty($results); /* fallback: bài đầu tiên */
                }
                if ($is_primary) {
                    $body = $full_combined;
                    if (mb_strlen($body, 'UTF-8') > 8000) {
                        $body = mb_substr($body, 0, 8000, 'UTF-8') . '…';
                    }
                } else {
                    $body = $intro_text;
                    if (mb_strlen($body, 'UTF-8') > 400) {
                        $body = mb_substr($body, 0, 400, 'UTF-8') . '…';
                    }
                }

                /* Xây dựng URL bài viết theo cấu trúc rewrite chuẩn của NukeViet 4:
                 * {site}/{lang}/{module}/{cat_alias}/{article_alias}-{id}{rewrite_exturl}
                 * (lang prefix chỉ xuất hiện khi rewrite_optional = 0)
                 */
                $alias_val = isset($row['alias']) ? trim($row['alias']) : '';
                $row_id    = isset($row['row_id']) ? (int)$row['row_id'] : 0;
                $row_catid = isset($row['row_catid']) ? (int)$row['row_catid'] : 0;
                $article_url = '';

                if (!empty($alias_val) && $row_id > 0) {
                    $cat_alias = nv_aichat_get_cat_alias($mod_name, $tbl_suffix, $row_catid);
                    $article_url = nv_aichat_build_article_url($mod_name, $cat_alias, $alias_val, $row_id);
                }

                $results[] = array(
                    'module'        => $mod_name,
                    'title'         => $title,
                    'body'          => $body,
                    'url'           => $article_url,
                    'primary'       => $is_primary,
                    'match_quality' => $match_quality,
                );

                if (count($results) >= $max_results) break 2;
            }
        } catch (Exception $e) { continue; }
    }

    if (empty($results)) return '';

    $context_lines = array();
    $url_map       = array(); /* Dùng để trả về danh sách link cho frontend */
    foreach ($results as $r) {
        $label = $r['primary'] ? 'NỘI DUNG ĐẦY ĐỦ' : 'TRÍCH ĐOẠN';
        $context_lines[] = '[NGUỒN: ' . strtoupper($r['module']) . ' | LOẠI: ' . $label . ']'
            . "\nTiêu đề bài viết: " . $r['title']
            . "\nNội dung:\n" . $r['body'];

        // Chỉ hiển thị "Bài viết liên quan" nếu: là bài chính (primary)
        // HOẶC khớp ĐẦY ĐỦ tất cả từ khóa (match_quality='and') → tránh hiện bài không liên quan
        // (kết quả từ OR fallback chỉ dùng làm ngữ cảnh phụ cho AI, không hiện ra ngoài UI)
        $show_as_related = $r['primary'] || (isset($r['match_quality']) && $r['match_quality'] === 'and');
        if (!empty($r['url']) && $show_as_related) {
            $url_map[] = array('title' => $r['title'], 'url' => $r['url'], 'primary' => $r['primary']);
        }
    }

    /* Lưu url_map vào global để ajax.php có thể lấy gửi về frontend */
    $GLOBALS['nv_aichat_url_map'] = $url_map;

    return "Dưới đây là nội dung từ website liên quan đến câu hỏi của người dùng:\n\n"
         . implode("\n\n", $context_lines)
         . "\n\n---\n"
         . "HƯỚNG DẪN BẮT BUỘC CHO AI — ĐỌC KỸ TRƯỚC KHI TRẢ LỜI:\n"
         . "0. TUYỆT ĐỐI KHÔNG sao chép các nhãn kỹ thuật như '[NGUỒN: ...]', 'LOẠI: NỘI DUNG ĐẦY ĐỦ', 'LOẠI: TRÍCH ĐOẠN', 'Tiêu đề bài viết:', 'Nội dung:' vào câu trả lời. Đây CHỈ LÀ thông tin nội bộ giúp bạn hiểu ngữ cảnh, KHÔNG phải nội dung để hiển thị cho người dùng. Khi nhắc tên bài viết, chỉ viết tên bài viết thuần (in đậm hoặc trong dấu ngoặc kép), không kèm nhãn phía sau.\n"
         . "1. Phần có LOẠI: NỘI DUNG ĐẦY ĐỦ: đây là toàn bộ nội dung bài viết. Khi người dùng yêu cầu tóm tắt, hãy tóm tắt ĐẦY ĐỦ VÀ CHI TIẾT, không chỉ lặp lại câu mở đầu. Tóm tắt cần có: (a) sự kiện/vấn đề chính, (b) các số liệu, mốc thời gian, tên đơn vị/cá nhân quan trọng được nêu trong bài, (c) kết quả hoặc ý nghĩa của sự kiện. Trình bày súc tích nhưng đầy đủ trọng điểm, có thể dùng vài câu hoặc gạch đầu dòng ngắn nếu bài có nhiều nội dung.\n"
         . "2. Phần có LOẠI: TRÍCH ĐOẠN: đây CHỈ LÀ đoạn giới thiệu ngắn (vài trăm ký tự), KHÔNG phải nội dung đầy đủ.\n"
         . "   ⚠️ NGHIÊM CẤM: Nếu người dùng yêu cầu tóm tắt một bài cụ thể mà bài đó chỉ có LOẠI: TRÍCH ĐOẠN, TUYỆT ĐỐI KHÔNG được bịa đặt hoặc suy diễn thêm nội dung. Thay vào đó hãy thông báo rõ (theo ngôn ngữ người dùng đang dùng) rằng hệ thống chỉ có đoạn giới thiệu ngắn, chưa đủ để tóm tắt chi tiết, và đề nghị đọc bài đầy đủ qua link bên dưới.\n"
         . "3. Sau khi tóm tắt hoặc trả lời, nếu có bài viết LOẠI: NỘI DUNG ĐẦY ĐỦ liên quan, hãy kết thúc bằng dòng riêng theo đúng ngôn ngữ người dùng đang dùng (ví dụ: 'Đọc bài đầy đủ tại: [XEM_BAI_CHINH]' nếu tiếng Việt, 'Read the full article here: [XEM_BAI_CHINH]' nếu tiếng Anh, v.v.) — TUYỆT ĐỐI giữ nguyên cụm [XEM_BAI_CHINH], KHÔNG tự viết hoặc đoán URL, KHÔNG dịch hoặc thay đổi cụm [XEM_BAI_CHINH] này.\n"
         . "4. Nếu không có nội dung nào liên quan đến câu hỏi, hãy nói rõ (theo ngôn ngữ người dùng) và có thể trả lời dựa trên kiến thức chung.\n"
         . "5. Tiêu đề bài viết trên website là tiếng Việt — nếu người dùng hỏi bằng ngôn ngữ khác, hãy DỊCH tiêu đề và nội dung sang ngôn ngữ của người dùng khi trả lời, chỉ giữ nguyên tên riêng (người, đơn vị, địa danh).";
}

/* ══════════════════════════════════════════════════════════════════════
   URL BÀI VIẾT — Theo cấu trúc rewrite chuẩn NukeViet 4
   Pattern (rewrite_enable=1, rewrite_optional=0, rewrite_exturl='.html'):
   {site_url}/vi/{module}/{cat_alias}/{article_alias}-{id}.html
   Map: NV_NAME_VARIABLE='nv', NV_OP_VARIABLE='op', NV_LANG_VARIABLE='language'
══════════════════════════════════════════════════════════════════════ */

/**
 * Lấy alias chuyên mục (catid) từ bảng *_{module}_cat, có cache trong request
 */
function nv_aichat_get_cat_alias($mod_name, $tbl_suffix, $catid)
{
    static $cache     = array();
    static $tbl_check = array(); // cache SHOW TABLES riêng cho hàm này

    if ($catid <= 0) return '';
    $cache_key = $mod_name . '_' . $catid;
    if (isset($cache[$cache_key])) return $cache[$cache_key];

    global $db;
    $cat_tbl = NV_PREFIXLANG . '_' . $mod_name . '_cat';
    $alias   = '';

    try {
        if (!isset($tbl_check[$cat_tbl])) {
            $r = $db->query("SHOW TABLES LIKE '" . $cat_tbl . "'");
            $tbl_check[$cat_tbl] = ($r && $r->rowCount() > 0);
        }
        if ($tbl_check[$cat_tbl]) {
            $sth = $db->prepare('SELECT alias FROM `' . $cat_tbl . '` WHERE catid = :catid LIMIT 1');
            $sth->bindValue(':catid', (int)$catid);
            $sth->execute();
            $row = $sth->fetch();
            if ($row && isset($row['alias'])) $alias = trim($row['alias']);
        }
    } catch (Exception $e) {}

    $cache[$cache_key] = $alias;
    return $alias;
}

/**
 * Xây URL bài viết đã rewrite, theo đúng convention NukeViet 4 quan sát từ
 * modules/news/blocks/*.php:
 *   $link = NV_BASE_SITEURL . 'index.php?language=vi&nv={module}&op={cat_alias}/{alias}-{id}{ext}';
 *   $link = nv_url_rewrite($link, true);
 *
 * Với rewrite_enable=1 và rewrite_optional=0 (lang luôn có trong URL):
 *   {site}/vi/{module}/{cat_alias}/{alias}-{id}{ext}
 *
 * Nếu nv_url_rewrite() có sẵn (đã load mainfile.php), dùng trực tiếp để đảm bảo
 * chính xác 100% theo cấu hình thực tế (rewrite_optional, rewrite_op_mod...).
 * Nếu không, fallback sang xây thủ công theo pattern đã xác minh.
 */
function nv_aichat_build_article_url($mod_name, $cat_alias, $alias, $id)
{
    $ext = '.html';
    if (! empty($GLOBALS['global_config']['rewrite_exturl'])) {
        $ext = $GLOBALS['global_config']['rewrite_exturl'];
    }

    $op = $alias . '-' . (int)$id . $ext;
    if (!empty($cat_alias)) {
        $op = $cat_alias . '/' . $op;
    }

    $site_url = '';
    if (defined('NV_BASE_SITEURL')) {
        $site_url = NV_BASE_SITEURL;
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $site_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/';
    }

    $raw_url = $site_url . 'index.php?language=' . NV_LANG_DATA . '&nv=' . $mod_name . '&op=' . $op;

    // Dùng nv_url_rewrite() thật nếu có (mainfile.php đã load)
    if (function_exists('nv_url_rewrite') && isset($GLOBALS['global_config']) && !empty($GLOBALS['global_config']['rewrite_enable'])) {
        return nv_url_rewrite($raw_url, true);
    }

    // Fallback: xây thủ công theo pattern rewrite chuẩn quan sát được
    // {site}/{lang}/{module}/{cat_alias}/{alias}-{id}{ext}  (nếu rewrite_optional=0)
    // {site}/{module}/{cat_alias}/{alias}-{id}{ext}         (nếu rewrite_optional=1)
    $rewrite_optional = isset($GLOBALS['global_config']['rewrite_optional']) && $GLOBALS['global_config']['rewrite_optional'];
    $lang_prefix = (!$rewrite_optional && defined('NV_LANG_DATA')) ? NV_LANG_DATA . '/' : '';
    return rtrim($site_url, '/') . '/' . $lang_prefix . $mod_name . '/' . $op;
}

function nv_aichat_http_post($url, array $payload, array $headers = array(), $timeout = 30)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => array_merge(array('Content-Type: application/json'), $headers),
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => (int)$timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('http_code' => $code, 'body' => $body, 'error' => $err);
}

function nv_aichat_parse_openai($result, $name)
{
    if (!empty($result['error'])) return array('error' => "Lỗi kết nối {$name}: " . $result['error']);
    if ($result['http_code'] !== 200) {
        $d = json_decode($result['body'], true);
        $m = isset($d['error']['message']) ? $d['error']['message'] : 'HTTP ' . $result['http_code'];
        return array('error' => "{$name} Error: {$m}");
    }
    $d = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) return array('error' => "{$name}: Phản hồi không hợp lệ");
    $c = isset($d['choices'][0]['message']['content']) ? $d['choices'][0]['message']['content'] : null;
    if ($c === null) return array('error' => "{$name}: Không có nội dung");
    return array('response' => $c);
}

/* ══════════════════════════════════════════════════════════════════════
   GỌI AI PROVIDERS
══════════════════════════════════════════════════════════════════════ */

function nv_aichat_build_system($cfg, $site_context = '')
{
    $custom = isset($cfg['system_prompt']) ? trim($cfg['system_prompt']) : 'Bạn là một trợ lý AI thông minh và hữu ích.';

    $lang_instruction = "QUY TẮC NGÔN NGỮ (ƯU TIÊN CAO NHẤT, áp dụng cho MỌI câu trả lời kể cả lời chào): "
        . "Luôn trả lời bằng CHÍNH NGÔN NGỮ mà người dùng đang sử dụng trong câu hỏi/lời chào của họ "
        . "(hỏi tiếng Anh → trả lời tiếng Anh; tiếng Trung → trả lời tiếng Trung; tiếng Nhật → trả lời tiếng Nhật; "
        . "tiếng Hàn → trả lời tiếng Hàn; tiếng Việt → trả lời tiếng Việt; ngôn ngữ khác → trả lời bằng ngôn ngữ đó), "
        . "BẤT KỂ hướng dẫn vai trò bên dưới có nhắc tới ngôn ngữ cụ thể nào hay không — quy tắc này LUÔN ĐƯỢC ÁP DỤNG và ƯU TIÊN HƠN. "
        . "Nếu nội dung tham khảo từ website là tiếng Việt nhưng người dùng hỏi bằng ngôn ngữ khác, hãy dịch ý chính sang ngôn ngữ của người dùng, "
        . "chỉ giữ nguyên tên riêng (tên người, tên đơn vị, địa danh) không dịch.\n\n"
        . "--- Vai trò và phong cách trả lời (áp dụng SAU khi đã xác định đúng ngôn ngữ ở trên) ---\n";

    $base = $lang_instruction . $custom;

    if (empty($site_context)) return $base;
    return $base . "\n\n" . $site_context;
}

function nv_aichat_call_openai($msg, $cfg, $site_context = '', $history = array())
{
    $key = isset($cfg['openai_api_key']) ? trim($cfg['openai_api_key']) : '';
    if (!$key) return array('error' => 'OpenAI API Key chưa cấu hình');

    $messages = array(
        array('role' => 'system', 'content' => nv_aichat_build_system($cfg, $site_context)),
    );
    foreach ($history as $h) {
        $messages[] = array('role' => 'user',      'content' => $h['message']);
        $messages[] = array('role' => 'assistant', 'content' => $h['response']);
    }
    $messages[] = array('role' => 'user', 'content' => $msg);

    return nv_aichat_parse_openai(nv_aichat_http_post(
        'https://api.openai.com/v1/chat/completions',
        array(
            'model'       => isset($cfg['openai_model']) ? $cfg['openai_model'] : 'gpt-4o-mini',
            'messages'    => $messages,
            'max_tokens'  => (int)(isset($cfg['max_tokens'])   ? $cfg['max_tokens']   : 2000),
            'temperature' => (float)(isset($cfg['temperature']) ? $cfg['temperature'] : 0.7),
        ),
        array('Authorization: Bearer ' . $key)
    ), 'OpenAI');
}

function nv_aichat_call_gemini($msg, $cfg, $site_context = '', $history = array())
{
    $key   = isset($cfg['gemini_api_key']) ? trim($cfg['gemini_api_key']) : '';
    if (!$key) return array('error' => 'Gemini API Key chưa cấu hình');
    $model = isset($cfg['gemini_model']) ? $cfg['gemini_model'] : 'gemini-2.5-flash-lite';
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
    $sys   = nv_aichat_build_system($cfg, $site_context);

    $contents = array();
    $contents[] = array('role' => 'user',  'parts' => array(array('text' => $sys)));
    $contents[] = array('role' => 'model', 'parts' => array(array('text' => 'Đã hiểu. Tôi sẽ trả lời theo hướng dẫn trên.')));
    foreach ($history as $h) {
        $contents[] = array('role' => 'user',  'parts' => array(array('text' => $h['message'])));
        $contents[] = array('role' => 'model', 'parts' => array(array('text' => $h['response'])));
    }
    $contents[] = array('role' => 'user', 'parts' => array(array('text' => $msg)));

    $res   = nv_aichat_http_post($url, array(
        'contents'         => $contents,
        'generationConfig' => array(
            'maxOutputTokens' => (int)(isset($cfg['max_tokens'])   ? $cfg['max_tokens']   : 2000),
            'temperature'     => (float)(isset($cfg['temperature']) ? $cfg['temperature'] : 0.7),
        ),
    ));
    if (!empty($res['error']))     return array('error' => 'Lỗi kết nối Gemini: ' . $res['error']);
    if ($res['http_code'] !== 200) {
        $d = json_decode($res['body'], true);
        $m = isset($d['error']['message']) ? $d['error']['message'] : '';
        $status = isset($d['error']['status']) ? $d['error']['status'] : '';
        $detail = trim($status . ($status && $m ? ': ' : '') . $m);
        return array('error' => 'Gemini HTTP ' . $res['http_code'] . ($detail ? ' - ' . $detail : ''));
    }
    $d = json_decode($res['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) return array('error' => 'Gemini: Phản hồi không hợp lệ');
    $t = isset($d['candidates'][0]['content']['parts'][0]['text']) ? $d['candidates'][0]['content']['parts'][0]['text'] : null;
    if ($t === null) return array('error' => 'Gemini: Không có nội dung');
    return array('response' => $t);
}

function nv_aichat_call_local($msg, $cfg, $site_context = '', $history = array())
{
    $url   = isset($cfg['local_ai_url'])   ? trim($cfg['local_ai_url'])   : 'http://localhost:11434/api/generate';
    $model = isset($cfg['local_ai_model']) ? $cfg['local_ai_model']       : 'llama2';
    $sys   = nv_aichat_build_system($cfg, $site_context);

    $convo = '';
    foreach ($history as $h) {
        $convo .= "\nNgười dùng: " . $h['message'] . "\nTrợ lý: " . $h['response'] . "\n";
    }

    $prompt = $sys . "\n" . $convo . "\nNgười dùng: " . $msg . "\nTrợ lý:";
    $res   = nv_aichat_http_post($url, array('model' => $model, 'prompt' => $prompt, 'stream' => false), array(), 60);
    if (!empty($res['error']))     return array('error' => 'Lỗi Local AI: ' . $res['error']);
    if ($res['http_code'] !== 200) return array('error' => 'Local AI HTTP ' . $res['http_code']);
    $d = json_decode($res['body'], true);
    if (!isset($d['response']))    return array('error' => 'Local AI: Phản hồi không hợp lệ');
    return array('response' => $d['response']);
}

function nv_aichat_call_deepseek($msg, $cfg, $site_context = '', $history = array())
{
    $key = isset($cfg['deepseek_api_key']) ? trim($cfg['deepseek_api_key']) : '';
    if (!$key) return array('error' => 'DeepSeek API Key chưa cấu hình');

    $messages = array(
        array('role' => 'system', 'content' => nv_aichat_build_system($cfg, $site_context)),
    );
    foreach ($history as $h) {
        $messages[] = array('role' => 'user',      'content' => $h['message']);
        $messages[] = array('role' => 'assistant', 'content' => $h['response']);
    }
    $messages[] = array('role' => 'user', 'content' => $msg);

    return nv_aichat_parse_openai(nv_aichat_http_post(
        'https://api.deepseek.com/v1/chat/completions',
        array(
            'model'       => isset($cfg['deepseek_model']) ? $cfg['deepseek_model'] : 'deepseek-chat',
            'messages'    => $messages,
            'max_tokens'  => (int)(isset($cfg['max_tokens'])   ? $cfg['max_tokens']   : 2000),
            'temperature' => (float)(isset($cfg['temperature']) ? $cfg['temperature'] : 0.7),
        ),
        array('Authorization: Bearer ' . $key)
    ), 'DeepSeek');
}

/* ══════════════════════════════════════════════════════════════════════
   RENDER WIDGET HTML
   FIX v3.5:
   - Dùng visibility/opacity thay display:none để tránh theme CSS conflict
   - JS dùng capture phase cho document click, thêm e.preventDefault()
   - Thêm setTimeout 15ms cho open/close để tránh race condition với jQuery theme
   - Widget HTML được echo trực tiếp, không dùng $my_footer
══════════════════════════════════════════════════════════════════════ */

function nv_aichat_render_widget($ajax_url, $config, $is_admin = false)
{
    $provider  = isset($config['active_provider']) ? $config['active_provider'] : 'openai';
    $z_index    = '9999';
    $bottom     = '24px';
    $box_bottom = '92px';
    $badge      = $is_admin ? '<span style="position:absolute;top:-4px;left:-4px;background:#ff4444;color:#fff;font-size:9px;padding:1px 4px;border-radius:8px;font-weight:700;line-height:1.4;">ADMIN</span>' : '';
    // Khi ở admin: đẩy widget lên trên thanh footer cố định của NukeViet admin (~44px)
    $admin_css  = $is_admin
        ? '#nv-aichat-wrap{bottom:68px!important;}#nv-aichat-box{bottom:136px!important;}'
        : '';
    $site_name = defined('NV_SITE_NAME') ? NV_SITE_NAME : 'Website';
    $placeholder = "Hỏi về nội dung {$site_name}… (Enter gửi)";

    $providers_html = '';
    $list = array('openai' => 'OpenAI', 'gemini' => 'Gemini', 'local_ai' => 'Local AI', 'deepseek' => 'DeepSeek');
    foreach ($list as $val => $label) {
        $sel = ($val === $provider) ? ' selected' : '';
        $providers_html .= "<option value=\"{$val}\"{$sel}>{$label}</option>";
    }

    $suggest_html = '';
    if (!empty($config['search_suggest_text'])) {
        $suggests = array_filter(array_map('trim', explode("\n", $config['search_suggest_text'])));
        if (!empty($suggests)) {
            $suggest_html = '<div id="nv-aichat-suggests" style="padding:10px 14px 0;display:flex;flex-wrap:wrap;gap:6px;">';
            foreach (array_slice($suggests, 0, 4) as $s) {
                $s_esc = htmlspecialchars($s, ENT_QUOTES);
                $suggest_html .= "<button class=\"nv-aichat-sug\" onclick=\"nvAichatSuggest(this)\" style=\"background:#f0f2ff;border:1px solid #c7cefc;color:#5a67d8;border-radius:20px;padding:4px 12px;font-size:12px;cursor:pointer;\">{$s_esc}</button>";
            }
            $suggest_html .= '</div>';
        }
    }

    $ajax_url_js = addslashes($ajax_url);

    return <<<HTML
<div style="position:fixed;bottom:0;right:0;width:0;height:0;overflow:visible;z-index:{$z_index};pointer-events:none;">
<div id="nv-aichat-wrap" style="position:absolute!important;bottom:24px!important;right:24px!important;z-index:{$z_index}!important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif!important;display:block!important;pointer-events:auto!important;">
<style>
#nv-aichat-wrap,#nv-aichat-wrap *{box-sizing:border-box;}
#nv-aichat-btn{
  width:58px!important;height:58px!important;border-radius:50%!important;
  background:linear-gradient(135deg,#667eea,#764ba2)!important;
  border:none!important;color:#fff!important;font-size:28px!important;
  cursor:pointer!important;display:flex!important;align-items:center!important;
  justify-content:center!important;box-shadow:0 4px 20px rgba(102,126,234,.6)!important;
  padding:0!important;margin:0!important;transition:transform .2s!important;
  position:relative!important;outline:none!important;
}
#nv-aichat-btn:hover{transform:scale(1.08)!important;}
#nv-aichat-box{
  position:fixed!important;
  bottom:{$box_bottom}!important;
  right:24px!important;
  width:370px!important;
  background:#fff!important;
  border-radius:16px!important;
  box-shadow:0 8px 40px rgba(0,0,0,.25)!important;
  border:1px solid #dde!important;
  overflow:hidden!important;
  z-index:{$z_index}!important;
  visibility:hidden!important;
  opacity:0!important;
  pointer-events:none!important;
  transition:opacity .15s ease, visibility .15s ease!important;
  display:block!important;
}
#nv-aichat-box.nv-ac-open{
  visibility:visible!important;
  opacity:1!important;
  pointer-events:auto!important;
}
#nv-aichat-msgs{height:320px!important;overflow-y:auto!important;padding:14px!important;background:#f8f9fc!important;}
#nv-aichat-msgs::-webkit-scrollbar{width:4px!important;}
#nv-aichat-msgs::-webkit-scrollbar-thumb{background:#ccc!important;border-radius:2px!important;}
.nv-ac-bubble{margin-bottom:10px!important;clear:both!important;}
@media(max-width:420px){#nv-aichat-box{width:calc(100vw - 20px)!important;right:10px!important;}}
{$admin_css}
</style>

<button id="nv-aichat-btn" type="button" title="Trợ lý AI">{$badge}🤖</button>

<div id="nv-aichat-box">
  <div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:11px 14px;display:flex;align-items:center;justify-content:space-between;">
    <span style="color:#fff;font-weight:700;font-size:14px;">🤖 Trợ lý AI</span>
    <div style="display:flex;gap:8px;align-items:center;">
      <select id="nv-aichat-prov" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.4);border-radius:20px;padding:3px 8px;font-size:12px;cursor:pointer;outline:none;">{$providers_html}</select>
      <button id="nv-aichat-close" type="button" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;padding:0 4px;margin:0;outline:none;">&times;</button>
    </div>
  </div>
  <div id="nv-aichat-msgs">
    <div id="nv-aichat-ph" style="text-align:center;color:#bbb;padding-top:110px;font-size:13px;">💬 Hỏi về nội dung website!</div>
  </div>
  {$suggest_html}
  <div style="padding:9px 11px;background:#fff;border-top:1px solid #eee;display:flex;gap:7px;align-items:flex-end;">
    <textarea id="nv-aichat-inp" rows="1" maxlength="4000" placeholder="{$placeholder}"
      style="flex:1;padding:8px 12px;border:1px solid #dde;border-radius:20px;
             outline:none;font-size:14px;resize:none;line-height:1.4;
             font-family:inherit;max-height:100px;background:#fff;"></textarea>
    <button id="nv-aichat-send" type="button"
      style="padding:8px 16px;background:linear-gradient(135deg,#667eea,#764ba2);
             color:#fff;border:none;border-radius:20px;cursor:pointer;
             font-weight:600;font-size:13px;white-space:nowrap;outline:none;">Gửi</button>
  </div>
</div>
</div>

<script>
(function(){
  var AJAX = '{$ajax_url_js}';
  var busy = false, loadEl = null, ph;
  var _open = false;

  function q(id){ return document.getElementById(id); }

  function nvAichatOpen(){
    var box = q('nv-aichat-box');
    if(!box) return;
    box.classList.add('nv-ac-open');
    _open = true;
    setTimeout(function(){ var i=q('nv-aichat-inp'); if(i) i.focus(); }, 80);
  }

  function nvAichatClose(){
    var box = q('nv-aichat-box');
    if(!box) return;
    box.classList.remove('nv-ac-open');
    _open = false;
  }

  function init(){
    var btn  = q('nv-aichat-btn');
    var box  = q('nv-aichat-box');
    var clos = q('nv-aichat-close');
    var msgs = q('nv-aichat-msgs');
    var inp  = q('nv-aichat-inp');
    var send = q('nv-aichat-send');
    var prov = q('nv-aichat-prov');
    ph = q('nv-aichat-ph');

    if(!btn || !box || !inp || !send){ return; }
    if(btn._nvAichatInit){ return; }
    btn._nvAichatInit = true;

    /* ── Toggle button ── */
    btn.onclick = function(e){
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      /* Delay nhỏ để event queue của jQuery/theme xử lý xong trước */
      setTimeout(function(){
        _open ? nvAichatClose() : nvAichatOpen();
      }, 15);
    };

    /* ── Nút đóng (×) ── */
    if(clos){
      clos.onclick = function(e){
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        setTimeout(nvAichatClose, 15);
      };
    }

    /* ── Click ngoài box để đóng — dùng capture phase để chạy trước jQuery ── */
    document.addEventListener('click', function(e){
      if(!_open) return;
      var w = q('nv-aichat-wrap');
      if(w && !w.contains(e.target)){
        setTimeout(nvAichatClose, 15);
      }
    }, true);  /* true = capture phase, chạy trước bubble */

    /* ── Escape key ── */
    document.addEventListener('keydown', function(e){
      if(_open && (e.key === 'Escape' || e.keyCode === 27)){
        nvAichatClose();
      }
    });

    /* ── Escape XSS ── */
    function esc(t){ var d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

    /* ── Chuyển URL trong text (đã escape) thành link <a> ngắn gọn, không hiện URL dài ── */
    function linkify(escapedHtml){
      return escapedHtml.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/gi, function(url){
        var isSameSite = url.indexOf(window.location.hostname) !== -1;
        var label = isSameSite ? '📄 Xem bài viết' : '🔗 Xem chi tiết';
        return '<a href="' + url + '" target="_blank" rel="noopener" '
             + 'style="color:#5a67d8;text-decoration:underline;font-weight:600;">'
             + label + '</a>';
      });
    }

    /* ── Hiển thị bubble chat ── */
    function bubble(txt, me){
      if(ph && ph.parentNode){ ph.parentNode.removeChild(ph); ph=null; }
      var row = document.createElement('div');
      row.className = 'nv-ac-bubble';
      row.style.textAlign = me ? 'right' : 'left';
      var bub = document.createElement('span');
      bub.style.cssText = 'display:inline-block;padding:8px 12px;border-radius:14px;max-width:84%;'
        + 'word-wrap:break-word;font-size:14px;line-height:1.45;'
        + (me ? 'background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;'
              : 'background:#fff;color:#333;border:1px solid #e0e4f0;');
      var html = esc(txt).split(String.fromCharCode(10)).join('<br>');
      if(!me) html = linkify(html);
      bub.innerHTML = html;
      row.appendChild(bub);
      msgs.appendChild(row);
      msgs.scrollTop = 99999;
    }

    function showLoad(){
      loadEl = document.createElement('div');
      loadEl.style.cssText = 'padding:6px 0;color:#aaa;font-size:13px;';
      loadEl.textContent = '••• Đang xử lý...';
      msgs.appendChild(loadEl);
      msgs.scrollTop = 99999;
    }
    function hideLoad(){
      if(loadEl && loadEl.parentNode){ loadEl.parentNode.removeChild(loadEl); }
      loadEl = null;
    }

    /* ── Gửi tin nhắn ── */
    function go(){
      if(busy) return;
      var t = inp.value.trim();
      if(!t){ inp.focus(); return; }
      busy = true;
      send.style.opacity = '.5';
      inp.disabled = true;
      bubble(t, true);
      inp.value = '';
      inp.style.height = 'auto';
      showLoad();
      var fd = new FormData();
      fd.append('nv_aichat_action', 'send');
      fd.append('message', t);
      fd.append('provider', prov ? prov.value : 'openai');
      fetch(AJAX, {method:'POST', body:fd})
        .then(function(r){ if(!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function(d){
          hideLoad();
          bubble(d.response || (d.error ? '❌ ' + d.error : '❌ Lỗi không xác định'), false);
          /* Hiển thị link bài viết liên quan (chỉ bài phụ, không phải bài chính đã được AI tóm tắt) */
          var related = (d.articles || []).filter(function(a){ return !a.primary; }).slice(0, 3);
          if(related.length){
            var linkWrap = document.createElement('div');
            linkWrap.className = 'nv-ac-bubble';
            linkWrap.style.textAlign = 'left';
            var inner = document.createElement('div');
            inner.style.cssText = 'display:inline-block;padding:6px 12px;border-radius:10px;max-width:90%;background:#f0f2ff;border:1px solid #c7cefc;font-size:12px;color:#444;';
            inner.innerHTML = '<span style="font-weight:600;color:#5a67d8;">📄 Bài viết liên quan:</span>';
            related.forEach(function(a){
              var lnk = document.createElement('div');
              lnk.style.marginTop = '4px';
              lnk.innerHTML = '<a href="' + a.url + '" target="_blank" rel="noopener" style="color:#5a67d8;text-decoration:underline;word-break:break-all;">'
                + esc(a.title) + '</a>';
              inner.appendChild(lnk);
            });
            linkWrap.appendChild(inner);
            msgs.appendChild(linkWrap);
            msgs.scrollTop = 99999;
          }
        })
        .catch(function(e){ hideLoad(); bubble('❌ ' + e.message, false); })
        .finally(function(){ busy=false; send.style.opacity='1'; inp.disabled=false; inp.focus(); });
    }

    send.onclick = function(e){ e.stopPropagation(); go(); };
    inp.addEventListener('keydown', function(e){
      if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); go(); }
    });
    inp.addEventListener('input', function(){
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });

    /* ── Gợi ý câu hỏi ── */
    window.nvAichatSuggest = function(el){
      inp.value = el.textContent || el.innerText;
      inp.dispatchEvent(new Event('input'));
      var sg = q('nv-aichat-suggests');
      if(sg) sg.style.display = 'none';
      inp.focus();
    };
  }

  /* Chạy khi DOM sẵn sàng */
  if(document.readyState !== 'loading'){ init(); }
  else { document.addEventListener('DOMContentLoaded', init); }
  /* Backup: chạy lại sau 1.5s phòng theme load chậm */
  setTimeout(init, 1500);
}());
</script>
</div>
HTML;
}

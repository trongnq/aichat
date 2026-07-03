<?php
/**
 * AI Chat - action_mysql.php
 * Chạy khi "Thiết lập module" trong Admin > Quản lý Modules
 * Tên bảng cố định: {prefix}_{lang}_aichat_config và _aichat_chat
 * để khớp với NV_PREFIXLANG . '_aichat_config' trong functions.php
 */
if (! defined('NV_IS_FILE_MODULES')) {
    exit('Stop!!!');
}

// Dùng NV_PREFIXLANG để đảm bảo tên bảng nhất quán với functions.php
$tbl_chat   = NV_PREFIXLANG . '_aichat_chat';
$tbl_config = NV_PREFIXLANG . '_aichat_config';

$sql_drop_module   = array();
$sql_drop_module[] = 'DROP TABLE IF EXISTS `' . $tbl_chat . '`';
$sql_drop_module[] = 'DROP TABLE IF EXISTS `' . $tbl_config . '`';

$sql_create_module   = $sql_drop_module;

// Bảng lịch sử chat
$sql_create_module[] = 'CREATE TABLE `' . $tbl_chat . '` (
    `id`          int(11)      NOT NULL AUTO_INCREMENT,
    `userid`      int(11)      NOT NULL DEFAULT 0,
    `session_id`  varchar(100) NOT NULL DEFAULT \'\',
    `message`     text         NOT NULL,
    `response`    text         NOT NULL,
    `provider`    varchar(50)  NOT NULL DEFAULT \'\',
    `model`       varchar(100) NOT NULL DEFAULT \'\',
    `create_time` int(11)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_userid_session` (`userid`, `session_id`),
    KEY `idx_create_time`    (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

// Bảng cấu hình
$sql_create_module[] = 'CREATE TABLE `' . $tbl_config . '` (
    `config_name`  varchar(100) NOT NULL,
    `config_value` text         NOT NULL,
    `create_time`  int(11)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`config_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

// Dữ liệu mặc định — widget_enabled=1 ngay từ đầu
$sql_create_module[] = 'INSERT IGNORE INTO `' . $tbl_config . '`
    (`config_name`, `config_value`, `create_time`) VALUES
    (\'widget_enabled\',        \'1\',                                              UNIX_TIMESTAMP()),
    (\'openai_api_key\',        \'\',                                               UNIX_TIMESTAMP()),
    (\'openai_model\',          \'gpt-4o-mini\',                                    UNIX_TIMESTAMP()),
    (\'gemini_api_key\',        \'\',                                               UNIX_TIMESTAMP()),
    (\'gemini_model\',          \'gemini-2.5-flash-lite\',                          UNIX_TIMESTAMP()),
    (\'local_ai_url\',          \'http://localhost:11434/api/generate\',             UNIX_TIMESTAMP()),
    (\'local_ai_model\',        \'llama2\',                                          UNIX_TIMESTAMP()),
    (\'deepseek_api_key\',      \'\',                                               UNIX_TIMESTAMP()),
    (\'deepseek_model\',        \'deepseek-chat\',                                  UNIX_TIMESTAMP()),
    (\'active_provider\',       \'openai\',                                          UNIX_TIMESTAMP()),
    (\'max_tokens\',            \'1000\',                                            UNIX_TIMESTAMP()),
    (\'temperature\',           \'0.7\',                                             UNIX_TIMESTAMP()),
    (\'system_prompt\',         \'Bạn là trợ lý AI của website. Hãy trả lời ngắn gọn, rõ ràng, lịch sự.\', UNIX_TIMESTAMP()),
    (\'site_search_enabled\',   \'0\',                                               UNIX_TIMESTAMP()),
    (\'search_modules\',        \'news\',                                            UNIX_TIMESTAMP()),
    (\'search_max_results\',    \'5\',                                               UNIX_TIMESTAMP()),
    (\'search_suggest_text\',   \'\',                                               UNIX_TIMESTAMP())';

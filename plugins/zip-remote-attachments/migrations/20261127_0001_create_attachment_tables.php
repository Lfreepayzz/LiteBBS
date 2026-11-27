<?php
declare(strict_types=1);

db()->query('CREATE TABLE IF NOT EXISTS plg_zip_remote_attachments_files (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,post_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,source_kind ENUM(\'upload\',\'remote\') NOT NULL,storage_name VARCHAR(64) NULL,remote_url VARCHAR(2048) NULL,original_name VARCHAR(255) NOT NULL,file_size BIGINT UNSIGNED NULL,sha256 CHAR(64) NULL,access_mode ENUM(\'login\',\'reply\',\'points\') NOT NULL DEFAULT \'login\',point_cost INT UNSIGNED NOT NULL DEFAULT 0,status ENUM(\'active\',\'removed\') NOT NULL DEFAULT \'active\',created_at DATETIME(6) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
db()->query('CREATE TABLE IF NOT EXISTS plg_zip_remote_attachments_grants (user_id BIGINT UNSIGNED NOT NULL,attachment_id BIGINT UNSIGNED NOT NULL,grant_kind ENUM(\'reply\',\'purchase\') NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(user_id,attachment_id),CONSTRAINT fk_plg_zip_remote_attachments_grants_attachment FOREIGN KEY(attachment_id) REFERENCES plg_zip_remote_attachments_files(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

return [
    'CREATE INDEX idx_plg_zip_remote_attachments_files_post_status ON plg_zip_remote_attachments_files(post_id,status)',
    'CREATE INDEX idx_plg_zip_remote_attachments_files_user_status ON plg_zip_remote_attachments_files(user_id,status)',
    'CREATE INDEX idx_plg_zip_remote_attachments_grants_attachment ON plg_zip_remote_attachments_grants(attachment_id)',
];

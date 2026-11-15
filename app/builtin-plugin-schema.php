<?php
declare(strict_types=1);

function builtin_plugin_schema(string $name): array
{
    if ($name !== 'zip-remote-attachments') return [];

    return [
        'CREATE TABLE IF NOT EXISTS plg_zip_remote_attachments_files(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,post_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,source_kind ENUM("upload","remote") NOT NULL,storage_name CHAR(64) NULL,remote_url VARCHAR(2048) NULL,original_name VARCHAR(255) NOT NULL,file_size BIGINT UNSIGNED NULL,sha256 CHAR(64) NULL,access_mode ENUM("login","reply","points") NOT NULL DEFAULT "login",point_cost INT UNSIGNED NOT NULL DEFAULT 0,status ENUM("active","removed") NOT NULL DEFAULT "active",created_at DATETIME(6) NOT NULL,FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id),KEY ix_plg_zip_remote_attachments_post(post_id,status,id),KEY ix_plg_zip_remote_attachments_user_storage(user_id,source_kind,status,file_size),KEY ix_plg_zip_remote_attachments_storage(status,source_kind,storage_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS plg_zip_remote_attachments_grants(user_id BIGINT UNSIGNED NOT NULL,attachment_id BIGINT UNSIGNED NOT NULL,grant_kind ENUM("reply","purchase") NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(user_id,attachment_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(attachment_id) REFERENCES plg_zip_remote_attachments_files(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];
}

<?php
declare(strict_types=1);

return [
    'CREATE TABLE plg_friend_links_links (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,title VARCHAR(100) NOT NULL,url VARCHAR(2048) NOT NULL,url_hash CHAR(64) NOT NULL,description VARCHAR(255) NOT NULL DEFAULT \'\',source_url VARCHAR(2048) NULL,status ENUM(\'pending\',\'active\',\'disabled\') NOT NULL DEFAULT \'pending\',verified_at DATETIME(6) NULL,last_checked_at DATETIME(6) NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE UNIQUE INDEX uq_plg_friend_links_links_url_hash ON plg_friend_links_links(url_hash)',
    'CREATE INDEX idx_plg_friend_links_links_status_created ON plg_friend_links_links(status,created_at)',
];

<?php
declare(strict_types=1);

return [
    'CREATE TABLE plg_friend_links_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(100) NOT NULL,url VARCHAR(2048) NOT NULL,url_hash CHAR(64) NOT NULL,description VARCHAR(255) NOT NULL DEFAULT \'\',source_url VARCHAR(2048) NULL,status ENUM("pending","active","disabled") NOT NULL DEFAULT "pending",verified_at DATETIME(6) NULL,last_checked_at DATETIME(6) NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,UNIQUE KEY uq_friend_links_url_hash(url_hash),KEY ix_friend_links_status_created(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
];

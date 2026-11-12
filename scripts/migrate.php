<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__).'/app/config.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");

$c = load_config();
$db = new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['name'].';charset=utf8mb4', $c['user'], $c['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$exists = static function (string $table, string $name, string $type) use ($db): bool { $q=$db->prepare('SELECT COUNT(*) FROM information_schema.'.$type.' WHERE table_schema=DATABASE() AND table_name=? AND '.($type==='columns'?'column_name':'index_name').'=?');$q->execute([$table,$name]);return (bool)$q->fetchColumn(); };
$exec = static function (string $sql) use ($db): void { $db->exec($sql); echo "Applied: {$sql}\n"; };

$exec('CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(64) PRIMARY KEY,applied_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
if (!$exists('users','avatar_path','columns')) $exec('ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL');
if (!$exists('topics','created_at','columns')) { $exec('ALTER TABLE topics ADD COLUMN created_at DATETIME(6) NULL'); $exec('UPDATE topics SET created_at=last_post_at WHERE created_at IS NULL'); $exec('ALTER TABLE topics MODIFY created_at DATETIME(6) NOT NULL'); }
if (!(bool)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='market_submissions'")->fetchColumn()) $exec("CREATE TABLE market_submissions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,kind ENUM('plugin','theme') NOT NULL,package_name VARCHAR(32) NOT NULL,title VARCHAR(100) NOT NULL,version VARCHAR(32) NOT NULL,summary VARCHAR(500) NOT NULL,package_file VARCHAR(64) NOT NULL,sha256 CHAR(64) NOT NULL,status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',review_note VARCHAR(500) NOT NULL DEFAULT '',created_at DATETIME(6) NOT NULL,reviewed_at DATETIME(6) NULL,FOREIGN KEY(user_id) REFERENCES users(id),KEY ix_market_status(status,created_at,id),KEY ix_market_submissions_user_id(user_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$indexes = ['sessions'=>['ix_sessions_user_expiry'=>'user_id,expires_at','ix_sessions_expires'=>'expires_at'],'topics'=>['ix_topics_category_activity'=>'category_id,status,is_pinned,last_post_at,id','ix_topics_activity'=>'status,is_pinned,last_post_at,id','ix_topics_user_last_post'=>'user_id,last_post_at,id','ix_topics_user_created'=>'user_id,created_at,id'],'posts'=>['ix_posts_topic_created'=>'topic_id,status,created_at,id','ix_posts_topic_order'=>'topic_id,status,id','ix_posts_user_created'=>'user_id,created_at,id'],'post_revisions'=>['ix_post_revisions_post'=>'post_id,id'],'point_ledger'=>['ix_ledger_user_created'=>'user_id,created_at,id','ix_point_ledger_ref'=>'ref_type,ref_id,id'],'audit_logs'=>['ix_audit_created'=>'created_at,id'],'rate_limits'=>['ix_rate_limits_expiry'=>'reset_at'],'market_submissions'=>['ix_market_status'=>'status,created_at,id','ix_market_submissions_user_id'=>'user_id,id']];
foreach ($indexes as $table=>$list) foreach ($list as $name=>$columns) if (!$exists($table,$name,'statistics')) $exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})");
if (!$exists('topics','ft_topics_title','statistics')) $exec('ALTER TABLE topics ADD FULLTEXT INDEX ft_topics_title(title)');
foreach (['1.0.0','1.1.0-market','1.2.0-avatars','1.3.0-performance','1.4.0-hardening-performance','1.5.0-settings-audit','1.6.0-search-cache-extension-hardening'] as $version) $db->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute([$version]);
echo "Migration checks complete.\n";

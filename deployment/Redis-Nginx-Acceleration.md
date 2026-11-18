# LiteBBS Redis 与 Nginx 加速部署

本文以 Debian/Ubuntu、Nginx、PHP-FPM、MySQL 8 为例。其他发行版仅需调整软件包名、PHP-FPM 服务名、socket 路径和站点配置目录。

Redis 和 Nginx 内部文件转发都是可选加速项：未配置时论坛仍可运行；配置后可降低数据库、PHP-FPM 和磁盘 I/O 压力，提高多并发读写稳定性。

## 目标

- Redis：共享页面/数据缓存、缓存版本、会话、限流、热门主题浏览量聚合和缓存击穿保护。
- Nginx：静态资源压缩与缓存、HTTPS、受控文件下载的内部转发。
- PHP-FPM：使用 OPcache，控制 worker 数量，避免慢下载占用 PHP worker。

不要将 Redis 暴露到公网，不要在浏览器或 Nginx 配置中写入 Redis 密码。

## 1. 安装组件

```bash
sudo apt update
sudo apt install -y nginx redis-server php-fpm php-mysql php-mbstring php-redis php-zip php-gd
```

确认 PHP 同时加载 Redis 扩展：

```bash
php -m | grep -E 'redis|pdo_mysql|mbstring|zip'
```

预期至少包含 `redis`、`pdo_mysql`、`mbstring`、`zip`。如果 Web 请求仍提示 `ext-redis` 不可用，重启对应 PHP-FPM 服务，而不仅是重新打开终端。

## 2. 配置 Redis

编辑 `/etc/redis/redis.conf`：

```conf
bind 127.0.0.1 ::1
protected-mode yes
port 6379

# 生成一个高强度随机密码，例如：openssl rand -base64 48
requirepass REPLACE_WITH_A_LONG_RANDOM_PASSWORD

# 缓存可以淘汰；不要使用 noeviction，否则满内存时写缓存会失败。
maxmemory 256mb
maxmemory-policy allkeys-lru

# 不把 Redis 当作唯一业务数据源。开启轻量持久化用于意外重启恢复。
appendonly yes
appendfsync everysec
```

内存起点可按站点规模调整：

- 小型论坛：`128mb`
- 普通生产论坛：`256mb` 至 `512mb`
- 多站点/多节点：按全部 Redis 实例的实际峰值加 30% 余量

启动并验证：

```bash
sudo systemctl enable --now redis-server
redis-cli -a 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD' ping
redis-cli -a 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD' info memory
```

`PING` 必须返回 `PONG`。不要把 `requirepass` 留空，也不要把 `bind` 改为 `0.0.0.0`。

## 3. 为 PHP-FPM 注入 LiteBBS 环境变量

编辑实际使用的 PHP-FPM pool，例如 `/etc/php/8.2/fpm/pool.d/litebbs.conf`。pool 名和版本须与服务器一致。

```conf
[litebbs]
user = www-data
group = www-data
listen = /run/php/php-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 24
pm.start_servers = 4
pm.min_spare_servers = 4
pm.max_spare_servers = 12
pm.max_requests = 1000
request_terminate_timeout = 60s
catch_workers_output = yes
security.limit_extensions = .php

; LiteBBS Redis: cache, session, rate limit, view aggregation.
env[LITEBBS_REDIS_HOST] = 127.0.0.1
env[LITEBBS_REDIS_PORT] = 6379
env[LITEBBS_REDIS_PASSWORD] = REPLACE_WITH_A_LONG_RANDOM_PASSWORD
env[LITEBBS_REDIS_DATABASE] = 0
env[LITEBBS_REDIS_PREFIX] = litebbs-production
env[LITEBBS_REDIS_TIMEOUT] = 0.25

; Let Nginx transfer approved local downloads after PHP authorization.
env[LITEBBS_X_ACCEL_REDIRECT] = 1

; Optional. Enable only after monitoring MySQL connections and worker reuse.
; env[LITEBBS_DB_PERSISTENT] = 1
```

规则：

- `LITEBBS_REDIS_PREFIX` 必须在同一 Redis 实例中唯一。例如 `forum-prod`、`forum-stage`。
- 正式站点与测试站点不能共用 prefix。
- `LITEBBS_REDIS_DATABASE` 可保留 `0`；不要把 database 编号当安全隔离。
- `LITEBBS_DB_PERSISTENT` 默认不要开启。只有 MySQL 连接建立耗时明显、连接数有余量且压力测试稳定时再启用。
- `pm.max_children` 不是越大越好。计算方式：可分配给 PHP 的 RAM / 实测单 worker 峰值 RSS，再预留 MySQL、Redis、操作系统内存。

应用 PHP-FPM 配置：

```bash
sudo php-fpm8.2 -t
sudo systemctl restart php8.2-fpm
```

将 `8.2` 替换为实际 PHP 版本。

## 4. 启用 OPcache

将 `deployment/opcache.ini.example` 的内容复制到 PHP-FPM 的额外 ini 目录，例如：

```bash
sudo cp deployment/opcache.ini.example /etc/php/8.2/fpm/conf.d/99-litebbs-opcache.ini
sudo systemctl restart php8.2-fpm
```

文件中使用 `opcache.validate_timestamps=0`，因此每次发布 PHP 代码后必须重启 PHP-FPM：

```bash
sudo systemctl restart php8.2-fpm
```

不要在生产环境开启 `display_errors`。

## 5. 配置 Nginx

以项目实际路径为准。以下假定：

- 项目：`/srv/litebbs`
- 域名：`forum.example.com`
- PHP-FPM socket：`/run/php/php-fpm.sock`

复制并修改仓库中的 `nginx.conf.example`：

```bash
sudo cp nginx.conf.example /etc/nginx/sites-available/litebbs
sudoedit /etc/nginx/sites-available/litebbs
sudo ln -s /etc/nginx/sites-available/litebbs /etc/nginx/sites-enabled/litebbs
```

至少替换这些项目：

```nginx
server_name forum.example.com;
root /srv/litebbs;
ssl_certificate /etc/letsencrypt/live/forum.example.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/forum.example.com/privkey.pem;
fastcgi_pass unix:/run/php/php-fpm.sock;
```

示例已经包含关键的加速与安全配置：

```nginx
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types text/plain text/css application/json application/javascript application/xml image/svg+xml;

location = /index.php {
    include fastcgi_params;
    fastcgi_param HTTPS on;
    fastcgi_param LITEBBS_X_ACCEL_REDIRECT 1;
    fastcgi_param DOCUMENT_ROOT $realpath_root;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param PATH_INFO "";
    fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}

location ^~ /__litebbs-uploads/ {
    internal;
    alias /srv/litebbs/uploads/;
    add_header Cache-Control "private, no-store" always;
}

location ^~ /__litebbs-market-packages/ {
    internal;
    alias /srv/litebbs/market-packages/;
}
```

`internal` 是必须项：外部用户不能直接请求 `/__litebbs-uploads/`；只有 PHP 完成权限判断后发出 `X-Accel-Redirect`，Nginx 才会传输文件。

加载并检查：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

不要使用下列危险 FastCGI 写法：

```nginx
fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
fastcgi_param SCRIPT_FILENAME $request_filename;
```

本项目必须保持固定入口：

```nginx
fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
```

## 6. 文件权限

PHP-FPM 用户必须能读取程序文件，并能写入下列运行目录：

```bash
sudo chown -R root:www-data /srv/litebbs
sudo find /srv/litebbs -type d -exec chmod 0750 {} \;
sudo find /srv/litebbs -type f -exec chmod 0640 {} \;

sudo install -d -o www-data -g www-data -m 0750 /srv/litebbs/cache
sudo install -d -o www-data -g www-data -m 0750 /srv/litebbs/uploads
sudo install -d -o www-data -g www-data -m 0750 /srv/litebbs/market-packages
```

私有配置目录优先放在 Web 根目录外：

```conf
env[LITEBBS_PRIVATE_DIR] = /var/lib/litebbs/private
```

创建目录：

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/lib/litebbs/private
```

若启用该变量，需要将现有 `private/config.php` 和 `private/install.lock` 安全迁移到新目录，再重启 PHP-FPM。迁移前先备份，避免配置丢失。

## 7. 启动后台 Worker

Web 请求不会同步处理通知、扩展异步事件、扩展任务或主题访问量落库。需要用 systemd timer、cron 或 Supervisor 定期运行：

```bash
php /srv/litebbs/scripts/notifications-worker.php 100
php /srv/litebbs/scripts/outbox-worker.php 100
php /srv/litebbs/scripts/extension-worker.php 100
php /srv/litebbs/scripts/flush-topic-views.php
php /srv/litebbs/scripts/cleanup.php
php /srv/litebbs/scripts/cleanup-attachments.php
```

建议频率：

| 任务 | 建议频率 | 并行度 |
| --- | --- | --- |
| notifications-worker | 每 10 至 30 秒 | 1 至 4 个实例 |
| outbox-worker | 每 10 至 30 秒 | 1 至 4 个实例 |
| extension-worker | 每分钟 | 1 至 2 个实例 |
| flush-topic-views | 每分钟 | 1 个实例 |
| cleanup | 每天凌晨 | 1 个实例 |
| cleanup-attachments | 每天凌晨 | 1 个实例 |

队列 worker 使用数据库行锁领取任务，可以横向扩展；定时清理和主题访问量 flush 应只运行一个实例。

## 8. 验证 Redis、生效状态与下载转发

### Redis

先访问论坛首页、主题页、登录页，再查看：

```bash
redis-cli -a 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD' info stats
redis-cli -a 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD' info memory
redis-cli -a 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD' --scan --pattern 'litebbs-production:*' | head
```

重点指标：

- `keyspace_hits` 应持续增长。
- `evicted_keys` 不应持续增长；若增长，增加 Redis 内存或减少不必要 key。
- Redis p99 延迟目标低于 5 ms。

### HTTP 与缓存

发布后先运行 `sudo nginx -t`，并用服务器上的 PHP CLI 对改动的 PHP 文件执行 `php -l`。标准 Nginx 示例对带 12 位资源版本号的静态 URL 使用一年 immutable 缓存；未版本化的资源只缓存一小时。宝塔和 Apache 示例统一采用一小时缓存。修改资源后应检查页面生成的 `?v=` 是否随之变化。

```bash
curl -I https://forum.example.com/
curl -I https://forum.example.com/c/1
curl -I https://forum.example.com/t/1
curl -I 'https://forum.example.com/templates/default/assets/theme.css?v=实际页面中的版本号'
curl -I https://forum.example.com/assets/css/admin.css
```

匿名首页和分区页应包含类似：

```text
Cache-Control: public, max-age=30, s-maxage=30, stale-while-revalidate=90
Server-Timing: ...
```

登录页面、会员中心、私信页应为：

```text
Cache-Control: private, no-store
```

静态文件不应带有 `private, no-store`；未带版本号的插件资源也不应带 `immutable`。若匿名请求仍很慢，先比较两次请求的 `Server-Timing`（首访与命中缓存），再查看 Redis `keyspace_hits`、PHP-FPM worker 排队和 MySQL 慢查询；没有运行中的站点和请求样本无法单凭代码判断实际瓶颈。

`Server-Timing` 中重点关注：

- `total`：总处理时间。
- `db`：建连时间；持续偏高时检查网络、持久连接策略和 MySQL。
- `sql`：SQL 执行总耗时。
- `queries`：每个请求的 SQL 数。
- `dispatch`：PHP 路由、渲染、插件逻辑耗时。

### X-Accel-Redirect

使用有下载权限的账号下载附件或市场包，并观察：

```bash
sudo tail -f /var/log/nginx/access.log /var/log/nginx/error.log
sudo journalctl -u php8.2-fpm -f
```

下载期间 PHP-FPM 不应长时间忙于传输文件；传输应由 Nginx 内部路径完成。直接访问下列路径必须返回 `404` 或 `403`：

```text
/__litebbs-uploads/
/__litebbs-market-packages/
```

## 9. 压测与验收目标

先准备真实结构的测试数据，再运行仓库自带脚本：

```bash
export BASE_URL='https://forum.example.com'
export TOPIC_ID='1'
export CATEGORY_ID='1'
export HOT_TOPIC_ID='1'
k6 run /srv/litebbs/scripts/load-test.js
```

初始验收目标：

| 场景 | 建议目标 |
| --- | --- |
| 匿名首页/分区缓存命中 | 应用处理低毫秒；端到端受网络距离影响 |
| 热门主题读取 | p95 小于 500 ms，p99 小于 1.5 s |
| 错误率 | 小于 1% |
| PHP-FPM | 不长期满 `pm.max_children` |
| MySQL | 无持续增长的慢查询与锁等待 |
| Redis | 无持续 eviction，p99 低于 5 ms |
| 队列 | 最老未处理任务年龄不持续上升 |

不要承诺所有请求都固定在“几毫秒”：跨公网网络、TLS、浏览器、登录态鉴权、数据库写入都增加端到端时间。可实现的目标是匿名缓存读低延迟、写路径稳定、p95/p99 可预测。

## 10. 常见问题

### Redis 已配置但站点报 `ext-redis is unavailable`

安装 PHP 对应版本的 Redis 扩展并重启 PHP-FPM：

```bash
sudo apt install php-redis
sudo systemctl restart php8.2-fpm
```

确认 PHP-FPM 的版本与 CLI 的 `php -v` 一致；它们可能加载不同 ini 文件。

### 配置 Redis 后全部请求变慢或报 500

检查：

```bash
redis-cli -a 'PASSWORD' ping
sudo journalctl -u php8.2-fpm -n 100 --no-pager
```

核对 host、port、password、database 与 prefix。应用将已配置 Redis 视为共享限流/会话依赖，Redis 不可达时应优先修复 Redis，而不是让站点长期带故障运行。

### Nginx 报 `No input file specified.`

检查站点 `root` 和：

```nginx
fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
```

不要把任意 URI 拼进 `SCRIPT_FILENAME`。

### 下载返回 404，或 PHP 仍然占满

确认三项完全一致：

1. PHP-FPM pool 中 `env[LITEBBS_X_ACCEL_REDIRECT] = 1`。
2. Nginx `location ^~ /__litebbs-uploads/` 的 `alias` 指向项目 `uploads/` 实际目录。
3. Nginx worker 用户可读取上传文件。

修改后依次执行：

```bash
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl restart php8.2-fpm
```

### 缓存命中率低

检查 CDN 或反代是否错误忽略/篡改 `Cache-Control`、`Vary: Cookie`。匿名首页和分区页不要添加无关全站 Cookie；本程序已把访客主题浏览 Cookie 限制在 `/t/` 路径，避免它打碎列表页缓存。

## 发布顺序

```bash
sudo nginx -t
sudo systemctl restart redis-server
sudo systemctl restart php8.2-fpm
sudo systemctl reload nginx
```

发布 PHP 代码后：

1. 重启 PHP-FPM，使 OPcache 加载新代码。
2. 以管理员登录 `/admin/extensions`。
3. 出现“数据库优化待升级”时，点击“立即升级数据库优化”。
4. 检查 worker 日志和 `Server-Timing`。
5. 最后运行 k6 读压测，确认 p95、p99、错误率和队列年龄。

# LiteBBS 生产性能基线

## 容量规划

- 先用实际 PHP-FPM worker RSS 计算 `pm.max_children`：为操作系统、MySQL、Redis 和文件缓存预留内存后，再除以单个 worker 的 P95 RSS。
- `pm.max_children` 不应超过 MySQL 可承受的并发连接数；数据库与 PHP-FPM 同机时，通常应先从 6 到 24 个 worker 压测，而不是按 CPU 核数直接放大。
- 开启 OPcache，并在发布后 reload PHP-FPM；本项目的推荐值见 `opcache.ini.example`。
- 多节点或高热点主题必须配置 Redis（Session、缓存、限流和浏览量聚合），不要使用本地文件缓存做横向扩容。

## 必监控指标

- PHP-FPM：active/idle workers、`pm.max_children` reached、慢日志、worker RSS、502/504。
- MySQL：连接数、慢 SQL、InnoDB buffer pool 命中率、行锁等待、临时表和磁盘 I/O。
- Redis latency：P95/P99 延迟、连接错误、内存使用、eviction、命中率；Redis 不可用时应用会失去共享缓存和限流能力。
- 队列：notification/outbox 的 queue age（最旧未处理任务距现在的秒数）、积压量、失败事件数和 worker 每分钟处理量。
- HTTP：首页、分类页、主题页的 P50/P95/P99、5xx 比率和 `Server-Timing` 中的 `sql`/`queries`。

## 上线前验证

```bash
php -l index.php
find app plugins templates scripts -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/framework.php
php scripts/migrate.php
php scripts/plugin-migrate.php zip-remote-attachments
```

在预发布环境执行项目自带压测：

```bash
BASE_URL=https://staging.example.com TOPIC_ID=1 CATEGORY_ID=1 HOT_TOPIC_ID=1 k6 run scripts/load-test.js
```

压测门槛：HTTP 失败率低于 1%，P95 小于 500 ms，P99 小于 1500 ms；同时确认 PHP-FPM 未持续触顶、MySQL 无持续慢查询、队列未持续增长。

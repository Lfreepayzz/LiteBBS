# Production Deployment

LiteBBS requires PHP 8.1 or newer, MySQL 8.0 or newer, and the PHP extensions
`pdo_mysql`, `mbstring`, `json`, `fileinfo`, and `zip`. Redis with `ext-redis`
is strongly recommended for shared cache, rate limiting, sessions, and cache
stampede protection when more than one PHP-FPM worker or host serves traffic.

For a complete Chinese Redis, PHP-FPM, OPcache, Nginx, X-Accel-Redirect,
worker, validation, and troubleshooting guide, read
`deployment/Redis-Nginx-Acceleration.md`.

## Capacity Baseline

1. Enable OPcache with `opcache.validate_timestamps=0` using
   `deployment/opcache.ini.example`; restart PHP-FPM on every release.
2. Start from `deployment/php-fpm.conf.example`. Set `pm.max_children` to
   available RAM divided by measured peak PHP worker RSS, reserving memory for
   MySQL, Redis, and the operating system. Do not set it from CPU count alone.
3. Configure `LITEBBS_REDIS_HOST`, `LITEBBS_REDIS_PORT`, and a unique
   `LITEBBS_REDIS_PREFIX`. Keep Redis latency below 5 ms at p99; a slow Redis
   makes every cache, rate-limit, and session operation slow.
4. Configure the supplied Nginx front-controller rules and enable HTTPS. Keep
   uploads, `private/`, `cache/`, `scripts/`, `app/`, and database files outside
   direct public access.
5. After updating program files, sign in as an administrator and open
    `/admin/extensions`. If the page shows **数据库优化待升级**, use its
    **立即升级数据库优化** button and wait for the redirect before restarting
    workers. Do not run `scripts/migrate.php` as part of every normal production
    deployment; the existing application upgrade flow remains unchanged.
6. This release adds queue and cleanup indexes through the backward-compatible
   `3.7.0-queue-cleanup-indexes` maintenance migration. Run
   `php scripts/migrate.php` once during a low-traffic maintenance window before
   scaling workers. It only adds
   indexes and migration records; it does not change plugin tables, plugin
   registrations, theme directories, or the online update program.

## Workers And Monitoring

Run the notification, generic outbox, extension, cleanup, and topic-view
workers under a service manager. Only one scheduled cleanup worker is needed;
outbox workers may be scaled horizontally because they claim rows with locks.

Monitor request p95/p99 latency, PHP-FPM active/idle workers, worker RSS,
MySQL slow queries and lock waits, Redis latency and memory eviction, cache hit
rate, and queue age. Alert when queue age rises continuously, because delayed
notification or extension work otherwise accumulates without slowing web
requests visibly.

For diagnosis, enable the MySQL slow query log temporarily and inspect
`Server-Timing` from the application. Compare `db`, `sql`, `queries`, and
`dispatch` for anonymous and authenticated requests separately. Run `EXPLAIN
ANALYZE` on the home/category feed, topic page, notification fan-out, search,
private-message queries, both queue-claim queries, and scheduled cleanup deletes
using production-like row counts. Do not increase
`pm.max_children` until MySQL connection limits and peak PHP worker RSS have
been measured; more workers can turn a database bottleneck into a connection
storm.

## FastCGI Troubleshooting

If Nginx returns `No input file specified.`, verify that the configured site
root points at this project, PHP-FPM can read it, and `SCRIPT_FILENAME` is the
resolved path to `index.php` rather than a path assembled from an arbitrary
request URI. The supplied Nginx example uses `$realpath_root/index.php` for
this reason.

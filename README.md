
基于 PHP 和 MySQL 程序，提供主题与回复、分区管理、内容审核、审计日志、主题和插件扩展能力。

## 演示

[演示地址](https://bolgk.eu.org/)

## 优点
- 体积极小，极致轻量化：源码仅  100KB左右，没有大量冗余代码、臃肿依赖包，
- 占用资源极低。  访问速度快：低配虚拟主机、小服务器也能流畅跑，负载压力小。
- 部署简单：PHP+MySQL  环境要求低，上传源码、即可快速搭建，上手门槛低。
- 支持模板  +  扩展机制：自带模板管理、扩展接口，方便改界面、按需加功能，不用改动核心代码。
- 开源：源码开放、私有化部署，数据自己掌控，  界面清爽简约：原生适配移动端，没有繁杂多余组件。   


## 截图

![LiteBLOG Screenshot 1](https://raw.githubusercontent.com/Lfreepayzz/LiteBLOG/refs/heads/main/img/1.png)

![LiteBLOG Screenshot 2](https://raw.githubusercontent.com/Lfreepayzz/LiteBLOG/refs/heads/main/img/2.png)



## 功能

- 文章主题、回复、编辑与分页
- 可见/隐藏分区及排序管理
- 管理员后台：内容、用户、分区、模板、插件、扩展审核、审计与站点设置
- 登录会话、CSRF 防护、HTTPS 来源校验和操作审计
- Markdown 安全转义渲染
- 文件缓存与主题、分区、主题列表缓存失效
- 内部扩展市场的结构校验、签名清单和审核流程
- 固定 GitHub 官方仓库更新检查与确认式程序更新

## 环境要求

- PHP 8.1 或更高版本
- MySQL 8.0+ 或 MariaDB 10.6+
- PHP 扩展：`pdo_mysql`、`mbstring`、`json`、`fileinfo`、`zip`
- 扩展市场清单签名需要 `openssl`。在线更新通过 PHP 的 HTTPS 流读取 GitHub，需启用 `allow_url_fopen` 和 OpenSSL 支持。
- HTTP 或 HTTPS Web 站点（生产环境建议使用 HTTPS）

## 安装

1. 将本项目文件上传到网站程序目录。
2. 确认 PHP Web 进程可写入 `private/` 和 `cache/`。启用上传或扩展市场时，也要分别赋予 `uploads/` 或 `market-packages/` 写权限。
3. 在数据库面板创建空数据库和独立数据库用户，并授予建表所需权限。
4. 配置 Apache `.htaccess` 或参考 `nginx.conf.example` 配置 Nginx。
5. 在 PHP Web 进程的环境中临时设置 `LITEBBS_INSTALL_SECRET`。使用 `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` 生成独立的 64 位小写十六进制密钥。使用 PHP-FPM 时，通过对应进程池的环境配置传入密钥并重新加载进程池。
6. 通过 HTTPS 访问安装页面，在浏览器认证提示中输入用户名 `installer`，密码使用上一步生成的密钥。站点 URL 可留空，填写时支持 HTTP 或 HTTPS。

```text
https://example.com/install
```

仓库提供的路由示例和页面链接以站点根路径为基准，建议使用独立域名或子域名部署。

安装完成后，移除 `LITEBBS_INSTALL_SECRET` 并重新加载 PHP Web 进程。恢复未完成的安装也需要设置密钥并通过 HTTPS 认证。数据库配置和安装锁默认保存在程序目录下。

```text
private/config.php
private/install.lock
```

不要将 `private/` 暴露给 Web 访问。项目提供的 Apache 和 Nginx 规则已拒绝该目录。

## 数据库权限

应用数据库用户至少需要目标数据库的以下权限：

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP
ON `forum_db`.* TO 'forum_app'@'WEB_SERVER_IP';
```

`WEB_SERVER_IP` 必须是 MySQL/MariaDB 实际看到的 Web 服务器来源地址。IPv4、IPv6、`localhost` 和 `127.0.0.1` 属于不同的授权匹配项。

## 后台管理

管理员登录后访问：

```text
/admin
```

后台使用固定系统布局，不依赖前台主题。后台可管理：

- 主题与回复内容
- 用户角色和状态
- 讨论分区
- 模板和插件
- 扩展审核
- 官方更新检查
- 审计日志
- 站点设置

## 程序更新

程序更新源固定为：

```text
https://github.com/Lfreepayzz/LiteBBS
```

在后台“程序更新”页面（`/admin/extensions`）可检查 `main` 分支的最新文件树。下载更新包后，由管理员确认覆盖更新。程序会在替换前备份已有的受管文件。

不会被后台更新覆盖的内容：

```text
private/
uploads/
cache/
market-packages/
数据库
```

更新仓库必须包含完整项目源码，至少包括：

```text
index.php
install.php
app/
database/
plugins/
scripts/
templates/
```

## 安全与运维

- 生产环境建议启用 HTTPS，以保护登录凭据和会话数据。
- 安装期间将 `/install` 和 `/install.php` 限制在受信任的管理网络。若使用自定义 Web 配置，确保安装请求的 `Authorization` 头传递给 PHP 且不会被记录。
- `private/` 必须由 Web 服务器拒绝访问。
- 不要通过网页直接安装未经审查的 PHP 模板或插件。
- PHP 插件与应用运行在同一 PHP 进程中。管理员审核插件代码后，可在后台安装、升级和启用，所需迁移会随操作执行。新插件安装后默认为停用。
- 单独使用 CLI 执行插件迁移时，仍须设置 `LITEBBS_ALLOW_TRUSTED_PLUGINS=1` 并传入 `--allow-trusted-migrations`。
- 更新前保留数据库和文件备份。
- 通过进程管理器定期运行清理脚本，并持续运行通知、扩展 outbox、扩展任务和主题浏览量 worker。PHP CLI 必须与 Web 使用相同的 PHP 版本、扩展和 `private/config.php`：

```sh
php scripts/cleanup.php
php scripts/notifications-worker.php
php scripts/outbox-worker.php
php scripts/extension-worker.php
php scripts/flush-topic-views.php
```

这些脚本每次调用处理一批任务后退出，应由进程管理器或定时器按部署配置周期调用；不要通过 Web 请求执行。部署参数、队列监控和 Redis 配置见 [`deployment/README.md`](deployment/README.md)。附件清理脚本仅在启用 ZIP 附件插件时需要：`php scripts/cleanup-attachments.php`。

## 开发文档

- [架构与开发约定](ARCHITECTURE.md)
- [主题开发](模板开发文档.md)
- [插件开发](插件开发文档.md)
- [生产部署](deployment/README.md)

## 许可证

本项目声明采用 Apache License 2.0。当前源码目录未附许可证正文。

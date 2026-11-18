<?php
declare(strict_types=1);

final class SiteStatsPlugin implements LiteBBSPlugin
{
    public function boot(PluginContext $context): void
    {
        $context->assets()->css('assets/style.css');
        $context->slots()->add('sidebar.sections', static fn(array $view): string => site_stats_render_sidebar(), 100);
    }
}

function site_stats_render_sidebar(): string
{
    $data = cached('plugin:site-stats:sidebar', 300, static function (): array {
        $stats = h('SELECT (SELECT COUNT(*) FROM topics WHERE status="normal") AS topics, (SELECT COALESCE(SUM(reply_count),0) FROM topics WHERE status="normal") AS replies, (SELECT COUNT(*) FROM users WHERE status="active") AS users')->fetch() ?: ['topics'=>0, 'replies'=>0, 'users'=>0];
        $users = h('SELECT username FROM users WHERE status="active" ORDER BY created_at DESC,id DESC LIMIT 10')->fetchAll();
        return ['stats'=>$stats, 'users'=>$users];
    });
    $stats = is_array($data['stats'] ?? null) ? $data['stats'] : ['topics'=>0, 'replies'=>0, 'users'=>0];
    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    $html = '<aside class="site-stats-sidebar"><div class="site-stats-heading"><h2>站点统计</h2></div><p class="site-stats-summary"><span>主题 <strong>'.number_format((int)$stats['topics']).'</strong></span><i aria-hidden="true">·</i><span>回复 <strong>'.number_format((int)$stats['replies']).'</strong></span><i aria-hidden="true">·</i><span>用户 <strong>'.number_format((int)$stats['users']).'</strong></span></p><h3>最新用户</h3>';
    if (!$users) return $html.'<p class="site-stats-empty">暂无用户。</p></aside>';

    $html .= '<ul class="site-stats-users">';
    foreach ($users as $user) $html .= '<li><span aria-hidden="true">'.e(text_slice((string)$user['username'], 0, 1)).'</span><a href="/u/'.rawurlencode((string)$user['username']).'">'.e((string)$user['username']).'</a></li>';
    return $html.'</ul></aside>';
}

<?php
declare(strict_types=1);

$context = $pagination['context'];
$page = (int)($pagination['current_page'] ?? 1);
$first = $pagination['first_url'];
$more = $pagination['has_more'];
if (!$more && $page <= 1) return;
?>
<nav id="pagination" class="pagination" aria-label="<?= $context === 'topic' ? '回复分页' : '主题分页' ?>">
  <?php if ($page > 1): ?>
    <a class="extend prev" href="<?= e($first) ?>">第一页</a>
  <?php endif; ?>
  <?php if ($more): ?><a class="extend next" href="<?= e($pagination['next_url']) ?>" rel="next"><?= $context === 'topic' ? '更多回复' : '下一页' ?> ›</a><?php endif; ?>
</nav>

<?php
declare(strict_types=1);
if (!$pagination['has_more']) return;
?>
<nav class="pagination"<?= $pagination['context'] === 'topic' ? ' aria-label="回复分页"' : '' ?>><a class="pagination-next" href="<?= e($pagination['next_url']) ?>"><?= $pagination['context'] === 'topic' ? '更多回复' : '加载更多' ?></a></nav>

<?php declare(strict_types=1); ?>
<article class="aurora-topic-card">
  <a class="aurora-topic-category" href="/c/<?= (int)$topic['category_id'] ?>"><?= e($topic['category']) ?></a>
  <h2><a href="/t/<?= (int)$topic['id'] ?>"><?= e($topic['title']) ?></a></h2>
  <p><?= e($topic['username']) ?> · <?= (int)$topic['reply_count'] ?> 条回复</p>
</article>

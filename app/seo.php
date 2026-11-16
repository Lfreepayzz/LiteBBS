<?php
declare(strict_types=1);

function seo_normalize_path(string $path): string
{
    $path = parse_url($path, PHP_URL_PATH) ?: '/';
    if (!str_starts_with($path, '/')) $path = '/';
    return rtrim($path, '/') ?: '/';
}

function seo_normalize_canonical(string $canonical): string
{
    $parts = parse_url($canonical);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return $canonical;
    $port = isset($parts['port']) ? ':'.(int)$parts['port'] : '';
    return strtolower((string)$parts['scheme']).'://'.strtolower((string)$parts['host']).$port.seo_normalize_path((string)($parts['path'] ?? '/'));
}

function seo_indexing_enabled(): bool
{
    $config = load_config();
    $seo = is_array($config['seo'] ?? null) ? $config['seo'] : [];
    return (string)($seo['indexing'] ?? '1') !== '0';
}

function seo_inject_head(string $html, array $page): string
{
    if (!preg_match('~</head\s*>~i', $html) || preg_match('~<meta\s+[^>]*name\s*=\s*["\']robots["\']~i', $html)) return $html;
    $head = seo_head(
        (string)($page['title'] ?? ''),
        (string)($page['description'] ?? ''),
        !empty($page['indexable']),
        (string)($page['canonical'] ?? ''),
        is_array($page['c'] ?? null) ? $page['c'] : []
    );
    if (preg_match('~<title\b~i', $html)) $head = (string)preg_replace('~<title>.*?</title>\s*~s', '', $head, 1);
    return (string)preg_replace('~</head\s*>~i', $head."\n</head>", $html, 1);
}

function seo_head(string $title, string $description, bool $indexable, string $canonical, array $site): string
{
    $config = load_config();
    $seo = is_array($config['seo'] ?? null) ? $config['seo'] : [];
    $siteName = (string)($site['site'] ?? 'LiteBBS');
    $fullTitle = $title.' · '.$siteName;
    $canonicalPath = seo_normalize_path($canonical);
    if ($canonicalPath === '/' && trim((string)($seo['home_description'] ?? '')) !== '') $description = (string)$seo['home_description'];
    $indexable = $indexable && seo_indexing_enabled();
    $summary = trim((string)preg_replace('/\s+/u', ' ', strip_tags($description)));
    $summary = text_slice($summary, 0, 220);
    $html = '<meta name="description" content="'.e($summary).'">'."\n";
    $html .= '<meta name="robots" content="'.($indexable ? 'index,follow' : 'noindex,follow').'">'."\n";
    $html .= '<title>'.e($fullTitle).'</title>';
    foreach (['google-site-verification'=>'google_verification','msvalidate.01'=>'bing_verification','baidu-site-verification'=>'baidu_verification','yandex-verification'=>'yandex_verification','naver-site-verification'=>'naver_verification'] as $name=>$key) {
        $value = trim((string)($seo[$key] ?? ''));
        if ($value !== '') $html .= "\n".'<meta name="'.e($name).'" content="'.e($value).'">';
    }
    if (!$indexable) return $html;

    $canonical = seo_normalize_canonical($canonical);
    $parts = parse_url($canonical);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return $html;
    $origin = strtolower((string)$parts['scheme']).'://'.strtolower((string)$parts['host']).(isset($parts['port']) ? ':'.(int)$parts['port'] : '');
    $locale = str_replace('-', '_', (string)($site['locale'] ?? 'zh-CN'));
    $page = ['@type'=>'WebPage','@id'=>$canonical.'#webpage','url'=>$canonical,'name'=>$fullTitle,'description'=>$summary,'isPartOf'=>['@id'=>$origin.'/#website']];
    if (preg_match('~^/t/[1-9][0-9]*$~', (string)($parts['path'] ?? ''))) {
        $page['mainEntity'] = ['@type'=>'DiscussionForumPosting','@id'=>$canonical.'#discussion','url'=>$canonical,'headline'=>$title,'description'=>$summary];
    }
    $schema = ['@context'=>'https://schema.org','@graph'=>[
        ['@type'=>'WebSite','@id'=>$origin.'/#website','url'=>$origin.'/','name'=>$siteName],
        $page,
    ]];
    $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= "\n".'<link rel="canonical" href="'.e($canonical).'">';
    $html .= "\n".'<meta property="og:type" content="website">';
    $html .= "\n".'<meta property="og:locale" content="'.e($locale).'">';
    $html .= "\n".'<meta property="og:site_name" content="'.e($siteName).'">';
    $html .= "\n".'<meta property="og:title" content="'.e($fullTitle).'">';
    $html .= "\n".'<meta property="og:description" content="'.e($summary).'">';
    $html .= "\n".'<meta property="og:url" content="'.e($canonical).'">';
    $html .= "\n".'<meta name="twitter:card" content="summary">';
    $html .= "\n".'<meta name="twitter:title" content="'.e($fullTitle).'">';
    $html .= "\n".'<meta name="twitter:description" content="'.e($summary).'">';
    if (is_string($schemaJson)) $html .= "\n".'<script type="application/ld+json">'.$schemaJson.'</script>';
    return $html;
}

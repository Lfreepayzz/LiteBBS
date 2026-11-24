<?php
declare(strict_types=1);

final class MarkdownPlugin implements LiteBBSPlugin
{
    public function boot(PluginContext $context): void
    {
        $context->assets()->css('assets/editor.css', ['topic.create','topic.view','post.edit']);
        $context->assets()->js('assets/editor.js', ['topic.create','topic.view','post.edit']);
        $context->routes()->front('POST', '~^/markdown/preview$~', static function (\LiteBBS\Framework\Request $request): \LiteBBS\Framework\Response {
            $source = (string)($request->post['body'] ?? '');
            if (text_length($source) > 12000) return new \LiteBBS\Framework\Response('预览内容不能超过 12000 个字符。', 422);
            return (new \LiteBBS\Framework\Response(markdown_plugin_render($source)))
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Cache-Control', 'no-store');
        }, ['auth'=>'user', 'csrf'=>true]);
        $editor = static fn(array $view): string => '<div class="markdown-editor-mount" data-markdown-editor></div>';
        $context->slots()->add('topic.create.fields', $editor);
        $context->slots()->add('reply.create.fields', $editor);
        $context->slots()->add('post.edit.fields', $editor);
        $context->content()->markdown(static fn(string $markdown, array $view): string => markdown_plugin_render($markdown), 1000);
    }
}

function markdown_plugin_render(string $source): string
{
    $source = str_replace(["\r\n", "\r"], "\n", $source);
    $lines = explode("\n", $source);
    $references = [];
    $footnotes = [];
    markdown_plugin_extract_definitions($lines, $references, $footnotes);
    $usedFootnotes = [];
    $html = markdown_plugin_blocks($lines, $references, $footnotes, $usedFootnotes);
    return '<div class="markdown-body">'.$html.markdown_plugin_footnotes($references, $footnotes, $usedFootnotes).'</div>';
}

function markdown_plugin_extract_definitions(array &$lines, array &$references, array &$footnotes): void
{
    $count = count($lines);
    for ($index = 0; $index < $count; $index++) {
        $line = $lines[$index];
        if (preg_match('/^\s{0,3}\[\^([^\]\n]+)\]:\s*(.*)$/', $line, $match)) {
            $body = [$match[2]];
            while ($index + 1 < $count && preg_match('/^(?: {2,}|\t)(.*)$/', $lines[$index + 1], $continued)) {
                $body[] = $continued[1];
                $lines[++$index] = '';
            }
            $key = markdown_plugin_reference_key($match[1]);
            if (!isset($footnotes[$key])) $footnotes[$key] = $body;
            $lines[$index - count($body) + 1] = '';
            continue;
        }
        if (!preg_match('/^\s{0,3}\[([^\]\n]+)\]:\s*(?:<([^>]+)>|(\S+))(?:\s+(?:"([^"]*)"|\'([^\']*)\'|\(([^)]*)\)))?\s*$/', $line, $match)) continue;
        $key = markdown_plugin_reference_key($match[1]);
        if (!isset($references[$key])) {
            $references[$key] = [
                'url' => (string)(($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')),
                'title' => (string)(($match[4] ?? '') !== '' ? $match[4] : (($match[5] ?? '') !== '' ? $match[5] : ($match[6] ?? ''))),
            ];
        }
        $lines[$index] = '';
    }
}

function markdown_plugin_blocks(array $lines, array $references, array $footnotes, array &$usedFootnotes, bool $tight = false): string
{
    $html = '';
    $count = count($lines);
    for ($index = 0; $index < $count;) {
        $line = $lines[$index];
        if (trim($line) === '') {
            $index++;
            continue;
        }

        if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*([^\s`]*)?.*$/', $line, $match)) {
            $marker = $match[1];
            $language = (string)($match[2] ?? '');
            $code = [];
            $index++;
            $close = '/^\s{0,3}'.preg_quote($marker[0], '/').'{'.strlen($marker).',}\s*$/';
            while ($index < $count && !preg_match($close, $lines[$index])) $code[] = $lines[$index++];
            if ($index < $count) $index++;
            $class = $language === '' ? '' : ' class="language-'.e($language).'"';
            $html .= markdown_plugin_code_block(implode("\n", $code), $class);
            continue;
        }

        if (preg_match('/^\s{0,3}(#{1,6})(?:\s+|$)(.*?)\s*#*\s*$/', $line, $match)) {
            $level = strlen($match[1]);
            $content = trim($match[2]);
            $html .= '<h'.$level.' id="'.e(markdown_plugin_slug($content)).'">'.markdown_plugin_inline($content, $references, $footnotes, $usedFootnotes).'</h'.$level.'>';
            $index++;
            continue;
        }

        if ($index + 1 < $count && trim($line) !== '' && preg_match('/^\s{0,3}(=+|-+)\s*$/', $lines[$index + 1], $match)) {
            $level = $match[1][0] === '=' ? 1 : 2;
            $content = trim($line);
            $html .= '<h'.$level.' id="'.e(markdown_plugin_slug($content)).'">'.markdown_plugin_inline($content, $references, $footnotes, $usedFootnotes).'</h'.$level.'>';
            $index += 2;
            continue;
        }

        if (preg_match('/^\s{0,3}(?:(?:\*\s*){3,}|(?:-\s*){3,}|(?:_\s*){3,})$/', $line)) {
            $html .= '<hr>';
            $index++;
            continue;
        }

        if (preg_match('/^\s{0,3}>/', $line)) {
            $quote = [];
            while ($index < $count) {
                if (preg_match('/^\s{0,3}> ?(.*)$/', $lines[$index], $match)) {
                    $quote[] = $match[1];
                    $index++;
                    continue;
                }
                if (trim($lines[$index]) === '' && $index + 1 < $count && preg_match('/^\s{0,3}>/', $lines[$index + 1])) {
                    $quote[] = '';
                    $index++;
                    continue;
                }
                break;
            }
            $alert = '';
            if ($quote && preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*$/i', $quote[0], $match)) {
                $alert = strtolower($match[1]);
                array_shift($quote);
            }
            $body = markdown_plugin_blocks($quote, $references, $footnotes, $usedFootnotes);
            if ($alert !== '') {
                $labels = ['note'=>'Note','tip'=>'Tip','important'=>'Important','warning'=>'Warning','caution'=>'Caution'];
                $html .= '<aside class="markdown-alert markdown-alert-'.$alert.'"><p class="markdown-alert-title">'.$labels[$alert].'</p>'.$body.'</aside>';
            } else {
                $html .= '<blockquote>'.$body.'</blockquote>';
            }
            continue;
        }

        if (markdown_plugin_list_marker($line) !== null) {
            [$listHtml, $index] = markdown_plugin_list($lines, $index, $references, $footnotes, $usedFootnotes);
            $html .= $listHtml;
            continue;
        }

        if (preg_match('/^(?: {4}|\t)(.*)$/', $line, $match)) {
            $code = [$match[1]];
            $index++;
            while ($index < $count) {
                if (preg_match('/^(?: {4}|\t)(.*)$/', $lines[$index], $match)) {
                    $code[] = $match[1];
                    $index++;
                    continue;
                }
                if (trim($lines[$index]) === '') {
                    $code[] = '';
                    $index++;
                    continue;
                }
                break;
            }
            while ($code && end($code) === '') array_pop($code);
            $html .= markdown_plugin_code_block(implode("\n", $code));
            continue;
        }

        $alignments = $index + 1 < $count ? markdown_plugin_table_alignments($lines[$index + 1]) : null;
        $headers = $alignments !== null ? markdown_plugin_table_cells($line) : [];
        if ($alignments !== null && count($headers) > 0) {
            $rows = [];
            $index += 2;
            while ($index < $count && trim($lines[$index]) !== '' && str_contains($lines[$index], '|')) {
                $rows[] = markdown_plugin_table_cells($lines[$index++]);
            }
            $html .= markdown_plugin_table($headers, $alignments, $rows, $references, $footnotes, $usedFootnotes);
            continue;
        }

        $paragraph = [$line];
        $index++;
        while ($index < $count && trim($lines[$index]) !== '') {
            if (markdown_plugin_starts_block($lines, $index)) break;
            $paragraph[] = $lines[$index++];
        }
        $content = markdown_plugin_inline(implode("\n", $paragraph), $references, $footnotes, $usedFootnotes);
        $html .= $tight ? $content : '<p>'.$content.'</p>';
    }
    return $html;
}

function markdown_plugin_code_block(string $code, string $class = ''): string
{
    return '<div class="markdown-code-block"><button type="button" class="markdown-code-copy" aria-label="复制代码">复制</button><pre><code'.$class.'>'.e($code).'</code></pre></div>';
}

function markdown_plugin_starts_block(array $lines, int $index): bool
{
    $line = $lines[$index];
    if (preg_match('/^\s{0,3}(?:#{1,6}(?:\s+|$)|>|`{3,}|~{3,}|(?:(?:\*\s*){3,}|(?:-\s*){3,}|(?:_\s*){3,}))/', $line)) return true;
    if (markdown_plugin_list_marker($line) !== null || preg_match('/^(?: {4}|\t)/', $line)) return true;
    if ($index + 1 < count($lines) && preg_match('/^\s{0,3}(?:=+|-+)\s*$/', $lines[$index + 1])) return true;
    return $index + 1 < count($lines) && markdown_plugin_table_alignments($lines[$index + 1]) !== null && str_contains($line, '|');
}

function markdown_plugin_list_marker(string $line): ?array
{
    if (!preg_match('/^(\s{0,3})([*+-]|(\d{1,9})[.)])([ \t]+)(.*)$/', $line, $match)) return null;
    $ordered = isset($match[3]) && $match[3] !== '';
    return [
        'type' => $ordered ? 'ol' : 'ul',
        'start' => $ordered ? max(1, (int)$match[3]) : 1,
        'indent' => strlen($match[1].$match[2].$match[4]),
        'body' => $match[5],
    ];
}

function markdown_plugin_list(array $lines, int $index, array $references, array $footnotes, array &$usedFootnotes): array
{
    $first = markdown_plugin_list_marker($lines[$index]);
    $type = $first['type'];
    $start = $first['start'];
    $items = [];
    $loose = false;
    $count = count($lines);

    while ($index < $count) {
        $marker = markdown_plugin_list_marker($lines[$index]);
        if ($marker === null || $marker['type'] !== $type) break;
        $item = [$marker['body']];
        $indent = $marker['indent'];
        $index++;
        $sawBlank = false;
        while ($index < $count) {
            if (trim($lines[$index]) === '') {
                $item[] = '';
                $sawBlank = true;
                $index++;
                if ($index >= $count) break;
                continue;
            }
            $nextMarker = markdown_plugin_list_marker($lines[$index]);
            if ($nextMarker !== null && strlen($lines[$index]) - strlen(ltrim($lines[$index], ' ')) < $indent) break;
            if (preg_match('/^ {'.min($indent, 4).'}(.*)$/', $lines[$index], $continued)) {
                $item[] = $continued[1];
                $index++;
                continue;
            }
            if ($nextMarker === null && !$sawBlank) {
                $item[] = ltrim($lines[$index]);
                $index++;
                continue;
            }
            break;
        }
        while ($item && end($item) === '') array_pop($item);
        if ($sawBlank) $loose = true;
        $items[] = $item;
    }

    $taskList = false;
    $rendered = [];
    foreach ($items as $item) {
        $checkbox = '';
        if (isset($item[0]) && preg_match('/^\[([ xX])\](?:\s+|$)(.*)$/', $item[0], $match)) {
            $taskList = true;
            $checkbox = '<input type="checkbox" disabled'.($match[1] !== ' ' ? ' checked' : '').'>';
            $item[0] = $match[2];
        }
        $body = markdown_plugin_blocks($item, $references, $footnotes, $usedFootnotes, !$loose);
        if ($checkbox !== '') {
            $body = $checkbox.'<div class="task-list-item-content">'.$body.'</div>';
        }
        $rendered[] = '<li'.($checkbox !== '' ? ' class="task-list-item"' : '').'>'.$body.'</li>';
    }
    $attributes = $type === 'ol' && $start !== 1 ? ' start="'.$start.'"' : '';
    if ($taskList) $attributes .= ' class="task-list"';
    return ['<'.$type.$attributes.'>'.implode('', $rendered).'</'.$type.'>', $index];
}

function markdown_plugin_table_cells(string $line): array
{
    $line = trim($line);
    if (str_starts_with($line, '|')) $line = substr($line, 1);
    if (str_ends_with($line, '|') && !str_ends_with($line, '\\|')) $line = substr($line, 0, -1);
    $cells = [];
    $cell = '';
    $codeMarker = '';
    $length = strlen($line);
    for ($offset = 0; $offset < $length; $offset++) {
        if ($line[$offset] === '`') {
            preg_match('/\G`+/', $line, $match, 0, $offset);
            $ticks = $match[0];
            $codeMarker = $codeMarker === '' ? $ticks : ($codeMarker === $ticks ? '' : $codeMarker);
            $cell .= $ticks;
            $offset += strlen($ticks) - 1;
            continue;
        }
        if ($line[$offset] === '|' && $codeMarker === '' && ($offset === 0 || $line[$offset - 1] !== '\\')) {
            $cells[] = trim($cell);
            $cell = '';
            continue;
        }
        $cell .= $line[$offset];
    }
    $cells[] = trim($cell);
    return array_map(static fn(string $value): string => str_replace('\|', '|', $value), $cells);
}

function markdown_plugin_table_alignments(string $line): ?array
{
    if (!str_contains($line, '|')) return null;
    $cells = markdown_plugin_table_cells($line);
    if (!$cells) return null;
    $alignments = [];
    foreach ($cells as $cell) {
        if (!preg_match('/^(:)?-{3,}(:)?$/', trim($cell), $match)) return null;
        $alignments[] = !empty($match[1]) && !empty($match[2]) ? 'center' : (!empty($match[2]) ? 'right' : (!empty($match[1]) ? 'left' : ''));
    }
    return $alignments;
}

function markdown_plugin_table(array $headers, array $alignments, array $rows, array $references, array $footnotes, array &$usedFootnotes): string
{
    $columns = count($alignments);
    $headers = array_slice(array_pad($headers, $columns, ''), 0, $columns);
    $html = '<div class="markdown-table-wrap"><table><thead><tr>';
    foreach ($headers as $index => $header) {
        $align = $alignments[$index] === '' ? '' : ' class="markdown-align-'.$alignments[$index].'"';
        $html .= '<th'.$align.'>'.markdown_plugin_inline($header, $references, $footnotes, $usedFootnotes).'</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $row = array_slice(array_pad($row, $columns, ''), 0, $columns);
        $html .= '<tr>';
        foreach ($row as $index => $cell) {
            $align = $alignments[$index] === '' ? '' : ' class="markdown-align-'.$alignments[$index].'"';
            $html .= '<td'.$align.'>'.markdown_plugin_inline($cell, $references, $footnotes, $usedFootnotes).'</td>';
        }
        $html .= '</tr>';
    }
    return $html.'</tbody></table></div>';
}

function markdown_plugin_inline(string $source, array $references, array $footnotes, array &$usedFootnotes): string
{
    static $requestTokenPrefix = null;
    static $tokenSequence = 0;
    $tokens = [];
    $requestTokenPrefix ??= bin2hex(random_bytes(8));
    $tokenPrefix = "\x1fmd".$requestTokenPrefix.'-'.(++$tokenSequence).'-';
    $stash = static function (string $html) use (&$tokens, $tokenPrefix): string {
        $marker = $tokenPrefix.count($tokens)."\x1f";
        $tokens[] = $html;
        return $marker;
    };

    $text = '';
    $length = strlen($source);
    for ($offset = 0; $offset < $length;) {
        if ($source[$offset] === '`' && preg_match('/\G(`+)/', $source, $match, 0, $offset)) {
            $marker = $match[1];
            $end = strpos($source, $marker, $offset + strlen($marker));
            if ($end !== false) {
                $code = str_replace("\n", ' ', substr($source, $offset + strlen($marker), $end - $offset - strlen($marker)));
                if (strlen($code) > 1 && $code[0] === ' ' && str_ends_with($code, ' ') && trim($code) !== '') $code = substr($code, 1, -1);
                $text .= $stash('<code>'.e($code).'</code>');
                $offset = $end + strlen($marker);
                continue;
            }
        }
        if ($source[$offset] === '\\' && isset($source[$offset + 1]) && ($source[$offset + 1] === "\n" || str_contains('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~', $source[$offset + 1]))) {
            if ($source[$offset + 1] === "\n") $text .= $stash('<br>');
            else $text .= $stash(e($source[$offset + 1]));
            $offset += 2;
            continue;
        }
        if (substr($source, $offset, 2) === '[^' && preg_match('/\G\[\^([^\]\n]+)\]/', $source, $match, 0, $offset)) {
            $key = markdown_plugin_reference_key($match[1]);
            if (isset($footnotes[$key])) {
                if (!isset($usedFootnotes[$key])) $usedFootnotes[$key] = count($usedFootnotes) + 1;
                $number = $usedFootnotes[$key];
                $text .= $stash('<sup id="fnref-'.e($key).'"><a href="#fn-'.e($key).'" class="footnote-ref">'.$number.'</a></sup>');
                $offset += strlen($match[0]);
                continue;
            }
        }
        if (($source[$offset] === '!' || $source[$offset] === '[') && preg_match('/\G(!?)\[([^\]\n]*)\]\(\s*(?:<([^>\n]+)>|((?:\\.|[^\s)])+?))(?:\s+(?:"([^"]*)"|\'([^\']*)\'|\(([^)]*)\)))?\s*\)/', $source, $match, 0, $offset)) {
            $image = $match[1] === '!';
            $target = ($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '');
            $url = $image ? markdown_plugin_image_url($target) : markdown_plugin_url($target);
            if ($url !== null) {
                $titleValue = ($match[5] ?? '') !== '' ? $match[5] : (($match[6] ?? '') !== '' ? $match[6] : ($match[7] ?? ''));
                $title = $titleValue === '' ? '' : ' title="'.e($titleValue).'"';
                $label = $match[2];
                $html = $image
                    ? markdown_plugin_image($url, strip_tags(markdown_plugin_inline($label, $references, [], $usedFootnotes)), $title)
                    : '<a href="'.e($url).'"'.$title.' rel="nofollow noopener">'.markdown_plugin_inline($label, $references, $footnotes, $usedFootnotes).'</a>';
                $text .= $stash($html);
                $offset += strlen($match[0]);
                continue;
            }
        }
        if (($source[$offset] === '!' || $source[$offset] === '[') && preg_match('/\G(!?)\[([^\]\n]+)\](?:\[([^\]\n]*)\])?/', $source, $match, 0, $offset)) {
            $key = markdown_plugin_reference_key(($match[3] ?? '') !== '' ? $match[3] : $match[2]);
            if (isset($references[$key])) {
                $image = $match[1] === '!';
                $url = $image ? markdown_plugin_image_url($references[$key]['url']) : markdown_plugin_url($references[$key]['url']);
                if ($url !== null) {
                    $title = $references[$key]['title'] === '' ? '' : ' title="'.e($references[$key]['title']).'"';
                    $html = $image
                        ? markdown_plugin_image($url, $match[2], $title)
                        : '<a href="'.e($url).'"'.$title.' rel="nofollow noopener">'.markdown_plugin_inline($match[2], $references, $footnotes, $usedFootnotes).'</a>';
                    $text .= $stash($html);
                    $offset += strlen($match[0]);
                    continue;
                }
            }
        }
        if ($source[$offset] === '<' && preg_match('/\G<(https?:\/\/[^\s<>]+|mailto:[^\s<>]+|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})>/i', $source, $match, 0, $offset)) {
            $email = preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i', $match[1]) === 1;
            $url = $email ? 'mailto:'.$match[1] : $match[1];
            $label = str_starts_with(strtolower($match[1]), 'mailto:') ? substr($match[1], 7) : $match[1];
            $text .= $stash('<a href="'.e($url).'" rel="nofollow noopener">'.e($label).'</a>');
            $offset += strlen($match[0]);
            continue;
        }
        $text .= $source[$offset++];
    }

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $output = e($text);
    $output = preg_replace_callback('/(?<!\*)\*\*\*([^\n]+?)\*\*\*(?!\*)|(?<!_)___([^\n]+?)___(?!_)/', static fn(array $m): string => '<strong><em>'.(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '')).'</em></strong>', $output) ?? $output;
    $output = preg_replace_callback('/(?<!\*)\*\*([^\n]+?)\*\*(?!\*)|(?<!_)__([^\n]+?)__(?!_)/', static fn(array $m): string => '<strong>'.(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '')).'</strong>', $output) ?? $output;
    $output = preg_replace('/~~([^\n]+?)~~/', '<del>$1</del>', $output) ?? $output;
    $output = preg_replace('/(?<![\pL\pN_*])(\*|_)(?!\s)([^\n]+?)(?<!\s)\1(?![\pL\pN_])/u', '<em>$2</em>', $output) ?? $output;
    $output = preg_replace_callback('~(?<!["=])(https?://[^\s<>\x1f]+|www\.[^\s<>\x1f]+|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})~i', static function (array $match): string {
        $target = rtrim($match[1], '.,:;!?)]}');
        $trailing = substr($match[1], strlen($target));
        $email = preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i', $target) === 1;
        $url = $email ? 'mailto:'.$target : (str_starts_with(strtolower($target), 'www.') ? 'https://'.$target : $target);
        return '<a href="'.e(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')).'" rel="nofollow noopener">'.$target.'</a>'.$trailing;
    }, $output) ?? $output;
    $output = preg_replace('/ {2,}\n/', '<br>', $output) ?? $output;
    $output = str_replace("\n", ' ', $output);
    foreach ($tokens as $index => $token) $output = str_replace($tokenPrefix.$index."\x1f", $token, $output);
    return $output;
}

function markdown_plugin_footnotes(array $references, array $footnotes, array $usedFootnotes): string
{
    if (!$usedFootnotes) return '';
    asort($usedFootnotes);
    $html = '<section class="footnotes"><hr><ol>';
    foreach ($usedFootnotes as $key => $number) {
        if (!isset($footnotes[$key])) continue;
        $nestedUsed = [];
        $body = markdown_plugin_blocks($footnotes[$key], $references, [], $nestedUsed);
        $back = '<a href="#fnref-'.e($key).'" class="footnote-backref" aria-label="Back to content">&#8617;</a>';
        $html .= '<li id="fn-'.e($key).'">'.$body.$back.'</li>';
    }
    return $html.'</ol></section>';
}

function markdown_plugin_url(string $url): ?string
{
    $url = str_replace(['\\(', '\\)', '\\_'], ['(', ')', '_'], $url);
    if ($url === '' || preg_match('/[\x00-\x20<>"\\\\]/', $url)) return null;
    $parts = parse_url($url);
    if (!is_array($parts)) return null;
    if (!isset($parts['scheme'])) return str_starts_with($url, '//') ? null : $url;
    return in_array(strtolower((string)$parts['scheme']), ['http', 'https', 'mailto'], true) ? $url : null;
}

function markdown_plugin_image_url(string $url): ?string
{
    $safeUrl = markdown_plugin_url($url);
    if ($safeUrl === null) return null;
    $parts = parse_url($safeUrl);
    return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'mailto' ? null : $safeUrl;
}

function markdown_plugin_image(string $url, string $label, string $title): string
{
    if (preg_match('~^https?://~i', $url) && !same_request_origin($url, (string)(load_config()['url'] ?? ''))) {
        return '<a href="'.e($url).'"'.$title.' rel="nofollow noopener noreferrer">'.e($label !== '' ? $label : $url).'</a>';
    }
    return '<img src="'.e($url).'" alt="'.e($label).'"'.$title.' loading="lazy">';
}

function markdown_plugin_reference_key(string $label): string
{
    return strtolower(trim(preg_replace('/\s+/u', ' ', $label) ?? $label));
}

function markdown_plugin_slug(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $slug = preg_replace('/[^\pL\pN]+/u', '-', strtolower($text)) ?? '';
    $slug = trim($slug, '-');
    return $slug === '' ? 'section' : $slug;
}

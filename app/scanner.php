<?php
declare(strict_types=1);

/** Independent, read-only scanner for installed plugin and theme packages. */
function extension_security_rules(): array
{
    return [
        ['id'=>'scanner-test-marker', 'severity'=>'test', 'label'=>'LiteBBS 无害病毒扫描测试标记', 'extensions'=>['txt'], 'pattern'=>'/LITEBBS-SCANNER-TEST-MARKER/'],
        ['id'=>'eicar', 'severity'=>'high', 'label'=>'EICAR 测试病毒特征', 'extensions'=>['php','js','css','html','htm','json','txt','md'], 'pattern'=>'~X5O!P%@AP\[4\\\\PZX54\(P\^\)7CC\)7}\$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!~i'],
        ['id'=>'php-eval', 'severity'=>'high', 'label'=>'动态 PHP 执行', 'extensions'=>['php'], 'pattern'=>'/\beval\s*\(/i'],
        ['id'=>'php-obfuscated-execution', 'severity'=>'high', 'label'=>'混淆后的动态代码执行', 'extensions'=>['php'], 'pattern'=>'/\b(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\([^;]{0,4096}\)\s*(?:;|\.)[^;]{0,4096}\b(?:eval|assert|create_function)\s*\(/is'],
        ['id'=>'php-remote-include', 'severity'=>'high', 'label'=>'远程代码包含', 'extensions'=>['php'], 'pattern'=>'/\b(?:include|include_once|require|require_once)\s*\(?\s*["\']https?:\/\//i'],
        ['id'=>'php-command-execution', 'severity'=>'high', 'label'=>'系统命令执行', 'extensions'=>['php'], 'pattern'=>'/\b(?:exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(/i'],
        ['id'=>'php-network-shell', 'severity'=>'high', 'label'=>'疑似网络后门', 'extensions'=>['php'], 'pattern'=>'/\b(?:fsockopen|stream_socket_client|curl_exec|curl_multi_exec)\s*\(/i'],
        ['id'=>'php-user-input-execution', 'severity'=>'high', 'label'=>'请求输入参与代码执行', 'extensions'=>['php'], 'pattern'=>'/\$_(?:GET|POST|REQUEST|COOKIE)\b.{0,512}\b(?:eval|assert|include|require|system|exec|shell_exec)\s*\(/is'],
        ['id'=>'php-obfuscation', 'severity'=>'medium', 'label'=>'可疑代码混淆', 'extensions'=>['php'], 'pattern'=>'/\b(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i'],
        ['id'=>'javascript-obfuscation', 'severity'=>'medium', 'label'=>'可疑 JavaScript 混淆', 'extensions'=>['js'], 'pattern'=>'/\b(?:eval|Function)\s*\(|\b(?:atob|unescape)\s*\(/i'],
        ['id'=>'hidden-iframe', 'severity'=>'medium', 'label'=>'隐藏远程 iframe', 'extensions'=>['php','html','htm'], 'pattern'=>'/<iframe\b[^>]+(?:display\s*:\s*none|visibility\s*:\s*hidden)[^>]+src\s*=\s*["\']https?:\/\//i'],
    ];
}

function extension_security_package_files(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
        if ($entry->isLink()) {
            $files[] = ['path'=>$relative, 'error'=>'发现符号链接'];
            continue;
        }
        if ($entry->isFile()) $files[] = ['path'=>$relative, 'file'=>$entry->getPathname()];
    }
    return $files;
}

function extension_security_virustotal_config(): array
{
    $config = load_config();
    $provider = (string)($config['security_scanner_provider'] ?? 'virustotal');
    return ['provider'=>in_array($provider, ['virustotal', 'malwarebazaar'], true) ? $provider : 'virustotal', 'key'=>(string)($config['virustotal_api_key'] ?? ''), 'malwarebazaar_key'=>(string)($config['malwarebazaar_api_key'] ?? ''), 'upload_unknown'=>!empty($config['virustotal_upload_unknown'])];
}

function &extension_security_virustotal_request_state(): array
{
    static $state = ['requests'=>0, 'limit'=>4];
    return $state;
}

function extension_security_virustotal_malicious_threshold(): int { return 3; }

function extension_security_virustotal_request(string $url, string $key, string $method='GET', ?string $body=null, array $headers=[]): array
{
    $headers = array_merge(['Accept: application/json', 'x-apikey: '.$key, 'User-Agent: LiteBBS-extension-scanner/1.0'], $headers);
    $context = stream_context_create(['http'=>['method'=>$method, 'timeout'=>20, 'ignore_errors'=>true, 'header'=>implode("\r\n", $headers)."\r\n", 'content'=>$body ?? '']]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) { $status = (int)$match[1]; break; }
    $data = is_string($response) ? json_decode($response, true) : null;
    return ['status'=>$status, 'data'=>is_array($data) ? $data : []];
}

function extension_security_virustotal_file(string $file, string $relative): array
{
    $config = extension_security_virustotal_config();
    if ($config['provider'] === 'malwarebazaar') return extension_security_malwarebazaar_file($file);
    if ($config['key'] === '') return ['state'=>'unconfigured'];
    $state =& extension_security_virustotal_request_state();
    if ($state['requests'] >= $state['limit']) return ['state'=>'deferred'];
    ++$state['requests'];
    $hash = @hash_file('sha256', $file);
    if (!is_string($hash)) return ['state'=>'error', 'message'=>'无法计算文件 SHA-256'];
    $lookup = extension_security_virustotal_request('https://www.virustotal.com/api/v3/files/'.$hash, $config['key']);
    if ($lookup['status'] === 200) {
        $attributes = $lookup['data']['data']['attributes'] ?? [];
        $stats = is_array($attributes['last_analysis_stats'] ?? null) ? $attributes['last_analysis_stats'] : [];
        return ['state'=>'known', 'hash'=>$hash, 'malicious'=>(int)($stats['malicious'] ?? 0), 'suspicious'=>(int)($stats['suspicious'] ?? 0), 'date'=>(int)($attributes['last_analysis_date'] ?? 0)];
    }
    if ($lookup['status'] === 404 && $config['upload_unknown']) {
        $source = @file_get_contents($file);
        if (!is_string($source) || strlen($source) > 33554432) return ['state'=>'error', 'hash'=>$hash, 'message'=>'未知文件无法上传或超过 VirusTotal 32 MiB 上传限制'];
        $boundary = '----LiteBBS'.bin2hex(random_bytes(12));
        $name = str_replace(['"', "\r", "\n"], '', basename($relative));
        $body = '--'.$boundary."\r\n".'Content-Disposition: form-data; name="file"; filename="'.$name.'"'."\r\nContent-Type: application/octet-stream\r\n\r\n".$source."\r\n--".$boundary."--\r\n";
        $upload = extension_security_virustotal_request('https://www.virustotal.com/api/v3/files', $config['key'], 'POST', $body, ['Content-Type: multipart/form-data; boundary='.$boundary]);
        return ['state'=>$upload['status'] === 200 ? 'submitted' : 'error', 'hash'=>$hash, 'message'=>$upload['status'] === 200 ? '未知文件已提交 VirusTotal，等待在线分析完成后重新扫描查看结果。' : 'VirusTotal 未接受未知文件上传。'];
    }
    if ($lookup['status'] === 404) return ['state'=>'unknown', 'hash'=>$hash];
    return ['state'=>'error', 'hash'=>$hash, 'message'=>$lookup['status'] === 429 ? 'VirusTotal API 请求频率受限，请稍后重试。' : 'VirusTotal 查询失败。'];
}

function extension_security_malwarebazaar_file(string $file): array
{
    $config = extension_security_virustotal_config();
    if ($config['malwarebazaar_key'] === '') return ['state'=>'unconfigured'];
    $state =& extension_security_virustotal_request_state();
    if ($state['requests'] >= $state['limit']) return ['state'=>'deferred'];
    ++$state['requests'];
    $hash = @hash_file('sha256', $file);
    if (!is_string($hash)) return ['state'=>'error', 'message'=>'无法计算文件 SHA-256'];
    $body = http_build_query(['query'=>'get_info', 'hash'=>$hash], '', '&');
    $context = stream_context_create(['http'=>['method'=>'POST', 'timeout'=>20, 'ignore_errors'=>true, 'header'=>"Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\nAuth-Key: ". $config['malwarebazaar_key']."\r\nUser-Agent: LiteBBS-extension-scanner/1.0\r\n", 'content'=>$body]]);
    $response = @file_get_contents('https://mb-api.abuse.ch/api/v1/', false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) { $status = (int)$match[1]; break; }
    $data = is_string($response) ? json_decode($response, true) : null;
    if ($status === 401 || $status === 403) return ['state'=>'error', 'hash'=>$hash, 'message'=>'MalwareBazaar API Key 缺失、无效或无权访问。'];
    if ($status === 429) return ['state'=>'error', 'hash'=>$hash, 'message'=>'MalwareBazaar API 请求频率受限，请稍后重试。'];
    if (!is_array($data)) return ['state'=>'error', 'hash'=>$hash, 'message'=>'MalwareBazaar 未返回有效 JSON（HTTP '.$status.'）。'];
    if (($data['query_status'] ?? '') === 'ok') {
        $sample = is_array($data['data'][0] ?? null) ? $data['data'][0] : [];
        return ['state'=>'known', 'hash'=>$hash, 'malicious'=>3, 'suspicious'=>0, 'date'=>strtotime((string)($sample['first_seen'] ?? '')) ?: 0, 'provider'=>'MalwareBazaar'];
    }
    if (($data['query_status'] ?? '') === 'hash_not_found') return ['state'=>'unknown', 'hash'=>$hash];
    return ['state'=>'error', 'hash'=>$hash, 'message'=>'MalwareBazaar 返回状态：'.substr((string)($data['query_status'] ?? 'unknown'), 0, 80).'。'];
}

function extension_security_scan_package(string $kind, string $name): array
{
    if (!in_array($kind, ['plugin', 'theme'], true) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $name)) {
        throw new InvalidArgumentException('扫描对象无效。');
    }
    $directory = dirname(__DIR__).'/'.($kind === 'plugin' ? 'plugins' : 'templates').'/'.$name;
    $onlineConfig = extension_security_virustotal_config();
    $result = ['kind'=>$kind, 'name'=>$name, 'findings'=>[], 'viruses'=>[], 'compatibility'=>[], 'files'=>0, 'online'=>['known'=>0, 'unknown'=>0, 'submitted'=>0, 'deferred'=>0, 'errors'=>0, 'configured'=>$onlineConfig['provider'] === 'malwarebazaar' ? $onlineConfig['malwarebazaar_key'] !== '' : $onlineConfig['key'] !== '', 'provider'=>$onlineConfig['provider']], 'scanned_at'=>gmdate('Y-m-d H:i:s')];
    if (!is_dir($directory) || is_link($directory)) {
        $result['compatibility'][] = '目录不存在或不是安全目录。';
        return $result;
    }
    try {
        extension_validate_directory($directory, $kind, $name);
    } catch (Throwable $error) {
        $result['compatibility'][] = $error->getMessage();
    }
    $manifestFile = $directory.'/'.($kind === 'plugin' ? 'plugin.json' : 'theme.json');
    $manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
    if (!is_array($manifest)) {
        $result['compatibility'][] = '清单文件不存在或 JSON 无效。';
    } elseif ($kind === 'plugin') {
        try {
            foreach (extension_dependency_report($name, $manifest)['errors'] as $error) $result['compatibility'][] = $error;
        } catch (Throwable $error) {
            $result['compatibility'][] = '依赖声明无效：'.$error->getMessage();
        }
    }
    foreach (extension_security_package_files($directory) as $entry) {
        if (isset($entry['error'])) {
            $result['findings'][] = ['severity'=>'high', 'rule'=>'unsafe-link', 'file'=>$entry['path'], 'message'=>$entry['error']];
            continue;
        }
        ++$result['files'];
        $size = @filesize($entry['file']);
        if (!is_int($size) || $size > 2097152) {
            $result['findings'][] = ['severity'=>'medium', 'rule'=>'oversized-file', 'file'=>$entry['path'], 'message'=>'文件不可读取或超过 2 MiB 安全上限'];
            continue;
        }
        $source = @file_get_contents($entry['file']);
        if (!is_string($source)) {
            $result['findings'][] = ['severity'=>'medium', 'rule'=>'unreadable-file', 'file'=>$entry['path'], 'message'=>'文件无法读取'];
            continue;
        }
        $extension = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION));
        foreach (extension_security_rules() as $rule) {
            if (!in_array($extension, $rule['extensions'], true)) continue;
            if (preg_match($rule['pattern'], $source) === 1) $result['findings'][] = ['severity'=>$rule['severity'], 'rule'=>$rule['id'], 'file'=>$entry['path'], 'message'=>$rule['label']];
        }
        $online = extension_security_virustotal_file($entry['file'], $entry['path']);
        if ($online['state'] === 'known') {
            ++$result['online']['known'];
            if ($online['malicious'] >= extension_security_virustotal_malicious_threshold()) $result['viruses'][] = ['file'=>$entry['path'], 'hash'=>$online['hash'], 'malicious'=>$online['malicious'], 'suspicious'=>$online['suspicious'], 'date'=>$online['date'], 'provider'=>$online['provider'] ?? 'VirusTotal'];
        } elseif ($online['state'] === 'submitted') ++$result['online']['submitted'];
        elseif ($online['state'] === 'unknown') ++$result['online']['unknown'];
        elseif ($online['state'] === 'deferred') ++$result['online']['deferred'];
        elseif ($online['state'] === 'error') { ++$result['online']['errors']; $result['online']['message'] = $online['message']; }
    }
    $result['findings'] = array_values(array_unique($result['findings'], SORT_REGULAR));
    $result['viruses'] = array_values(array_unique($result['viruses'], SORT_REGULAR));
    $result['compatibility'] = array_values(array_unique($result['compatibility']));
    return $result;
}

function extension_security_scan_all(): array
{
    $results = [];
    foreach (['plugin'=>'plugins', 'theme'=>'templates'] as $kind=>$root) {
        $path = dirname(__DIR__).'/'.$root;
        if (!is_dir($path) || is_link($path)) continue;
        foreach (new DirectoryIterator($path) as $entry) {
            $name = $entry->getFilename();
            if ($entry->isDot() || !$entry->isDir() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $name)) continue;
            $results[] = extension_security_scan_package($kind, $name);
        }
    }
    usort($results, static fn(array $left, array $right): int => [$left['kind'], $left['name']] <=> [$right['kind'], $right['name']]);
    return $results;
}

function handle_admin_security_scan(\LiteBBS\Framework\Request $request): void
{
    if ($request->path !== '/admin/scanner' || !in_array($request->method, ['GET', 'POST'], true)) return;
    admin();
    if (setting('security_scanner_enabled', '0') !== '1') fail(404, '病毒扫描未开启。');
    $scan = $request->query['scan'] ?? 'all';
    $kind = $request->query['kind'] ?? '';
    $name = $request->query['name'] ?? '';
    if ($request->method === 'POST') {
        require_post();
        $scan = (string)($request->post['scan'] ?? 'all');
        $kind = (string)($request->post['kind'] ?? '');
        $name = (string)($request->post['name'] ?? '');
    }
    if ($scan === 'all') $results = extension_security_scan_all();
    elseif ($scan === 'one' && is_string($kind) && is_string($name)) $results = [extension_security_scan_package($kind, $name)];
    else fail(400, '扫描参数无效。');
    $infected = array_filter($results, static fn(array $result): bool => $result['viruses'] !== []);
    $review = array_filter($results, static fn(array $result): bool => $result['findings'] !== []);
    $incompatible = array_filter($results, static fn(array $result): bool => $result['compatibility'] !== []);
    $clean = count(array_filter($results, static fn(array $result): bool => $result['viruses'] === [] && $result['findings'] === [] && $result['compatibility'] === []));
    $fileCount = array_sum(array_column($results, 'files'));
    $onlineConfigured = $results !== [] && !empty($results[0]['online']['configured']);
    $provider = $results[0]['online']['provider'] ?? 'virustotal';
    $providerName = $provider === 'malwarebazaar' ? 'MalwareBazaar' : 'VirusTotal';
    $online = ['known'=>0, 'unknown'=>0, 'submitted'=>0, 'deferred'=>0, 'errors'=>0];
    foreach ($results as $result) foreach (array_keys($online) as $key) $online[$key] += (int)($result['online'][$key] ?? 0);
    $onlineCompleted = $online['known'] + $online['unknown'] + $online['submitted'] + $online['errors'];
    $threshold = extension_security_virustotal_malicious_threshold();
    $providerDescription = $provider === 'malwarebazaar' ? 'MalwareBazaar 只查询 SHA-256 是否命中已知恶意样本，不上传文件；命中即视为高可信恶意样本情报。' : 'VirusTotal 在线多引擎结果仅用于确认已知病毒；本地静态规则和兼容性问题始终与病毒检测分开显示。';
    $body = '<div class="security-console"><section class="security-hero"><div class="security-hero-copy"><p class="security-kicker"><span></span>EXTENSION DEFENSE</p><h2>插件与模板安全控制台</h2><p>'.e($providerDescription).'</p></div><form method="post" class="security-hero-action" data-security-scan-form><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="scan" value="all"><button class="primary" type="submit">重新扫描全部</button><progress class="security-live-progress" aria-label="正在扫描" hidden></progress><small data-security-scan-status>最后扫描：'.e(gmdate('Y-m-d H:i:s')).' UTC</small></form></section><section class="security-metrics" aria-label="扫描统计"><article class="security-metric"><span>扫描扩展</span><strong>'.count($results).'</strong><small>'.(int)$fileCount.' 个文件已分析</small></article><article class="security-metric'.($infected ? ' is-alert' : ' is-safe').'"><span>已确认病毒</span><strong>'.count($infected).'</strong><small>'.($onlineConfigured ? ($infected ? $providerName.' 检出恶意文件' : '未达到确认门槛') : '未配置 VirusTotal API').'</small></article><article class="security-metric'.($incompatible ? ' is-warning' : ' is-safe').'"><span>兼容异常</span><strong>'.count($incompatible).'</strong><small>'.($incompatible ? '请检查清单与依赖' : '全部满足框架要求').'</small></article></section><section class="security-guide"><div><strong>当前在线源：'.e($providerName).'</strong><span>'.($onlineConfigured ? ($provider === 'malwarebazaar' ? '已启用已知恶意样本哈希查询，不会上传文件。' : '已启用在线哈希查询；至少 '.$threshold.' 个恶意引擎命中才标记为已确认病毒。未知文件是否上传由站点设置控制。') : '未配置 VirusTotal API，仅执行本地安全审计；不会标记任何文件为真实病毒。').'</span></div><div><strong>安全审计提示</strong><span>'.count($review).' 个扩展存在需人工复核的危险行为特征，不等同于病毒。</span></div></section>'.($onlineConfigured ? '<section class="security-online-progress"><div><strong>'.e($providerName).' 本轮查询进度</strong><span>已完成 '.(int)$onlineCompleted.' / '.(int)$fileCount.' 个文件查询</span></div><progress max="'.max(1, $fileCount).'" value="'.min($fileCount, $onlineCompleted).'" aria-label="在线样本查询进度"></progress><p>已知哈希 '.(int)$online['known'].' · 未收录 '.(int)$online['unknown'].' · 已提交分析 '.(int)$online['submitted'].' · 查询失败 '.(int)$online['errors'].' · 因本轮 API 配额延后 '.(int)$online['deferred'].' 个</p></section>' : '').'<section class="security-results"><div class="security-results-heading"><div><p class="security-kicker">SCAN REPORT</p><h2>扩展检测结果</h2></div><span>'.($clean > 0 ? $clean.' 个扩展通过检测' : '等待复核').'</span></div>';
    foreach ($results as $result) {
        $title = ($result['kind'] === 'plugin' ? '插件' : '模板').' '.$result['name'];
        $testFinding = count($result['findings']) === 1 && ($result['findings'][0]['rule'] ?? '') === 'scanner-test-marker';
        $state = $result['viruses'] ? '已确认病毒' : ($testFinding ? '扫描测试命中' : ($result['findings'] ? '需安全复核' : ($result['compatibility'] ? '不兼容' : '通过')));
        $tone = $result['viruses'] ? ' is-alert' : (($result['findings'] || $result['compatibility']) ? ' is-warning' : ' is-safe');
        $body .= '<article class="security-result'.$tone.'"><header><div class="security-package"><span class="security-package-kind">'.($result['kind'] === 'plugin' ? 'PLG' : 'THM').'</span><div><h3>'.e($title).'</h3><p>'.(int)$result['files'].' 个文件 · 扫描完成 '.e($result['scanned_at']).' UTC</p></div></div><div class="security-result-actions"><span class="security-status">'.e($state).'</span><form method="post" data-security-scan-form><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="scan" value="one"><input type="hidden" name="kind" value="'.e($result['kind']).'"><input type="hidden" name="name" value="'.e($result['name']).'"><button type="submit">单独扫描</button><progress class="security-live-progress" aria-label="正在扫描" hidden></progress><small data-security-scan-status hidden></small></form></div></header>';
        if ($result['viruses']) { $body .= '<section class="security-issues"><h4>在线已确认病毒</h4><ul>'; foreach ($result['viruses'] as $virus) $body .= '<li><span class="security-severity is-high">病毒</span><code>'.e($virus['file']).'</code><span>'.e($virus['provider']).' · 恶意引擎：'.(int)$virus['malicious'].' · 可疑引擎：'.(int)$virus['suspicious'].'</span><small>SHA-256 '.e($virus['hash']).'</small></li>'; $body .= '</ul></section>'; }
        if ($result['findings']) { $body .= '<section class="security-issues"><h4>'.($testFinding ? '无害扫描测试标记' : '本地安全审计提示').'</h4><ul>'; foreach ($result['findings'] as $finding) $body .= '<li><span class="security-severity is-'.e($finding['severity']).'">'.e($finding['severity']).'</span><code>'.e($finding['file']).'</code><span>'.e($finding['message']).'</span><small>'.e($finding['rule']).'</small></li>'; $body .= '</ul></section>'; }
        if ($result['compatibility']) { $body .= '<section class="security-issues"><h4>与框架不兼容</h4><ul>'; foreach ($result['compatibility'] as $issue) $body .= '<li><span class="security-severity is-compatibility">兼容性</span><span>'.e($issue).'</span></li>'; $body .= '</ul></section>'; }
        if ($result['online']['configured']) $body .= '<p class="security-online">'.e(($result['online']['provider'] ?? '') === 'malwarebazaar' ? 'MalwareBazaar' : 'VirusTotal').'：已命中 '.(int)$result['online']['known'].' 个已知哈希，未知 '.(int)$result['online']['unknown'].' 个，已提交 '.(int)$result['online']['submitted'].' 个，等待下次扫描 '.(int)$result['online']['deferred'].' 个'.(!empty($result['online']['message']) ? ' · '.e($result['online']['message']) : '').'。</p>';
        $body .= '</article>';
    }
    $body .= '</section></div>';
    admin_page('插件和模板病毒扫描', $body.'<script src="/assets/js/admin-scanner.js?v='.asset_version(dirname(__DIR__).'/assets/js/admin-scanner.js').'" defer></script>');
}

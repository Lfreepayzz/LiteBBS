<?php
declare(strict_types=1);

const LITEBBS_THEME_VIEW_API_VERSION = 2;

function theme_pagination_manifest(string $theme): array
{
    static $manifests = [];
    if (!array_key_exists($theme, $manifests)) {
        $file = extension_secure_runtime_file(theme_runtime_directory($theme), 'theme.json');
        $manifest = $file !== null ? json_decode((string)file_get_contents($file), true) : null;
        $manifests[$theme] = is_array($manifest) ? $manifest : [];
    }
    return $manifests[$theme];
}

function theme_page_size(string $theme, string $context): int
{
    static $sizes = [];
    if (!in_array($context, ['home', 'category', 'topic'], true)) throw new InvalidArgumentException('Invalid pagination context.');
    if (!isset($sizes[$theme])) {
        $manifest = theme_pagination_manifest($theme);
        $sizes[$theme] = ['home'=>30, 'category'=>30, 'topic'=>20];
        foreach ($sizes[$theme] as $key=>$default) {
            $size = is_array($manifest) ? ($manifest['pagination'][$key] ?? $default) : $default;
            if (is_int($size) && $size >= 1 && $size <= 100) $sizes[$theme][$key] = $size;
        }
    }
    return $sizes[$theme][$context];
}

function theme_home_page_path(string $theme): string
{
    static $paths = [];
    if (!array_key_exists($theme, $paths)) {
        $manifest = theme_pagination_manifest($theme);
        $paths[$theme] = is_array($manifest) && ($manifest['pagination']['home_path'] ?? null) === 'forum' ? 'forum' : '';
    }
    return $paths[$theme];
}

function theme_manifest_settings(array $manifest): array
{
    $definitions = $manifest['settings'] ?? [];
    if (!is_array($definitions) || count($definitions) > 32) throw new RuntimeException('Theme settings declaration is invalid.');
    $validated = [];
    foreach ($definitions as $key=>$definition) {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) || !is_array($definition)) throw new RuntimeException('Theme setting name is invalid.');
        $type = (string)($definition['type'] ?? '');
        $label = (string)($definition['label'] ?? '');
        if (!in_array($type, ['text','textarea','select','checkbox','color'], true) || $label === '' || text_length($label) > 80) throw new RuntimeException('Theme setting definition is invalid.');
        $item = ['type'=>$type,'label'=>$label,'default'=>$definition['default'] ?? ($type === 'checkbox' ? false : '')];
        if (in_array($type, ['text','textarea'], true)) {
            $item['max'] = max(1, min(2000, (int)($definition['max'] ?? ($type === 'textarea' ? 500 : 120))));
            if (!is_string($item['default']) || text_length($item['default']) > $item['max']) throw new RuntimeException('Theme setting default is invalid.');
        } elseif ($type === 'color') {
            if (!is_string($item['default']) || !preg_match('/^#[0-9a-fA-F]{6}$/', $item['default'])) throw new RuntimeException('Theme color default is invalid.');
        } elseif ($type === 'checkbox') {
            if (!is_bool($item['default'])) throw new RuntimeException('Theme checkbox default is invalid.');
        } else {
            $options = $definition['options'] ?? [];
            if (!is_array($options) || !$options || count($options) > 32 || !is_string($item['default'])) throw new RuntimeException('Theme select setting is invalid.');
            $item['options'] = [];
            foreach ($options as $value=>$optionLabel) {
                if (!is_string($value) || !preg_match('/^[a-z0-9_-]{1,32}$/', $value) || !is_string($optionLabel) || $optionLabel === '' || text_length($optionLabel) > 80) throw new RuntimeException('Theme select option is invalid.');
                $item['options'][$value] = $optionLabel;
            }
            if (!array_key_exists($item['default'], $item['options'])) throw new RuntimeException('Theme select default is invalid.');
        }
        $validated[$key] = $item;
    }
    return $validated;
}

function theme_settings_from_input(array $input, array $definitions): array
{
    $values = [];
    foreach ($definitions as $key=>$definition) {
        $value = $input['theme_setting_'.$key] ?? null;
        if ($definition['type'] === 'checkbox') { $values[$key] = $value === '1'; continue; }
        if (!is_string($value)) throw new InvalidArgumentException('Theme setting input is invalid.');
        $value = trim($value);
        if (in_array($definition['type'], ['text','textarea'], true) && text_length($value) <= $definition['max']) $values[$key] = $value;
        elseif ($definition['type'] === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) $values[$key] = $value;
        elseif ($definition['type'] === 'select' && isset($definition['options'][$value])) $values[$key] = $value;
        else throw new InvalidArgumentException('Theme setting value is invalid.');
    }
    return $values;
}

function theme_settings(string $theme): array
{
    $directory = theme_runtime_directory($theme);
    $file = extension_secure_runtime_file($directory, 'theme.json');
    if ($file === null) return [];
    try { $manifest = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { return []; }
    if (!is_array($manifest)) return [];
    $definitions = theme_manifest_settings($manifest);
    if (!$definitions) return [];
    $version = cache_version('theme-settings:'.$theme);
    $cached = cached('theme-settings:v1:'.$theme.':'.$version, 300, static function () use ($theme): array {
        $json = h('SELECT config_json FROM themes WHERE name=?', [$theme])->fetchColumn();
        $values = is_string($json) ? json_decode($json, true) : [];
        return [is_array($values) ? $values : []];
    });
    $stored = is_array($cached[0] ?? null) ? $cached[0] : [];
    $values = [];
    foreach ($definitions as $key=>$definition) {
        $value = $stored[$key] ?? $definition['default'];
        $valid = match($definition['type']) {
            'text','textarea' => is_string($value) && text_length($value) <= $definition['max'],
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value),
            'checkbox' => is_bool($value),
            'select' => is_string($value) && isset($definition['options'][$value]),
        };
        $values[$key] = $valid ? $value : $definition['default'];
    }
    return $values;
}

function theme_current_settings(): array
{
    return theme_settings((string)(load_config()['theme'] ?? 'default'));
}

final class ThemeTrustedHtml
{
    private function __construct(private string $html) {}
    public static function fromCore(string $html): self { return new self($html); }
    public function __toString(): string { return $this->html; }
}

function theme_view_contracts(): array
{
    return [
        'home'=>['topics'=>'array','viewer'=>'nullable_array','pagination'=>'html'],
        'category'=>['category'=>'array','topics'=>'array','viewer'=>'nullable_array','pagination'=>'html'],
        'topic'=>['topic'=>'array','posts'=>'array','viewer'=>'nullable_array','reply_form'=>'html'],
        'profile'=>['profile'=>'array','posts'=>'array','viewer'=>'nullable_array'],
        'components.post'=>['post'=>'array','topic'=>'array','viewer'=>'nullable_array','body_html'=>'html'],
        'forms.reply'=>['topic'=>'array','viewer'=>'array','errors'=>'array','values'=>'array'],
        'pagination'=>['pagination'=>'array'],
    ];
}

function theme_view_variables(string $view,array $variables): array
{
    $contract=theme_view_contracts()[$view]??null;
    if(!is_array($contract))throw new InvalidArgumentException('Unknown standard theme view.');
    if(array_diff(array_keys($variables),array_keys($contract))||array_diff(array_keys($contract),array_keys($variables)))throw new InvalidArgumentException('Invalid theme variables for '.$view.'.');
    $safe=['theme_api_version'=>LITEBBS_THEME_VIEW_API_VERSION];
    foreach($contract as $name=>$type){$value=$variables[$name];$valid=match($type){'array'=>is_array($value),'nullable_array'=>$value===null||is_array($value),'html'=>$value instanceof ThemeTrustedHtml,default=>false};if(!$valid)throw new InvalidArgumentException('Invalid theme variable '.$view.'.'.$name.'.');$safe[$name]=$value;}
    return $safe;
}

function theme_value(mixed $value): string
{
    return $value instanceof ThemeTrustedHtml ? (string)$value : e(is_scalar($value)?(string)$value:'');
}

function theme_render_standard(string $view,array $variables,string $fallback): string
{
    $variables=theme_view_variables($view,$variables);
    $variables['safe']=(string)(load_config()['theme']??'default');
    return theme_render_view($view,$variables,$fallback);
}

function theme_runtime_directory(string $theme): string
{
    $root=dirname(__DIR__).'/templates';$direct=$root.'/'.$theme;if(is_dir($direct)&&!is_link($direct)&&!is_link($direct.'/layout.php'))return $direct;
    $match=null;foreach(is_dir($root)?new DirectoryIterator($root):[] as $item){if(!$item->isDir()||$item->isDot()||$item->isLink()||is_link($item->getPathname().'/layout.php'))continue;$manifest=json_decode((string)@file_get_contents($item->getPathname().'/theme.json'),true);if(($manifest['name']??null)!==$theme)continue;if($match!==null)return $root.'/default';$match=$item->getPathname();}if($match!==null)return $match;
    return $root.'/default';
}

function theme_resolve_view(string $view,array $variables,string $fallback,bool $allowTheme=true): string
{
    if(!preg_match('/^[a-z][a-z0-9._-]{0,63}$/',$view))throw new InvalidArgumentException('Invalid theme view.');
    $root=dirname(__DIR__);$theme=(string)($variables['safe']??'default');
    if(!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$theme))$theme='default';
    $file=null;
    if($allowTheme){$relative=$view==='layout'?'layout.php':'views/'.str_replace('.','/',$view).'.php';$file=extension_secure_runtime_file(theme_runtime_directory($theme),$relative);}
    if($file===null){$rootReal=realpath($root);$fallbackReal=realpath($fallback);if($rootReal!==false&&$fallbackReal!==false){$rootPrefix=rtrim(str_replace('\\','/',$rootReal),'/').'/';$normalized=str_replace('\\','/',$fallbackReal);if(str_starts_with($normalized,$rootPrefix))$file=extension_secure_runtime_file($root,substr($normalized,strlen($rootPrefix)));}}
    if($file===null)throw new RuntimeException('Theme view unavailable.');
    return $file;
}

function theme_render_view(string $view,array $variables,string $fallback,bool $allowTheme=true): string
{
    $file=theme_resolve_view($view,$variables,$fallback,$allowTheme);$level=ob_get_level();ob_start();try{extract($variables,EXTR_SKIP);include $file;return (string)ob_get_clean();}catch(Throwable $error){while(ob_get_level()>$level)ob_end_clean();throw $error;}
}

function theme_runtime_template(string $defaultFile, array $variables): string
{
    $root=dirname(__DIR__);$theme=(string)($variables['safe']??'default');if(!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$theme))$theme='default';$directory=theme_runtime_directory($theme);
    $legacyRoot=extension_secure_runtime_file($directory,basename($defaultFile));
    if($legacyRoot!==null&&!is_file($directory.'/views/'.basename($defaultFile))){$level=ob_get_level();ob_start();try{extract($variables,EXTR_SKIP);include $legacyRoot;return (string)ob_get_clean();}catch(Throwable $error){while(ob_get_level()>$level)ob_end_clean();throw $error;}}
    return theme_render_view(pathinfo(basename($defaultFile),PATHINFO_FILENAME),$variables,$defaultFile);
}

function theme_view(string $view, array $variables, string $fallback): string
{
    return theme_render_view($view,$variables,$fallback);
}

function theme_stylesheet(string $theme, string $asset): string
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $theme) || !preg_match('/^[A-Za-z0-9._-]+\.css$/', $asset)) return '';
    $directory=theme_runtime_directory($theme);$file = extension_secure_runtime_file($directory, 'assets/'.$asset);
    if ($file === null) return '';
    return '/templates/'.rawurlencode(basename($directory)).'/assets/'.rawurlencode($asset).'?v='.asset_version($file);
}

function theme_runtime_assets(string $theme, bool $home, bool $topic): string
{
    $assets = ['theme.css'];
    if ($home) $assets[] = 'home.css';
    if ($topic) $assets[] = 'topic.css';
    $html = '';
    foreach ($assets as $asset) {
        $url = theme_stylesheet($theme, $asset);
        if ($url !== '') $html .= '<link rel="stylesheet" href="'.$url.'">';
    }
    if ($topic) {
        $directory=theme_runtime_directory($theme);$file = extension_secure_runtime_file($directory, 'assets/topic.js');
        if ($file !== null) $html .= '<script src="/templates/'.rawurlencode(basename($directory)).'/assets/topic.js?v='.asset_version($file).'" defer></script>';
    }
    return $html;
}

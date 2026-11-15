<?php
declare(strict_types=1);

const LITEBBS_THEME_VIEW_API_VERSION = 1;

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

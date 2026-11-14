<?php
declare(strict_types=1);

$modules=[];
if($path==='/new')$modules[]='plugin-topic.php';
if($path==='/admin/categories')$modules[]='admin-categories.php';
if(str_starts_with($path,'/market')||$path==='/admin/market'||$path==='/admin/extensions')$modules[]='market.php';
if($path==='/admin/themes'||$path==='/admin/plugins')$modules[]='admin-extensions-manage.php';
if(in_array($path,['/admin/users','/admin/settings','/admin/content'],true))$modules[]='admin-users-settings.php';
if($path==='/admin/reports')$modules[]='admin-reports.php';
if($path==='/avatar/upload'||preg_match('~^/(?:avatar/\d+|topic/\d+/(?:bookmark|subscribe)|post/\d+/like|report|user/\d+/(?:block|unblock))$~',$path))$modules[]='community.php';
if($method==='GET'&&(preg_match('~^/(?:t/\d+|c/\d+)$~',$path)||str_starts_with($path,'/u/')))$modules[]='read-pages.php';
if($path==='/admin')$modules[]='admin-dashboard.php';
if($path==='/admin/audit')$modules[]='admin-audit.php';

foreach($modules as $module){
    try{require __DIR__.'/'.$module;}
    catch(\LiteBBS\Framework\ResponseHalt $halt){if(ob_get_level()>0)ob_end_clean();return $halt->response;}
}
return null;

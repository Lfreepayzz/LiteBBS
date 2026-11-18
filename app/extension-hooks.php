<?php
declare(strict_types=1);

const LITEBBS_HOOK_REGISTRY_VERSION = 2;

function extension_hook_registry(): array
{
    static $hooks = [
        'slot'=>[
            'head.assets'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'navigation.primary'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.content.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.content.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.header.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.footer.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'sidebar.sections'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'footer.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'body.end'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'home.feed.before'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'home.feed.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'category.header.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'category.feed.before'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'category.feed.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'topic.create.fields'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.header.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.actions.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.thread.before'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'topic.thread.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'post.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'reply.create.fields'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'reply.form.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.edit.fields'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.edit.form.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'profile.header.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'profile.content.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.after-body'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'admin.head'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'admin.navigation'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'admin.content.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'admin.content.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
        ],
        'preload'=>[
            'post.after-body'=>['version'=>1,'strict'=>false,'return'=>'void'],
            'post.after'=>['version'=>1,'strict'=>false,'return'=>'void'],
        ],
        'renderer'=>[
            'post.markdown'=>['version'=>1,'strict'=>false,'return'=>'html'],
        ],
        'filter'=>[
            'post.rendered'=>['version'=>1,'strict'=>false,'return'=>'html'],
        ],
        'event'=>[
            'topic.create.preflight'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'topic.created'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'post.created'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'post.updated'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'topic.deleting'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'topic.create.preflight.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['actor_id'=>'int','title'=>'string','body'=>'string','category_id'=>'int','uploads'=>'uploads']],
            'topic.created.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int','title'=>'string','body'=>'string','category_id'=>'int','uploads'=>'uploads']],
            'post.created.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int','body'=>'string','uploads'=>'uploads']],
            'post.updated.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int','body'=>'string','previous_body_markdown'=>'string']],
            'topic.updated.async'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int','title'=>'string','category_id'=>'int','previous_title'=>'string','previous_category_id'=>'int']],
            'topic.created.async'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int']],
            'post.created.async'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int']],
            'topic.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','category_id'=>'int','post_ids'=>'int_list','actor_id'=>'int','reason'=>'optional_string']],
            'post.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['post_id'=>'int','topic_id'=>'int','actor_id'=>'int','reason'=>'optional_string']],
            'topic.moderated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','category_id'=>'int','action'=>'string','value'=>'int','actor_id'=>'int']],
            'category.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['category_id'=>'int','actor_id'=>'int']],
            'category.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['category_id'=>'int','actor_id'=>'int']],
            'category.visibility.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['category_id'=>'int','visible'=>'bool','actor_id'=>'int']],
            'reaction.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['post_id'=>'int','topic_id'=>'int','actor_id'=>'int','reaction'=>'string']],
            'reaction.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['post_id'=>'int','topic_id'=>'int','actor_id'=>'int','reaction'=>'string']],
            'bookmark.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int']],
            'bookmark.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int']],
            'subscription.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int']],
            'subscription.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int']],
            'report.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['report_id'=>'int','target_type'=>'string','target_id'=>'int','actor_id'=>'int']],
            'report.reviewed'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['report_id'=>'int','target_type'=>'string','target_id'=>'int','status'=>'string','actor_id'=>'int']],
            'user.registered'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['user_id'=>'int']],
            'user.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['user_id'=>'int','actor_id'=>'int','role'=>'string','status'=>'string','is_developer'=>'int']],
            'user.profile.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['user_id'=>'int']],
            'user.avatar.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['user_id'=>'int']],
            'user.blocked'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['actor_id'=>'int','user_id'=>'int']],
            'user.unblocked'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['actor_id'=>'int','user_id'=>'int']],
        ],
    ];
    return $hooks;
}

function extension_hook_contract(string $kind,string $name): ?array
{
    return extension_hook_registry()[$kind][$name]??null;
}

function extension_safe_uploads(array $files): array
{
    $uploads=[];
    foreach($files as $field=>$file){
        if(!is_string($field)||!is_array($file)||is_array($file['name']??null))continue;
        $uploads[]=['field'=>$field,'name'=>basename(str_replace('\\','/',(string)($file['name']??''))),'type'=>(string)($file['type']??''),'size'=>(int)($file['size']??0),'error'=>(int)($file['error']??UPLOAD_ERR_NO_FILE)];
    }
    return $uploads;
}

function extension_validate_hook_payload(string $name,array $payload): void
{
    $contract=extension_hook_contract('event',$name);
    if(!is_array($contract)||empty($contract['strict']))return;
    $fields=$contract['fields']??[];
    $required=array_keys(array_filter($fields,static fn(string $type):bool=>!str_starts_with($type,'optional_')));
    if(array_diff(array_keys($payload),array_keys($fields))||array_diff($required,array_keys($payload)))throw new InvalidArgumentException('Invalid extension event payload fields: '.$name);
    foreach($fields as $field=>$type){if(!array_key_exists($field,$payload)&&str_starts_with($type,'optional_'))continue;$value=$payload[$field];$valid=match($type){'int'=>is_int($value)&&$value>=0,'string','optional_string'=>is_string($value),'bool'=>is_bool($value),'int_list'=>is_array($value)&&array_reduce($value,static fn(bool $ok,mixed $item):bool=>$ok&&is_int($item)&&$item>=0,true),'uploads'=>is_array($value)&&array_reduce($value,static fn(bool $ok,mixed $upload):bool=>$ok&&is_array($upload)&&!array_diff(array_keys($upload),['field','name','type','size','error'])&&!array_diff(['field','name','type','size','error'],array_keys($upload))&&is_string($upload['field'])&&is_string($upload['name'])&&is_string($upload['type'])&&is_int($upload['size'])&&$upload['size']>=0&&is_int($upload['error']),true),default=>false};if(!$valid)throw new InvalidArgumentException('Invalid extension event payload type: '.$name.'.'.$field);}
}

function extension_validate_async_event_payload(string $name,array $payload):void
{
    $contract=extension_hook_contract('event',$name);
    if(!is_array($contract)||empty($contract['async'])||empty($contract['strict'])||!isset($contract['version']))throw new InvalidArgumentException('Async extension event requires a strict versioned contract: '.$name);
    extension_validate_hook_payload($name,$payload);
}

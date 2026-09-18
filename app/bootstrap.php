<?php
declare(strict_types=1);

require __DIR__.'/Store.php';
require __DIR__.'/AI.php';
require __DIR__.'/DocumentConverter.php';
require __DIR__.'/Importer.php';
require __DIR__.'/MarkdownFormatter.php';
require __DIR__.'/View.php';

function config(): array {
    static $config;
    if ($config === null) {
        $config = require dirname(__DIR__).'/config.example.php';
        if (is_file(dirname(__DIR__).'/config.php')) $config = array_replace_recursive($config, require dirname(__DIR__).'/config.php');
        if (getenv('KB_CONTENT_ROOT')) $config['content_root'] = getenv('KB_CONTENT_ROOT');
        if (getenv('KB_PASSWORD') !== false) $config['password'] = getenv('KB_PASSWORD');
    }
    return $config;
}
// Persistent sessions have isolated storage so the host's short default GC cannot remove them.
function start_login_session(): void {
    $lifetime=32*24*60*60;
    $directory=content_root().'/.sessions';
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Sessionsmappen kunde inte skapas. Kontrollera skrivbehörigheten.',500);
    session_name('kunskapstratten');
    session_save_path($directory);
    $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||strtolower(trim(explode(',',$_SERVER['HTTP_X_FORWARDED_PROTO']??'')[0]))==='https';
    if(!session_start(['save_handler'=>'files','gc_maxlifetime'=>$lifetime,'cookie_lifetime'=>$lifetime,'cookie_path'=>base_path().'/',
        'cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_secure'=>$secure,'use_strict_mode'=>true,'use_only_cookies'=>true]))throw new RuntimeException('Sessionen kunde inte startas.',500);
    if(!empty($_SESSION['authenticated'])){
        if(isset($_SESSION['authenticated_until'])&&$_SESSION['authenticated_until']<time())unset($_SESSION['authenticated'],$_SESSION['authenticated_until']);
        else renew_login_session();
    }
}
function renew_login_session(): void {
    $expires=time()+32*24*60*60;
    $_SESSION['authenticated_until']=$expires;
    setcookie(session_name(),session_id(),['expires'=>$expires]+array_diff_key(session_get_cookie_params(),['lifetime'=>true]));
}
function end_login_session(): void {
    $_SESSION=[];
    setcookie(session_name(),'',['expires'=>time()-3600]+array_diff_key(session_get_cookie_params(),['lifetime'=>true]));
    session_destroy();
}
function bank_id(?string $candidate=null): string {
    $env=getenv('KB_BANK');$id=$candidate ?? ($env!==false&&$env!==''?$env:($_SESSION['bank']??config()['default_bank']));
    if(!is_string($id)||!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/',$id))throw new RuntimeException('Ogiltig kunskapsbank.',400);
    return$id;
}
function content_root(): string {
    $root=config()['content_root'];if(!is_dir($root)&&!mkdir($root,0700,true))throw new RuntimeException('Content-mappen kunde inte skapas.');
    $real=str_replace('\\','/',realpath($root));if(!$real)throw new RuntimeException('Content-mappen kunde inte läsas.');return$real;
}
function bank_path(string $id): string {return content_root().'/'.bank_id($id);}
function ensure_bank(string $id,string $name=''): string {$id=bank_id($id);$root=bank_path($id);if(!is_dir($root)&&!mkdir($root,0700,true))throw new RuntimeException('Kunskapsbanken kunde inte skapas.');$meta=$root.'/bank.json';if(!is_file($meta))file_put_contents($meta,json_encode(['id'=>$id,'name'=>$name?:ucfirst($id),'created_at'=>gmdate('c')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX);return$root.'/storage';}
function banks(): array {$out=[];foreach(scandir(content_root())as$id){if(str_starts_with($id,'.')||!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/',$id)||!is_dir(content_root().'/'.$id.'/storage'))continue;$meta=[];$p=content_root().'/'.$id.'/bank.json';if(is_file($p))$meta=json_decode(file_get_contents($p),true)?:[];$out[]=['id'=>$id,'name'=>(string)($meta['name']??ucfirst($id))];}usort($out,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return$out;}
function store(): Store { static $stores=[];$id=bank_id();return $stores[$id]??=new Store(ensure_bank($id)); }
function settings(): array {
    $base = config();
    $settings=array_replace_recursive(['title'=>$base['title'], 'ai'=>$base['ai'], 'import'=>DocumentConverter::DEFAULTS, 'gui'=>['preview_enabled'=>false]], store()->json('data/settings.json'));
    // API keys are supplied by config.php or the current browser request, never bank settings.
    $settings['ai']['api_key']=$base['ai']['api_key'];
    return $settings;
}
function json_response(array $value, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit;
}
function input(): array {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 16*1024*1024) throw new RuntimeException('Begäran är för stor.', 413);
    $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Ogiltig begäran.', 400);
    return $data;
}
function base_path(): string {
    return rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
}
function app_url(string $path): string { return base_path().'/index.php?r='.rawurlencode($path); }
function url_for(string $name, ...$args): string {
    $routes = ['browse'=>'/browse','skills_page'=>'/skills','chat_page'=>'/chat','new_doc'=>'/new','upload_documents'=>'/upload','new_skill'=>'/skills/new','help_guide'=>'/help/guide', 'view_doc'=>'/doc/{relpath}', 'edit_doc'=>'/doc/{relpath}/edit','download_doc'=>'/doc/{relpath}/download','open_original'=>'/doc/{relpath}/original','doc_preview'=>'/api/doc-preview/{relpath}','save_doc'=>'/api/doc/{relpath}','save_skill'=>'/api/skill/{relpath}','edit_skill'=>'/skills/edit/{relpath}','run_skill_page'=>'/skills/run/{slug}','skill_documents'=>'/skills/documents/{slug}','delete_item'=>'/api/delete/{kind}/{relpath}'];
    if ($name === 'static') { $file=dirname(__DIR__).'/static/'.$args['filename'];return base_path().'/static/'.rawurlencode($args['filename']).(is_file($file)?'?v='.filemtime($file):''); }
    $path = $routes[$name] ?? throw new RuntimeException('Okänd route: '.$name);
    foreach ($args as $key=>$value) if (str_contains($path,'{'.$key.'}')) { $path = str_replace('{'.$key.'}',(string)$value,$path); unset($args[$key]); }
    return app_url($path).($args ? '&'.http_build_query($args) : '');
}
function redirect_to(string $url): never { header('Location: '.$url, true, 303); exit; }
function markdown(string $text): string {
    $text=str_replace("\r\n","\n",$text);$code=[];
    $text=preg_replace_callback('/```([\w-]*)\n(.*?)```/s',function($m)use(&$code){$key='@@CODE'.count($code).'@@';$code[$key]='<pre><code class="language-'.h($m[1]).'">'.h($m[2]).'</code></pre>';return$key;},$text);
    $text=h($text);
    $text=preg_replace('/^######\s+(.+)$/m','<h6>$1</h6>',$text);$text=preg_replace('/^#####\s+(.+)$/m','<h5>$1</h5>',$text);$text=preg_replace('/^####\s+(.+)$/m','<h4>$1</h4>',$text);$text=preg_replace('/^###\s+(.+)$/m','<h3>$1</h3>',$text);$text=preg_replace('/^##\s+(.+)$/m','<h2>$1</h2>',$text);$text=preg_replace('/^#\s+(.+)$/m','<h1>$1</h1>',$text);
    $text=preg_replace('/\*\*(.+?)\*\*/s','<strong>$1</strong>',$text);$text=preg_replace('/`([^`]+)`/','<code>$1</code>',$text);
    $text=preg_replace_callback('/!\[([^]]*)\]\(([^ )]+)(?:\s+"[^"]*")?\)/',fn($m)=>'<img src="'.h(md_url(html_entity_decode($m[2]))).'" alt="'.$m[1].'">',$text);
    $text=preg_replace_callback('/\[([^]]+)\]\(&lt;([^&]+)&gt;\)|\[([^]]+)\]\(([^ )]+)\)/',fn($m)=>'<a href="'.h(md_url(html_entity_decode($m[2]?:$m[4]))).'">'.($m[1]?:$m[3]).'</a>',$text);
    $text=preg_replace_callback('/^\|[^\n]*\|\n\|[ :|\-]+\|\n(?:\|[^\n]*\|(?:\n|$))*/m',function($m)use(&$code){
        $rows=explode("\n",trim($m[0]));$html='<table><thead>';foreach($rows as $i=>$row){if($i===1)continue;$cells=preg_split('/(?<!\\\\)\|/',trim($row,'| '));$tag=$i===0?'th':'td';$html.='<tr>';foreach($cells as $cell)$html.='<'.$tag.'>'.str_replace(['\\|','&lt;br&gt;'],['|','<br>'],trim($cell)).'</'.$tag.'>';$html.='</tr>';if($i===0)$html.='</thead><tbody>';}$html.='</tbody></table>';$key='@@CODE'.count($code).'@@';$code[$key]=$html;return $key;
    },$text);
    $lines=explode("\n",$text);$out='';$list=false;
    foreach($lines as $line){if(preg_match('/^[-*]\s+(.+)$/',$line,$m)){if(!$list){$out.='<ul>';$list=true;}$out.='<li>'.$m[1].'</li>';continue;}if($list){$out.='</ul>';$list=false;}if(str_starts_with($line,'<h')||str_starts_with($line,'<pre')||str_starts_with($line,'@@CODE'))$out.=$line;elseif(trim($line)==='')$out.="\n";else $out.='<p>'.$line.'</p>';}
    if($list)$out.='</ul>';return strtr($out,$code);
}
function md_url(string $url): string {if(preg_match('~^(javascript|data|vbscript):~i',$url))return'#';if(str_starts_with($url,'/'))return app_url($url);return$url;}

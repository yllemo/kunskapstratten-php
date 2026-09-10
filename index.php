<?php
declare(strict_types=1);

// PHP development server: serve only explicitly public assets as static files.
if (PHP_SAPI==='cli-server') {
    $asset=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
    if (preg_match('~^/static/[a-zA-Z0-9._-]+\.(css|js|svg)$~',$asset) && is_file(__DIR__.$asset)) return false;
    $_SERVER['SCRIPT_NAME']='/index.php';
}
ini_set('display_errors','0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
require __DIR__.'/app/bootstrap.php';

try {
    session_name('kunskapstratten');
    session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','use_strict_mode'=>true]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $method=$_SERVER['REQUEST_METHOD'];
    $route=$_GET['r'] ?? rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
    if (!isset($_GET['r']) && base_path() && str_starts_with($route,base_path())) $route=substr($route,strlen(base_path()));
    if ($route==='' || $route==='/' || $route==='/index.php') $route='/browse';
    if (!str_starts_with($route,'/')) throw new RuntimeException('Ogiltig adress.',400);
    if (!in_array($method,['GET','HEAD'],true)) {
        $token=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'],$token)) throw new RuntimeException('Sidan har gått ut. Ladda om och försök igen.',403);
    }
    $password=(string)(config()['password']??'');
    if ($password==='') {
        http_response_code(503);
        echo '<!doctype html><meta charset="utf-8"><h1>Slutför installationen</h1><p>Ange password i config.php. Se README.md.</p>'; exit;
    }
    if (empty($_SESSION['authenticated'])) {
        $error='';
        if ($route==='/login' && $method==='POST') {
            $key='auth-'.hash('sha256',$_SERVER['REMOTE_ADDR'] ?? '').'.json';
            $ok=store()->locked(function() use ($key,$password) {
                $attempt=store()->json('logs/'.$key);
                if (($attempt['until']??0)>time()) throw new RuntimeException('För många försök. Vänta en minut.',429);
                if (hash_equals($password,(string)($_POST['password']??''))) { store()->saveJson('logs/'.$key,[]); return true; }
                $count=($attempt['count']??0)+1;
                store()->saveJson('logs/'.$key,['count'=>$count>=5?0:$count,'until'=>$count>=5?time()+60:0]); return false;
            });
            if($ok) { session_regenerate_id(true); $_SESSION['authenticated']=true; $_SESSION['csrf']=bin2hex(random_bytes(32)); redirect_to(app_url('/browse')); }
            $error='Fel lösenord.';
        }
        if(str_starts_with($route,'/api/')) json_response(['error'=>'Logga in igen.'],401);
        render('login.html',['error'=>$error]);
    }
    if ($route==='/logout' && $method==='POST') { $_SESSION=[]; session_destroy(); redirect_to(app_url('/browse')); }
    require __DIR__.'/app/routes.php';
    // All writes and streams coordinate with reset and each other across PHP workers.
    if (!in_array($method,['GET','HEAD'],true)) store()->locked(fn()=>dispatch($route,$method));
    else dispatch($route,$method);
} catch (Throwable $e) {
    $status=(int)$e->getCode(); if($status<400 || $status>599) $status=500;
    error_log(get_class($e).': '.$e->getMessage());
    $message=$status===500?'Åtgärden misslyckades. Kontrollera serverns fellogg och skrivbehörigheter.':$e->getMessage();
    if ($e instanceof JsonException) {$status=400;$message='Ogiltig JSON: '.$e->getMessage();}
    if(headers_sent()) {echo "\n\nFel: ".$message;exit;}
    if(str_starts_with($route ?? '', '/api/')) json_response(['error'=>$message],$status);
    http_response_code($status);
    echo '<!doctype html><html lang="sv"><meta charset="utf-8"><title>Fel – Kunskapstratten</title><h1>'.htmlspecialchars($message).'</h1><p><a href="'.htmlspecialchars(app_url('/browse')).'">Till kunskapsbanken</a></p></html>';
}

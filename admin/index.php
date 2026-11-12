<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");

session_name('tgmenu_admin');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));

function jsonResponse(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function authenticated(): bool { return ($_SESSION['auth'] ?? false) === true; }
function checkCsrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '')))) {
        jsonResponse(403, ['error'=>'安全令牌失效，请刷新页面']);
    }
}
function safeId($id): bool { return is_string($id) && (bool)preg_match('/^[a-zA-Z0-9_-]{1,48}$/D', $id); }
function validateMenu($cfg): void {
    if (!is_array($cfg) || !isset($cfg['pages'], $cfg['replies']) || !is_array($cfg['pages']) || !is_array($cfg['replies']) || !isset($cfg['pages']['home'])) throw new RuntimeException('必须包含 pages、replies 和 home 首页');
    if (count($cfg['pages']) > 300 || count($cfg['replies']) > 600) throw new RuntimeException('菜单或回复数量超出限制');
    foreach ($cfg['pages'] as $id=>$page) {
        if (!safeId($id) || !is_array($page)) throw new RuntimeException('无效菜单 ID：'.$id);
        foreach (['title','text'] as $f) if (!isset($page[$f]) || !is_string($page[$f]) || mbLen($page[$f]) > 3500) throw new RuntimeException("菜单 $id 的 $f 无效");
        if (!isset($page['buttons']) || !is_array($page['buttons']) || count($page['buttons']) > 60) throw new RuntimeException("菜单 $id 按钮数量或格式无效");
        foreach ($page['buttons'] as $b) {
            if (!is_array($b) || !isset($b['label']) || !is_string($b['label']) || trim($b['label']) === '' || mbLen($b['label']) > 50) throw new RuntimeException("菜单 $id 存在无效按钮名称");
            $types = array_intersect(['page','reply','url'], array_keys($b));
            if (count($types) !== 1) throw new RuntimeException("菜单 $id 按钮必须恰好有一种类型");
            $t = reset($types); $val = $b[$t];
            if ($t === 'page' && (!safeId($val) || !isset($cfg['pages'][$val]))) throw new RuntimeException("按钮目标菜单不存在：$val");
            if ($t === 'reply' && (!safeId($val) || !isset($cfg['replies'][$val]))) throw new RuntimeException("按钮目标回复不存在：$val");
            if ($t === 'url' && (!is_string($val) || strlen($val) > 1024 || !preg_match('~^https://[^\\s]+$~i', $val))) throw new RuntimeException('外部网址必须是 https:// 开头');
        }
    }
    foreach ($cfg['replies'] as $id=>$r) {
        if (!safeId($id) || !is_array($r)) throw new RuntimeException('无效回复 ID：'.$id);
        if (isset($r['text']) && (!is_string($r['text']) || mbLen($r['text']) > 4000)) throw new RuntimeException("回复 $id 文本过长");
        foreach (['photo','video','document'] as $f) if (isset($r[$f]) && (!is_string($r[$f]) || !preg_match('~^[a-zA-Z0-9_.-]{1,180}$~D', $r[$f]) || $r[$f] === '..')) throw new RuntimeException("回复 $id 的媒体文件名无效");
    }
}
function mbLen(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : (preg_match_all('/./us', $s) ?: strlen($s)); }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($path === '/health') { header('Content-Type: text/plain'); echo 'ok'; exit; }
if ($path === '/api/login' && $method === 'POST') {
    checkCsrf();
    $user = getenv('ADMIN_USER') ?: 'admin';
    $pass = getenv('ADMIN_PASSWORD') ?: '';
    if (strlen($pass) < 8) jsonResponse(503, ['error'=>'请先在 .env 中设置至少8位 ADMIN_PASSWORD']);
    if (hash_equals($user, (string)($_POST['username'] ?? '')) && hash_equals($pass, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true); $_SESSION['auth'] = true; jsonResponse(200, ['ok'=>true]);
    }
    sleep(1); jsonResponse(401, ['error'=>'账号或密码不正确']);
}
if ($path === '/api/logout' && $method === 'POST') { checkCsrf(); $_SESSION = []; session_destroy(); jsonResponse(200,['ok'=>true]); }
if ($path === '/api/session') jsonResponse(200,['authenticated'=>authenticated(), 'csrf'=>$_SESSION['csrf']]);
if (str_starts_with($path, '/api/')) {
    if (!authenticated()) jsonResponse(401,['error'=>'请先登录']);
    $file = getenv('MENU_FILE_PATH') ?: '/app/menus.json';
    if ($path === '/api/config' && $method === 'GET') {
        $raw = @file_get_contents($file);
        if ($raw === false) jsonResponse(500,['error'=>'无法读取 menus.json']);
        $cfg = json_decode($raw,true);
        if (!is_array($cfg)) jsonResponse(500,['error'=>'menus.json 不是有效 JSON']);
        jsonResponse(200,['config'=>$cfg]);
    }
    if ($path === '/api/save' && $method === 'POST') {
        checkCsrf();
        $input = file_get_contents('php://input',false,null,0,1048577);
        if ($input === false || strlen($input) > 1048576) jsonResponse(413,['error'=>'文件超过 1MB']);
        try {
            $cfg = json_decode($input,true,512,JSON_THROW_ON_ERROR);
            validateMenu($cfg);
            // 发布时为首页无法到达的菜单补充入口，保留现有子菜单层级。
            $reachable = ['home' => true];
            $queue = ['home'];
            for ($i = 0; $i < count($queue); $i++) {
                foreach ($cfg['pages'][$queue[$i]]['buttons'] as $button) {
                    if (isset($button['page']) && !isset($reachable[$button['page']])) {
                        $reachable[$button['page']] = true;
                        $queue[] = $button['page'];
                    }
                }
            }
            foreach ($cfg['pages'] as $id => $page) {
                if (!isset($reachable[$id])) {
                    $cfg['pages']['home']['buttons'][] = ['label' => $page['title'], 'page' => (string)$id];
                }
            }
            validateMenu($cfg);
            $encoded = json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            $dir=dirname($file);
            $dataDir = getenv('BOT_DATA_DIR') ?: '/app/data';
            $lock=fopen($dataDir.'/admin-save.lock','c');
            if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('无法获得保存锁');
            try {
                $backup=$dataDir.'/menus-backup-'.date('Ymd-His').'-'.bin2hex(random_bytes(2)).'.json';
                if (is_file($file)) copy($file,$backup);
                // bind-mount 单个文件不能 rename 替换，使用原 inode 写入，保证 bot 仍能读取。
                $f=fopen($file,'c+');
                if (!$f || !flock($f,LOCK_EX)) throw new RuntimeException('无法写入 menus.json，检查挂载权限');
                ftruncate($f,0); rewind($f);
                $written=fwrite($f,$encoded);
                fflush($f); flock($f,LOCK_UN); fclose($f);
                if ($written !== strlen($encoded)) throw new RuntimeException('写入不完整，请检查备份');
            } finally { flock($lock,LOCK_UN); fclose($lock); }
            jsonResponse(200,['ok'=>true,'backup'=>basename($backup),'config'=>$cfg]);
        } catch (Throwable $e) { jsonResponse(400,['error'=>$e->getMessage()]); }
    }
    jsonResponse(404,['error'=>'接口不存在']);
}
if ($path !== '/' && $path !== '/index.php' && !is_file(__DIR__.$path)) { http_response_code(404); exit('Not found'); }
if ($path === '/' || $path === '/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/app.html'); exit;
}
return false;

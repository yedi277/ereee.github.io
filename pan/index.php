<?php
/**
 * 文件管理器 
 * 功能：登录、浏览、上传、下载、新建、重命名、复制、移动、删除、ZIP/TAR打包、解压、拖拽上传/移动、回收站、收藏夹
 */
session_start();

// ========== 配置 ==========
define('APP_TITLE', '文件管理器');
$auth_user = 'admin';
$auth_pass = '$2y$10$m7JT3rdrZ3nK4tKxpJ8z5.pDalU75BZH9nu82zBJkVNhLJgoC6uXm'; // admin
$root_path = $_SERVER['DOCUMENT_ROOT'];
$max_upload_size = 5000000000;

date_default_timezone_set('Asia/Shanghai');
set_time_limit(600);
if (empty($_SESSION['token'])) $_SESSION['token'] = bin2hex(random_bytes(32));

// ========== 工具函数 ==========
function fm_clean($s) { return preg_replace('#/+#', '/', str_replace(['\\','..'], ['/',''], trim($s))); }
function fm_rmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, 3), 2) as $i)
        $i->isDir() ? @rmdir($i) : @unlink($i);
    @rmdir($dir);
}
function fm_copy($src, $dst) {
    if (is_file($src)) return copy($src, $dst);
    @mkdir($dst, 0755, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, 3), 1) as $i) {
        $t = $dst . substr($i, strlen($src));
        $i->isDir() ? @mkdir($t, 0755, true) : copy($i, $t);
    }
}
function fm_trash_dir() {
    global $root_path;
    $t = $root_path . '/.trash';
    if (!is_dir($t)) @mkdir($t, 0755, true);
    return $t;
}
function fm_trash_move($path) {
    $t = fm_trash_dir();
    $name = basename($path);
    $trash_name = date('Ymd_His_') . $name;
    if (!rename($path, $t . '/' . $trash_name)) return false;
    $meta = json_decode(file_get_contents($t . '/.trash_meta.json') ?: '{}', true);
    $meta[$trash_name] = ['name' => $name, 'path' => dirname($path), 'time' => time(), 'dir' => is_dir($t . '/' . $trash_name)];
    file_put_contents($t . '/.trash_meta.json', json_encode($meta, 448));
    return true;
}
function fm_trash_clean($days = 7) {
    $t = fm_trash_dir();
    $f = $t . '/.trash_meta.json';
    if (!file_exists($f)) return;
    $meta = json_decode(file_get_contents($f), true) ?: [];
    $expire = time() - $days * 86400;
    foreach ($meta as $k => $v) {
        if ($v['time'] < $expire) {
            $p = $t . '/' . $k;
            $v['dir'] ? fm_rmdir($p) : @unlink($p);
            unset($meta[$k]);
        }
    }
    file_put_contents($f, json_encode($meta, 448));
}

// ========== 收藏夹功能 ==========
function fm_star_file() {
    global $root_path;
    return $root_path . '/.stars.json';
}
function fm_star_load() {
    $f = fm_star_file();
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?: [];
}
function fm_star_save($data) {
    file_put_contents(fm_star_file(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function fm_star_add($rel_path) {
    $stars = fm_star_load();
    if (!in_array($rel_path, $stars)) {
        $stars[] = $rel_path;
        fm_star_save($stars);
    }
    return true;
}
function fm_star_remove($rel_path) {
    $stars = fm_star_load();
    $stars = array_values(array_filter($stars, function($s) use ($rel_path) { return $s !== $rel_path; }));
    fm_star_save($stars);
    return true;
}
function fm_star_toggle($rel_path) {
    $stars = fm_star_load();
    if (in_array($rel_path, $stars)) {
        $stars = array_values(array_filter($stars, function($s) use ($rel_path) { return $s !== $rel_path; }));
    } else {
        $stars[] = $rel_path;
    }
    fm_star_save($stars);
    return in_array($rel_path, $stars);
}

// ========== 路径处理 ==========
$p = isset($_GET['p']) ? fm_clean($_GET['p']) : '';
$current_path = realpath($root_path . ($p ? '/' . $p : ''));
if (!$current_path || strpos($current_path, realpath($root_path)) !== 0) die('非法路径');

$self = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

// ========== 语言 ==========
$L = ['Login'=>'登录','Logout'=>'退出','Username'=>'用户名','Password'=>'密码','Login failed'=>'登录失败','Name'=>'名称','Size'=>'大小','Modified'=>'修改时间','Download'=>'下载','Upload'=>'上传','New Folder'=>'新建文件夹','New File'=>'新建文件','Rename'=>'重命名','Copy'=>'复制','Move'=>'移动','Delete'=>'删除','Cancel'=>'取消','Created'=>'已创建','Trash'=>'回收站','Restore'=>'恢复','Empty Trash'=>'清空回收站','Purge'=>'彻底删除','Trash is empty'=>'回收站为空','Pack ZIP'=>'打包ZIP','Pack TAR'=>'打包TAR','Unpack'=>'解压','Error'=>'错误','Favorites'=>'收藏夹','Star'=>'收藏','Unstar'=>'取消收藏','Starred'=>'已收藏','My Files'=>'我的文档','Quick Access'=>'快捷访问'];
function lng($k) { global $L; return $L[$k] ?? $k; }

// ========== 认证 ==========
$is_logged = isset($_SESSION['fm']);

if (isset($_GET['logout'])) { session_destroy(); header('Location: ' . $self); exit; }
if (!$is_logged && isset($_POST['login'])) {
    if ($_POST['user'] === $auth_user && password_verify($_POST['pass'], $auth_pass)) {
        $_SESSION['fm'] = $auth_user;
        header('Location: ' . $self . '?p=' . urlencode($p)); exit;
    }
    $login_error = lng('Login failed');
}

// ========== 回收站自动清理 ==========
if ($is_logged) fm_trash_clean(7);

// ========== 收藏夹 AJAX ==========
if ($is_logged && isset($_POST['ajax_star'])) {
    header('Content-Type: application/json');
    $sp = fm_clean($_POST['star_path']);
    $action = fm_star_toggle($sp);
    echo json_encode(['success' => true, 'starred' => $action]);
    exit;
}

// ========== 清空回收站 AJAX ==========
if ($is_logged && isset($_POST['ajax_empty_trash'])) {
    header('Content-Type: application/json');
    $t = fm_trash_dir();
    $mf = $t . '/.trash_meta.json';
    $meta = json_decode(file_get_contents($mf) ?: '{}', true) ?: [];
    $count = count($meta);
    foreach ($meta as $k => $v) {
        $fp = $t . '/' . $k;
        $v['dir'] ? fm_rmdir($fp) : @unlink($fp);
    }
    file_put_contents($mf, '{}');
    echo json_encode(['success' => true, 'count' => $count]);
    exit;
}

// ========== 回收站操作 ==========
if ($is_logged && isset($_GET['trash'])) {
    $t = fm_trash_dir();
    $mf = $t . '/.trash_meta.json';
    $meta = json_decode(file_get_contents($mf) ?: '{}', true);
    
    if (isset($_GET['restore'], $_GET['token']) && $_GET['token'] === $_SESSION['token'] && isset($meta[$_GET['restore']])) {
        $n = fm_clean($_GET['restore']);
        rename($t . '/' . $n, $meta[$n]['path'] . '/' . $meta[$n]['name']);
        unset($meta[$n]);
        file_put_contents($mf, json_encode($meta, 448));
        header('Location: ' . $self . '?trash'); exit;
    }
    if (isset($_GET['purge'], $_GET['token']) && $_GET['token'] === $_SESSION['token'] && isset($meta[$_GET['purge']])) {
        $n = fm_clean($_GET['purge']);
        $meta[$n]['dir'] ? fm_rmdir($t . '/' . $n) : @unlink($t . '/' . $n);
        unset($meta[$n]);
        file_put_contents($mf, json_encode($meta, 448));
        header('Location: ' . $self . '?trash'); exit;
    }
    if (isset($_GET['empty_trash'], $_GET['token']) && $_GET['token'] === $_SESSION['token']) {
        foreach ($meta as $k => $v) { $p = $t . '/' . $k; $v['dir'] ? fm_rmdir($p) : @unlink($p); }
        file_put_contents($mf, '{}');
        header('Location: ' . $self . '?trash'); exit;
    }
}

// ========== AJAX 处理 ==========
if ($is_logged && isset($_POST['token']) && $_POST['token'] === $_SESSION['token']) {
    header('Content-Type: application/json');
    ob_start();
    
    // 上传
    if (isset($_GET['ajax_upload']) && !empty($_FILES['file'])) {
        $f = $_FILES['file'];
        $rel = isset($_POST['relative_path']) ? fm_clean($_POST['relative_path']) : '';
        $target = $current_path . '/' . ($rel ?: fm_clean($f['name']));
        if ($rel) @mkdir(dirname($target), 0755, true);
        echo json_encode(['success' => move_uploaded_file($f['tmp_name'], $target) && $f['size'] <= $max_upload_size]);
        exit;
    }
    
    // 移动(拖拽)
    if (isset($_POST['ajax_move'])) {
        $src = $current_path . '/' . fm_clean($_POST['src']);
        $dst = $current_path . '/' . fm_clean($_POST['dst']) . '/' . basename($src);
        echo json_encode(['success' => file_exists($src) && !file_exists($dst) && rename($src, $dst)]);
        exit;
    }
    
    // 粘贴
    if (isset($_POST['ajax_paste'])) {
        $src_name = fm_clean($_POST['src']);
        parse_str(parse_url($_POST['from'] ?? '', PHP_URL_QUERY) ?? '', $qp);
        $src = $root_path . '/' . ($qp['p'] ?? '') . '/' . $src_name;
        $dst = $current_path . '/' . fm_clean($_POST['dst_folder'] ?? '') . '/' . $src_name;
        if (!file_exists($src) || file_exists($dst)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '路径错误']); exit; }
        if ($_POST['mode'] === 'copy') fm_copy($src, $dst);
        else rename($src, $dst);
        ob_end_clean(); echo json_encode(['success' => true]); exit;
    }
    
    // 打包
    if (isset($_POST['ajax_pack'])) {
        $type = $_POST['ajax_pack'];
        $files = (array)($_POST['files'] ?? []);
        if (empty($files)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '未选择']); exit; }
        
        $name = (count($files) === 1 ? pathinfo($files[0], 8) : basename($p ?: 'root')) . '.' . $type;
        $name = preg_replace('/[^\w\x{4e00}-\x{9fa5}\-\.]/u', '_', $name);
        $path = $current_path . '/' . $name;
        
        if ($type === 'zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE) !== TRUE) { ob_end_clean(); echo json_encode(['success' => false, 'error' => 'ZIP创建失败']); exit; }
            foreach ($files as $f) {
                $f = fm_clean($f);
                $t = $current_path . '/' . $f;
                if (is_file($t)) $zip->addFile($t, $f);
                elseif (is_dir($t)) {
                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, 3), 1) as $i)
                        $i->isDir() ? $zip->addEmptyDir($f . substr($i, strlen($t))) : $zip->addFile($i, $f . substr($i, strlen($t)));
                }
            }
            $zip->close();
        } elseif ($type === 'tar' && class_exists('PharData')) {
            $tar = new PharData($path);
            foreach ($files as $f) {
                $f = fm_clean($f);
                $t = $current_path . '/' . $f;
                is_file($t) ? $tar->addFile($t, $f) : (is_dir($t) && $tar->buildFromDirectory($t, '#^' . preg_quote($t . '/', '#') . '#'));
            }
        }
        ob_end_clean(); echo json_encode(['success' => true, 'name' => $name]); exit;
    }
    
    // 解压
    if (isset($_POST['ajax_unpack'])) {
        $f = fm_clean($_POST['archive']);
        $path = $current_path . '/' . $f;
        $ext = strtolower(pathinfo($f, 4));
        if (!file_exists($path)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '文件不存在']); exit; }
        
        if ($ext === 'zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $zip->open($path) && $zip->extractTo($current_path) && $zip->close();
        } elseif (in_array($ext, ['tar','gz','tgz']) && class_exists('PharData')) {
            (new PharData($path))->extractTo($current_path);
        }
        ob_end_clean(); echo json_encode(['success' => true]); exit;
    }
    
    // 批量下载
    if (isset($_POST['ajax_batch_download']) && class_exists('ZipArchive')) {
        $names = (array)($_POST['names'] ?? []);
        if (empty($names)) exit;
        $tmp = tempnam(sys_get_temp_dir(), 'fm') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) !== TRUE) exit;
        foreach ($names as $n) {
            $n = fm_clean($n);
            $t = $current_path . '/' . $n;
            if (is_file($t)) $zip->addFile($t, $n);
            elseif (is_dir($t)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, 3), 1) as $i)
                    $i->isDir() ? $zip->addEmptyDir($n . substr($i, strlen($t))) : $zip->addFile($i, $n . substr($i, strlen($t)));
            }
        }
        $zip->close();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="download_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }
    
    ob_end_clean();
}

// ========== POST 操作 ==========
if ($is_logged && isset($_POST['token']) && $_POST['token'] === $_SESSION['token'] && !isset($_POST['ajax_move'])) {
    $redirect = 'Location: ' . $self . '?p=' . urlencode($p);
    
    function fm_unique_name($base,$path){if(!file_exists($path.'/'.$base))return $base;$ext=pathinfo($base,PATHINFO_EXTENSION);$name=$ext?substr($base,0,-(strlen($ext)+1)):$base;for($i=1;file_exists($path.'/'.$name.'-'.$i.($ext?'.'.$ext:''));$i++);return $name.'-'.$i.($ext?'.'.$ext:'');}
    // 检查名称是否重复
    if (isset($_POST['ajax_check_name'])) {
        header('Content-Type:application/json');
        $n=fm_clean($_POST['name']); $type=$_POST['type'];
        if(!$n){print(json_encode(['error'=>'名称无效']));exit;}
        if(file_exists($current_path.'/'.$n)){
            $s=fm_unique_name($n,$current_path);
            print(json_encode(['duplicate'=>true,'suggest'=>$s]));
        } else {
            print(json_encode(['duplicate'=>false]));
        }
        exit;
    }
    $isAjax=isset($_POST['ajax_new']);if($isAjax)header('Content-Type:application/json');
    if (isset($_POST['new_folder'])) { $n = fm_clean($_POST['new_folder']); if(!$n){if($isAjax){print(json_encode(['error'=>'名称无效']));exit;}header($redirect);exit;} if(file_exists($current_path.'/'.$n)){if($isAjax){print(json_encode(['error'=>'"'.$n.'" 已存在']));exit;}header($redirect);exit;} mkdir($current_path.'/'.$n,0755); if($isAjax){print(json_encode(['success'=>true,'name'=>$n]));exit;}header($redirect);exit; }
    if (isset($_POST['new_file'])) { $n = fm_clean($_POST['new_file']); if(!$n){if($isAjax){print(json_encode(['error'=>'名称无效']));exit;}header($redirect);exit;} if(file_exists($current_path.'/'.$n)){if($isAjax){print(json_encode(['error'=>'"'.$n.'" 已存在']));exit;}header($redirect);exit;} file_put_contents($current_path.'/'.$n,''); if($isAjax){print(json_encode(['success'=>true,'name'=>$n]));exit;}header($redirect);exit; }
    if (!empty($_FILES['file']['name']) && !isset($_GET['ajax_upload'])) { move_uploaded_file($_FILES['file']['tmp_name'], $current_path . '/' . fm_clean($_FILES['file']['name'])); header($redirect); exit; }
    if (isset($_POST['rename_from'], $_POST['rename_to'])) { $o = $current_path . '/' . fm_clean($_POST['rename_from']); $n = $current_path . '/' . fm_clean($_POST['rename_to']); file_exists($o) && !file_exists($n) && rename($o, $n); header($redirect); exit; }
    if (isset($_POST['copy_src'], $_POST['copy_dst'])) { $s = $current_path . '/' . fm_clean($_POST['copy_src']); $d = $current_path . '/' . fm_clean($_POST['copy_dst']); file_exists($s) && !file_exists($d) && fm_copy($s, $d); header($redirect); exit; }
    if (isset($_POST['move_src'], $_POST['move_dst'])) { $s = $current_path . '/' . fm_clean($_POST['move_src']); $d = $current_path . '/' . fm_clean($_POST['move_dst']); file_exists($s) && !file_exists($d) && rename($s, $d); header($redirect); exit; }
    if (isset($_POST['delete'])) { foreach ((array)$_POST['delete'] as $item) fm_trash_move($current_path . '/' . fm_clean($item)); header($redirect); exit; }
    if (isset($_POST['pack_zip'], $_POST['files'])) {
        $zip = new ZipArchive();
        $name = 'archive_' . date('Ymd_His') . '.zip';
        if ($zip->open($current_path . '/' . $name, ZipArchive::CREATE) === TRUE) {
            foreach ((array)$_POST['files'] as $f) {
                $f = fm_clean($f);
                $t = $current_path . '/' . $f;
                if (is_file($t)) $zip->addFile($t, $f);
                elseif (is_dir($t)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, 3), 1) as $i) $i->isDir() ? $zip->addEmptyDir($f . substr($i, strlen($t))) : $zip->addFile($i, $f . substr($i, strlen($t))); }
            }
            $zip->close();
        }
        header($redirect); exit;
    }
    if (isset($_POST['unpack'])) {
        $f = fm_clean($_POST['archive']);
        $path = $current_path . '/' . $f;
        $ext = strtolower(pathinfo($f, 4));
        if ($ext === 'zip' && class_exists('ZipArchive')) { $z = new ZipArchive(); $z->open($path) && $z->extractTo($current_path) && $z->close(); }
        elseif (in_array($ext, ['tar','gz','tgz']) && class_exists('PharData')) (new PharData($path))->extractTo($current_path);
        header($redirect); exit;
    }
}

// ========== 下载 ==========
if ($is_logged && isset($_GET['dl'])) {
    $f = $current_path . '/' . fm_clean($_GET['dl']);
    if (is_file($f)) { header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="' . basename($f) . '"'); header('Content-Length: ' . filesize($f)); readfile($f); exit; }
}

// ========== 文件列表 ==========
$folders = $files = [];
foreach (scandir($current_path) as $i) { is_dir($current_path . '/' . $i) ? $folders[] = $i : $files[] = $i; }
natcasesort($folders); natcasesort($files);
$parent = $p ? dirname($p) : false; if ($parent === '.') $parent = '';
function fmtsize($b) { $u = ['B','KB','MB','GB','TB']; $i = 0; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, 2) . ' ' . $u[$i]; }

// ========== 收藏数据 ==========
$stars = $is_logged ? fm_star_load() : [];

// ========== CSS ==========
$css = <<<'CSS'
:root{--bg:#1e1e1e;--card:#2d2d2d;--border:#3a3a3c;--text:#f5f5f7;--muted:#888;--primary:#0a84ff;--danger:#ff453a;--hover:#2c2c2e;--sel:#0a84ff;--sidebar-bg:#252527;--sidebar-width:200px;--star-color:#ffc107}
*{margin:0;padding:0;box-sizing:border-box}
body{font:13px -apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}
a{color:var(--primary);text-decoration:none}
.btn{display:inline-flex;align-items:center;gap:4px;padding:6px 14px;border:none;border-radius:6px;cursor:pointer;font-size:13px;background:var(--hover);color:var(--muted);transition:all .1s}
.btn:hover{background:var(--border);color:var(--text)}
.btn-primary{background:var(--primary);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.alert{padding:8px 14px;border-radius:6px;margin:8px 12px;font-size:12px}
.alert-success{background:#0a2a0a;border:1px solid #30d158;color:#30d158}
.alert-error{background:#2a0a0a;border:1px solid #ff453a;color:#ff453a}
.form-control{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(0,122,255,.2)}
.login-box{width:320px;margin:100px auto;background:var(--card);padding:32px;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.login-box h2{text-align:center;margin-bottom:20px;font-size:17px;font-weight:600}

/* ===== 侧边栏 ===== */
.sidebar{width:var(--sidebar-width);min-width:var(--sidebar-width);background:var(--sidebar-bg);border-right:1px solid var(--border);display:flex;flex-direction:column;height:100vh;position:sticky;top:0;overflow-y:auto;overflow-x:hidden}
.sidebar-header{padding:14px 16px 10px;font-size:15px;font-weight:600;display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border)}
.sidebar-section{padding:8px 0}
.sidebar-title{font-size:11px;text-transform:uppercase;color:var(--muted);padding:6px 16px 4px;font-weight:600;letter-spacing:.5px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:4px}
.sidebar-title .arrow{transition:transform .15s;font-size:10px}
.sidebar-title.collapsed .arrow{transform:rotate(-90deg)}
.sidebar-list{list-style:none}
.sidebar-item{display:flex;align-items:center;gap:6px;padding:5px 16px 5px 24px;cursor:pointer;font-size:13px;color:var(--muted);transition:background .08s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar-item:hover{background:var(--hover);color:var(--text)}
.sidebar-item.active{background:rgba(10,132,255,.12);color:var(--primary)}
.sidebar-item .item-icon{font-size:14px;flex-shrink:0}
.sidebar-item .item-name{overflow:hidden;text-overflow:ellipsis}
.sidebar-item .star-btn{margin-left:auto;cursor:pointer;font-size:13px;opacity:.4;transition:opacity .15s;flex-shrink:0;padding:0 2px}
.sidebar-item .star-btn:hover{opacity:1}
.sidebar-item .star-btn.starred{opacity:1;color:var(--star-color)}

/* ===== 主区域 ===== */
.main-area{flex:1;display:flex;flex-direction:column;height:100vh;min-width:0;overflow:hidden}
.header{display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border);gap:12px;position:sticky;top:0;z-index:100;background:var(--bg)}
.header h1{font-size:16px;font-weight:600;flex:1}
.header-actions{display:flex;gap:8px}
.breadcrumb{padding:8px 16px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);position:sticky;top:45px;z-index:90;background:var(--bg)}
.breadcrumb a{color:var(--muted);padding:2px 4px;border-radius:3px}
.breadcrumb a:hover{background:var(--hover);color:var(--text)}
.toolbar{display:flex;align-items:center;gap:6px;padding:8px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap;position:sticky;top:78px;z-index:80;background:var(--bg)}
.toolbar .hint{margin-left:auto;font-size:11px;color:var(--muted)}
form#mainForm{flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0}
.table-container{flex:1;overflow:auto}
table{width:100%;border-collapse:collapse}
th{padding:8px 14px;text-align:left;font-size:11px;color:var(--muted);text-transform:uppercase;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg)}
td{padding:6px 14px;border-bottom:.5px solid var(--border);white-space:nowrap}
tr{cursor:default;transition:background .08s}
tr:hover td{background:var(--hover)}
tr.selected td{background:var(--sel);color:#fff}
.checkbox{width:14px;height:14px;cursor:pointer;accent-color:var(--primary)}
.empty{text-align:center;padding:60px;color:var(--muted)}
.footer{position:sticky;bottom:0;z-index:100;padding:8px 16px;font-size:12px;color:var(--muted);border-top:1px solid var(--border);background:var(--bg)}
.dropdown{position:relative;display:inline-block}
.dropdown-menu{display:none;position:absolute;top:100%;left:0;background:var(--card);border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.5);min-width:140px;padding:4px 0;z-index:100;border:1px solid var(--border)}
.dropdown-item{padding:6px 14px;cursor:pointer;font-size:12px;color:var(--muted)}
.dropdown-item:hover{background:var(--primary);color:#fff;border-radius:4px;margin:0 4px;padding-left:10px;padding-right:10px}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center}
.modal.show{display:flex}
.modal-content{background:var(--card);padding:20px;border-radius:10px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.modal-header{font-size:15px;font-weight:600;margin-bottom:14px}
.modal-body{margin-bottom:16px;color:var(--muted)}
.modal-footer{display:flex;gap:8px;justify-content:flex-end}
.context-menu{position:fixed;background:var(--card);border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.5);min-width:160px;z-index:2000;padding:4px 0;display:none;border:1px solid var(--border)}
.context-menu.show{display:block}
.context-menu-item{padding:6px 14px;cursor:default;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)}
.context-menu-item:hover{background:var(--primary);color:#fff;border-radius:4px;margin:0 4px;padding-left:10px;padding-right:10px}
.context-menu-divider{height:1px;background:var(--border);margin:4px 8px}
.context-menu-item.danger:hover{background:var(--danger)}
.inline-input{border:1px solid var(--primary);border-radius:4px;padding:3px 6px;font-size:13px;background:var(--bg);color:var(--text);outline:none;min-width:160px}
.inline-edit td{background:rgba(10,132,255,.1)!important}
.upload-progress{position:fixed;bottom:20px;right:20px;background:var(--card);padding:14px 18px;border-radius:8px;min-width:200px;box-shadow:0 8px 30px rgba(0,0,0,.5)}
.progress-bar{height:3px;background:var(--border);border-radius:1.5px;margin-top:8px}
.progress-bar-fill{height:100%;background:var(--primary);border-radius:1.5px;transition:width .3s}
.drag-over{background:rgba(10,132,255,.1)!important}

/* 表格中的星标按钮 */
.star-cell{width:28px;text-align:center;cursor:pointer}
.star-cell .star-icon{font-size:14px;opacity:.35;transition:opacity .15s,color .15s}
.star-cell .star-icon:hover{opacity:.7}
.star-cell .star-icon.starred{opacity:1;color:var(--star-color)}

/* 自定义滚动条 */
.table-container::-webkit-scrollbar{width:6px}
.table-container::-webkit-scrollbar-track{background:transparent}
.table-container::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px}
.table-container::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.3)}
.sidebar::-webkit-scrollbar{width:4px}
.sidebar::-webkit-scrollbar-track{background:transparent}
.sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}
CSS;

// ========== JS ==========
$jsPath = addslashes($p);
$jsStars = json_encode($stars);
$js = "const currentPath='{$jsPath}';\nconst stars={$jsStars};\n";
$js .= <<<'JS'
console.log('FM JS loaded, token:',document.querySelector('input[name="token"]')?.value);
const token=document.querySelector('input[name="token"]')?.value||'';let draggedItem=null,lastSelected=null,ctxTarget=null;

function getNames(){return[...document.querySelectorAll('.file-check:checked')].map(c=>c.value)}
function updateSel(){const c=document.querySelectorAll('.file-check:checked');document.querySelectorAll('tr[data-name]').forEach(r=>r.classList.toggle('selected',r.querySelector('.file-check')?.checked));document.getElementById('selectAll').checked=c.length>0&&c.length===document.querySelectorAll('.file-check').length;const b=document.getElementById('btnUnpack');if(c.length===1){const r=document.querySelector('.file-check:checked')?.closest('tr');b.style.display=r?.dataset.archive==='1'?'inline-flex':'none'}else b.style.display='none'}
function toggleAll(){document.querySelectorAll('.file-check').forEach(c=>c.checked=document.getElementById('selectAll').checked);updateSel()}
function selectRow(r,e){if(e.shiftKey&&lastSelected){const rows=[...document.querySelectorAll('tr[data-name]')],i=rows.indexOf(lastSelected),j=rows.indexOf(r);rows.slice(Math.min(i,j),Math.max(i,j)+1).forEach(x=>{x.querySelector('.file-check').checked=true;x.classList.add('selected')})}else if(e.ctrlKey||e.metaKey){r.querySelector('.file-check').checked=!r.querySelector('.file-check').checked}else{document.querySelectorAll('.file-check').forEach(c=>c.checked=false);r.querySelector('.file-check').checked=true}lastSelected=r;updateSel()}
function getClip(){try{return JSON.parse(sessionStorage.getItem('clip'))}catch(e){return null}}
function setClip(d){sessionStorage.setItem('clip',JSON.stringify(d));updatePaste()}
function updatePaste(){const has=!!getClip();document.querySelectorAll('.paste-item').forEach(e=>e.style.display=has?'flex':'none')}
async function pasteTo(folder){const c=getClip();if(!c)return;const fd=new FormData();fd.append('ajax_paste','1');fd.append('mode',c.mode);fd.append('src',c.name);fd.append('from',c.from);fd.append('dst_folder',folder||'');fd.append('token',token);try{const r=await fetch(location.href,{method:'POST',body:fd}),d=await r.json();if(d.success){sessionStorage.removeItem('clip');updatePaste();location.reload()}else alert(d.error)}catch(e){alert('操作失败')}}
async function ajaxAct(fd,ok){const r=await fetch(location.href,{method:'POST',body:fd}),d=await r.json();d.success?ok():alert(d.error||'操作失败')}
function showProgress(t){let e=document.getElementById('progress');if(!e){e=document.createElement('div');e.id='progress';e.className='upload-progress';e.innerHTML='<div class="progress-text"></div><div class="progress-bar"><div class="progress-bar-fill" style="width:0%"></div></div>';document.body.appendChild(e)}e.querySelector('.progress-text').textContent=t;return e}
function hideProgress(){document.getElementById('progress')?.remove()}
async function packFiles(type){const n=getNames();if(!n.length){alert('请先选择文件');return}const p=showProgress(`正在打包 ${type.toUpperCase()}...`);p.querySelector('.progress-bar-fill').style.width='50%';const fd=new FormData();fd.append('ajax_pack',type);fd.append('token',token);n.forEach(x=>fd.append('files[]',x));try{const r=await fetch(location.href,{method:'POST',body:fd}),d=await r.json();if(d.success){p.querySelector('.progress-text').textContent=`✅ 已创建 ${d.name}`;p.querySelector('.progress-bar-fill').style.width='100%';setTimeout(()=>location.reload(),800)}else{alert(d.error);hideProgress()}}catch(e){alert('打包失败');hideProgress()}}
async function unpackFile(name){const p=showProgress(`正在解压 ${name}...`);p.querySelector('.progress-bar-fill').style.width='50%';const fd=new FormData();fd.append('ajax_unpack','1');fd.append('archive',name);fd.append('token',token);try{const r=await fetch(location.href,{method:'POST',body:fd}),d=await r.json();if(d.success){p.querySelector('.progress-text').textContent=`✅ 已解压`;p.querySelector('.progress-bar-fill').style.width='100%';setTimeout(()=>location.reload(),800)}else{alert(d.error);hideProgress()}}catch(e){alert('解压失败');hideProgress()}}
async function uploadFiles(files,folder){const p=showProgress(`上传中 0/${files.length}...`);for(let i=0;i<files.length;i++){const fd=new FormData();fd.append('file',files[i].file);fd.append('relative_path',folder?folder+'/'+files[i].path:files[i].path);fd.append('token',token);p.querySelector('.progress-text').textContent=`上传中 ${i+1}/${files.length}: ${files[i].file.name}`;p.querySelector('.progress-bar-fill').style.width=Math.round((i+1)/files.length*100)+'%';await fetch('?ajax_upload=1&p='+encodeURIComponent(new URLSearchParams(location.search).get('p')||''),{method:'POST',body:fd})}hideProgress();location.reload()}
async function readDir(reader){const all=[],read=()=>new Promise(res=>reader.readEntries(e=>{if(!e.length)res(all);else{all.push(...e);read().then(res)}}));return read()}
async function collectFiles(e,path=''){const r=[];if(e.isFile)await new Promise(res=>e.file(f=>{r.push({file:f,path:path+f.name});res()}));else if(e.isDirectory){const entries=await readDir(e.createReader());for(const x of entries)r.push(...await collectFiles(x,path+e.name+'/'))}return r}
async function handleDrop(e,folder){const items=e.dataTransfer.items,files=[];for(const i of items){const entry=i.webkitGetAsEntry?.()||i.getAsEntry?.();if(entry)files.push(...await collectFiles(entry))}files.length?await uploadFiles(files,folder):alert('未检测到文件')}
function showModal(id){document.getElementById(id).classList.add('show')}
function hideModal(id){document.getElementById(id).classList.remove('show')}
function hideCtx(){document.getElementById('ctx')?.classList.remove('show');document.getElementById('ctxBlank')?.classList.remove('show')}
function showCtx(e,name,isFolder,isArchive){e.preventDefault();ctxTarget=name;const m=document.getElementById('ctx');m.style.left=e.pageX+'px';m.style.top=e.pageY+'px';document.getElementById('ctxDl').style.display=isFolder?'none':'flex';document.getElementById('ctxUnpack').style.display=isArchive?'flex':'none';document.querySelectorAll('.paste-folder-item').forEach(x=>x.style.display=isFolder&&getClip()?'flex':'none');document.querySelectorAll('#ctx .paste-item').forEach(x=>x.style.display='none');m.classList.add('show');const rect=m.getBoundingClientRect();if(rect.right>window.innerWidth)m.style.left=e.pageX-rect.width+'px';if(rect.bottom>window.innerHeight)m.style.top=e.pageY-rect.height+'px'}
function showBlankCtx(e){e.preventDefault();const m=document.getElementById('ctxBlank');m.style.left=e.pageX+'px';m.style.top=e.pageY+'px';m.classList.add('show');const rect=m.getBoundingClientRect();if(rect.right>window.innerWidth)m.style.left=e.pageX-rect.width+'px';if(rect.bottom>window.innerHeight)m.style.top=e.pageY-rect.height+'px'}
function batchDelete(){const n=getNames();if(!n.length){alert('请先选择');return}if(!confirm('确定删除'+n.length+'项?'))return;const f=document.createElement('form');f.method='post';f.innerHTML='<input name="token" value="'+token+'">';n.forEach(x=>{const i=document.createElement('input');i.name='delete[]';i.value=x;f.appendChild(i)});document.body.appendChild(f);f.submit()}

// ===== 收藏功能 =====
async function toggleStar(path,btn){const fd=new FormData();fd.append('ajax_star','1');fd.append('star_path',path);fd.append('token',token);try{const r=await fetch(location.href,{method:'POST',body:fd}),d=await r.json();if(d.success){if(btn){btn.textContent=d.starred?'★':'☆';btn.classList.toggle('starred',d.starred)}refreshStarState();refreshSidebarStars()}}catch(e){}}
function refreshStarState(){document.querySelectorAll('[data-star-path]').forEach(el=>{const p=el.dataset.starPath;const isStar=stars.includes(p);el.textContent=isStar?'★':'☆';el.classList.toggle('starred',isStar)})}
async function emptyTrash(){if(!confirm('确定清空回收站吗？'))return;const p=showProgress('正在清空回收站...');const fd=new FormData();fd.append('ajax_empty_trash','1');fd.append('token',token);try{const r=await fetch(location.href,{method:'POST',body:fd}),d=await r.json();if(d.success){p.querySelector('.progress-text').textContent=`✅ 已清空 ${d.count} 项`;p.querySelector('.progress-bar-fill').style.width='100%';setTimeout(()=>location.reload(),800)}else{alert(d.error||'清空失败');hideProgress()}}catch(e){alert('清空回收站失败');hideProgress()}}
function refreshSidebarStars(){const starList=document.getElementById('starList');if(!starList)return;const html=stars.length?stars.map(sp=>{const n=sp.split('/').pop(),isDir=currentPath.split('/').includes(sp.split('/')[0]);return`<li class="sidebar-item ${currentPath===sp?'active':''}"><span class="item-icon">📁</span><a href="?p=${encodeURIComponent(sp)}" class="item-name" title="${sp.replace(/"/g,'&quot;')}">${n}</a><span class="star-btn starred" onclick="event.stopPropagation();toggleStar('${sp.replace(/'/g,"\\'")}',this)" data-star-path="${sp.replace(/"/g,'&quot;')}" title="取消收藏">★</span></li>`}).join(''):'<li style="padding:8px 16px;font-size:12px;color:var(--muted)">暂无收藏</li>';starList.innerHTML=html}
function toggleHidden(){const btn=document.getElementById('toggleHiddenBtn');const show=btn.textContent.includes('显示');document.querySelectorAll('tr[data-hidden="1"]').forEach(r=>r.style.display=show?'':'none');btn.innerHTML=show?'👁️ <s>隐藏</s> 隐藏文件':'👁️ 显示隐藏文件';btn.style.color=show?'var(--muted)':'var(--text)';sessionStorage.setItem('fm_showHidden',show?'1':'0')}

// 侧边栏折叠
function toggleSection(id){const sec=document.getElementById(id);const title=sec.previousElementSibling;if(sec.style.display==='none'){sec.style.display='';title.classList.remove('collapsed')}else{sec.style.display='none';title.classList.add('collapsed')}}

function startNew(type){var table=document.querySelector('#dropZone table tbody')||document.querySelector('#dropZone table');var tr=document.createElement('tr');tr.className='inline-edit';var icon=type==='folder'?'📁':'📄',defName=type==='folder'?'新建文件夹':'新建文件';tr.innerHTML='<td></td><td><span style="margin-right:4px">'+icon+'</span> <input type="text" class="inline-input" value="'+defName+'" data-type="'+type+'" style="min-width:120px"> <button type="button" class="btn btn-primary" style="padding:2px 8px;font-size:12px">✓</button> <button type="button" class="btn" style="padding:2px 8px;font-size:12px">✕</button></td><td>'+type+'</td><td>-</td><td>-</td>';var firstRow=table.querySelector('tr[data-name]');if(firstRow)table.insertBefore(tr,firstRow);else table.appendChild(tr);var input=tr.querySelector('.inline-input');var btnOk=tr.querySelectorAll('button')[0],btnCancel=tr.querySelectorAll('button')[1];function doCreate(){var name=input.value.trim();if(!name){alert('名称不能为空');return}var fd=new FormData();fd.append('token',token);fd.append('ajax_check_name','1');fd.append('name',name);fd.append('type',type);fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.error){alert(d.error);return}if(d.duplicate){input.value=d.suggest;input.style.background='rgba(255,204,0,.15)';input.style.borderColor='#ffcc00';input.select();var tip=tr.querySelector('.dup-tip');if(!tip){tip=document.createElement('span');tip.className='dup-tip';tip.style.cssText='color:#ffcc00;font-size:11px;margin-left:4px';tr.querySelector('td:nth-child(2)').appendChild(tip)}tip.textContent='→ 将创建为 '+d.suggest;return}var cfd=new FormData();cfd.append('token',token);cfd.append('ajax_new','1');cfd.append(type==='folder'?'new_folder':'new_file',name);fetch(location.href,{method:'POST',body:cfd}).then(r=>r.json()).then(d=>{if(d.error)alert(d.error);else location.reload()}).catch(()=>alert('创建失败'))}).catch(()=>alert('创建失败'))}btnOk.onclick=function(e){e.stopPropagation();doCreate()};btnCancel.onclick=function(e){e.stopPropagation();tr.remove()};input.focus();input.select();input.addEventListener('keydown',function(e){e.stopPropagation();if(e.key==='Enter'){e.preventDefault();doCreate()}else if(e.key==='Escape'){tr.remove()}})}
function startRename(name){const row=document.querySelector('tr[data-name="'+name+'"]');if(!row)return;const cell=row.querySelectorAll('td')[1],isFolder=row.dataset.type==='folder',icon=isFolder?'📁':(row.dataset.archive==='1'?'📦':'📄'),link=isFolder?(cell.querySelector('a')?.href||''):'';cell.innerHTML='<form method="post" style="display:inline-flex;align-items:center;gap:4px" onclick="event.stopPropagation()">'+icon+' <input type="hidden" name="token" value="'+token+'"><input type="hidden" name="rename_from" value="'+name+'"><input type="text" name="rename_to" class="inline-input" value="'+name+'" required autofocus><button type="submit" class="btn btn-primary" style="padding:2px 8px;font-size:12px">✓</button><button type="button" class="btn" style="padding:2px 8px;font-size:12px" onclick="cancelRename(this,\''+name+'\',\''+icon+'\','+isFolder+',\''+link+'\')">✕</button></form>';row.ondblclick=null;row.draggable=false}
function cancelRename(btn,name,icon,isFolder,link){const row=btn.closest('tr'),cell=row.querySelectorAll('td')[1];if(isFolder){cell.innerHTML='<a href="'+link+'" class="folder-link" data-folder="'+name+'" onclick="event.stopPropagation()">'+icon+' '+name+'</a>';bindFolderDrop(cell.querySelector('.folder-link'))}else cell.innerHTML='<span>'+icon+'</span> '+name;row.ondblclick=isFolder?()=>location.href=link:()=>location.href='?p='+encodeURIComponent(new URLSearchParams(location.search).get('p')||'')+'&dl='+encodeURIComponent(name);row.draggable=true}
function bindFolderDrop(el){if(!el)return;el.addEventListener('dragover',e=>{e.preventDefault();e.stopPropagation();el.classList.add('drag-over')});el.addEventListener('dragleave',()=>el.classList.remove('drag-over'));el.addEventListener('drop',async e=>{e.preventDefault();e.stopPropagation();el.classList.remove('drag-over');const folder=el.dataset.folder;if(draggedItem){const fd=new FormData();fd.append('ajax_move','1');fd.append('src',draggedItem);fd.append('dst',folder);fd.append('token',token);try{const r=await fetch(location.href,{method:'POST',body:fd}),d=await r.json();d.success?location.reload():alert(d.error)}catch(e){alert('移动失败')}}else handleDrop(e,folder)})}
document.addEventListener('keydown',e=>{if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA')return;if((e.ctrlKey||e.metaKey)&&e.key==='a'){e.preventDefault();document.querySelectorAll('.file-check').forEach(c=>c.checked=true);updateSel()}else if((e.ctrlKey||e.metaKey)&&e.key==='c'){e.preventDefault();const n=getNames();n.length===1&&setClip({mode:'copy',name:n[0],from:location.search})}else if((e.ctrlKey||e.metaKey)&&e.key==='x'){e.preventDefault();const n=getNames();n.length===1&&setClip({mode:'cut',name:n[0],from:location.search})}else if((e.ctrlKey||e.metaKey)&&e.key==='v'){e.preventDefault();pasteTo('')}else if(e.key==='Escape'){document.querySelectorAll('.modal.show').forEach(m=>m.classList.remove('show'));hideCtx()}});
document.addEventListener('click',hideCtx);
const dropZone=document.getElementById('dropZone');let dropTarget=null;
dropZone?.addEventListener('dragover',e=>{e.preventDefault();!dropTarget&&dropZone.classList.add('drag-over')});
dropZone?.addEventListener('dragleave',()=>dropZone.classList.remove('drag-over'));
dropZone?.addEventListener('drop',e=>{e.preventDefault();dropZone.classList.remove('drag-over');dropTarget=null;handleDrop(e,'')});
document.querySelectorAll('[draggable="true"]').forEach(el=>{el.addEventListener('dragstart',e=>{draggedItem=el.dataset.name;el.classList.add('dragging');e.dataTransfer.effectAllowed='move'});el.addEventListener('dragend',()=>{el.classList.remove('dragging');draggedItem=null})});
document.querySelectorAll('.folder-link').forEach(bindFolderDrop);
document.querySelectorAll('[data-ctx]').forEach(el=>el.addEventListener('contextmenu',e=>showCtx(e,el.dataset.name,el.dataset.type==='folder',el.dataset.archive==='1')));
dropZone?.addEventListener('contextmenu',e=>{if(e.target.closest('tr[data-name]'))return;showBlankCtx(e)});
updatePaste();
refreshStarState();
(function(){const sh=sessionStorage.getItem('fm_showHidden');if(sh==='0'){document.querySelectorAll('tr[data-hidden="1"]').forEach(r=>r.style.display='none');const btn=document.getElementById('toggleHiddenBtn');if(btn){btn.innerHTML='👁️ <s>隐藏</s> 隐藏文件';btn.style.color='var(--muted)'}}})();
JS;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=APP_TITLE?></title><style><?=$css?></style></head>
<body>
<?php if (!$is_logged): ?>
<div class="login-box">
    <h2>📁 <?=APP_TITLE?></h2>
    <?php if (isset($login_error)): ?><div class="alert alert-error"><?=$login_error?></div><?php endif; ?>
    <form method="post">
        <div class="form-group" style="margin-bottom:12px"><input type="text" name="user" class="form-control" placeholder="<?=lng('Username')?>" required autofocus></div>
        <div class="form-group" style="margin-bottom:12px"><input type="password" name="pass" class="form-control" placeholder="<?=lng('Password')?>" required></div>
        <button type="submit" name="login" class="btn btn-primary" style="width:100%"><?=lng('Login')?></button>
    </form>
</div>
<?php elseif(isset($_GET['trash'])): ?>
<?php $t=fm_trash_dir(); $meta=json_decode(file_get_contents($t.'/.trash_meta.json')?:'{}',true); ?>
<div class="main-area"><div style="max-width:960px;margin:20px auto;background:var(--card);border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.3)">
    <div class="header"><h1>🗑️ <?=lng('Trash')?></h1><div class="header-actions"><a href="?p=" class="btn">📁 文件管理</a><a href="?logout" class="btn"><?=lng('Logout')?></a></div></div>
    <div class="toolbar"><button type="button" class="btn btn-danger" onclick="emptyTrash()">🗑️ <?=lng('Empty Trash')?></button></div>
    <div class="table-container"><table>
        <tr><th>名称</th><th style="width:100px">类型</th><th style="width:200px">原路径</th><th style="width:150px">删除时间</th><th style="width:140px">操作</th></tr>
        <?php if(empty($meta)): ?><tr><td colspan="5" class="empty"><?=lng('Trash is empty')?></td></tr><?php endif; ?>
        <?php foreach($meta as $k=>$v): ?>
        <tr><td><?=htmlspecialchars($v['name'])?></td><td><?=$v['dir']?'文件夹':'文件'?></td><td style="font-size:12px;color:var(--muted)"><?=htmlspecialchars(str_replace($root_path,'',$v['path']))?></td><td><?=date('Y-m-d H:i',$v['time'])?></td><td><a href="?trash&restore=<?=urlencode($k)?>&token=<?=$_SESSION['token']?>" class="btn btn-primary" style="font-size:12px;padding:3px 8px"><?=lng('Restore')?></a> <a href="?trash&purge=<?=urlencode($k)?>&token=<?=$_SESSION['token']?>" class="btn btn-danger" style="font-size:12px;padding:3px 8px" onclick="return confirm('确定彻底删除?')"><?=lng('Purge')?></a></td></tr>
        <?php endforeach; ?>
    </table></div>
    <div class="footer">共 <?=count($meta)?> 项 · 7天后自动删除</div>
</div></div>
<?php else: ?>

<!-- ===== 主布局：侧边栏 + 内容区 ===== -->
<div class="sidebar">
    <div class="sidebar-header">☁️ <?=APP_TITLE?></div>

    <!-- 收藏夹 -->
    <div class="sidebar-section">
        <div class="sidebar-title" onclick="toggleSection('starList')">⭐ <?=lng('Favorites')?> <span class="arrow">▼</span></div>
        <ul class="sidebar-list" id="starList">
            <?php if(empty($stars)): ?>
            <li style="padding:8px 16px;font-size:12px;color:var(--muted)">暂无收藏</li>
            <?php else: foreach($stars as $sp): $sp_name=basename($sp); $sp_is_dir=is_dir($root_path.'/'.($sp?$sp.'/':'.')); ?>
            <li class="sidebar-item <?=$p===$sp?'active':''?>">
                <span class="item-icon"><?=$sp_is_dir?'📁':'📄'?></span>
                <a href="?p=<?=urlencode($sp)?>" class="item-name" title="<?=htmlspecialchars($sp)?>"><?=htmlspecialchars($sp_name)?></a>
                <span class="star-btn starred" onclick="event.stopPropagation();toggleStar('<?=addslashes($sp)?>',this)" data-star-path="<?=addslashes($sp)?>" title="取消收藏">★</span>
            </li>
            <?php endforeach; endif; ?>
        </ul>
    </div>

    <!-- 我的文档（当前目录下的子文件夹） -->
    <div class="sidebar-section">
        <div class="sidebar-title" onclick="toggleSection('quickList')">🏠 <?=lng('My Files')?> <span class="arrow">▼</span></div>
        <ul class="sidebar-list" id="quickList">
            <li class="sidebar-item <?=$p===''?'active':''?>">
                <span class="item-icon">🏠</span>
                <a href="?p=" class="item-name">根目录</a>
            </li>
            <?php foreach($folders as $sf): $sf_path=trim($p.'/'.$sf,'/'); ?>
            <li class="sidebar-item">
                <span class="item-icon">📁</span>
                <a href="?p=<?=urlencode($sf_path)?>" class="item-name"><?=$sf?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- 底部操作 -->
    <div style="margin-top:auto;padding:12px 16px;border-top:1px solid var(--border)">
        <a href="?trash" class="sidebar-item" style="padding:5px 0"><span class="item-icon">🗑️</span> <?=lng('Trash')?></a>
        <a href="?logout" class="sidebar-item" style="padding:5px 0"><span class="item-icon">🚪</span> <?=lng('Logout')?></a>
    </div>
</div>

<!-- 主内容区 -->
<div class="main-area">
<div style="max-width:100%;background:var(--card);flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0">
    <div class="header"><h1>📂 <?=htmlspecialchars(basename($p)?:'根目录')?></h1><div class="header-actions"></div></div>
    <div class="breadcrumb"><a href="?p=">🏠</a> <?php $acc=''; foreach(array_filter(explode('/',$p)) as $part): $acc.=($acc?'/':'').$part; ?><span>›</span><a href="?p=<?=urlencode($acc)?>"><?=htmlspecialchars($part)?></a><?php endforeach; ?></div>
    <?php if(isset($msg)): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>
    <!-- Toolbar: 在 form 外部，固定在滚动区外 -->
    <div class="toolbar">
        <button type="button" class="btn btn-primary" onclick="startNew('folder')">📁 <?=lng('New Folder')?></button>
        <button type="button" class="btn" onclick="startNew('file')">📄 <?=lng('New File')?></button>
        <button type="button" class="btn" onclick="showModal('uploadModal')">📤 <?=lng('Upload')?></button>
        <button type="button" class="btn" onclick="const n=getNames();n.length===1?location.href='?p=<?=urlencode($p)?>&dl='+encodeURIComponent(n[0]):n.length>1?(fd=>{n.forEach(x=>fd.append('names[]',x));fetch('?ajax_batch_download=1',{method:'POST',body:fd}).then(r=>r.blob()).then(b=>{const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='download.zip';a.click()})})(new FormData()):alert('请先选择文件')">⬇️ <?=lng('Download')?></button>
        <button type="button" class="btn" onclick="const n=getNames();n.length===1?startRename(n[0]):alert('只能重命名单个文件')">✏️ <?=lng('Rename')?></button>
        <button type="button" class="btn" onclick="const n=getNames();n.length===1?setClip({mode:'copy',name:n[0],from:location.search}):alert('只能复制单个')">📋 <?=lng('Copy')?></button>
        <button type="button" class="btn" onclick="const n=getNames();n.length===1?setClip({mode:'cut',name:n[0],from:location.search}):alert('只能剪切单个')">✂️ <?=lng('Move')?></button>
        <button type="button" class="btn paste-item" style="display:none" onclick="pasteTo('')">📋 粘贴</button>
        <button type="button" class="btn btn-danger" onclick="batchDelete()">🗑️ <?=lng('Delete')?></button>
        <div class="dropdown"><button type="button" class="btn" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">⋯ 更多 ▼</button>
        <div class="dropdown-menu" style="display:none"><div class="dropdown-item" onclick="packFiles('zip')">📦 ZIP</div><div class="dropdown-item" onclick="packFiles('tar')">📦 TAR</div><div class="dropdown-item" id="toggleHiddenBtn" onclick="toggleHidden()">👁️ 显示隐藏文件</div></div></div>
        <span class="hint">💡 拖拽上传/移动</span>
    </div>
    <form method="post" id="mainForm" style="display:flex;flex-direction:column;flex:1"><input type="hidden" name="token" value="<?=$_SESSION['token']?>">
    <div class="table-container" id="dropZone"><table>
        <tr><th style="width:30px"><input type="checkbox" id="selectAll" class="checkbox" onchange="toggleAll()"></th><th style="width:30px"></th><th><?=lng('Name')?></th><th style="width:80px">类型</th><th style="width:100px"><?=lng('Size')?></th><th style="width:150px"><?=lng('Modified')?></th></tr>
        <?php if($parent!==false): ?><tr><td></td><td></td><td><a href="?p=<?=urlencode($parent)?>">📁 ..</a></td><td>-</td><td>-</td><td></td></tr><?php endif; ?>
        <?php foreach($folders as $f): $fp=trim($p.'/'.$f,'/'); $fs=in_array($fp,$stars); ?>
        <tr draggable="true" data-name="<?=htmlspecialchars($f)?>" data-ctx="1" data-type="folder" data-hidden="<?=$f[0]==='.'?1:0?>" onclick="selectRow(this,event)" ondblclick="location.href='?p=<?=urlencode($fp)?>'">
            <td><input type="checkbox" name="files[]" value="<?=htmlspecialchars($f)?>" class="checkbox file-check" onclick="event.stopPropagation()"></td>
            <td class="star-cell"><span class="star-icon <?=$fs?'starred':''?>" onclick="event.stopPropagation();toggleStar('<?=addslashes($fp)?>',this)" data-star-path="<?=addslashes($fp)?>" title="<?=$fs?'取消收藏':'收藏'?>"><?=$fs?'★':'☆'?></span></td>
            <td><a href="?p=<?=urlencode($fp)?>" class="folder-link" data-folder="<?=htmlspecialchars($f)?>" onclick="event.stopPropagation()">📁 <?=htmlspecialchars($f)?></a></td>
            <td>文件夹</td><td>-</td><td><?=date('Y-m-d H:i',filemtime($current_path.'/'.$f))?></td>
        </tr>
        <?php endforeach; ?>
        <?php foreach($files as $f): $ext=strtolower(pathinfo($f,PATHINFO_EXTENSION)); $isZip=in_array($ext,['zip','tar','gz','tgz']); ?>
        <tr draggable="true" data-name="<?=htmlspecialchars($f)?>" data-ctx="1" data-type="file" data-archive="<?=$isZip?1:0?>" data-hidden="<?=$f[0]==='.'?1:0?>" onclick="selectRow(this,event)" ondblclick="location.href='?p=<?=urlencode($p)?>&dl=<?=urlencode($f)?>'">
            <td><input type="checkbox" name="files[]" value="<?=htmlspecialchars($f)?>" class="checkbox file-check" onclick="event.stopPropagation()"></td>
            <td></td>
            <td><?=$isZip?'📦':'📄'?> <?=htmlspecialchars($f)?></td>
            <td><?=strtoupper($ext)?></td><td><?=fmtsize(filesize($current_path.'/'.$f))?></td><td><?=date('Y-m-d H:i',filemtime($current_path.'/'.$f))?></td>
        </tr>
        <?php endforeach; ?>
    </table><?php if(empty($folders)&&empty($files)&&$parent===false): ?><div class="empty">空文件夹 - 拖拽文件上传</div><?php endif; ?></div>
    </form>
    <div class="footer"><?=count($folders)?> 文件夹 · <?=count($files)?> 文件</div>
</div>
</div><!-- end main-area -->

<!-- 上传模态框 -->
<div class="modal" id="uploadModal"><div class="modal-content">
    <div class="modal-header">📤 <?=lng('Upload')?></div>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="token" value="<?=$_SESSION['token']?>">
    <div class="modal-body"><input type="file" name="file" class="form-control" required><div style="margin-top:10px;color:var(--muted);font-size:12px">支持拖拽上传</div></div>
    <div class="modal-footer"><button type="button" class="btn" onclick="hideModal('uploadModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-primary"><?=lng('Upload')?></button></div>
    </form>
</div></div>
<?php endif; ?>

<script><?=$js?></script>

<!-- 右键菜单 -->
<div class="context-menu" id="ctx">
  <div class="context-menu-item" id="ctxDl" onclick="ctxTarget&&(location.href='?p=<?=urlencode($p)?>&dl='+encodeURIComponent(ctxTarget))">⬇️ 下载</div>
  <div class="context-menu-item" onclick="ctxTarget&&startRename(ctxTarget)">✏️ 重命名</div>
  <div class="context-menu-item" onclick="ctxTarget&&setClip({mode:'copy',name:ctxTarget,from:location.search})">📋 复制</div>
  <div class="context-menu-item" onclick="ctxTarget&&setClip({mode:'cut',name:ctxTarget,from:location.search})">✂️ 剪切</div>
  <div class="context-menu-item paste-item" style="display:none" onclick="pasteTo('')">📋 粘贴</div>
  <div class="context-menu-item paste-folder-item" style="display:none" onclick="ctxTarget&&pasteTo(ctxTarget)">📋 粘贴到此文件夹</div>
  <div class="context-menu-divider"></div>
  <div class="context-menu-item" id="ctxStar" onclick="ctxTarget&&(hideCtx(),toggleStar(currentPath+'/'+ctxTarget,null))">⭐ 收藏</div>
  <div class="context-menu-divider"></div>
  <div class="context-menu-item" onclick="ctxTarget&&(hideCtx(),packFiles('zip'))">📦 ZIP</div>
  <div class="context-menu-item" onclick="ctxTarget&&(hideCtx(),packFiles('tar'))">📦 TAR</div>
  <div class="context-menu-item" id="ctxUnpack" style="display:none" onclick="ctxTarget&&(hideCtx(),unpackFile(ctxTarget))">📦 解压</div>
  <div class="context-menu-item danger" onclick="ctxTarget&&document.querySelector('input[value=\x22'+ctxTarget+'\x22]')&&(document.querySelector('input[name=delete]')?document.querySelector('input[name=delete]').value=ctxTarget:(document.getElementById('mainForm').innerHTML+='<input name=delete value=\x22'+ctxTarget+'\x22>'),document.getElementById('mainForm').submit())">🗑️ 删除</div>
</div>

<div class="context-menu" id="ctxBlank">
  <div class="context-menu-item" onclick="showModal('uploadModal')">📤 上传</div>
  <div class="context-menu-item paste-item" style="display:none" onclick="pasteTo('')">📋 粘贴</div>
  <div class="context-menu-item" onclick="startNew('folder')">📁 新建文件夹</div>
  <div class="context-menu-item" onclick="startNew('file')">📄 新建文件</div>
  <div class="context-menu-divider"></div>
  <div class="context-menu-item" onclick="packFiles('zip')">📦 ZIP</div>
  <div class="context-menu-item" onclick="packFiles('tar')">📦 TAR</div>
</div>
</body>
</html>

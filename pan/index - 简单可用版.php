<?php
/**
 * 文件管理器 - 无外部依赖版
 * 功能：登录、浏览、上传、下载、新建、重命名、复制、移动、删除、ZIP/TAR打包、解压、拖拽上传、拖拽移动
 */
session_start();

// ========== 配置 ==========
define('APP_TITLE', '文件管理器');
define('FM_SESSION_ID', 'filemanager');

$auth_user = 'admin';
$auth_pass = '$2y$10$m7JT3rdrZ3nK4tKxpJ8z5.pDalU75BZH9nu82zBJkVNhLJgoC6uXm'; // admin
$root_path = $_SERVER['DOCUMENT_ROOT'];
$max_upload_size = 5000000000;

// ========== 初始化 ==========
date_default_timezone_set('Asia/Shanghai');
ini_set('default_charset', 'UTF-8');
set_time_limit(600);

if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

function fm_clean_path($path) {
    $path = trim($path);
    $path = str_replace(['\\', '..'], ['/', ''], $path);
    return preg_replace('#/+#', '/', $path);
}

function fm_rmdir_recursive($dir) {
    if (!is_dir($dir)) return false;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) @rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    return @rmdir($dir);
}

function fm_copy_recursive($src, $dst) {
    if (is_file($src)) return copy($src, $dst);
    if (!is_dir($src)) return false;
    @mkdir($dst, 0755, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $rel = substr($item->getPathname(), strlen($src));
        $target = $dst . $rel;
        if ($item->isDir()) @mkdir($target, 0755, true);
        else copy($item->getPathname(), $target);
    }
    return true;
}

$p = isset($_GET['p']) ? fm_clean_path($_GET['p']) : '';
$current_path = $root_path . ($p ? '/' . $p : '');
$current_path = realpath($current_path);

if ($current_path === false || strpos($current_path, realpath($root_path)) !== 0) {
    die('非法路径');
}

$self_url = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . 
            '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

// ========== 语言 ==========
function lng($key) {
    static $lang = [
        'Login' => '登录', 'Logout' => '退出', 'Username' => '用户名', 'Password' => '密码',
        'Login failed' => '登录失败', 'Name' => '名称', 'Size' => '大小', 'Modified' => '修改时间',
        'Actions' => '操作', 'Download' => '下载', 'Upload' => '上传', 'New Folder' => '新建文件夹',
        'New File' => '新建文件', 'Rename' => '重命名', 'Copy' => '复制', 'Move' => '移动', 'Delete' => '删除',
        'Cancel' => '取消', 'Save' => '保存', 'Confirm' => '确认', 'Created' => '已创建', 'Uploaded' => '已上传',
        'Deleted' => '已删除', 'Renamed' => '已重命名', 'Copied' => '已复制', 'Moved' => '已移动',
        'Error' => '错误', 'Pack ZIP' => '打包ZIP', 'Pack TAR' => '打包TAR', 'Unpack' => '解压',
        'Archive created' => '压缩包已创建', 'Archive unpacked' => '已解压', 'Select items' => '请先选择',
        'Drop to upload' => '拖拽上传', 'Drag to move' => '拖拽移动',
    ];
    return $lang[$key] ?? $key;
}

// ========== 认证 ==========
$is_logged = isset($_SESSION[FM_SESSION_ID]);

if (isset($_GET['logout'])) {
    unset($_SESSION[FM_SESSION_ID]);
    session_destroy();
    header('Location: ' . $self_url);
    exit;
}

if (!$is_logged && isset($_POST['login'])) {
    if ($_POST['user'] === $auth_user && password_verify($_POST['pass'], $auth_pass)) {
        $_SESSION[FM_SESSION_ID] = $auth_user;
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    } else {
        $login_error = lng('Login failed');
    }
}

// ========== AJAX 上传（支持拖拽批量+文件夹） ==========
if ($is_logged && isset($_GET['ajax_upload']) && isset($_POST['token']) && $_POST['token'] === $_SESSION['token']) {
    header('Content-Type: application/json');
    
    if (!empty($_FILES['file'])) {
        $file = $_FILES['file'];
        $relative_path = isset($_POST['relative_path']) ? fm_clean_path($_POST['relative_path']) : '';
        
        // 处理文件夹上传的相对路径
        if ($relative_path) {
            $target_dir = $current_path . '/' . dirname($relative_path);
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            $target = $current_path . '/' . $relative_path;
        } else {
            $target = $current_path . '/' . fm_clean_path($file['name']);
        }
        
        if ($file['size'] <= $max_upload_size) {
            if (move_uploaded_file($file['tmp_name'], $target)) {
                echo json_encode(['success' => true, 'name' => basename($target)]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Upload failed']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'File too large']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'No file']);
    }
    exit;
}

// ========== AJAX 移动（拖拽移动） ==========
if ($is_logged && isset($_POST['ajax_move']) && isset($_POST['token']) && $_POST['token'] === $_SESSION['token']) {
    header('Content-Type: application/json');
    
    $src_name = fm_clean_path($_POST['src']);
    $dst_folder = fm_clean_path($_POST['dst']);
    
    $src = $current_path . '/' . $src_name;
    $dst = $current_path . '/' . $dst_folder . '/' . $src_name;
    
    if (file_exists($src) && !file_exists($dst) && $dst_folder && is_dir($current_path . '/' . $dst_folder)) {
        if (rename($src, $dst)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Move failed']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid path']);
    }
    exit;
}

// ========== AJAX 粘贴（剪切/复制到目标文件夹） ==========
if ($is_logged && isset($_POST['ajax_paste']) && isset($_POST['token']) && $_POST['token'] === $_SESSION['token']) {
    header('Content-Type: application/json');
    
    $src_name = fm_clean_path($_POST['src']);
    $dst_folder = fm_clean_path($_POST['dst_folder']);
    $mode = $_POST['mode'] === 'copy' ? 'copy' : 'cut';
    
    // 解析来源路径
    $from_p = '';
    if (!empty($_POST['from'])) {
        parse_str(parse_url($_POST['from'], PHP_URL_QUERY), $from_params);
        $from_p = isset($from_params['p']) ? $from_params['p'] : '';
    }
    $from_path = $root_path . '/' . $from_p;
    $src = $from_path . '/' . $src_name;
    
    // 目标路径
    $dst_dir = $current_path . ($dst_folder ? '/' . $dst_folder : '');
    $dst = $dst_dir . '/' . $src_name;
    
    if (!file_exists($src)) {
        echo json_encode(['success' => false, 'error' => '源文件不存在']);
        exit;
    }
    if (file_exists($dst)) {
        echo json_encode(['success' => false, 'error' => '目标已存在']);
        exit;
    }
    if (!is_dir($dst_dir)) {
        echo json_encode(['success' => false, 'error' => '目标目录不存在']);
        exit;
    }
    
    if ($mode === 'cut') {
        if (rename($src, $dst)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => '移动失败']);
        }
    } else {
        fm_copy_recursive($src, $dst);
        echo json_encode(['success' => true]);
    }
    exit;
}

// ========== AJAX 批量下载（自动打包zip流式下载） ==========
if ($is_logged && isset($_POST['ajax_batch_download']) && isset($_POST['token']) && $_POST['token'] === $_SESSION['token'] && class_exists('ZipArchive')) {
    $names = isset($_POST['names']) ? $_POST['names'] : [];
    if (empty($names)) { echo '[]'; exit; }
    
    $tmpFile = tempnam(sys_get_temp_dir(), 'fmdl_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE) !== TRUE) { echo '[]'; exit; }
    
    foreach ($names as $n) {
        $n = fm_clean_path($n);
        $target = $current_path . '/' . $n;
        if (is_file($target)) {
            $zip->addFile($target, $n);
        } elseif (is_dir($target)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                $rel = $n . '/' . substr($item->getPathname(), strlen($target) + 1);
                if ($item->isDir()) $zip->addEmptyDir($rel);
                else $zip->addFile($item->getPathname(), $rel);
            }
        }
    }
    $zip->close();
    
    $zipName = 'download_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

if ($is_logged && isset($_POST['token']) && $_POST['token'] === $_SESSION['token'] && !isset($_POST['ajax_move'])) {
    
    // 新建文件夹
    if (isset($_POST['new_folder'])) {
        $name = fm_clean_path($_POST['new_folder']);
        if ($name && !file_exists($current_path . '/' . $name)) {
            mkdir($current_path . '/' . $name, 0755);
            $msg = lng('Created');
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 新建文件
    if (isset($_POST['new_file'])) {
        $name = fm_clean_path($_POST['new_file']);
        if ($name && !file_exists($current_path . '/' . $name)) {
            file_put_contents($current_path . '/' . $name, '');
            $msg = lng('Created');
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 上传（传统方式）
    if (!empty($_FILES['file']) && !isset($_GET['ajax_upload'])) {
        $file = $_FILES['file'];
        $name = fm_clean_path($file['name']);
        $target = $current_path . '/' . $name;
        if ($name && $file['size'] <= $max_upload_size) {
            move_uploaded_file($file['tmp_name'], $target);
            $msg = lng('Uploaded');
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 重命名
    if (isset($_POST['rename_from'], $_POST['rename_to'])) {
        $old = $current_path . '/' . fm_clean_path($_POST['rename_from']);
        $new = $current_path . '/' . fm_clean_path($_POST['rename_to']);
        if (file_exists($old) && !file_exists($new)) {
            rename($old, $new);
            $msg = lng('Renamed');
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 复制
    if (isset($_POST['copy_src'], $_POST['copy_dst'])) {
        $src = $current_path . '/' . fm_clean_path($_POST['copy_src']);
        $dst = $current_path . '/' . fm_clean_path($_POST['copy_dst']);
        if (file_exists($src) && !file_exists($dst)) {
            fm_copy_recursive($src, $dst);
            $msg = lng('Copied');
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 移动
    if (isset($_POST['move_src'], $_POST['move_dst'])) {
        $src = $current_path . '/' . fm_clean_path($_POST['move_src']);
        $dst = $current_path . '/' . fm_clean_path($_POST['move_dst']);
        if (file_exists($src) && !file_exists($dst)) {
            rename($src, $dst);
            $msg = lng('Moved');
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 删除（单个）
    if (isset($_POST['delete']) && !is_array($_POST['delete'])) {
        $target = $current_path . '/' . fm_clean_path($_POST['delete']);
        if (is_file($target)) @unlink($target);
        elseif (is_dir($target)) fm_rmdir_recursive($target);
        $msg = lng('Deleted');
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    // 删除（批量）
    if (isset($_POST['delete']) && is_array($_POST['delete'])) {
        foreach ($_POST['delete'] as $item) {
            $target = $current_path . '/' . fm_clean_path($item);
            if (is_file($target)) @unlink($target);
            elseif (is_dir($target)) fm_rmdir_recursive($target);
        }
        $msg = lng('Deleted');
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 打包 ZIP
    if (isset($_POST['pack_zip']) && !empty($_POST['files']) && class_exists('ZipArchive')) {
        $zipname = 'archive_' . date('Ymd_His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($current_path . '/' . $zipname, ZipArchive::CREATE) === TRUE) {
            foreach ($_POST['files'] as $f) {
                $f = fm_clean_path($f);
                $target = $current_path . '/' . $f;
                if (is_file($target)) {
                    $zip->addFile($target, $f);
                } elseif (is_dir($target)) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iterator as $item) {
                        $relPath = $f . '/' . substr($item->getPathname(), strlen($target) + 1);
                        if ($item->isDir()) $zip->addEmptyDir($relPath);
                        else $zip->addFile($item->getPathname(), $relPath);
                    }
                }
            }
            $zip->close();
            $msg = lng('Archive created') . ': ' . $zipname;
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 打包 TAR
    if (isset($_POST['pack_tar']) && !empty($_POST['files']) && class_exists('PharData')) {
        $tarname = 'archive_' . date('Ymd_His') . '.tar';
        try {
            $tar = new PharData($current_path . '/' . $tarname);
            foreach ($_POST['files'] as $f) {
                $f = fm_clean_path($f);
                $target = $current_path . '/' . $f;
                if (is_dir($target)) {
                    $tar->buildFromDirectory($target, '#^' . preg_quote($target . '/', '#') . '#');
                }
            }
            $msg = lng('Archive created') . ': ' . $tarname;
        } catch (Exception $e) {
            $msg = lng('Error') . ': ' . $e->getMessage();
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
    
    // 解压
    if (isset($_POST['unpack']) && !empty($_POST['archive'])) {
        $archive = fm_clean_path($_POST['archive']);
        $filepath = $current_path . '/' . $archive;
        $ext = strtolower(pathinfo($archive, PATHINFO_EXTENSION));
        
        if ($ext === 'zip' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filepath) === TRUE) {
                $zip->extractTo($current_path);
                $zip->close();
                $msg = lng('Archive unpacked');
            }
        } elseif (in_array($ext, ['tar', 'gz', 'tgz']) && class_exists('PharData')) {
            try {
                $phar = new PharData($filepath);
                $phar->extractTo($current_path);
                $msg = lng('Archive unpacked');
            } catch (Exception $e) {
                $msg = lng('Error') . ': ' . $e->getMessage();
            }
        }
        header('Location: ' . $self_url . '?p=' . urlencode($p));
        exit;
    }
}

// 下载
if ($is_logged && isset($_GET['dl'])) {
    $dl = fm_clean_path($_GET['dl']);
    $file = $current_path . '/' . $dl;
    if (is_file($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// ========== 文件列表 ==========
$folders = [];
$files = [];
if (is_dir($current_path)) {
    foreach (scandir($current_path) as $item) {
        if ($item === '.' || $item === '..' || substr($item, 0, 1) === '.') continue;
        $full = $current_path . '/' . $item;
        if (is_dir($full)) $folders[] = $item;
        else $files[] = $item;
    }
    natcasesort($folders);
    natcasesort($files);
}

$parent = $p ? dirname($p) : false;
if ($parent === '.') $parent = '';

function format_size($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

// ========== 内联 CSS ==========
$css = <<<'CSS'
:root {
  --bg: #f5f7fa; --card: #fff; --border: #e5e9ef; --border2: #d0d7de;
  --text: #333; --text2: #555; --text3: #666; --muted: #999; --heading: #1a1a1a;
  --primary: #4f8ff7; --primary-h: #3a7de6;
  --hover: #f8f9fb; --selected: #eef3ff; --input-bg: #fff;
  --shadow: rgba(0,0,0,.12); --overlay: rgba(0,0,0,.35);
  --drop-zone: #eef3ff;
  --alert-s-bg: #ecfdf5; --alert-s-b: #6ee7b7; --alert-s-t: #065f46;
  --alert-e-bg: #fef2f2; --alert-e-b: #fca5a5; --alert-e-t: #991b1b;
  --danger: #e5534b; --danger-h: #d1242f; --danger-bg: #fef2f2;
}
body.dark {
  --bg: #1a1a2e; --card: #16213e; --border: #0f3460; --border2: #1a4a7a;
  --text: #d0d0d0; --text2: #bbb; --text3: #999; --muted: #777; --heading: #eee;
  --primary: #5b9aff; --primary-h: #4f8ff7;
  --hover: #1a3050; --selected: #1a4a7a; --input-bg: #1a1a2e;
  --shadow: rgba(0,0,0,.5); --overlay: rgba(0,0,0,.7);
  --drop-zone: #1a4a7a;
  --alert-s-bg: #0a3a0a; --alert-s-b: #2d8a2d; --alert-s-t: #6ee7b7;
  --alert-e-bg: #3a0a0a; --alert-e-b: #a00; --alert-e-t: #fca5a5;
  --danger: #ff6b6b; --danger-h: #e5534b; --danger-bg: #3a0a0a;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif; background: var(--bg); color: var(--text); padding: 24px; font-size: 14px; transition: background .3s,color .3s; }
.container { max-width: 1100px; margin: 0 auto; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 0 4px; gap: 12px; }
.header h1 { font-size: 22px; font-weight: 600; color: var(--heading); }
.header-actions { display: flex; align-items: center; gap: 8px; }
.theme-toggle { background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; cursor: pointer; font-size: 16px; line-height: 1; transition: all .15s; }
.theme-toggle:hover { background: var(--hover); }
.btn { display: inline-flex; align-items: center; gap: 4px; padding: 7px 14px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 13px; transition: all .15s; margin: 2px; font-weight: 500; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-h); }
.btn-secondary { background: var(--hover); color: var(--text2); }
.btn-secondary:hover { background: var(--border); }
.btn-outline { background: var(--card); border: 1px solid var(--border2); color: var(--text2); }
.btn-outline:hover { background: var(--hover); border-color: var(--text3); }
.btn-danger { background: var(--danger); color: #fff; }
.btn-danger:hover { background: var(--danger-h); }
.btn-sm { padding: 4px 8px; font-size: 12px; }
.breadcrumb { background: var(--card); padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid var(--border); font-size: 14px; }
.breadcrumb a { color: var(--primary); text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb span { color: var(--muted); margin: 0 6px; }
.toolbar { background: var(--card); padding: 10px 12px; border-radius: 8px; margin-bottom: 16px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center; border: 1px solid var(--border); }
.drop-hint { color: var(--muted); font-size: 12px; margin-left: auto; }
.table-container { background: var(--card); border-radius: 8px; overflow-x: auto; min-height: 300px; transition: .2s; border: 1px solid var(--border); }
.table-container.drag-over { background: var(--drop-zone); box-shadow: inset 0 0 20px rgba(79,143,247,.15); }
.table-container.drag-over-folder { box-shadow: inset 0 0 30px rgba(79,143,247,.3); }
table { width: 100%; border-collapse: collapse; min-width: 600px; }
th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border); }
th { background: var(--hover); font-weight: 600; white-space: nowrap; color: var(--text3); font-size: 13px; }
tr:hover { background: var(--hover); }
tr.selected { background: var(--selected) !important; }
tr.dragging { opacity: .5; }
tr.drop-target { background: var(--selected) !important; }
tr { cursor: pointer; transition: background .1s; }
.dropdown-item { padding: 9px 16px; cursor: pointer; font-size: 13px; color: var(--text2); }
.dropdown-item:hover { background: var(--hover); }
.folder-drop-zone { padding: 10px 14px; display: block; color: var(--primary); }
.folder-drop-zone.drag-over { background: var(--drop-zone); border-radius: 4px; }
a { color: var(--primary); text-decoration: none; }
a:hover { color: var(--primary-h); }
.folder-link { display: block; padding: 2px; cursor: default; }
.actions { white-space: nowrap; }
.actions .btn { padding: 3px 6px; font-size: 11px; margin: 1px; }
.checkbox { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
.footer { text-align: center; color: var(--muted); margin-top: 16px; font-size: 12px; }
.alert { padding: 10px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
.alert-success { background: var(--alert-s-bg); border: 1px solid var(--alert-s-b); color: var(--alert-s-t); }
.alert-error { background: var(--alert-e-bg); border: 1px solid var(--alert-e-b); color: var(--alert-e-t); }
.alert-info { background: var(--selected); border: 1px solid var(--primary); color: var(--primary); }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--overlay); z-index: 1000; }
.modal.show { display: flex; align-items: center; justify-content: center; }
.modal-content { background: var(--card); padding: 24px; border-radius: 12px; max-width: 420px; width: 90%; box-shadow: 0 8px 30px var(--shadow); }
.modal-header { margin-bottom: 16px; font-size: 17px; font-weight: 600; color: var(--heading); }
.modal-body { margin-bottom: 20px; color: var(--text2); }
.modal-footer { display: flex; gap: 10px; justify-content: flex-end; }
.form-group { margin-bottom: 16px; }
.form-control { width: 100%; padding: 9px 12px; border: 1px solid var(--border2); border-radius: 6px; background: var(--input-bg); color: var(--text); font-size: 14px; transition: border .15s; }
.form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,143,247,.1); }
.login-box { max-width: 360px; margin: 100px auto; background: var(--card); padding: 36px; border-radius: 12px; box-shadow: 0 2px 20px var(--shadow); }
.login-box h2 { text-align: center; margin-bottom: 24px; color: var(--heading); }
.upload-progress { position: fixed; bottom: 20px; right: 20px; background: var(--card); padding: 16px 20px; border-radius: 10px; min-width: 200px; box-shadow: 0 4px 16px var(--shadow); border: 1px solid var(--border); }
.progress-bar { height: 4px; background: var(--border); border-radius: 2px; margin-top: 8px; overflow: hidden; }
.progress-bar-fill { height: 100%; background: var(--primary); transition: width .3s; }
.context-menu { position: fixed; background: var(--card); border-radius: 8px; box-shadow: 0 4px 24px var(--shadow); min-width: 160px; z-index: 2000; padding: 6px 0; display: none; border: 1px solid var(--border); }
.context-menu.show { display: block; }
.context-menu-item { padding: 9px 16px; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text2); }
.context-menu-item:hover { background: var(--hover); }
.context-menu-item:first-child { border-radius: 8px 8px 0 0; }
.context-menu-item:last-child { border-radius: 0 0 8px 8px; }
.context-menu-divider { height: 1px; background: var(--border); margin: 4px 0; }
.context-menu-item.danger { color: var(--danger); }
.context-menu-item.danger:hover { background: var(--danger-bg); }
.context-menu-item.has-submenu { position: relative; }
.context-menu-item.has-submenu::after { content: '\u203a'; margin-left: auto; color: var(--muted); }
.context-submenu { position: absolute; left: 100%; top: 0; background: var(--card); border-radius: 8px; box-shadow: 0 4px 20px var(--shadow); min-width: 140px; padding: 6px 0; display: none; border: 1px solid var(--border); }
.context-menu-item:hover > .context-submenu { display: block; }
CSS;

$js = <<<'JS'
const token=document.querySelector('input[name="token"]')?.value||'';
let draggedItem=null,uploadCount=0,contextTarget=null;

// 选择
let lastSelected=null;
function toggleSelectAll(){document.querySelectorAll('.file-check').forEach(c=>c.checked=document.getElementById('selectAll').checked);updateSelection()}
function selectRow(row,e){
  if(e.shiftKey&&lastSelected){
    const rows=Array.from(document.querySelectorAll('#dropZone tr[data-context]'));
    const idx1=rows.indexOf(lastSelected),idx2=rows.indexOf(row);
    const start=Math.min(idx1,idx2),end=Math.max(idx1,idx2);
    for(let i=start;i<=end;i++){rows[i].querySelector('.file-check').checked=true;rows[i].classList.add('selected')}
  }else{
    const cb=row.querySelector('.file-check');
    if(e.ctrlKey||e.metaKey){cb.checked=!cb.checked}
    else{document.querySelectorAll('.file-check').forEach(c=>c.checked=false);cb.checked=true}
    lastSelected=row;
  }
  updateSelection();
}
function updateSelection(){
  document.querySelectorAll('#dropZone tr[data-context]').forEach(row=>{
    row.classList.toggle('selected',row.querySelector('.file-check').checked);
  });
  const count=document.querySelectorAll('.file-check:checked').length;
  document.getElementById('selectAll').checked=count>0&&count===document.querySelectorAll('.file-check').length;
  // 解压按钮：选中1个且是压缩包时显示
  const btnUnpack=document.getElementById('btnUnpack');
  if(count===1){
    const row=document.querySelector('.file-check:checked').closest('tr');
    btnUnpack.style.display=row&&row.dataset.archive==='1'?'inline-flex':'none';
  }else btnUnpack.style.display='none';
}
function getSelected(){var c=document.querySelectorAll('.file-check:checked');if(!c.length){alert('请先选择文件');return!1}return!0}
function getSelectedNames(){return Array.from(document.querySelectorAll('.file-check:checked')).map(c=>c.value)}

// 工具栏操作
function shareSelected(){const names=getSelectedNames();if(!names.length){alert('请先选择文件');return}alert('分享链接: '+location.origin+location.pathname+'?share='+encodeURIComponent(names[0]))}
function downloadSelected(){const names=getSelectedNames();if(!names.length){alert('请先选择文件');return}if(names.length===1){location.href='?p='+encodeURIComponent(new URLSearchParams(location.search).get('p')||'')+'&dl='+encodeURIComponent(names[0]);return}const fd=new FormData();fd.append('ajax_batch_download','1');fd.append('token',token);names.forEach(n=>fd.append('names[]',n));fetch(location.href,{method:'POST',body:fd}).then(r=>{if(!r.ok)throw new Error('下载失败');return r.blob()}).then(blob=>{const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='download_'+new Date().toISOString().replace(/[:.]/g,'').slice(0,15)+'.zip';a.click();URL.revokeObjectURL(url)}).catch(e=>alert('下载失败: '+e.message))}
function deleteSelected(){const names=getSelectedNames();if(!names.length){alert('请先选择文件');return}if(names.length===1)openDelete(names[0]);else if(confirm('确定删除选中的 '+names.length+' 项?')){const f=document.createElement('form');f.method='post';f.innerHTML='<input name="token" value="'+token+'">';names.forEach(n=>f.innerHTML+='<input name="delete[]" value="'+n+'">');document.body.appendChild(f);f.submit()}}
function renameSelected(){const names=getSelectedNames();if(!names.length){alert('请先选择文件');return}if(names.length===1)openRename(names[0],names[0]);else alert('只能重命名单个文件')}
function copySelected(){const names=getSelectedNames();if(!names.length){alert('请先选择文件');return}if(names.length===1){sessionStorage.setItem('clipboard',JSON.stringify({mode:'copy',name:names[0],from:location.search}));updatePasteState()}else alert('只能复制单个文件/文件夹')}
function cutSelected(){const names=getSelectedNames();if(!names.length){alert('请先选择文件');return}if(names.length===1){sessionStorage.setItem('clipboard',JSON.stringify({mode:'cut',name:names[0],from:location.search}));updatePasteState()}else alert('只能剪切单个文件/文件夹')}
function copyToClipboard(name){sessionStorage.setItem('clipboard',JSON.stringify({mode:'copy',name:name,from:location.search}));updatePasteState()}
function getClipboard(){try{const d=sessionStorage.getItem('clipboard');return d?JSON.parse(d):null}catch(e){return null}}
function hasClipboard(){return !!getClipboard()}
function updatePasteState(){const has=!!getClipboard();document.querySelectorAll('.paste-item').forEach(el=>el.style.display=has?'flex':'none')}
async function pasteToFolder(folder){
  const cb=getClipboard();if(!cb)return;
  const fd=new FormData();
  fd.append('ajax_paste','1');
  fd.append('mode',cb.mode);
  fd.append('src',cb.name);
  fd.append('from',cb.from);
  fd.append('dst_folder',folder||'');
  fd.append('token',token);
  try{
    const res=await fetch(location.href,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){sessionStorage.removeItem('clipboard');updatePasteState();location.reload()}
    else alert('操作失败: '+data.error);
  }catch(e){alert('操作失败')}
}
function openFile(name){location.href='?p='+encodeURIComponent(new URLSearchParams(location.search).get('p')||'')+'&dl='+encodeURIComponent(name)}
function toggleDropdown(btn){const menu=btn.nextElementSibling;menu.style.display=menu.style.display==='none'?'block':'none';document.addEventListener('click',function close(e){if(!btn.contains(e.target)&&!menu.contains(e.target)){menu.style.display='none';document.removeEventListener('click',close)}})}
function packSelected(type){const names=getSelectedNames();if(!names.length){alert('请先选择文件');return}const f=document.createElement('form');f.method='post';f.innerHTML='<input name="token" value="'+token+'"><input name="pack_'+type+'" value="1">';names.forEach(n=>f.innerHTML+='<input name="files[]" value="'+n+'">');document.body.appendChild(f);f.submit()}

// 模态框
function showModal(id){document.getElementById(id).classList.add('show')}
function hideModal(id){document.getElementById(id).classList.remove('show')}
function openUpload(){showModal('uploadModal')}
function openNewFolder(){showModal('folderModal')}
function openNewFile(){showModal('fileModal')}
function openRename(name,to){document.getElementById('rename_from').value=name;document.getElementById('rename_to').value=to||name;showModal('renameModal')}
function openCopy(name){document.getElementById('copy_src').value=name;document.getElementById('copy_dst').value=name;showModal('copyModal')}
function openMove(name){document.getElementById('move_src').value=name;document.getElementById('move_dst').value=name;showModal('moveModal')}
function openDelete(name){document.getElementById('delete_item').value=name;document.getElementById('delete_name').textContent=name;showModal('deleteModal')}
function openUnpack(name){document.getElementById('unpack_file').value=name;document.getElementById('unpack_name').textContent=name;showModal('unpackModal')}

// ESC关闭模态框
function hideContextMenu(){document.getElementById('contextMenu')?.classList.remove('show')}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.modal.show').forEach(m=>m.classList.remove('show'));hideContextMenu()}});
document.addEventListener('click',hideContextMenu);

// ========== 拖拽上传 ==========
const dropZone=document.getElementById('dropZone');

function showProgress(text){
  let el=document.getElementById('uploadProgress');
  if(!el){
    el=document.createElement('div');el.id='uploadProgress';el.className='upload-progress';
    el.innerHTML='<div class="progress-text"></div><div class="progress-bar"><div class="progress-bar-fill" style="width:0%"></div></div>';
    document.body.appendChild(el);
  }
  el.querySelector('.progress-text').textContent=text;
  return el;
}

function hideProgress(){document.getElementById('uploadProgress')?.remove()}

// 递归读取文件夹
function readDirectoryEntries(reader){
  return new Promise(resolve=>{
    const all=[];
    function readBatch(){
      reader.readEntries(entries=>{
        if(!entries.length){resolve(all);return}
        all.push(...entries);readBatch();
      });
    }
    readBatch();
  });
}

// 收集所有文件（含子目录）
async function collectFiles(entry,path=''){
  const results=[];
  if(entry.isFile){
    await new Promise(resolve=>entry.file(file=>{results.push({file,relativePath:path+file.name});resolve()}));
  }else if(entry.isDirectory){
    const reader=entry.createReader();
    const entries=await readDirectoryEntries(reader);
    for(const e of entries){
      const subResults=await collectFiles(e,path+entry.name+'/');
      results.push(...subResults);
    }
  }
  return results;
}

async function uploadFileList(fileList,targetFolder){
  const pParam=new URLSearchParams(location.search).get('p')||'';
  uploadCount=0;
  const total=fileList.length;
  
  for(const item of fileList){
    const fd=new FormData();
    fd.append('file',item.file);
    fd.append('relative_path',targetFolder?targetFolder+'/'+item.relativePath:item.relativePath);
    fd.append('token',token);
    
    const progress=showProgress(`上传中 ${uploadCount+1}/${total}: ${item.file.name}`);
    progress.querySelector('.progress-bar-fill').style.width=Math.round((uploadCount+1)/total*100)+'%';
    
    try{
      const res=await fetch('?ajax_upload=1&p='+encodeURIComponent(pParam),{method:'POST',body:fd});
      const data=await res.json();
      if(!data.success)console.error('Upload failed:',data.error);
    }catch(e){console.error(e)}
    uploadCount++;
  }
  
  hideProgress();
  location.reload();
}

// 处理拖放
async function handleDrop(e,targetFolder){
  const items=e.dataTransfer.items;
  if(!items||!items.length)return;
  
  const allFiles=[];
  
  // 优先使用 webGetAsEntry
  const entries=[];
  for(let i=0;i<items.length;i++){
    const entry=items[i].webkitGetAsEntry?.()||items[i].getAsEntry?.();
    if(entry)entries.push(entry);
  }
  
  if(entries.length){
    for(const entry of entries){
      const files=await collectFiles(entry);
      allFiles.push(...files);
    }
  }else{
    // fallback: 直接用 dataTransfer.files
    for(let i=0;i<e.dataTransfer.files.length;i++){
      const f=e.dataTransfer.files[i];
      allFiles.push({file:f,relativePath:f.name});
    }
  }
  
  if(!allFiles.length){alert('未检测到文件');return}
  await uploadFileList(allFiles,targetFolder);
}

// 拖拽到空白区域上传
let currentDropTarget=null;
dropZone?.addEventListener('dragover',e=>{
  e.preventDefault();e.stopPropagation();
  if(!currentDropTarget)dropZone.classList.add('drag-over');
});
dropZone?.addEventListener('dragleave',e=>{
  dropZone.classList.remove('drag-over','drag-over-folder');
  currentDropTarget=null;
});
dropZone?.addEventListener('drop',e=>{
  e.preventDefault();e.stopPropagation();
  dropZone.classList.remove('drag-over','drag-over-folder');
  currentDropTarget=null;
  handleDrop(e,'');
});

// ========== 拖拽移动 + 拖拽上传到文件夹 ==========
document.querySelectorAll('[draggable="true"]').forEach(el=>{
  el.addEventListener('dragstart',e=>{
    draggedItem=el.dataset.name;
    el.classList.add('dragging');
    e.dataTransfer.effectAllowed='move';
  });
  el.addEventListener('dragend',()=>{el.classList.remove('dragging');draggedItem=null});
});

// 文件夹作为上传/移动目标
document.querySelectorAll('.folder-drop-zone').forEach(el=>{
  el.addEventListener('dragover',e=>{
    e.preventDefault();
    e.stopPropagation();
    currentDropTarget=el.dataset.folder;
    el.classList.add('drag-over');
    dropZone.classList.remove('drag-over');
    dropZone.classList.add('drag-over-folder');
  });
  el.addEventListener('dragleave',e=>{
    el.classList.remove('drag-over');
    dropZone.classList.remove('drag-over-folder');
    currentDropTarget=null;
  });
  el.addEventListener('drop',async e=>{
    e.preventDefault();
    e.stopPropagation();
    el.classList.remove('drag-over');
    dropZone.classList.remove('drag-over-folder');
    
    const folder=el.dataset.folder;
    
    // 如果是拖拽移动（有draggedItem）
    if(draggedItem){
      const fd=new FormData();
      fd.append('ajax_move','1');
      fd.append('src',draggedItem);
      fd.append('dst',folder);
      fd.append('token',token);
      
      try{
        const res=await fetch(location.href,{method:'POST',body:fd});
        const data=await res.json();
        if(data.success)location.reload();
        else alert('移动失败: '+data.error);
      }catch(e){alert('移动失败')}
    }
    // 如果是拖拽上传
    else{
      handleDrop(e,folder);
    }
    currentDropTarget=null;
  });
});

// ========== 右键菜单 ==========
function showContextMenu(e,name,isFolder,isArchive){
  e.preventDefault();
  contextTarget=name;
  const menu=document.getElementById('contextMenu');
  
  // 设置菜单位置
  menu.style.left=e.pageX+'px';
  menu.style.top=e.pageY+'px';
  
  // 显示/隐藏特定菜单项
  document.getElementById('ctxDownload').style.display=isFolder?'none':'flex';
  document.getElementById('ctxUnpack').style.display=isArchive?'flex':'none';
  // 文件夹时显示“粘贴到此文件夹”
  document.querySelectorAll('.paste-folder-item').forEach(el=>el.style.display=(isFolder&&getClipboard())?'flex':'none');
  // 普通粘贴项在文件右键时也隐藏（只在空白区用）
  document.querySelectorAll('#contextMenu .paste-item').forEach(el=>el.style.display='none');
  
  menu.classList.add('show');
  
  // 防止菜单超出屏幕
  const rect=menu.getBoundingClientRect();
  if(rect.right>window.innerWidth)menu.style.left=(e.pageX-rect.width)+'px';
  if(rect.bottom>window.innerHeight)menu.style.top=(e.pageY-rect.height)+'px';
}

function showBlankContextMenu(e){
  e.preventDefault();
  contextTarget=null;
  const menu=document.getElementById('blankContextMenu');
  menu.style.left=e.pageX+'px';
  menu.style.top=e.pageY+'px';
  menu.classList.add('show');
  
  const rect=menu.getBoundingClientRect();
  if(rect.right>window.innerWidth)menu.style.left=(e.pageX-rect.width)+'px';
  if(rect.bottom>window.innerHeight)menu.style.top=(e.pageY-rect.height)+'px';
}

// 右键点击文件行
document.querySelectorAll('[data-context]').forEach(el=>{
  el.addEventListener('contextmenu',e=>showContextMenu(e,el.dataset.name,el.dataset.type==='folder',el.dataset.archive==='1'));
});

// 右键点击空白区域
document.getElementById('dropZone')?.addEventListener('contextmenu',e=>{
  if(e.target.closest('tr[data-context]'))return;
  e.preventDefault();
  showBlankContextMenu(e);
});

// 批量打包
function packSelected(type){
  const checked=document.querySelectorAll('.file-check:checked');
  if(!checked.length){alert('请先选择文件');return}
  const f=document.createElement('form');
  f.method='post';
  f.innerHTML='<input name="token" value="'+token+'"><input name="pack_'+type+'" value="1">';
  checked.forEach(c=>f.innerHTML+='<input name="files[]" value="'+c.value+'">');
  document.body.appendChild(f);
  f.submit();
}
document.getElementById('ctxPackZip')?.addEventListener('click',()=>packSelected('zip'));
document.getElementById('ctxPackTar')?.addEventListener('click',()=>packSelected('tar'));
JS;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=APP_TITLE?></title>
<style><?=$css?></style>
</head>
<body>
<?php if (!$is_logged): ?>
<div class="login-box">
    <h2>📁 <?=APP_TITLE?></h2>
    <?php if (isset($login_error)): ?><div class="alert alert-error"><?=$login_error?></div><?php endif; ?>
    <form method="post">
        <div class="form-group"><input type="text" name="user" class="form-control" placeholder="<?=lng('Username')?>" required autofocus></div>
        <div class="form-group"><input type="password" name="pass" class="form-control" placeholder="<?=lng('Password')?>" required></div>
        <button type="submit" name="login" class="btn btn-primary" style="width:100%"><?=lng('Login')?></button>
    </form>
</div>
<?php else: ?>
<div class="container">
    <div class="header">
        <h1>📁 <?=APP_TITLE?></h1>
        <div class="header-actions">
            <button type="button" class="theme-toggle" id="themeBtn" onclick="toggleTheme()">🌙</button>
            <a href="?logout" class="btn btn-outline"><?=lng('Logout')?></a>
        </div>
    </div>

    <div class="breadcrumb">
        <a href="?p=">🏠</a>
        <?php $path_acc=''; foreach(array_filter(explode('/',$p)) as $part): $path_acc.=($path_acc?'/':'').$part; ?>
        <span>›</span><a href="?p=<?=urlencode($path_acc)?>"><?=htmlspecialchars($part)?></a>
        <?php endforeach; ?>
    </div>

    <?php if (isset($msg)): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>

    <form method="post" id="mainForm">
    <input type="hidden" name="token" value="<?=$_SESSION['token']?>">

    <div class="toolbar">
        <button type="button" class="btn btn-primary" onclick="openNewFolder()">📁 新建文件夹</button>
        <button type="button" class="btn btn-outline" onclick="openUpload()">📤 上传</button>
        <button type="button" class="btn btn-outline" onclick="shareSelected()">🔗 分享</button>
        <button type="button" class="btn btn-outline" onclick="downloadSelected()">⬇️ 下载</button>
        <button type="button" class="btn btn-outline" onclick="deleteSelected()">🗑️ 删除</button>
        <button type="button" class="btn btn-outline" onclick="renameSelected()">✏️ 重命名</button>
        <button type="button" class="btn btn-outline" onclick="copySelected()">📋 复制</button>
        <button type="button" class="btn btn-outline" onclick="cutSelected()">✂️ 剪切</button>
        <button type="button" class="btn btn-outline" id="btnUnpack" style="display:none" onclick="unpackSelected()">📦 解压</button>
        <button type="button" class="btn btn-outline paste-item" style="display:none" onclick="pasteToFolder('')">📋 粘贴</button>
        <div class="dropdown" style="display:inline-block;position:relative">
            <button type="button" class="btn btn-outline" onclick="toggleDropdown(this)">⋯ 更多 ▼</button>
            <div class="dropdown-menu" style="display:none;position:absolute;top:100%;left:0;background:var(--card);border-radius:8px;box-shadow:0 4px 16px var(--shadow);min-width:140px;padding:6px 0;z-index:100;border:1px solid var(--border)">
                <div class="dropdown-item" onclick="packSelected('zip')">📦 ZIP 打包</div>
                <div class="dropdown-item" onclick="packSelected('tar')">📦 TAR 打包</div>
                <div class="dropdown-item" onclick="unpackSelected()">📦 解压</div>
                <div class="dropdown-item" onclick="openNewFile()">📄 新建文件</div>
            </div>
        </div>
        <span class="drop-hint">💡 拖拽上传 | 拖拽到文件夹移动</span>
    </div>

    <div class="table-container" id="dropZone">
        <table>
            <tr><th style="width:30px"><input type="checkbox" id="selectAll" class="checkbox" onchange="toggleSelectAll()"></th>
                <th>名称</th><th style="width:80px">类型</th><th style="width:100px">大小</th><th style="width:150px">修改时间</th></tr>
            <?php if ($parent!==false): ?>
            <tr><td></td><td><a href="?p=<?=urlencode($parent)?>">📁 ..</a></td><td>-</td><td>-</td><td></td></tr>
            <?php endif; ?>
            <?php foreach($folders as $folder): ?>
            <tr draggable="true" data-name="<?=htmlspecialchars($folder)?>" data-context="1" data-type="folder" onclick="selectRow(this,event)" ondblclick="location.href='?p=<?=urlencode(trim($p.'/'.$folder,'/'))?>'">
                <td><input type="checkbox" name="files[]" value="<?=htmlspecialchars($folder)?>" class="checkbox file-check" onclick="event.stopPropagation()"></td>
                <td><a href="?p=<?=urlencode(trim($p.'/'.$folder,'/'))?>" class="folder-drop-zone" data-folder="<?=htmlspecialchars($folder)?>" onclick="event.stopPropagation()">📁 <?=htmlspecialchars($folder)?></a></td>
                <td>文件夹</td><td>-</td><td><?=date('Y-m-d H:i',filemtime($current_path.'/'.$folder))?></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach($files as $file): $fp=$current_path.'/'.$file; $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION)); $is_archive=in_array($ext,['zip','tar','gz','tgz']); ?>
            <tr draggable="true" data-name="<?=htmlspecialchars($file)?>" data-context="1" data-type="file" data-archive="<?=$is_archive?'1':'0'?>" onclick="selectRow(this,event)" ondblclick="openFile('<?=htmlspecialchars($file,ENT_QUOTES)?>')">
                <td><input type="checkbox" name="files[]" value="<?=htmlspecialchars($file)?>" class="checkbox file-check" onclick="event.stopPropagation()"></td>
                <td><span><?=$is_archive?'📦':'📄'?></span> <?=htmlspecialchars($file)?></td>
                <td><?=strtoupper($ext?:'file')?></td><td><?=format_size(filesize($fp))?></td><td><?=date('Y-m-d H:i',filemtime($fp))?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php if(empty($folders)&&empty($files)&&$parent===false): ?><div style="text-align:center;padding:60px;color:#aaa">空文件夹 - 拖拽文件上传</div><?php endif; ?>
    </div>
    </form>

    <div class="footer"><?=count($folders)?> 个文件夹 · <?=count($files)?> 个文件</div>
</div>

<!-- 上传模态框 -->
<div class="modal" id="uploadModal"><div class="modal-content">
    <div class="modal-header">📤 <?=lng('Upload')?></div>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <div class="modal-body">
            <input type="file" name="file" class="form-control" required>
            <div style="margin-top:10px;color:#999;font-size:12px">支持拖拽上传到文件区域</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideModal('uploadModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-primary"><?=lng('Upload')?></button></div>
    </form>
</div></div>

<!-- 新建文件夹模态框 -->
<div class="modal" id="folderModal"><div class="modal-content">
    <div class="modal-header">📁 <?=lng('New Folder')?></div>
    <form method="post">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <div class="modal-body"><input type="text" name="new_folder" class="form-control" placeholder="文件夹名称" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideModal('folderModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-primary"><?=lng('Save')?></button></div>
    </form>
</div></div>

<!-- 新建文件模态框 -->
<div class="modal" id="fileModal"><div class="modal-content">
    <div class="modal-header">📄 <?=lng('New File')?></div>
    <form method="post">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <div class="modal-body"><input type="text" name="new_file" class="form-control" placeholder="文件名称" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideModal('fileModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-primary"><?=lng('Save')?></button></div>
    </form>
</div></div>

<!-- 重命名模态框 -->
<div class="modal" id="renameModal"><div class="modal-content">
    <div class="modal-header">✏️ <?=lng('Rename')?></div>
    <form method="post">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <input type="hidden" name="rename_from" id="rename_from">
        <div class="modal-body"><input type="text" name="rename_to" id="rename_to" class="form-control" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideModal('renameModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-primary"><?=lng('Save')?></button></div>
    </form>
</div></div>

<!-- 复制模态框 -->
<div class="modal" id="copyModal"><div class="modal-content">
    <div class="modal-header">📋 <?=lng('Copy')?></div>
    <form method="post">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <input type="hidden" name="copy_src" id="copy_src">
        <div class="modal-body"><label><?=lng('New name')?>:</label><input type="text" name="copy_dst" id="copy_dst" class="form-control" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideModal('copyModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-primary"><?=lng('Copy')?></button></div>
    </form>
</div></div>

<!-- 移动模态框 -->
<div class="modal" id="moveModal"><div class="modal-content">
    <div class="modal-header">📂 <?=lng('Move')?></div>
    <form method="post">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <input type="hidden" name="move_src" id="move_src">
        <div class="modal-body"><label><?=lng('New name')?>:</label><input type="text" name="move_dst" id="move_dst" class="form-control" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideModal('moveModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-primary"><?=lng('Move')?></button></div>
    </form>
</div></div>

<!-- 删除模态框 -->
<div class="modal" id="deleteModal"><div class="modal-content">
    <div class="modal-header">🗑️ <?=lng('Delete')?></div>
    <form method="post">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <input type="hidden" name="delete" id="delete_item">
        <div class="modal-body" style="color:var(--danger)">确认删除: <strong id="delete_name"></strong>？</div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideModal('deleteModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-danger"><?=lng('Delete')?></button></div>
    </form>
</div></div>

<!-- 解压模态框 -->
<div class="modal" id="unpackModal"><div class="modal-content">
    <div class="modal-header">📂 <?=lng('Unpack')?></div>
    <form method="post">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <input type="hidden" name="unpack" value="1">
        <input type="hidden" name="archive" id="unpack_file">
        <div class="modal-body">解压: <strong id="unpack_name"></strong></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideModal('unpackModal')"><?=lng('Cancel')?></button><button type="submit" class="btn btn-primary"><?=lng('Unpack')?></button></div>
    </form>
</div></div>
<?php endif; ?>

<script><?=$js?></script>
<script>
updatePasteState();
// 主题切换
function toggleTheme(){document.body.classList.toggle('dark');const d=document.body.classList.contains('dark');localStorage.setItem('fm-theme',d?'dark':'light');document.getElementById('themeBtn').textContent=d?'☀️':'🌙'}
if(localStorage.getItem('fm-theme')==='dark'){document.body.classList.add('dark');document.getElementById('themeBtn').textContent='☀️'}
</script>

<!-- 右键菜单 - 文件/文件夹 -->
<div class="context-menu" id="contextMenu">
  <div class="context-menu-item" id="ctxDownload" onclick="if(contextTarget)location.href='?p=<?=urlencode($p)?>&dl='+encodeURIComponent(contextTarget)">⬇️ 下载</div>
  <div class="context-menu-item" onclick="if(contextTarget)openRename(contextTarget,contextTarget)">✏️ 重命名</div>
  <div class="context-menu-item" onclick="if(contextTarget)copyToClipboard(contextTarget)">📋 复制</div>
  <div class="context-menu-item" onclick="if(contextTarget){sessionStorage.setItem('clipboard',JSON.stringify({mode:'cut',name:contextTarget,from:location.search}));updatePasteState()}">✂️ 剪切</div>
  <div class="context-menu-item paste-item" style="display:none" onclick="pasteToFolder('')">📋 粘贴</div>
  <div class="context-menu-item paste-folder-item" style="display:none" onclick="if(contextTarget)pasteToFolder(contextTarget)">📋 粘贴到此文件夹</div>
  <div class="context-menu-divider"></div>
  <div class="context-menu-item" id="ctxUnpack" style="display:none" onclick="if(contextTarget)openUnpack(contextTarget)">📦 解压</div>
  <div class="context-menu-item danger" onclick="if(contextTarget)openDelete(contextTarget)">🗑️ 删除</div>
</div>

<!-- 右键菜单 - 空白区域 -->
<div class="context-menu" id="blankContextMenu">
  <div class="context-menu-item" onclick="openUpload()">📤 上传</div>
  <div class="context-menu-item paste-item" style="display:none" onclick="pasteToFolder('')">📋 粘贴</div>
  <div class="context-menu-item" onclick="openNewFolder()">📁 新建文件夹</div>
  <div class="context-menu-item" onclick="openNewFile()">📄 新建文件</div>
  <div class="context-menu-divider"></div>
  <div class="context-menu-item" id="ctxPackZip">📦 ZIP 打包</div>
  <div class="context-menu-item" id="ctxPackTar">📦 TAR 打包</div>
</div>
</body>
</html>

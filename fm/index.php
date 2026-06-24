<?php

// ============================================================================
// 加载用户配置文件(如果存在)
// ============================================================================
// 如果同目录下存在 config.php,则加载它覆盖默认配置
// 这样可以在不修改 index.php 的情况下自定义配置
if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
}

// ============================================================================
// 默认配置(如果 config.php 未定义相应变量,则使用这些默认值)
// ============================================================================

// 应用标题
if (!defined('APP_TITLE')) define('APP_TITLE', '文件管理器');

// 分享链接基础URL(留空则自动检测,格式如 https://github.com/)
// 自动检测时,根据 $root_path 与 DOCUMENT_ROOT 的路径差自动推算 Web URL 前缀
// 仅在反向代理等特殊场景(文件无法通过 Web 直接访问)需要手动指定
if (!isset($base_url)) $base_url = '';

// 认证凭据(修改这里的用户名和密码)
// 生成 bcrypt 哈希: password_hash('your_password', PASSWORD_BCRYPT)
if (!isset($auth_user)) $auth_user = 'admin';
if (!isset($auth_pass)) $auth_pass = '$2y$10$m7JT3rdrZ3nK4tKxpJ8z5.pDalU75BZH9nu82zBJkVNhLJgoC6uXm';

// 文件操作根目录(限制文件管理器只能在此目录下操作)
if (!isset($root_path)) $root_path = $_SERVER['DOCUMENT_ROOT'];

// 分享链接基础路径(自动计算,无需手动配置)
// 原理: $root_path 相对于 DOCUMENT_ROOT 的路径差 = 文件在 Web 上的 URL 前缀
// 例: $root_path=/var/www/html/fm, DOC_ROOT=/var/www/html → 前缀=/fm
// 例: $root_path=/var/www/html, DOC_ROOT=/var/www/html → 前缀=(空)
// ⚠️ 此段必须放在 $root_path 定义之后
$_doc_root = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
$_root_real = rtrim(str_replace('\\', '/', realpath($root_path)), '/');
$_web_prefix = ($_root_real && $_doc_root && strpos($_root_real, $_doc_root) === 0) ? substr($_root_real, strlen($_doc_root)) : '';
$share_base = $base_url !== '' ? rtrim($base_url, '/') : ('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_web_prefix);

// 最大上传文件大小(字节),默认 5GB
if (!isset($max_upload_size)) $max_upload_size = 5000000000;

// 登录安全设置
if (!isset($login_max_attempts)) $login_max_attempts = 5;  // 最大失败尝试次数
if (!isset($login_lockout_time)) $login_lockout_time = 900; // 锁定时间(秒),默认 15 分钟

// 禁止上传的文件扩展名
if (!isset($forbidden_extensions)) $forbidden_extensions = [
    'php', 'php3', 'php4', 'php5', 'phtml', 'phar',
    'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py',
    'sh', 'bash', 'exe', 'bat', 'cmd', 'com',
    'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh',
    'htaccess', 'ini', 'shtml', 'stm', 'shtm'
];

// ============================================================================
// 函数库
// ============================================================================

session_start();

// CSRF Token
if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
    $_SESSION['token_created'] = time();
}

date_default_timezone_set('Asia/Shanghai');
set_time_limit(600);

// ============================================================================
// 路径安全函数
// ============================================================================

function fm_clean($s) {
    return preg_replace('#/+#', '/', str_replace(['\\','..'], ['/',''], trim($s)));
}

function fm_is_safe_path($path, $root) {
    $real_path = realpath($path);
    $real_root = realpath($root);
    if ($real_path === false) {
        $parent = dirname($path);
        $real_path = realpath($parent);
        if ($real_path === false) return false;
    }
    if ($real_root === false) return false;
    $real_path = str_replace('\\', '/', $real_path);
    $real_root = str_replace('\\', '/', $real_root);
    return ($real_path === $real_root || strpos($real_path . '/', $real_root . '/') === 0);
}

function fm_is_safe_str_path($path, $root) {
    $path_norm = str_replace('\\', '/', $path);
    $root_norm = str_replace('\\', '/', rtrim($root, '/'));
    return ($path_norm === $root_norm || strpos($path_norm . '/', $root_norm . '/') === 0);
}

function fm_is_safe_zip_entry($entry_name, $target_dir, $root) {
    $entry = str_replace('\\', '/', $entry_name);
    $entry = ltrim($entry, '/');
    if ($entry === '' || $entry === '.') return false;

    $win_reserved_pattern = '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\\.|$)/i';
    $segments = explode('/', $entry);
    $clean_segments = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            if (count($clean_segments) > 0) array_pop($clean_segments);
        } else {
            if (preg_match($win_reserved_pattern, $seg)) return false;
            $clean_segments[] = $seg;
        }
    }
    $clean_name = implode('/', $clean_segments);
    $full_path = $target_dir . '/' . $clean_name;
    $full_norm = str_replace('\\', '/', $full_path);
    $root_norm = str_replace('\\', '/', $root);
    return ($full_norm === $root_norm || strpos($full_norm . '/', $root_norm . '/') === 0);
}

// ============================================================================
// 文件操作函数
// ============================================================================

function fm_check_upload_ext($filename) {
    global $forbidden_extensions;
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return !in_array($ext, $forbidden_extensions);
}

function fm_safe_unpack($archive_path, $target_dir) {
    global $root_path;
    $ext = strtolower(pathinfo($archive_path, PATHINFO_EXTENSION));
    if (preg_match('/\.tar\.(gz|bz2|xz)$/i', basename($archive_path), $m)) {
        $ext = 'tar.' . $m[1];
    }

    // ZIP
    if (($ext === 'zip' || $ext === 'zipx') && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($archive_path) !== TRUE) return ['ok' => false, 'error' => '无法打开 ZIP 文件'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (!fm_is_safe_zip_entry($zip->getNameIndex($i), $target_dir, $root_path)) {
                $zip->close();
                return ['ok' => false, 'error' => '安全警告:压缩包包含危险路径'];
            }
        }
        $ok = $zip->extractTo($target_dir);
        $zip->close();
        return ['ok' => $ok, 'error' => $ok ? '' : '解压失败'];
    }

    // TAR / TAR.GZ / TAR.BZ2 / TAR.XZ / GZ
    if (in_array($ext, ['tar', 'gz', 'tgz', 'tar.gz', 'tar.bz2', 'tbz2', 'tar.xz', 'txz']) && class_exists('PharData')) {
        try {
            $phar = new PharData($archive_path);
            $temp_dir = $target_dir . '/.unpack_temp_' . uniqid();
            @mkdir($temp_dir, 0755, true);
            try {
                $phar->extractTo($temp_dir);
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    $relative = substr($file->getPathname(), strlen($temp_dir) + 1);
                    $target = $target_dir . '/' . $relative;
                    if (!fm_is_safe_str_path($target, $root_path)) {
                        return ['ok' => false, 'error' => '安全警告:压缩包包含危险路径'];
                    }
                }
                $iterator->rewind();
                foreach ($iterator as $file) {
                    $relative = substr($file->getPathname(), strlen($temp_dir) + 1);
                    $target = $target_dir . '/' . $relative;
                    $file->isDir() ? @mkdir($target, 0755, true) : rename($file->getPathname(), $target);
                }
                return ['ok' => true, 'error' => ''];
            } finally { fm_rmdir($temp_dir); }
        } catch (Exception $e) {
            return ['ok' => false, 'error' => '解压失败:' . $e->getMessage()];
        }
    }

    // 7Z
    if ($ext === '7z') {
        $cmd = fm_find_executable('7z');
        if (!$cmd) return ['ok' => false, 'error' => '服务器未安装 7-Zip,无法解压 7Z 文件'];
        if (!fm_is_safe_path($target_dir, $root_path)) {
            return ['ok' => false, 'error' => '安全警告:解压路径异常'];
        }
        $escaped_target = escapeshellarg($target_dir);
        $escaped_archive = escapeshellarg($archive_path);
        @set_time_limit(300);
        $output = shell_exec("$cmd x -y -o$escaped_target $escaped_archive 2>&1");
        return ['ok' => true, 'error' => '', 'output' => trim($output ?: '')];
    }

    // RAR
    if (in_array($ext, ['rar'])) {
        $cmd = fm_find_executable(['unrar', 'rar']);
        if (!$cmd) return ['ok' => false, 'error' => '服务器未安装 unrar/rar,无法解压 RAR 文件'];
        if (!fm_is_safe_path($target_dir, $root_path)) {
            return ['ok' => false, 'error' => '安全警告:解压路径异常'];
        }
        $escaped_target = escapeshellarg($target_dir);
        $escaped_archive = escapeshellarg($archive_path);
        @set_time_limit(300);
        $output = shell_exec("$cmd x -y -o+ $escaped_archive $escaped_target 2>&1");
        return ['ok' => true, 'error' => '', 'output' => trim($output ?: '')];
    }

    return ['ok' => false, 'error' => '不支持的压缩格式'];
}

// ============================================================================
// 压缩函数
// ============================================================================

function fm_find_executable($names) {
    if (is_string($names)) $names = [$names];
    foreach ($names as $name) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = shell_exec('where ' . escapeshellarg($name) . ' 2>nul');
            if ($output) {
                $lines = explode("\n", trim($output));
                return $lines[0];
            }
        } else {
            $output = shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null');
            if ($output) return trim($output);
        }
    }
    return null;
}

function fm_pack($type, $files, $base_dir, &$output_path) {
    global $root_path;
    if (empty($files)) return ['ok' => false, 'error' => '未选择文件', 'name' => ''];

    $base_name = count($files) === 1 ? pathinfo($files[0], PATHINFO_FILENAME) : basename($base_dir);
    $base_name = preg_replace('/[^\w\x{4e00}-\x{9fa5}\-\.]/u', '_', $base_name);

    switch ($type) {
        case 'zip':
            $output_path = $base_dir . '/' . $base_name . '.zip';
            if (!class_exists('ZipArchive')) return ['ok' => false, 'error' => 'PHP 未安装 ZipArchive 扩展', 'name' => ''];
            $zip = new ZipArchive();
            if ($zip->open($output_path, ZipArchive::CREATE) !== TRUE) return ['ok' => false, 'error' => 'ZIP 创建失败', 'name' => ''];
            foreach ($files as $f) {
                $f = fm_clean($f);
                $full = $base_dir . '/' . $f;
                if (!file_exists($full)) continue;
                if (is_file($full)) {
                    $zip->addFile($full, $f);
                } elseif (is_dir($full)) {
                    $prefix = $f;
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iterator as $item) {
                        $relPath = $prefix . '/' . substr($item->getPathname(), strlen($full) + 1);
                        $relPath = str_replace('\\', '/', $relPath);
                        if ($item->isDir()) {
                            $zip->addEmptyDir($relPath);
                        } else {
                            $zip->addFile($item->getPathname(), $relPath);
                        }
                    }
                    $zip->addEmptyDir($prefix);
                }
            }
            $zip->close();
            return ['ok' => file_exists($output_path), 'error' => '', 'name' => basename($output_path)];

        case 'tar':
            $output_path = $base_dir . '/' . $base_name . '.tar';
            if (!class_exists('PharData')) return ['ok' => false, 'error' => 'PHP 未安装 PharData 扩展', 'name' => ''];
            try {
                $tar = new PharData($output_path);
                foreach ($files as $f) {
                    $f = fm_clean($f);
                    $full = $base_dir . '/' . $f;
                    if (!file_exists($full)) continue;
                    if (is_file($full)) {
                        $tar->addFile($full, $f);
                    } elseif (is_dir($full)) {
                        $prefix = $f;
                        $iter = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST
                        );
                        foreach ($iter as $item) {
                            $rel = $prefix . '/' . substr($item->getPathname(), strlen($full) + 1);
                            $rel = str_replace('\\', '/', $rel);
                            if ($item->isDir()) {
                                $tar->addEmptyDir($rel);
                            } else {
                                $tar->addFile($item->getPathname(), $rel);
                            }
                        }
                    }
                }
                unset($tar);
                return ['ok' => file_exists($output_path), 'error' => '', 'name' => basename($output_path)];
            } catch (Exception $e) {
                return ['ok' => false, 'error' => 'TAR 创建失败:' . $e->getMessage(), 'name' => ''];
            }

        case 'gzip':
            $has_dir = false;
            foreach ($files as $f) {
                if (is_dir($base_dir . '/' . fm_clean($f))) { $has_dir = true; break; }
            }
            if (count($files) > 1 || $has_dir) {
                $tar_path = $base_dir . '/' . $base_name . '.tar';
                $output_path = $base_dir . '/' . $base_name . '.tar.gz';
                if (!class_exists('PharData')) return ['ok' => false, 'error' => 'PHP 未安装 PharData 扩展', 'name' => ''];
                try {
                    $tar = new PharData($tar_path);
                    foreach ($files as $f) {
                        $f = fm_clean($f);
                        $full = $base_dir . '/' . $f;
                        if (!file_exists($full)) continue;
                        if (is_file($full)) {
                            $tar->addFile($full, $f);
                        } elseif (is_dir($full)) {
                            $prefix = $f;
                            $iter = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::SELF_FIRST
                            );
                            foreach ($iter as $item) {
                                $rel = $prefix . '/' . substr($item->getPathname(), strlen($full) + 1);
                                $rel = str_replace('\\', '/', $rel);
                                if ($item->isDir()) {
                                    $tar->addEmptyDir($rel);
                                } else {
                                    $tar->addFile($item->getPathname(), $rel);
                                }
                            }
                        }
                    }
                    unset($tar);
                    $gz_data = gzencode(file_get_contents($tar_path), 9);
                    if ($gz_data === false) return ['ok' => false, 'error' => 'GZIP 压缩失败', 'name' => ''];
                    file_put_contents($output_path, $gz_data);
                    @unlink($tar_path);
                    return ['ok' => file_exists($output_path), 'error' => '', 'name' => basename($output_path)];
                } catch (Exception $e) {
                    @unlink($tar_path ?? '');
                    return ['ok' => false, 'error' => 'GZIP 创建失败:' . $e->getMessage(), 'name' => ''];
                }
            } else {
                $f = fm_clean($files[0]);
                $full = $base_dir . '/' . $f;
                if (!file_exists($full)) return ['ok' => false, 'error' => '文件不存在', 'name' => ''];
                $output_path = $base_dir . '/' . $f . '.gz';
                $data = file_get_contents($full);
                $gz_data = gzencode($data, 9);
                if ($gz_data === false) return ['ok' => false, 'error' => 'GZIP 压缩失败', 'name' => ''];
                file_put_contents($output_path, $gz_data);
                return ['ok' => true, 'error' => '', 'name' => basename($output_path)];
            }

        case '7z':
            $output_path = $base_dir . '/' . $base_name . '.7z';
            $cmd = fm_find_executable('7z');
            if (!$cmd) return ['ok' => false, 'error' => '服务器未安装 7-Zip,无法创建 7Z 文件', 'name' => ''];
            $escaped_output = escapeshellarg($output_path);
            $escaped_files = implode(' ', array_map('escapeshellarg', array_map(function ($f) use ($base_dir) { return $base_dir . '/' . fm_clean($f); }, $files)));
            @set_time_limit(600);
            shell_exec("$cmd a -y $escaped_output $escaped_files 2>&1");
            return ['ok' => file_exists($output_path) && filesize($output_path) > 0, 'error' => file_exists($output_path) ? '' : '7Z 创建失败', 'name' => basename($output_path)];

        case 'rar':
            $output_path = $base_dir . '/' . $base_name . '.rar';
            $cmd = fm_find_executable(['rar', 'winrar']);
            if (!$cmd) return ['ok' => false, 'error' => '服务器未安装 WinRAR,无法创建 RAR 文件', 'name' => ''];
            $escaped_output = escapeshellarg($output_path);
            $escaped_files = implode(' ', array_map('escapeshellarg', array_map(function ($f) use ($base_dir) { return $base_dir . '/' . fm_clean($f); }, $files)));
            @set_time_limit(600);
            shell_exec("$cmd a -y $escaped_output $escaped_files 2>&1");
            return ['ok' => file_exists($output_path) && filesize($output_path) > 0, 'error' => file_exists($output_path) ? '' : 'RAR 创建失败', 'name' => basename($output_path)];

        default:
            return ['ok' => false, 'error' => '不支持的压缩类型: ' . $type, 'name' => ''];
    }
}

function fm_rmdir($dir) {
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $i) {
        $path = $i->getPathname();
        $i->isDir() ? @rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function fm_copy($src, $dst, $root = null) {
    global $root_path;
    $root = $root ?: $root_path;
    if (!fm_is_safe_path($src, $root) || !fm_is_safe_path($dst, $root)) return false;
    if (is_file($src)) return copy($src, $dst);
    @mkdir($dst, 0755, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $i) {
        $t = $dst . substr($i->getPathname(), strlen($src));
        if (!fm_is_safe_path($t, $root)) continue;
        $i->isDir() ? @mkdir($t, 0755, true) : copy($i->getPathname(), $t);
    }
    return true;
}

// ============================================================================
// 回收站函数
// ============================================================================

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
    file_put_contents($t . '/.trash_meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
    file_put_contents($f, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ============================================================================
// 收藏夹函数
// ============================================================================

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

// ============================================================================
// 辅助函数
// ============================================================================

function fmtsize($b) {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, 2) . ' ' . $u[$i];
}

function fm_unique_name($base, $path) {
    if (!file_exists($path . '/' . $base)) return $base;
    $ext = pathinfo($base, PATHINFO_EXTENSION);
    $name = $ext ? substr($base, 0, -(strlen($ext) + 1)) : $base;
    for ($i = 1; file_exists($path . '/' . $name . '-' . $i . ($ext ? '.' . $ext : '')); $i++);
    return $name . '-' . $i . ($ext ? '.' . $ext : '');
}

// 获取文件权限(Linux风格)
function fm_get_perms($path) {
    if (!file_exists($path)) return '-';
    $perms = fileperms($path);
    if ($perms === false) return '-';
    // 转为 4位八进制(如 0755)
    return sprintf('%04o', $perms & 0777);
}

// 修改文件权限(仅 Linux 有效,Windows 返回 false)
function fm_chmod($path, $mode) {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') return false; // Windows 不支持
    return @chmod($path, octdec($mode));
}

// 语言映射
$L = [
    'Login' => '登录', 'Logout' => '退出', 'Username' => '用户名', 'Password' => '密码',
    'Login failed' => '登录失败', 'Name' => '名称', 'Size' => '大小', 'Modified' => '修改时间',
    'Download' => '下载', 'Upload' => '上传', 'New Folder' => '新建文件夹', 'New File' => '新建文件',
    'Rename' => '重命名', 'Copy' => '复制', 'Move' => '移动', 'Delete' => '删除', 'Cancel' => '取消',
    'Created' => '已创建', 'Trash' => '回收站', 'Restore' => '恢复', 'Empty Trash' => '清空回收站',
    'Purge' => '彻底删除', 'Trash is empty' => '回收站为空', 'Pack ZIP' => '打包ZIP', 'Pack TAR' => '打包TAR',
    'Unpack' => '解压', 'Error' => '错误', 'Favorites' => '收藏夹', 'Star' => '收藏',
    'Unstar' => '取消收藏', 'Starred' => '已收藏', 'My Files' => '我的文档', 'Quick Access' => '快捷访问'
];

function lng($k) {
    global $L;
    return $L[$k] ?? $k;
}

// ============================================================================
// 路径处理 (主程序)
// ============================================================================

$p = isset($_GET['p']) ? fm_clean($_GET['p']) : '';
$current_path = realpath($root_path . ($p ? '/' . $p : ''));

$pathHint = null;
if (!$current_path) {
    $parent = $root_path . '/' . $p . '/..';
    if (!empty($_GET['p']) && is_dir($parent)) {
        $pathHint = htmlspecialchars(rawurldecode($_GET['p']));
        $current_path = realpath($parent) ?: $root_path;
    } else {
        http_response_code(400);
        die('操作无效');
    }
}
$rp = realpath($root_path);
if ($current_path !== $rp) {
    $a = strtolower(str_replace('\\', '/', $current_path));
    $b = strtolower(str_replace('\\', '/', $rp));
    if ($a !== $b && strpos($a . '/', $b . '/') !== 0) {
        http_response_code(400);
        die('操作无效');
    }
}

$self = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

// ============================================================================
// 认证
// ============================================================================

$is_logged = isset($_SESSION['fm']);
if (isset($_GET['logout'])) { session_destroy(); header('Location: ' . $self); exit; }

$login_token = $_SESSION['login_token'] ?? ($_SESSION['login_token'] = bin2hex(random_bytes(32)));
$login_error = null;
$login_locked = false;
$login_remaining_time = 0;

if (!$is_logged) {
    $login_attempts = $_SESSION['login_attempts'] ?? 0;
    $login_locked_until = $_SESSION['login_locked_until'] ?? 0;
    if ($login_locked_until > time()) {
        $login_locked = true;
        $login_remaining_time = ceil(($login_locked_until - time()) / 60);
    }
}

if (!$is_logged && isset($_POST['login'])) {
    if ($login_locked) {
        $login_error = "登录已锁定,请 {$login_remaining_time} 分钟后重试";
    } elseif (!isset($_POST['login_token']) || $_POST['login_token'] !== $_SESSION['login_token']) {
        $login_error = '安全验证失败,请刷新页面重试';
    } elseif ($_POST['user'] === $auth_user && password_verify($_POST['pass'], $auth_pass)) {
        session_regenerate_id(true);
        $_SESSION['fm'] = $auth_user;
        unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
        $_SESSION['token'] = bin2hex(random_bytes(32));
        $_SESSION['token_created'] = time();
        header('Location: ' . $self . '?p=' . urlencode($p)); exit;
    } else {
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= $login_max_attempts) {
            $_SESSION['login_locked_until'] = time() + $login_lockout_time;
            $login_error = "登录失败次数过多,已锁定";
        } else {
            $remaining = $login_max_attempts - $_SESSION['login_attempts'];
            $login_error = lng('Login failed') . "(剩余 {$remaining} 次尝试机会)";
        }
    }
}

if ($is_logged) fm_trash_clean(7);

// ============================================================================
// AJAX 处理器
// ============================================================================

// 收藏夹切换
if ($is_logged && isset($_POST['ajax_star'])) {
    header('Content-Type: application/json');
    $sp = fm_clean($_POST['star_path']);
    $action = fm_star_toggle($sp);
    echo json_encode(['success' => true, 'starred' => $action]);
    exit;
}

// 目录树(用于解压到目录选择器)
if ($is_logged && isset($_POST['ajax_dir_tree'])) {
    header('Content-Type: application/json');
    $relPath = isset($_POST['path']) ? fm_clean($_POST['path']) : '';
    $base = $root_path . ($relPath ? '/' . $relPath : '');
    if (!fm_is_safe_path($base, $root_path)) { echo json_encode(['error' => '非法路径']); exit; }
    $items = [];
    if (is_dir($base)) {
        $dh = opendir($base);
        while (($item = readdir($dh)) !== false) {
            if ($item === '.' || $item === '..') continue;
            $full = $base . '/' . $item;
            if (is_dir($full)) {
                $rel = ($relPath ? $relPath . '/' : '') . $item;
                $items[] = ['name' => $item, 'path' => $rel, 'type' => 'folder'];
            }
        }
        closedir($dh);
    }
    usort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
    echo json_encode(['items' => $items, 'current' => $relPath ?: '/']);
    exit;
}

// 文件搜索
if ($is_logged && isset($_POST['ajax_search'])) {
    header('Content-Type: application/json');
    $keyword = trim($_POST['keyword'] ?? '');
    $searchPath = isset($_POST['path']) ? fm_clean($_POST['path']) : '';
    $deep = isset($_POST['deep']) && $_POST['deep'] === '1';

    if (empty($keyword)) {
        echo json_encode(['success' => false, 'error' => '请输入搜索关键词']);
        exit;
    }

    $base = $root_path . ($searchPath ? '/' . $searchPath : '');
    if (!fm_is_safe_path($base, $root_path)) {
        echo json_encode(['success' => false, 'error' => '非法路径']);
        exit;
    }

    $results = [];
    $count = 0;
    $maxResults = 500; // 最大结果数

    function fm_search_dir($dir, $keyword, &$results, &$count, $maxResults, $root, $relPath = '') {
        if ($count >= $maxResults) return;
        if (!is_dir($dir)) return;

        $dh = opendir($dir);
        if ($dh === false) return;
        while (($item = readdir($dh)) !== false) {
            if ($item === '.' || $item === '..') continue;
            if ($count >= $maxResults) break;

            $fullPath = $dir . '/' . $item;
            $itemRelPath = $relPath ? $relPath . '/' . $item : $item;

            // 匹配文件名
            if (stripos($item, $keyword) !== false) {
                $isDir = is_dir($fullPath);
                $results[] = [
                    'name' => $item,
                    'path' => $itemRelPath,
                    'type' => $isDir ? 'folder' : 'file',
                    'size' => $isDir ? '-' : fmtsize(filesize($fullPath)),
                    'mtime' => date('Y-m-d H:i', filemtime($fullPath)),
                    'ext' => $isDir ? '' : strtolower(pathinfo($item, PATHINFO_EXTENSION))
                ];
                $count++;
            }
        }
        closedir($dh);
    }

    if ($deep) {
        // 深度搜索(递归子目录)
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($count >= $maxResults) break;

            $item = $file->getFilename();
            $fullPath = $file->getPathname();
            $itemRelPath = substr($fullPath, strlen($base) + 1);
            $itemRelPath = str_replace('\\', '/', $itemRelPath);

            if (stripos($item, $keyword) !== false) {
                $isDir = $file->isDir();
                $results[] = [
                    'name' => $item,
                    'path' => $itemRelPath,
                    'type' => $isDir ? 'folder' : 'file',
                    'size' => $isDir ? '-' : fmtsize(filesize($fullPath)),
                    'mtime' => date('Y-m-d H:i', $file->getMTime()),
                    'ext' => $isDir ? '' : strtolower(pathinfo($item, PATHINFO_EXTENSION))
                ];
                $count++;
            }
        }
    } else {
        // 浅层搜索(仅当前目录)
        fm_search_dir($base, $keyword, $results, $count, $maxResults, $root_path, $searchPath);
    }

    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results),
        'max' => $maxResults,
        'truncated' => $count >= $maxResults
    ]);
    exit;
}

// 清空回收站
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

// 回收站操作
if ($is_logged && isset($_GET['trash'])) {
    $t = fm_trash_dir();
    $mf = $t . '/.trash_meta.json';
    $meta = json_decode(file_get_contents($mf) ?: '{}', true);

    if (isset($_GET['restore'], $_GET['token']) && $_GET['token'] === $_SESSION['token'] && isset($meta[$_GET['restore']])) {
        $n = fm_clean($_GET['restore']);
        $restore_path = $meta[$n]['path'] . '/' . $meta[$n]['name'];
        $real_restore = realpath(dirname($restore_path));
        $real_root = realpath($root_path);
        if ($real_restore && $real_root && strpos($real_restore . '/', $real_root . '/') === 0) {
            rename($t . '/' . $n, $restore_path);
            unset($meta[$n]);
            file_put_contents($mf, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        header('Location: ' . $self . '?trash'); exit;
    }
    if (isset($_GET['purge'], $_GET['token']) && $_GET['token'] === $_SESSION['token'] && isset($meta[$_GET['purge']])) {
        $n = fm_clean($_GET['purge']);
        $meta[$n]['dir'] ? fm_rmdir($t . '/' . $n) : @unlink($t . '/' . $n);
        unset($meta[$n]);
        file_put_contents($mf, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        header('Location: ' . $self . '?trash'); exit;
    }
    if (isset($_GET['empty_trash'], $_GET['token']) && $_GET['token'] === $_SESSION['token']) {
        foreach ($meta as $k => $v) { $p = $t . '/' . $k; $v['dir'] ? fm_rmdir($p) : @unlink($p); }
        file_put_contents($mf, '{}');
        header('Location: ' . $self . '?trash'); exit;
    }
}

// 主要 AJAX 操作
if ($is_logged && isset($_POST['token']) && $_POST['token'] === $_SESSION['token']) {
    header('Content-Type: application/json');
    ob_start();

    // 文件上传
    if (isset($_GET['ajax_upload']) && !empty($_FILES['file'])) {
        $f = $_FILES['file'];
        $filename = fm_clean($f['name']);
        $rel = isset($_POST['relative_path']) ? fm_clean($_POST['relative_path']) : '';
        $target = $current_path . '/' . ($rel ?: $filename);

        if (!fm_is_safe_path($target, $root_path)) {
            echo json_encode(['success' => false, 'error' => '非法上传路径']);
            exit;
        }
        if (!fm_check_upload_ext($filename)) {
            echo json_encode(['success' => false, 'error' => '禁止上传此类型文件(安全风险)']);
            exit;
        }
        if ($f['size'] > $max_upload_size) {
            echo json_encode(['success' => false, 'error' => '文件超过最大上传限制']);
            exit;
        }
        if ($rel) @mkdir(dirname($target), 0755, true);
        $upload_success = move_uploaded_file($f['tmp_name'], $target);
        echo json_encode(['success' => $upload_success]);
        exit;
    }

    // 拖拽移动
    if (isset($_POST['ajax_move'])) {
        $src = $current_path . '/' . fm_clean($_POST['src']);
        $dstDir = !empty($_POST['dst_abs']) ? rtrim($root_path . '/' . fm_clean($_POST['dst']), '/') : $current_path . '/' . fm_clean($_POST['dst']);
        $dst = $dstDir . '/' . basename($src);
        echo json_encode(['success' => file_exists($src) && !file_exists($dst) && rename($src, $dst)]);
        exit;
    }
    // 批量拖拽移动
    if (isset($_POST['ajax_move_batch'])) {
        $files = json_decode($_POST['files'] ?? '[]', true);
        $dstDir = !empty($_POST['dst_abs']) ? rtrim($root_path . '/' . fm_clean($_POST['dst']), '/') : $current_path . '/' . fm_clean($_POST['dst']);
        $dst = $dstDir;
        $moved = 0; $errors = [];
        foreach ($files as $f) {
            $src = $current_path . '/' . fm_clean($f);
            $newDst = $dst . '/' . basename($src);
            if (file_exists($src) && !file_exists($newDst)) {
                if (rename($src, $newDst)) $moved++; else $errors[] = $f;
            }
        }
        echo json_encode(['success' => $moved > 0, 'moved' => $moved, 'error' => implode(', ', $errors)]);
        exit;
    }

    // 另存为(直接写入内容)
    if (isset($_GET['ajax_save_as'])) {
        $relPath = fm_clean($_GET['ajax_save_as']);
        $target = $root_path . '/' . $relPath;
        if (!fm_is_safe_path($target, $root_path)) {
            echo json_encode(['success' => false, 'error' => '非法保存路径']);
            exit;
        }
        $content = $_POST['content'] ?? '';
        $ok = file_put_contents($target, $content) !== false;
        echo json_encode(['success' => $ok, 'error' => $ok ? '' : '写入失败']);
        exit;
    }

    // 粘贴
    if (isset($_POST['ajax_paste'])) {
        $src_name = fm_clean($_POST['src']);
        parse_str(parse_url($_POST['from'] ?? '', PHP_URL_QUERY) ?? '', $qp);
        $src = $root_path . '/' . ($qp['p'] ?? '') . '/' . $src_name;
        $dst = $current_path . '/' . fm_clean($_POST['dst_folder'] ?? '') . '/' . $src_name;

        if (!fm_is_safe_path($src, $root_path) || !fm_is_safe_path($dst, $root_path)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => '路径错误']); exit;
        }
        if (!file_exists($src)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => '源文件不存在']); exit;
        }

        // 同一目录复制粘贴:生成副本
        $src_real = realpath($src);
        $dst_real = realpath(dirname($dst)) . '/' . basename($dst);
        if ($_POST['mode'] === 'copy' && $src_real === $dst_real) {
            $pathinfo = pathinfo($src_name);
            $base = $pathinfo['filename'];
            $ext = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
            $copy_name = $base . ' - 副本' . $ext;
            $copy_path = $current_path . '/' . $copy_name;
            $i = 2;
            while (file_exists($copy_path)) {
                $copy_name = $base . ' - 副本 (' . $i . ')' . $ext;
                $copy_path = $current_path . '/' . $copy_name;
                $i++;
            }
            $result = fm_copy($src, $copy_path, $root_path);
            ob_end_clean();
            echo json_encode(['success' => (bool)$result, 'name' => $copy_name]); exit;
        }

        if (file_exists($dst)) {
            // 冲突:返回两边文件信息让前端弹出对比窗口
            ob_end_clean();
            echo json_encode([
                'conflict' => true,
                'src' => $src_name,
                'srcSize' => is_dir($src) ? null : filesize($src),
                'srcMtime' => filemtime($src),
                'dstSize' => is_dir($dst) ? null : filesize($dst),
                'dstMtime' => filemtime($dst),
            ]); exit;
        }
        if ($_POST['mode'] === 'copy') {
            $result = fm_copy($src, $dst, $root_path);
            if (!$result) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => '复制失败:路径安全检查未通过']); exit;
            }
        } else {
            rename($src, $dst);
        }
        ob_end_clean(); echo json_encode(['success' => true]); exit;
    }
    // 解决冲突(用户确认后)
    if (isset($_POST['ajax_paste_resolve'])) {
        $src_name = fm_clean($_POST['src']);
        $action = $_POST['action']; // 'src' = 用源文件覆盖, 'skip' = 跳过
        parse_str(parse_url($_POST['from'] ?? '', PHP_URL_QUERY) ?? '', $qp);
        $src = $root_path . '/' . ($qp['p'] ?? '') . '/' . $src_name;
        $dst = $current_path . '/' . fm_clean($_POST['dst_folder'] ?? '') . '/' . $src_name;
        if (!fm_is_safe_path($src, $root_path) || !fm_is_safe_path($dst, $root_path)) {
            ob_end_clean(); echo json_encode(['success' => false, 'error' => '路径错误']); exit;
        }
        if ($action === 'skip') {
            ob_end_clean(); echo json_encode(['success' => true, 'skipped' => true]); exit;
        }
        if ($action === 'src') {
            if (is_dir($dst)) fm_rmdir($dst);
            else if (is_file($dst)) @unlink($dst);
            if ($_POST['mode'] === 'copy') {
                $r = fm_copy($src, $dst, $root_path);
                ob_end_clean(); echo json_encode(['success' => (bool)$r]); exit;
            } else {
                $ok = rename($src, $dst);
                ob_end_clean(); echo json_encode(['success' => $ok]); exit;
            }
        }
        ob_end_clean(); echo json_encode(['success' => false, 'error' => '无效操作']); exit;
    }
    if (isset($_POST['ajax_paste_batch'])) {
        $files = json_decode($_POST['files'] ?? '[]', true);
        $dst_folder = fm_clean($_POST['dst_folder'] ?? '');
        $mode = $_POST['mode'];
        parse_str(parse_url($_POST['from'] ?? '', PHP_URL_QUERY) ?? '', $qp);
        $src_base = $root_path . '/' . ($qp['p'] ?? '');
        $dst = $current_path . '/' . $dst_folder;
        $copied = 0; $errors = []; $conflict_info = [];
        foreach ($files as $f) {
            $src_name = fm_clean($f);
            $src = $src_base . '/' . $src_name;
            $newDst = $dst . '/' . $src_name;
            if (!fm_is_safe_path($src, $root_path) || !fm_is_safe_path($newDst, $root_path)) { $errors[] = $f; continue; }
            if (!file_exists($src)) { $errors[] = $f; continue; }
            if (file_exists($newDst)) {
                $src_is_dir = is_dir($src); $dst_is_dir = is_dir($newDst);
                $conflict_info[] = ['name' => $f, 'srcSize' => $src_is_dir ? null : filesize($src), 'srcMtime' => filemtime($src), 'dstSize' => $dst_is_dir ? null : filesize($newDst), 'dstMtime' => filemtime($newDst)];
                continue;
            }
            if ($mode === 'copy') {
                $ok = fm_copy($src, $newDst, $root_path);
            } else {
                $ok = rename($src, $newDst);
            }
            if ($ok) $copied++; else $errors[] = $f;
        }
        if (!empty($conflict_info)) { ob_end_clean(); echo json_encode(['success' => $copied > 0, 'copied' => $copied, 'conflict' => true, 'conflict_info' => $conflict_info, 'mode' => $mode, 'from' => $_POST['from'] ?? '', 'dst_folder' => $dst_folder]); exit; }
        ob_end_clean(); echo json_encode(['success' => $copied > 0, 'copied' => $copied, 'error' => implode(', ', $errors)]); exit;
    }

    // 批量冲突解决(用户勾选后提交覆盖)
    if (isset($_POST['ajax_paste_batch_resolve'])) {
        $names = json_decode($_POST['conflicts'] ?? '[]', true);
        $dst_folder = fm_clean($_POST['dst_folder'] ?? '');
        $mode = $_POST['mode'];
        parse_str(parse_url($_POST['from'] ?? '', PHP_URL_QUERY) ?? '', $qp);
        $src_base = $root_path . '/' . ($qp['p'] ?? '');
        $dst_base = $current_path . '/' . $dst_folder;
        $copied = 0; $errors = [];
        foreach ($names as $f) {
            $src_name = fm_clean($f);
            $src = $src_base . '/' . $src_name;
            $newDst = $dst_base . '/' . $src_name;
            if (!fm_is_safe_path($src, $root_path) || !fm_is_safe_path($newDst, $root_path)) { $errors[] = $f; continue; }
            if (!file_exists($src)) { $errors[] = $f; continue; }
            if (file_exists($newDst)) {
                if (is_dir($newDst)) fm_rmdir($newDst); else @unlink($newDst);
            }
            if ($mode === 'copy') { $ok = fm_copy($src, $newDst, $root_path); }
            else { $ok = rename($src, $newDst); }
            if ($ok) $copied++; else $errors[] = $f;
        }
        ob_end_clean();
        echo json_encode(['success' => $copied > 0, 'copied' => $copied, 'error' => implode(', ', $errors)]);
        exit;
    }

    // 打包(压缩)
    if (isset($_POST['ajax_pack'])) {
        $type = $_POST['ajax_pack'];
        $files = (array)($_POST['files'] ?? []);
        if (empty($files)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '未选择文件']); exit; }

        $output_path = '';
        $result = fm_pack($type, $files, $current_path, $output_path);
        ob_end_clean();
        echo json_encode(['success'=>$result['ok'],'error'=>$result['error'],'name'=>$result['name']]);
        exit;
    }

    // 解压
    if (isset($_POST['ajax_unpack'])) {
        $f = fm_clean($_POST['archive']);
        $path = $current_path . '/' . $f;
        if (!file_exists($path)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '文件不存在']); exit; }

        // 解压模式:current=解压到当前, custom=解压到指定文件夹, dir=解压到目录树选中的目录
        $mode = $_POST['unpack_mode'] ?? 'current';
        $target = $current_path;

        if ($mode === 'custom') {
            $custom = trim($_POST['unpack_target'] ?? '');
            $custom = fm_clean($custom);
            if (empty($custom)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '请输入目标文件夹名']); exit; }
            $folder = $current_path . '/' . $custom;
            if (!fm_is_safe_str_path($folder, $root_path)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '目标路径不安全']); exit; }
            if (!is_dir($folder)) @mkdir($folder, 0755, true);
            $target = $folder;
        } elseif ($mode === 'dir') {
            // 解压到目录树选中的任意目录
            $relDir = trim($_POST['unpack_dir'] ?? '');
            $relDir = fm_clean($relDir);
            if ($relDir === '' || $relDir === '/') {
                $target = $root_path;
            } else {
                $relDir = ltrim($relDir, '/');
                $target = $root_path . '/' . $relDir;
            }
            if (!fm_is_safe_path($target, $root_path)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '目标路径不安全']); exit; }
            if (!is_dir($target)) @mkdir($target, 0755, true);
        }

        $result = fm_safe_unpack($path, $target);
        ob_end_clean();
        echo json_encode(['success' => $result['ok'], 'error' => $result['error']]);
        exit;
    }

    // 离线下载
    if (isset($_POST['ajax_offline'])) {
        $url = $_POST['url'] ?? '';
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => '无效的下载链接']);
            exit;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https', 'ftp'])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => '仅支持 HTTP/HTTPS/FTP 链接']);
            exit;
        }
        $filename = basename(parse_url($url, PHP_URL_PATH)) ?: 'download_' . time();
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        if (strlen($filename) > 200) $filename = substr($filename, 0, 200);
        $target = $current_path . '/' . $filename;
        $counter = 1;
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        while (file_exists($target)) {
            $target = $current_path . '/' . $base . '(' . $counter . ')' . ($ext ? '.' . $ext : '');
            $counter++;
        }
        
        // 检查文件大小(防止磁盘耗尽)
        $maxSize = 5000000000; // 5GB 限制
        $headers = @get_headers($url, 1);
        if ($headers && !empty($headers['Content-Length'])) {
            $contentLength = (int)$headers['Content-Length'];
            if ($contentLength > $maxSize) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => '文件过大(最大 5GB)']);
                exit;
            }
        }
        
        $ctx = stream_context_create(['http' => ['timeout' => 300, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => '下载失败:无法获取远程文件']);
            exit;
        }
        file_put_contents($target, $data);
        ob_end_clean();
        echo json_encode(['success' => true, 'name' => basename($target)]);
        exit;
    }

    // 修改权限(仅 Linux 有效)
    if (isset($_POST['ajax_chmod'])) {
        $name = fm_clean($_POST['name'] ?? '');
        $mode = $_POST['mode'] ?? '';
        if (!$name || !preg_match('/^[0-7]{3,4}$/', $mode)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => '参数无效']);
            exit;
        }
        $path = $current_path . '/' . $name;
        if (!fm_is_safe_path($path, $root_path) || !file_exists($path)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => '文件不存在或路径不安全']);
            exit;
        }
        $ok = fm_chmod($path, $mode);
        ob_end_clean();
        echo json_encode(['success' => (bool)$ok, 'perms' => fm_get_perms($path), 'error' => $ok ? '' : '修改失败(可能不支持此系统)']);
        exit;
    }

    // AJAX 重命名
    if (isset($_POST['ajax_rename'])) {
        $from = fm_clean($_POST['rename_from']);
        $to = fm_clean($_POST['rename_to']);
        if (!$from || !$to) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '名称无效']); exit; }
        $src = $current_path . '/' . $from;
        $dst = $current_path . '/' . $to;
        if (!file_exists($src)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '源文件不存在']); exit; }
        if (file_exists($dst)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '目标名称已存在']); exit; }
        $ok = rename($src, $dst);
        ob_end_clean();
        echo json_encode(['success' => $ok, 'error' => $ok ? '' : '重命名失败']);
        exit;
    }

    // AJAX 删除(移到回收站)
    if (isset($_POST['ajax_delete'])) {
        $items = json_decode($_POST['delete'] ?? '[]', true);
        if (empty($items)) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '未选择文件']); exit; }
        $deleted = 0; $errors = [];
        foreach ($items as $item) {
            $full = $current_path . '/' . fm_clean($item);
            if (file_exists($full)) {
                if (fm_trash_move($full)) $deleted++;
                else $errors[] = $item;
            } else {
                $errors[] = $item;
            }
        }
        ob_end_clean();
        echo json_encode(['success' => $deleted > 0, 'deleted' => $deleted, 'error' => implode(', ', $errors)]);
        exit;
    }

    // AJAX 回收站恢复
    if (isset($_POST['ajax_trash_restore'])) {
        $key = fm_clean($_POST['key']);
        $t = fm_trash_dir();
        $mf = $t . '/.trash_meta.json';
        $meta = json_decode(file_get_contents($mf) ?: '{}', true);
        if (!isset($meta[$key])) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '项目不存在']); exit; }
        $n = $key;
        $restore_path = $meta[$n]['path'] . '/' . $meta[$n]['name'];
        $real_restore = realpath(dirname($restore_path));
        $real_root = realpath($root_path);
        $ok = false;
        if ($real_restore && $real_root && strpos($real_restore . '/', $real_root . '/') === 0) {
            $ok = rename($t . '/' . $n, $restore_path);
            if ($ok) {
                unset($meta[$n]);
                file_put_contents($mf, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        ob_end_clean();
        echo json_encode(['success' => $ok, 'error' => $ok ? '' : '恢复失败']);
        exit;
    }

    // AJAX 回收站彻底删除
    if (isset($_POST['ajax_trash_purge'])) {
        $key = fm_clean($_POST['key']);
        $t = fm_trash_dir();
        $mf = $t . '/.trash_meta.json';
        $meta = json_decode(file_get_contents($mf) ?: '{}', true);
        if (!isset($meta[$key])) { ob_end_clean(); echo json_encode(['success' => false, 'error' => '项目不存在']); exit; }
        $meta[$key]['dir'] ? fm_rmdir($t . '/' . $key) : @unlink($t . '/' . $key);
        unset($meta[$key]);
        file_put_contents($mf, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        ob_end_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    // AJAX 退出登录
    if (isset($_POST['ajax_logout'])) {
        session_destroy();
        ob_end_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    ob_end_clean();
}

// 回收站列表
if ($is_logged && isset($_GET['ajax_trash_list'])) {
    $header_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($header_token !== $_SESSION['token']) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF 验证失败']); exit;
    }
    header('Content-Type: application/json');
    $t = fm_trash_dir();
    $mf = $t . '/.trash_meta.json';
    $meta = file_exists($mf) ? (json_decode(file_get_contents($mf), true) ?: []) : [];
    $items = [];
    foreach ($meta as $k => $v) {
        $items[] = [
            'key' => $k, 'name' => $v['name'],
            'path' => str_replace($root_path, '', $v['path']),
            'time' => date('Y-m-d H:i', $v['time']), 'dir' => $v['dir']
        ];
    }
    echo json_encode(['items' => $items]); exit;
}

// ============================================================================
// 传统 POST 操作
// ============================================================================

if ($is_logged && isset($_POST['token']) && $_POST['token'] === $_SESSION['token'] && !isset($_POST['ajax_move'])) {
    $redirect = 'Location: ' . $self . '?p=' . urlencode($p);

    if (isset($_POST['ajax_check_name'])) {
        header('Content-Type:application/json');
        $n = fm_clean($_POST['name']); $type = $_POST['type'];
        if (!$n) { print(json_encode(['error' => '名称无效'])); exit; }
        if (file_exists($current_path . '/' . $n)) {
            $s = fm_unique_name($n, $current_path);
            print(json_encode(['duplicate' => true, 'suggest' => $s]));
        } else {
            print(json_encode(['duplicate' => false]));
        }
        exit;
    }

    $isAjax = isset($_POST['ajax_new']);
    if ($isAjax) header('Content-Type:application/json');

    if (isset($_POST['new_folder'])) {
        $n = fm_clean($_POST['new_folder']);
        if (!$n) { if ($isAjax) { print(json_encode(['error' => '名称无效'])); exit; } header($redirect); exit; }
        if (file_exists($current_path . '/' . $n)) { if ($isAjax) { print(json_encode(['error' => '"' . $n . '" 已存在'])); exit; } header($redirect); exit; }
        mkdir($current_path . '/' . $n, 0755);
        if ($isAjax) { print(json_encode(['success' => true, 'name' => $n])); exit; }
        header($redirect); exit;
    }

    if (isset($_POST['new_file'])) {
        $n = fm_clean($_POST['new_file']);
        if (!$n) { if ($isAjax) { print(json_encode(['error' => '名称无效'])); exit; } header($redirect); exit; }
        if (file_exists($current_path . '/' . $n)) { if ($isAjax) { print(json_encode(['error' => '"' . $n . '" 已存在'])); exit; } header($redirect); exit; }
        file_put_contents($current_path . '/' . $n, '');
        if ($isAjax) { print(json_encode(['success' => true, 'name' => $n])); exit; }
        header($redirect); exit;
    }

    if (!empty($_FILES['file']['name']) && !isset($_GET['ajax_upload'])) {
        $filename = fm_clean($_FILES['file']['name']);
        $target = $current_path . '/' . $filename;
        if (fm_check_upload_ext($filename) && fm_is_safe_path($target, $root_path)) {
            move_uploaded_file($_FILES['file']['tmp_name'], $target);
        }
        header($redirect); exit;
    }

    if (isset($_POST['rename_from'], $_POST['rename_to'])) {
        $o = $current_path . '/' . fm_clean($_POST['rename_from']);
        $n = $current_path . '/' . fm_clean($_POST['rename_to']);
        file_exists($o) && !file_exists($n) && rename($o, $n);
        header($redirect); exit;
    }

    if (isset($_POST['copy_src'], $_POST['copy_dst'])) {
        $s = $current_path . '/' . fm_clean($_POST['copy_src']);
        $d = $current_path . '/' . fm_clean($_POST['copy_dst']);
        if (fm_is_safe_path($s, $root_path) && fm_is_safe_path($d, $root_path)) {
            file_exists($s) && !file_exists($d) && fm_copy($s, $d, $root_path);
        }
        header($redirect); exit;
    }

    if (isset($_POST['move_src'], $_POST['move_dst'])) {
        $s = $current_path . '/' . fm_clean($_POST['move_src']);
        $d = $current_path . '/' . fm_clean($_POST['move_dst']);
        file_exists($s) && !file_exists($d) && rename($s, $d);
        header($redirect); exit;
    }

    if (isset($_POST['delete'])) {
        foreach ((array)$_POST['delete'] as $item) fm_trash_move($current_path . '/' . fm_clean($item));
        header($redirect); exit;
    }

    if (isset($_POST['unpack'])) {
        $f = fm_clean($_POST['archive']);
        $path = $current_path . '/' . $f;
        if (file_exists($path)) fm_safe_unpack($path, $current_path);
        header($redirect); exit;
    }
}

// ============================================================================
// 文件下载
// ============================================================================

// AJAX: 获取目录列表(JSON) — SPA 导航核心接口
if ($is_logged && isset($_GET['ajax']) && $_GET['ajax'] === 'filelist') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $relPath = isset($_GET['p']) ? fm_clean($_GET['p']) : '';
    $absPath = realpath($root_path . ($relPath ? '/' . $relPath : ''));
    if (!$absPath || !fm_is_safe_path($absPath, $root_path)) {
        echo json_encode(['error' => '路径不存在或无权访问']);
        exit;
    }
    $folders = []; $files = [];
    foreach (scandir($absPath) as $i) {
        if ($i !== '.' && $i !== '..') {
            is_dir($absPath . '/' . $i) ? $folders[] = $i : $files[] = $i;
        }
    }
    natcasesort($folders); natcasesort($files);
    $parent = $relPath ? dirname($relPath) : false;
    if ($parent === '.') $parent = '';
    $stars = fm_star_load();
    $result = [
        'path'     => $relPath,
        'basename' => basename($relPath) ?: '根目录',
        'parent'   => $parent,
        'folders'  => [],
        'files'    => [],
        'stars'    => $stars,
        'folderCount' => count($folders),
        'fileCount'   => count($files),
    ];
    $forbidden = ['php','php3','php4','php5','phtml','phar','asp','aspx','jsp','cgi','pl','py','sh','bash','exe','bat','cmd','com','vbs','vbe','js','jse','wsf','wsh','htaccess','ini','shtml','stm','shtm'];
    $zipExts = ['zip','tar','gz','tgz','7z','rar','bz2'];
    $imgExts = ['jpg','jpeg','png','gif','webp','svg','bmp','ico'];
    $vidExts = ['mp4','webm','ogg','mov','avi','mkv','m4v','3gp','flv','wmv'];
    $audExts = ['mp3','wav','ogg','flac','aac','m4a','wma','opus'];
    $editExts = ['js','ts','css','html','htm','php','json','py','md','xml','yaml','yml','sql','sh','bash','txt','csv','log','conf','ini','bat','ps1','go','rs','java','c','cpp','cc','h','cs','rb','swift','kt','vue','jsx','tsx','scss','less','toml','dockerfile','makefile','lua','perl','r','scala','properties','xhtml','svg'];
    foreach ($folders as $f) {
        $fp = trim($relPath . '/' . $f, '/');
        $full = $absPath . '/' . $f;
        $result['folders'][] = [
            'name'  => $f,
            'path'  => $fp,
            'shareUrl' => $share_base . '/' . $fp,
            'perms' => fm_get_perms($full),
            'mtime' => filemtime($full),
            'starred' => in_array($fp, $stars),
        ];
    }
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $full = $absPath . '/' . $f;
        $fp = trim($relPath . '/' . $f, '/');
        $result['files'][] = [
            'name'     => $f,
            'path'     => $fp,
            'shareUrl' => $share_base . '/' . $fp,
            'ext'      => $ext,
            'size'     => filesize($full),
            'humanSize'=> fmtsize(filesize($full)),
            'perms'    => fm_get_perms($full),
            'mtime'    => filemtime($full),
            'mtimeStr' => date('Y-m-d H:i', filemtime($full)),
            'starred'  => false,
            'isZip'    => in_array($ext, $zipExts),
            'isImage'  => in_array($ext, $imgExts),
            'isVideo'  => in_array($ext, $vidExts),
            'isAudio'  => in_array($ext, $audExts),
            'isEditable'=> in_array($ext, $editExts),
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: 获取目录树(侧边栏用)
if ($is_logged && isset($_GET['ajax']) && $_GET['ajax'] === 'dirs') {
    header('Cache-Control: private, max-age=10');
    $etag = md5($root_path . filemtime($root_path));
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
        http_response_code(304); exit;
    }
    header('ETag: ' . $etag);
    $scan_path = $root_path;
    $result = [];
    $dirs = glob($scan_path . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $d) {
        $name = basename($d);
        $rel = ltrim(substr($d, strlen($root_path)), '/');
        $result[] = ['name' => $name, 'path' => $rel];
    }
    echo json_encode($result);
    exit;
}

// AJAX: 获取指定目录的文件列表
if ($is_logged && isset($_GET['ajax']) && $_GET['ajax'] === 'files' && isset($_GET['p'])) {
    $dir_path = $root_path . '/' . fm_clean($_GET['p']);
    if (!is_dir($dir_path)) { echo json_encode([]); exit; }
    header('Cache-Control: private, max-age=5');
    $etag = md5($dir_path . filemtime($dir_path));
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
        http_response_code(304); exit;
    }
    header('ETag: ' . $etag);
    $result = [];
    $files = scandir($dir_path);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = $dir_path . '/' . $f;
        if (is_file($fp)) {
            $result[] = ['name' => $f, 'size' => filesize($fp)];
        }
    }
    echo json_encode($result);
    exit;
}

// 检查文件是否存在
if ($is_logged && isset($_GET['check'])) {
    $f = $current_path . '/' . fm_clean($_GET['check']);
    echo json_encode(['exists' => is_file($f)]);
    exit;
}

if ($is_logged && isset($_GET['dl'])) {
    $f = $current_path . '/' . fm_clean($_GET['dl']);
    if (!fm_is_safe_path($f, $root_path)) { http_response_code(403); die('禁止访问'); }
    if (is_file($f)) {
        $safe_filename = str_replace(['"', '\\', "\r", "\n"], '', basename($f));
        $content = file_get_contents($f);
        // 如果指定了编码,进行转换
        if (isset($_GET['enc'])) {
            $targetEnc = strtoupper($_GET['enc']);
            $validEncs = ['UTF-8','GBK','GB2312','BIG5','ISO-8859-1'];
            if (in_array($targetEnc, $validEncs)) {
                $detected = mb_detect_encoding($content, ['UTF-8','GBK','GB2312','BIG5','ISO-8859-1'], true);
                if ($detected && $detected !== $targetEnc) {
                    $content = mb_convert_encoding($content, $targetEnc, $detected);
                }
            }
            header('Content-Type: text/plain; charset=' . $targetEnc);
            echo $content; exit;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
        header('Content-Length: ' . filesize($f));
        header('X-Content-Type-Options: nosniff');
        readfile($f); exit;
    }
}

// raw 文件服务(图片预览等)
if ($is_logged && isset($_GET['raw'])) {
    $f = $current_path . '/' . fm_clean($_GET['raw']);
    if (!fm_is_safe_path($f, $root_path)) { http_response_code(403); die('禁止访问'); }
    if (is_file($f)) {
        $safe_filename = str_replace(['"', "\\", "\r", "\n"], '', basename($f));
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp', 'ico' => 'image/x-icon'
        ];
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($f));
        header('X-Content-Type-Options: nosniff');
        readfile($f); exit;
    }
}

// ============================================================================
// 文件列表
// ============================================================================

$folders = [];
$files = [];
foreach (scandir($current_path) as $i) {
    if ($i !== '.' && $i !== '..') {
        is_dir($current_path . '/' . $i) ? $folders[] = $i : $files[] = $i;
    }
}
natcasesort($folders); natcasesort($files);
$parent = $p ? dirname($p) : false;
if ($parent === '.') $parent = '';
$stars = $is_logged ? fm_star_load() : [];

// ============================================================================
// 页面渲染
// ============================================================================
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-Content-Type-Options" content="nosniff">
<meta http-equiv="X-XSS-Protection" content="1; mode=block">
<title><?=APP_TITLE?></title>
<link rel="stylesheet" href="styles.css">
<script>
(function(){
  var t=localStorage.getItem('theme');
  var isDark=t==='dark'||(!t&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);
  if(isDark)document.documentElement.classList.add('dark');
  var el=document.getElementById('themeToggle')||document.getElementById('loginThemeToggle');
  if(el)el.textContent=isDark?'☀️':'🌙';
})();
function toggleTheme(){
  var r=document.documentElement,isDark=!r.classList.contains('dark');
  r.classList.toggle('dark',isDark);
  localStorage.setItem('theme',isDark?'dark':'light');
  var el=document.getElementById('themeToggle')||document.getElementById('loginThemeToggle');
  if(el)el.textContent=isDark?'☀️':'🌙';
  var b=document.getElementById('mobileDarkModeBtn');
  if(b)b.innerHTML=isDark?'☀️ 日间模式':'🌙 夜间模式';
  if(typeof closeDesktopMenu==='function')closeDesktopMenu();
}
</script>

<script>!function(){var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/ace-builds@1.36.2/src-min-noconflict/ace.js';s.onerror=function(){s.onerror=null;s.src='/js/ace.js';};document.head.appendChild(s);}();</script> <!-- 先尝试 CDN.js失败时自动回退到本地 /js/ace.js-->
</head>
<body class="<?=$is_logged?'logged':''?>">

<?php if (!$is_logged): ?>
<!-- 登录页 -->
<div class="login-box">
    <button id="loginThemeToggle" onclick="toggleTheme()" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;font-size:16px;opacity:.3;transition:opacity .2s" onmouseenter="this.style.opacity='.7'" onmouseleave="this.style.opacity='.3'">🌙</button>
    <h2>📁 <?=APP_TITLE?></h2>
    <?php if (isset($login_error)): ?><div class="alert alert-error"><?=htmlspecialchars($login_error)?></div><?php endif; ?>
    <?php if ($login_locked): ?>
        <div class="alert alert-error">🔒 登录已锁定,请 <?=htmlspecialchars($login_remaining_time)?> 分钟后重试</div>
    <?php else: ?>
    <form method="post" id="loginForm">
        <input type="hidden" name="login_token" value="<?=htmlspecialchars($login_token)?>">
        <div class="form-group" style="margin-bottom:12px"><input type="text" name="user" class="form-control" placeholder="<?=lng('Username')?>" required autofocus autocomplete="username"></div>
        <div class="form-group" style="margin-bottom:12px"><input type="password" name="pass" class="form-control" placeholder="<?=lng('Password')?>" required autocomplete="current-password"></div>
        <button type="submit" name="login" class="btn btn-primary" style="width:60%;margin:0 auto;display:block;text-align:center"><?=lng('Login')?></button>
    </form>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- 主界面 -->
<div class="sidebar desktop-sidebar" id="desktopSidebar">
    <div class="sidebar-header">☁️ <?=APP_TITLE?><button id="themeToggle" onclick="toggleTheme()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;padding:2px 4px;border-radius:6px">🌙</button></div>
    <div class="sidebar-scroll">
        <div class="sidebar-section">
            <div class="sidebar-title" onclick="toggleSection('starList')">⭐ <?=lng('Favorites')?> <span class="arrow">▼</span></div>
            <ul class="sidebar-list" id="starList">
                <?php if(empty($stars)): ?>
                <li style="padding:8px 16px;font-size:12px;color:var(--muted)">暂无收藏</li>
                <?php else: foreach($stars as $sp): $sp_name=basename($sp)?:'./'; ?>
                <li class="sidebar-item <?=$p===$sp?'active':''?>">
                    <span class="item-icon"><?=is_dir($root_path.'/'.($sp?$sp.'/':''))?'📁':'📄'?></span>
                    <a href="?p=<?=urlencode($sp)?>" class="item-name spa-nav" data-nav-path="<?=htmlspecialchars($sp)?>" title="<?=htmlspecialchars($sp)?>"><?=htmlspecialchars($sp_name)?></a>
                    <span class="star-btn starred" onclick="event.stopPropagation();toggleStar('<?=addslashes($sp)?>',this)" data-star-path="<?=addslashes($sp)?>" title="取消收藏">★</span>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-title" onclick="toggleSection('quickList')">🏠 <?=lng('My Files')?> <span class="arrow">▼</span></div>
            <ul class="sidebar-list" id="quickList">
                <li class="sidebar-item <?=$p===''?'active':''?>"><span class="item-icon">🏠</span><a href="?p=" class="item-name spa-nav" data-nav-path="">根目录</a></li>
                <?php foreach($folders as $sf): $sf_path=trim($p.'/'.$sf,'/'); ?>
                <li class="sidebar-item"><span class="item-icon">📁</span><a href="?p=<?=urlencode($sf_path)?>" class="item-name spa-nav" data-nav-path="<?=htmlspecialchars($sf_path)?>"><?=$sf?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    </div>
</div>

<div class="music-panel" id="musicPanel">
    <div class="music-panel-title">🎵 播放器 <button class="music-close" onclick="var p=document.getElementById('musicPanel');_mPanel=!_mPanel;p.classList.toggle('show',_mPanel);if(_mPanel&&_mList.length===0)loadMusic();if(!_mPanel)saveMusicState();">✕</button></div>
    <div class="music-lrc-box" id="musicLrcBox"><div class="no-lrc">🎶</div></div>
    <div class="music-title" id="musicTitle">选择音乐播放</div>
    <div class="music-progress" onclick="seekMusic(event)"><div class="music-progress-fill" id="musicFill"></div></div>
    <div class="music-time"><span id="musicCur">0:00</span><span id="musicDur">0:00</span></div>
    <div class="music-controls">
        <button onclick="prevTrack()">⏮</button>
        <button class="play" onclick="togglePlay()" id="playBtn">▶</button>
        <button onclick="nextTrack()">⏭</button>
        <div class="music-volume">
            <button onclick="toggleMute()" id="volBtn">🔊</button>
            <input type="range" min="0" max="1" step="0.05" value="0.8" id="volSlider" oninput="setVol(this.value)">
        </div>
    </div>
    <div class="music-toolbar">
        <button onclick="showAddMusic()">➕ 添加</button>
        <button onclick="cycleMode()" id="modeBtn" class="mode-on" title="切换播放模式">➡️顺序</button>
        <button onclick="toggleList()" class="list-toggle" id="listToggle">📃 ▼</button>
    </div>
    <div class="music-list" id="musicList"></div>
</div>
<audio id="musicAudio"></audio>
<div class="music-add-modal" id="musicAddModal" style="display:none">
    <div class="music-add-modal-title">📁 选择目录 <button class="music-close" onclick="hideAddMusic()">✕</button></div>
    <div class="music-add-dir" id="musicAddDir"></div>
    <div style="margin-top:8px;display:flex;gap:8px">
        <button style="flex:1;padding:6px;border:1px solid var(--border);background:var(--hover);border-radius:4px;cursor:pointer;font-size:12px" onclick="hideAddMusic()">取消</button>
        <button style="flex:1;padding:6px;background:var(--primary);color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px" onclick="confirmAddMusic()">确认添加</button>
    </div>
</div>

<div class="main-area">
<!-- 移动端顶部导航栏 -->
<div class="mobile-top-bar" id="mobileTopBar">
    <button class="mobile-menu-btn" id="mobileSidebarToggle" onclick="toggleMobileSidebar()" title="菜单">☰</button>
    <button class="mobile-home-btn" onclick="navigateTo('')" title="返回根目录">🏠</button>
    <span class="mobile-title">个人盘</span>
    <button class="mobile-more-btn" onclick="toggleMobileMore()" title="更多">≡</button>
</div>
<!-- 移动端侧边栏 -->
<div class="sidebar mobile-sidebar" id="mobileSidebar">
    <div class="sidebar-header">☁️ <?=APP_TITLE?><button onclick="toggleMobileSidebar()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;padding:2px 4px;border-radius:6px">✕</button></div>
    <div class="sidebar-scroll">
        <div class="sidebar-section">
            <div class="sidebar-title" onclick="toggleSection('mStarList')">⭐ <?=lng('Favorites')?> <span class="arrow">▼</span></div>
            <ul class="sidebar-list" id="mStarList">
                <?php if(empty($stars)): ?>
                <li style="padding:8px 16px;font-size:12px;color:var(--muted)">暂无收藏</li>
                <?php else: foreach($stars as $sp): $sp_name=basename($sp)?:'./'; ?>
                <li class="sidebar-item <?=$p===$sp?'active':''?>">
                    <span class="item-icon"><?=is_dir($root_path.'/'.($sp?$sp.'/':''))?'📁':'📄'?></span>
                    <a href="?p=<?=urlencode($sp)?>" class="item-name spa-nav" data-nav-path="<?=htmlspecialchars($sp)?>" title="<?=htmlspecialchars($sp)?>"><?=htmlspecialchars($sp_name)?></a>
                    <span class="star-btn starred" onclick="event.stopPropagation();toggleStar('<?=addslashes($sp)?>',this)" data-star-path="<?=addslashes($sp)?>" title="取消收藏">★</span>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-title" onclick="toggleSection('mQuickList')">🏠 <?=lng('My Files')?> <span class="arrow">▼</span></div>
            <ul class="sidebar-list" id="mQuickList">
                <li class="sidebar-item <?=$p===''?'active':''?>"><span class="item-icon">🏠</span><a href="?p=" class="item-name spa-nav" data-nav-path="">根目录</a></li>
                <?php foreach($folders as $sf): $sf_path=trim($p.'/'.$sf,'/'); ?>
                <li class="sidebar-item"><span class="item-icon">📁</span><a href="?p=<?=urlencode($sf_path)?>" class="item-name spa-nav" data-nav-path="<?=htmlspecialchars($sf_path)?>"><?=$sf?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<div style="max-width:100%;background:var(--card);flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0">

<div class="header">
<button class="sidebar-toggle-btn" onclick="toggleDesktopSidebar()" title="切换侧边栏">☰</button>
<h1 style="flex-shrink:0">📂 <?=htmlspecialchars(basename($p)?:'根目录')?></h1><div class="header-actions">

<button class="music-btn" onclick="var p=document.getElementById('musicPanel');_mPanel=!_mPanel;p.classList.toggle('show',_mPanel);if(_mPanel&&_mList.length===0)loadMusic();if(!_mPanel)saveMusicState();">🎵</button>
<div class="user-btn-wrapper"><button class="user-btn" onclick="toggleDesktopMenu()" title="更多">👤</button>
<div class="desktop-menu" id="desktopMenu">
<div class="desktop-menu-item" onclick="toggleTheme();closeDesktopMenu()">🌙 夜间模式</div>
<div class="desktop-menu-item" onclick="showTrashPanel();closeDesktopMenu()">🗑️ 回收站</div>
<div class="desktop-menu-item" onclick="ajaxLogout();closeDesktopMenu()">🚪 退出登录</div></div></div></div></div>
<div class="breadcrumb" style="display:flex;align-items:center;flex-wrap:nowrap;overflow:hidden;gap:0;min-width:0">
		<button onclick="history.back()" title="后退" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;flex-shrink:0">◀</button>
		<button onclick="history.forward()" title="前进" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;flex-shrink:0">▶</button>
		<span style="border-left:1px solid var(--border);height:14px;margin:0 2px;flex-shrink:0"></span>
		<span onclick="showPathInput(this)" style="cursor:text;flex:1;display:flex;align-items:center;min-width:0;overflow:hidden">
			<a href="?p=" data-folder="" class="spa-nav" data-nav-path="" ondragstart="return false" style="flex-shrink:0">🏠</a>
			<?php $acc=''; foreach(array_filter(explode('/',$p)) as $part): $acc.=($acc?'/':'').$part; ?>
			<span style="flex-shrink:0">›</span>
			<a href="?p=<?=urlencode($acc)?>" data-folder="<?=htmlspecialchars($acc, ENT_QUOTES)?>" class="spa-nav" data-nav-path="<?=htmlspecialchars($acc, ENT_QUOTES)?>" ondragstart="return false" style="flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($part, ENT_QUOTES)?></a>
			<?php endforeach; ?>
		</span>
		<button data-star-path="<?=htmlspecialchars(addslashes($p))?>" class="addr-star-btn<?=in_array($p,$stars)?' starred':''?>" onclick="event.stopPropagation();toggleStar('<?=addslashes($p)?>',this)" title="<?=in_array($p,$stars)?'取消收藏':'收藏此路径'?>" style="flex-shrink:0;background:none;border:none;cursor:pointer;font-size:15px;padding:0 6px;color:var(--muted);opacity:.5;transition:opacity .15s,color .15s"><?=in_array($p,$stars)?'★':'☆'?></button>
</div>

    <?php if($pathHint !== null): $token=$_SESSION['token']??''; $encFolder=addslashes($pathHint); ?>
    <div style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9998" onclick="var h=document.getElementById('pathHint');if(h){h.remove();this.remove()}"></div>
    <div id="pathHint" style="position:fixed;left:50%;top:40%;transform:translate(-50%,-50%);background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 18px;z-index:9999;max-width:300px;font-size:13px">
        ⚠️ <b style="color:var(--primary)"><?=$pathHint?></b> 不存在<br>
        <button class="btn btn-primary" onclick="createMissingFolder()" style="margin-top:10px;padding:4px 14px;font-size:12px">✅ 创建此文件夹</button>
        <button class="btn" onclick="var h=document.getElementById('pathHint');var ov=h.previousElementSibling;if(ov&&ov.style&&ov.style.position==='fixed')ov.remove();h.remove()" style="margin-top:10px;padding:4px 14px;font-size:12px">✕ 关闭</button>
    </div>
    <script>function createMissingFolder(){const fd=new FormData();fd.append('token','<?=$token?>');fd.append('ajax_new','1');fd.append('new_folder','<?=$encFolder?>');fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{var h=document.getElementById('pathHint');if(h){var ov=h.previousElementSibling;if(ov&&ov.style&&ov.style.position==='fixed')ov.remove();h.remove()}if(d.error)alert(d.error);else navigateTo(currentPath)}).catch(()=>alert('创建失败'))}</script>
    <?php endif; ?>

    <div class="toolbar">
        <!-- 新建下拉 -->
        <div class="dropdown" onmouseleave="this.querySelector('.dropdown-menu').style.display='none'">
            <button type="button" class="btn btn-primary" onclick="const m=this.nextElementSibling;m.style.display=m.style.display==='none'?'block':'none'">📁 新建 ▼</button>
            <div class="dropdown-menu" style="display:none">
                <div class="dropdown-item" onclick="startNew('folder');this.closest('.dropdown-menu').style.display='none'">📁 新建文件夹</div>
                <div class="dropdown-item" onclick="startNew('file');this.closest('.dropdown-menu').style.display='none'">📄 新建文件</div>
            </div>
        </div>
        <!-- 上传下拉 -->
        <div class="dropdown" onmouseleave="this.querySelector('.dropdown-menu').style.display='none'">
            <button type="button" class="btn" onclick="const m=this.nextElementSibling;m.style.display=m.style.display==='none'?'block':'none'">📤 上传 ▼</button>
            <div class="dropdown-menu" style="display:none">
                <div class="dropdown-item" onclick="showModal('uploadModal');this.closest('.dropdown-menu').style.display='none'">📤 上传文件</div>
                <div class="dropdown-item" onclick="document.getElementById('uploadFolderInput').click();this.closest('.dropdown-menu').style.display='none'">📂 上传文件夹</div>
                <div class="dropdown-item" onclick="showModal('offlineModal');this.closest('.dropdown-menu').style.display='none'">🌐 离线下载</div>
            </div>
        </div>
        <button type="button" class="btn btn-icon" onclick="refreshFileList()" title="刷新">🔄</button>
        <button type="button" class="btn btn-icon" id="toggleHiddenBtn" onclick="toggleHidden()" title="显示/隐藏隐藏文件">👁️</button>
        <div class="toolbar-search">
            <input type="text" id="quickSearchInput" placeholder="搜索当前目录..." oninput="quickSearch(this.value)">
            <button type="button" class="btn" onclick="showSearchModal()" title="深度搜索 (Ctrl+F)">🔍</button>
        </div>
    </div>

    <form method="post" id="mainForm" style="display:flex;flex-direction:column;flex:1">
        <input type="hidden" name="token" value="<?=$_SESSION['token']?>">
        <div class="table-container" id="dropZone"><table>

			<thead><tr>
				<th class="no-sort no-resize" style="width:26px"><input type="checkbox" id="selectAll" class="checkbox" onchange="toggleAll()"></th>
				<th class="no-sort no-resize" style="width:24px">★</th>
				<th class="sortable" data-sort="name" style="width:35%; min-width:200px;">名称<span class="sort-icon"></th>
				<th class="sortable" data-sort="perms" style="width:55px">权限<span></th>
				<th class="sortable" data-sort="type" style="width:65px">类型<span class="sort-icon"></th>
				<th class="sortable" data-sort="size" style="width:70px">大小<span class="sort-icon"></th>
				<th class="sortable" data-sort="mtime" style="width:90px">修改时间<span class="sort-icon"></th>
			<th class="no-sort" style="width:50px">链接</th>
			</tr></thead>
            <tbody>
            <?php if($parent !== false): ?><tr class="parent-row"><td></td><td></td><td><a href="?p=<?=urlencode($parent)?>" class="spa-nav" data-nav-path="<?=htmlspecialchars($parent)?>">📁 ..</a></td><td>-</td><td>-</td><td></td><td></td></tr><?php endif; ?>
            <?php foreach($folders as $f): $fp=trim($p.'/'.$f,'/'); $fs=in_array($fp,$stars); ?>
            <tr draggable="true" data-name="<?=htmlspecialchars($f)?>" data-ctx="1" data-type="folder" data-size="0" data-mtime="<?=filemtime($current_path.'/'.$f)?>" data-hidden="<?=$f[0]==='.'?1:0?>" onclick="selectRow(this,event)" ondblclick="navigateTo('<?=addslashes($fp)?>')">
                <td><input type="checkbox" name="files[]" value="<?=htmlspecialchars($f)?>" class="checkbox file-check" onclick="event.stopPropagation()"></td>
                <td><span class="star-icon <?=$fs?'starred':''?>" onclick="event.stopPropagation();toggleStar('<?=addslashes($fp)?>',this)" data-star-path="<?=addslashes($fp)?>" title="<?=$fs?'取消收藏':'收藏'?>"><?=$fs?'★':'☆'?></span></td>
                <td><a href="?p=<?=urlencode($fp)?>" class="folder-link spa-nav" data-nav-path="<?=htmlspecialchars($fp)?>" data-folder="<?=htmlspecialchars($f)?>" onclick="event.stopPropagation()">📁 <?=htmlspecialchars($f)?></a></td>
                <td class="perms-cell" onclick="event.stopPropagation();showChmodModal('<?=addslashes($f)?>','<?=addslashes($fp)?>',this)" title="点击修改权限"><?=fm_get_perms($current_path.'/'.$f)?></td><td>文件夹</td><td>-</td><td><?=date('Y-m-d H:i',filemtime($current_path.'/'.$f))?></td><td class="share-cell"><a href="<?=htmlspecialchars($share_base.'/'.$fp)?>" target="_blank" title="打开文件夹">🔗</a></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach($files as $f): $ext=strtolower(pathinfo($f,PATHINFO_EXTENSION)); $isZip=in_array($ext,['zip','tar','gz','tgz','7z','rar','bz2']); $isImage=in_array($ext,['jpg','jpeg','png','gif','webp','svg','bmp','ico']); $isVideo=in_array($ext,['mp4','webm','ogg','mov','avi','mkv','m4v','3gp','flv','wmv']); $isAudio=in_array($ext,['mp3','wav','ogg','flac','aac','m4a','wma','opus']); $isEditable=in_array($ext,['js','ts','css','html','htm','php','json','py','md','xml','yaml','yml','sql','sh','bash','txt','csv','log','conf','ini','bat','ps1','go','rs','java','c','cpp','cc','h','cs','rb','swift','kt','vue','jsx','tsx','scss','less','toml','dockerfile','makefile','lua','perl','r','scala','properties','xhtml','svg']); ?>
            <tr draggable="true" data-name="<?=htmlspecialchars($f)?>" data-ctx="1" data-type="file" data-ext="<?=$ext?>" data-archive="<?=$isZip?1:0?>" data-size="<?=filesize($current_path.'/'.$f)?>" data-mtime="<?=filemtime($current_path.'/'.$f)?>" data-hidden="<?=$f[0]==='.'?1:0?>" data-editable="<?=$isEditable?1:0?>" data-image="<?=$isImage?1:0?>" data-video="<?=$isVideo?1:0?>" data-audio="<?=$isAudio?1:0?>" data-audio-path="<?=urlencode($p)?>" onclick="selectRow(this,event)" ondblclick="<?=$isImage?'showImagePreview(\''.addslashes($p).'\',\''.addslashes($f).'\')':($isVideo?'showVideoPreview(\''.addslashes($p).'\',\''.addslashes($f).'\')':($isAudio?'playAudioFile(\''.addslashes($p).'\',\''.addslashes($f).'\')':'event.ctrlKey||event.metaKey||event.shiftKey||openAceEditor(\''.addslashes($p).'\',\''.addslashes($f).'\')'))?>">
                <td><input type="checkbox" name="files[]" value="<?=htmlspecialchars($f)?>" class="checkbox file-check" onclick="event.stopPropagation()"></td>
                <td></td>
                <td><?=($isZip?'📦':'📄').' '.htmlspecialchars($f)?></td>
                <td class="perms-cell" onclick="event.stopPropagation();showChmodModal('<?=addslashes($f)?>','<?=addslashes(trim($p.'/'.$f,'/'))?>',this)" title="点击修改权限"><?=fm_get_perms($current_path.'/'.$f)?></td><td><?=strtoupper($ext)?></td><td><?=fmtsize(filesize($current_path.'/'.$f))?></td><td><?=date('Y-m-d H:i',filemtime($current_path.'/'.$f))?></td><td class="share-cell"><a href="<?=htmlspecialchars($share_base.'/'.trim($p.'/'.$f,'/'))?>" target="_blank" title="打开文件">🔗</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if(empty($folders)&&empty($files)&&$parent===false): ?><div class="empty">空文件夹 - 拖拽文件上传</div><?php endif; ?>
        </div>
    </form>
    <div class="footer"><?=count($folders)?> 文件夹 · <?=count($files)?> 文件</div>
</div>
</div><!-- end main-area -->

<!-- 权限修改模态框 -->
<div class="modal" id="chmodModal" aria-modal="true" role="dialog" aria-labelledby="chmodTitle">
  <div class="modal-backdrop" onclick="hideModal('chmodModal')"></div>
  <div class="modal-dialog" style="max-width:400px;width:100%" role="document">
    <div class="modal-header" id="chmodTitle">🔒 修改权限</div>
    <div class="modal-body" style="padding:20px">
      <div style="margin-bottom:12px;font-size:14px;color:var(--text)">文件: <strong id="chmodName"></strong></div>
      <div style="margin-bottom:16px">
        <label style="display:block;margin-bottom:6px;font-size:13px;color:var(--muted)">权限值(3或4位八进制数)</label>
        <input type="text" id="chmodInput" class="form-control" placeholder="如 755 或 0644" maxlength="4" style="font-family:'Courier New',monospace;font-size:14px">
        <div style="margin-top:6px;font-size:11px;color:var(--muted)">常见值: 755(可执行) · 644(普通文件) · 700(私有目录)</div>
      </div>
    </div>
    <div class="modal-footer" style="padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end">
      <button class="btn" onclick="hideModal('chmodModal')">取消</button>
      <button class="btn btn-primary" onclick="applyChmod()">应用</button>
    </div>
  </div>
</div>

<!-- 图片预览模态框 -->
<div class="modal" id="imagePreviewModal">
  <div class="modal-backdrop" onclick="hideImagePreview()"></div>
  <div class="modal-dialog" style="max-width:90%;max-height:90%;background:transparent;box-shadow:none">
    <div style="display:flex;align-items:center;justify-content:center;height:100%;position:relative">
      <img id="imagePreviewImg" src="" style="max-width:100%;max-height:90vh;object-fit:contain;border-radius:4px">
      <button id="imagePrevBtn" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,0.6);border:none;color:#fff;font-size:22px;cursor:pointer;padding:12px 16px;border-radius:4px" onclick="navigateImage(-1)">◀</button><button id="imageNextBtn" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,0.6);border:none;color:#fff;font-size:22px;cursor:pointer;padding:12px 16px;border-radius:4px" onclick="navigateImage(1)">▶</button><div id="imageCounter" style="position:absolute;bottom:15px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.7);color:#fff;padding:6px 14px;border-radius:4px;font-size:14px"></div>
    </div>
  </div>
</div>

<!-- 视频预览模态框 -->
<div class="modal" id="videoPreviewModal">
  <div class="modal-backdrop" onclick="hideVideoPreview()"></div>
  <div class="modal-dialog" style="max-width:90%;max-height:90%;background:transparent;box-shadow:none">
    <div style="display:flex;align-items:center;justify-content:center;height:100%;position:relative">
      <video id="videoPreviewPlayer" controls style="max-width:100%;max-height:90vh;border-radius:4px;background:#000"></video>
      <button style="position:absolute;top:15px;right:15px;background:rgba(0,0,0,0.6);border:none;color:#fff;font-size:24px;cursor:pointer;padding:8px 12px;border-radius:4px" onclick="hideVideoPreview()">✕</button>
    </div>
  </div>
</div>
<div class="modal" id="uploadModal"><div class="modal-content">
    <div class="modal-header">📤 <?=lng('Upload')?></div>
    <form method="post" enctype="multipart/form-data" id="uploadForm"><input type="hidden" name="token" value="<?=$_SESSION['token']?>">
    <div class="modal-body"><input type="file" name="file" id="uploadFileInput" class="form-control" multiple required><div style="margin-top:10px;color:var(--muted);font-size:12px">支持多文件上传 · 支持拖拽</div></div>
    <div class="modal-footer"><button type="button" class="btn" onclick="hideModal('uploadModal')"><?=lng('Cancel')?></button><button type="button" class="btn btn-primary" onclick="const f=document.getElementById('uploadFileInput').files;if(f.length){const a=[];for(let i=0;i<f.length;i++)a.push({file:f[i],path:''});uploadFiles(a);hideModal('uploadModal')}"><?=lng('Upload')?></button></div>
    </form>
</div></div>

<!-- 回收站浮窗 -->
<div class="trash-overlay" id="trashOverlay" onclick="hideTrashPanel()"></div>
<div class="trash-panel" id="trashPanel">
    <div class="trash-panel-header"><h2>🗑️ 回收站</h2><button class="trash-panel-close" onclick="hideTrashPanel()">✕</button></div>
    <div class="trash-panel-toolbar"><button class="btn btn-danger" onclick="emptyTrash()">🗑️ 清空回收站</button></div>
    <div class="trash-panel-content" id="trashPanelContent"><div class="trash-empty">加载中...</div></div>
    <div class="trash-panel-footer" id="trashPanelFooter">共 0 项</div>
</div>
<?php endif; ?>

<div id="aceModal">
    <div class="modal-backdrop" onclick="aceClose()"></div>
    <div class="ace-wrap">
        <div class="ace-resize-handle" id="aceResizeHandle"></div>
        <!-- 标题栏 -->
        <div id="aceEditorHeader">
            <span class="ace-path" id="aceFullPath">-</span>
            <span class="ace-lang" id="aceLang">-</span>
            <button class="ace-close-btn" onclick="aceClose()">✕</button>
        </div>
        <!-- 工具栏 -->
        <div id="aceEditorToolbar">
            <button class="ace-text-btn ace-btn-primary" onclick="aceSave()">保存</button>
            <button class="ace-text-btn" onclick="aceSaveAs()">另存为</button>
            <span class="ace-sep"></span>
            <button class="ace-text-btn" onclick="aceUndo()">撤销</button>
            <button class="ace-text-btn" onclick="aceRedo()">重做</button>
            <span class="ace-sep"></span>
            <button class="ace-text-btn" onclick="aceFind()">查找</button>
            <button class="ace-text-btn" onclick="aceReplace()">替换</button>
            <span class="ace-sep"></span>
            <button class="ace-text-btn" onclick="aceBeautify()">格式化</button>
            <button class="ace-text-btn" onclick="aceToggleWrap()">换行</button>
            <span class="ace-sep"></span>
            <div class="ace-dropdown" id="aceEncodingMenu">
                <button class="ace-text-btn" onclick="toggleEncodingMenu()">编码</button>
                <div class="ace-dropdown-content">
                    <div class="ace-dropdown-group">
                        <div class="ace-dropdown-label">用编码打开</div>
                        <div class="ace-dropdown-item" onclick="aceReopenWithEncoding('UTF-8')">UTF-8</div>
                        <div class="ace-dropdown-item" onclick="aceReopenWithEncoding('GBK')">GBK</div>
                        <div class="ace-dropdown-item" onclick="aceReopenWithEncoding('GB2312')">GB2312</div>
                        <div class="ace-dropdown-item" onclick="aceReopenWithEncoding('BIG5')">BIG5</div>
                        <div class="ace-dropdown-item" onclick="aceReopenWithEncoding('ISO-8859-1')">ISO-8859-1</div>
                    </div>
                    <div class="ace-dropdown-divider"></div>
                    <div class="ace-dropdown-group">
                        <div class="ace-dropdown-label">转换为编码</div>
                        <div class="ace-dropdown-item" onclick="aceConvertToEncoding('UTF-8')">UTF-8</div>
                        <div class="ace-dropdown-item" onclick="aceConvertToEncoding('GBK')">GBK</div>
                        <div class="ace-dropdown-item" onclick="aceConvertToEncoding('GB2312')">GB2312</div>
                    </div>
                </div>
            </div>
        </div>
        <!-- 编辑器主区域 -->
        <div id="aceMainArea">
            <!-- 编辑器 -->
            <div id="aceEditorArea"><pre id="aceEditor"></pre></div>
            <!-- 函数列表侧边栏 -->
            <div id="aceFunctionPanel" class="ace-panel-collapsed">
                <div class="ace-panel-header" onclick="toggleFunctionPanel()">
                    <span>函数列表</span>
                    <span class="ace-panel-toggle">◀</span>
                </div>
                <div id="aceFunctionList"></div>
            </div>
        </div>
        <!-- 底部状态栏 -->
        <div id="aceStatusBar">
            <span id="aceCursorPos">1:1</span>
            <span id="aceFileInfo">UTF-8</span>
            <span id="aceTabSize">Tab: 4</span>
        </div>
    </div>
</div>

<!-- 右键菜单 -->
<div class="context-menu" id="ctx">
  <div class="context-menu-item" id="ctxDl" onclick="ctxTarget&&startDownload(ctxTarget)">⬇️ 下载</div>
  <div class="context-menu-item" id="ctxEdit" onclick="ctxTarget&&ctxExt&&aceOpen(currentPath,ctxTarget)">✏️ 编辑</div>
  <div class="context-menu-divider"></div>
  <div class="context-menu-item" onclick="ctxTarget&&startRename(ctxTarget)">✏️ 重命名</div>
  <div class="context-menu-item" onclick="(getNames().length||ctxTarget)&&setClip({mode:'copy',files:getNames().length?getNames():[ctxTarget],from:location.search})">📋 复制</div>
  <div class="context-menu-item" onclick="(getNames().length||ctxTarget)&&setClip({mode:'cut',files:getNames().length?getNames():[ctxTarget],from:location.search})">✂️ 剪切</div>
  <div class="context-menu-item paste-item" style="display:none" onclick="pasteTo('')">📋 粘贴</div>
  <div class="context-menu-item paste-folder-item" style="display:none" onclick="ctxTarget&&pasteTo(ctxTarget)">📋 粘贴到此文件夹</div>
  <div class="context-menu-divider"></div>
  <div class="context-menu-item" id="ctxStar" onclick="ctxTarget&&(hideCtx(),toggleStar(currentPath+'/'+ctxTarget,null))">⭐ 收藏</div>
  <div class="context-menu-item has-submenu" onmouseenter="showPackSub(this)" onmouseleave="hidePackSub(this)" onclick="event.stopPropagation();togglePackSub(this)">📦 创建压缩包 ›
    <div class="context-submenu">
      <div class="context-menu-item submenu-item" onclick="ctxTarget&&(hideCtx(),packFiles('zip'))">📄 ZIP 文件</div>
      <div class="context-menu-item submenu-item" onclick="ctxTarget&&(hideCtx(),packFiles('tar'))">📄 TAR 文件</div>
      <div class="context-menu-item submenu-item" onclick="ctxTarget&&(hideCtx(),packFiles('gzip'))">📄 GZIP 文件</div>
      <div class="context-menu-item submenu-item" onclick="ctxTarget&&(hideCtx(),packFiles('7z'))">📦 7Z 文件</div>
      <div class="context-menu-item submenu-item" onclick="ctxTarget&&(hideCtx(),packFiles('rar'))">📦 RAR 文件</div>
    </div>
  </div>
  <div class="context-menu-divider"></div>
  <div class="context-menu-item has-submenu" id="ctxUnpack" style="display:none" onmouseenter="showPackSub(this)" onmouseleave="hidePackSub(this)" onclick="event.stopPropagation();togglePackSub(this)">📦 解压 ›
    <div class="context-submenu">
      <div class="context-menu-item submenu-item" onclick="ctxTarget&&(hideCtx(),unpackFile(ctxTarget,'current'))">📂 解压到当前</div>
      <div class="context-menu-item submenu-item" onclick="ctxTarget&&(hideCtx(),showUnpackToModal(ctxTarget))">📁 解压到文件夹</div>
      <div class="context-menu-item submenu-item" onclick="ctxTarget&&(hideCtx(),showUnpackDirModal(ctxTarget))">📁 解压到...</div>
    </div>
  </div>
  <div class="context-menu-item danger" onclick="ctxTarget&&ajaxDeleteSingle(ctxTarget)">🗑️ 删除</div>
</div>
<div class="context-menu" id="ctxBlank">
  <div class="context-menu-item" onclick="showModal('uploadModal')">📤 上传</div>
  <div class="context-menu-item paste-item" style="display:none" onclick="pasteTo('')">📋 粘贴</div>
  <div class="context-menu-item" onclick="startNew('folder')">📁 新建文件夹</div>
  <div class="context-menu-item" onclick="startNew('file')">📄 新建文件</div>
  <div class="context-menu-divider"></div>
</div>

<!-- 搜索模态框 -->
<div class="modal" id="searchModal">
  <div class="modal-backdrop" onclick="hideModal('searchModal')"></div>
  <div class="modal-dialog" style="max-width:560px;width:100%">
    <div class="modal-header" style="display:flex;align-items:center;gap:8px">
      <span style="font-size:18px">🔍</span>
      <span style="font-weight:600;font-size:14px">搜索文件</span>
    </div>
    <div class="modal-body" style="padding:16px 20px">
      <div style="display:flex;gap:8px;margin-bottom:12px">
        <input type="text" id="searchKeywordInput" class="form-control" placeholder="输入文件名关键词..." style="flex:1" onkeydown="if(event.key==='Enter')startSearch()">
        <button class="btn btn-primary" onclick="startSearch()">🔍 搜索</button>
      </div>
      <div style="display:flex;align-items:center;gap:16px;font-size:12px;color:var(--muted)">
        <label style="display:flex;align-items:center;gap:4px;cursor:pointer">
          <input type="checkbox" id="searchDeep" style="accent-color:var(--primary)">
          包含子目录
        </label>
        <span id="searchPath">当前目录: /</span>
      </div>
    </div>
    <div id="searchResults" style="max-height:320px;overflow-y:auto;border-top:1px solid var(--border);display:none">
      <div id="searchResultsList"></div>
    </div>
    <div class="modal-footer" style="padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:space-between">
      <span id="searchStatus" style="font-size:12px;color:var(--muted)"></span>
      <button class="btn" onclick="hideModal('searchModal')">关闭</button>
    </div>
  </div>
</div>

<!-- 离线下载模态框 -->
<div class="modal" id="offlineModal"><div class="modal-content">
    <div class="modal-header">🌐 离线下载</div>
    <form method="post" id="offlineForm"><input type="hidden" name="token" value="<?=$_SESSION['token']?>">
    <div class="modal-body">
        <input type="text" name="offline_url" id="offlineUrlInput" class="form-control" placeholder="输入下载链接 (HTTP/HTTPS/FTP)" required>
        <div style="margin-top:10px;color:var(--muted);font-size:12px">支持直链下载 · 自动保存到当前目录</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn" onclick="hideModal('offlineModal')"><?=lng('Cancel')?></button><button type="button" class="btn btn-primary" onclick="startOfflineDownload()">开始下载</button></div>
    </form>
</div></div>

<!-- 解压到文件夹模态框 -->
<!-- 另存为模态框 -->
<div class="modal" id="saveAsModal" style="z-index:3500">
  <div class="modal-backdrop" onclick="hideModal('saveAsModal')"></div>
  <div class="ace-wrap" style="position:absolute;background:#1e1e1e;border-radius:8px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 12px 40px rgba(0,0,0,.5);border:1px solid #3c3c3c;width:600px;height:420px;top:50%;left:50%;transform:translate(-50%,-50%)">
    <!-- 标题栏 -->
    <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#252526;border-bottom:1px solid #3c3c3c">
      <span style="font-size:12px;color:#ccc" id="saveAsTitle">另存为</span>
      <span style="margin-left:auto;cursor:pointer;font-size:14px;color:#888;line-height:1" onclick="hideModal('saveAsModal')" title="关闭">✕</span>
    </div>
    <!-- 地址栏 -->
    <div style="display:flex;align-items:center;gap:4px;padding:6px 10px;background:#1e1e1e;border-bottom:1px solid #3c3c3c">
      <button onclick="loadSaveAsDirList('')" title="根目录" style="background:#333;border:1px solid #3c3c3c;font-size:11px;cursor:pointer;padding:2px 8px;border-radius:2px;color:#ccc">⬅</button>
      <button onclick="var p=_saveAsState.currentPath;if(p){var parts=p.split('/');parts.pop();loadSaveAsDirList(parts.join('/'))}" title="上级" style="background:#333;border:1px solid #3c3c3c;font-size:11px;cursor:pointer;padding:2px 8px;border-radius:2px;color:#ccc">➡</button>
      <button onclick="loadSaveAsDirList(_saveAsState.currentPath)" title="刷新" style="background:#333;border:1px solid #3c3c3c;font-size:11px;cursor:pointer;padding:2px 8px;border-radius:2px;color:#ccc">🔄</button>
      <input type="text" id="saveAsBreadcrumb" style="flex:1;background:#1e1e1e;border:1px solid #3c3c3c;outline:none;color:#ccc;font-size:11px;font-family:monospace;padding:3px 6px;border-radius:2px" placeholder="/" onkeydown="if(event.key==='Enter'){event.preventDefault();jumpSaveAsInputPath()}">
    </div>
    <div style="display:flex;flex:1;min-height:280px">
      <!-- 左侧边栏 -->
      <div style="width:140px;border-right:1px solid #3c3c3c;padding:6px 0;background:#252526">
        <div style="padding:4px 10px;font-size:10px;color:#666;font-weight:600;text-transform:uppercase">快速访问</div>
        <div id="saveAsSidebarRoot" onclick="loadSaveAsDirList('')" style="padding:4px 10px;cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;color:#ccc" onmouseover="this.style.background='#2d2d2d'" onmouseout="this.style.background='transparent'"><span>📁</span> 根目录</div>
        <div id="saveAsSidebarCurrent" onclick="loadSaveAsDirList(this.dataset.path)" style="padding:4px 10px;cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;color:#888;display:none" data-path="" title="当前页面"><span>📍</span> <span id="saveAsCurrentName">当前</span></div>
      </div>
      <!-- 右侧文件列表 -->
      <div style="flex:1;display:flex;flex-direction:column">
        <!-- 列表内容 -->
        <div id="saveAsDirList" style="flex:1;overflow-y:auto;padding:2px 0;background:#1e1e1e">
          <div style="padding:16px;text-align:center;color:#666;font-size:12px">加载中...</div>
        </div>
      </div>
    </div>
    <!-- 底部栏 -->
    <div style="padding:8px 12px;border-top:1px solid #3c3c3c;display:flex;align-items:center;gap:8px;background:#252526">
      <label style="font-size:11px;color:#888;white-space:nowrap">文件名:</label>
      <input type="text" id="saveAsFileNameInput" style="flex:1;height:24px;font-size:11px;padding:0 6px;border:1px solid #3c3c3c;border-radius:2px;background:#1e1e1e;color:#ccc" placeholder="输入文件名">
      <button onclick="startSaveAs()" style="min-width:50px;font-size:11px;background:#0e639c;border:none;color:#fff;padding:4px 12px;border-radius:2px;cursor:pointer" id="saveAsConfirmBtn">保存</button>
      <button onclick="hideModal('saveAsModal')" style="min-width:50px;font-size:11px;background:#333;border:1px solid #3c3c3c;color:#ccc;padding:4px 12px;border-radius:2px;cursor:pointer">取消</button>
    </div>
  </div>
</div>

<div class="modal" id="unpackToModal">
  <div class="modal-backdrop" onclick="hideModal('unpackToModal')"></div>
  <div class="modal-dialog" style="max-width:380px">
    <div class="modal-header" style="display:flex;align-items:center;gap:8px">
      <span style="font-size:18px">📦</span>
      <span style="font-weight:600;font-size:14px">解压到文件夹</span>
    </div>
    <div class="modal-body" style="padding:20px 24px">
      <input type="hidden" id="unpackArchiveInput">
      <div style="font-size:13px;color:var(--muted);margin-bottom:8px">目标文件夹</div>
      <div style="position:relative">
        <div style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none">📁</div>
        <input type="text" id="unpackTargetInput" class="form-control" placeholder="" style="padding-left:36px;height:40px;font-size:14px">
      </div>
      <div id="unpackPreview" style="margin-top:10px;font-size:12px;color:var(--muted);display:flex;align-items:center;gap:4px">
        <span style="opacity:0.6">→</span><span id="unpackPreviewPath" style="word-break:break-all"></span>
      </div>
    </div>
    <div class="modal-footer" style="padding:12px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end">
      <button class="btn" onclick="hideModal('unpackToModal')" style="min-width:70px">取消</button>
      <button class="btn btn-primary" onclick="startUnpackTo()" style="min-width:70px">确认</button>
    </div>
  </div>
</div>

<!-- 解压到...目录树选择模态框 -->
<div class="modal" id="unpackDirModal">
  <div class="modal-backdrop" onclick="hideModal('unpackDirModal')"></div>
  <div class="modal-dialog" style="max-width:460px;width:100%">
    <div class="modal-header" style="display:flex;align-items:center;gap:8px">
      <span style="font-size:18px">📦</span>
      <span style="font-weight:600;font-size:14px">解压到...</span>
      <span id="unpackDirArchive" style="margin-left:8px;font-size:12px;color:var(--muted);font-weight:normal;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
    </div>
    <div class="modal-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:8px">
      <input type="hidden" id="unpackDirArchiveInput">
      <!-- 路径导航(可输入) -->
      <div style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:var(--hover);border-radius:6px;font-size:13px">
        <span style="color:var(--muted);flex-shrink:0">📂</span>
        <input type="text" id="unpackDirBreadcrumb" style="flex:1;background:transparent;border:none;outline:none;color:var(--text);font-size:13px;font-family:monospace" placeholder="/" onkeydown="if(event.key==='Enter'){event.preventDefault();jumpUnpackInputPath()}">
      </div>
      <!-- 文件夹列表 -->
      <div id="unpackDirList" style="min-height:200px;max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:6px;background:var(--card)">
        <div style="padding:16px;text-align:center;color:var(--muted);font-size:13px">加载中...</div>
      </div>
    </div>
    <div class="modal-footer" style="padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end">
      <span id="unpackDirSelected" style="margin-right:auto;font-size:12px;color:var(--muted);display:none">已选:<span id="unpackDirSelectedPath" style="color:var(--text)"></span></span>
      <button class="btn" onclick="hideModal('unpackDirModal')" style="min-width:70px">取消</button>
      <button class="btn btn-primary" onclick="startUnpackDir()" style="min-width:70px" disabled id="unpackDirConfirmBtn">确认</button>
    </div>
  </div>
</div>

<!-- 文件夹上传隐藏输入 -->
<input type="file" id="uploadFolderInput" webkitdirectory directory multiple style="display:none" onchange="const f=this.files;if(f.length){const a=[];for(let i=0;i<f.length;i++)a.push({file:f[i],path:f[i].webkitRelativePath||f[i].name});uploadFiles(a)}">

<!-- Mobile Module (独立手机端模块 · 不影响登录界面和桌面端) -->
<?php if($is_logged): ?>
<style>
/* ========== Mobile Styles ========== */
/* 默认：手机端元素完全脱流隐藏（桌面端不受影响） */
.mobile-breadcrumb,
.mobile-menu-overlay,
.mobile-menu-panel {
  display: none !important;
  pointer-events: none !important;
}

@media (max-width: 768px) {
  /* 右下角浮动操作按钮 */
  .mobile-fab {
    display: flex !important;
    pointer-events: auto !important;
    position: fixed; bottom: 60px; right: 20px;
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--primary); color: #fff; font-size: 24px;
    border: none; cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    z-index: 1001;
    align-items: center; justify-content: center;
    transition: transform 0.3s, box-shadow 0.3s;
  }
  .mobile-fab:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.4); }
  .mobile-fab.active { transform: rotate(45deg); }
  .mobile-fab:active { transform: scale(0.9); }

  /* ---------- 文件列表：卡片式每行 ---------- */
  .table-container { overflow-x: hidden; }

  /* 隐藏表头 */
  .table-container thead { display: none; }

  /* 每行 = 灵活行，允许换行 */
  .table-container tbody tr {
    display: flex !important;
    flex-wrap: wrap;  /* 允许换行 */
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    gap: 10px;  /* 水平间距 */
    row-gap: 4px;  /* 垂直间距（两行之间） */
    min-height: 44px;
  }
  .table-container tbody tr:hover { background: var(--hover); }
  .table-container tbody tr td {
    padding: 0 !important;
    border: none !important;
    vertical-align: middle;
  }

  /* 隐藏复选框列、收藏列、权限列、类型列、分享列 */
  .table-container tbody tr td:nth-child(1),  /* 复选框 */
  .table-container tbody tr td:nth-child(2),  /* 收藏 */
  .table-container tbody tr td:nth-child(4),  /* 权限 */
  .table-container tbody tr td:nth-child(5),  /* 类型 */
  .table-container tbody tr td:nth-child(8) {  /* 分享链接 */
    display: none !important;
  }

  /* 第3列：文件名（占满整行，强制换行） */
  .table-container tbody tr td:nth-child(3) {
    display: block !important;
    flex: 1 1 100%;  /* 占满整行，强制换行 */
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
    color: var(--text);
  }

  /* 第7列：修改时间（第二行，左侧） */
  .table-container tbody tr td:nth-child(7) {
    display: block !important;
    font-size: 11px;
    color: var(--muted);
    flex: 0 0 auto;
    order: 1;
  }

  /* 第6列：文件大小（第二行，右侧） */
  .table-container tbody tr td:nth-child(6) {
    display: block !important;
    font-size: 11px;
    color: var(--muted);
    flex: 0 0 auto;
    order: 2;
    margin-left: auto;  /* 推到右侧 */
  }

  /* ---------- 手机端菜单遮罩（透明，仅用于点击关闭） ---------- */
  .mobile-menu-overlay {
    display: none;
    position: fixed; inset: 0;
    background: transparent;
    z-index: 1002;
  }
  .mobile-menu-overlay.show { display: block; }

  /* ---------- 卡片式弹出菜单 ---------- */
  .mobile-menu-panel {
    display: none;
    position: fixed;
    bottom: 130px;  /* 在FAB按钮上方 */
    right: 20px;
    width: 200px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.2);
    padding: 8px;
    z-index: 1003;
    animation: fabMenuIn 0.2s ease;
  }
  .mobile-menu-panel.show { display: block !important; pointer-events: auto !important; }

  @keyframes fabMenuIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .mobile-menu-item {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; cursor: pointer;
    font-size: 14px; color: var(--text);
    border-radius: 8px;
    transition: background 0.15s;
    white-space: nowrap;
  }
  .mobile-menu-item:hover { background: var(--hover); }
  .mobile-menu-item .menu-icon { font-size: 18px; width: 24px; text-align: center; }
  .mobile-menu-divider {
    height: 1px;
    background: var(--border);
    margin: 4px 8px;
  }

  /* 移动端隐藏侧边栏底部按钮 */
  .sidebar-footer {
    display: none !important;
  }
}
</style>

<!-- 右下角浮动操作按钮 -->
<button class="mobile-fab" id="mobileFab" onclick="toggleMobileMenu()" title="打开菜单">＋</button>

<!-- 手机端菜单遮罩（透明，点击关闭） -->
<div class="mobile-menu-overlay" id="mobileMenuOverlay" onclick="toggleMobileMenu()"></div>

<!-- 卡片式弹出菜单 -->
<div class="mobile-menu-panel" id="mobileMenuPanel">
  <div class="mobile-menu-item" onclick="showModal('uploadModal');toggleMobileMenu()"><span class="menu-icon">📤</span>上传文件</div>
  <div class="mobile-menu-item" onclick="document.getElementById('uploadFolderInput').click();toggleMobileMenu()"><span class="menu-icon">📂</span>上传文件夹</div>
  <div class="mobile-menu-item" onclick="showModal('offlineModal');toggleMobileMenu()"><span class="menu-icon">🌐</span>离线下载</div>
  <div class="mobile-menu-divider"></div>
  <div class="mobile-menu-item" onclick="startNew('folder');toggleMobileMenu()"><span class="menu-icon">📁</span>新建文件夹</div>
  <div class="mobile-menu-item" onclick="startNew('file');toggleMobileMenu()"><span class="menu-icon">📄</span>新建文件</div>
  <div class="mobile-menu-item paste-item" style="display:none" onclick="pasteTo('');toggleMobileMenu()"><span class="menu-icon">📋</span>粘贴</div>
  <div class="mobile-menu-divider"></div>
  <div class="mobile-menu-item" onclick="refreshFileList();toggleMobileMenu()"><span class="menu-icon">🔄</span>刷新</div>
  <div class="mobile-menu-item" onclick="toggleHidden();toggleMobileMenu()"><span class="menu-icon">👁️</span>显示/隐藏文件</div>
  <div class="mobile-menu-item" onclick="showSearchModal();toggleMobileMenu()"><span class="menu-icon">🔍</span>搜索</div>
</div>

<script>
  // PHP 变量传递给 JavaScript
  var currentPath = '<?=addslashes($p)?>';
  var stars = <?=json_encode($stars)?>;
  var token = document.querySelector('input[name="token"]')?.value || '';
</script>
<script src="editor.js"></script>
<script src="main.js"></script>
<script src="kebab-case.js"></script>
<?php endif; ?>

</body>
</html>

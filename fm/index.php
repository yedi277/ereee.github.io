<?php
/**
 * 文件管理器 index.php
 * 单文件 PHP 文件管理器,集成 Ace 代码编辑器
 * 功能:浏览/上传/下载/编辑/重命名/删除/复制/剪切/粘贴/压缩/解压/收藏/回收站/离线下载
 *
 * ⚠️ 注意事项:
 * - 所有 AJAX 操作必须携带 token 参数(CSRF 防护)
 * - fm_is_safe_path 防止路径遍历攻击
 * - $forbidden_extensions 禁止上传可执行文件
 * - 密码使用 Argon2i 哈希存储,禁止明文
 * - refreshFileList() 响应含 'login' 或长度<1000 时触发 location.reload()
 * - aceSave/aceSaveAs 保存期间 window._aceSaving 标志阻止编辑器关闭
 * - 面包屑拖拽移动发送 dst_abs=1(绝对路径模式)
 * - 同目录复制粘贴自动生成 "- 副本" 后缀
 */

// 应用标题
define('APP_TITLE', '文件管理器');

// 文件管理器所在路径(部署在子目录时填写,如 /fm ,末尾不带斜杠。留空表示在根目录)分享链接时使用.
$app_path = '/fm';

// 分享链接基础URL(留空则自动检测,格式如 http://your-domain.com/)
$base_url = '';

// 认证凭据(修改这里的用户名和密码)
// 生成 bcrypt 哈希: password_hash('your_password', PASSWORD_BCRYPT)
$auth_user = 'admin';
$auth_pass = '$argon2i$v=19$m=65536,t=4,p=1$Li9LSVdkL29PTGF0enpGQg$Sb9c3Eu6eu2w6UCS2XDIGs/KVVQ95uwV7nZU/sKIHWA'; // admin:$2y$10$m7JT3rdrZ3nK4tKxpJ8z5.pDalU75BZH9nu82zBJkVNhLJgoC6uXm

// 文件操作根目录(限制文件管理器只能在此目录下操作)
$root_path = $_SERVER['DOCUMENT_ROOT'];

// 最大上传文件大小(字节),默认 5GB
$max_upload_size = 5000000000;

// 登录安全设置
$login_max_attempts = 5;  // 最大失败尝试次数
$login_lockout_time = 900; // 锁定时间(秒),默认 15 分钟

// 禁止上传的文件扩展名
$forbidden_extensions = [
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
    $lower_name = strtolower(basename($archive_path));
    if (preg_match('/\.tar\.(gz|bz2|xz)$/', $lower_name, $m)) {
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
        $escaped_target = escapeshellarg($target_dir);
        $escaped_archive = escapeshellarg($archive_path);
        @set_time_limit(300);
        $output = shell_exec("$cmd x -y -o$escaped_target $escaped_archive 2>&1");
        if (!fm_is_safe_path($target_dir, $root_path)) {
            return ['ok' => false, 'error' => '安全警告:解压路径异常'];
        }
        return ['ok' => true, 'error' => '', 'output' => trim($output ?: '')];
    }

    // RAR
    if (in_array($ext, ['rar'])) {
        $cmd = fm_find_executable(['unrar', 'rar']);
        if (!$cmd) return ['ok' => false, 'error' => '服务器未安装 unrar/rar,无法解压 RAR 文件'];
        $is_unrar = basename($cmd) === 'unrar';
        $escaped_target = escapeshellarg($target_dir);
        $escaped_archive = escapeshellarg($archive_path);
        @set_time_limit(300);
        $output = shell_exec("$cmd x -y -o+ $escaped_archive $escaped_target 2>&1");
        if (!fm_is_safe_path($target_dir, $root_path)) {
            return ['ok' => false, 'error' => '安全警告:解压路径异常'];
        }
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
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, 3), 2) as $i)
        $i->isDir() ? @rmdir($i) : @unlink($i);
    @rmdir($dir);
}

function fm_copy($src, $dst, $root = null) {
    global $root_path;
    $root = $root ?: $root_path;
    if (!fm_is_safe_path($src, $root) || !fm_is_safe_path($dst, $root)) return false;
    if (is_file($src)) return copy($src, $dst);
    @mkdir($dst, 0755, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, 3), 1) as $i) {
        $t = $dst . substr($i, strlen($src));
        if (!fm_is_safe_path($t, $root)) continue;
        $i->isDir() ? @mkdir($t, 0755, true) : copy($i, $t);
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
    $p = fm_clean($_GET['p'] ?? '');
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
            file_put_contents($mf, json_encode($meta, 448));
        }
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
        if ($rel) @mkdir(dirname($target), 0755, true);
        $upload_success = move_uploaded_file($f['tmp_name'], $target) && $f['size'] <= $max_upload_size;
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

// AJAX: 获取目录列表
if ($is_logged && isset($_GET['ajax']) && $_GET['ajax'] === 'dirs') {
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
<meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
<meta http-equiv="X-XSS-Protection" content="1; mode=block">
<title><?=APP_TITLE?></title>
<style>:root{--bg:#f5f5f7;--card:#ffffff;--border:#d1d1d6;--text:#1d1d1f;--muted:#86868b;--primary:#007aff;--danger:#ff3b30;--success:#34c759;--hover:rgba(0,0,0,.05);--sel:rgba(0,122,255,.15);--sidebar-bg:rgba(255,255,255,.8);--sidebar-width:9%;--star-color:#ff9f0a;--shadow:0 8px 32px rgba(0,0,0,.1);--radius:6px;font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,sans-serif;--ace-bg:#1e1e1e;--ace-header:#1e1e1e;--ace-toolbar:#252526;--ace-border:#3c3c3c;--ace-hover:#2d2d2d;--ace-sel:#094771;--ace-text:#ccc;--ace-muted:#888}
:root.dark{--bg:#1c1c1e;--card:#2c2c2e;--border:#3a3a3c;--text:#f5f5f7;--muted:#98989d;--hover:rgba(255,255,255,.08);--sel:rgba(0,122,255,.25);--sidebar-bg:rgba(44,44,46,.8);--shadow:0 8px 32px rgba(0,0,0,.4);--star-color:#ff9f0a}
*{margin:0;padding:0;box-sizing:border-box}
body:not(.logged){background:linear-gradient(135deg,#e8eaf0 0%,#dfe0e8 100%);min-height:100vh}
body.logged{font:13px -apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}a{color:var(--primary);text-decoration:none}
.folder-link,.sidebar a{color:var(--text);text-decoration:none;font-weight:500}
.folder-link:hover,.sidebar a:hover{color:var(--primary)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:5px 12px;border:none;border-radius:var(--radius);cursor:pointer;font-size:13px;background:var(--hover);color:var(--text);transition:all .15s;font-weight:500}
.btn-icon{padding:6px;min-width:0}
.btn:hover{background:var(--border);color:var(--text);transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:#0066d8;box-shadow:0 4px 12px rgba(0,122,255,.3)}
.btn-danger{background:var(--danger);color:#fff}
.alert{padding:10px 16px;border-radius:8px;margin:8px 12px;font-size:13px;font-weight:500}
.alert-success{background:rgba(48,209,88,.12);border:1px solid rgba(48,209,88,.3);color:var(--success)}
.alert-error{background:rgba(255,69,58,.12);border:1px solid rgba(255,69,58,.3);color:var(--danger)}
.form-control{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:14px;transition:all .15s}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(129,140,248,.2)}
.login-box{width:320px;position:fixed;top:45%;left:50%;transform:translate(-50%,-50%);background:var(--card);padding:32px;border-radius:16px;box-shadow:var(--shadow);border:1px solid var(--border)}
.login-box h2{text-align:center;margin-bottom:24px;font-size:20px;font-weight:600;color:var(--text)}
.sidebar{width:var(--sidebar-width);min-width:180px;background:var(--sidebar-bg);border-right:1px solid var(--border);display:flex;flex-direction:column;height:100vh;position:sticky;top:0;overflow:hidden;backdrop-filter:blur(20px)}
.sidebar-scroll{flex:1;overflow-y:auto;overflow-x:hidden}
.sidebar-header{padding:12px 14px 8px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border)}
.sidebar-section{padding:8px 0}
.sidebar-title{font-size:11px;text-transform:uppercase;color:var(--muted);padding:6px 16px 4px;font-weight:600;letter-spacing:.5px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:4px}
.sidebar-title .arrow{transition:transform .15s;font-size:10px}
.sidebar-title.collapsed .arrow{transform:rotate(-90deg)}
.sidebar-list{list-style:none}
.sidebar-item{display:flex;align-items:center;gap:6px;padding:1px 2px 1px 22px;cursor:pointer;font-size:13px;color:var(--muted);transition:all .12s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-radius:6px;margin:0 2px;width:calc(100% - 4px)}
.sidebar-item:hover{background:var(--hover);color:var(--text);padding-left:28px}
.sidebar-item.active{background:rgba(10,132,255,.15);color:var(--primary);font-weight:500}
.sidebar-item .item-icon{font-size:14px;flex-shrink:0}
.sidebar-item .item-name{overflow:hidden;text-overflow:ellipsis}
.sidebar-item .star-btn{margin-left:auto;cursor:pointer;font-size:13px;opacity:.4;transition:all .15s;flex-shrink:0;padding:0 2px}
.sidebar-item .star-btn:hover{opacity:1;transform:scale(1.2)}
.sidebar-item .star-btn.starred{opacity:1;color:var(--star-color)}
.main-area{flex:1;display:flex;flex-direction:column;height:100vh;min-width:0;overflow:hidden;background:rgba(0,0,0,.25)}
.header{display:flex;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border);gap:12px;position:sticky;top:0;z-index:100;background:var(--bg)}
.header h1{font-size:16px;font-weight:600}
.header-actions{display:flex;gap:6px}
.breadcrumb{padding:8px 16px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);position:sticky;top:45px;z-index:90;background:var(--bg)}
.breadcrumb a{color:var(--muted);padding:2px 6px;border-radius:4px;transition:all .1s}
.breadcrumb a.drag-over{background:var(--primary)!important;color:#fff!important}
.addr-star-btn:hover{opacity:1!important}
.addr-star-btn.starred{color:#f5a623;opacity:.8!important}
.addr-star-btn.starred:hover{opacity:1!important}
a:hover{text-decoration:none}
.toolbar{display:flex;align-items:center;gap:6px;padding:8px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap;position:sticky;top:72px;z-index:80;background:var(--bg)}
.toolbar .hint{margin-left:auto;font-size:11px;color:var(--muted);opacity:.8}
form#mainForm{flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0}
.table-container{flex:1;overflow-y:auto;overflow-x:hidden}
table{width:100%;border-collapse:collapse;table-layout:fixed}
th{padding:7px 14px;text-align:left;font-size:13px;color:var(--muted);background:var(--card);border-bottom:2px solid var(--border);position:sticky;top:0;z-index:10;font-weight:600;user-select:none}
:root.dark th{color:#bbb;border-bottom-color:#555}
th:first-child{border-left:none}
th:last-child{border-right:none}
th.sortable{cursor:pointer}
th.sortable:hover{color:var(--text)}
th.sortable .sort-icon{font-size:11px;margin-left:4px;opacity:.4}
th.sortable:hover .sort-icon{opacity:.8}
.col-resize-handle{position:absolute;right:-1px;top:0;bottom:0;width:4px;cursor:col-resize;background:transparent;z-index:2}
.col-resize-handle:hover{background:var(--primary)!important}
td{padding:6px 14px;border-bottom:1px solid var(--border);color:var(--text);white-space:nowrap;vertical-align:middle;font-size:13px}
:root.dark td{border-bottom-color:#333}
tr{transition:background .08s}
tr:hover td{background:var(--hover)}
tr.selected td{background:var(--sel)}
.checkbox{width:16px;height:16px;cursor:pointer;accent-color:var(--primary);border-radius:3px;transition:all .1s}
.empty{text-align:center;padding:80px 40px;color:var(--muted);font-size:14px;opacity:.7}
.footer{position:sticky;bottom:0;z-index:100;padding:8px 16px;font-size:12px;color:var(--muted);border-top:1px solid var(--border);background:var(--bg)}
.dropdown{position:relative;display:inline-block}
.dropdown-menu{display:none;position:absolute;top:100%;left:0;background:var(--card);border-radius:var(--radius);box-shadow:0 4px 24px rgba(0,0,0,.15);min-width:160px;padding:6px 0;z-index:200;background:var(--bg);opacity:1;transform:none;border:1px solid var(--border)}
.dropdown-menu.show{display:block;opacity:1;transform:translateY(4px)}
.dropdown:hover>.dropdown-menu{display:block}
.dropdown-item{padding:8px 16px;cursor:pointer;font-size:13px;color:var(--muted);border-radius:6px;margin:2px 8px;transition:all .1s}
.dropdown-item:hover{background:var(--primary);color:#fff}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center}
.modal.show{display:flex}
.modal-content{background:var(--card);padding:20px;border-radius:10px;max-width:400px;width:90%;box-shadow:var(--shadow)}
.modal-dialog{background:var(--card);border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;border:1px solid var(--border)}
.modal-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600}
.modal-body{padding:20px 24px;color:var(--muted);margin-bottom:16px}
.modal-footer{padding:12px 24px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}
.modal-footer .btn{min-width:80px}
.context-menu{position:fixed;background:var(--card);border-radius:6px;box-shadow:var(--shadow);min-width:160px;z-index:2000;padding:4px 0;display:none;border:1px solid var(--border)}
.context-menu.show{display:block}
.context-menu-item{padding:6px 14px;cursor:default;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)}
.context-menu-item:hover{background:var(--primary);color:#fff;border-radius:4px;margin:0 4px;padding-left:10px;padding-right:10px}
.context-menu-divider{height:1px;background:var(--border);margin:4px 8px}
.context-menu-item.danger{color:var(--danger);font-weight:500}
.context-menu-item.danger:hover{background:var(--danger);color:#fff}
.context-menu-item.has-submenu{position:relative}
.context-submenu{display:none;position:absolute;left:calc(100% + 4px);top:0;background:var(--card);border-radius:6px;box-shadow:var(--shadow);min-width:150px;z-index:2100;padding:4px 0;border:1px solid var(--border)}
.submenu-item{padding:6px 14px;cursor:pointer;font-size:13px;color:var(--muted)}
.submenu-item:hover{background:var(--primary);color:#fff}
.inline-input{border:1px solid var(--primary);border-radius:4px;padding:3px 6px;font-size:13px;background:var(--bg);color:var(--text);outline:none;min-width:160px}
.inline-edit td{background:rgba(10,132,255,.1)!important}
.upload-progress{position:fixed;bottom:20px;right:20px;background:var(--card);padding:14px 18px;border-radius:8px;min-width:200px;box-shadow:var(--shadow)}
.progress-bar{height:3px;background:var(--border);border-radius:1.5px;margin-top:8px}
.progress-bar-fill{height:100%;background:var(--primary);border-radius:1.5px;transition:width .3s}
.drag-over{background:rgba(10,132,255,.1)!important}
.star-cell{width:28px;text-align:center;cursor:pointer}
.star-cell .star-icon{font-size:14px;opacity:.35;transition:opacity .15s,color .15s;color:#ff9f0a}
.star-cell .star-icon:hover{opacity:.7}
.perms-cell{width:90px;font-family:'Courier New',monospace;font-size:12px;cursor:pointer;color:var(--primary);transition:background .15s}
.perms-cell:hover{background:var(--hover)}
.share-cell{text-align:center;vertical-align:middle;padding:0 4px}.share-cell a{text-decoration:none;font-size:14px;opacity:.6;transition:opacity .15s}.share-cell a:hover{opacity:1}
.star-cell .star-icon.starred{opacity:1;color:var(--star-color)}
.table-container::-webkit-scrollbar{width:6px}
.table-container::-webkit-scrollbar-track{background:transparent}
.table-container::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px}
.table-container::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.3)}
.sidebar-scroll::-webkit-scrollbar{width:4px}
.sidebar-scroll::-webkit-scrollbar-track{background:transparent}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:2px;transition:background .2s}
.sidebar-scroll::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.25)}
.trash-panel-content::-webkit-scrollbar{width:5px}
.trash-panel-content::-webkit-scrollbar-track{background:transparent}
.trash-panel-content::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.trash-panel{position:fixed;top:0;right:-400px;width:400px;height:100vh;background:var(--card);border-left:1px solid var(--border);z-index:1500;transition:right .3s ease;box-shadow:-4px 0 24px rgba(0,0,0,.25);display:flex;flex-direction:column}
.trash-panel.show{right:0}
.trash-panel-header{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.trash-panel-header h2{font-size:15px;font-weight:600;flex:1}
.trash-panel-close{width:28px;height:28px;border:none;border-radius:6px;background:var(--hover);color:var(--muted);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
.trash-panel-close:hover{background:var(--border);color:var(--text)}
.trash-panel-toolbar{padding:8px 16px;border-bottom:1px solid var(--border);display:flex;gap:8px}
.trash-panel-content{flex:1;overflow:auto;padding:8px 0}
.trash-panel-footer{padding:8px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--muted)}
.trash-item{padding:8px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border);transition:background .08s}
.trash-item:hover{background:var(--hover)}
.trash-item-icon{font-size:16px}
.trash-item-info{flex:1;min-width:0}
.trash-item-name{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.trash-item-meta{font-size:11px;color:var(--muted);margin-top:2px}
.trash-item-actions{display:flex;gap:4px}
.trash-empty{text-align:center;padding:40px 20px;color:var(--muted)}
.trash-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:1400;opacity:0;pointer-events:none;transition:opacity .3s}
.trash-overlay.show{opacity:1;pointer-events:auto}
#aceModal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:3000}
#aceModal.show{display:block}
#aceModal .modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.5)}
#aceModal .ace-wrap{position:absolute;background:var(--ace-bg);border-radius:8px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 12px 40px rgba(0,0,0,.5);border:1px solid var(--ace-border)}
.ace-resize-handle{position:absolute;right:0;bottom:0;width:16px;height:16px;cursor:nwse-resize;z-index:10}
.ace-resize-handle::after{content:'';position:absolute;right:3px;bottom:3px;width:8px;height:8px;border-right:2px solid #666;border-bottom:2px solid #666}
#aceEditorArea{flex:1;overflow:hidden}
#aceEditorArea .ace_editor{height:100%!important;font-family:'Cascadia Code','Fira Code','Consolas',monospace!important;font-size:14px!important}
#aceEditorHeader{background:var(--ace-header);padding:5px 10px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--ace-border);flex-shrink:0;cursor:move;user-select:none}
#aceEditorHeader .ace-path{color:var(--ace-text);font-size:12px;font-family:monospace;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#aceEditorHeader .ace-lang{color:var(--ace-muted);font-size:10px;background:#333;padding:1px 6px;border-radius:3px;flex-shrink:0}
#aceEditorHeader .ace-close-btn{width:22px;height:22px;border:none;border-radius:3px;background:var(--ace-border);color:var(--ace-text);font-size:13px;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;flex-shrink:0}
#aceEditorHeader .ace-close-btn:hover{background:#c42b1c;color:#fff}
#aceEditorToolbar{background:var(--ace-toolbar);padding:3px 8px;display:flex;align-items:center;gap:2px;border-bottom:1px solid var(--ace-border);flex-shrink:0}
.ace-text-btn{padding:3px 8px;border:none;border-radius:3px;background:transparent;color:#bbb;font-size:11px;cursor:pointer;transition:all .15s}
.ace-text-btn:hover{background:var(--ace-border);color:#fff}
.ace-text-btn.ace-btn-primary{background:#007aff;color:#fff}
.ace-text-btn.ace-btn-primary:hover{background:#0066d8}
.ace-sep{width:1px;height:14px;background:var(--ace-border);margin:0 4px}
#aceStatusBar{background:#007acc;padding:2px 10px;display:flex;align-items:center;gap:16px;font-size:11px;color:#fff;flex-shrink:0}
#aceStatusBar span{opacity:.9}
.ace-dropdown{position:relative;display:inline-block}
.ace-dropdown-content{display:none;position:absolute;top:100%;left:50%;transform:translateX(-50%);margin-top:4px;background:var(--ace-toolbar);border:1px solid var(--ace-border);border-radius:6px;min-width:160px;box-shadow:0 4px 12px rgba(0,0,0,.4);z-index:1000;padding:4px 0}
.ace-dropdown.open .ace-dropdown-content{display:block}
.ace-dropdown-group{padding:4px 0}
.ace-dropdown-label{padding:4px 12px;font-size:11px;color:var(--ace-muted);text-transform:uppercase;letter-spacing:.5px}
.ace-dropdown-item{padding:6px 16px;cursor:pointer;font-size:13px;color:var(--ace-text);transition:all .15s}
.ace-dropdown-item:hover{background:var(--ace-border);color:#fff}
.ace-dropdown-divider{height:1px;background:var(--ace-border);margin:4px 0}
#aceMainArea{display:flex;flex:1;overflow:hidden}
#aceFunctionPanel{width:200px;background:var(--ace-toolbar);border-left:1px solid var(--ace-border);display:flex;flex-direction:column;transition:width .2s;flex-shrink:0}
#aceFunctionPanel.ace-panel-collapsed{width:28px}
#aceFunctionPanel.ace-panel-collapsed #aceFunctionList{display:none}
.ace-panel-header{padding:5px 6px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;background:var(--ace-hover);border-bottom:1px solid var(--ace-border);white-space:nowrap;overflow:hidden}
.ace-panel-header span:first-child{color:var(--ace-text);font-size:11px}
.ace-panel-toggle{color:var(--ace-muted);font-size:9px;transition:transform .2s}
#aceFunctionPanel.ace-panel-collapsed .ace-panel-toggle{transform:rotate(180deg)}
#aceFunctionPanel.ace-panel-collapsed .ace-panel-header span:first-child{display:none}
#aceFunctionList{flex:1;overflow-y:auto;padding:2px 0}
.ace-func-item{padding:4px 10px;cursor:pointer;font-size:11px;color:var(--ace-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:all .15s}
.ace-func-item:hover{background:var(--ace-border);color:#fff}
.ace-func-empty{padding:16px;text-align:center;color:#666;font-size:11px}
.search-result-item{display:flex;align-items:center;gap:8px;padding:10px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .08s}
.search-result-item:hover{background:var(--hover)}
.search-result-item:last-child{border-bottom:none}
.search-result-icon{font-size:16px;flex-shrink:0}
.search-result-info{flex:1;min-width:0}
.search-result-name{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.search-result-name mark{background:rgba(255,200,0,.3);color:inherit;padding:0 2px;border-radius:2px}
.search-result-path{font-size:11px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.search-result-meta{font-size:11px;color:var(--muted);flex-shrink:0;text-align:right}
.search-empty{text-align:center;padding:32px 20px;color:var(--muted);font-size:13px}
.search-loading{text-align:center;padding:20px;color:var(--muted);font-size:13px}
.toolbar-search{display:flex;align-items:center;gap:0;margin-left:auto}
.toolbar-search input{border:1px solid var(--border);border-right:none;border-radius:6px 0 0 6px;padding:6px 10px;font-size:12px;background:var(--input-bg);color:var(--text);outline:none;width:160px;transition:border-color .15s}
.toolbar-search input:focus{border-color:var(--primary)}
.toolbar-search .btn{border-radius:0 6px 6px 0;padding:6px 10px}
tr.filter-match{background:rgba(255,200,0,.12)!important}
tr.filter-match td{border-bottom-color:rgba(255,200,0,.2)}
/* Music Player */
.music-btn{position:fixed;top:14px;right:14px;z-index:998;width:40px;height:40px;background:var(--primary);color:#fff;border:none;border-radius:50%;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.music-btn:hover{transform:scale(1.1)}
.music-panel{position:fixed;top:60px;right:10px;width:280px;background:var(--card);border:1px solid var(--border);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.2);z-index:997;display:none;padding:12px}
.music-panel.show{display:block}
.music-panel-title{font-size:13px;font-weight:600;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.music-close{background:none;border:none;cursor:pointer;color:var(--muted);font-size:14px}
.music-lrc-box{height:60px;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:8px;margin-bottom:8px;overflow:hidden;position:relative}
.music-lrc-box .no-lrc{display:flex;align-items:center;justify-content:center;height:100%;font-size:28px}
.music-lrc-scroll{position:absolute;left:0;right:0;transition:transform .4s ease}
.music-lrc-line{height:20px;line-height:20px;text-align:center;font-size:11px;color:rgba(255,255,255,.5);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 8px}
.music-lrc-line.active{color:#fff;font-weight:600;font-size:12px}
.music-lrc-line.near{color:rgba(255,255,255,.75)}
.music-title{font-size:12px;font-weight:600;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.music-controls{display:flex;justify-content:center;gap:12px;margin-top:8px}
.music-controls button{background:none;border:none;cursor:pointer;font-size:16px;color:var(--text);padding:6px;border-radius:50%;transition:background .2s}
.music-controls button:hover{background:var(--hover)}
.music-controls .play{width:36px;height:36px;background:var(--primary);color:#fff;font-size:14px}
.music-progress{height:4px;background:var(--border);border-radius:2px;margin-top:8px;cursor:pointer}
.music-progress-fill{height:100%;background:var(--primary);border-radius:2px;width:0%;transition:width .1s}
.music-time{display:flex;justify-content:space-between;font-size:10px;color:var(--muted);margin-top:2px}
.music-volume{display:flex;align-items:center;gap:6px;margin-top:6px}
.music-volume input{accent-color:var(--primary)}

.music-list-item{padding:6px 8px;cursor:pointer;font-size:12px;display:flex;align-items:center;gap:6px;border-radius:4px}
.music-list-item:hover{background:var(--hover)}
.music-list-item.active{background:var(--sel);color:var(--primary)}
.music-toolbar{margin-top:6px;padding-top:6px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.music-toolbar button{background:none;border:1px solid var(--border);cursor:pointer;font-size:11px;padding:4px 10px;border-radius:4px;color:var(--muted);transition:all .15s}
.music-toolbar button:hover{background:var(--hover);color:var(--text)}
.music-toolbar button.mode-on{background:var(--primary);color:#fff;border-color:var(--primary)}
.music-toolbar .list-toggle{border:none;font-size:11px;color:var(--muted);padding:4px 6px}
.music-toolbar .list-toggle:hover{color:var(--text)}
.music-list{max-height:0;overflow:hidden;transition:max-height .3s ease}
.music-list.open{max-height:180px;overflow-y:auto;border-top:1px solid var(--border);margin-top:6px;padding-top:6px}
.music-add-modal{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:300px;background:var(--card);border:1px solid var(--border);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.3);z-index:999;padding:12px}
.music-add-modal-title{font-size:13px;font-weight:600;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.music-add-dir{max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:4px}
.music-add-dir-item{padding:6px 10px;cursor:pointer;font-size:12px;display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border)}
.music-add-dir-item:last-child{border-bottom:none}
.music-add-dir-item:hover{background:var(--hover)}
.music-add-dir-item.selected{background:var(--sel);color:var(--primary)}
</style>

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
    <form method="post">
        <input type="hidden" name="login_token" value="<?=htmlspecialchars($login_token)?>">
        <div class="form-group" style="margin-bottom:12px"><input type="text" name="user" class="form-control" placeholder="<?=lng('Username')?>" required autofocus autocomplete="username"></div>
        <div class="form-group" style="margin-bottom:12px"><input type="password" name="pass" class="form-control" placeholder="<?=lng('Password')?>" required autocomplete="current-password"></div>
        <button type="submit" name="login" class="btn btn-primary" style="width:60%;margin:0 auto;display:block;text-align:center"><?=lng('Login')?></button>
    </form>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- 主界面 -->
<div class="sidebar">
    <div class="sidebar-header">☁️ <?=APP_TITLE?><button id="themeToggle" onclick="toggleTheme()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;padding:2px 4px;border-radius:6px">🌙</button></div>
    <div class="sidebar-scroll">
        <div class="sidebar-section">
            <div class="sidebar-title" onclick="toggleSection('starList')">⭐ <?=lng('Favorites')?> <span class="arrow">▼</span></div>
            <ul class="sidebar-list" id="starList">
                <?php if(empty($stars)): ?>
                <li style="padding:8px 16px;font-size:12px;color:var(--muted)">暂无收藏</li>
                <?php else: foreach($stars as $sp): $sp_name=basename($sp)?:'./'; ?>
                <li class="sidebar-item <?=$p===$sp?'active':''?>">
                    <span class="item-icon"><?=is_dir($root_path.'/'.($sp?$sp.'/':'.'))?'📁':'📄'?></span>
                    <a href="?p=<?=urlencode($sp)?>" class="item-name" title="<?=htmlspecialchars($sp)?>"><?=htmlspecialchars($sp_name)?></a>
                    <span class="star-btn starred" onclick="event.stopPropagation();toggleStar('<?=addslashes($sp)?>',this)" data-star-path="<?=addslashes($sp)?>" title="取消收藏">★</span>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-title" onclick="toggleSection('quickList')">🏠 <?=lng('My Files')?> <span class="arrow">▼</span></div>
            <ul class="sidebar-list" id="quickList">
                <li class="sidebar-item <?=$p===''?'active':''?>"><span class="item-icon">🏠</span><a href="?p=" class="item-name">根目录</a></li>
                <?php foreach($folders as $sf): $sf_path=trim($p.'/'.$sf,'/'); ?>
                <li class="sidebar-item"><span class="item-icon">📁</span><a href="?p=<?=urlencode($sf_path)?>" class="item-name"><?=$sf?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div style="padding:12px 16px;border-top:1px solid var(--border);flex-shrink:0">
        <div class="sidebar-item" style="padding:5px 0;cursor:pointer" onclick="showTrashPanel()"><span class="item-icon">🗑️</span> <?=lng('Trash')?></div>
        <a href="?logout" class="sidebar-item" style="padding:5px 0"><span class="item-icon">🚪</span> <?=lng('Logout')?></a>
    </div>
</div>

<!-- Music Player -->
<button class="music-btn" onclick="toggleMusic()">🎵</button>
<div class="music-panel" id="musicPanel">
    <div class="music-panel-title">🎵 播放器 <button class="music-close" onclick="toggleMusic()">✕</button></div>
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
<div style="max-width:100%;background:var(--card);flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0">
    <div class="header"><h1>📂 <?=htmlspecialchars(basename($p)?:'根目录')?></h1></div>
	<div class="breadcrumb" style="display:flex;align-items:center;flex-wrap:nowrap;overflow:hidden;gap:0;min-width:0">
		<button onclick="history.back()" title="后退" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;flex-shrink:0">◀</button>
		<button onclick="history.forward()" title="前进" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;flex-shrink:0">▶</button>
		<span style="border-left:1px solid var(--border);height:14px;margin:0 2px;flex-shrink:0"></span>
		<span onclick="showPathInput(this)" style="cursor:text;flex:1;display:flex;align-items:center;min-width:0;overflow:hidden">
			<a href="?p=" data-folder="" ondragstart="return false" style="flex-shrink:0">🏠</a>
			<?php $acc=''; foreach(array_filter(explode('/',$p)) as $part): $acc.=($acc?'/':'').$part; ?>
			<span style="flex-shrink:0">›</span>
			<a href="?p=<?=urlencode($acc)?>" data-folder="<?=htmlspecialchars($acc)?>" ondragstart="return false" style="flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($part)?></a>
			<?php endforeach; ?>
		</span>
		<button data-star-path="<?=htmlspecialchars(addslashes($p))?>" class="addr-star-btn<?=in_array($p,$stars)?' starred':''?>" onclick="event.stopPropagation();toggleStar('<?=addslashes($p)?>',this)" title="<?=in_array($p,$stars)?'取消收藏':'收藏此路径'?>" style="flex-shrink:0;background:none;border:none;cursor:pointer;font-size:15px;padding:0 6px;color:var(--muted);opacity:.5;transition:opacity .15s,color .15s"><?=in_array($p,$stars)?'★':'☆'?></button>
</div>

    <?php if($pathHint!==null): $token=$_SESSION['token']??''; $encFolder=addslashes($pathHint); ?>
    <div style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9998" onclick="var h=document.getElementById('pathHint');if(h){h.remove();this.remove()}}"></div>
    <div id="pathHint" style="position:fixed;left:50%;top:40%;transform:translate(-50%,-50%);background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 18px;z-index:9999;max-width:300px;font-size:13px">
        ⚠️ <b style="color:var(--primary)"><?=$pathHint?></b> 不存在<br>
        <button class="btn btn-primary" onclick="createMissingFolder()" style="margin-top:10px;padding:4px 14px;font-size:12px">✅ 创建此文件夹</button>
        <button class="btn" onclick="var h=document.getElementById('pathHint');var ov=h.previousElementSibling;if(ov&&ov.style&&ov.style.position==='fixed')ov.remove();h.remove()" style="margin-top:10px;padding:4px 14px;font-size:12px">✕ 关闭</button>
    </div>
    <script>function createMissingFolder(){const fd=new FormData();fd.append('token','<?=$token?>');fd.append('ajax_new','1');fd.append('new_folder','<?=$encFolder?>');fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{var h=document.getElementById('pathHint');if(h){var ov=h.previousElementSibling;if(ov&&ov.style&&ov.style.position==='fixed')ov.remove();h.remove()}if(d.error)alert(d.error);else location.reload()}).catch(()=>alert('创建失败'))}</script>
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
            <?php if($parent!==false): ?><tr class="parent-row"><td></td><td></td><td><a href="?p=<?=urlencode($parent)?>">📁 ..</a></td><td>-</td><td>-</td><td></td><td></td></tr><?php endif; ?>
            <?php foreach($folders as $f): $fp=trim($p.'/'.$f,'/'); $fs=in_array($fp,$stars); ?>
            <tr draggable="true" data-name="<?=htmlspecialchars($f)?>" data-ctx="1" data-type="folder" data-size="0" data-mtime="<?=filemtime($current_path.'/'.$f)?>" data-hidden="<?=$f[0]==='.'?1:0?>" onclick="selectRow(this,event)" ondblclick="location.href='?p=<?=urlencode($fp)?>'">
                <td><input type="checkbox" name="files[]" value="<?=htmlspecialchars($f)?>" class="checkbox file-check" onclick="event.stopPropagation()"></td>
                <td><span class="star-icon <?=$fs?'starred':''?>" onclick="event.stopPropagation();toggleStar('<?=addslashes($fp)?>',this)" data-star-path="<?=addslashes($fp)?>" title="<?=$fs?'取消收藏':'收藏'?>"><?=$fs?'★':'☆'?></span></td>
                <td><a href="?p=<?=urlencode($fp)?>" class="folder-link" data-folder="<?=htmlspecialchars($f)?>" onclick="event.stopPropagation()">📁 <?=htmlspecialchars($f)?></a></td>
                <td class="perms-cell" onclick="event.stopPropagation();showChmodModal('<?=addslashes($f)?>','<?=addslashes($fp)?>',this)" title="点击修改权限"><?=fm_get_perms($current_path.'/'.$f)?></td><td>文件夹</td><td>-</td><td><?=date('Y-m-d H:i',filemtime($current_path.'/'.$f))?></td><td class="share-cell"><a href="<?=$base_url!==''?rtrim($base_url,'/').'/'.$fp:('https://'.$_SERVER['HTTP_HOST'].ltrim(dirname($_SERVER['SCRIPT_NAME']),$app_path).'/'.$fp)?>" target="_blank" title="打开文件夹">🔗</a></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach($files as $f): $ext=strtolower(pathinfo($f,PATHINFO_EXTENSION)); $isZip=in_array($ext,['zip','tar','gz','tgz','7z','rar','bz2']); $isImage=in_array($ext,['jpg','jpeg','png','gif','webp','svg','bmp','ico']); $isVideo=in_array($ext,['mp4','webm','ogg','mov','avi','mkv','m4v','3gp','flv','wmv']); $isAudio=in_array($ext,['mp3','wav','ogg','flac','aac','m4a','wma','opus']); $isEditable=in_array($ext,['js','ts','css','html','htm','php','json','py','md','xml','yaml','yml','sql','sh','bash','txt','csv','log','conf','ini','bat','ps1','go','rs','java','c','cpp','cc','h','cs','rb','swift','kt','vue','jsx','tsx','scss','less','toml','dockerfile','makefile','lua','perl','r','scala','properties','xhtml','svg']); ?>
            <tr draggable="true" data-name="<?=htmlspecialchars($f)?>" data-ctx="1" data-type="file" data-ext="<?=$ext?>" data-archive="<?=$isZip?1:0?>" data-size="<?=filesize($current_path.'/'.$f)?>" data-mtime="<?=filemtime($current_path.'/'.$f)?>" data-hidden="<?=$f[0]==='.'?1:0?>" data-editable="<?=$isEditable?1:0?>" data-image="<?=$isImage?1:0?>" data-video="<?=$isVideo?1:0?>" data-audio="<?=$isAudio?1:0?>" data-audio-path="<?=urlencode($p)?>" onclick="selectRow(this,event)" ondblclick="<?=$isImage?'showImagePreview(\''.addslashes($p).'\',\''.addslashes($f).'\')':($isVideo?'showVideoPreview(\''.addslashes($p).'\',\''.addslashes($f).'\')':($isAudio?'playAudioFile(\''.addslashes($p).'\',\''.addslashes($f).'\')':'event.ctrlKey||event.metaKey||event.shiftKey||openAceEditor(\''.addslashes($p).'\',\''.addslashes($f).'\')'))?>">
                <td><input type="checkbox" name="files[]" value="<?=htmlspecialchars($f)?>" class="checkbox file-check" onclick="event.stopPropagation()"></td>
                <td></td>
                <td><?=($isZip?'📦':'📄').' '.htmlspecialchars($f)?></td>
                <td class="perms-cell" onclick="event.stopPropagation();showChmodModal('<?=addslashes($f)?>','<?=addslashes(trim($p.'/'.$f,'/'))?>',this)" title="点击修改权限"><?=fm_get_perms($current_path.'/'.$f)?></td><td><?=strtoupper($ext)?></td><td><?=fmtsize(filesize($current_path.'/'.$f))?></td><td><?=date('Y-m-d H:i',filemtime($current_path.'/'.$f))?></td><td class="share-cell"><a href="<?=$base_url!==''?rtrim($base_url,'/').'/'.trim($p.'/'.$f,'/'):('https://'.$_SERVER['HTTP_HOST'].ltrim(dirname($_SERVER['SCRIPT_NAME']),$app_path).'/'.trim($p.'/'.$f,'/'))?>" target="_blank" title="打开文件">🔗</a></td>
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
<div class="modal" id="chmodModal">
  <div class="modal-backdrop" onclick="hideModal('chmodModal')"></div>
  <div class="modal-dialog" style="max-width:400px;width:100%">
    <div class="modal-header">🔒 修改权限</div>
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

<script>
const currentPath='<?=addslashes($p)?>';
const stars=<?=json_encode($stars)?>;
let aceInst=null,aceCurFile='',aceCurPath='';
function aceOpen(path,name){
    var full='/'+String(path).replace(/^\/|\/$/g,'');
    aceCurPath=full; aceCurFile=name;
    document.getElementById('aceFullPath').textContent=path+'/'+name;
    fetch('?p='+encodeURIComponent(path)+'&dl='+encodeURIComponent(name))
        .then(r=>r.text())
        .then(txt=>{
            document.getElementById('aceModal').classList.add('show');
            window._aceOpen = true;
            /* 恢复位置 */
            (function(){var w=document.querySelector('.ace-wrap');if(!w)return;try{var s=localStorage.getItem('ace_pos');if(s){var p=JSON.parse(s);w.style.left=p.left+'px';w.style.top=p.top+'px';w.style.width=Math.max(400,p.width)+'px';w.style.height=Math.max(300,p.height)+'px';}}catch(e){}if(aceInst)setTimeout(function(){aceInst.resize();},10);})();
            if(!aceInst){
                aceInst=ace.edit('aceEditor');
                aceInst.setTheme('ace/theme/vs_dark');
                aceInst.setOption('wrap','free');
                aceInst.setOption('showPrintMargin',false);
                aceInst.setFontSize(14);
                aceInst.$blockScrolling=Infinity;
                aceInst.commands.addCommand({
                    name:'aceSave',
                    bindKey:{win:'Ctrl-S',mac:'Cmd-S'},
                    exec:function(){aceSave();}
                });
                aceInst.commands.addCommand({
                    name:'aceSaveAs',
                    bindKey:{win:'Ctrl-Alt-S',mac:'Cmd-Opt-S'},
                    exec:function(){aceSaveAs();}
                });
            }
            aceInst.setValue(txt,1);
            var ext=name.split('.').pop().toLowerCase();
            var modes={'js':'javascript','ts':'typescript','css':'css','html':'html','htm':'html','php':'php','json':'json','py':'python','md':'markdown','xml':'xml','yaml':'yaml','yml':'yaml','sql':'sql','sh':'sh','bash':'bash','csv':'csv','txt':'plain','go':'golang','rs':'rust','java':'java','c':'c_cpp','cpp':'c_cpp','cc':'c_cpp','h':'c_cpp','cs':'csharp','rb':'ruby','swift':'swift','kt':'kotlin','vue':'html','jsx':'javascript','tsx':'javascript','scss':'scss','less':'less','toml':'ini','ini':'ini','dockerfile':'dockerfile','makefile':'makefile','lua':'lua','perl':'perl','r':'r','scala':'scala','properties':'properties','bat':'batchfile','ps1':'powershell','conf':'conf','xhtml':'html','svg':'svg'}[ext]||'plain';
            aceInst.session.setMode('ace/mode/'+modes);
            document.getElementById('aceLang').textContent=ext.toUpperCase();
            aceInst.session.on('changeSelection',updateAceStatus);
            updateAceStatus();
            updateFunctionList();
        })
        .catch(()=>{alert('读取文件失败');aceClose()});
}
function aceClose(){
    if(window._aceSaving)return;
    window._aceOpen = false;
    document.getElementById('aceModal').classList.remove('show');
    if(aceInst)aceInst.resize();
}
async function aceSave(){
    var txt=aceInst.getValue();
    var cur=currentPath||'';
    var fd=new FormData();
    fd.append('token',token);
    fd.append('content',txt);
    var path=encodeURIComponent(cur?(cur+'/'+aceCurFile):aceCurFile);
    window._aceSaving=true;
    fetch('?ajax_save_as='+path,{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.success){
                toast('保存成功','ok');
                // 不调用 refreshFileList,避免页面刷新导致编辑器关闭
                setTimeout(function(){window._aceSaving=false;},2000);
            }else{
                window._aceSaving=false;
                toast(d.error||'保存失败','err');
            }
        })
        .catch(()=>{window._aceSaving=false;toast('保存失败','err');});
}
var _saveAsState={content:'',fileName:'',currentPath:'',selectedPath:''};
async function aceSaveAs(){
    _saveAsState.content=aceInst.getValue();
    _saveAsState.fileName=aceCurFile||'untitled.txt';
    _saveAsState.currentPath=currentPath||'';
    _saveAsState.selectedPath='';
    document.getElementById('saveAsFileNameInput').value=_saveAsState.fileName;
    document.getElementById('saveAsBreadcrumb').value='/'+(_saveAsState.currentPath?_saveAsState.currentPath+'/':'');
    document.getElementById('saveAsTitle').textContent='另存为';
    document.getElementById('saveAsDirList').innerHTML='<div style="padding:16px;text-align:center;color:#666;font-size:12px">加载中...</div>';
    var curName=document.getElementById('saveAsCurrentName'),curEl=document.getElementById('saveAsSidebarCurrent');
    if(_saveAsState.currentPath){
        var parts=_saveAsState.currentPath.split('/');
        curName.textContent=parts[parts.length-1]||'当前';
        curEl.dataset.path=_saveAsState.currentPath;
        curEl.style.display='flex';
    }else{
        curEl.style.display='none';
    }
    showModal('saveAsModal');
    await loadSaveAsDirList(_saveAsState.currentPath);
    setTimeout(function(){var inp=document.getElementById('saveAsFileNameInput');inp.focus();inp.select();},100);
}
async function loadSaveAsDirList(relPath){
    var listEl=document.getElementById('saveAsDirList'),breadcrumbEl=document.getElementById('saveAsBreadcrumb');
    listEl.innerHTML='<div style="padding:16px;text-align:center;color:#666;font-size:12px">加载中...</div>';
    _saveAsState.currentPath=relPath;
    breadcrumbEl.value='/'+(relPath?relPath+'/':'');
    var fd=new FormData();fd.append('ajax_dir_tree','1');fd.append('path',relPath);fd.append('token',token);
    try{
        var r=await fetch(location.href,{method:'POST',body:fd}),d=await r.json();
        if(d.error){listEl.innerHTML='<div style="padding:16px;text-align:center;color:#f44;font-size:12px">'+d.error+'</div>';return;}
        var items=d.items||[],html='';
        if(relPath!==''){
            var parent=relPath.split('/').slice(0,-1).join('/');
            html+='<div style="display:flex;align-items:center;padding:4px 10px;cursor:pointer;font-size:11px;color:#888" ondblclick="loadSaveAsDirList(\''+(parent||'')+'\')" onmouseover="this.style.background=\'#2d2d2d\'" onmouseout="this.style.background=\'transparent\'"><span>📁 ..</span></div>';
        }
        if(!items.length && relPath===''){html+='<div style="padding:16px;text-align:center;color:#666;font-size:12px">此目录为空</div>';}
        for(var i=0;i<items.length;i++){
            var item=items[i],isSelected=(_saveAsState.selectedPath===item.path);
            html+='<div class="saveas-file-row" data-path="'+item.path+'" onclick="selectSaveAsDir(\''+item.path+'\')" ondblclick="loadSaveAsDirList(\''+item.path+'\')" style="display:flex;align-items:center;padding:4px 10px;cursor:pointer;font-size:11px;background:'+(isSelected?'#094771':'transparent')+'" onmouseover="if(!this.style.background.includes(\'#094771\'))this.style.background=\'#2d2d2d\'" onmouseout="if(!this.dataset.selected)this.style.background=\'transparent\'"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:'+(isSelected?'#fff':'#ccc')+'">📁 '+item.name+'</span></div>';
        }
        listEl.innerHTML=html;
    }catch(e){listEl.innerHTML='';toast('加载失败');}
}
function selectSaveAsDir(path){
    var items=document.querySelectorAll('#saveAsDirList .saveas-file-row[data-path]');
    for(var i=0;i<items.length;i++){items[i].style.background='transparent';items[i].dataset.selected='';items[i].querySelector('span').style.color='#ccc';}
    _saveAsState.selectedPath=path;
    var selectedEl=document.querySelector('#saveAsDirList .saveas-file-row[data-path="'+path+'"]');
    if(selectedEl){selectedEl.style.background='#094771';selectedEl.dataset.selected='1';selectedEl.querySelector('span').style.color='#fff';}
    document.getElementById('saveAsBreadcrumb').value='/'+path+'/';
    var curEl=document.getElementById('saveAsSidebarCurrent');
    curEl.dataset.path=path;
    var parts=path.split('/');
    document.getElementById('saveAsCurrentName').textContent=parts[parts.length-1]||'当前';
}
function startSaveAs(){
    var fileName=document.getElementById('saveAsFileNameInput').value.trim();
    if(!fileName){alert('请输入文件名');return;}
    var targetDir=_saveAsState.selectedPath||_saveAsState.currentPath||'';
    var targetFile=targetDir?targetDir+'/'+fileName:fileName;
    hideModal('saveAsModal');
    var fd=new FormData();
    fd.append('content',_saveAsState.content);
    fd.append('token',token);
    window._aceSaving=true;
    fetch('?ajax_save_as='+encodeURIComponent(targetFile),{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.success){
                toast('已保存: '+fileName);
                setTimeout(function(){window._aceSaving=false;},2000);
            }else{
                window._aceSaving=false;
                alert('保存失败: '+(d.error||'未知错误'));
            }
        })
        .catch(e=>{window._aceSaving=false;alert('保存失败');});
}
function jumpSaveAsInputPath(){
    var v=document.getElementById('saveAsBreadcrumb').value.replace(/^\/+/,'').replace(/\/+$/,'');
    loadSaveAsDirList(v);
}

function aceUndo(){if(aceInst)aceInst.undo();}
function aceRedo(){if(aceInst)aceInst.redo();}
function aceFind(){if(aceInst)aceInst.execCommand('find');}
function aceReplace(){if(aceInst)aceInst.execCommand('replace');}
function aceBeautify(){
    if(!aceInst)return;
    aceInst.execCommand('beautify');
}
function aceToggleWrap(){
    if(!aceInst)return;
    var wrap=aceInst.getOption('wrap');
    aceInst.setOption('wrap',wrap=='off'?'free':'off');
}
let aceCurrentEncoding='UTF-8';
function toggleEncodingMenu(){
    document.getElementById('aceEncodingMenu').classList.toggle('open');
}
document.addEventListener('click',function(e){
    if(!e.target.closest('#aceEncodingMenu')){
        document.getElementById('aceEncodingMenu').classList.remove('open');
    }
});
function aceReopenWithEncoding(encoding){
    aceCurrentEncoding=encoding;
    fetch('?p='+encodeURIComponent(aceCurPath)+'&dl='+encodeURIComponent(aceCurFile)+'&enc='+encoding)
        .then(r=>r.text())
        .then(txt=>{
            if(aceInst)aceInst.setValue(txt,1);
            document.getElementById('aceFileInfo').textContent=encoding;
        })
        .catch(()=>alert('用'+encoding+'编码打开失败'));
}
function aceConvertToEncoding(targetEncoding){
    if(!aceInst)return;
    var txt=aceInst.getValue();
    var fd=new FormData();
    fd.append('token',token);
    fd.append('rename_from',aceCurFile);
    fd.append('rename_to','__ace_tmp__');
    fetch(location.pathname+'?p='+encodeURIComponent(currentPath),{method:'POST',body:fd})
        .then(()=>{
            var fd2=new FormData();
            fd2.append('token',token);
            fd2.append('rename_from','__ace_tmp__');
            fd2.append('rename_to',aceCurFile);
            return fetch(location.pathname+'?p='+encodeURIComponent(currentPath),{method:'POST',body:fd2});
        })
        .then(async()=>{
            var blob=new Blob([txt],{type:'text/plain;charset='+targetEncoding});
            var fd3=new FormData();
            fd3.append('file',blob,aceCurFile);
            fd3.append('token',token);
            await fetch(location.pathname+'?p='+encodeURIComponent(currentPath),{method:'POST',body:fd3});
            aceCurrentEncoding=targetEncoding;
            document.getElementById('aceFileInfo').textContent=targetEncoding;
            alert('已转换为'+targetEncoding+'编码');
        })
        .catch(()=>alert('转换编码失败'));
}
function toggleFunctionPanel(){
    document.getElementById('aceFunctionPanel').classList.toggle('ace-panel-collapsed');
}
function updateFunctionList(){
    if(!aceInst)return;
    var session=aceInst.getSession();
    var doc=session.getDocument();
    var lines=doc.getAllLines();
    var funcs=[];
    var mode=session.getMode().$id||'';
    var patterns=[];
    if(mode.includes('php')){
        patterns=[{re:/^\s*(?:public|private|protected|static)?\s*function\s+(\w+)/,type:'function'},{re:/^\s*class\s+(\w+)/,type:'class'}];
    }else if(mode.includes('javascript')||mode.includes('typescript')){
        patterns=[{re:/^\s*(?:async\s+)?function\s+(\w+)/,type:'function'},{re:/^\s*(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s*)?\([^)]*\)\s*=>/,type:'arrow'},{re:/^\s*(?:class|interface)\s+(\w+)/,type:'class'}];
    }else if(mode.includes('python')){
        patterns=[{re:/^\s*def\s+(\w+)/,type:'function'},{re:/^\s*class\s+(\w+)/,type:'class'}];
    }else if(mode.includes('java')||mode.includes('csharp')){
        patterns=[{re:/^\s*(?:public|private|protected|static)?\s*(?:\w+\s+)?(\w+)\s*\([^)]*\)\s*\{/,type:'method'},{re:/^\s*(?:public|private|protected)?\s*class\s+(\w+)/,type:'class'}];
    }else{
        patterns=[{re:/^\s*(?:function|def|sub)\s+(\w+)/,type:'function'},{re:/^\s*(?:class|struct|interface)\s+(\w+)/,type:'class'}];
    }
    for(var i=0;i<lines.length;i++){
        var line=lines[i];
        for(var j=0;j<patterns.length;j++){
            var m=line.match(patterns[j].re);
            if(m){
                funcs.push({name:m[1],line:i,type:patterns[j].type});
                break;
            }
        }
    }
    var html='';
    if(funcs.length===0){
        html='<div class="ace-func-empty">暂无函数</div>';
    }else{
        for(var k=0;k<funcs.length;k++){
            var f=funcs[k];
            var icon=f.type==='class'?'📦':'🔧';
            html+='<div class="ace-func-item" onclick="aceGotoLine('+f.line+')">'+icon+' '+f.name+'</div>';
        }
    }
    document.getElementById('aceFunctionList').innerHTML=html;
}
function aceGotoLine(line){
    if(!aceInst)return;
    aceInst.gotoLine(line+1,0,true);
    aceInst.focus();
}
function updateAceStatus(){
    if(!aceInst)return;
    var pos=aceInst.getCursorPosition();
    document.getElementById('aceCursorPos').textContent=(pos.row+1)+':'+(pos.column+1);
}
/* ===== 编辑器拖拽/缩放/持久化 ===== */
(function(){
    var wrap,header,resizeHandle;
    var dragging=false,resizing=false,dragStart,resizeStart;

    function getDefaults(){
        var w=Math.min(window.innerWidth*0.85,1100);
        var h=Math.min(window.innerHeight*0.82,700);
        return{left:Math.round((window.innerWidth-w)/2),top:Math.round((window.innerHeight-h)/2),width:Math.round(w),height:Math.round(h)};
    }
    function savePos(){
        if(!wrap)return;
        try{localStorage.setItem('ace_pos',JSON.stringify({left:parseInt(wrap.style.left),top:parseInt(wrap.style.top),width:parseInt(wrap.style.width),height:parseInt(wrap.style.height)}));}catch(e){}
    }

    function init(){
        wrap=document.querySelector('.ace-wrap');
        header=document.getElementById('aceEditorHeader');
        resizeHandle=document.getElementById('aceResizeHandle');
        if(!wrap)return;
        /* 初始默认位置 */
        if(!wrap.style.left){
            var d=getDefaults();
            wrap.style.left=d.left+'px';wrap.style.top=d.top+'px';wrap.style.width=d.width+'px';wrap.style.height=d.height+'px';
        }

        /* 拖拽 */
        header.addEventListener('mousedown',function(e){
            if(e.target.closest('.ace-close-btn'))return;
            dragging=true;
            dragStart={x:e.clientX-parseInt(wrap.style.left),y:e.clientY-parseInt(wrap.style.top)};
            e.preventDefault();
        });
        /* 缩放 */
        resizeHandle.addEventListener('mousedown',function(e){
            resizing=true;
            resizeStart={x:e.clientX,y:e.clientY,w:parseInt(wrap.style.width),h:parseInt(wrap.style.height)};
            e.preventDefault();
            e.stopPropagation();
        });
        document.addEventListener('mousemove',function(e){
            if(dragging){
                wrap.style.left=(e.clientX-dragStart.x)+'px';
                wrap.style.top=(e.clientY-dragStart.y)+'px';
            }
            if(resizing){
                wrap.style.width=Math.max(400,resizeStart.w+(e.clientX-resizeStart.x))+'px';
                wrap.style.height=Math.max(300,resizeStart.h+(e.clientY-resizeStart.y))+'px';
                if(aceInst)aceInst.resize();
            }
        });
        document.addEventListener('mouseup',function(){
            if(dragging||resizing)savePos();
            dragging=false;resizing=false;
        });
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
})();

/* Music Player */
var _mList=[],_mIdx=-1,_mPlay=false,_mPanel=false,_mMode=0,_mLyrics=[],_mAddPath='',_mListOpen=false;
var _modeLabels=['➡️顺序','🔀随机','🔁单曲'],_mLastHash='',_mAutoRefresh=setInterval(checkMusicUpdate,5000);
function _mHash(arr){return arr.map(function(x){return x.name;}).join('|');}
function checkMusicUpdate(){if(!_mPanel)return;var p=currentPath||'';var xhr=new XMLHttpRequest();xhr.open('GET','?ajax=files&p='+encodeURIComponent(p)+'&_t='+Date.now(),true);xhr.onreadystatechange=function(){if(xhr.readyState===4&&xhr.status===200){try{var data=JSON.parse(xhr.responseText);if(!data.length)return;var hash=_mHash(data.map(function(f){return{name:f.name};));if(hash===_mLastHash)return;_mLastHash=hash;var exts=['mp3','wav','ogg','flac','aac','m4a','wma','opus'],newList=[];data.forEach(function(f){var e=f.name.split('.').pop().toLowerCase();if(exts.indexOf(e)!==-1)newList.push({name:f.name,ext:e,path:p});});var added=newList.filter(function(n){return!_mList.some(function(o){return o.name===n.name&&o.path===n.path;})});var removed=_mList.filter(function(o){return!newList.some(function(n){return n.name===o.name&&n.path===o.path;})});if(added.length>0||removed.length>0){_mList=newList;renderMusicList();if(removed.some(function(r){return r===_mList[_mIdx]})){_mIdx=-1;var a=document.getElementById('musicAudio');a.pause();a.src='';document.getElementById('musicTitle').textContent='选择音乐播放';}}}}catch(e){}}};xhr.send();}
function toggleMusic(){var p=document.getElementById('musicPanel');_mPanel=!_mPanel;p.classList.toggle('show',_mPanel);if(_mPanel&&_mList.length===0)loadMusic();}
document.addEventListener('click',function(e){var p=document.getElementById('musicPanel'),b=document.querySelector('.music-btn'),m=document.getElementById('musicAddModal');if(_mPanel&&!p.contains(e.target)&&!b.contains(e.target)&&!m.contains(e.target)){p.classList.remove('show');_mPanel=false;}});
function loadMusic(){var exts=['mp3','wav','ogg','flac','aac','m4a','wma','opus'],rows=document.querySelectorAll('tr[data-type="file"]');_mList=[];rows.forEach(function(r){var n=r.dataset.name||'',e=n.split('.').pop().toLowerCase();if(exts.indexOf(e)!==-1)_mList.push({name:n,ext:e,path:currentPath||''});});renderMusicList();_mLastHash=_mHash(_mList);}
function playAudioFile(path,name){var p=document.getElementById('musicPanel');if(!_mPanel){p.classList.add('show');_mPanel=true;}var a=document.getElementById('musicAudio');a.src='?p='+encodeURIComponent(path)+'&dl='+encodeURIComponent(name);a.volume=document.getElementById('volSlider').value;a.play().catch(function(){});_mPlay=true;_mIdx=-1;document.getElementById('musicTitle').textContent=name;document.getElementById('playBtn').textContent='⏸';document.getElementById('musicFill').style.width='0%';document.getElementById('musicCur').textContent='0:00';loadLyrics(path,name);a.onended=function(){autoNext();};a.ontimeupdate=function(){if(a.duration){document.getElementById('musicFill').style.width=(a.currentTime/a.duration*100)+'%';document.getElementById('musicCur').textContent=fmtTime(a.currentTime);updateLrc(a.currentTime);}};a.onloadedmetadata=function(){document.getElementById('musicDur').textContent=fmtTime(a.duration);};}
function renderMusicList(){var d=document.getElementById('musicList');if(_mList.length===0){d.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;text-align:center">暂无音乐</div>';return;}d.innerHTML=_mList.map(function(m,i){return'<div class="music-list-item'+(i===_mIdx?' active':'')+'" onclick="playMusic('+i+')">🎵 '+m.name+'</div>';}).join('');}
function playMusic(i){if(i<0||i>=_mList.length)return;_mIdx=i;var m=_mList[i];var a=document.getElementById('musicAudio');a.src='?p='+encodeURIComponent(m.path)+'&dl='+encodeURIComponent(m.name);a.volume=document.getElementById('volSlider').value;a.play().catch(function(){});_mPlay=true;document.getElementById('musicTitle').textContent=m.name;document.getElementById('playBtn').textContent='⏸';renderMusicList();loadLyrics(m.path,m.name);a.onended=function(){autoNext();};a.ontimeupdate=function(){if(a.duration){document.getElementById('musicFill').style.width=(a.currentTime/a.duration*100)+'%';document.getElementById('musicCur').textContent=fmtTime(a.currentTime);updateLrc(a.currentTime);}};a.onloadedmetadata=function(){document.getElementById('musicDur').textContent=fmtTime(a.duration);};}
function togglePlay(){if(_mIdx<0&&_mList.length>0){playMusic(0);return;}var a=document.getElementById('musicAudio');if(_mPlay){a.pause();_mPlay=false;document.getElementById('playBtn').textContent='▶';}else{a.play().catch(function(){});_mPlay=true;document.getElementById('playBtn').textContent='⏸';}}
function prevTrack(){if(_mList.length===0)return;if(_mMode===1){playMusic(Math.floor(Math.random()*_mList.length));}else{var i=_mIdx<=0?_mList.length-1:_mIdx-1;playMusic(i);}}
function nextTrack(){if(_mList.length===0)return;if(_mMode===1){playMusic(Math.floor(Math.random()*_mList.length));}else{var i=_mIdx>=_mList.length-1?0:_mIdx+1;playMusic(i);}}
function autoNext(){if(_mMode===2){var a=document.getElementById('musicAudio');a.currentTime=0;a.play().catch(function(){});}else{nextTrack();}}
function seekMusic(e){var a=document.getElementById('musicAudio'),bar=document.querySelector('.music-progress'),rect=bar.getBoundingClientRect(),r=(e.clientX-rect.left)/rect.width;if(a.duration)a.currentTime=a.duration*Math.max(0,Math.min(1,r));}
function setVol(v){document.getElementById('musicAudio').volume=v;document.getElementById('volBtn').textContent=v==0?'🔇':'🔊';}
function toggleMute(){var a=document.getElementById('musicAudio');a.muted=!a.muted;document.getElementById('volBtn').textContent=a.muted?'🔇':'🔊';}
function fmtTime(s){if(!s||isNaN(s))return'0:00';var m=Math.floor(s/60),sec=Math.floor(s%60);return m+':'+(sec<10?'0':'')+sec;}
/* cycleMode */
function cycleMode(){_mMode=(_mMode+1)%3;var btn=document.getElementById('modeBtn');btn.textContent=_modeLabels[_mMode];}
/* toggleList */
function toggleList(){_mListOpen=!_mListOpen;var d=document.getElementById('musicList'),t=document.getElementById('listToggle');d.classList.toggle('open',_mListOpen);t.textContent=_mListOpen?'📃 ▲':'📃 ▼';}
function loadLyrics(path,name){var lrc=name.replace(/\.[^.]+$/,'.lrc');_mLyrics=[];resetLrcBox();var xhr=new XMLHttpRequest();xhr.open('GET','?p='+encodeURIComponent(path)+'&check='+encodeURIComponent(lrc),true);xhr.onreadystatechange=function(){if(xhr.readyState===4&&xhr.status===200){try{var data=JSON.parse(xhr.responseText);if(data.exists)loadLrcFile(path,lrc);}catch(e){}}};xhr.send();}
function loadLrcFile(path,lrc){var xhr=new XMLHttpRequest();xhr.open('GET','?p='+encodeURIComponent(path)+'&dl='+encodeURIComponent(lrc),true);xhr.onreadystatechange=function(){if(xhr.readyState===4&&xhr.status===200){_mLyrics=parseLrc(xhr.responseText);if(_mLyrics.length>0)renderLrc(0);}};xhr.send();}
function parseLrc(text){var lines=text.split('\n'),result=[];lines.forEach(function(line){var m=line.match(/\[(\d+):(\d+\.?\d*)\](.*)/);if(m){var time=parseInt(m[1])*60+parseFloat(m[2]);var t=m[3].trim();if(t)result.push({time:time,text:t});}});return result.sort(function(a,b){return a.time-b.time;});}
function resetLrcBox(){document.getElementById('musicLrcBox').innerHTML='<div class="no-lrc">🎶</div>';}
function renderLrc(activeIdx){if(_mLyrics.length===0)return;var box=document.getElementById('musicLrcBox');var html='<div class="music-lrc-scroll" id="musicLrcScroll">';var start=Math.max(0,activeIdx-1);for(var i=start;i<start+3&&i<_mLyrics.length;i++){var cls='music-lrc-line';if(i===activeIdx)cls+=' active';else if(i===activeIdx-1||i===activeIdx+1)cls+=' near';html+='<div class="'+cls+'">'+_mLyrics[i].text+'</div>';}
html+='</div>';box.innerHTML=html;}
function updateLrc(curTime){if(_mLyrics.length===0)return;var idx=-1;for(var i=_mLyrics.length-1;i>=0;i--){if(curTime>=_mLyrics[i].time){idx=i;break;}}
if(idx<0)return;var scroll=document.getElementById('musicLrcScroll');if(!scroll){renderLrc(idx);return;}
var start=Math.max(0,idx-1);var lines=scroll.querySelectorAll('.music-lrc-line');var offset=0;for(var j=start;j<idx;j++){var prevIdx=j-start;if(prevIdx<lines.length)offset+=lines[prevIdx].offsetHeight;}
for(var k=0;k<lines.length;k++){lines[k].className='music-lrc-line';var lineIdx=start+k;if(lineIdx===idx)lines[k].className='music-lrc-line active';else if(lineIdx===idx-1||lineIdx===idx+1)lines[k].className='music-lrc-line near';}
scroll.style.transform='translateY(-'+offset+'px)';}
function showAddMusic(){var modal=document.getElementById('musicAddModal'),dirEl=document.getElementById('musicAddDir');modal.style.display='block';_mAddPath='';var xhr=new XMLHttpRequest();xhr.open('GET','?ajax=dirs',true);xhr.onreadystatechange=function(){if(xhr.readyState===4&&xhr.status===200){try{var dirs=JSON.parse(xhr.responseText);renderAddDirList(dirs,'');}catch(e){dirEl.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px">加载失败</div>';}}};xhr.send();}
function hideAddMusic(){document.getElementById('musicAddModal').style.display='none';}
function renderAddDirList(dirs,path){var dirEl=document.getElementById('musicAddDir');if(dirs.length===0){dirEl.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;text-align:center">空目录</div>';return;}dirEl.innerHTML=dirs.map(function(d){return'<div class="music-add-dir-item'+(d.path===_mAddPath?' selected':'')+'" onclick="selectAddDir(\''+d.path.replace(/'/g,"\\\'")+'\')">📁 '+d.name+'</div>';}).join('');}
function selectAddDir(path){_mAddPath=path;var items=document.querySelectorAll('.music-add-dir-item');items.forEach(function(item){item.classList.toggle('selected',item.textContent.includes(path));});}
function confirmAddMusic(){if(!_mAddPath){alert('请选择目录');return;}var exts=['mp3','wav','ogg','flac','aac','m4a','wma','opus'];var xhr=new XMLHttpRequest();xhr.open('GET','?ajax=files&p='+encodeURIComponent(_mAddPath),true);xhr.onreadystatechange=function(){if(xhr.readyState===4&&xhr.status===200){try{var files=JSON.parse(xhr.responseText);var added=0;files.forEach(function(f){var ext=f.name.split('.').pop().toLowerCase();if(exts.indexOf(ext)!==-1&&_mList.every(function(m){return m.path+'/'+m.name!==_mAddPath+'/'+f.name;})){_mList.push({name:f.name,ext:ext,path:_mAddPath});added++;}});if(added>0){renderMusicList();alert('已添加 '+added+' 首音乐');}else{alert('没有新音乐可添加');}hideAddMusic();}catch(e){alert('加载失败');}}};xhr.send();}

</script>

<script>
/* =========================================================
   main.js - 文件管理器核心脚本
   ========================================================= */

/* ---------- 基础变量 & 剪贴板 ---------- */
const token = document.querySelector('input[name="token"]')?.value || '';
let draggedItem = null, lastSelected = null, ctxTarget = null, ctxExt = null;

function getNames() { return [...document.querySelectorAll('.file-check:checked')].map(c => c.value); }
function toggleTheme() {
  var r = document.documentElement, isDark = !r.classList.contains('dark');
  r.classList.toggle('dark', isDark); localStorage.setItem('theme', isDark ? 'dark' : 'light');
  var el = document.getElementById('themeToggle') || document.getElementById('loginThemeToggle');
  if (el) el.textContent = isDark ? '☀️' : '🌙';
}
function updateSel() {
  const c = document.querySelectorAll('.file-check:checked');
  document.querySelectorAll('tr[data-name]').forEach(r => r.classList.toggle('selected', r.querySelector('.file-check')?.checked));
  document.getElementById('selectAll').checked = c.length > 0 && c.length === document.querySelectorAll('.file-check').length;
  const b = document.getElementById('btnUnpack');
  if (b) {
    if (c.length === 1) {
      const r = document.querySelector('.file-check:checked')?.closest('tr');
      b.style.display = r?.dataset.archive === '1' ? 'inline-flex' : 'none';
    } else { b.style.display = 'none'; }
  }
}
function toggleAll() { document.querySelectorAll('.file-check').forEach(c => c.checked = document.getElementById('selectAll').checked); updateSel(); }
function openAceEditor(path, name) {
  var modal = document.getElementById('aceModal');
  if (!modal) return;
  var isEditable = false;
  var row = document.querySelector('tr[data-name="' + name + '"]');
  if (row && row.dataset.editable === '1') isEditable = true;
  if (!isEditable) { toast('暂不支持编辑此文件类型'); return; }
  aceOpen(path, name);
}
function selectRow(r, e) {
  if (e.shiftKey && lastSelected) {
    const rows = [...document.querySelectorAll('tr[data-name]')], i = rows.indexOf(lastSelected), j = rows.indexOf(r);
    rows.slice(Math.min(i, j), Math.max(i, j) + 1).forEach(x => { x.querySelector('.file-check').checked = true; x.classList.add('selected'); });
  } else if (e.ctrlKey || e.metaKey) {
    r.querySelector('.file-check').checked = !r.querySelector('.file-check').checked;
  } else {
    document.querySelectorAll('.file-check').forEach(c => c.checked = false);
    r.querySelector('.file-check').checked = true;
  }
  lastSelected = r; updateSel();
}
function getClip() { try { return JSON.parse(sessionStorage.getItem('clip')); } catch (e) { return null; } }
function setClip(d) { sessionStorage.setItem('clip', JSON.stringify(d)); updatePaste(); }
function clearClip() { sessionStorage.removeItem('clip'); updatePaste(); }
function updatePaste() { const has = !!getClip(); document.querySelectorAll('.paste-item').forEach(e => e.style.display = has ? 'flex' : 'none'); }
function isClipSameFolder() {
  const c = getClip(); if (!c) return false;
  return location.search === c.from;
}

/* ---------- 公共工具 ---------- */
// ajax 辅助函数 - 统一 fetch 模式
async function ajax(url, opts = {}) {
  try {
    var r = await fetch(url, opts);
    return opts.text ? await r.text() : await r.json();
  } catch (e) {
    return { error: opts.errMsg || '请求失败' };
  }
}
function ajaxPost(action, data, errMsg) {
  var fd = new FormData();
  fd.append('token', token);
  for (var k in data) fd.append(k, data[k]);
  return ajax(location.href, { method: 'POST', body: fd, errMsg });
}
function toast(msg) {
  var c = document.createElement('div');
  c.className = 'toast-item';
  c.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:99999;max-width:320px;background:var(--card);padding:10px 16px;border-radius:8px;box-shadow:var(--shadow);font-size:13px;color:var(--text);display:flex;align-items:center;gap:8px;opacity:0;transform:translateY(10px);transition:all .25s ease';
  c.textContent = msg;
  var bar = document.createElement('div');
  bar.style.cssText = 'position:absolute;bottom:0;left:0;height:2px;background:var(--primary);border-radius:0 0 8px 8px;width:100%;transition:width 2.5s linear';
  c.appendChild(bar);
  document.body.appendChild(c);
  requestAnimationFrame(() => { c.style.opacity = '1'; c.style.transform = 'translateY(0)'; bar.style.width = '0'; });
  setTimeout(() => { c.style.opacity = '0'; c.style.transform = 'translateY(10px)'; setTimeout(() => c.remove(), 250); }, 2500);
}
function showProgress(t) {
  let e = document.getElementById('progress');
  if (!e) {
    e = document.createElement('div'); e.id = 'progress'; e.className = 'upload-progress';
    e.innerHTML = '<div class="progress-text"></div><div class="progress-bar"><div class="progress-bar-fill" style="width:0%"></div></div>';
    document.body.appendChild(e);
  }
  e.querySelector('.progress-text').textContent = t;
  return e;
}
function hideProgress() { document.getElementById('progress')?.remove(); }

/* ---------- 右键菜单 ---------- */
function showModal(id) { document.getElementById(id).classList.add('show'); }
function hideModal(id) { document.getElementById(id).classList.remove('show'); }
function hideCtx() { document.getElementById('ctx')?.classList.remove('show'); document.getElementById('ctxBlank')?.classList.remove('show'); }
function showCtx(e, name, isFolder, isArchive) {
  e.preventDefault(); ctxTarget = name;
  ctxExt = document.querySelector('tr[data-name="' + name + '"]')?.dataset.ext || '';
  const m = document.getElementById('ctx');
  m.style.left = e.pageX + 'px'; m.style.top = e.pageY + 'px';
  document.getElementById('ctxDl').style.display = isFolder ? 'none' : 'flex';
  document.getElementById('ctxEdit').style.display = isFolder ? 'none' : 'flex';
  document.getElementById('ctxUnpack').style.display = isArchive ? 'flex' : 'none';
  document.querySelectorAll('.paste-folder-item').forEach(x => x.style.display = isFolder && getClip() ? 'flex' : 'none');
  document.querySelectorAll('#ctx .paste-item').forEach(x => x.style.display = 'none');
  m.classList.add('show');
  const rect = m.getBoundingClientRect();
  if (rect.right > window.innerWidth) m.style.left = e.pageX - rect.width + 'px';
  if (rect.bottom > window.innerHeight) m.style.top = e.pageY - rect.height + 'px';
}
function showBlankCtx(e) {
  e.preventDefault();
  const m = document.getElementById('ctxBlank');
  m.style.left = e.pageX + 'px'; m.style.top = e.pageY + 'px';
  m.classList.add('show');
  const rect = m.getBoundingClientRect();
  if (rect.right > window.innerWidth) m.style.left = e.pageX - rect.width + 'px';
  if (rect.bottom > window.innerHeight) m.style.top = e.pageY - rect.height + 'px';
}
function batchDelete() {
  const n = getNames();
  if (!n.length) { toast('请先选择'); return; }
  if (!confirm('确定删除' + n.length + '项?')) return;
  const f = document.createElement('form'); f.method = 'post';
  f.innerHTML = '<input name="token" value="' + token + '">';
  n.forEach(x => { const i = document.createElement('input'); i.name = 'delete[]'; i.value = x; f.appendChild(i); });
  document.body.appendChild(f); f.submit();
}
async function toggleStar(path, btn) {
  const fd = new FormData(); fd.append('ajax_star', '1'); fd.append('star_path', path); fd.append('token', token);
  try {
    const r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json();
    if (d.success) {
      if (d.starred) { if (!stars.includes(path)) stars.push(path); } else { const i = stars.indexOf(path); if (i >= 0) stars.splice(i, 1); }
      refreshFileList(); refreshStarState();
    } else { toast(d.error || '操作失败'); }
  } catch (e) { toast('网络错误'); }
}
function refreshStarState() { document.querySelectorAll('[data-star-path]').forEach(el => { const p = el.dataset.starPath, isStar = stars.includes(p); el.textContent = isStar ? '★' : '☆'; el.classList.toggle('starred', isStar); }); }

/* ---------- 列表刷新(核心) ---------- */
function refreshFileList() {
  var u = location.href, tok = document.querySelector('input[name="token"]')?.value || '', fd = new FormData(); fd.append('token', tok);
  fetch(u, { method: 'POST', body: fd }).then(r => r.text()).then(html => {
    // Session 检测:登录页很小,应用页很大(~180KB)
    // 如果响应体包含 login-box class,说明是登录页需重新认证
    if (html.includes('login-box') || html.length < 3000) { location.reload(); return; }
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nc = doc.querySelector('.table-container'), oc = document.querySelector('.table-container');
    if (nc && oc) {
      oc.innerHTML = nc.innerHTML;
      document.querySelectorAll('.folder-link').forEach(bindFolderDrop);
      document.querySelectorAll('[data-ctx]').forEach(el => el.addEventListener('contextmenu', e => showCtx(e, el.dataset.name, el.dataset.type === 'folder', el.dataset.archive === '1')));
      document.querySelectorAll('[draggable="true"]').forEach(el => {
        el.addEventListener('dragstart', e => {
          const isSelected = el.closest('tr')?.classList.contains('selected');
          if (isSelected) {
            draggedItem = [...document.querySelectorAll('tr.selected .file-check')].map(c => c.value);
          } else {
            draggedItem = [el.dataset.name];
          }
          el.classList.add('dragging');
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', draggedItem.join('\n'));
        });
        el.addEventListener('dragend', () => { el.classList.remove('dragging'); draggedItem = null; });
      });
      if (typeof initTable === 'function') initTable();
      updateSel(); updatePaste(); refreshStarState(); toast('已刷新');
    } else { location.reload(); }
  }).catch(() => { location.reload(); });
}

/* ---------- 重命名 + 拖拽移动 ---------- */
function startRename(name) {
  const row = document.querySelector('tr[data-name="' + name + '"]');
  if (!row) return;
  const cell = row.querySelectorAll('td')[2], isFolder = row.dataset.type === 'folder',
    icon = isFolder ? '📁' : (row.dataset.archive === '1' ? '📦' : '📄'),
    link = isFolder ? (cell.querySelector('a')?.href || '') : '';
  cell.innerHTML = '<form method="post" style="display:inline-flex;align-items:center;gap:4px" onclick="event.stopPropagation()">' + icon + ' <input type="hidden" name="token" value="' + token + '"><input type="hidden" name="rename_from" value="' + name + '"><input type="text" name="rename_to" class="inline-input" value="' + name + '" required autofocus><button type="submit" class="btn btn-primary" style="padding:2px 8px;font-size:12px">✓</button><button type="button" class="btn" style="padding:2px 8px;font-size:12px" onclick="cancelRename(this,\'' + name + '\',\'' + icon + '\',' + isFolder + ',\'' + link + '\')">✕</button></form>';
  row.ondblclick = null; row.draggable = false;
  var inp = cell.querySelector('input[name="rename_to"]');
  inp.onkeydown = function(e) { if (e.key === 'Escape') { e.preventDefault(); cancelRename(inp.nextElementSibling.nextElementSibling, name, icon, isFolder, link); } };
}
function cancelRename(btn, name, icon, isFolder, link) {
  const row = btn.closest('tr'), cell = row.querySelectorAll('td')[2];
  if (isFolder) {
    cell.innerHTML = '<a href="' + link + '" class="folder-link" data-folder="' + name + '" onclick="event.stopPropagation()">' + icon + ' ' + name + '</a>';
    bindFolderDrop(cell.querySelector('.folder-link'));
  } else { cell.innerHTML = '<span>' + icon + '</span> ' + name; }
  row.ondblclick = isFolder ? () => location.href = link : () => location.href = '?p=' + encodeURIComponent(new URLSearchParams(location.search).get('p') || '') + '&dl=' + encodeURIComponent(name);
  row.draggable = true;
}
function bindFolderDrop(el) {
  if (!el) return;
  el.addEventListener('dragover', e => { e.preventDefault(); e.stopPropagation(); el.classList.add('drag-over'); });
  el.addEventListener('dragleave', () => el.classList.remove('drag-over'));
  el.addEventListener('drop', async e => {
    e.preventDefault(); e.stopPropagation(); el.classList.remove('drag-over');
    const folder = el.dataset.folder;
    if (draggedItem) {
      const fd = new FormData();
      if (Array.isArray(draggedItem) && draggedItem.length > 1) {
        fd.append('ajax_move_batch', '1'); fd.append('files', JSON.stringify(draggedItem)); fd.append('dst', folder); fd.append('token', token);
      } else {
        fd.append('ajax_move', '1'); fd.append('src', Array.isArray(draggedItem) ? draggedItem[0] : draggedItem); fd.append('dst', folder); fd.append('token', token);
      }
      try { const r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json(); d.success ? refreshFileList() : toast(d.error || '移动失败'); } catch (e) { toast('移动失败'); }
    } else { handleDrop(e, folder); }
  });
}

/* ---------- 图片预览 ---------- */
function showImagePreview(path, name) {
  var imgList = [];
  document.querySelectorAll('tr[data-image="1"]').forEach(function(el) { var fn = el.dataset.name, p = new URLSearchParams(location.search).get('p') || ''; imgList.push({name: fn, path: p}); });
  imageList = imgList;
  imageIndex = imgList.findIndex(function(x) { return x.name === name; });
  if (imageIndex < 0) imageIndex = 0;
  updateImagePreview();
  showModal('imagePreviewModal');
}
function updateImagePreview() {
  if (imageIndex < 0 || !imageList || imageIndex >= imageList.length) return;
  var cur = imageList[imageIndex];
  document.getElementById('imagePreviewImg').src = '?p=' + encodeURIComponent(cur.path || '') + '&raw=' + encodeURIComponent(cur.name);
  document.getElementById('imageCounter').textContent = (imageIndex + 1) + ' / ' + imageList.length;
  document.getElementById('imagePrevBtn').style.opacity = imageIndex <= 0 ? '0.3' : '0.7';
  document.getElementById('imageNextBtn').style.opacity = imageIndex >= imageList.length - 1 ? '0.3' : '0.7';
}
function navigateImage(dir) {
  if (!imageList || imageList.length <= 0) return;
  if ((dir < 0 && imageIndex <= 0) || (dir > 0 && imageIndex >= imageList.length - 1)) return;
  imageIndex += dir;
  updateImagePreview();
}
var imageList = [], imageIndex = 0;
document.addEventListener('keydown', function(e) {
  if (document.getElementById('imagePreviewModal').style.display !== 'none') {
    if (e.key === 'ArrowLeft') navigateImage(-1);
    else if (e.key === 'ArrowRight') navigateImage(1);
    else if (e.key === 'Escape') hideImagePreview();
  }
});
function hideImagePreview() {
  hideModal('imagePreviewModal');
  document.getElementById('imagePreviewImg').src = '';
}

/* ---------- 视频播放 ---------- */
function showVideoPreview(path, name) {
  var player = document.getElementById('videoPreviewPlayer');
  player.src = '?p=' + encodeURIComponent(path || '') + '&raw=' + encodeURIComponent(name);
  showModal('videoPreviewModal');
  player.play();
}
function hideVideoPreview() {
  var player = document.getElementById('videoPreviewPlayer');
  player.pause();
  player.src = '';
  hideModal('videoPreviewModal');
}

/* ---------- 权限修改 ---------- */
var chmodRow = null, chmodName = '', chmodPath = '';
function showChmodModal(name, path, cell) {
  chmodRow = cell.closest('tr');
  chmodName = name;
  chmodPath = path;
  var perms = cell.textContent.trim();
  document.getElementById('chmodName').textContent = name;
  document.getElementById('chmodInput').value = perms;
  showModal('chmodModal');
  setTimeout(function() { document.getElementById('chmodInput').focus(); }, 50);
}
function applyChmod() {
  var mode = document.getElementById('chmodInput').value.trim();
  if (!/^[0-7]{3,4}$/.test(mode)) { alert('请输入 3 或 4 位八进制数(如 755)'); return; }
  var fd = new FormData();
  fd.append('token', token);
  fd.append('ajax_chmod', '1');
  fd.append('name', chmodName);
  fd.append('mode', mode);
  fetch(location.href, { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
      if (d.success) {
        if (chmodRow) {
          var cell = chmodRow.querySelector('.perms-cell');
          if (cell) cell.textContent = d.perms || mode;
        }
        hideModal('chmodModal');
        toast('权限已更新');
      } else { alert(d.error || '修改失败'); }
    }).catch(() => alert('修改失败'));
}

/* ---------- 粘贴/移动 ---------- */
async function pasteTo(folder) {
  const c = getClip();
  if (!c) return;
  const dst_folder = folder || '';
  if (c.files && c.files.length > 1) {
    const fd = new FormData(); fd.append('ajax_paste_batch', '1'); fd.append('mode', c.mode); fd.append('from', c.from); fd.append('dst_folder', dst_folder); fd.append('files', JSON.stringify(c.files)); fd.append('token', token);
    try {
      const r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json();
      if (d.conflict) {
        showPasteBatchConflict(d);
      } else if (d.success) { clearClip(); refreshFileList(); toast('已完成 ' + d.copied + ' 项'); } else { toast(d.error || '粘贴失败'); }
    } catch (e) { toast('粘贴失败'); }
  } else {
    const name = c.files ? c.files[0] : c.name;
    const fd = new FormData(); fd.append('ajax_paste', '1'); fd.append('mode', c.mode); fd.append('src', name); fd.append('from', c.from); fd.append('dst_folder', dst_folder); fd.append('token', token);
    try {
      const r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json();
      if (d.conflict) {
        showPasteConflict(d, c, dst_folder);
      } else if (d.success) {
        clearClip(); refreshFileList();
      } else { toast(d.error || '粘贴失败'); }
    } catch (e) { toast('粘贴失败'); }
  }
}
function showPasteConflict(d, clip, dst_folder) {
  var srcSize = d.srcSize, dstSize = d.dstSize;
  var srcStr = srcSize === null ? '文件夹' : formatSize(srcSize);
  var dstStr = dstSize === null ? '文件夹' : formatSize(dstSize);
  var srcDate = new Date(d.srcMtime * 1000).toLocaleString('zh-CN');
  var dstDate = new Date(d.dstMtime * 1000).toLocaleString('zh-CN');
  var m = document.getElementById('pasteConflictModal') || (function() {
    var el = document.createElement('div'); el.id = 'pasteConflictModal'; el.className = 'modal';
    el.innerHTML = '<div class="modal-backdrop"></div><div class="modal-dialog" style="max-width:400px">'
      + '<div class="modal-header" style="display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600">⚠️ 文件冲突</div>'
      + '<div class="modal-body" id="pasteConflictBody" style="padding:16px 20px"></div>'
      + '<div class="modal-footer" id="pasteConflictFooter" style="padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end"></div>'
      + '</div></div>';
    document.body.appendChild(el);
    return el;
  }());
  document.getElementById('pasteConflictBody').innerHTML = '<div style="font-size:13px;margin-bottom:12px">目标位置已存在 <strong>' + d.src + '</strong></div>'
    + '<table style="width:100%;border-collapse:collapse;font-size:12px">'
    + '<tr style="background:var(--sidebar-bg)"><th style="padding:6px 8px;text-align:left;color:var(--muted)"></th><th style="padding:6px 8px;text-align:left">源文件</th><th style="padding:6px 8px;text-align:left">目标文件</th></tr>'
    + '<tr><td style="padding:6px 8px;color:var(--muted)">大小</td><td style="padding:6px 8px">' + srcStr + '</td><td style="padding:6px 8px">' + dstStr + '</td></tr>'
    + '<tr><td style="padding:6px 8px;color:var(--muted)">修改时间</td><td style="padding:6px 8px">' + srcDate + '</td><td style="padding:6px 8px">' + dstDate + '</td></tr>'
    + '</table>';
  document.getElementById('pasteConflictFooter').innerHTML = ''
    + '<button class="btn" style="padding:6px 16px" onclick="hideModal(\'pasteConflictModal\');resolvePaste(null,false)">跳过</button>'
    + '<button class="btn" style="padding:6px 16px" onclick="hideModal(\'pasteConflictModal\');resolvePaste(null,true)">✓ 替换</button>';
  m.classList.add('show');
  window._pasteConflict = { src: d.src, clip: clip, dst_folder: dst_folder };
}
function resolvePaste(c, useSrc) {
  c = c || window._pasteConflict; if (!c) return;
  window._pasteConflict = null;
  var fd = new FormData();
  fd.append('ajax_paste_resolve', '1');
  fd.append('src', c.src);
  fd.append('from', c.clip.from);
  fd.append('dst_folder', c.dst_folder);
  fd.append('mode', c.clip.mode);
  fd.append('action', useSrc ? 'src' : 'skip');
  fd.append('token', token);
  fetch(location.href, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
    if (d.success && !d.skipped) clearClip();
    if (d.success) refreshFileList();
    else toast(d.error || '操作失败');
  }).catch(() => toast('操作失败'));
}
function showPasteBatchConflict(d) {
  var cis = d.conflict_info || [];
  var m = document.getElementById('pasteBatchConflictModal') || (function() {
    var el = document.createElement('div'); el.id = 'pasteBatchConflictModal'; el.className = 'modal';
    el.innerHTML = '<div class="modal-backdrop"></div><div class="modal-dialog" style="max-width:540px">'
      + '<div class="modal-header" style="display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600">⚠️ 批量冲突</div>'
      + '<div class="modal-body" id="pasteBatchConflictBody" style="padding:12px 16px;max-height:320px;overflow-y:auto"></div>'
      + '<div class="modal-footer" id="pasteBatchConflictFooter" style="padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end"></div>'
      + '</div></div>';
    document.body.appendChild(el);
    return el;
  }());
  var total = cis.length;
  var html = '<div style="font-size:12px;color:var(--muted);margin-bottom:8px">目标位置已存在 ' + total + ' 个同名文件:</div>';
  html += '<table style="width:100%;border-collapse:collapse;font-size:12px">'
    + '<tr style="background:var(--sidebar-bg)"><th style="padding:5px 8px;width:32px"><input type="checkbox" id="batchSelAll" checked onchange="toggleBatchAll(this)"></th>'
    + '<th style="padding:5px 8px;text-align:left">文件名</th><th style="padding:5px 8px;text-align:left">源</th><th style="padding:5px 8px;text-align:left">目标</th></tr>';
  cis.forEach(function(c, i) {
    var srcStr = c.srcSize === null ? '文件夹' : formatSize(c.srcSize);
    var dstStr = c.dstSize === null ? '文件夹' : formatSize(c.dstSize);
    var srcDate = new Date(c.srcMtime * 1000).toLocaleString('zh-CN');
    var dstDate = new Date(c.dstMtime * 1000).toLocaleString('zh-CN');
    html += '<tr><td style="padding:5px 8px"><input type="checkbox" class="batch-item-cb" checked data-i="' + i + '"></td>'
      + '<td style="padding:5px 8px;font-weight:500">📄 ' + c.name + '</td>'
      + '<td style="padding:5px 8px">' + srcStr + '<br><span style="color:var(--muted);font-size:11px">' + srcDate + '</span></td>'
      + '<td style="padding:5px 8px">' + dstStr + '<br><span style="color:var(--muted);font-size:11px">' + dstDate + '</span></td></tr>';
  });
  html += '</table>';
  m.querySelector('.modal-header').textContent = '⚠️ 批量冲突 (' + total + ' 项)';
  document.getElementById('pasteBatchConflictBody').innerHTML = html;
  document.getElementById('pasteBatchConflictFooter').innerHTML = ''
    + '<button class="btn" style="padding:6px 16px" onclick="hideModal(\'pasteBatchConflictModal\');">跳过</button>'
    + '<button class="btn btn-primary" style="padding:6px 16px" onclick="hideModal(\'pasteBatchConflictModal\');resolveBatchConflict()">✓ 替换选中</button>';
  m.classList.add('show');
  window._batchConflict = { conflict_info: cis, mode: d.mode, from: d.from, dst_folder: d.dst_folder };
}
function toggleBatchAll(el) {
  document.querySelectorAll('.batch-item-cb').forEach(function(cb) { cb.checked = el.checked; });
}
function resolveBatchConflict() {
  var c = window._batchConflict; if (!c) return;
  window._batchConflict = null;
  var names = [];
  document.querySelectorAll('.batch-item-cb:checked').forEach(function(cb) {
    var ci = c.conflict_info[parseInt(cb.dataset.i)];
    if (ci) names.push(ci.name);
  });
  if (names.length === 0) { hideModal('pasteBatchConflictModal'); return; }
  var fd = new FormData();
  fd.append('ajax_paste_batch_resolve', '1');
  fd.append('conflicts', JSON.stringify(names));
  fd.append('mode', c.mode);
  fd.append('from', c.from);
  fd.append('dst_folder', c.dst_folder);
  fd.append('token', token);
  fetch(location.href, { method: 'POST', body: fd }).then(r => r.json()).then(function(d) {
    if (d.success) clearClip();
    refreshFileList();
    toast(d.success ? '已完成 ' + d.copied + ' 项' : (d.error || '操作失败'));
  }).catch(function() { toast('操作失败'); });
}
function formatSize(bytes) {
  if (bytes === null) return 'N/A';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
  if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
  return (bytes / 1073741824).toFixed(2) + ' GB';
}
async function ajaxAct(fd, ok) { const r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json(); d.success ? ok() : toast(d.error || '操作失败'); }

/* ---------- 打包压缩 ---------- */
async function packFiles(type) {
  let n = getNames();
  if (!n.length) { if (!ctxTarget) { toast('请先选择文件'); return; } n = [ctxTarget]; }
  const p = showProgress('正在打包 ' + n.length + ' 个文件 (' + type.toUpperCase() + ')...');
  const fd = new FormData(); fd.append('ajax_pack', type); fd.append('token', token);
  n.forEach(x => fd.append('files[]', x));
  try {
    await new Promise((res, rej) => {
      const xhr = new XMLHttpRequest(); xhr.open('POST', location.href);
      xhr.upload.onprogress = e => { if (e.lengthComputable) { const pct = Math.round(e.loaded / e.total * 90); p.querySelector('.progress-bar-fill').style.width = pct + '%'; p.querySelector('.progress-text').textContent = '正在打包 ' + n.length + ' 个文件 (' + type.toUpperCase() + ') ' + pct + '%'; } };
      xhr.onload = () => {
        if (xhr.status === 200) {
          try { const d = JSON.parse(xhr.responseText); if (d.success) { p.querySelector('.progress-bar-fill').style.width = '100%'; p.querySelector('.progress-text').textContent = '已创建: ' + d.name; setTimeout(() => refreshFileList(), 800); res(); } else { toast(d.error); hideProgress(); res(); } } catch (ex) { toast('打包失败'); hideProgress(); res(); }
        } else { toast('打包失败'); hideProgress(); res(); }
      };
      xhr.onerror = () => { toast('网络错误'); hideProgress(); rej(); };
      xhr.send(fd);
    });
  } catch (e) { toast('打包失败'); hideProgress(); }
}
function showPackSub(el) { const sub = el.querySelector('.context-submenu'); if (sub) { sub.style.display = 'block'; if (sub.getBoundingClientRect().right > window.innerWidth) { sub.style.left = 'auto'; sub.style.right = '100%'; } } }
function hidePackSub(el) { const sub = el.querySelector('.context-submenu'); if (sub) sub.style.display = 'none'; }
function togglePackSub(el) { const sub = el.querySelector('.context-submenu'); if (sub) { if (sub.style.display === 'block') { sub.style.display = 'none'; } else { showPackSub(el); } } }
function showSubmenu(parent, id) { const sub = document.getElementById(id); if (sub) { sub.classList.add('show'); if (sub.getBoundingClientRect().right > window.innerWidth) { sub.style.left = 'auto'; sub.style.right = '100%'; } } }
function hideSubmenu(parent) { const sub = parent.querySelector('.context-submenu'); if (sub) sub.classList.remove('show'); }
function startDownload(name) {
  const p = showProgress('正在准备: ' + name); p.querySelector('.progress-bar-fill').style.width = '30%';
  const a = document.createElement('a'); a.href = '?p=<?=urlencode($p)?>&dl=' + encodeURIComponent(name); a.download = name;
  a.onclick = () => { p.querySelector('.progress-text').textContent = name + ' '; setTimeout(() => { p.querySelector('.progress-bar-fill').style.width = '100%'; setTimeout(hideProgress, 600); }, 200); };
  document.body.appendChild(a); a.click(); a.remove();
}

/* ---------- 解压(到文件夹/弹窗输入) ---------- */
function showUnpackToModal(name) {
  document.getElementById('unpackArchiveInput').value = name;
  var base = name.replace(/\.tar\.(gz|bz2|xz)$/i, '').replace(/\.[a-z0-9]+$/i, '');
  var input = document.getElementById('unpackTargetInput');
  input.value = base;
  updateUnpackPreview();
  showModal('unpackToModal');
  setTimeout(function () { input.focus(); input.select(); }, 50);
}
function updateUnpackPreview() {
  var target = document.getElementById('unpackTargetInput').value.trim();
  var preview = document.getElementById('unpackPreviewPath');
  preview.textContent = target ? '/' + currentPath + '/' + target + '/...' : '请输入文件夹名称';
}
function startUnpackTo() {
  var archive = document.getElementById('unpackArchiveInput').value;
  var target = document.getElementById('unpackTargetInput').value.trim();
  if (!archive || !target) { toast('请填写目标文件夹'); return; }
  hideModal('unpackToModal'); doUnpack(archive, 'custom', target);
}
async function unpackFile(name, mode) { if (!mode) mode = 'current'; doUnpack(name, mode, null); }
async function doUnpack(name, mode, target) {
  var p = showProgress('正在解压 ' + name + '...'); p.querySelector('.progress-bar-fill').style.width = '50%';
  var fd = new FormData(); fd.append('ajax_unpack', '1'); fd.append('archive', name); fd.append('unpack_mode', mode); fd.append('token', token);
  if (mode === 'custom') fd.append('unpack_target', target);
  try {
    var r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json();
    if (d.success) { p.querySelector('.progress-text').textContent = '已解压'; p.querySelector('.progress-bar-fill').style.width = '100%'; setTimeout(function () { refreshFileList(); }, 800); }
    else { toast(d.error); hideProgress(); }
  } catch (e) { toast('解压失败'); hideProgress(); }
}

/* ---------- 解压(目录树选择器) ---------- */
var _unpackDirState = { archive: '', currentPath: '', selectedPath: '' };
async function showUnpackDirModal(name) {
  _unpackDirState.archive = name; _unpackDirState.currentPath = ''; _unpackDirState.selectedPath = '';
  document.getElementById('unpackDirArchiveInput').value = name;
  document.getElementById('unpackDirArchive').textContent = name;
  document.getElementById('unpackDirBreadcrumb').value = '/';
  document.getElementById('unpackDirSelected').style.display = 'none';
  document.getElementById('unpackDirConfirmBtn').disabled = true;
  document.getElementById('unpackDirList').innerHTML = '<div style="padding:16px;text-align:center;color:var(--muted);font-size:13px">加载中...</div>';
  showModal('unpackDirModal');
  await loadUnpackDirList('');
}
async function loadUnpackDirList(relPath) {
  var listEl = document.getElementById('unpackDirList'), breadcrumbEl = document.getElementById('unpackDirBreadcrumb');
  listEl.innerHTML = '<div style="padding:16px;text-align:center;color:var(--muted);font-size:13px">加载中...</div>';
  _unpackDirState.currentPath = relPath;
  breadcrumbEl.value = '/' + (relPath ? relPath + '/' : '');
  var fd = new FormData(); fd.append('ajax_dir_tree', '1'); fd.append('path', relPath); fd.append('token', token);
  try {
    var r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json();
    if (d.error) { listEl.innerHTML = '<div style="padding:16px;text-align:center;color:#e74c3c;font-size:13px">' + d.error + '</div>'; return; }
    var items = d.items || [];
    if (!items.length) { listEl.innerHTML = '<div style="padding:16px;text-align:center;color:var(--muted);font-size:13px">此目录为空</div>'; return; }
    var html = '';
    if (relPath !== '') {
      var parent = relPath.split('/').slice(0, -1).join('/');
      html += '<div class="dir-item dir-up" onclick="loadUnpackDirList(\'' + (parent || '') + '\')" style="display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;border-radius:4px;margin-bottom:2px"><span style="color:var(--muted)">↑</span><span style="color:var(--muted);font-size:13px">.. 返回上级</span></div>';
    }
    for (var i = 0; i < items.length; i++) {
      var item = items[i], isSelected = (_unpackDirState.selectedPath === item.path);
      html += '<div class="dir-item" data-path="' + item.path + '" onclick="selectUnpackDir(\'' + item.path + '\')" ondblclick="loadUnpackDirList(\'' + item.path + '\')" style="display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;border-radius:4px;margin-bottom:2px;background:' + (isSelected ? 'var(--primary)' : 'transparent') + '" onmouseover="if(!this.style.background.includes(\'var(--primary)\'))this.style.background=\'var(--hover)\'" onmouseout="if(!this.dataset.selected)this.style.background=\'transparent\'">' +
        '<span>📁</span><span style="font-size:13px;color:' + (isSelected ? '#fff' : 'var(--text)') + ';flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + item.name + '</span>' +
        '<span style="color:' + (isSelected ? 'rgba(255,255,255,0.6)' : 'var(--muted)') + ';font-size:11px">' + (isSelected ? '✓ 已选' : '双击进入') + '</span></div>';
    }
    listEl.innerHTML = html;
  } catch (e) { listEl.innerHTML = ''; toast('加载失败'); }
}
function selectUnpackDir(path) {
  var items = document.querySelectorAll('#unpackDirList .dir-item[data-path]');
  for (var i = 0; i < items.length; i++) {
    items[i].style.background = 'transparent';
    var span = items[i].querySelector('span:nth-child(3)');
    if (span) { span.style.color = 'var(--text)'; span.textContent = '双击进入'; }
    items[i].dataset.selected = '';
  }
  _unpackDirState.selectedPath = path;
  document.getElementById('unpackDirBreadcrumb').value = '/' + path;
  var selectedEl = document.querySelector('#unpackDirList .dir-item[data-path="' + path + '"]');
  if (selectedEl) { selectedEl.style.background = 'var(--primary)'; selectedEl.dataset.selected = '1'; var span = selectedEl.querySelector('span:nth-child(3)'); if (span) { span.style.color = 'rgba(255,255,255,0.6)'; span.textContent = '✓ 已选'; } }
  document.getElementById('unpackDirSelected').style.display = '';
  document.getElementById('unpackDirSelectedPath').textContent = '/' + path;
  document.getElementById('unpackDirConfirmBtn').disabled = false;
}
function startUnpackDir() {
  var archive = document.getElementById('unpackDirArchiveInput').value, dir = _unpackDirState.selectedPath;
  if (!archive) { toast('未指定压缩包'); return; }
  hideModal('unpackDirModal'); doUnpackDir(archive, dir);
}
async function doUnpackDir(name, dir) {
  var p = showProgress('正在解压 ' + name + ' 到 /' + dir + '/...'); p.querySelector('.progress-bar-fill').style.width = '50%';
  var fd = new FormData(); fd.append('ajax_unpack', '1'); fd.append('archive', name); fd.append('unpack_mode', 'dir'); fd.append('unpack_dir', dir); fd.append('token', token);
  try { var r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json(); if (d.success) { p.querySelector('.progress-text').textContent = '已解压'; p.querySelector('.progress-bar-fill').style.width = '100%'; setTimeout(function () { refreshFileList(); }, 800); } else { toast(d.error || '解压失败'); hideProgress(); } } catch (e) { toast('解压失败'); hideProgress(); }
}
async function jumpUnpackInputPath() {
  var raw = document.getElementById('unpackDirBreadcrumb').value.trim();
  var path = raw.replace(/^\/+|\/+$/g, '');
  _unpackDirState.selectedPath = path; _unpackDirState.currentPath = path;
  var items = document.querySelectorAll('#unpackDirList .dir-item[data-path]');
  for (var i = 0; i < items.length; i++) { items[i].style.background = 'transparent'; items[i].dataset.selected = ''; }
  await loadUnpackDirList(path);
  document.getElementById('unpackDirSelected').style.display = '';
  document.getElementById('unpackDirSelectedPath').textContent = '/' + (path || '');
  document.getElementById('unpackDirConfirmBtn').disabled = false;
}

/* ---------- 上传 ---------- */
async function uploadFiles(files, folder) {
  const p = showProgress('上传中 0/' + files.length + '...');
  for (let i = 0; i < files.length; i++) {
    const fd = new FormData(); fd.append('file', files[i].file); fd.append('relative_path', folder ? folder + '/' + files[i].path : files[i].path); fd.append('token', token);
    p.querySelector('.progress-text').textContent = '上传中 ' + (i + 1) + '/' + files.length + ': ' + files[i].file.name;
    p.querySelector('.progress-bar-fill').style.width = Math.round((i + 1) / files.length * 100) + '%';
    await fetch('?ajax_upload=1&p=' + encodeURIComponent(new URLSearchParams(location.search).get('p') || ''), { method: 'POST', body: fd });
  }
  p.querySelector('.progress-text').textContent = '上传完成 (' + files.length + ' 项)'; p.querySelector('.progress-bar-fill').style.width = '100%';
  setTimeout(() => refreshFileList(), 600);
}
async function readDir(reader) { const all = [], read = () => new Promise(res => reader.readEntries(e => { if (!e.length) res(all); else { all.push(...e); read().then(res); } })); return read(); }
async function collectFiles(e, path) { path = path || ''; const r = []; if (e.isFile) await new Promise(res => e.file(f => { r.push({ file: f, path: path + f.name }); res(); })); else if (e.isDirectory) { const entries = await readDir(e.createReader()); for (const x of entries) r.push(...await collectFiles(x, path + e.name + '/')); } return r; }
async function handleDrop(e, folder) {
  var raw = e.dataTransfer.files, files = [];
  if (raw.length) for (var i = 0; i < raw.length; i++) files.push({ file: raw[i], path: raw[i].webkitRelativePath || raw[i].name });
  if (files.length) uploadFiles(files, folder).catch(() => toast('上传失败')); else toast('未检测到文件');
}
function startOfflineDownload() {
  const url = document.getElementById('offlineUrlInput').value.trim();
  if (!url) { toast('请输入下载链接'); return; }
  const p = showProgress('离线下载准备中...'); p.querySelector('.progress-bar-fill').style.width = '30%';
  const fd = new FormData(); fd.append('token', token); fd.append('ajax_offline', '1'); fd.append('url', url);
  fetch(location.href, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
    if (d.success) { p.querySelector('.progress-text').textContent = '下载完成: ' + d.name; p.querySelector('.progress-bar-fill').style.width = '100%'; setTimeout(() => { hideProgress(); refreshFileList(); }, 800); }
    else { toast(d.error || '下载失败'); hideProgress(); }
  }).catch(() => { toast('离线下载失败'); hideProgress(); });
  hideModal('offlineModal');
}

/* ---------- 回收站 ---------- */
async function emptyTrash() {
  if (!confirm('确定清空回收站吗?')) return;
  const p = showProgress('正在清空回收站...');
  const fd = new FormData(); fd.append('ajax_empty_trash', '1'); fd.append('token', token);
  try { const r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json(); if (d.success) { p.querySelector('.progress-text').textContent = '已清空 ' + d.count + ' 项'; p.querySelector('.progress-bar-fill').style.width = '100%'; setTimeout(() => refreshFileList(), 800); } else { toast(d.error || '清空失败'); hideProgress(); } } catch (e) { toast('清空回收站失败'); hideProgress(); }
}
function showTrashPanel() { document.getElementById('trashPanel')?.classList.add('show'); document.getElementById('trashOverlay')?.classList.add('show'); loadTrashContent(); }
function hideTrashPanel() { document.getElementById('trashPanel')?.classList.remove('show'); document.getElementById('trashOverlay')?.classList.remove('show'); }
async function loadTrashContent() {
  const panel = document.getElementById('trashPanelContent');
  if (!panel) return;
  panel.innerHTML = '<div class="trash-empty">加载中...</div>';
  try {
    const r = await fetch('?ajax_trash_list=1&token=' + encodeURIComponent(token), { headers: { 'X-CSRF-Token': token } }), d = await r.json();
    if (d.items && d.items.length) {
      panel.innerHTML = d.items.map(item => '<div class="trash-item"><span class="trash-item-icon">' + (item.dir ? '📁' : '📄') + '</span><div class="trash-item-info"><div class="trash-item-name">' + item.name + '</div><div class="trash-item-meta">' + item.path + ' · ' + item.time + '</div></div><div class="trash-item-actions"><button class="btn btn-primary" style="padding:3px 8px;font-size:11px" onclick="restoreTrash(\'' + item.key + '\')">恢复</button><button class="btn btn-danger" style="padding:3px 8px;font-size:11px" onclick="purgeTrash(\'' + item.key + '\')">删除</button></div></div>').join('');
      document.getElementById('trashPanelFooter').textContent = '共 ' + d.items.length + ' 项 · 7天后自动删除';
    } else { panel.innerHTML = '<div class="trash-empty">🗑️ 回收站为空</div>'; document.getElementById('trashPanelFooter').textContent = '共 0 项'; }
  } catch (e) { panel.innerHTML = ''; toast('加载失败'); }
}
async function restoreTrash(key) { if (!confirm('确定恢复此项?')) return; try { await fetch('?trash&restore=' + encodeURIComponent(key) + '&token=' + token); refreshFileList(); } catch (e) { toast('恢复失败'); } }
async function purgeTrash(key) { if (!confirm('确定彻底删除此项?')) return; try { await fetch('?trash&purge=' + encodeURIComponent(key) + '&token=' + token); loadTrashContent(); } catch (e) { toast('删除失败'); } }

/* ---------- 侧边栏 & UI ---------- */
function refreshSidebarStars() {
  const starList = document.getElementById('starList');
  if (!starList) return;
  const html = stars.length ? stars.map(sp => { const n = sp.split('/').pop() || './'; return '<li class="sidebar-item ' + (currentPath === sp ? 'active' : '') + '"><span class="item-icon">📁</span><a href="?p=' + encodeURIComponent(sp) + '" class="item-name" title="' + sp.replace(/"/g, '&quot;') + '">' + n + '</a><span class="star-btn starred" onclick="event.stopPropagation();toggleStar(\'' + sp.replace(/'/g, "\\'") + '\',this)" data-star-path="' + sp.replace(/"/g, '&quot;') + '" title="取消收藏">★</span></li>'; }).join('') : '<li style="padding:8px 16px;font-size:12px;color:var(--muted)">暂无收藏</li>';
  starList.innerHTML = html;
}
function toggleHidden() {
  const btn = document.getElementById('toggleHiddenBtn');
  const show = btn.textContent.includes('显示');
  document.querySelectorAll('tr[data-hidden="1"]').forEach(r => r.style.display = show ? '' : 'none');
  btn.innerHTML = show ? '👁️ <s>隐藏</s> 隐藏文件' : '👁️ 显示隐藏文件';
  btn.style.color = show ? 'var(--muted)' : 'var(--text)';
  sessionStorage.setItem('fm_showHidden', show ? '1' : '0');
}
function showPathInput(el) {
  window._addrOrig = el.innerHTML;
  window._addrEl = el;
  const inp = document.createElement('input'); inp.type = 'text';
  const rawPath = decodeURIComponent(location.search.slice(3));
  inp.value = '/' + rawPath.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  inp.style.cssText = 'border:none;background:var(--input-bg);color:var(--text);font-size:13px;outline:1px solid var(--primary);border-radius:4px;padding:2px 6px;width:600px';
  inp.onblur = function () {
    if (!window._addrEl) return;
    var rawValue = this.value.trim().replace(/^\/+|\/+$/g, '').replace(/\\+/g, '/');
    window._addrEl.innerHTML = window._addrOrig;
    window._addrEl = null;
    window._addrOrig = null;
    if (!rawValue) return;
    var p = encodeURIComponent(rawValue);
    fetch('?p=' + p, { method: 'HEAD' }).then(function(r) {
      if (r.ok) location.href = '?p=' + p;
      else showPathError('路径不存在');
    }).catch(function() { showPathError('路径不存在'); });
  };
  inp.onkeydown = function (e) { if (e.key === 'Enter') { this.blur(); } else if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); window._addrEl.innerHTML = window._addrOrig; window._addrEl = null; window._addrOrig = null; this.blur(); hideCtx(); } };
  inp.onclick = function (e) { e.stopPropagation(); };
  el.innerHTML = ''; el.appendChild(inp); inp.focus();
}
function showPathError(msg) {
  var el = window._addrEl || document.querySelector('.breadcrumb');
  var tip = document.createElement('span');
  tip.style.cssText = 'position:fixed;top:120px;left:50%;transform:translateX(-50%);background:#ff3b30;color:#fff;padding:6px 18px;border-radius:8px;font-size:13px;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,.3);pointer-events:none;opacity:0;transition:opacity .2s';
  tip.textContent = msg;
  document.body.appendChild(tip);
  requestAnimationFrame(function() { tip.style.opacity = '1'; });
  setTimeout(function() { tip.style.opacity = '0'; setTimeout(function() { tip.remove(); }, 200); }, 2000);
}
function toggleSection(id) { const sec = document.getElementById(id), title = sec.previousElementSibling; if (sec.style.display === 'none') { sec.style.display = ''; title.classList.remove('collapsed'); } else { sec.style.display = 'none'; title.classList.add('collapsed'); } }

/* ---------- 表格排序 & 列宽拖动 ---------- */
(function () {
  var sortCol = null, sortDir = 'asc', sortInited = false, dragTh = null, dragStartX = 0, dragStartW = 0, resizeInited = false;
  function initSort() { if (sortInited) return; sortInited = true; document.addEventListener('click', function (e) { var th = e.target.closest('th.sortable'); if (!th) return; doSort(th.dataset.sort); }); }
  function doSort(col) { sortCol = col === sortCol ? (sortDir = sortDir === 'asc' ? 'desc' : 'asc', col) : (sortDir = 'asc', col); updateSortIcons(); sortRows(col, sortDir); }
  function updateSortIcons() { document.querySelectorAll('th.sortable .sort-icon').forEach(i => i.textContent = ''); var active = document.querySelector('th[data-sort="' + sortCol + '"] .sort-icon'); if (active) active.textContent = sortDir === 'asc' ? ' ▲' : ' ▼'; }
  function getVal(row, key) { if (key === 'name') return (row.dataset.name || '').toLowerCase(); if (key === 'type') return row.dataset.ext || 'folder'; if (key === 'size') return parseInt(row.dataset.size || 0, 10); if (key === 'mtime') return parseInt(row.dataset.mtime || 0, 10); return ''; }
  function cmp(a, b) { var va = getVal(a, sortCol), vb = getVal(b, sortCol), r = typeof va === 'number' && typeof vb === 'number' ? va - vb : String(va).localeCompare(String(vb), 'zh-CN'); return sortDir === 'asc' ? r : -r; }
  function sortRows(col) {
    var tbody = document.querySelector('.table-container tbody');
    if (!tbody) return;
    var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-type]'));
    if (!allRows.length) return;
    var folders = [], files = [];
    for (var i = 0; i < allRows.length; i++) { if (allRows[i].dataset.type === 'folder') folders.push(allRows[i]); else files.push(allRows[i]); }
    folders.sort(cmp); files.sort(cmp);
    var ordered = folders.concat(files);
    for (var j = 0; j < ordered.length; j++) { tbody.appendChild(ordered[j]); }
  }
  function initResize() { if (resizeInited) return; resizeInited = true; document.addEventListener('mousemove', function (e) { if (!dragTh) return; dragTh.style.width = Math.max(30, dragStartW + (e.pageX - dragStartX)) + 'px'; }); document.addEventListener('mouseup', function () { dragTh = null; }); }
  function addResizeHandles() { document.querySelectorAll('th:not(.no-resize)').forEach(function (th) { if (th.querySelector('.col-resize-handle')) return; var handle = document.createElement('div'); handle.className = 'col-resize-handle'; handle.style.cssText = 'position:absolute;right:-1px;top:0;bottom:0;width:5px;cursor:col-resize;background:transparent;z-index:2'; handle.addEventListener('mousedown', function (e) { e.preventDefault(); e.stopPropagation(); dragTh = e.target.closest('th'); dragStartX = e.pageX; dragStartW = dragTh.offsetWidth; }); th.style.position = 'relative'; th.appendChild(handle); }); }
  window.initTable = function () { initSort(); initResize(); addResizeHandles(); if (sortCol) updateSortIcons(); };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', window.initTable); } else { window.initTable(); }
})();

/* ---------- 新建文件/文件夹 ---------- */
function startNew(type) {
  var table = document.querySelector('#dropZone table tbody') || document.querySelector('#dropZone table'), tr = document.createElement('tr'); tr.className = 'inline-edit';
  var icon = type === 'folder' ? '📁' : '📄', defName = type === 'folder' ? '新建文件夹' : '新建文件';
  tr.innerHTML = '<td></td><td></td><td><span style="margin-right:4px">' + icon + '</span> <input type="text" class="inline-input" value="' + defName + '" data-type="' + type + '" style="min-width:120px"> <button type="button" class="btn btn-primary" style="padding:2px 8px;font-size:12px">✓</button> <button type="button" class="btn" style="padding:2px 8px;font-size:12px">✕</button></td><td>' + type + '</td><td>-</td><td>-</td>';
  var firstRow = table.querySelector('tr[data-name]');
  if (firstRow) table.insertBefore(tr, firstRow); else table.appendChild(tr);
  var input = tr.querySelector('.inline-input'), btnOk = tr.querySelectorAll('button')[0], btnCancel = tr.querySelectorAll('button')[1];
  function doCreate() {
    var name = input.value.trim();
    if (!name) { toast('名称不能为空'); return; }
    var fd = new FormData(); fd.append('token', token); fd.append('ajax_check_name', '1'); fd.append('name', name); fd.append('type', type);
    fetch(location.href, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
      if (d.error) { toast(d.error); return; }
      if (d.duplicate) { input.value = d.suggest; input.style.background = 'rgba(255,204,0,.15)'; input.style.borderColor = '#ffcc00'; input.select(); var tip = tr.querySelector('.dup-tip'); if (!tip) { tip = document.createElement('span'); tip.className = 'dup-tip'; tip.style.cssText = 'color:#ffcc00;font-size:11px;margin-left:4px'; tr.querySelector('td:nth-child(3)').appendChild(tip); } tip.textContent = '→ 将创建为 ' + d.suggest; return; }
      var cfd = new FormData(); cfd.append('token', token); cfd.append('ajax_new', '1'); cfd.append(type === 'folder' ? 'new_folder' : 'new_file', name);
      fetch(location.href, { method: 'POST', body: cfd }).then(r => r.json()).then(d => { if (d.error) toast(d.error); else refreshFileList(); }).catch(() => toast('创建失败'));
    }).catch(() => toast('创建失败'));
  }
  btnOk.onclick = function (e) { e.stopPropagation(); doCreate(); };
  btnCancel.onclick = function (e) { e.stopPropagation(); tr.remove(); };
  input.focus(); input.select();
  input.addEventListener('keydown', function (e) { e.stopPropagation(); if (e.key === 'Enter') { e.preventDefault(); doCreate(); } else if (e.key === 'Escape') { tr.remove(); } });
}

/* ---------- 初始化 & 键盘导航 ---------- */
function getVisibleRows() { return [...document.querySelectorAll('tr[data-name]')].filter(r => r.style.display !== 'none'); }
function getSelectedRow() { return document.querySelector('tr[data-name].selected') || document.querySelector('tr[data-name] .file-check:checked')?.closest('tr'); }
function selectRowByIndex(idx) { const rows = getVisibleRows(); if (rows[idx]) { document.querySelectorAll('.file-check').forEach(c => c.checked = false); rows[idx].querySelector('.file-check').checked = true; rows[idx].classList.add('selected'); lastSelected = rows[idx]; updateSel(); } }
function navUp() { const rows = getVisibleRows(), cur = getSelectedRow(); if (!rows.length) return; const i = cur ? rows.indexOf(cur) : -1; selectRowByIndex(i > 0 ? i - 1 : rows.length - 1); }
function navDown() { const rows = getVisibleRows(), cur = getSelectedRow(); if (!rows.length) return; const i = cur ? rows.indexOf(cur) : -1; selectRowByIndex(i < rows.length - 1 ? i + 1 : 0); }
function navHome() { const rows = getVisibleRows(); rows.length && selectRowByIndex(0); }
function navEnd() { const rows = getVisibleRows(); rows.length && selectRowByIndex(rows.length - 1); }
function navEnter() { const row = getSelectedRow(); if (!row) return; const type = row.dataset.type, name = row.dataset.name; if (type === 'folder') { const p = new URLSearchParams(location.search).get('p') || ''; const fp = p ? p + '/' + name : name; location.href = '?p=' + encodeURIComponent(fp); } else { const archive = row.dataset.archive === '1'; if (archive) unpackFile(name); else startDownload(name); } }
function navBack() { const p = new URLSearchParams(location.search).get('p') || ''; if (!p) { history.back(); return; } const parts = p.split('/').filter(x => x); parts.pop(); location.href = '?p=' + encodeURIComponent(parts.join('/')); }
function navForward() { history.forward(); }

document.addEventListener('keydown', function (e) {
  if (window._aceOpen) return;
  if (e.key === 'Escape') {
    if(window._aceSaving)return;
    // 关闭所有弹层
    document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show'));
    hideCtx();
    aceClose();
    // 关闭工具栏下拉菜单
    document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    // 关闭右键子菜单
    document.querySelectorAll('.context-submenu').forEach(m => m.style.display = 'none');
    // 恢复地址栏编辑
    if (window._addrEl && window._addrOrig) { window._addrEl.innerHTML = window._addrOrig; window._addrEl = null; window._addrOrig = null; }
    // 取消内联编辑(新建行)
    document.querySelectorAll('tr.inline-edit').forEach(tr => tr.remove());
    return;
  }
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
  if (e.key === 'Enter') { e.preventDefault(); navEnter(); }
  else if ((e.ctrlKey || e.metaKey) && e.key === 'a') { e.preventDefault(); document.querySelectorAll('.file-check').forEach(c => c.checked = true); updateSel(); }
  else if ((e.ctrlKey || e.metaKey) && e.key === 'c') { e.preventDefault(); const n = getNames(); if (!n.length && ctxTarget) n.push(ctxTarget); if (n.length) { setClip({ mode: 'copy', files: n, from: location.search }); toast(n.length > 1 ? '已复制 ' + n.length + ' 项' : '已复制: ' + n[0]); } }
  else if ((e.ctrlKey || e.metaKey) && e.key === 'x') { e.preventDefault(); const n = getNames(); if (!n.length && ctxTarget) n.push(ctxTarget); if (n.length) { setClip({ mode: 'cut', files: n, from: location.search }); toast(n.length > 1 ? '已剪切 ' + n.length + ' 项' : '已剪切: ' + n[0]); } }
  else if ((e.ctrlKey || e.metaKey) && e.key === 'v') { e.preventDefault(); pasteTo(''); }
  else if ((e.ctrlKey || e.metaKey) && e.key === 'f') { e.preventDefault(); showSearchModal(); }
  else if (e.altKey && e.key === 'n') { e.preventDefault(); startNew('file'); }
  else if (e.altKey && e.key === 'm') { e.preventDefault(); startNew('folder'); }
  else if (e.key === 'Delete') { const n = getNames(); if (n.length) batchDelete(); }
  else if (e.key === 'Backspace' && e.ctrlKey) { e.preventDefault(); navForward(); }
  else if (e.key === 'Backspace') { e.preventDefault(); navBack(); }
  else if (e.key === 'F2') { e.preventDefault(); const n = getNames(); if (n.length === 1) startRename(n[0]); }
  else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); navUp(); }
  else if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); navDown(); }
  else if (e.key === 'Home') { e.preventDefault(); navHome(); }
  else if (e.key === 'End') { e.preventDefault(); navEnd(); }
  else if (e.key === 'd') { const n = getNames(); if (n.length) batchDelete(); }
});
document.addEventListener('click', hideCtx);

// 主题初始化
(function () { var t = localStorage.getItem('theme'); var isDark = t === 'dark' || (!t && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches); if (isDark) { document.documentElement.classList.add('dark'); } var el = document.getElementById('themeToggle') || document.getElementById('loginThemeToggle'); if (el) el.textContent = isDark ? '☀️' : '🌙'; })();

// 拖拽区域
const dropZone = document.getElementById('dropZone');
let dropTarget = null;
dropZone?.addEventListener('dragover', function (e) { e.preventDefault(); !dropTarget && dropZone.classList.add('drag-over'); });
dropZone?.addEventListener('dragleave', function () { dropZone.classList.remove('drag-over'); });
dropZone?.addEventListener('drop', function (e) { e.preventDefault(); dropZone.classList.remove('drag-over'); dropTarget = null; handleDrop(e, ''); });
document.querySelectorAll('[draggable="true"]').forEach(function (el) {
  el.addEventListener('dragstart', function (e) {
    const isSelected = el.closest('tr')?.classList.contains('selected');
    if (isSelected) {
      draggedItem = [...document.querySelectorAll('tr.selected .file-check')].map(c => c.value);
    } else {
      draggedItem = [el.dataset.name];
    }
    el.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', draggedItem.join('\n'));
  });
  el.addEventListener('dragend', function () { el.classList.remove('dragging'); draggedItem = null; });
});
document.querySelectorAll('.folder-link').forEach(bindFolderDrop);
document.querySelectorAll('.breadcrumb a[data-folder]').forEach(function(el) {
  if (!el) return;
  el.addEventListener('dragover', function(e) { e.preventDefault(); e.stopPropagation(); el.classList.add('drag-over'); });
  el.addEventListener('dragleave', function() { el.classList.remove('drag-over'); });
  el.addEventListener('drop', async function(e) {
    e.preventDefault(); e.stopPropagation(); el.classList.remove('drag-over');
    var folder = el.dataset.folder;
    if (draggedItem) {
      var fd = new FormData();
      if (Array.isArray(draggedItem) && draggedItem.length > 1) {
        fd.append('ajax_move_batch', '1'); fd.append('files', JSON.stringify(draggedItem)); fd.append('dst', folder); fd.append('dst_abs', '1'); fd.append('token', token);
      } else {
        fd.append('ajax_move', '1'); fd.append('src', Array.isArray(draggedItem) ? draggedItem[0] : draggedItem); fd.append('dst', folder); fd.append('dst_abs', '1'); fd.append('token', token);
      }
      try { var r = await fetch(location.href, { method: 'POST', body: fd }), d = await r.json(); d.success ? refreshFileList() : toast(d.error || '移动失败'); } catch(e) { toast('移动失败'); }
    } else { handleDrop(e, folder); }
  });
});
document.querySelectorAll('[data-ctx]').forEach(function (el) { el.addEventListener('contextmenu', function (e) { showCtx(e, el.dataset.name, el.dataset.type === 'folder', el.dataset.archive === '1'); }); });
dropZone?.addEventListener('contextmenu', function (e) { if (e.target.closest('tr[data-name]')) return; showBlankCtx(e); });
updatePaste(); refreshStarState();

// 隐藏文件状态初始化
(function () { var sh = sessionStorage.getItem('fm_showHidden'); if (sh !== '1') { document.querySelectorAll('tr[data-hidden="1"]').forEach(function (r) { r.style.display = 'none'; }); var btn = document.getElementById('toggleHiddenBtn'); if (btn) { btn.innerHTML = '👁️'; btn.style.color = 'var(--text)'; } } })();

// 解压到文件夹 - 实时预览绑定
document.addEventListener('DOMContentLoaded', function () { var input = document.getElementById('unpackTargetInput'); if (input) { input.addEventListener('input', updateUnpackPreview); } });

/* ---------- 快速过滤(工具栏搜索框) ---------- */
var lastFilterKeyword = '';
function quickSearch(keyword) {
    keyword = keyword.trim().toLowerCase();

    if (keyword === lastFilterKeyword) return;
    lastFilterKeyword = keyword;

    var rows = document.querySelectorAll('tr[data-name]');
    var firstMatch = null;
    var matchCount = 0;

    rows.forEach(function(row) {
        var name = (row.dataset.name || '').toLowerCase();

        if (keyword === '') {
            // 清空搜索:恢复显示
            row.style.display = '';
            row.classList.remove('filter-match');
            return;
        }

        if (name.indexOf(keyword) !== -1) {
            // 匹配:显示 + 高亮
            row.style.display = '';
            row.classList.add('filter-match');
            matchCount++;
            if (!firstMatch) firstMatch = row;
        } else {
            // 不匹配:隐藏
            row.style.display = 'none';
            row.classList.remove('filter-match');
        }
    });

    // 滚动到第一个匹配项
    if (firstMatch) {
        var container = document.querySelector('.table-container');
        if (container) {
            var rowRect = firstMatch.getBoundingClientRect();
            var containerRect = container.getBoundingClientRect();
            if (rowRect.top < containerRect.top || rowRect.bottom > containerRect.bottom) {
                firstMatch.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }
    }
}

function clearQuickSearch() {
    var input = document.getElementById('quickSearchInput');
    if (input) input.value = '';
    quickSearch('');
}

/* ---------- 搜索功能 ---------- */
function showSearchModal() {
    document.getElementById('searchKeywordInput').value = '';
    document.getElementById('searchDeep').checked = false;
    document.getElementById('searchPath').textContent = '当前目录: ' + (currentPath || '/') + '/';
    document.getElementById('searchResults').style.display = 'none';
    document.getElementById('searchResultsList').innerHTML = '';
    document.getElementById('searchStatus').textContent = '';
    showModal('searchModal');
    setTimeout(function() { document.getElementById('searchKeywordInput').focus(); }, 50);
}

function startSearch() {
    var keyword = document.getElementById('searchKeywordInput').value.trim();
    if (!keyword) { toast('请输入搜索关键词'); return; }

    var deep = document.getElementById('searchDeep').checked ? '1' : '0';
    var resultsDiv = document.getElementById('searchResults');
    var listDiv = document.getElementById('searchResultsList');
    var statusDiv = document.getElementById('searchStatus');

    resultsDiv.style.display = 'block';
    listDiv.innerHTML = '<div class="search-loading">🔍 搜索中...</div>';
    statusDiv.textContent = '';

    var fd = new FormData();
    fd.append('ajax_search', '1');
    fd.append('keyword', keyword);
    fd.append('path', currentPath);
    fd.append('deep', deep);
    fd.append('token', token);

    fetch(location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                listDiv.innerHTML = '<div class="search-empty">' + (d.error || '搜索失败') + '</div>';
                return;
            }

            if (d.count === 0) {
                listDiv.innerHTML = '<div class="search-empty">未找到匹配的文件</div>';
                statusDiv.textContent = '共 0 个结果';
                return;
            }

            var html = '';
            d.results.forEach(function(item) {
                var icon = item.type === 'folder' ? '📁' : (item.ext && ['zip','tar','gz','7z','rar'].indexOf(item.ext) !== -1 ? '📦' : '📄');
                var displayName = item.name.replace(new RegExp('(' + keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'), '<mark>$1</mark>');
                var gotoPath = item.path.indexOf('/') !== -1 ? item.path.substring(0, item.path.lastIndexOf('/')) : '';

                html += '<div class="search-result-item" onclick="goToSearchResult(\'' + item.path.replace(/'/g, "\\'") + '\',\'' + item.type + '\')">' +
                    '<span class="search-result-icon">' + icon + '</span>' +
                    '<div class="search-result-info">' +
                    '<div class="search-result-name">' + displayName + '</div>' +
                    '<div class="search-result-path">' + item.path + '</div>' +
                    '</div>' +
                    '<div class="search-result-meta">' + item.size + '<br>' + item.mtime + '</div>' +
                    '</div>';
            });

            listDiv.innerHTML = html;
            statusDiv.textContent = '共 ' + d.count + ' 个结果' + (d.truncated ? ' (已达上限)' : '');
        })
        .catch(function() {
            listDiv.innerHTML = '<div class="search-empty">搜索失败</div>';
        });
}

function goToSearchResult(path, type) {
    hideModal('searchModal');
    if (type === 'folder') {
        location.href = '?p=' + encodeURIComponent(path);
    } else {
        var parentPath = path.indexOf('/') !== -1 ? path.substring(0, path.lastIndexOf('/')) : '';
        location.href = '?p=' + encodeURIComponent(parentPath);
    }
}
</script>

<!-- 右键菜单 -->
<div class="context-menu" id="ctx">
  <div class="context-menu-item" id="ctxDl" onclick="ctxTarget&&(location.href='?p=<?=urlencode($p)?>&dl='+encodeURIComponent(ctxTarget))">⬇️ 下载</div>
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
  <div class="context-menu-item danger" onclick="ctxTarget&&document.querySelector('input[value=\x22'+ctxTarget+'\x22]')&&(document.querySelector('input[name=delete]')?document.querySelector('input[name=delete]').value=ctxTarget:(document.getElementById('mainForm').innerHTML+='<input name=delete value=\x22'+ctxTarget+'\x22>'),document.getElementById('mainForm').submit())">🗑️ 删除</div>
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

<!-- 离线下载模态框 -->
<input type="file" id="uploadFolderInput" webkitdirectory directory multiple style="display:none" onchange="const f=this.files;if(f.length){const a=[];for(let i=0;i<f.length;i++)a.push({file:f[i],path:f[i].webkitRelativePath||f[i].name});uploadFiles(a)}">

</body>
</html>

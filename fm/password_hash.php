<?php
/**
 * PHP Password Hash Generator — Interactive Web Interface (中文版)
 * Requires: PHP >= 5.5.0 | Web Server (Apache/Nginx/built-in php -S)
 */

$hash      = null;
$algo      = isset($_POST['algo'])     ? $_POST['algo']     : 'PASSWORD_BCRYPT';
$cost      = isset($_POST['cost'])     ? max(4, min(31, intval($_POST['cost']))) : 12;
$password  = isset($_POST['password']) ? $_POST['password']  : '';
$error     = null;
$debugInfo = [];

// 环境检测（用于调试）
$debugInfo['php_version'] = PHP_VERSION;
$debugInfo['password_hash_exists'] = function_exists('password_hash') ? 'YES' : 'NO';
$debugInfo['password_verify_exists'] = function_exists('password_verify') ? 'YES' : 'NO';
$debugInfo['PASSWORD_BCRYPT_defined'] = defined('PASSWORD_BCRYPT') ? 'YES' : 'NO';
$debugInfo['PASSWORD_ARGON2I_defined'] = defined('PASSWORD_ARGON2I') ? 'YES' : 'NO';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $password !== '') {
    $debugInfo['algo_received'] = $algo;
    $debugInfo['cost_received'] = $cost;
    $debugInfo['password_length'] = strlen($password);

    if (!function_exists('password_hash')) {
        $error = '错误：当前环境不支持 password_hash()，需要 PHP >= 5.5';
    } elseif (!defined($algo)) {
        $error = '错误：算法 "' . htmlspecialchars($algo) . '" 在此 PHP 版本不可用，请切换为 BCRYPT';
    } else {
        // 捕获所有错误
        set_error_handler(function($errno, $errstr) use (&$error) {
            $error = '执行错误: ' . $errstr;
            return true;
        });

        $options = ['cost' => $cost];
        $hash = @password_hash($password, constant($algo), $options);

        restore_error_handler();

        if ($hash === false) {
            $error = 'password_hash() 返回 false，请检查参数是否正确';
        } else {
            // 验证生成的哈希是否可用
            $verify_ok = @password_verify($password, $hash);
            $debugInfo['self_verify'] = $verify_ok ? 'PASS' : 'FAIL';
        }
    }
}

$algoOptions = [
    'PASSWORD_BCRYPT'    => 'BCRYPT（默认）',
    'PASSWORD_DEFAULT'   => 'DEFAULT（当前同 BCRYPT）',
    'PASSWORD_ARGON2I'  => 'ARGON2I（PHP 7.2+）',
    'PASSWORD_ARGON2ID' => 'ARGON2ID（PHP 7.3+）',
];
$argonAvailable = defined('PASSWORD_ARGON2I');

$algoDisplay = [
    'PASSWORD_BCRYPT'    => 'bcrypt',
    'PASSWORD_DEFAULT'   => 'default',
    'PASSWORD_ARGON2I'  => 'argon2i',
    'PASSWORD_ARGON2ID' => 'argon2id',
];

$hashLen = $hash ? strlen($hash) : 0;
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>密码哈希生成器</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
    background: #f0f2f5;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }

  .card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    padding: 40px;
    width: 100%;
    max-width: 520px;
  }

  .card h1 {
    font-size: 22px;
    font-weight: 600;
    color: #1a1a2e;
    margin-bottom: 6px;
  }

  .card .subtitle {
    font-size: 13px;
    color: #888;
    margin-bottom: 28px;
  }

  label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #444;
    margin-bottom: 6px;
  }

  input[type="password"],
  input[type="number"],
  select {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #ddd;
    border-radius: 10px;
    font-size: 15px;
    outline: none;
    transition: border-color 0.2s;
    background: #fafafa;
  }

  input[type="password"]:focus,
  input[type="number"]:focus,
  select:focus {
    border-color: #4f8ef7;
    background: #fff;
  }

  .row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
  }

  .full { margin-bottom: 16px; }

  .btn {
    width: 100%;
    padding: 12px;
    background: #4f8ef7;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    margin-bottom: 16px;
    font-family: inherit;
  }

  .btn:hover { background: #3a7ae8; }
  .btn:active { background: #2d6ad4; }

  .result-box {
    background: #f8f9ff;
    border: 1px solid #dde3ff;
    border-radius: 10px;
    padding: 16px;
    word-break: break-all;
    font-family: 'Courier New', Consolas, monospace;
    font-size: 13px;
    color: #2a2a4a;
    display: none;
  }

  .result-box.show { display: block; }

  .result-box .label {
    font-size: 11px;
    color: #888;
    font-family: sans-serif;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .result-box .value {
    font-size: 13px;
    color: #1a1a2e;
    word-break: break-all;
    line-height: 1.6;
    user-select: all;
  }

  .result-box .info {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #dde3ff;
    font-size: 12px;
    color: #888;
    font-family: sans-serif;
  }

  .error {
    background: #fff3f3;
    border: 1px solid #ffd0d0;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 13px;
    color: #c0392b;
    margin-bottom: 16px;
  }

  .divider {
    border: none;
    border-top: 1px solid #eee;
    margin: 20px 0;
  }

  .hint {
    font-size: 11px;
    color: #aaa;
    margin-top: 4px;
    font-family: sans-serif;
  }

  .copy-btn {
    margin-top: 8px;
    padding: 6px 14px;
    background: #eef1ff;
    border: 1px solid #c5cdff;
    border-radius: 6px;
    font-size: 12px;
    color: #4f8ef7;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.2s;
  }

  .copy-btn:hover { background: #e0e6ff; }
  .copy-btn.copied { color: #27ae60; border-color: #27ae60; }

  .debug-box {
    background: #f0f0f0;
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: 12px;
    font-family: 'Courier New', Consolas, monospace;
    color: #333;
    margin-bottom: 16px;
  }

  .debug-box .label {
    font-size: 11px;
    color: #888;
    font-family: sans-serif;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .debug-box div { margin-bottom: 2px; }
</style>
</head>
<body>
<div class="card">
  <h1>密码哈希生成器</h1>
  <p class="subtitle">PHP password_hash() &bull; PHP >= 5.5</p>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (isset($_GET['debug'])): ?>
    <div class="debug-box">
      <div class="label">调试信息</div>
      <?php foreach ($debugInfo as $k => $v): ?>
        <div><strong><?= htmlspecialchars($k) ?>:</strong> <?= htmlspecialchars($v) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <div class="full">
      <label for="password">密码</label>
      <input
        type="password"
        id="password"
        name="password"
        placeholder="请输入密码..."
        value="<?= htmlspecialchars($password) ?>"
        required
      >
    </div>

    <div class="row">
      <div>
        <label for="algo">算法</label>
        <select name="algo" id="algo">
          <?php foreach ($algoOptions as $val => $label): ?>
            <?php
              $disabled = (strpos($val, 'ARGON2') !== false && !$argonAvailable);
            ?>
            <option
              value="<?= $val ?>"
              <?= $algo === $val ? 'selected' : '' ?>
              <?= $disabled ? 'disabled' : '' ?>
            ><?= $label ?><?= $disabled ? '（不可用）' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="cost">Cost（bcrypt 轮数）</label>
        <input
          type="number"
          id="cost"
          name="cost"
          min="4"
          max="31"
          value="<?= $cost ?>"
        >
        <div class="hint">4–31，数值越高越安全但越慢</div>
      </div>
    </div>

    <button type="submit" class="btn">生成哈希</button>
  </form>

  <?php if ($hash): ?>
    <hr class="divider">
    <div class="result-box show">
      <div class="label">哈希值</div>
      <div class="value" id="hash-value"><?= htmlspecialchars($hash) ?></div>
      <div class="info">
        算法：<?= $algoDisplay[$algo] ?? $algo ?> &nbsp;|&nbsp;
        Cost：<?= $cost ?> &nbsp;|&nbsp;
        长度：<?= $hashLen ?> 字符
        <?php if (isset($debugInfo['self_verify'])): ?><br>
        自验证：<span style="color:#27ae60"><?= $debugInfo['self_verify'] ?> ✓</span>
        <?php endif; ?>
      </div>
      <button class="copy-btn" id="copy-btn" onclick="copyHash()">复制</button>
    </div>
  <?php endif; ?>
</div>

<script>
function copyHash() {
  var hashEl = document.getElementById('hash-value');
  var btn = document.getElementById('copy-btn');
  if (!hashEl) return;
  navigator.clipboard.writeText(hashEl.textContent).then(function() {
    btn.textContent = '已复制 ✓';
    btn.classList.add('copied');
    setTimeout(function() {
      btn.textContent = '复制';
      btn.classList.remove('copied');
    }, 1500);
  }).catch(function() {
    // fallback
    var ta = document.createElement('textarea');
    ta.value = hashEl.textContent;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    btn.textContent = '已复制 ✓';
    btn.classList.add('copied');
    setTimeout(function() {
      btn.textContent = '复制';
      btn.classList.remove('copied');
    }, 1500);
  });
}
</script>
</body>
</html>

<?php
/**
 * PHP Password Hash Generator
 * Requires: PHP >= 5.5.0 | php -S localhost:8080
 */

$hash     = null;
$algo     = isset($_POST['algo'])    ? $_POST['algo']    : 'PASSWORD_BCRYPT';
$cost     = isset($_POST['cost'])    ? max(4, min(31, intval($_POST['cost']))) : 10;
$password = isset($_POST['password']) ? $_POST['password'] : '';
$error    = null;
$ok       = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $password !== '') {
    if (!defined($algo)) {
        $error = '此算法不可用，请使用 BCRYPT';
    } else {
        $hash = password_hash($password, constant($algo), ['cost' => $cost]);
        $ok   = $hash ? password_verify($password, $hash) : false;
        if (!$hash) $error = '生成失败';
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>密码哈希生成器</title>
<style>
  body {
    font-family: -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif;
    background: #f0f2f5;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .box {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 16px rgba(0,0,0,0.08);
    padding: 32px;
    width: 480px;
  }
  h1 { font-size: 20px; font-weight: 600; color: #1a1a2e; margin-bottom: 4px; }
  .sub { font-size: 13px; color: #aaa; margin-bottom: 24px; }
  label { display: block; font-size: 13px; color: #555; margin-bottom: 4px; }
  input, select {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid #ddd; border-radius: 8px;
    font-size: 14px; outline: none; background: #fafafa;
    box-sizing: border-box;
  }
  input:focus, select:focus { border-color: #4f8ef7; background: #fff; }
  .row { display: flex; gap: 12px; margin-bottom: 16px; }
  .row > * { flex: 1; }
  .gap { margin-bottom: 16px; }
  .err { background: #fff3f3; border: 1px solid #ffd0d0; border-radius: 8px; padding: 10px 14px; color: #c0392b; font-size: 13px; margin-bottom: 16px; }
  .btn {
    width: 100%; padding: 11px;
    background: #4f8ef7; color: #fff; border: none; border-radius: 8px;
    font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit;
  }
  .btn:hover { background: #3a7ae8; }
  .res {
    margin-top: 20px; padding: 16px; background: #f8f9ff;
    border: 1px solid #dde3ff; border-radius: 10px;
    font-family: 'Courier New', Consolas, monospace; font-size: 13px;
    word-break: break-all; line-height: 1.6; color: #1a1a2e;
  }
  .res-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; font-family: sans-serif; }
  .ok { color: #27ae60; font-size: 12px; margin-top: 8px; }
</style>
</head>
<body>
<div class="box">
  <h1>密码哈希生成器</h1>
  <p class="sub">password_hash() &bull; PHP >= 5.5</p>

  <?php if ($error): ?>
    <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="gap">
      <label>密码</label>
      <input type="password" name="password" placeholder="输入密码" value="<?= htmlspecialchars($password) ?>" required>
    </div>
    <div class="row">
      <div>
        <label>算法</label>
        <select name="algo">
          <option value="PASSWORD_BCRYPT" <?= $algo==='PASSWORD_BCRYPT'?'selected':'' ?>>BCRYPT</option>
          <?php if (defined('PASSWORD_ARGON2I')): ?>
          <option value="PASSWORD_ARGON2I" <?= $algo==='PASSWORD_ARGON2I'?'selected':'' ?>>ARGON2I</option>
          <option value="PASSWORD_ARGON2ID" <?= $algo==='PASSWORD_ARGON2ID'?'selected':'' ?>>ARGON2ID</option>
          <?php endif; ?>
        </select>
      </div>
      <div>
        <label>Cost</label>
        <input type="number" name="cost" min="4" max="31" value="<?= $cost ?>">
      </div>
    </div>
    <button type="submit" class="btn">生成哈希</button>
  </form>

  <?php if ($hash): ?>
  <div class="res">
    <div class="res-label">哈希值</div>
    <?= htmlspecialchars($hash) ?>
    <div class="ok">✓ 验证通过</div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
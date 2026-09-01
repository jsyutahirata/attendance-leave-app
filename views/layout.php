<?php
use App\Auth;
use App\Csrf;
$user = Auth::user();
$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#176b5b">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <title><?= e($title ?? '勤怠管理') ?> | 社内勤怠管理</title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/app.css">
  <script src="/assets/app.js" defer></script>
</head>
<body>
<?php if ($user): ?>
  <header class="topbar">
    <a class="brand" href="<?= e(url()) ?>"><span class="brand-mark">勤</span><span>社内勤怠管理</span></a>
    <button class="nav-toggle" type="button" aria-label="メニュー" onclick="document.querySelector('.nav').classList.toggle('open')">☰</button>
    <nav class="nav">
      <a href="<?= e(url()) ?>">ホーム</a>
      <a href="<?= e(url('leave')) ?>">有給</a>
      <a href="<?= e(url('attendance')) ?>">出退勤</a>
      <a href="<?= e(url('notice')) ?>">勤怠連絡</a>
      <?php if ($user['role'] === 'admin'): ?><a href="<?= e(url('admin')) ?>">管理</a><?php endif; ?>
      <button type="button" id="pwa-install" class="install-button" hidden>アプリを追加</button>
      <form method="post" action="<?= e(url('logout')) ?>" class="nav-form"><?= Csrf::field() ?><button>ログアウト</button></form>
    </nav>
  </header>
<?php endif; ?>
<main class="container<?= $user ? '' : ' auth-container' ?>">
  <?php foreach ($flashes as $flash): ?>
    <div class="flash <?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
  <?php endforeach; ?>
  <?php require $viewFile; ?>
</main>
<footer>日時は日本標準時（JST）で表示しています。</footer>
<script>
document.querySelectorAll('[data-confirm]').forEach(function (el) {
  el.addEventListener('submit', function (event) {
    if (!confirm(el.dataset.confirm)) event.preventDefault();
  });
});
</script>
</body>
</html>

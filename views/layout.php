<?php
use App\Auth;
use App\Csrf;
$user = Auth::user();
$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$assetVersion = '20260909-01';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#176b5b">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <?php if ($user): ?><meta name="csrf-token" content="<?= e(Csrf::token()) ?>"><meta name="vapid-public-key" content="<?= e(\App\PushService::publicKey()) ?>"><?php endif; ?>
  <title><?= e($title ?? '勤怠管理') ?> | 社内勤怠管理</title>
  <script>
  (function () {
    var key = 'attendance-theme';
    var media = window.matchMedia('(prefers-color-scheme: dark)');
    function preference() {
      try { var saved = localStorage.getItem(key); return ['system','light','dark'].indexOf(saved) >= 0 ? saved : 'system'; }
      catch (_) { return 'system'; }
    }
    function apply(value) {
      var resolved = value === 'system' ? (media.matches ? 'dark' : 'light') : value;
      document.documentElement.dataset.theme = value;
      document.documentElement.dataset.resolvedTheme = resolved;
      document.documentElement.style.colorScheme = resolved;
      var meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.content = resolved === 'dark' ? '#10231f' : '#176b5b';
      window.dispatchEvent(new CustomEvent('attendance-theme-change', {detail:{preference:value,resolved:resolved}}));
    }
    window.getAttendanceTheme = preference;
    window.setAttendanceTheme = function (value) {
      if (['system','light','dark'].indexOf(value) < 0) value = 'system';
      try { localStorage.setItem(key, value); } catch (_) {}
      apply(value);
    };
    apply(preference());
    media.addEventListener ? media.addEventListener('change', function () { if (preference() === 'system') apply('system'); }) : media.addListener(function () { if (preference() === 'system') apply('system'); });
  })();
  </script>
  <link rel="manifest" href="/manifest.webmanifest?v=2">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192.png?v=2">
  <link rel="icon" type="image/png" sizes="512x512" href="/assets/icons/icon-512.png?v=2">
  <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
  <link rel="stylesheet" href="/assets/app.css?v=<?= e($assetVersion) ?>">
  <script src="/assets/app.js?v=<?= e($assetVersion) ?>" defer></script>
</head>
<body>
<?php if ($user): ?>
  <header class="topbar">
    <a class="brand" href="<?= e(url()) ?>"><span class="brand-mark">勤</span><span>社内勤怠管理</span></a>
    <button class="nav-toggle" type="button" aria-label="メニュー" aria-expanded="false" aria-controls="global-nav"><span class="nav-toggle-bars"></span></button>
    <?php
      $currentRoute = (string)($_GET['route'] ?? '');
      // 有給・代休・勤怠連絡は「休暇・連絡」タブに集約している。
      $navItems = [
        ['', 'ホーム'],
        ['leave', '休暇・勤怠連絡'],
        ['attendance', '出退勤'],
        ['viewable', '閲覧'],
        ['sharing', '公開設定'],
        ['security', '設定'],
        ['contact', '問い合わせ'],
      ];
      if ($user['role'] === 'admin') $navItems[] = ['admin', '管理'];
      // 旧「勤怠連絡」ページは休暇・連絡タブに統合。ブックマーク対策でルートは残す。
      if ($currentRoute === 'notice') $currentRoute = 'leave';
      $isActiveNav = static function (string $route) use ($currentRoute): bool {
        if ($route === '') return $currentRoute === '';
        return $currentRoute === $route || str_starts_with($currentRoute, $route . '/');
      };
    ?>
    <nav class="nav" id="global-nav">
      <?php foreach ($navItems as [$route, $label]): $active = $isActiveNav($route); ?>
        <a href="<?= e(url($route)) ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
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

<section class="auth-card">
  <h1>新しいパスワード</h1>
  <form method="post" action="<?= e(url('reset-password')) ?>">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>新しいパスワード（8文字以上）<input type="password" name="password" minlength="8" autocomplete="new-password" required autofocus></label>
    <button class="primary wide">パスワードを更新</button>
  </form>
</section>

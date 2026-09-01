<section class="auth-card plain-login">
  <div class="login-form-side">
    <span class="login-app-name"><span class="brand-mark">勤</span> 社内勤怠管理</span>
    <h1>おかえりなさい</h1>
    <p class="muted">会社のメールアドレスでログインしてください。</p>
    <form method="post" action="<?= e(url('login')) ?>">
      <?= \App\Csrf::field() ?>
      <label>メールアドレス<input type="email" name="email" autocomplete="username" required autofocus></label>
      <label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
      <label class="check-row"><input type="checkbox" name="remember" value="1"> この端末でログイン状態を保持する（30日間）</label>
      <button class="primary wide">ログイン</button>
    </form>
    <p><a href="<?= e(url('forgot-password')) ?>">パスワードを忘れた方</a></p>
  </div>
</section>

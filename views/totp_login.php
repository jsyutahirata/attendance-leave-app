<section class="auth-card plain-login">
  <div class="login-form-side">
    <span class="login-app-name"><span class="brand-mark">勤</span> 社内勤怠管理</span>
    <h1>二要素認証</h1>
    <p class="muted">認証アプリに表示される6桁のコードを入力してください。</p>
    <form method="post" action="<?= e(url('login/totp')) ?>">
      <?= \App\Csrf::field() ?>
      <label>認証コード<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus></label>
      <button class="primary wide">認証する</button>
    </form>
    <details><summary class="muted">認証アプリを使えない場合</summary><form method="post" action="<?= e(url('login/totp')) ?>" style="margin-top:12px"><?= \App\Csrf::field() ?><label>バックアップコード<input type="text" name="code" placeholder="xxxx-xxxx" autocomplete="off"></label><button class="wide">バックアップコードで認証</button></form></details>
    <p style="margin-top:16px"><a href="<?= e(url('login')) ?>">ログインに戻る</a></p>
  </div>
</section>

<section class="auth-card">
  <h1>パスワード再設定</h1>
  <p class="muted">登録済みのメールアドレスへ再設定URLを送信します。</p>
  <form method="post" action="<?= e(url('forgot-password')) ?>">
    <?= \App\Csrf::field() ?>
    <label>メールアドレス<input type="email" name="email" required autofocus></label>
    <button class="primary wide">再設定メールを送る</button>
  </form>
  <p><a href="<?= e(url('login')) ?>">ログインへ戻る</a></p>
</section>


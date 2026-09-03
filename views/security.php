<div class="page-head"><div><h1>セキュリティ設定</h1><p class="muted">二要素認証（TOTP）でログインを保護します。</p></div></div>
<section class="panel">
  <h2>二要素認証（TOTP）</h2>
  <?php if ($enabled): ?>
    <p>状態：<span class="tag" style="background:#daf2e7;color:#176b50">有効</span></p>
    <p class="muted">ログイン時に認証アプリの6桁コードが必要です。</p>
    <form method="post" action="<?= e(url('security/totp/disable')) ?>" data-confirm="二要素認証を無効にしますか？"><?= \App\Csrf::field() ?><button class="danger">無効にする</button></form>
  <?php elseif ($setup): ?>
    <p class="muted">認証アプリ（Google Authenticator、Microsoft Authenticator 等）で下のQRコードを読み取り、表示された6桁コードを入力してください。</p>
    <div class="totp-setup">
      <div class="totp-qr"><?= $qrSvg /* 自前生成のSVG（信頼済み） */ ?></div>
      <div class="totp-detail">
        <p class="muted">QRを読み取れない場合は、次のキーを手動で登録してください。</p>
        <p class="totp-secret"><code><?= e($setup['secret']) ?></code></p>
        <h3>バックアップコード</h3>
        <p class="muted">認証アプリを使えないときに1回ずつ使えます。今すぐ安全な場所に保管してください（この後は再表示されません）。</p>
        <ul class="backup-codes">
          <?php foreach ($setup['backup'] as $bc): ?><li><code><?= e($bc) ?></code></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
    <form method="post" action="<?= e(url('security/totp/confirm')) ?>" class="inline-form" style="margin-top:16px">
      <?= \App\Csrf::field() ?>
      <label>認証コード<input type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required></label>
      <button class="primary">確認して有効化</button>
    </form>
    <form method="post" action="<?= e(url('security/totp/disable')) ?>" style="margin-top:8px"><?= \App\Csrf::field() ?><button class="small">セットアップを中止</button></form>
  <?php else: ?>
    <p>状態：<span class="tag">無効</span></p>
    <p class="muted">有効にすると、ログイン時にパスワードに加えて認証アプリの6桁コードが必要になります。</p>
    <form method="post" action="<?= e(url('security/totp/init')) ?>"><?= \App\Csrf::field() ?><button class="primary">有効化する</button></form>
  <?php endif; ?>
</section>
<section class="panel push-settings" id="push-settings">
  <h2>退勤忘れ通知</h2>
  <p class="muted">通知の有無と時刻は社員ごとに設定できます。通知本文には勤怠の詳細や有給残数を表示しません。Electronではネイティブ通知も併用します。</p>
  <form method="post" action="<?= e(url('push/preferences')) ?>" class="notification-preferences">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="enabled" value="0">
    <label class="checkbox-label"><input type="checkbox" name="enabled" value="1" <?= $pushPreference['enabled'] ? 'checked' : '' ?>>退勤忘れ通知を受け取る</label>
    <label>通知時刻<input type="time" name="reminder_time" value="<?= e($pushPreference['reminder_time']) ?>" required></label>
    <button class="primary">個人設定を保存</button>
  </form>
  <?php if (!$pushConfigured): ?>
    <p class="notice">サーバー側のVAPIDキーが未設定です。XServer配置時に設定すると利用できます。</p>
  <?php else: ?>
    <p>登録済み端末：<strong><?= (int)$pushSubscriptionCount ?></strong>台</p>
    <p id="push-state" class="muted">この端末の通知状態を確認しています。</p>
    <div class="row-actions"><button type="button" id="push-enable" class="primary">この端末で通知を有効にする</button><button type="button" id="push-disable" class="danger" hidden>この端末の通知を無効にする</button></div>
  <?php endif; ?>
</section>

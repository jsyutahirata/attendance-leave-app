<div class="page-head"><div><h1>問い合わせ</h1><p class="muted">不具合の報告や使い方の質問などを担当者へ送信できます。</p></div></div>
<div class="one-col">
  <section class="panel">
    <?php if (empty($configured)): ?>
      <p class="notice">現在、問い合わせの送信先が設定されていません。お手数ですが管理担当者へ直接ご連絡ください。</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('contact')) ?>">
      <?= \App\Csrf::field() ?>
      <label>種別
        <select name="category" required>
          <?php foreach (\App\ContactService::CATEGORIES as $value => $label): ?>
            <option value="<?= e($value) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>内容
        <textarea name="body" rows="6" maxlength="2000" required placeholder="例：〇〇の画面でエラーが出ます／△△の操作方法が分かりません"></textarea>
      </label>
      <button class="primary"<?= empty($configured) ? ' disabled' : '' ?>>送信する</button>
    </form>
  </section>
</div>

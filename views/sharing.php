<div class="page-head"><div><h1>データの公開先</h1><p class="muted">自分の勤怠・有給データを閲覧できる相手を、自分で追加・削除できます（閲覧のみ）。</p></div></div>
<div class="two-col form-first">
  <section class="panel"><h2>閲覧を許可する相手を追加</h2>
    <form method="post" action="<?= e(url('sharing/add')) ?>">
      <?= \App\Csrf::field() ?>
      <label>社員<select name="viewer_employee_id" required><option value="">選択してください</option><?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?></option><?php endforeach; ?></select></label>
      <button class="primary">公開先に追加</button>
    </form>
    <p class="muted" style="margin-top:12px">グループ単位の設定や、他人を対象にした設定は管理者が行います。</p>
  </section>
  <section class="panel"><h2>現在の公開先</h2><div class="table-wrap"><table><thead><tr><th>閲覧できる相手</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($grants as $g): ?><tr><td><?= e($g['viewer_name'] ?: '（不明）') ?></td><td><form method="post" action="<?= e(url('sharing/remove')) ?>" class="inline-form" data-confirm="この公開先を削除しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="grant_id" value="<?= (int)$g['id'] ?>"><button class="danger small">削除</button></form></td></tr><?php endforeach; ?>
    <?php if (!$grants): ?><tr><td colspan="2" class="empty">公開先は設定されていません。</td></tr><?php endif; ?>
  </tbody></table></div></section>
</div>

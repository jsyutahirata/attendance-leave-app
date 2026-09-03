<div class="page-head"><div><h1>閲覧</h1><p class="muted"><?= $isAdmin ? '管理者は全社員のデータを閲覧できます。' : '閲覧権限が付与された社員のデータを参照できます（読み取りのみ）。' ?></p></div></div>
<section class="panel">
  <?php if (!$employees): ?>
    <p class="empty">閲覧できる社員はいません。閲覧権限は管理者、または本人の公開設定によって付与されます。</p>
  <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>氏名</th><th>社員番号</th><th></th></tr></thead><tbody>
      <?php foreach ($employees as $emp): ?><tr><td><?= e($emp['full_name']) ?></td><td><?= e($emp['employee_code'] ?: '—') ?></td><td><a class="button small" href="<?= e(url('viewable/show')) ?>&employee_id=<?= (int)$emp['id'] ?>">状況を見る</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

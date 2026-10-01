<div class="page-head"><div><h1>操作履歴</h1><p class="muted">絞り込み条件に一致する直近500件を表示しています（自動処理は「システム(自動)」で記録)。</p></div><a class="button" href="<?= e(url('admin')) ?>">管理トップ</a></div>
<section class="panel">
  <form method="get" action="<?= e(url('')) ?>" class="grid-form audit-filter">
    <input type="hidden" name="route" value="admin/audit">
    <label>操作者（誰がやったか）
      <select name="actor">
        <option value="">すべて</option>
        <option value="system"<?= ($filter['actor'] ?? '') === 'system' ? ' selected' : '' ?>>システム(自動)</option>
        <?php foreach ($actorOptions as $opt): ?>
          <option value="<?= (int)$opt['user_id'] ?>"<?= (string)($filter['actor'] ?? '') === (string)$opt['user_id'] ? ' selected' : '' ?>><?= e($opt['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>対象者（誰に対してか）
      <select name="target">
        <option value="">すべて</option>
        <?php foreach ($actorOptions as $opt): ?>
          <option value="<?= (int)$opt['employee_id'] ?>"<?= (string)($filter['target'] ?? '') === (string)$opt['employee_id'] ? ' selected' : '' ?>><?= e($opt['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>操作
      <select name="action">
        <option value="">すべて</option>
        <?php foreach ($actionOptions as $act): ?>
          <option value="<?= e($act) ?>"<?= ($filter['action'] ?? '') === $act ? ' selected' : '' ?>><?= e($act) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>開始日<input type="date" name="from" value="<?= e($filter['from'] ?? '') ?>"></label>
    <label>終了日<input type="date" name="to" value="<?= e($filter['to'] ?? '') ?>"></label>
    <div class="row-actions" style="align-self:end">
      <button class="primary">絞り込む</button>
      <a class="button" href="<?= e(url('admin/audit')) ?>">クリア</a>
    </div>
  </form>
</section>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>変更内容</th></tr></thead><tbody><?php foreach($logs as $log): ?><tr><td><?= e($log['created_at']) ?></td><td><?= $log['actor_name'] !== null && $log['actor_name'] !== '' ? e($log['actor_name']) : ($log['actor_user_id'] === null ? '<span class="tag">システム(自動)</span>' : '不明') ?></td><td><?= e($log['action']) ?></td><td><?= e($log['target_type']) ?> #<?= e($log['target_id']) ?></td><td><details><summary>表示</summary><pre><?= e($log['before_json']) ?>
→
<?= e($log['after_json']) ?></pre></details></td></tr><?php endforeach; ?><?php if(!$logs): ?><tr><td colspan="5" class="empty">該当する履歴はありません。</td></tr><?php endif; ?></tbody></table></div></section>

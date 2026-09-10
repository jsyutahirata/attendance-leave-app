<div class="page-head"><div><h1>操作履歴</h1><p class="muted">直近500件を表示しています。</p></div><a class="button" href="<?= e(url('admin')) ?>">管理トップ</a></div>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>変更内容</th></tr></thead><tbody><?php foreach($logs as $log): ?><tr><td><?= e($log['created_at']) ?></td><td><?= e($log['actor_email'] ?: '不明') ?></td><td><?= e($log['action']) ?></td><td><?= e($log['target_type']) ?> #<?= e($log['target_id']) ?></td><td><details><summary>表示</summary><pre><?= e($log['before_json']) ?>
→
<?= e($log['after_json']) ?></pre></details></td></tr><?php endforeach; ?></tbody></table></div></section>

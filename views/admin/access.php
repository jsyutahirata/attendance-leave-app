<div class="page-head"><div><h1>閲覧権限・グループ</h1><p class="muted">管理者権限とは独立した「閲覧のみ」の権限を、個人・グループ単位で設定します。</p></div><a class="button" href="<?= e(url('admin')) ?>">管理トップ</a></div>

<h2 class="section-title">グループ</h2>
<div class="two-col form-first">
  <section class="panel"><h2>グループを作成</h2>
    <form method="post" action="<?= e(url('admin/groups/create')) ?>" class="inline-form"><?= \App\Csrf::field() ?><input name="name" placeholder="例：プログラマー" required><button class="primary">作成</button></form>
  </section>
  <section class="panel"><h2>グループ一覧・所属</h2>
    <?php if (!$groups): ?><p class="empty">グループはまだありません。</p><?php endif; ?>
    <?php foreach ($groups as $group): ?>
      <div class="group-block">
        <div class="section-head"><strong><?= e($group['name']) ?></strong>
          <form method="post" action="<?= e(url('admin/groups/delete')) ?>" class="inline-form" data-confirm="グループを削除しますか？関連する閲覧権限も削除されます。"><?= \App\Csrf::field() ?><input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>"><button class="danger small">グループ削除</button></form>
        </div>
        <div class="tag-list">
          <?php foreach (($membersByGroup[(int)$group['id']] ?? []) as $m): ?>
            <span class="tag"><?= e($m['full_name']) ?><form method="post" action="<?= e(url('admin/groups/remove-member')) ?>" class="inline-remove"><?= \App\Csrf::field() ?><input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>"><input type="hidden" name="employee_id" value="<?= (int)$m['employee_id'] ?>"><button title="外す">×</button></form></span>
          <?php endforeach; ?>
          <?php if (empty($membersByGroup[(int)$group['id']])): ?><span class="muted">所属なし</span><?php endif; ?>
        </div>
        <form method="post" action="<?= e(url('admin/groups/add-member')) ?>" class="inline-form"><?= \App\Csrf::field() ?><input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>"><select name="employee_id" required><option value="">社員を追加</option><?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?></select><button class="small">追加</button></form>
      </div>
    <?php endforeach; ?>
  </section>
</div>

<h2 class="section-title" id="all-view">全閲覧権限</h2>
<section class="panel">
  <h2>全社員を閲覧できる社員</h2>
  <p class="muted">管理者にせず、全社員の有給・代休・打刻・勤怠連絡を読み取り専用で確認できるようにします。</p>
  <form method="post" action="<?= e(url('admin/view-grants/all/create')) ?>" class="grid-form"><?= \App\Csrf::field() ?>
    <label>付与する社員<select name="viewer_employee_id" required><option value="">社員を選択</option><?php foreach ($employees as $emp): ?><?php if ($emp['status'] === 'active' && $emp['role'] !== 'admin'): ?><option value="<?= (int)$emp['id'] ?>" <?= (int)($_GET['employee_id'] ?? 0) === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?><?= $emp['employee_code'] ? '（'.e($emp['employee_code']).'）' : '' ?></option><?php endif; ?><?php endforeach; ?></select></label>
    <label>有効期限（任意）<input type="date" name="expires_on" min="<?= e(date('Y-m-d')) ?>"></label>
    <button class="primary">全閲覧権限を付与</button>
  </form>
  <div class="table-wrap"><table><thead><tr><th>社員</th><th>有効期限</th><th>状態</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($allGrants as $g): $expired = $g['expires_on'] !== null && $g['expires_on'] < date('Y-m-d'); ?><tr>
      <td><?= e($g['viewer_emp_name'] ?: '（不明）') ?></td>
      <td><?= e($g['expires_on'] ?: '無期限') ?></td>
      <td><span class="tag <?= $expired ? 'danger-tag' : 'success-tag' ?>"><?= $expired ? '期限切れ' : '有効' ?></span></td>
      <td><form method="post" action="<?= e(url('admin/view-grants/delete')) ?>" class="inline-form" data-confirm="この社員の全閲覧権限を削除しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="grant_id" value="<?= (int)$g['id'] ?>"><button class="danger small">削除</button></form></td>
    </tr><?php endforeach; ?>
    <?php if (!$allGrants): ?><tr><td colspan="4" class="empty">全閲覧権限が付与された社員はいません。</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

<h2 class="section-title">個別・グループ閲覧権限</h2>
<section class="panel"><h2>閲覧権限を付与</h2><p class="muted">「閲覧する側」が「対象」のデータを読み取れるようにします。個人・グループのいずれも指定できます。</p>
  <form method="post" action="<?= e(url('admin/view-grants/create')) ?>" class="grid-form"><?= \App\Csrf::field() ?>
    <label>閲覧する側の種別<select name="viewer_type"><option value="employee">個人</option><option value="group">グループ</option></select></label>
    <label>閲覧する側（個人）<select name="viewer_employee_id"><option value="">—</option><?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?></select></label>
    <label>閲覧する側（グループ）<select name="viewer_group_id"><option value="">—</option><?php foreach ($groups as $group): ?><option value="<?= (int)$group['id'] ?>"><?= e($group['name']) ?></option><?php endforeach; ?></select></label>
    <label>対象の種別<select name="target_type"><option value="employee">個人</option><option value="group">グループ</option></select></label>
    <label>対象（個人）<select name="target_employee_id"><option value="">—</option><?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?></select></label>
    <label>対象（グループ）<select name="target_group_id"><option value="">—</option><?php foreach ($groups as $group): ?><option value="<?= (int)$group['id'] ?>"><?= e($group['name']) ?></option><?php endforeach; ?></select></label>
    <label>有効期限（任意）<input type="date" name="expires_on"></label>
    <button class="primary">付与する</button>
  </form>
</section>
<section class="panel"><h2>閲覧権限一覧</h2><div class="table-wrap"><table><thead><tr><th>閲覧する側</th><th>対象</th><th>有効期限</th><th>操作</th></tr></thead><tbody>
  <?php foreach ($grants as $g): ?><tr>
    <td><?= $g['viewer_type']==='group' ? 'グループ：'.e($g['viewer_grp_name']) : e($g['viewer_emp_name'] ?: '（不明）') ?></td>
    <td><?= $g['target_type']==='group' ? 'グループ：'.e($g['target_grp_name']) : e($g['target_emp_name'] ?: '（不明）') ?></td>
    <td><?= e($g['expires_on'] ?: '無期限') ?></td>
    <td><form method="post" action="<?= e(url('admin/view-grants/delete')) ?>" class="inline-form" data-confirm="この閲覧権限を削除しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="grant_id" value="<?= (int)$g['id'] ?>"><button class="danger small">削除</button></form></td>
  </tr><?php endforeach; ?>
  <?php if (!$grants): ?><tr><td colspan="4" class="empty">閲覧権限はまだありません。</td></tr><?php endif; ?>
</tbody></table></div></section>

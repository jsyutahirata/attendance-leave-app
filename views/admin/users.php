<div class="page-head">
    <div><h1>社員管理</h1><p class="muted">招待制アカウントを管理します。</p></div>
    <a class="button" href="<?= e(url('admin')) ?>">管理トップ</a>
</div>

<?php if ($editUser): ?>
<dialog class="employee-edit-dialog js-auto-modal" id="employee-edit-dialog" aria-labelledby="employee-edit-title">
    <div class="modal-head">
        <div>
            <p class="eyebrow">社員情報を編集中</p>
            <h2 id="employee-edit-title"><?= e($editUser['full_name']) ?>さん</h2>
        </div>
        <button type="button" class="modal-close" data-modal-close aria-label="編集画面を閉じる">×</button>
    </div>
    <p class="muted modal-description">登録情報・権限・在籍状態を変更できます。</p>
    <form method="post" action="<?= e(url('admin/users/update')) ?>" class="employee-edit-form">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
        <div class="grid-form">
            <label>氏名<input name="full_name" maxlength="100" value="<?= e($editUser['full_name']) ?>" required></label>
            <label>社員番号<input name="employee_code" maxlength="50" value="<?= e($editUser['employee_code']) ?>"></label>
            <label>メールアドレス<input type="email" name="email" maxlength="255" value="<?= e($editUser['email']) ?>" required></label>
            <label>入社日<input type="date" name="hired_on" value="<?= e($editUser['hired_on']) ?>"></label>
            <label>有給更新月<input type="number" name="leave_renewal_month" min="1" max="12" value="<?= e($editUser['leave_renewal_month']) ?>" placeholder="例：10"></label>
            <label>権限
                <select name="role" <?= (int)$editUser['id'] === \App\Auth::id() ? 'disabled' : '' ?>>
                    <option value="employee" <?= $editUser['role'] === 'employee' ? 'selected' : '' ?>>社員</option>
                    <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>管理者</option>
                </select>
                <?php if ((int)$editUser['id'] === \App\Auth::id()): ?><input type="hidden" name="role" value="admin"><?php endif; ?>
            </label>
            <label>在籍状態
                <select name="status" <?= (int)$editUser['id'] === \App\Auth::id() ? 'disabled' : '' ?>>
                    <option value="active" <?= $editUser['status'] === 'active' ? 'selected' : '' ?>>有効</option>
                    <option value="suspended" <?= $editUser['status'] === 'suspended' ? 'selected' : '' ?>>休職中</option>
                    <option value="disabled" <?= $editUser['status'] === 'disabled' ? 'selected' : '' ?>>無効</option>
                </select>
                <?php if ((int)$editUser['id'] === \App\Auth::id()): ?><input type="hidden" name="status" value="active"><?php endif; ?>
            </label>
            <label class="form-sync-field"><span>フォーム同期（部分移行）</span>
                <span class="checkbox-inline"><input type="checkbox" name="form_sync_enabled" value="1" <?= (int)($editUser['form_sync_enabled'] ?? 0) === 1 ? 'checked' : '' ?>> アプリ打刻を元フォームへ転送する</span>
            </label>
            <label>フォーム送信氏名<input name="form_sync_name" maxlength="100" value="<?= e($editUser['form_sync_name'] ?? '') ?>" placeholder="送信先フォームの氏名と完全一致（例：平田雄大）"></label>
        </div>
        <p class="modal-note">休職中は所属・閲覧権限を保持します。無効にすると関連設定も削除します。<br>フォーム同期をONにすると、この社員がアプリで打刻するたびに、元Googleフォームへ同じ内容が自動送信されます（部分移行の対象者のみ）。氏名は送信先フォームの選択肢と完全一致させてください。</p>
        <div class="modal-actions">
            <button type="button" data-modal-close>キャンセル</button>
            <button class="primary">変更を保存</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<section class="panel">
    <h2>社員を追加</h2>
    <p class="muted">作成後、本人へ24時間有効のパスワード設定メールを送ります。</p>
    <form method="post" action="<?= e(url('admin/users/create')) ?>" class="grid-form">
        <?= \App\Csrf::field() ?>
        <label>氏名<input name="full_name" maxlength="100" required></label>
        <label>社員番号<input name="employee_code" maxlength="50"></label>
        <label>メールアドレス<input type="email" name="email" maxlength="255" required></label>
        <label>入社日<input type="date" name="hired_on"></label>
        <label>有給更新月<input type="number" name="leave_renewal_month" min="1" max="12" placeholder="例：10"></label>
        <label>権限<select name="role"><option value="employee">社員</option><option value="admin">管理者</option></select></label>
        <button class="primary">作成して招待する</button>
    </form>
</section>

<section class="panel">
    <h2>社員一覧</h2>
    <div class="admin-danger-zone">
        <div><strong>全社員を一括ログアウト</strong><p class="muted">緊急時に、管理者自身を含む全員の全端末セッションを失効します。</p></div>
        <form method="post" action="<?= e(url('admin/users/logout-all')) ?>" data-confirm="管理者自身を含む全社員を、すべての端末からログアウトします。実行しますか？"><?= \App\Csrf::field() ?><button class="danger">全員をログアウト</button></form>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>氏名</th><th>社員番号</th><th>メール</th><th>権限</th><th>状態</th><th>2FA</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $labels = ['active' => '有効', 'disabled' => '無効', 'suspended' => '休職中']; $active = $user['status'] === 'active'; ?>
            <tr>
                <td><?= e($user['full_name']) ?></td><td><?= e($user['employee_code'] ?: '—') ?></td><td><?= e($user['email']) ?></td>
                <td><?= $user['role'] === 'admin' ? '管理者' : '社員' ?></td>
                <td><span class="tag"><?= e($labels[$user['status']] ?? $user['status']) ?></span></td>
                <td><?= (int)$user['totp_enabled'] === 1 ? '<span class="tag success-tag">有効</span>' : '—' ?></td>
                <td><div class="row-actions">
                    <a class="button small" href="<?= e(url('admin/users') . '&edit=' . (int)$user['id']) ?>">編集</a>
                    <?php if ($user['role'] !== 'admin'): ?><a class="button small" href="<?= e(url('admin/access') . '&employee_id=' . (int)$user['employee_id'] . '#all-view') ?>">全閲覧権限</a><?php endif; ?>
                    <?php if ($active): ?><form method="post" action="<?= e(url('admin/users/password-reset')) ?>" data-confirm="この社員を全端末からログアウトし、パスワード再設定メールを送信しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button class="small">パスワード再設定</button></form><?php endif; ?>
                    <?php if ((int)$user['id'] !== \App\Auth::id()): ?>
                    <form method="post" action="<?= e(url('admin/users/toggle')) ?>" data-confirm="状態を変更しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button class="small <?= $active ? 'danger' : '' ?>"><?= $active ? '無効化' : '有効化' ?></button></form>
                    <form method="post" action="<?= e(url('admin/users/force-logout')) ?>" data-confirm="この社員の全端末を強制ログアウトしますか？"><?= \App\Csrf::field() ?><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button class="small">強制ログアウト</button></form>
                    <?php if ((int)$user['totp_enabled'] === 1): ?><form method="post" action="<?= e(url('admin/users/totp-disable')) ?>" data-confirm="この社員の二要素認証を無効化しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button class="small danger">2FA無効化</button></form><?php endif; ?>
                    <?php else: ?><span class="muted">自分</span><?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<div class="page-head"><div><h1>有給管理</h1><p class="muted">残数確認、予定登録、取消ができます。</p></div></div>
<section class="summary-grid compact">
  <article class="metric"><span>現在残数</span><strong><?= number_format($summary['current'], 1) ?><small>日</small></strong><div class="balance-detail"><span>前年繰越 <b><?= number_format($summary['previous_year'], 1) ?>日</b></span><span>今年付与 <b><?= number_format($summary['current_year'], 1) ?>日</b></span></div></article>
  <article class="metric"><span>取得予定</span><strong><?= number_format($summary['scheduled'], 1) ?><small>日</small></strong></article>
  <article class="metric accent"><span>予定反映後</span><strong><?= number_format($summary['forecast'], 1) ?><small>日</small></strong><div class="balance-detail"><span>前年繰越 <b><?= number_format($summary['forecast_previous_year'], 1) ?>日</b></span><span>今年付与 <b><?= number_format($summary['forecast_current_year'], 1) ?>日</b></span></div></article>
</section>
<p class="balance-rule">有給は今年付与分から先に消化します。未使用分の繰越は翌年末まで有効です。</p>
<div class="two-col form-first">
  <section class="panel"><h2>有給予定を登録</h2><p class="notice"><?= \App\Settings::bool('leave_approval_required', true) ? '登録後、管理者の承認を受けると確定します。承認待ちの間も予定日数として残数を確保します。' : 'マネージャーまたは上長への事前確認後に登録してください。現在、アプリ内の承認処理はありません。' ?></p>
    <form method="post" action="<?= e(url('leave/create')) ?>">
      <?= \App\Csrf::field() ?>
      <label>取得日<input type="date" name="leave_date" required></label>
      <label>取得区分<select name="leave_type" required><option value="full">1日（1.0日）</option><option value="am">午前休（0.5日）</option><option value="pm">午後休（0.5日）</option></select></label>
      <label>事前確認相手<input name="confirmed_with" maxlength="100"></label>
      <label>連絡事項<textarea name="note" rows="3"></textarea></label>
      <button class="primary">登録する</button>
    </form>
  </section>
  <section class="panel"><h2>取得履歴・取消履歴</h2>
    <div class="table-wrap"><table><thead><tr><th>取得日</th><th>区分</th><th>状態</th><th>操作</th></tr></thead><tbody>
      <?php foreach ($entries as $entry): $past = $entry['leave_date'] < date('Y-m-d'); $labels=['pending'=>'承認待ち','approved'=>'承認済み','registered'=>'登録済み','taken'=>'取得済み','rejected'=>'却下','cancelled'=>'取消済み']; ?><tr><td><?= e($entry['leave_date']) ?></td><td><?= e(['full'=>'1日','am'=>'午前休','pm'=>'午後休'][$entry['leave_type']]) ?></td><td><span class="tag"><?= e($labels[$entry['status']] ?? ($past ? '取得済み' : '登録済み')) ?></span><?php if ($entry['status']==='cancelled'): ?><small><?= e($entry['cancellation_reason']) ?></small><?php elseif($entry['status']==='rejected'): ?><small><?= e($entry['rejection_reason']) ?></small><?php endif; ?></td><td>
        <?php if (!in_array($entry['status'], ['cancelled','rejected'], true) && !$past): ?><form method="post" action="<?= e(url('leave/cancel')) ?>" class="inline-form" data-confirm="この有給予定を取り消しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>"><input name="reason" placeholder="取消理由" required><button class="danger small">取消</button></form><?php else: ?>—<?php endif; ?>
      </td></tr><?php endforeach; ?>
      <?php if (!$entries): ?><tr><td colspan="4" class="empty">履歴はありません。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>
<h2 class="section-title">代休</h2>
<p class="balance-rule">代休は休日出勤の登録で自動的に付与されます。発生した事業年度（4月始まり）の年度末（翌3/31）まで有効です。</p>
<div class="two-col form-first">
  <section class="panel"><h2>代休の取得予定を登録<span class="metric-inline">残数 <?= number_format($compSummary['available'], 1) ?>日</span></h2>
    <form method="post" action="<?= e(url('comp-leave/create')) ?>">
      <?= \App\Csrf::field() ?>
      <label>取得日<input type="date" name="leave_date" required></label>
      <label>連絡事項<textarea name="note" rows="3"></textarea></label>
      <button class="primary" <?= $compSummary['available'] < 1 ? 'disabled' : '' ?>>登録する（1日）</button>
    </form>
  </section>
  <section class="panel"><h2>代休の取得・取消履歴</h2>
    <div class="table-wrap"><table><thead><tr><th>取得日</th><th>状態</th><th>操作</th></tr></thead><tbody>
      <?php foreach ($compEntries as $entry): $past = $entry['leave_date'] < date('Y-m-d'); ?><tr><td><?= e($entry['leave_date']) ?></td><td><span class="tag"><?= $entry['status']==='cancelled' ? '取消済み' : ($past ? '取得済み' : '登録済み') ?></span><?php if ($entry['status']==='cancelled'): ?><small><?= e($entry['cancellation_reason']) ?></small><?php endif; ?></td><td>
        <?php if ($entry['status'] !== 'cancelled' && !$past): ?><form method="post" action="<?= e(url('comp-leave/cancel')) ?>" class="inline-form" data-confirm="この代休予定を取り消しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>"><input name="reason" placeholder="取消理由" required><button class="danger small">取消</button></form><?php else: ?>—<?php endif; ?>
      </td></tr><?php endforeach; ?>
      <?php if (!$compEntries): ?><tr><td colspan="3" class="empty">代休はありません。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>

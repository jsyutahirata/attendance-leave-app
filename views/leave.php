<?php $noticeLabels=['late'=>'遅刻','early'=>'早退','leave_full'=>'有給休暇（1日）','leave_am'=>'有給休暇（午前休）','leave_pm'=>'有給休暇（午後休）','absence'=>'欠勤','holiday_work'=>'休日出勤','medical'=>'健康診断','other'=>'その他']; ?>
<div class="page-head"><div><h1>休暇・勤怠連絡</h1><p class="muted">有給・代休・勤怠連絡をまとめて確認・登録できます。</p></div><button type="button" class="button primary js-open-entry-dialog">＋ 予定を登録</button></div>
<section class="summary-grid compact js-calendar-summary">
  <article class="metric"><span>現在残数</span><strong><?= number_format($summary['current'], 1) ?><small>日</small></strong><div class="balance-detail"><span>前年繰越 <b><?= number_format($summary['previous_year'], 1) ?>日</b></span><span>今年付与 <b><?= number_format($summary['current_year'], 1) ?>日</b></span></div></article>
  <article class="metric"><span>取得予定</span><strong><?= number_format($summary['scheduled'], 1) ?><small>日</small></strong></article>
  <article class="metric accent"><span>予定反映後</span><strong><?= number_format($summary['forecast'], 1) ?><small>日</small></strong><div class="balance-detail"><span>前年繰越 <b><?= number_format($summary['forecast_previous_year'], 1) ?>日</b></span><span>今年付与 <b><?= number_format($summary['forecast_current_year'], 1) ?>日</b></span></div></article>
</section>
<?php if ($summary['days_until_renewal'] <= 60 && $summary['expiring_at_renewal'] > 0): ?>
<p class="notice leave-expiry-warning"><strong>有給の失効予定があります。</strong> <?= e($summary['renewal_date']) ?> の更新で、予定反映後も残る前年繰越 <?= number_format($summary['expiring_at_renewal'], 1) ?>日がなくなります。</p>
<?php endif; ?>
<p class="balance-rule">有給は今年付与分から先に消化します。残った分は翌年度へ繰り越せます。</p>
<section class="panel leave-calendar-panel">
  <div class="section-head"><div><h2>休暇・会社予定カレンダー</h2><p class="muted">自分の有給・代休と、会社共通の休日・予定を確認できます。一斉有給消化日は表示だけで、有給残数は自動では減りません。</p></div></div>
  <?php $calendarBaseUrl = url('leave'); $showPersonalLegend = true; $calendarDateAction = 'personal'; require __DIR__ . '/partials/calendar.php'; ?>
</section>
<?php $calendarReturnRoute = 'leave'; require __DIR__ . '/partials/personal_calendar_dialog.php'; ?>
<p class="notice"><?= \App\Settings::bool('leave_approval_required', true) ? '有給は「＋ 予定を登録」またはカレンダーの日付から登録できます。登録後、管理者の承認を受けると確定します。承認待ちの間も予定日数として残数を確保します。' : '有給は「＋ 予定を登録」またはカレンダーの日付から登録できます。マネージャーまたは上長への事前確認後に登録してください。現在、アプリ内の承認処理はありません。' ?></p>
<div class="one-col">
  <section class="panel"><h2>有給の取得履歴・取消履歴</h2>
    <div class="table-wrap"><table><thead><tr><th>取得日</th><th>区分</th><th>状態</th><th>操作</th></tr></thead><tbody>
      <?php foreach ($entries as $entry): $past = $entry['leave_date'] < date('Y-m-d'); $labels=['pending'=>'承認待ち','approved'=>'承認済み','registered'=>'登録済み','taken'=>'取得済み','rejected'=>'却下','cancelled'=>'取消済み']; ?><tr><td><?= e($entry['leave_date']) ?></td><td><?= e(['full'=>'1日','am'=>'午前休','pm'=>'午後休'][$entry['leave_type']]) ?></td><td><span class="tag"><?= e($labels[$entry['status']] ?? ($past ? '取得済み' : '登録済み')) ?></span><?php if ($entry['status']==='cancelled'): ?><small><?= e($entry['cancellation_reason']) ?></small><?php elseif($entry['status']==='rejected'): ?><small><?= e($entry['rejection_reason']) ?></small><?php endif; ?></td><td>
        <?php if (!in_array($entry['status'], ['cancelled','rejected'], true) && !$past): ?><form method="post" action="<?= e(url('leave/cancel')) ?>" class="inline-form" data-confirm="この有給予定を取り消しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>"><input name="reason" placeholder="取消理由" required><button class="danger small">取消</button></form><?php else: ?>—<?php endif; ?>
      </td></tr><?php endforeach; ?>
      <?php if (!$entries): ?><tr><td colspan="4" class="empty">履歴はありません。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>
<h2 class="section-title">代休<span class="metric-inline">残数 <?= number_format($compSummary['available'], 1) ?>日</span></h2>
<p class="balance-rule">代休は休日出勤の登録で自動的に付与されます。取得予定は「＋ 予定を登録」またはカレンダーの日付から登録できます。発生した事業年度（4月始まり）の年度末（翌3/31）まで有効です。</p>
<div class="one-col">
  <section class="panel"><h2>代休の取得・取消履歴</h2>
    <div class="table-wrap"><table><thead><tr><th>取得日</th><th>状態</th><th>操作</th></tr></thead><tbody>
      <?php foreach ($compEntries as $entry): $past = $entry['leave_date'] < date('Y-m-d'); ?><tr><td><?= e($entry['leave_date']) ?></td><td><span class="tag"><?= $entry['status']==='cancelled' ? '取消済み' : ($past ? '取得済み' : '登録済み') ?></span><?php if ($entry['status']==='cancelled'): ?><small><?= e($entry['cancellation_reason']) ?></small><?php endif; ?></td><td>
        <?php if ($entry['status'] !== 'cancelled' && !$past): ?><form method="post" action="<?= e(url('comp-leave/cancel')) ?>" class="inline-form" data-confirm="この代休予定を取り消しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>"><input name="reason" placeholder="取消理由" required><button class="danger small">取消</button></form><?php else: ?>—<?php endif; ?>
      </td></tr><?php endforeach; ?>
      <?php if (!$compEntries): ?><tr><td colspan="3" class="empty">代休はありません。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>
<h2 class="section-title">勤怠連絡</h2>
<p class="balance-rule">遅刻・早退・欠勤・休日出勤などは「＋ 予定を登録」またはカレンダーの日付から登録できます。</p>
<div class="one-col">
  <section class="panel"><h2>連絡履歴</h2>
    <div class="table-wrap"><table><thead><tr><th>対象日</th><th>種別</th><th>内容</th></tr></thead><tbody>
      <?php foreach(($notices ?? []) as $notice): ?><tr><td><?= e($notice['target_date']) ?></td><td><?= e($noticeLabels[$notice['notice_type']] ?? $notice['notice_type']) ?></td><td><?= nl2br(e($notice['details'])) ?></td></tr><?php endforeach; ?>
      <?php if(empty($notices)): ?><tr><td colspan="3" class="empty">履歴はありません。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>

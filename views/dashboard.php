<?php $working = $lastEvent && $lastEvent['event_type'] === 'clock_in'; $nextPlan = $upcoming[0] ?? null; $leaveTypeLabels = ['full'=>'1日','am'=>'午前休','pm'=>'午後休']; ?>
<div class="page-head welcome-head">
  <div class="welcome-copy">
    <p class="eyebrow">TODAY'S WORK</p>
    <?php if ($nextPlan): ?>
      <p class="welcome-plan">次の予定：<strong><?= e($nextPlan['leave_date']) ?></strong> 有給<?= e($leaveTypeLabels[$nextPlan['leave_type']] ?? '') ?><?= $nextPlan['status']==='pending' ? '（承認待ち）' : '' ?></p>
    <?php else: ?>
      <p class="welcome-plan muted">これからの有給予定はありません。</p>
    <?php endif; ?>
    <span class="status <?= $working ? 'working' : '' ?>"><?= $working ? '出勤中' : '退勤中' ?></span>
  </div>
  <div class="welcome-side">
    <p class="welcome-name"><?= e(\App\Auth::user()['full_name']) ?><span>さん</span></p>
    <?php /* 稼働状況（働いている／休んでいる）を示すドット表示。名前の下。現状は静的、将来アニメーション化する枠。 */ ?>
    <div class="work-indicator" data-state="<?= $working ? 'working' : 'resting' ?>" title="<?= $working ? '出勤中' : '退勤中' ?>" aria-hidden="true"><span></span><span></span><span></span></div>
  </div>
</div>
<section class="quick-actions">
  <form method="post" action="<?= e(url('attendance/clock')) ?>">
    <?= \App\Csrf::field() ?><input type="hidden" name="event_type" value="<?= $working ? 'clock_out' : 'clock_in' ?>">
    <button class="primary big clock-action <?= $working ? 'clock-out' : 'clock-in' ?>"><?= $working ? '退勤する' : '出勤する' ?></button>
  </form>
  <a class="button big" href="<?= e(url('leave')) ?>">有給予定を登録</a>
  <button type="button" class="button big js-open-entry-dialog" data-entry-kind="notice">勤怠連絡を登録</button>
</section>
<?php if ($summary['days_until_renewal'] <= 60 && $summary['expiring_at_renewal'] > 0): ?>
<p class="notice leave-expiry-warning"><strong>有給の失効予定があります。</strong> <?= e($summary['renewal_date']) ?> の更新で、予定反映後も残る前年繰越 <?= number_format($summary['expiring_at_renewal'], 1) ?>日がなくなります。</p>
<?php endif; ?>
<section class="summary-grid js-calendar-summary">
  <article class="metric"><span>現在の残有給数</span><strong><?= number_format($summary['current'], 1) ?><small>日</small></strong><div class="balance-detail"><span>前年繰越 <b><?= number_format($summary['previous_year'], 1) ?>日</b></span><span>今年付与 <b><?= number_format($summary['current_year'], 1) ?>日</b></span></div></article>
  <article class="metric"><span>登録済みの取得予定</span><strong><?= number_format($summary['scheduled'], 1) ?><small>日</small></strong></article>
  <article class="metric accent"><span>予定反映後の残数</span><strong><?= number_format($summary['forecast'], 1) ?><small>日</small></strong><div class="balance-detail"><span>前年繰越 <b><?= number_format($summary['forecast_previous_year'], 1) ?>日</b></span><span>今年付与 <b><?= number_format($summary['forecast_current_year'], 1) ?>日</b></span></div></article>
  <article class="metric"><span>代休残数</span><strong><?= number_format($compSummary['available'], 1) ?><small>日</small></strong><?php if ($compSummary['scheduled'] > 0): ?><div class="balance-detail"><span>取得予定 <b><?= number_format($compSummary['scheduled'], 1) ?>日</b></span></div><?php endif; ?></article>
</section>
<section class="panel leave-calendar-panel home-calendar-panel">
  <div class="section-head"><div><h2>予定カレンダー</h2><p class="muted">日付を選んで、有給・代休・勤怠連絡をまとめて登録できます。</p></div></div>
  <?php $calendarBaseUrl = url(); $showPersonalLegend = true; $calendarDateAction = 'personal'; require __DIR__ . '/partials/calendar.php'; ?>
</section>
<?php $calendarReturnRoute = ''; require __DIR__ . '/partials/personal_calendar_dialog.php'; ?>
<div class="two-col">
  <section class="panel"><div class="section-head"><h2>直近の有給予定</h2><a href="<?= e(url('leave')) ?>">すべて見る</a></div>
    <?php if (!$upcoming): ?><p class="empty">登録済みの予定はありません。</p><?php else: ?><ul class="item-list"><?php foreach ($upcoming as $entry): ?><li><strong><?= e($entry['leave_date']) ?></strong><span><?= e(['full'=>'1日','am'=>'午前休','pm'=>'午後休'][$entry['leave_type']]) ?><?= $entry['status']==='pending' ? '（承認待ち）' : '' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="panel"><div class="section-head"><h2>直近の勤怠連絡</h2><a href="<?= e(url('leave')) ?>">すべて見る</a></div>
    <?php if (!$notices): ?><p class="empty">登録済みの連絡はありません。</p><?php else: ?><ul class="item-list"><?php foreach ($notices as $notice): ?><li><strong><?= e($notice['target_date']) ?></strong><span><?= e($notice['notice_type']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>

<?php $working = $lastEvent && $lastEvent['event_type'] === 'clock_in'; ?>
<div class="page-head welcome-head"><div class="welcome-copy"><p class="eyebrow">TODAY'S WORK</p><h1><?= e(\App\Auth::user()['full_name']) ?>さん</h1><p class="muted">今日も自分のペースでいきましょう。</p></div><span class="status <?= $working ? 'working' : '' ?>"><?= $working ? '出勤中' : '退勤中' ?></span></div>
<section class="summary-grid">
  <article class="metric"><span>現在の残有給数</span><strong><?= number_format($summary['current'], 1) ?><small>日</small></strong><div class="balance-detail"><span>前年繰越 <b><?= number_format($summary['previous_year'], 1) ?>日</b></span><span>今年付与 <b><?= number_format($summary['current_year'], 1) ?>日</b></span></div></article>
  <article class="metric"><span>登録済みの取得予定</span><strong><?= number_format($summary['scheduled'], 1) ?><small>日</small></strong></article>
  <article class="metric accent"><span>予定反映後の残数</span><strong><?= number_format($summary['forecast'], 1) ?><small>日</small></strong><div class="balance-detail"><span>前年繰越 <b><?= number_format($summary['forecast_previous_year'], 1) ?>日</b></span><span>今年付与 <b><?= number_format($summary['forecast_current_year'], 1) ?>日</b></span></div></article>
  <article class="metric"><span>代休残数</span><strong><?= number_format($compSummary['available'], 1) ?><small>日</small></strong><?php if ($compSummary['scheduled'] > 0): ?><div class="balance-detail"><span>取得予定 <b><?= number_format($compSummary['scheduled'], 1) ?>日</b></span></div><?php endif; ?></article>
</section>
<section class="quick-actions">
  <form method="post" action="<?= e(url('attendance/clock')) ?>">
    <?= \App\Csrf::field() ?><input type="hidden" name="event_type" value="<?= $working ? 'clock_out' : 'clock_in' ?>">
    <button class="primary big"><?= $working ? '退勤する' : '出勤する' ?></button>
  </form>
  <a class="button big" href="<?= e(url('leave')) ?>">有給予定を登録</a>
  <a class="button big" href="<?= e(url('notice')) ?>">勤怠連絡を登録</a>
</section>
<div class="two-col">
  <section class="panel"><div class="section-head"><h2>直近の有給予定</h2><a href="<?= e(url('leave')) ?>">すべて見る</a></div>
    <?php if (!$upcoming): ?><p class="empty">登録済みの予定はありません。</p><?php else: ?><ul class="item-list"><?php foreach ($upcoming as $entry): ?><li><strong><?= e($entry['leave_date']) ?></strong><span><?= e(['full'=>'1日','am'=>'午前休','pm'=>'午後休'][$entry['leave_type']]) ?><?= $entry['status']==='pending' ? '（承認待ち）' : '' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="panel"><div class="section-head"><h2>直近の勤怠連絡</h2><a href="<?= e(url('notice')) ?>">すべて見る</a></div>
    <?php if (!$notices): ?><p class="empty">登録済みの連絡はありません。</p><?php else: ?><ul class="item-list"><?php foreach ($notices as $notice): ?><li><strong><?= e($notice['target_date']) ?></strong><span><?= e($notice['notice_type']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>

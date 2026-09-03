<?php $working = $lastEvent && $lastEvent['event_type'] === 'clock_in'; ?>
<div class="page-head"><div><h1>出退勤</h1><p class="muted">サーバーの日本時間で記録されます。</p></div><span class="status <?= $working ? 'working' : '' ?>"><?= $working ? '出勤中' : '退勤中' ?></span></div>
<section class="panel clock-panel"><p>現在時刻</p><strong id="clock"><?= date('Y/m/d H:i:s') ?></strong><form method="post" action="<?= e(url('attendance/clock')) ?>"><?= \App\Csrf::field() ?><input type="hidden" name="event_type" value="<?= $working ? 'clock_out' : 'clock_in' ?>"><button class="primary big"><?= $working ? '退勤を記録' : '出勤を記録' ?></button></form></section>
<section class="summary-grid compact">
  <article class="metric"><span><?= e($month) ?> 出勤日数</span><strong><?= (int)$workedDays ?><small>日</small></strong></article>
  <article class="metric accent"><span><?= e($month) ?> 勤務時間</span><strong><?= e($workedTime) ?></strong></article>
</section>
<p class="balance-rule">勤務時間は打刻（退勤−出勤）の合計です。休憩・残業の控除は含みません。</p>
<section class="panel"><h2>打刻履歴</h2><div class="table-wrap"><table><thead><tr><th>日時</th><th>種別</th></tr></thead><tbody><?php foreach ($events as $event): ?><tr><td><?= e($event['occurred_at']) ?></td><td><?= $event['event_type']==='clock_in' ? '出勤' : '退勤' ?></td></tr><?php endforeach; ?><?php if (!$events): ?><tr><td colspan="2" class="empty">履歴はありません。</td></tr><?php endif; ?></tbody></table></div></section>
<script>setInterval(function(){document.getElementById('clock').textContent=new Intl.DateTimeFormat('ja-JP',{dateStyle:'medium',timeStyle:'medium',timeZone:'Asia/Tokyo'}).format(new Date())},1000)</script>


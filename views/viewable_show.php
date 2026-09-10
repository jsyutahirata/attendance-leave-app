<div class="page-head"><div><h1><?= e($employee['full_name']) ?>さんの状況</h1><p class="muted">閲覧専用です。この画面から登録・取消・調整はできません。</p></div><a class="button" href="<?= e(url('viewable')) ?>">閲覧一覧へ</a></div>
<section class="summary-grid compact">
  <article class="metric"><span>有給 現在残数</span><strong><?= number_format($summary['current'], 1) ?><small>日</small></strong></article>
  <article class="metric"><span>有給 取得予定</span><strong><?= number_format($summary['scheduled'], 1) ?><small>日</small></strong></article>
  <article class="metric accent"><span>代休残数</span><strong><?= number_format($compSummary['available'], 1) ?><small>日</small></strong></article>
</section>
<div class="two-col">
  <section class="panel"><h2>代休予定・履歴（直近20件）</h2><div class="table-wrap"><table><thead><tr><th>取得日</th><th>日数</th><th>状態</th></tr></thead><tbody>
    <?php foreach ($compEntries as $entry): ?><tr><td><?= e($entry['leave_date']) ?></td><td><?= number_format((float)$entry['days'], 1) ?>日</td><td><?= $entry['status'] === 'taken' ? '取得済み' : '登録済み' ?></td></tr><?php endforeach; ?>
    <?php if (!$compEntries): ?><tr><td colspan="3" class="empty">代休の予定・履歴はありません。</td></tr><?php endif; ?>
  </tbody></table></div></section>
  <section class="panel"><h2>有給予定・履歴</h2><div class="table-wrap"><table><thead><tr><th>取得日</th><th>区分</th><th>状態</th></tr></thead><tbody>
    <?php foreach ($entries as $entry): $past = $entry['leave_date'] < date('Y-m-d'); ?><tr><td><?= e($entry['leave_date']) ?></td><td><?= e(['full'=>'1日','am'=>'午前休','pm'=>'午後休'][$entry['leave_type']]) ?></td><td><?= $entry['status']==='pending' ? '承認待ち' : ($past || $entry['status']==='taken' ? '取得済み' : '登録済み') ?></td></tr><?php endforeach; ?>
    <?php if (!$entries): ?><tr><td colspan="3" class="empty">有給の予定はありません。</td></tr><?php endif; ?>
  </tbody></table></div></section>
  <section class="panel"><h2>直近の勤怠連絡</h2><div class="table-wrap"><table><thead><tr><th>対象日</th><th>種別</th></tr></thead><tbody>
    <?php $ntypes=['late'=>'遅刻','early'=>'早退','leave_full'=>'有給（1日）','leave_am'=>'有給（午前）','leave_pm'=>'有給（午後）','absence'=>'欠勤','holiday_work'=>'休日出勤','medical'=>'健康診断','other'=>'その他']; ?>
    <?php foreach ($notices as $notice): ?><tr><td><?= e($notice['target_date']) ?></td><td><?= e($ntypes[$notice['notice_type']] ?? $notice['notice_type']) ?></td></tr><?php endforeach; ?>
    <?php if (!$notices): ?><tr><td colspan="2" class="empty">連絡はありません。</td></tr><?php endif; ?>
  </tbody></table></div></section>
</div>
<section class="panel"><h2>直近の打刻</h2><div class="table-wrap"><table><thead><tr><th>日時</th><th>種別</th></tr></thead><tbody>
  <?php foreach ($events as $event): ?><tr><td><?= e($event['occurred_at']) ?></td><td><?= $event['event_type']==='clock_in'?'出勤':'退勤' ?></td></tr><?php endforeach; ?>
  <?php if (!$events): ?><tr><td colspan="2" class="empty">打刻はありません。</td></tr><?php endif; ?>
</tbody></table></div></section>

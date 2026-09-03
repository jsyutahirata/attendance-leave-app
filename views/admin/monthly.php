<div class="page-head"><div><h1>月次集計</h1><p class="muted">指定月の社員別の出勤日数・勤務時間を集計します。</p></div><a class="button" href="<?= e(url('admin/attendance')) ?>">全社勤怠</a></div>
<section class="panel">
  <form method="get" action="<?= e(url('admin/attendance/monthly')) ?>" class="inline-form">
    <input type="hidden" name="route" value="admin/attendance/monthly">
    <label>対象月<input type="month" name="month" value="<?= e($month) ?>"></label>
    <button class="primary">表示</button>
    <a class="button" href="<?= e(url('admin/attendance')) ?>">CSV出力へ</a>
  </form>
  <p class="balance-rule">勤務時間は打刻（退勤−出勤、業務日＝出勤日）の合計です。休憩・残業の控除は含みません（ルールは今後確定）。「未退勤」は退勤打刻が無い出勤の件数です。</p>
  <div class="table-wrap"><table><thead><tr><th>社員</th><th>社員番号</th><th>出勤日数</th><th>勤務時間(H:MM)</th><th>未退勤</th></tr></thead><tbody>
    <?php foreach ($summary as $row): ?><tr>
      <td><?= e($row['full_name']) ?></td>
      <td><?= e($row['employee_code'] ?: '—') ?></td>
      <td><?= (int)$row['worked_days'] ?>日</td>
      <td><strong><?= e(\App\AttendanceService::formatMinutes((int)$row['total_minutes'])) ?></strong></td>
      <td><?= (int)$row['incomplete'] > 0 ? '<span class="tag" style="background:#feeaea;color:#8a2e2e">'.(int)$row['incomplete'].'件</span>' : '—' ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$summary): ?><tr><td colspan="5" class="empty">この月の打刻はありません。</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

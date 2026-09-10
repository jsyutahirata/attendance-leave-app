<?php
$editing = $editEvent ?? null;
$selectedType = $editing['event_type'] ?? 'company_holiday';
$startDate = $editing['start_date'] ?? date('Y-m-d');
$endDate = $editing['end_date'] ?? $startDate;
?>
<div class="page-head"><div><h1>会社カレンダー設定</h1><p class="muted">会社休日、一斉有給消化日、全体定例会議などを全社員の有給カレンダーに表示します。</p></div><a class="button secondary" href="<?= e(url('admin')) ?>">管理トップへ</a></div>
<section class="panel company-calendar-note"><strong>一斉有給消化日は表示専用です。</strong><p>この予定を登録しても社員の有給残数は減りません。実際に休む社員は通常の有給登録を行い、出勤する社員はそのまま出勤できます。</p></section>
<p class="balance-rule">日本の祝日と休日は内閣府の公式CSVから自動更新します。最終同期：<?= e($holidaySyncAt ?: '未同期（内蔵データを表示中）') ?></p>
<section class="panel">
  <div class="section-head"><div><h2>会社カレンダーをExcelから取り込む</h2><p class="muted">「J'sカレンダー」のAP～AV列から、会社休日・全体定例会議・一斉有給消化日・会社行事を読み取ります。登録前に内容を確認できます。</p></div></div>
  <form method="post" action="<?= e(url('admin/calendar/import/preview')) ?>" enctype="multipart/form-data" class="import-upload">
    <?= \App\Csrf::field() ?><input type="hidden" name="MAX_FILE_SIZE" value="5242880">
    <label>取り込むシート<select name="calendar_sheet" required><option value="一般">一般</option><option value="閏年">閏年</option></select></label>
    <input type="file" name="calendar_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><button class="primary">Excelを読み込む</button>
  </form>
  <?php if ($importPreview): ?>
    <div class="import-preview">
      <div class="section-head"><div><h2>取り込み内容の確認</h2><p class="muted"><?= e($importPreview['source_name']) ?> ／ <?= e($importPreview['selected_sheet'] ?? '') ?>シート ／ 新規 <?= (int)$importPreview['valid_count'] ?>件・重複 <?= (int)$importPreview['duplicate_count'] ?>件・エラー <?= (int)$importPreview['error_count'] ?>件</p></div></div>
      <div class="table-wrap"><table><thead><tr><th>元セル</th><th>日付</th><th>種別</th><th>予定名</th><th>判定</th></tr></thead><tbody>
        <?php foreach ($importPreview['rows'] as $row): ?><tr><td><?= e($row['source_sheet']) ?>!<?= e($row['source_cell']) ?></td><td><?= e($row['start_date'] ?: '—') ?></td><td><?= e(\App\CompanyCalendarService::TYPES[$row['event_type']] ?? $row['event_type']) ?></td><td><?= e($row['title']) ?></td><td><?php if ($row['errors']): ?><span class="tag danger-tag"><?= e(implode('／', $row['errors'])) ?></span><?php elseif ($row['duplicate']): ?><span class="tag">重複・スキップ</span><?php else: ?><span class="tag success-tag">取り込み対象</span><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="row-actions">
        <?php if ((int)$importPreview['error_count'] === 0 && (int)$importPreview['valid_count'] > 0): ?><form method="post" action="<?= e(url('admin/calendar/import/confirm')) ?>" data-confirm="表示されている会社予定を取り込みますか？"><?= \App\Csrf::field() ?><button class="primary">この内容で取り込む</button></form><?php endif; ?>
        <form method="post" action="<?= e(url('admin/calendar/import/cancel')) ?>"><?= \App\Csrf::field() ?><button>確認を取り消す</button></form>
      </div>
      <?php if ((int)$importPreview['error_count'] > 0): ?><p class="flash error">エラー行があります。Excelを修正して、もう一度読み込んでください。</p><?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<section class="panel">
  <?php $calendarBaseUrl = url('admin/calendar'); $showPersonalLegend = false; require dirname(__DIR__) . '/partials/calendar.php'; ?>
</section>
<div class="two-col form-first">
  <section class="panel"><h2><?= $editing ? '会社予定を編集' : '会社予定を追加' ?></h2>
    <form method="post" action="<?= e(url('admin/calendar/save')) ?>">
      <?= \App\Csrf::field() ?>
      <input type="hidden" name="event_id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <label>種別<select name="event_type" required><?php foreach (\App\CompanyCalendarService::TYPES as $value => $label): ?><option value="<?= e($value) ?>" <?= $selectedType === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label>予定名<input name="title" maxlength="100" value="<?= e($editing['title'] ?? '') ?>" placeholder="例：敬老の日、年末年始休業、9月全体定例" required></label>
      <div class="field-row"><label>開始日<input type="date" name="start_date" value="<?= e($startDate) ?>" required></label><label>終了日<input type="date" name="end_date" value="<?= e($endDate) ?>" required></label></div>
      <div class="field-row"><label>開始時刻（任意）<input type="time" name="start_time" value="<?= e(isset($editing['start_time']) ? substr((string)$editing['start_time'], 0, 5) : '') ?>"></label><label>終了時刻（任意）<input type="time" name="end_time" value="<?= e(isset($editing['end_time']) ? substr((string)$editing['end_time'], 0, 5) : '') ?>"></label></div>
      <label>補足（任意）<textarea name="notes" rows="3" maxlength="1000"><?= e($editing['notes'] ?? '') ?></textarea></label>
      <div class="row-actions"><button class="primary"><?= $editing ? '変更を保存' : '予定を追加' ?></button><?php if ($editing): ?><a class="button secondary" href="<?= e(url('admin/calendar') . '&month=' . $calendar['month']) ?>">編集をやめる</a><?php endif; ?></div>
    </form>
  </section>
  <section class="panel"><h2>登録済みの会社予定</h2>
    <div class="table-wrap"><table><thead><tr><th>日付</th><th>種別・予定</th><th>操作</th></tr></thead><tbody>
      <?php foreach ($events as $event): ?><tr><td><?= e($event['start_date']) ?><?= $event['end_date'] !== $event['start_date'] ? '<br>〜 ' . e($event['end_date']) : '' ?></td><td><span class="calendar-item company-<?= e($event['event_type']) ?>"><?= e(\App\CompanyCalendarService::TYPES[$event['event_type']] ?? $event['event_type']) ?></span><strong class="event-title"><?= e($event['title']) ?></strong><?php if ($event['start_time']): ?><small><?= e(substr((string)$event['start_time'], 0, 5)) ?><?= $event['end_time'] ? '〜' . e(substr((string)$event['end_time'], 0, 5)) : '' ?></small><?php endif; ?><?php if ($event['notes']): ?><small><?= e($event['notes']) ?></small><?php endif; ?></td><td><div class="row-actions"><a class="button secondary small" href="<?= e(url('admin/calendar') . '&month=' . substr((string)$event['start_date'], 0, 7) . '&edit=' . (int)$event['id']) ?>">編集</a><form method="post" action="<?= e(url('admin/calendar/delete')) ?>" class="inline-form" data-confirm="この会社予定を削除しますか？"><?= \App\Csrf::field() ?><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>"><button class="danger small">削除</button></form></div></td></tr><?php endforeach; ?>
      <?php if (!$events): ?><tr><td colspan="3" class="empty">会社予定はまだ登録されていません。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>

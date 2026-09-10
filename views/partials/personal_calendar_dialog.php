<dialog class="employee-edit-dialog calendar-entry-dialog" id="personal-calendar-dialog" aria-labelledby="personal-calendar-dialog-title"<?= !empty($calendarAddDate) ? ' open' : '' ?>>
  <form method="post" action="<?= e(url('calendar/personal/create')) ?>" class="js-personal-calendar-form">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="return_route" value="<?= e($calendarReturnRoute ?? '') ?>">
    <div class="modal-head"><div><h2 id="personal-calendar-dialog-title">この日の予定を追加</h2><p class="muted modal-description">有給・代休・勤怠連絡をここから登録できます。</p></div><button type="button" class="modal-close" data-calendar-dialog-close aria-label="閉じる">×</button></div>
    <p class="calendar-form-status" role="status" hidden></p>
    <div class="field-row">
      <label>対象日<input type="date" name="entry_date" value="<?= e($calendarAddDate ?? '') ?>" required></label>
      <label>予定の種類<select name="entry_kind" required><option value="leave">有給</option><option value="comp_leave">代休</option><option value="notice">勤怠連絡</option></select></label>
    </div>
    <div class="calendar-form-section" data-calendar-fields="leave">
      <label>取得区分<select name="leave_type"><option value="full">1日（1.0日）</option><option value="am">午前休（0.5日）</option><option value="pm">午後休（0.5日）</option></select></label>
      <label>事前確認相手<input name="confirmed_with" maxlength="100" placeholder="例：山田課長"></label>
      <label>連絡事項<textarea name="note" rows="3" placeholder="例：私用のため"></textarea></label>
    </div>
    <div class="calendar-form-section is-hidden" data-calendar-fields="comp_leave">
      <p class="modal-note">代休は1日単位です。取得可能な残数が必要です。</p>
      <label>連絡事項<textarea name="comp_note" rows="3" placeholder="例：休日出勤の振替"></textarea></label>
    </div>
    <div class="calendar-form-section is-hidden" data-calendar-fields="notice">
      <label>勤怠種別<select name="notice_type"><option value="late">遅刻</option><option value="early">早退</option><option value="absence">欠勤</option><option value="holiday_work">休日出勤</option><option value="medical">健康診断</option><option value="other">その他</option></select></label>
      <div class="field-row"><label>出勤見込み<input type="time" name="expected_start"></label><label>退勤見込み<input type="time" name="expected_end"></label></div>
      <label>連絡内容・理由<textarea name="details" rows="4" placeholder="例：電車遅延のため15分ほど遅刻します"></textarea></label>
    </div>
    <div class="modal-actions"><button type="button" data-calendar-dialog-close>キャンセル</button><button class="primary" type="submit">登録する</button></div>
  </form>
</dialog>

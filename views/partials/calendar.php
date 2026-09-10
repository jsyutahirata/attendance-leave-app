<?php
$calendarDate = new \DateTimeImmutable($calendar['month'] . '-01');
$previousYear = $calendarDate->modify('-1 year')->format('Y-m');
$nextYear = $calendarDate->modify('+1 year')->format('Y-m');
$calendarQuerySeparator = str_contains($calendarBaseUrl, '?') ? '&' : '?';
$previousMonthUrl = $calendarBaseUrl . $calendarQuerySeparator . 'month=' . $calendar['previous'];
$nextMonthUrl = $calendarBaseUrl . $calendarQuerySeparator . 'month=' . $calendar['next'];
$calendarDateAction = $calendarDateAction ?? '';
?>
<div class="calendar-widget js-calendar-widget" data-calendar-month="<?= e($calendar['month']) ?>" data-calendar-base-url="<?= e($calendarBaseUrl) ?>">
<div class="calendar-toolbar">
  <h2><?= e($calendar['label']) ?></h2>
  <div class="calendar-toolbar-actions">
    <a class="button secondary small js-calendar-navigation" data-calendar-direction="previous" href="<?= e($calendarBaseUrl . $calendarQuerySeparator . 'month=' . $previousYear) ?>" aria-label="前年の同じ月">« 前年</a>
    <a class="button secondary small js-calendar-navigation" data-calendar-direction="previous" href="<?= e($previousMonthUrl) ?>" aria-label="前の月">← 前月</a>
    <a class="button secondary small js-calendar-navigation" data-calendar-direction="current" href="<?= e($calendarBaseUrl . $calendarQuerySeparator . 'month=' . date('Y-m')) ?>">今月</a>
    <a class="button secondary small js-calendar-navigation" data-calendar-direction="next" href="<?= e($nextMonthUrl) ?>" aria-label="次の月">次月 →</a>
    <a class="button secondary small js-calendar-navigation" data-calendar-direction="next" href="<?= e($calendarBaseUrl . $calendarQuerySeparator . 'month=' . $nextYear) ?>" aria-label="翌年の同じ月">翌年 »</a>
  </div>
</div>
<div class="calendar-legend" aria-label="予定の色分け">
  <?php if (!empty($showPersonalLegend)): ?><span class="personal-leave">自分の有給</span><span class="personal-comp-leave">自分の代休</span><span class="personal-notice">自分の勤怠連絡</span><?php endif; ?>
  <span class="company-public_holiday">祝日（自動表示）</span><span class="company-company_holiday">会社休日</span><span class="company-recommended_leave">一斉有給消化日（予定）</span><span class="company-all_hands">全体定例会議</span><span class="company-other">その他</span>
</div>
<p class="calendar-swipe-hint"><span class="calendar-touch-hint">左右にスワイプして月を移動できます。</span><span class="calendar-desktop-hint">左右にドラッグ、またはトラックパッドの横スワイプで月を移動できます。</span></p>
<div class="calendar-scroll js-swipe-calendar" tabindex="0" aria-label="<?= e($calendar['label']) ?>のカレンダー" data-previous-month-url="<?= e($previousMonthUrl) ?>" data-next-month-url="<?= e($nextMonthUrl) ?>">
  <div class="month-calendar">
    <?php foreach (['日','月','火','水','木','金','土'] as $weekday): ?><div class="calendar-weekday"><?= $weekday ?></div><?php endforeach; ?>
    <?php foreach ($calendar['days'] as $day): ?>
      <article class="calendar-day<?= !$day['current_month'] ? ' outside' : '' ?><?= $day['today'] ? ' today' : '' ?><?= $day['weekday'] === 0 ? ' sunday' : ($day['weekday'] === 6 ? ' saturday' : '') ?><?= $day['public_holiday'] ? ' public-holiday' : '' ?><?= $calendarDateAction === 'personal' ? ' calendar-day-interactive js-personal-calendar-date' : '' ?>"<?= $calendarDateAction === 'personal' ? ' data-calendar-date="' . e($day['date']) . '" tabindex="0"' : '' ?>>
        <time datetime="<?= e($day['date']) ?>"><?= e($day['day']) ?></time>
        <?php if ($calendarDateAction === 'personal'): ?><a class="calendar-add-button js-calendar-date-add" href="<?= e($calendarBaseUrl . $calendarQuerySeparator . 'month=' . substr($day['date'], 0, 7) . '&add_date=' . $day['date']) ?>" data-calendar-date="<?= e($day['date']) ?>" aria-label="<?= e($day['date']) ?>に予定を追加">＋</a><?php endif; ?>
        <div class="calendar-items">
          <?php foreach ($day['items'] as $item): ?>
            <?php if ($item['detail'] !== ''): ?><details class="calendar-item calendar-item-detail <?= e($item['class']) ?>"><summary><?= e($item['label']) ?></summary><p><?= e($item['detail']) ?></p></details><?php else: ?><span class="calendar-item <?= e($item['class']) ?>"><?= e($item['label']) ?></span><?php endif; ?>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div>
</div>

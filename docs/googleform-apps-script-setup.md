# テストフォーム連携セットアップ手順（Google Apps Script）

対象テストフォーム: 「出退勤テスト」
連携先エンドポイント: `public/webhook.php`（本コミットで実装済み）

---

## ⚠ 最重要: Apps Script は localhost に到達できない

Google Apps Script（`UrlFetchApp`）は**Googleのサーバー上で動く**ため、あなたのPCの
`http://localhost/...`（XAMPP）には**直接アクセスできません**。実データ往復テストの入口は次の2択:

| 方法 | 概要 | 向き |
|------|------|------|
| **(推奨) トンネルで公開** | ngrok / Cloudflare Tunnel でローカルXAMPPを一時的に公開し、その https URL を Apps Script に設定 | 手軽・本番DBに触れず検証 |
| **XServerに配置** | webhook.php等を本番/検証サーバーに上げ、そのURLでテスト | 公開URLが即得られる |

ngrok 例:
```
ngrok http 80
# → https://xxxx.ngrok-free.app が発行される
# WEBHOOK_URL = https://xxxx.ngrok-free.app/<公開ディレクトリ>/webhook.php
```
※ URLの `/webhook.php` の前は、XAMPPで `public/` をドキュメントルートにしているかで変わります。
　`http://localhost/attendance/public/webhook.php` で見えているなら、トンネルURLも同じパス構成にします。

---

## 事前準備（アプリ側）

1. マイグレーション適用（冪等テーブル追加）:
   ```
   database/migrations/20260909_add_form_webhook_events.sql
   ```
   （既存の `scripts/run-migration.php` があればそれで。無ければ phpMyAdmin 等でSQL実行）
2. `.env` に共有トークンを設定（十分に長いランダム文字列）:
   ```
   FORM_WEBHOOK_TOKEN=（例）0d3f...64文字程度のランダム
   ```
3. トンネル/サーバーで `webhook.php` に到達できることを確認。未POSTだと405、トークン無しだと401が返れば正常。

疎通の素振り（PowerShell）:
```powershell
$body = '{"name":"平田雄大","event_type":"clock_in","occurred_at":"2026-09-08 09:00:00","response_id":"manual-1"}'
Invoke-RestMethod -Method Post -Uri "https://xxxx.ngrok-free.app/attendance/public/webhook.php" `
  -Headers @{ "X-Webhook-Token" = "（.envと同じトークン）" } -ContentType "application/json" -Body $body
```
`ok = True` と `event_id` が返れば成功。`平田テスト`を送ると `unknown_employee` が返るはず。

---

## Apps Script の設置

1. テストフォーム編集画面 → 右上「⋮」→ **スクリプト エディタ**（またはApps Script）を開く
2. 下の `Code.gs` を貼り付け
3. 左メニュー「プロジェクトの設定」→ **スクリプト プロパティ** に3つ登録:
   - `WEBHOOK_URL` … 例 `https://xxxx.ngrok-free.app/attendance/public/webhook.php`
   - `WEBHOOK_TOKEN` … `.env` の `FORM_WEBHOOK_TOKEN` と**完全一致**
   - `ADMIN_EMAIL` … 失敗通知の宛先
4. トリガー（⏰アイコン）→ **トリガーを追加**:
   - 実行する関数: `onFormSubmit`
   - イベントのソース: **フォームから**
   - イベントの種類: **フォーム送信時**
5. 初回は権限承認ダイアログが出るので許可

---

## Code.gs

```javascript
/**
 * 「出退勤テスト」フォーム送信時に、勤怠アプリのWebhookへ打刻を転送する。
 * スクリプトプロパティ: WEBHOOK_URL / WEBHOOK_TOKEN / ADMIN_EMAIL
 */
function onFormSubmit(e) {
  const props = PropertiesService.getScriptProperties();
  const url   = props.getProperty('WEBHOOK_URL');
  const token = props.getProperty('WEBHOOK_TOKEN');
  const admin = props.getProperty('ADMIN_EMAIL');

  // 回答を項目タイトルで取り出す（本番フォームと同じ「氏名」「出退勤」）。
  const answers = {};
  e.response.getItemResponses().forEach(function (ir) {
    answers[ir.getItem().getTitle()] = ir.getResponse();
  });

  const name = String(answers['氏名'] || '').trim();
  const type = answers['出退勤'] === '出勤' ? 'clock_in'
             : answers['出退勤'] === '退勤' ? 'clock_out' : '';

  // 送信時刻をJST（Y-m-d H:i:s）で整形。
  const occurredAt = Utilities.formatDate(
    e.response.getTimestamp(), 'Asia/Tokyo', 'yyyy-MM-dd HH:mm:ss');

  const payload = {
    name: name,
    event_type: type,
    occurred_at: occurredAt,
    response_id: e.response.getId(),
  };

  const res = UrlFetchApp.fetch(url, {
    method: 'post',
    contentType: 'application/json',
    headers: { 'X-Webhook-Token': token },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true,
  });

  const code = res.getResponseCode();
  if (code !== 200) {
    // 失敗してもフォーム回答は残るので後追い可能。管理者へ通知する。
    if (admin) {
      MailApp.sendEmail(admin, '【勤怠連携】打刻の自動反映に失敗',
        '氏名: ' + name + '\n区分: ' + type + '\n時刻: ' + occurredAt +
        '\nHTTP: ' + code + '\n応答: ' + res.getContentText());
    }
    console.error('webhook failed: ' + code + ' ' + res.getContentText());
  }
}
```

---

## テスト観点（フォームから実際に送信して確認）

1. `平田雄大` + 出勤 → `attendance_events` に1件、`admin/attendance` に反映
2. 続けて `平田雄大` + 退勤 → 2件目が記録
3. `平田雄大` + 出勤 を連続送信 → 2回目は `out_of_order`（記録されない・管理者通知）
4. `平田テスト` + 出勤 → `unknown_employee`（未登録氏名の弾き確認）
5. 土曜の日付で出勤 → 代休1日が自動付与される
6. 同じ送信を素早く2回 → `response_id` により1件だけ記録

> 本番展開時は、フォーム所有者から**回答スプレッドシートの編集権限**をもらい、
> スプレッドシート側に同じ `onFormSubmit` トリガーを付ければ、フォーム本体を触らずに連携できます。
> スクリプトプロパティの `WEBHOOK_URL` を本番URLに、`WEBHOOK_TOKEN` を本番 `.env` の値に差し替えるだけです。

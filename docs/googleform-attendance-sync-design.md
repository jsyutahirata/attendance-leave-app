# Googleフォーム打刻 → 勤怠アプリ自動連携 設計書

作成日: 2026-09-09
対象: 「出退勤フォーム」(Googleフォーム) の送信を、勤怠アプリの `attendance_events` に自動記録する連携

---

## 1. 目的・全体像

現在、出退勤はGoogleフォームで記録している。フォーム送信時に、勤怠アプリ側の打刻(`attendance_events`)も自動で行う仕組みを作る。

```
[社員] Googleフォーム送信（氏名 / 出勤・退勤 を選択）
   │  ← フォーム送信時刻を打刻時刻として使用
   ▼
Google Apps Script（onFormSubmit トリガー）
   │  ← 氏名・出退勤区分・送信時刻 ＋ 共有トークン を JSON で POST
   ▼
勤怠アプリ  public/webhook.php（新規・専用エントリ）
   │  ① トークン検証
   │  ② 氏名 → 社員(employee) 照合
   │  ③ 直前打刻との順序チェック（二重打刻防止）
   │  ④ attendance_events へ INSERT ＋ 監査ログ
   ▼
JSON レスポンス（ok / エラー理由）を Apps Script が受け取り、失敗時は管理者へ通知メール
```

フォーム側は Apps Script を1つ追加するだけ、アプリ側は **トークン認証付きの受け口を1本追加** するだけ。既存のログイン用打刻(`attendance/clock`)は触らない。

---

## 2. 決定事項（推奨・要確認）

| # | 論点 | 推奨方針 | 備考 |
|---|------|----------|------|
| 1 | 氏名の突き合わせ | **Apps Script側に「フォーム氏名 → 社員番号」対応表**を持たせ、社員番号でアプリに送る | 氏名の表記ゆれ(スペース有無等)に依存せず堅牢。社員番号未設定の社員がいれば先に登録が必要 |
| 2 | 二重打刻・順序ガード | **既存 `clock()` と同じ順序チェックを入れる**（出勤中の再出勤は弾く） | 弾いた場合は記録せず、Apps Script経由で本人/管理者に通知 |
| 3 | アプリ既存の打刻ボタン | **残す**（フォームを正の運用にしつつ、緊急時の手段として温存） | 非表示にしたい場合は `views/attendance.php` の打刻フォーム部分を調整 |

> **要確認**: 論点1について、現状フォームの氏名プルダウンには社員番号が無い。社員番号方式にするならフォームに項目追加 or Apps Scriptの対応表を人力で用意する。フォームを一切触らず氏名文字列のまま照合する場合は「フォーム氏名 → 社員」対応表をアプリ側かApps Script側に持つ（表記完全一致 or 空白除去正規化）。

---

## 3. アプリ側 実装設計

### 3.1 専用エントリ `public/webhook.php`（新規）

既存 `public/index.php` は **全POSTに対し `Csrf::verify()`、非公開ルートに `Auth::requireLogin()` を強制**する。Webhookはセッションを持たないためこのゲートを通れない。よって **index.php を経由しない独立エントリ** を新設する。

責務:
1. `Content-Type: application/json` を返す
2. リクエストヘッダの共有トークンを検証（不一致は 401）
3. JSONボディをパース・バリデート（不正は 400）
4. `WebhookAttendanceService`（新規サービス）に処理を委譲
5. 成功/失敗を JSON で返す

```php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use App\WebhookAttendanceService;

header('Content-Type: application/json; charset=utf-8');

// ① トークン検証（タイミング安全比較）
$expected = (string) config('FORM_WEBHOOK_TOKEN', '');
$provided = (string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ② 入力パース
$raw = file_get_contents('php://input') ?: '';
try {
    $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ③ 処理委譲
try {
    $result = WebhookAttendanceService::record((array) $payload);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (\App\WebhookException $e) {           // 業務エラー（順序違反・氏名不一致など）
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getCode(), 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[webhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
}
```

> **サーバ設定の注意**: Webサーバのルーティングで `webhook.php` へ直接アクセスできること。`.htaccess` などで `index.php` に全リライトしている場合は、`webhook.php` を除外する。XServer(本番)/XAMPP(ローカル)双方で要確認。

### 3.2 リクエスト仕様（Apps Script → アプリ）

- Method: `POST`
- URL: `https://<APP_URL>/webhook.php`
- Header: `X-Webhook-Token: <FORM_WEBHOOK_TOKEN>`
- Body(JSON):

```json
{
  "employee_code": "E012",        // 論点1で社員番号方式の場合
  "name": "平田雄大",              // 氏名方式の場合（どちらか / 両方送って優先順で照合）
  "event_type": "clock_in",       // "clock_in" | "clock_out"
  "occurred_at": "2026-09-09 09:01:23",  // JST。フォーム送信時刻
  "response_id": "2_ABc...xyz"    // フォーム回答ID（冪等キー・任意）
}
```

### 3.3 氏名/社員の照合

- **社員番号方式**: `SELECT id FROM employees WHERE employee_code = ?`
- **氏名方式**: `SELECT id FROM employees WHERE full_name = ?`。ヒット0件/複数件はエラー(`unknown_employee` / `ambiguous_employee`)にして記録しない（誤記録防止）。空白ゆれ対策として、両辺から空白を除去して比較する正規化を挟む選択肢あり。
- いずれも該当社員が見つからなければ **記録せずエラー返却** → Apps Scriptが管理者へ通知。

### 3.4 `created_by` の解決（FK制約対応）

`attendance_events.created_by` は `NOT NULL` かつ `users(id)` への外部キー。Webhookにはログインユーザーがいないため:

- **方針: 打刻した本人の user.id を `created_by` に使う**
  `SELECT id FROM users WHERE employee_id = ? AND status='active'` で取得。
- 該当ユーザーが無い/無効なら記録せずエラー(`no_user_for_employee`)。
- （代替案）専用の「システムユーザー」を1件作り、その id を `FORM_WEBHOOK_ACTOR_USER_ID` として使う方法もある。監査ログ上「誰が入れたか」を人と区別したい場合はこちら。→ **推奨は本人id方式**（誰の打刻か自明・追加ユーザー不要）。

### 3.5 打刻ロジック（`WebhookAttendanceService::record`）

既存 `Controller::clock()`（`src/Controller.php:532` 付近）と同じ整合性ルールを踏襲する。

1. トランザクション開始
2. `SELECT ... FROM employees WHERE id = ? FOR UPDATE`（同一社員の同時実行を直列化）
3. 直近イベントを取得: `SELECT * FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 1 FOR UPDATE`
4. 期待イベント判定: 直近が無い or `clock_out` → 次は `clock_in` / 直近が `clock_in` → 次は `clock_out`
5. 送られてきた `event_type` が期待と不一致 → ロールバックして業務エラー(`out_of_order`)
6. （冪等）`response_id` を使う場合、既に同IDで記録済みなら成功扱いで二重INSERTしない（下記3.6）
7. `INSERT INTO attendance_events (employee_id, event_type, occurred_at, created_by, created_at) VALUES (?, ?, ?, ?, NOW())`
8. `Audit::log($eventType, 'attendance_event', $newId, null, ['occurred_at'=>..., 'source'=>'google_form'], $actorUserId)`
   （`Audit::log` は末尾で actor を明示指定できる。既存 `src/Controller.php:189` 参照）
9. 土日出勤の代休自動付与を既存同様に行うか判断（`clock()` は clock_in かつ土日なら `CompLeaveService::generateForHolidayWork` を呼ぶ）。**フォーム連携でも同じ挙動にするか要確認**（推奨: 揃える）
10. コミット

> **時刻の扱い**: DBは `SET time_zone='+09:00'`。Apps Scriptは必ず **JST(Asia/Tokyo)** で `occurred_at` を整形して送る。深夜跨ぎ(退勤が翌日)はフォーム注記どおり運用で担当報告 → 本連携は送信時刻をそのまま記録するだけとする。

### 3.6 冪等性（多重送信対策・任意だが推奨）

Apps Script はまれにリトライ・二重発火し得る。対策:

- フォーム回答ID(`response_id`)を保存する小テーブルを追加し、UNIQUE制約で二重を防ぐ。

```sql
-- database/migrations/<date>_add_form_webhook_dedupe.sql
CREATE TABLE form_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_id VARCHAR(64) NOT NULL UNIQUE,
  attendance_event_id BIGINT UNSIGNED NULL,
  received_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- 同一 `response_id` が既存なら INSERT せず `{ok:true, duplicate:true}` を返す。

### 3.7 エラーコード一覧（レスポンス `error` フィールド）

| HTTP | error | 意味 | Apps Script側の扱い |
|------|-------|------|--------------------|
| 401 | `unauthorized` | トークン不一致 | 設定ミス。管理者へ通知 |
| 400 | `invalid_json` / `invalid_payload` | 入力不正 | 管理者へ通知 |
| 422 | `unknown_employee` / `ambiguous_employee` | 氏名照合失敗 | 対応表の見直しを管理者へ通知 |
| 422 | `no_user_for_employee` | 社員に有効ユーザー無し | 管理者へ通知 |
| 422 | `out_of_order` | 順序違反（連続出勤等） | 本人へ「打刻順を確認」通知 |
| 200 | (duplicate) | 二重送信を吸収 | 正常扱い |
| 500 | `internal` | サーバ内部エラー | 再送 or 管理者通知 |

---

## 4. フォーム側（Google Apps Script）設計

### 4.1 トリガー

- フォームに紐づくスクリプトプロジェクトで **「フォーム送信時(onFormSubmit)」のインストール型トリガー** を登録。
- 送信されたレスポンスから「氏名」「出退勤」を取得し、送信時刻と共にアプリへ POST。

### 4.2 コード骨子（`Code.gs`）

```javascript
// スクリプトプロパティに WEBHOOK_URL, WEBHOOK_TOKEN, ADMIN_EMAIL を設定
const NAME_TO_CODE = {
  '関根郁民': 'E001',
  '白髭裕太': 'E002',
  '平田雄大': 'E012',
  // … 全社員分の対応表（氏名方式なら不要）
};

function onFormSubmit(e) {
  const props = PropertiesService.getScriptProperties();
  const url   = props.getProperty('WEBHOOK_URL');
  const token = props.getProperty('WEBHOOK_TOKEN');

  // 回答の取り出し（項目名で拾う）
  const answers = {};
  e.response.getItemResponses().forEach(ir => {
    answers[ir.getItem().getTitle()] = ir.getResponse();
  });
  const name  = answers['氏名'];
  const type  = answers['出退勤'] === '出勤' ? 'clock_in' : 'clock_out';

  // JSTで送信時刻を整形
  const ts = Utilities.formatDate(e.response.getTimestamp(),
                                  'Asia/Tokyo', 'yyyy-MM-dd HH:mm:ss');

  const payload = {
    name: name,
    employee_code: NAME_TO_CODE[name] || null,
    event_type: type,
    occurred_at: ts,
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
    // 失敗は管理者へ通知（打刻自体はフォームに残っているので後追い可能）
    MailApp.sendEmail(props.getProperty('ADMIN_EMAIL'),
      '【勤怠連携】打刻の自動反映に失敗',
      `氏名:${name} 区分:${type} 時刻:${ts}\n応答:${code}\n${res.getContentText()}`);
  }
}
```

### 4.3 失敗時の運用

- POST失敗・業務エラー時は **フォームの回答は残る** ので、管理者が後から手動で `admin/attendance` から補正できる（データ喪失はしない）。
- Apps Script のトリガーは自動リトライしないため、通知メールで気づける設計にしておく。

---

## 5. 設定・秘密情報

`.env`（アプリ）に追加:

```
FORM_WEBHOOK_TOKEN=<十分に長いランダム文字列>
# （システムユーザー方式を採るなら）FORM_WEBHOOK_ACTOR_USER_ID=<user id>
```

`.env.example` にもキーだけ追記。Apps Script側はスクリプトプロパティに `WEBHOOK_URL / WEBHOOK_TOKEN / ADMIN_EMAIL` を設定（コード直書きしない）。

---

## 6. セキュリティ考慮

- **必ず HTTPS**。トークンはヘッダ送信（URLクエリに入れない）。
- トークンは `hash_equals()` で比較（タイミング攻撃対策）。
- Webhookは **打刻の追加のみ** を行い、削除・更新・他テーブル操作はしない（最小権限）。
- `event_type` は `clock_in|clock_out` のホワイトリスト検証。`occurred_at` は形式・妥当な範囲(未来すぎ/過去すぎを拒否)を検証。
- レート/濫用対策として、`response_id` 冪等キー＋（任意で）1社員あたり短時間の連投を弾く。
- 監査ログに `source: google_form` を残し、フォーム由来の打刻を後から区別できるようにする。

---

## 7. テスト観点

1. 正常: 出勤→退勤の順で送信 → `attendance_events` に2件、`admin/attendance` に反映
2. 順序違反: 出勤→出勤 → `out_of_order` で2件目は記録されない
3. 氏名不一致: 対応表に無い氏名 → `unknown_employee`、管理者通知
4. トークン不正: 401、記録されない
5. 二重送信: 同 `response_id` 2回 → 1件だけ記録（`duplicate:true`）
6. 時刻: JSTで正しく記録される／深夜跨ぎの扱い確認
7. 土日出勤の代休自動付与を「揃える」場合、土曜の出勤送信で代休1件付与されるか
8. 既存のアプリ内打刻ボタンと混在しても順序判定が破綻しないか

`tests/` に PHP のユニット/結合テスト（例: `tests/webhook-attendance-test.php`）を追加し、既存テストと同様に静的チェック(`tests/static-check.mjs`)に載せる。

---

## 8. 実装チェックリスト（Bフェーズ確定後の作業単位）

- [ ] 論点1〜3・要確認事項の確定（社員番号方式 or 氏名方式、代休自動付与の有無）
- [ ] `employees.employee_code` の整備（社員番号方式の場合、全社員に付番）
- [ ] マイグレーション: `form_webhook_events`（冪等・任意）
- [ ] `src/WebhookAttendanceService.php`（照合・順序ガード・INSERT・監査）
- [ ] `src/WebhookException.php`（業務エラー型）
- [ ] `public/webhook.php`（トークン検証エントリ）
- [ ] サーバのリライト設定確認（webhook.php を除外）
- [ ] `.env` / `.env.example` に `FORM_WEBHOOK_TOKEN` 追加
- [ ] Apps Script `Code.gs` ＋ onFormSubmit トリガー登録＋スクリプトプロパティ設定
- [ ] `tests/webhook-attendance-test.php` ＋ 静的チェック登録
- [ ] ローカル(XAMPP)で疎通 → 本番(XServer)で疎通

---

## 付録: 関係する既存コード

- 打刻ロジックの原型: `src/Controller.php` `clock()`（§3.5の順序ガード・ロック・代休付与の参考）
- 監査ログの actor 明示指定: `Audit::log(..., $actorUserId)` の使用例 `src/Controller.php` `securityTotpConfirm()`
- 入口の CSRF/認証ゲート: `public/index.php`（webhook.php を別エントリにする理由）
- スキーマ: `database/schema.sql`（`employees` / `attendance_events` / `users`）

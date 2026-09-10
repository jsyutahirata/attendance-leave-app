# 社内勤怠・有給管理アプリ

約20名規模の社内利用を想定した、XServer向けの PHP / MySQL アプリです。スマートフォンではPWA、PCではブラウザから同じサーバーを利用します。

## 実装済み

- メール招待制ログイン、無効アカウント拒否、ログイン失敗ロック、アイドルタイムアウト
- メールによるパスワード再設定（本人または管理者から発行、有効期限60分、トークンはハッシュ保存、管理者発行時は対象者を全端末からログアウト）
- 社員本人の有給残数（前年繰越・今年付与の内訳）、予定、取得・取消履歴
- 1日・午前休・午後休の登録、重複・残数超過防止、理由付き取消
- 出勤・退勤打刻、状態に応じたボタン色、二重打刻防止、日跨ぎ退勤
- 遅刻・早退・欠勤等の勤怠連絡（有給種別は有給予定と同じデータを参照）
- 管理者による社員作成・有効化・無効化・再設定メール送信・全社員一括ログアウト、有給付与・残数調整・代理登録・取消、全社勤怠閲覧
- 重要操作の監査ログ（変更前後、操作者。接続元IPは取得・保存しない）
- 有給更新日の60日前から、失効予定の前年繰越日数を本人画面へ通知
- スマートフォン対応画面、CSRF・XSS・SQLインジェクション対策
- 30日間のログイン保持（一般社員のみ、トークンはハッシュ保存・利用時更新）
- アカウント状態のステータス管理（有効/無効/休職中）と、無効化・パスワード変更・強制ログアウト時の全端末セッション即時失効
- 所有者・閲覧権限照合の共通クラス（`src/Access.php`）
- 指定月の全社員出退勤打刻のCSVエクスポート（管理者専用・BOM付きUTF-8・一時ファイルを残さずストリーム・実行を監査ログに記録）
- 代休（休日出勤の勤怠連絡で自動発生、事業年度末＝翌3/31まで有効、取得予定の登録・取消、社員ホーム・管理者画面での残数/一覧表示、管理者による発生取消）
- 閲覧権限（管理者権限とは独立した「閲覧のみ」の権限。個人・グループ単位で付与、閲覧専用ページ、本人によるセルフサービス公開設定、管理者のグループ管理・権限一覧/付与/削除、所有者・閲覧権限照合を`src/Access.php`に集約、無効化時に関連権限・所属を整理）
- 二要素認証（TOTP。本人が任意で有効化＝QRコードでシークレット登録・バックアップコード10個発行、二段階ログイン、バックアップコードは単回使用、管理者による無効化リセット。TOTP検証は`spomky-labs/otphp`、QRは`bacon/bacon-qr-code`のSVG）
- 出退勤の月次集計（社員別の出勤日数・勤務時間。業務日＝出勤日で日跨ぎ退勤に対応、未退勤件数を表示。管理者の月次集計画面と社員本人の当月サマリー。ペアリングはCSV出力と共通の`src/AttendanceService.php`。休憩・残業の控除は未実装＝ルール未確定）
- PWAマニフェスト、ホーム画面アイコン、Service Worker、オフライン案内
- 有給承認フロー（管理者の承認・却下、管理画面からON/OFF。初期値ON、OFF時は承認待ちを通常登録へ移行）
- Web Push退勤忘れ通知（社員ごとの通知ON/OFF・通知時刻、端末ごとの購読ON/OFF、VAPID送信、期限切れ購読の掃除、同一勤務日の重複防止）

## 必要環境

- PHP 8.2 以上（PDO MySQL、mbstring、curl、JSON、OpenSSL）
- MySQL 5.7 以上または MariaDB 10.4 以上
- Apache（`mod_rewrite` が利用可能なこと）
- HTTPS
- Composer 依存（TOTP: `spomky-labs/otphp`、QR: `bacon/bacon-qr-code`）。QRはSVG生成のためGD拡張は不要。

## セットアップ

1. `.env.example` を `.env` にコピーし、URL、DB接続情報、メール送信元を設定します。
2. 依存パッケージを用意します。XServerにSSHでComposerが使える場合は公開領域外で `composer install --no-dev` を実行します。使えない場合は、ローカルで `composer install --no-dev` した `vendor/` ディレクトリをそのままアップロードします（`vendor/` は `public` の外に置くこと）。
3. MySQLに空のデータベースと専用ユーザーを作成します。
4. `database/schema.sql` を対象データベースへインポートします。

   新規構築では `schema.sql` に全テーブルが含まれるため、これだけで完了します。既存の稼働中データベースを更新する場合のみ、`database/migrations/` 内の未適用マイグレーションをファイル名順に適用してください。`20260909_remove_audit_ip_address.sql` は既存の操作履歴からIP列と保存済みIPを削除するため、適用前にバックアップ方針を確認してください。
5. Webサーバーのドキュメントルートを `public` ディレクトリへ設定します。
6. 最初の管理者をCLIで作成します。

```bash
php scripts/create-admin.php admin@example.co.jp "管理 太郎" "12文字以上の安全な初期パスワード" ADM001
```

7. `.env`、`src`、`database`、`scripts`、`vendor` がWebから直接公開されていないこと、HTTPSへの転送、XServer WAF、バックアップを確認します。

8. Web Push用のVAPIDキーを生成し、出力された2行を`.env`へ設定します。秘密鍵は公開領域へ置かず、運用開始後は同じキーを維持してください。

```bash
php scripts/generate-vapid-keys.php
```

9. XServerのCronでメンテナンスを毎日深夜、退勤忘れ通知を5分おきに実行します（PHPと配置先は実環境のパスへ変更）。社員本人がセキュリティ設定画面で通知のON/OFFと時刻を設定できます。`.env`の`PUSH_REMINDER_TIME`（既定18:00）は未設定社員の初期値です。同一日に一度だけ通知し、退勤済み・有給・通知購読なしの社員は対象外です。

```bash
/usr/bin/php /home/account/app/scripts/maintenance.php
*/5 * * * * /usr/bin/php /home/account/app/scripts/push-reminders.php
10 2 * * * /usr/bin/php /home/account/app/scripts/accrue-leave.php
```

## ドキュメント

- [利用説明書](docs/利用説明書.md)：社員・管理者向けの画面操作
- [引継ぎ書](docs/引継ぎ書.md)：構成、リリース、定期処理、障害・セキュリティ対応

## ローカル開発（Windows / XAMPP）

手元Windowsでの動作確認は、XAMPP同梱のPHPとMariaDBを使い、Apacheを立てずにPHPビルトインサーバーで動かせます（ルーティングは `index.php?route=` 直リンクのため mod_rewrite 不要）。

1. XAMPP（PHP 8.1以上同梱）を `C:\xampp` に導入する。
2. MariaDBを起動し、DBとユーザーを作成、`database/schema.sql` をインポートする。

```powershell
& 'C:\xampp\mysql\bin\mysqld.exe' --defaults-file='C:\xampp\mysql\bin\my.ini'
# 別ウィンドウで
'CREATE DATABASE IF NOT EXISTS attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 CREATE USER IF NOT EXISTS ''attendance_user''@''localhost'' IDENTIFIED BY ''attendance_pass'';
 GRANT ALL PRIVILEGES ON attendance.* TO ''attendance_user''@''localhost''; FLUSH PRIVILEGES;' | & 'C:\xampp\mysql\bin\mysql.exe' -u root
Get-Content -Raw -Encoding UTF8 database\schema.sql | & 'C:\xampp\mysql\bin\mysql.exe' -u root attendance
```

3. `.env` を作成し、`APP_ENV=local`（HTTPS強制とSecure Cookieを無効化）、`DB_*` をローカル値にする。
4. 管理者を作成し、サーバーを起動する。以降の起動/停止は補助スクリプトで行える。

```powershell
& 'C:\xampp\php\php.exe' scripts\create-admin.php admin@example.co.jp "Admin" "LocalAdmin1234" ADM001
powershell -ExecutionPolicy Bypass -File scripts\dev-local.ps1 start   # 起動（http://localhost:8000）
powershell -ExecutionPolicy Bypass -File scripts\dev-local.ps1 stop    # 停止
powershell -ExecutionPolicy Bypass -File scripts\dev-local.ps1 status  # 状態確認
```

ローカルではメール送信（`mail()`）は動作しないため、招待・再設定メールのリンクはDBのトークンを直接使うか、パスワードを直接設定して検証する。

Web Pushの実配信をWindowsローカルで試す場合、`push-reminders.php`実行前に`OPENSSL_CONF`を設定する必要がある（未設定だとライブラリのEC鍵生成が「Unable to create the local key」で失敗する。Linux/XServerでは既定設定があるため不要）。

```powershell
$env:OPENSSL_CONF = 'C:\xampp\php\extras\ssl\openssl.cnf'; & 'C:\xampp\php\php.exe' scripts\push-reminders.php
```

## XServerでの配置

サブドメインの公開ディレクトリをこのリポジトリの `public` に向ける構成を推奨します。公開ディレクトリを変更できない契約・設定の場合は、`public` の中身だけを公開側に置き、`index.php` の `require` パスを公開領域外に置いた `src/bootstrap.php` へ合わせて変更してください。`.env` を公開ディレクトリへ置かないでください。

本番のPHP設定では `display_errors=Off`、`log_errors=On` とし、セッションCookieのSecure属性が付くHTTPS環境で運用してください。

## 残数計算

過去の取得は「今年付与分を先、前年繰越分を後」の順で割り当てます。本日時点で未失効の付与残 + 管理者調整を現在残数とし、「前年繰越」と「今年付与」に分けて表示します。未使用分を保持できるのは付与年度の翌年末までで、それ以前の有効期限が設定されている場合は早い方を採用します。予定反映後の内訳も今年付与分から先に減算します。

## 未実装（後続フェーズ）

- 休憩・残業・遅刻早退の自動計算、締め処理・月次確定
- 会社固有の休憩・残業・繰越規則
- 業務イベントのメール通知（実装しない方針。パスワード再設定・初回招待の認証メールのみ使用）
- Googleフォーム／スプレッドシートからの移行ツール

## 対象外（実装しない方針）

- 打刻修正申請
- シフト・所定労働時間管理
- 経費精算・領収書添付（会社適用可否が決まるまで保留）

## ディレクトリ

```text
public/             Web公開ディレクトリ
src/                アプリケーション、認証、業務ロジック
views/              HTMLテンプレート
database/schema.sql 初期DBスキーマ
scripts/            CLI管理ツール
storage/logs/        非公開ログ配置用
```

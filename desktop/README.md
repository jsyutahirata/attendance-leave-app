# Electronクライアント

ブラウザ版と同じ勤怠管理URLをPC専用ウィンドウで開く薄いクライアントです。認証、業務ロジック、勤怠データはElectron内に持ちません。

## 開発起動

1. `npm install`
2. 必要に応じて環境変数`ATTENDANCE_APP_URL`を設定
3. `npm start`

ローカルの既定URLは`http://127.0.0.1:8765/index.php`です。本番配布時は必ずHTTPSの本番URLを指定します。

## Windowsパッケージ

`npm run package:win`でNSISインストーラーを生成します。正式配布前にアプリID、会社名、コード署名証明書、自動更新方式を確定してください。

# Electronクライアント

ブラウザ版と同じ勤怠管理URLをPC専用ウィンドウで開く薄いクライアントです。認証、業務ロジック、勤怠データはElectron内に持ちません。

## 開発起動

1. `npm install`
2. 必要に応じて環境変数`ATTENDANCE_APP_URL`を設定
3. `npm start`

ローカルの既定URLは`http://127.0.0.1:8765/index.php`です。本番配布時は必ずHTTPSの本番URLを指定します。

## Windowsパッケージ

PowerShellで本番URLを指定してからNSISインストーラーを生成します。URLは生成物内の`app-config.json`へ埋め込まれ、実行PCで環境変数を設定する必要はありません。

```powershell
$env:ATTENDANCE_APP_URL='https://attendance.example.co.jp/index.php'
npm run package:win
```

Electronで標準Web Pushが利用できない場合は、ログイン中にサーバーを定期確認してOSネイティブ通知を表示します。ウィンドウを閉じても通知領域に常駐し、トレイメニューの「終了」で完全終了します。業務データは端末へ保存しません。正式配布前に`appId`、会社名、コード署名証明書、自動更新方式を確定してください。

<?php
declare(strict_types=1);

// スタッフ名簿（在籍者）を事前登録する使い切りスクリプト。
// グレーアウト（退職等）・空行・共有アドレス(営業)は名簿に含めていない＝除外済み。
// 既存社員（氏名一致）はスキップし、メールが空の場合のみ補完する。新規は招待メールなし・status='disabled' で作成。
//
//   php scripts/provision-staff.php            … ドライラン（作成/補完/スキップの一覧）
//   php scripts/provision-staff.php --confirm  … 実行

use App\Database;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$confirm = in_array('--confirm', $argv, true);

// [社員番号, 氏名, メール]（在籍のみ）
$roster = [
    ['002', '谷田部 彰太', 'yatabe.shota@j-style-tokyo.com'],
    ['004', '白髭 裕太', 'shirahige.yuta@j-style-tokyo.com'],
    ['005', '北川 和貴', 'kitagawa.kazuki@j-style-tokyo.com'],
    ['008', '高橋 祐希', 'takahashi.yuuki@j-style-tokyo.com'],
    ['009', '児玉 優斗', 'kodama.yuto@j-style-tokyo.com'],
    ['012', '稲葉しずく', 'inaba.shizuku@j-style-tokyo.com'],
    ['014', '関根 郁民', 'sekine.ikuto@j-style-tokyo.com'],
    ['017', '橋澤 光南', 'hashizawa.mitsuna@j-style-tokyo.com'],
    ['019', '伊藤 陽梧', 'ito.yogo@j-style-tokyo.com'],
    ['020', '鈴木 尚人', 'suzuki.naoto@j-style-tokyo.com'],
    ['021', '岡本 夏実', 'okamoto.natsumi@j-style-tokyo.com'],
    ['023', '奥平 昌希', 'okudaira.masaki@j-style-tokyo.com'],
    ['025', '板橋 旺大', 'itabashi.ota@j-style-tokyo.com'],
    ['026', '石井 海翔', 'isiikaito.kaito@j-style-tokyo.com'],
    ['027', '木村 優衣', 'kimura.yui@j-style-tokyo.com'],
    ['030', '三輪 侑里', 'miwa.yuri@j-style-tokyo.com'],
    ['032', '平田 雄大', 'hirata.yuta@j-style-tokyo.com'],
];

$norm = static fn(string $s): string => mb_strtolower(preg_replace('/[\s　]+/u', '', trim($s)) ?? trim($s));
$pdo = Database::connection();

// 既存社員（氏名正規化→id, email）
$existing = [];
foreach ($pdo->query('SELECT e.id, e.full_name, u.id AS user_id, u.email FROM employees e JOIN users u ON u.employee_id = e.id')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $existing[$norm((string)$row['full_name'])] = $row;
}
// メール重複チェック用（主・サブ）
$emailInUse = static function (string $email) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? OR secondary_email = ?');
    $s->execute([$email, $email]);
    return (int)$s->fetchColumn() > 0;
};

$toCreate = []; $toFill = []; $skip = [];
foreach ($roster as [$code, $name, $email]) {
    $email = mb_strtolower(trim($email));
    $hit = $existing[$norm($name)] ?? null;
    if ($hit) {
        if ((string)$hit['email'] === '' || $hit['email'] === null) {
            $toFill[] = [$hit, $name, $email];
        } else {
            $skip[] = [$name, '既存（メール設定済み）'];
        }
        continue;
    }
    if ($emailInUse($email)) { $skip[] = [$name, 'メール重複のためスキップ']; continue; }
    $toCreate[] = [$code, $name, $email];
}

echo "=== " . ($confirm ? '実行' : 'ドライラン') . " ===\n";
echo "-- 新規作成（" . count($toCreate) . "名・status=disabled・招待なし）--\n";
foreach ($toCreate as [$code, $name, $email]) echo "  CREATE  {$name}  <{$email}>  (社員番号 {$code})\n";
echo "-- 既存でメール補完（" . count($toFill) . "名）--\n";
foreach ($toFill as [$hit, $name, $email]) echo "  FILL    {$name}  <{$email}>\n";
echo "-- スキップ（" . count($skip) . "名）--\n";
foreach ($skip as [$name, $why]) echo "  SKIP    {$name}  … {$why}\n";

if (!$confirm) { echo "\nドライランです。実行するには --confirm を付けてください。\n"; exit(0); }

$pdo->beginTransaction();
try {
    $insEmp = $pdo->prepare('INSERT INTO employees (employee_code, full_name, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
    $insUser = $pdo->prepare("INSERT INTO users (employee_id, email, password_hash, role, status, session_token, failed_login_attempts, created_at, updated_at) VALUES (?, ?, ?, 'employee', 'disabled', ?, 0, NOW(), NOW())");
    foreach ($toCreate as [$code, $name, $email]) {
        // 社員番号が既に使われていれば衝突回避のため NULL で作る
        $chk = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE employee_code = ?'); $chk->execute([$code]);
        $codeVal = (int)$chk->fetchColumn() > 0 ? null : $code;
        $insEmp->execute([$codeVal, $name]);
        $empId = (int)$pdo->lastInsertId();
        $insUser->execute([$empId, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), bin2hex(random_bytes(16))]);
        \App\Audit::log('account_created', 'user', (int)$pdo->lastInsertId(), null, ['email' => $email, 'employee_id' => $empId, 'invite_sent' => false, 'source' => 'staff_provision'], null);
    }
    $fill = $pdo->prepare('UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?');
    foreach ($toFill as [$hit, $name, $email]) {
        if ($emailInUse($email)) continue;
        $fill->execute([$email, (int)$hit['user_id']]);
    }
    $pdo->commit();
    echo "\n完了しました（作成 " . count($toCreate) . "名 / 補完 " . count($toFill) . "名）。\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "失敗しました。ロールバックしました: " . $e->getMessage() . "\n");
    exit(1);
}

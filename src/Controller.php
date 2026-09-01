<?php
declare(strict_types=1);

namespace App;

use DomainException;
use PDO;

final class Controller
{
    public function dispatch(string $route, string $method): void
    {
        $routes = [
            'GET' => [
                '' => 'dashboard', 'login' => 'loginForm',
                'forgot-password' => 'forgotForm', 'reset-password' => 'resetForm',
                'leave' => 'leavePage', 'notice' => 'noticePage', 'attendance' => 'attendancePage',
                'admin' => 'adminPage', 'admin/users' => 'adminUsers', 'admin/leave' => 'adminLeave', 'admin/attendance' => 'adminAttendance', 'admin/audit' => 'adminAudit',
            ],
            'POST' => [
                'login' => 'login', 'logout' => 'logout', 'forgot-password' => 'forgot', 'reset-password' => 'reset',
                'leave/create' => 'leaveCreate', 'leave/cancel' => 'leaveCancel',
                'notice/create' => 'noticeCreate', 'attendance/clock' => 'clock',
                'admin/users/create' => 'adminUserCreate', 'admin/users/toggle' => 'adminUserToggle', 'admin/users/force-logout' => 'adminUserForceLogout',
                'admin/leave/create' => 'adminLeaveCreate', 'admin/leave/grant' => 'adminGrant', 'admin/leave/adjust' => 'adminAdjust', 'admin/leave/cancel' => 'adminLeaveCancel',
            ],
        ];
        $handler = $routes[$method][$route] ?? null;
        if (!$handler) {
            http_response_code(404);
            render('error', ['title' => 'ページが見つかりません', 'message' => '指定されたページはありません。']);
            return;
        }
        $this->{$handler}();
    }

    private function loginForm(): void
    {
        if (Auth::user()) redirect();
        render('login', ['title' => 'ログイン']);
    }

    private function login(): void
    {
        if (Auth::attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), isset($_POST['remember']))) {
            redirect();
        }
        flash('error', 'メールアドレスまたはパスワードが正しくないか、一時的にロックされています。');
        redirect('login');
    }

    private function logout(): void
    {
        Auth::logout();
        redirect('login');
    }

    private function forgotForm(): void
    {
        render('forgot', ['title' => 'パスワード再設定']);
    }

    private function forgot(): void
    {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();
        if ($userId) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())')->execute([$userId, $hash]);
            $link = rtrim((string)config('APP_URL'), '/') . url('reset-password') . '&token=' . urlencode($token);
            $subject = 'パスワード再設定';
            $body = "以下のURLから60分以内にパスワードを再設定してください。\n\n" . $link;
            $headers = 'From: ' . (string)config('MAIL_FROM_NAME', '勤怠管理') . ' <' . (string)config('MAIL_FROM', 'no-reply@localhost') . '>';
            mail($email, mb_encode_mimeheader($subject), $body, $headers);
            Audit::log('password_reset_requested', 'user', (int)$userId, null, null, (int)$userId);
        }
        flash('success', '登録済みのアドレスの場合、再設定メールを送信しました。');
        redirect('forgot-password');
    }

    private function resetForm(): void
    {
        render('reset', ['title' => '新しいパスワード', 'token' => (string)($_GET['token'] ?? '')]);
    }

    private function reset(): void
    {
        $token = (string)($_POST['token'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (mb_strlen($password) < 12) {
            flash('error', 'パスワードは12文字以上で入力してください。');
            header('Location: ' . url('reset-password') . '&token=' . urlencode($token)); exit;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM password_reset_tokens WHERE token_hash = ? AND expires_at >= NOW() AND used_at IS NULL LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row) {
            flash('error', '再設定URLが無効または期限切れです。'); redirect('forgot-password');
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        // パスワード変更時は当該ユーザーの全端末セッション・長期トークンを一括失効させる（§5）。
        Auth::revokeAllSessions((int)$row['user_id']);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        Audit::log('password_reset_completed', 'user', (int)$row['user_id'], null, null, (int)$row['user_id']);
        $pdo->commit();
        flash('success', 'パスワードを更新しました。'); redirect('login');
    }

    private function dashboard(): void
    {
        $pdo = Database::connection();
        $employeeId = (int)Auth::employeeId();
        $summary = LeaveService::summary($employeeId);
        $stmt = $pdo->prepare("SELECT * FROM leave_entries WHERE employee_id = ? AND status <> 'cancelled' AND leave_date >= CURDATE() ORDER BY leave_date LIMIT 5");
        $stmt->execute([$employeeId]);
        $upcoming = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC LIMIT 1');
        $stmt->execute([$employeeId]);
        $lastEvent = $stmt->fetch() ?: null;
        $stmt = $pdo->prepare('SELECT * FROM attendance_notices WHERE employee_id = ? ORDER BY target_date DESC, id DESC LIMIT 5');
        $stmt->execute([$employeeId]);
        render('dashboard', compact('summary', 'upcoming', 'lastEvent') + ['notices' => $stmt->fetchAll(), 'title' => 'ホーム']);
    }

    private function leavePage(): void
    {
        $employeeId = (int)Auth::employeeId();
        $stmt = Database::connection()->prepare('SELECT le.*, u.email AS cancelled_by_email FROM leave_entries le LEFT JOIN users u ON u.id = le.cancelled_by WHERE le.employee_id = ? ORDER BY leave_date DESC, id DESC');
        $stmt->execute([$employeeId]);
        render('leave', ['title' => '有給管理', 'summary' => LeaveService::summary($employeeId), 'entries' => $stmt->fetchAll()]);
    }

    private function leaveCreate(): void
    {
        try {
            LeaveService::create((int)Auth::employeeId(), $_POST);
            flash('success', '有給予定を登録しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('leave');
    }

    private function leaveCancel(): void
    {
        try {
            LeaveService::cancel((int)($_POST['entry_id'] ?? 0), (string)($_POST['reason'] ?? ''));
            flash('success', '有給予定を取り消しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('leave');
    }

    private function noticePage(): void
    {
        $stmt = Database::connection()->prepare('SELECT * FROM attendance_notices WHERE employee_id = ? ORDER BY target_date DESC, id DESC');
        $stmt->execute([Auth::employeeId()]);
        render('notice', ['title' => '勤怠連絡', 'notices' => $stmt->fetchAll()]);
    }

    private function noticeCreate(): void
    {
        $types = ['late', 'early', 'leave_full', 'leave_am', 'leave_pm', 'absence', 'holiday_work', 'medical', 'other'];
        $type = (string)($_POST['notice_type'] ?? '');
        $date = (string)($_POST['target_date'] ?? '');
        if (!in_array($type, $types, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash('error', '対象日と勤怠種別を正しく入力してください。'); redirect('notice');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $leaveEntryId = null;
            $leaveMap = ['leave_full' => 'full', 'leave_am' => 'am', 'leave_pm' => 'pm'];
            if (isset($leaveMap[$type])) {
                $leaveEntryId = LeaveService::create((int)Auth::employeeId(), ['leave_date' => $date, 'leave_type' => $leaveMap[$type], 'note' => $_POST['details'] ?? '', 'confirmed_with' => $_POST['confirmed_with'] ?? '']);
            }
            $stmt = $pdo->prepare('INSERT INTO attendance_notices (employee_id, target_date, notice_type, expected_start, expected_end, details, leave_entry_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([Auth::employeeId(), $date, $type, $_POST['expected_start'] ?: null, $_POST['expected_end'] ?: null, trim((string)($_POST['details'] ?? '')), $leaveEntryId, Auth::id()]);
            Audit::log('attendance_notice_created', 'attendance_notice', (int)$pdo->lastInsertId(), null, ['type' => $type, 'date' => $date]);
            $pdo->commit(); flash('success', '勤怠連絡を登録しました。');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $e instanceof DomainException ? $e->getMessage() : '勤怠連絡を登録できませんでした。');
        }
        redirect('notice');
    }

    private function attendancePage(): void
    {
        $stmt = Database::connection()->prepare('SELECT * FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC LIMIT 100');
        $stmt->execute([Auth::employeeId()]);
        $events = $stmt->fetchAll();
        render('attendance', ['title' => '出退勤', 'events' => $events, 'lastEvent' => $events[0] ?? null]);
    }

    private function clock(): void
    {
        $eventType = (string)($_POST['event_type'] ?? '');
        if (!in_array($eventType, ['clock_in', 'clock_out'], true)) { flash('error', '不正な操作です。'); redirect('attendance'); }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([Auth::employeeId()]);
        $last = $stmt->fetch();
        $expected = (!$last || $last['event_type'] === 'clock_out') ? 'clock_in' : 'clock_out';
        if ($eventType !== $expected) {
            $pdo->rollBack(); flash('error', $eventType === 'clock_in' ? 'すでに出勤中です。' : '先に出勤打刻が必要です。'); redirect('attendance');
        }
        $stmt = $pdo->prepare('INSERT INTO attendance_events (employee_id, event_type, occurred_at, created_by, created_at) VALUES (?, ?, NOW(), ?, NOW())');
        $stmt->execute([Auth::employeeId(), $eventType, Auth::id()]);
        Audit::log($eventType, 'attendance_event', (int)$pdo->lastInsertId(), null, ['occurred_at' => date('Y-m-d H:i:s')]);
        $pdo->commit(); flash('success', $eventType === 'clock_in' ? '出勤を記録しました。' : '退勤を記録しました。'); redirect('attendance');
    }

    private function adminPage(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $stats = [
            'employees' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(),
            'working' => (int)$pdo->query("SELECT COUNT(*) FROM attendance_events ae WHERE ae.event_type = 'clock_in' AND ae.id IN (SELECT MAX(id) FROM attendance_events GROUP BY employee_id)")->fetchColumn(),
            'future_leave' => (int)$pdo->query("SELECT COUNT(*) FROM leave_entries WHERE status <> 'cancelled' AND leave_date >= CURDATE()")->fetchColumn(),
        ];
        render('admin/index', ['title' => '管理', 'stats' => $stats]);
    }

    private function adminUsers(): void
    {
        Auth::requireAdmin();
        $users = Database::connection()->query('SELECT u.*, e.full_name, e.employee_code, e.hired_on FROM users u JOIN employees e ON e.id = u.employee_id ORDER BY e.full_name')->fetchAll();
        render('admin/users', ['title' => '社員管理', 'users' => $users]);
    }

    private function adminUserCreate(): void
    {
        Auth::requireAdmin();
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $name = trim((string)($_POST['full_name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            flash('error', '氏名と正しいメールアドレスを入力してください。'); redirect('admin/users');
        }
        $temporaryPassword = bin2hex(random_bytes(32));
        $pdo = Database::connection(); $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO employees (employee_code, full_name, hired_on, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
            $stmt->execute([trim((string)($_POST['employee_code'] ?? '')) ?: null, $name, ($_POST['hired_on'] ?? '') ?: null]);
            $employeeId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO users (employee_id, email, password_hash, role, status, session_token, failed_login_attempts, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', ?, 0, NOW(), NOW())");
            $stmt->execute([$employeeId, $email, password_hash($temporaryPassword, PASSWORD_DEFAULT), ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'employee', bin2hex(random_bytes(16))]);
            $userId = (int)$pdo->lastInsertId();
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())')->execute([$userId, hash('sha256', $token)]);
            Audit::log('account_created', 'user', $userId, null, ['email' => $email, 'employee_id' => $employeeId]);
            $pdo->commit();
            $link = rtrim((string)config('APP_URL'), '/') . url('reset-password') . '&token=' . urlencode($token);
            $subject = '社内勤怠管理アカウントのご案内';
            $body = "アカウントが作成されました。以下のURLから24時間以内にパスワードを設定してください。\n\n" . $link;
            $headers = 'From: ' . (string)config('MAIL_FROM_NAME', '勤怠管理') . ' <' . (string)config('MAIL_FROM', 'no-reply@localhost') . '>';
            $sent = mail($email, mb_encode_mimeheader($subject), $body, $headers);
            flash($sent ? 'success' : 'error', $sent ? '社員アカウントを作成し、招待メールを送信しました。' : 'アカウントは作成しましたが、招待メールを送信できませんでした。本人にパスワード再設定を試してもらってください。');
        } catch (\Throwable $e) {
            $pdo->rollBack(); flash('error', 'アカウントを作成できませんでした。メールアドレスや社員番号の重複を確認してください。');
        }
        redirect('admin/users');
    }

    private function adminUserToggle(): void
    {
        Auth::requireAdmin();
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === Auth::id()) { flash('error', '自分自身は無効化できません。'); redirect('admin/users'); }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, status FROM users WHERE id = ?'); $stmt->execute([$id]); $user = $stmt->fetch();
        if ($user) {
            $enable = $user['status'] !== 'active';
            $newStatus = $enable ? 'active' : 'disabled';
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus, $id]);
                if (!$enable) {
                    // 無効化は即時反映。全端末セッション・長期トークンを失効し、未使用の再設定トークンも無効化する（§4.2/§14）。
                    Auth::revokeAllSessions($id);
                    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$id]);
                }
                Audit::log($enable ? 'account_enabled' : 'account_disabled', 'user', $id, ['status' => $user['status']], ['status' => $newStatus]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            flash('success', $enable ? 'アカウントを有効化しました。' : 'アカウントを無効化し、全端末のログインを即時失効しました。');
        }
        redirect('admin/users');
    }

    private function adminUserForceLogout(): void
    {
        Auth::requireAdmin();
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === Auth::id()) { flash('error', '自分自身は「ログアウト」から実行してください。'); redirect('admin/users'); }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?'); $stmt->execute([$id]); $target = $stmt->fetch();
        if (!$target) { flash('error', '対象の社員が見つかりません。'); redirect('admin/users'); }
        $pdo->beginTransaction();
        try {
            // 端末紛失時対応（§5）。対象社員の全端末セッション・長期トークンを一括失効させる。
            Auth::revokeAllSessions($id);
            Audit::log('force_logout', 'user', $id, null, null);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', '対象社員の全端末を強制ログアウトしました。');
        redirect('admin/users');
    }

    private function adminLeave(): void
    {
        Auth::requireAdmin(); $pdo = Database::connection();
        $employees = $pdo->query('SELECT e.id, e.full_name, e.employee_code FROM employees e JOIN users u ON u.employee_id=e.id ORDER BY e.full_name')->fetchAll();
        $entries = $pdo->query('SELECT le.*, e.full_name FROM leave_entries le JOIN employees e ON e.id=le.employee_id ORDER BY le.leave_date DESC, le.id DESC LIMIT 300')->fetchAll();
        foreach ($employees as &$employee) $employee['summary'] = LeaveService::summary((int)$employee['id']);
        render('admin/leave', ['title' => '有給管理（管理者）', 'employees' => $employees, 'entries' => $entries]);
    }

    private function adminGrant(): void
    {
        Auth::requireAdmin(); $days = (float)($_POST['days'] ?? 0);
        $grantedOn = (string)($_POST['granted_on'] ?? ''); $expiresOn = (string)($_POST['expires_on'] ?? ''); $reason = trim((string)($_POST['reason'] ?? ''));
        $grantYear = (int)($_POST['grant_year'] ?? date('Y'));
        $carryoverLimit = ($grantYear + 1) . '-12-31';
        if ($days <= 0 || $grantedOn === '' || $expiresOn === '' || $expiresOn < $grantedOn || $expiresOn > $carryoverLimit || $reason === '' || $grantYear < 2000 || $grantYear > (int)date('Y') + 1) { flash('error', '付与内容を正しく入力してください。有効期限は付与年度の翌年末までです。'); redirect('admin/leave'); }
        $pdo = Database::connection();
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO leave_grants (employee_id, granted_on, grant_year, days, expires_on, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$employeeId, $grantedOn, $grantYear, $days, $expiresOn, $reason, Auth::id()]);
            $grantId = (int)$pdo->lastInsertId();
            Audit::log('leave_granted', 'leave_grant', $grantId, null, ['employee_id' => $employeeId, 'granted_on' => $grantedOn, 'grant_year' => $grantYear, 'days' => $days, 'expires_on' => $expiresOn, 'reason' => $reason]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', '有給を付与しました。'); redirect('admin/leave');
    }

    private function adminLeaveCreate(): void
    {
        Auth::requireAdmin();
        try {
            LeaveService::create((int)($_POST['employee_id'] ?? 0), $_POST, Auth::id());
            flash('success', '社員に代わって有給予定を登録しました。');
        } catch (DomainException $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin/leave');
    }

    private function adminAdjust(): void
    {
        Auth::requireAdmin(); $days = (float)($_POST['days_delta'] ?? 0); $reason = trim((string)($_POST['reason'] ?? ''));
        $grantYear = (int)($_POST['grant_year'] ?? date('Y'));
        if ($days == 0.0 || $reason === '' || $grantYear < 2000 || $grantYear > (int)date('Y') + 1) { flash('error', '0以外の調整日数、対象年度、理由を入力してください。'); redirect('admin/leave'); }
        $pdo = Database::connection();
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO leave_adjustments (employee_id, grant_year, days_delta, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$employeeId, $grantYear, $days, $reason, Auth::id()]);
            $adjustmentId = (int)$pdo->lastInsertId();
            Audit::log('leave_adjusted', 'leave_adjustment', $adjustmentId, null, ['employee_id' => $employeeId, 'grant_year' => $grantYear, 'days_delta' => $days, 'reason' => $reason]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', '残数を調整しました。'); redirect('admin/leave');
    }

    private function adminLeaveCancel(): void
    {
        Auth::requireAdmin();
        try { LeaveService::cancel((int)($_POST['entry_id'] ?? 0), (string)($_POST['reason'] ?? ''), true); flash('success', '有給を管理者取消しました。'); }
        catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('admin/leave');
    }

    private function adminAudit(): void
    {
        Auth::requireAdmin();
        $logs = Database::connection()->query('SELECT a.*, u.email AS actor_email FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.id DESC LIMIT 500')->fetchAll();
        render('admin/audit', ['title' => '操作履歴', 'logs' => $logs]);
    }

    private function adminAttendance(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $events = $pdo->query('SELECT ae.*, e.full_name FROM attendance_events ae JOIN employees e ON e.id=ae.employee_id ORDER BY ae.occurred_at DESC LIMIT 500')->fetchAll();
        $notices = $pdo->query('SELECT an.*, e.full_name FROM attendance_notices an JOIN employees e ON e.id=an.employee_id ORDER BY an.target_date DESC, an.id DESC LIMIT 500')->fetchAll();
        render('admin/attendance', ['title' => '全社勤怠', 'events' => $events, 'notices' => $notices]);
    }
}

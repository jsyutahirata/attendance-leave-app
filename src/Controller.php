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
                '' => 'dashboard', 'login' => 'loginForm', 'login/totp' => 'totpForm',
                'forgot-password' => 'forgotForm', 'reset-password' => 'resetForm',
                'security' => 'securityPage',
                'leave' => 'leavePage', 'notice' => 'noticePage', 'attendance' => 'attendancePage',
                'viewable' => 'viewablePage', 'viewable/show' => 'viewableShow', 'sharing' => 'sharingPage',
                'contact' => 'contactPage',
                'admin' => 'adminPage', 'admin/users' => 'adminUsers', 'admin/leave' => 'adminLeave', 'admin/leave/import/template' => 'adminLeaveImportTemplate', 'admin/calendar' => 'adminCalendar', 'admin/attendance' => 'adminAttendance', 'admin/attendance/monthly' => 'adminAttendanceMonthly', 'admin/audit' => 'adminAudit', 'admin/access' => 'adminAccess',
            ],
            'POST' => [
                'login' => 'login', 'login/totp' => 'totpVerify', 'logout' => 'logout', 'forgot-password' => 'forgot', 'reset-password' => 'reset',
                'security/totp/init' => 'securityTotpInit', 'security/totp/confirm' => 'securityTotpConfirm', 'security/totp/disable' => 'securityTotpDisable',
                'push/subscribe' => 'pushSubscribe', 'push/unsubscribe' => 'pushUnsubscribe', 'push/preferences' => 'pushPreferences',
                'admin/users/totp-disable' => 'adminUserTotpDisable',
                'leave/create' => 'leaveCreate', 'leave/cancel' => 'leaveCancel',
                'comp-leave/create' => 'compLeaveCreate', 'comp-leave/cancel' => 'compLeaveCancel',
                'calendar/personal/create' => 'personalCalendarCreate',
                'notice/create' => 'noticeCreate', 'attendance/clock' => 'clock',
                'contact' => 'contactSubmit',
                'admin/attendance/export' => 'adminAttendanceExport',
                'admin/comp-leave/grant-cancel' => 'adminCompGrantCancel',
                'sharing/add' => 'sharingAdd', 'sharing/remove' => 'sharingRemove',
                'admin/groups/create' => 'adminGroupCreate', 'admin/groups/add-member' => 'adminGroupAddMember', 'admin/groups/remove-member' => 'adminGroupRemoveMember', 'admin/groups/delete' => 'adminGroupDelete',
                'admin/view-grants/create' => 'adminViewGrantCreate', 'admin/view-grants/all/create' => 'adminAllViewGrantCreate', 'admin/view-grants/delete' => 'adminViewGrantDelete',
                'admin/users/create' => 'adminUserCreate', 'admin/users/update' => 'adminUserUpdate', 'admin/users/toggle' => 'adminUserToggle', 'admin/users/force-logout' => 'adminUserForceLogout',
                'admin/users/password-reset' => 'adminUserPasswordReset', 'admin/users/logout-all' => 'adminUsersLogoutAll',
                'admin/leave/create' => 'adminLeaveCreate', 'admin/leave/grant' => 'adminGrant', 'admin/leave/adjust' => 'adminAdjust', 'admin/leave/cancel' => 'adminLeaveCancel', 'admin/leave/review' => 'adminLeaveReview',
                'admin/leave/import/preview' => 'adminLeaveImportPreview', 'admin/leave/import/confirm' => 'adminLeaveImportConfirm', 'admin/leave/import/cancel' => 'adminLeaveImportCancel',
                'admin/calendar/save' => 'adminCalendarSave', 'admin/calendar/delete' => 'adminCalendarDelete',
                'admin/calendar/import/preview' => 'adminCalendarImportPreview', 'admin/calendar/import/confirm' => 'adminCalendarImportConfirm', 'admin/calendar/import/cancel' => 'adminCalendarImportCancel',
                'admin/settings/approval' => 'adminApprovalSetting',
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
        $result = Auth::attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), isset($_POST['remember']));
        if ($result === 'ok') {
            redirect();
        }
        if ($result === 'totp') {
            redirect('login/totp');
        }
        flash('error', 'メールアドレスまたはパスワードが正しくないか、一時的にロックされています。');
        redirect('login');
    }

    private function totpForm(): void
    {
        if (!Auth::pending2fa()) {
            redirect('login');
        }
        render('totp_login', ['title' => '二要素認証']);
    }

    private function totpVerify(): void
    {
        if (!Auth::pending2fa()) {
            flash('error', '認証の有効期限が切れました。もう一度ログインしてください。');
            redirect('login');
        }
        if (Auth::completeTotp((string)($_POST['code'] ?? ''))) {
            redirect();
        }
        flash('error', '認証コードが正しくありません。');
        redirect('login/totp');
    }

    // ---- 二要素認証（TOTP）設定：本人（§5）----

    /** 設定画面。表示テーマ、通知、TOTPの状態と各種操作を表示。 */
    private function securityPage(): void
    {
        $user = Auth::user();
        $enabled = (int)$user['totp_enabled'] === 1;
        $setup = $_SESSION['totp_setup'] ?? null; // セットアップ中（未確定）の状態
        $qrSvg = null;
        if (!$enabled && is_array($setup)) {
            $uri = Totp::provisioningUri((string)$setup['secret'], (string)$user['email']);
            $qrSvg = Totp::qrSvg($uri);
        }
        render('security', [
            'title' => '設定',
            'enabled' => $enabled,
            'setup' => $setup,
            'qrSvg' => $qrSvg,
            'pushConfigured' => PushService::configured(),
            'pushSubscriptionCount' => PushService::subscriptionCount((int)Auth::id()),
            'pushPreference' => PushService::preference((int)Auth::id()),
        ]);
    }

    private function pushPreferences(): void
    {
        try {
            $enabled = ($_POST['enabled'] ?? '0') === '1';
            $time = (string)($_POST['reminder_time'] ?? '');
            $before = PushService::preference((int)Auth::id());
            PushService::savePreference((int)Auth::id(), $enabled, $time);
            Audit::log('push_preference_changed', 'user', Auth::id(), $before, ['enabled' => $enabled, 'reminder_time' => $time]);
            flash('success', '退勤忘れ通知の個人設定を更新しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('security');
    }

    private function pushSubscribe(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!PushService::configured()) throw new DomainException('Web Pushはまだサーバー設定されていません。');
            $subscription = json_decode((string)($_POST['subscription'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($subscription)) throw new DomainException('Push購読情報が正しくありません。');
            PushService::saveSubscription((int)Auth::id(), $subscription);
            Audit::log('push_subscribed', 'user', Auth::id(), null, ['endpoint_hash' => hash('sha256', (string)$subscription['endpoint'])]);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $e instanceof DomainException ? $e->getMessage() : '通知設定を保存できませんでした。'], JSON_UNESCAPED_UNICODE);
        }
    }

    private function pushUnsubscribe(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $endpoint = (string)($_POST['endpoint'] ?? '');
        if ($endpoint !== '') PushService::removeSubscription((int)Auth::id(), $endpoint);
        Audit::log('push_unsubscribed', 'user', Auth::id());
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    /** TOTPセットアップを開始する。シークレットとバックアップコードをセッションに保持し、確認待ちにする。 */
    private function securityTotpInit(): void
    {
        $user = Auth::user();
        if ((int)$user['totp_enabled'] === 1) { flash('error', 'すでに有効です。'); redirect('security'); }
        $_SESSION['totp_setup'] = [
            'secret' => Totp::newSecret(),
            'backup' => Totp::generateBackupCodes(10),
        ];
        redirect('security');
    }

    /** 入力コードでセットアップを確定する。シークレットとバックアップコード（ハッシュ）を保存し有効化する。 */
    private function securityTotpConfirm(): void
    {
        $user = Auth::user();
        $setup = $_SESSION['totp_setup'] ?? null;
        if ((int)$user['totp_enabled'] === 1 || !is_array($setup)) { flash('error', 'セットアップ情報がありません。最初からやり直してください。'); redirect('security'); }
        if (!Totp::verify((string)$setup['secret'], (string)($_POST['code'] ?? ''))) {
            flash('error', '認証コードが正しくありません。認証アプリの時刻同期を確認してください。');
            redirect('security');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, updated_at = NOW() WHERE id = ?')->execute([$setup['secret'], Auth::id()]);
            $pdo->prepare('DELETE FROM totp_backup_codes WHERE user_id = ?')->execute([Auth::id()]);
            $ins = $pdo->prepare('INSERT INTO totp_backup_codes (user_id, code_hash, created_at) VALUES (?, ?, NOW())');
            foreach ((array)$setup['backup'] as $bc) {
                $ins->execute([Auth::id(), Totp::hashBackup((string)$bc)]);
            }
            Audit::log('totp_enabled', 'user', Auth::id(), null, null, Auth::id());
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        unset($_SESSION['totp_setup']);
        flash('success', '二要素認証を有効にしました。バックアップコードは安全に保管してください。');
        redirect('security');
    }

    /** 本人がTOTPを無効化する。 */
    private function securityTotpDisable(): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, updated_at = NOW() WHERE id = ?')->execute([Auth::id()]);
            $pdo->prepare('DELETE FROM totp_backup_codes WHERE user_id = ?')->execute([Auth::id()]);
            Audit::log('totp_disabled', 'user', Auth::id(), null, null, Auth::id());
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        unset($_SESSION['totp_setup']);
        flash('success', '二要素認証を無効にしました。');
        redirect('security');
    }

    /** 管理者が対象アカウントのTOTPを無効化（リセット）する（端末紛失時対応・§4.2）。 */
    private function adminUserTotpDisable(): void
    {
        Auth::requireAdmin();
        $id = (int)($_POST['user_id'] ?? 0);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM totp_backup_codes WHERE user_id = ?')->execute([$id]);
            Audit::log('totp_disabled_by_admin', 'user', $id, null, null);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', '対象アカウントの二要素認証を無効化しました。');
        redirect('admin/users');
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
            if (!PasswordMail::send($email, $link)) {
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?')->execute([$hash]);
                error_log('Password reset mail submission failed for user ' . (int)$userId);
            }
            Audit::log('password_reset_requested', 'user', (int)$userId, null, null, (int)$userId);
        }
        flash('success', '再設定を受け付けました。登録済みのアドレスをご確認ください。届かない場合は迷惑メールフォルダを確認するか、管理者へお問い合わせください。');
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
        if (mb_strlen($password) < 8) {
            flash('error', 'パスワードは8文字以上で入力してください。');
            header('Location: ' . url('reset-password') . '&token=' . urlencode($token)); exit;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM password_reset_tokens WHERE token_hash = ? AND expires_at >= NOW() AND used_at IS NULL LIMIT 1 FOR UPDATE');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            flash('error', '再設定URLが無効または期限切れです。'); redirect('forgot-password');
        }
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
        // 入社月による有給の自動付与を、ログイン中は1日1回だけ判定・実行する（冪等）。
        if (($_SESSION['leave_accrued_on'] ?? '') !== date('Y-m-d')) {
            try {
                LeaveAccrualService::accrueForEmployee($employeeId);
                $_SESSION['leave_accrued_on'] = date('Y-m-d');
            } catch (\Throwable $e) {
                error_log('Leave accrual on dashboard failed for employee ' . $employeeId . ': ' . $e->getMessage());
            }
        }
        $summary = LeaveService::summary($employeeId);
        $stmt = $pdo->prepare("SELECT * FROM leave_entries WHERE employee_id = ? AND status IN ('pending','registered','approved') AND leave_date >= CURDATE() ORDER BY leave_date LIMIT 5");
        $stmt->execute([$employeeId]);
        $upcoming = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC LIMIT 1');
        $stmt->execute([$employeeId]);
        $lastEvent = $stmt->fetch() ?: null;
        $stmt = $pdo->prepare('SELECT * FROM attendance_notices WHERE employee_id = ? ORDER BY target_date DESC, id DESC LIMIT 5');
        $stmt->execute([$employeeId]);
        $month = CompanyCalendarService::normalizeMonth((string)($_GET['month'] ?? ''));
        $addDate = $this->validCalendarAddDate((string)($_GET['add_date'] ?? ''));
        render('dashboard', compact('summary', 'upcoming', 'lastEvent') + ['notices' => $stmt->fetchAll(), 'compSummary' => CompLeaveService::summary($employeeId), 'calendar' => CompanyCalendarService::calendar($employeeId, $month), 'calendarAddDate' => $addDate, 'title' => 'ホーム']);
    }

    private function leavePage(): void
    {
        $employeeId = (int)Auth::employeeId();
        $stmt = Database::connection()->prepare('SELECT le.*, u.email AS cancelled_by_email FROM leave_entries le LEFT JOIN users u ON u.id = le.cancelled_by WHERE le.employee_id = ? ORDER BY leave_date DESC, id DESC');
        $stmt->execute([$employeeId]);
        $compStmt = Database::connection()->prepare("SELECT * FROM comp_leave_entries WHERE employee_id = ? ORDER BY leave_date DESC, id DESC");
        $compStmt->execute([$employeeId]);
        $noticeStmt = Database::connection()->prepare('SELECT * FROM attendance_notices WHERE employee_id = ? ORDER BY target_date DESC, id DESC LIMIT 100');
        $noticeStmt->execute([$employeeId]);
        $month = CompanyCalendarService::normalizeMonth((string)($_GET['month'] ?? ''));
        $addDate = $this->validCalendarAddDate((string)($_GET['add_date'] ?? ''));
        render('leave', ['title' => '休暇・勤怠連絡', 'summary' => LeaveService::summary($employeeId), 'entries' => $stmt->fetchAll(), 'compSummary' => CompLeaveService::summary($employeeId), 'compEntries' => $compStmt->fetchAll(), 'notices' => $noticeStmt->fetchAll(), 'calendar' => CompanyCalendarService::calendar($employeeId, $month), 'calendarAddDate' => $addDate]);
    }

    private function compLeaveCreate(): void
    {
        try {
            CompLeaveService::createEntry((int)Auth::employeeId(), $_POST);
            flash('success', '代休の取得予定を登録しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('leave');
    }

    private function compLeaveCancel(): void
    {
        try {
            CompLeaveService::cancelEntry((int)($_POST['entry_id'] ?? 0), (string)($_POST['reason'] ?? ''));
            flash('success', '代休の取得予定を取り消しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('leave');
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
        try {
            flash('success', $this->createAttendanceNotice($_POST));
        } catch (\Throwable $e) {
            flash('error', $e instanceof DomainException ? $e->getMessage() : '勤怠連絡を登録できませんでした。');
        }
        redirect('leave');
    }

    private function contactPage(): void
    {
        render('contact', ['title' => '問い合わせ', 'configured' => ContactService::configured()]);
    }

    private function contactSubmit(): void
    {
        try {
            ContactService::send((array)Auth::user(), (string)($_POST['category'] ?? ''), (string)($_POST['body'] ?? ''));
            Audit::log('contact_sent', 'user', Auth::id(), null, ['category' => (string)($_POST['category'] ?? '')]);
            flash('success', '問い合わせを送信しました。担当者からの連絡をお待ちください。');
        } catch (DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Contact submit failed: ' . $e->getMessage());
            flash('error', '問い合わせを送信できませんでした。時間をおいて再度お試しください。');
        }
        redirect('contact');
    }

    private function personalCalendarCreate(): void
    {
        $jsonResponse = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'calendar-entry';
        try {
            $kind = (string)($_POST['entry_kind'] ?? '');
            $input = $_POST;
            $input['leave_date'] = (string)($_POST['entry_date'] ?? '');
            $input['target_date'] = (string)($_POST['entry_date'] ?? '');
            if ($kind === 'leave') {
                LeaveService::create((int)Auth::employeeId(), $input);
                $message = '有給予定を登録しました。';
            } elseif ($kind === 'comp_leave') {
                $input['note'] = (string)($_POST['comp_note'] ?? '');
                CompLeaveService::createEntry((int)Auth::employeeId(), $input);
                $message = '代休の取得予定を登録しました。';
            } elseif ($kind === 'notice') {
                $message = $this->createAttendanceNotice($input);
            } else {
                throw new DomainException('予定の種類を選択してください。');
            }
            if (!$jsonResponse) {
                flash('success', $message);
                $this->redirectToPersonalCalendar((string)($_POST['return_route'] ?? ''), (string)($_POST['entry_date'] ?? ''));
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $message = $e instanceof DomainException ? $e->getMessage() : '予定を登録できませんでした。';
            if (!$jsonResponse) {
                flash('error', $message);
                $this->redirectToPersonalCalendar((string)($_POST['return_route'] ?? ''), (string)($_POST['entry_date'] ?? ''));
            }
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    private function validCalendarAddDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : '';
    }

    private function redirectToPersonalCalendar(string $route, string $date): never
    {
        $route = $route === 'leave' ? 'leave' : '';
        $month = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? substr($date, 0, 7) : date('Y-m');
        $location = url($route);
        header('Location: ' . $location . (str_contains($location, '?') ? '&' : '?') . 'month=' . rawurlencode($month));
        exit;
    }

    private function createAttendanceNotice(array $input): string
    {
        $types = ['late', 'early', 'leave_full', 'leave_am', 'leave_pm', 'absence', 'holiday_work', 'medical', 'other'];
        $type = (string)($input['notice_type'] ?? '');
        $date = (string)($input['target_date'] ?? '');
        if (!in_array($type, $types, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new DomainException('対象日と勤怠種別を正しく入力してください。');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $leaveEntryId = null;
            if ($type === 'holiday_work') {
                $lock = $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
                $lock->execute([Auth::employeeId()]);
            }
            $leaveMap = ['leave_full' => 'full', 'leave_am' => 'am', 'leave_pm' => 'pm'];
            if (isset($leaveMap[$type])) {
                $leaveEntryId = LeaveService::create((int)Auth::employeeId(), ['leave_date' => $date, 'leave_type' => $leaveMap[$type], 'note' => $input['details'] ?? '', 'confirmed_with' => $input['confirmed_with'] ?? '']);
            }
            $stmt = $pdo->prepare('INSERT INTO attendance_notices (employee_id, target_date, notice_type, expected_start, expected_end, details, leave_entry_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $expectedStart = trim((string)($input['expected_start'] ?? '')) ?: null;
            $expectedEnd = trim((string)($input['expected_end'] ?? '')) ?: null;
            $stmt->execute([Auth::employeeId(), $date, $type, $expectedStart, $expectedEnd, trim((string)($input['details'] ?? '')), $leaveEntryId, Auth::id()]);
            $noticeId = (int)$pdo->lastInsertId();
            Audit::log('attendance_notice_created', 'attendance_notice', $noticeId, null, ['type' => $type, 'date' => $date]);
            // 休日出勤は代休1日分を同一トランザクション内で自動発生させる（§7.8）。
            $grantId = $type === 'holiday_work'
                ? CompLeaveService::generateForHolidayWork((int)Auth::employeeId(), $date, $noticeId) : 0;
            $pdo->commit();
            return $grantId > 0 ? '勤怠連絡を登録し、代休1日を付与しました。' : '勤怠連絡を登録しました。';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function attendancePage(): void
    {
        $employeeId = (int)Auth::employeeId();
        $stmt = Database::connection()->prepare('SELECT * FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC LIMIT 100');
        $stmt->execute([$employeeId]);
        $events = $stmt->fetchAll();
        // 当月の自分の集計（出勤日数・勤務時間）。
        $month = date('Y-m');
        $pairs = AttendanceService::pairs($month, $employeeId);
        $days = [];
        $minutes = 0;
        foreach ($pairs as $p) {
            $days[$p['date']] = true;
            if ($p['clock_in'] && $p['clock_out']) {
                $minutes += AttendanceService::minutesBetween($p['clock_in'], $p['clock_out']);
            }
        }
        render('attendance', ['title' => '出退勤', 'events' => $events, 'lastEvent' => $events[0] ?? null,
            'month' => $month, 'workedDays' => count($days), 'workedTime' => AttendanceService::formatMinutes($minutes)]);
    }

    private function clock(): void
    {
        $eventType = (string)($_POST['event_type'] ?? '');
        if (!in_array($eventType, ['clock_in', 'clock_out'], true)) { flash('error', '不正な操作です。'); redirect('attendance'); }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
        $lock->execute([Auth::employeeId()]);
        $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([Auth::employeeId()]);
        $last = $stmt->fetch();
        $expected = (!$last || $last['event_type'] === 'clock_out') ? 'clock_in' : 'clock_out';
        if ($eventType !== $expected) {
            $pdo->rollBack(); flash('error', $eventType === 'clock_in' ? 'すでに出勤中です。' : '先に出勤打刻が必要です。'); redirect('attendance');
        }
        $now = new \DateTimeImmutable();
        $stmt = $pdo->prepare('INSERT INTO attendance_events (employee_id, event_type, occurred_at, created_by, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([Auth::employeeId(), $eventType, $now->format('Y-m-d H:i:s'), Auth::id()]);
        Audit::log($eventType, 'attendance_event', (int)$pdo->lastInsertId(), null, ['occurred_at' => $now->format('Y-m-d H:i:s')]);
        $grantId = 0;
        if ($eventType === 'clock_in' && (int)$now->format('N') >= 6) {
            $grantId = CompLeaveService::generateForHolidayWork((int)Auth::employeeId(), $now->format('Y-m-d'), null);
        }
        $pdo->commit();
        // 部分移行: フォーム同期対象の社員は、アプリ打刻を元Googleフォームへも転送する（ベストエフォート）。
        try {
            $emp = $pdo->prepare('SELECT id, full_name, form_sync_enabled, form_sync_name FROM employees WHERE id = ?');
            $emp->execute([Auth::employeeId()]);
            if ($row = $emp->fetch()) {
                FormSyncService::submitForEmployee($row, $eventType);
            }
        } catch (\Throwable $e) {
            error_log('[form-sync] clock hook failed: ' . $e->getMessage());
        }
        flash('success', ($eventType === 'clock_in' ? '出勤を記録しました。' : '退勤を記録しました。') . ($grantId > 0 ? '土日の出勤につき代休1日を付与しました。' : ''));
        redirect('attendance');
    }

    private function adminPage(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $stats = [
            'employees' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(),
            'working' => (int)$pdo->query("SELECT COUNT(*) FROM attendance_events ae WHERE ae.event_type = 'clock_in' AND ae.id IN (SELECT MAX(id) FROM attendance_events GROUP BY employee_id)")->fetchColumn(),
            'future_leave' => (int)$pdo->query("SELECT COUNT(*) FROM leave_entries WHERE status IN ('pending','registered','approved') AND leave_date >= CURDATE()")->fetchColumn(),
            'pending_leave' => (int)$pdo->query("SELECT COUNT(*) FROM leave_entries WHERE status='pending'")->fetchColumn(),
        ];
        render('admin/index', ['title' => '管理', 'stats' => $stats]);
    }

    private function adminUsers(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $users = $pdo->query('SELECT u.*, e.full_name, e.employee_code, e.hired_on, e.leave_renewal_month , e.form_sync_enabled, e.form_sync_name FROM users u JOIN employees e ON e.id = u.employee_id ORDER BY e.full_name')->fetchAll();
        $editUser = null;
        $editId = (int)($_GET['edit'] ?? 0);
        if ($editId > 0) {
            $stmt = $pdo->prepare('SELECT u.*, e.full_name, e.employee_code, e.hired_on, e.leave_renewal_month , e.form_sync_enabled, e.form_sync_name FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.id = ?');
            $stmt->execute([$editId]);
            $editUser = $stmt->fetch() ?: null;
            if ($editUser === null) flash('error', '編集対象の社員が見つかりません。');
        }
        render('admin/users', ['title' => '社員管理', 'users' => $users, 'editUser' => $editUser]);
    }

    private function adminUserCreate(): void
    {
        Auth::requireAdmin();
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $name = trim((string)($_POST['full_name'] ?? ''));
        $renewalMonthInput = trim((string)($_POST['leave_renewal_month'] ?? ''));
        $renewalMonth = $renewalMonthInput === '' ? 0 : (ctype_digit($renewalMonthInput) ? (int)$renewalMonthInput : -1);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            flash('error', '氏名と正しいメールアドレスを入力してください。'); redirect('admin/users');
        }
        if ($renewalMonth < 0 || $renewalMonth > 12) { flash('error', '有給更新月は1〜12で入力してください。'); redirect('admin/users'); }
        $temporaryPassword = bin2hex(random_bytes(32));
        $pdo = Database::connection(); $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO employees (employee_code, full_name, hired_on, leave_renewal_month, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([trim((string)($_POST['employee_code'] ?? '')) ?: null, $name, ($_POST['hired_on'] ?? '') ?: null, $renewalMonth ?: null]);
            $employeeId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO users (employee_id, email, password_hash, role, status, session_token, failed_login_attempts, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', ?, 0, NOW(), NOW())");
            $stmt->execute([$employeeId, $email, password_hash($temporaryPassword, PASSWORD_DEFAULT), ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'employee', bin2hex(random_bytes(16))]);
            $userId = (int)$pdo->lastInsertId();
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())')->execute([$userId, hash('sha256', $token)]);
            Audit::log('account_created', 'user', $userId, null, ['email' => $email, 'employee_id' => $employeeId]);
            $pdo->commit();
            $link = rtrim((string)config('APP_URL'), '/') . url('reset-password') . '&token=' . urlencode($token);
            $sent = PasswordMail::sendInvite($email, $link);
            flash($sent ? 'success' : 'error', $sent ? '社員アカウントを作成し、招待メールを送信しました。' : 'アカウントは作成しましたが、招待メールを送信できませんでした。本人にパスワード再設定を試してもらってください。');
        } catch (\Throwable $e) {
            $pdo->rollBack(); flash('error', 'アカウントを作成できませんでした。メールアドレスや社員番号の重複を確認してください。');
        }
        redirect('admin/users');
    }

    private function adminUserUpdate(): void
    {
        Auth::requireAdmin();
        $id = (int)($_POST['user_id'] ?? 0);
        $name = trim((string)($_POST['full_name'] ?? ''));
        $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $hiredOn = trim((string)($_POST['hired_on'] ?? ''));
        $renewalMonthInput = trim((string)($_POST['leave_renewal_month'] ?? ''));
        $renewalMonth = $renewalMonthInput === '' ? 0 : (ctype_digit($renewalMonthInput) ? (int)$renewalMonthInput : -1);
        $role = (string)($_POST['role'] ?? '');
        $status = (string)($_POST['status'] ?? '');
        $formSyncEnabled = ($_POST['form_sync_enabled'] ?? '') === '1' ? 1 : 0;
        $formSyncName = trim((string)($_POST['form_sync_name'] ?? ''));
        if ($id < 1 || $name === '' || mb_strlen($name) > 100 || mb_strlen($employeeCode) > 50 || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', '氏名・社員番号・メールアドレスを確認してください。'); redirect('admin/users');
        }
        if (!in_array($role, ['employee', 'admin'], true) || !in_array($status, ['active', 'disabled', 'suspended'], true)) {
            flash('error', '権限または在籍状態が正しくありません。'); redirect('admin/users');
        }
        if ($renewalMonth < 0 || $renewalMonth > 12) { flash('error', '有給更新月は1〜12で入力してください。'); redirect('admin/users'); }
        if (mb_strlen($formSyncName) > 100) { flash('error', 'フォーム同期の氏名は100文字以内で入力してください。'); redirect('admin/users'); }
        if ($formSyncEnabled === 1 && $formSyncName === '') { flash('error', 'フォーム同期をONにする場合は、フォームに送信する氏名を入力してください。'); redirect('admin/users'); }
        if ($hiredOn !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $hiredOn);
            if (!$date || $date->format('Y-m-d') !== $hiredOn) { flash('error', '入社日を正しく入力してください。'); redirect('admin/users'); }
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT u.id, u.employee_id, u.email, u.role, u.status, e.full_name, e.employee_code, e.hired_on, e.leave_renewal_month , e.form_sync_enabled, e.form_sync_name FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.id = ?');
        $stmt->execute([$id]); $before = $stmt->fetch();
        if (!$before) { flash('error', '対象の社員が見つかりません。'); redirect('admin/users'); }
        if ($id === Auth::id() && ($role !== 'admin' || $status !== 'active')) {
            flash('error', '自分自身の管理者権限と有効状態は変更できません。'); redirect('admin/users');
        }
        $employeeCode = $employeeCode === '' ? null : $employeeCode;
        $hiredOn = $hiredOn === '' ? null : $hiredOn;
        $formSyncName = $formSyncName === '' ? null : $formSyncName;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?'); $stmt->execute([$email, $id]);
        if ((int)$stmt->fetchColumn() > 0) { flash('error', 'そのメールアドレスは別の社員が使用しています。'); redirect('admin/users'); }
        if ($employeeCode !== null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE employee_code = ? AND id <> ?'); $stmt->execute([$employeeCode, (int)$before['employee_id']]);
            if ((int)$stmt->fetchColumn() > 0) { flash('error', 'その社員番号は別の社員が使用しています。'); redirect('admin/users'); }
        }
        if ($before['role'] === 'admin' && $before['status'] === 'active' && ($role !== 'admin' || $status !== 'active')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?"); $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() < 1) { flash('error', '有効な管理者が0人になるため、この変更はできません。'); redirect('admin/users'); }
        }
        $after = ['full_name' => $name, 'employee_code' => $employeeCode, 'hired_on' => $hiredOn, 'leave_renewal_month' => $renewalMonth ?: null, 'form_sync_enabled' => $formSyncEnabled, 'form_sync_name' => $formSyncName, 'email' => $email, 'role' => $role, 'status' => $status];
        $beforeAudit = array_intersect_key($before, $after);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE employees SET full_name = ?, employee_code = ?, hired_on = ?, leave_renewal_month = ?, form_sync_enabled = ?, form_sync_name = ?, updated_at = NOW() WHERE id = ?')->execute([$name, $employeeCode, $hiredOn, $renewalMonth ?: null, $formSyncEnabled, $formSyncName, (int)$before['employee_id']]);
            $pdo->prepare('UPDATE users SET email = ?, role = ?, status = ?, updated_at = NOW() WHERE id = ?')->execute([$email, $role, $status, $id]);
            $emailOrRoleChanged = $email !== $before['email'] || $role !== $before['role'];
            $becameInactive = $status !== 'active' && $before['status'] === 'active';
            if (($emailOrRoleChanged && $id !== Auth::id()) || $becameInactive) Auth::revokeAllSessions($id);
            if ($status !== 'active') {
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$id]);
                $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$id]);
            }
            if ($status === 'disabled' && $before['status'] !== 'disabled') {
                $employeeId = (int)$before['employee_id'];
                $pdo->prepare('DELETE FROM view_grants WHERE viewer_employee_id = ? OR target_employee_id = ?')->execute([$employeeId, $employeeId]);
                $pdo->prepare('DELETE FROM group_memberships WHERE employee_id = ?')->execute([$employeeId]);
            }
            Audit::log('account_updated', 'user', $id, $beforeAudit, $after);
            $pdo->commit(); flash('success', '社員情報を更新しました。');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', '社員情報を更新できませんでした。入力内容の重複を確認してください。');
        }
        redirect('admin/users');
    }

    private function adminUserToggle(): void
    {
        Auth::requireAdmin();
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === Auth::id()) { flash('error', '自分自身は無効化できません。'); redirect('admin/users'); }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, status, employee_id FROM users WHERE id = ?'); $stmt->execute([$id]); $user = $stmt->fetch();
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
                    $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$id]);
                    // 当該社員が関わる閲覧権限（閲覧者／対象）とグループ所属を削除する（§14。方針は§18で確定予定）。
                    $empId = (int)$user['employee_id'];
                    $pdo->prepare('DELETE FROM view_grants WHERE viewer_employee_id = ? OR target_employee_id = ?')->execute([$empId, $empId]);
                    $pdo->prepare('DELETE FROM group_memberships WHERE employee_id = ?')->execute([$empId]);
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

    private function adminUserPasswordReset(): void
    {
        Auth::requireAdmin();
        $id = (int)($_POST['user_id'] ?? 0);
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT u.id, u.email, u.status, e.full_name , e.form_sync_enabled, e.form_sync_name FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) { flash('error', '対象の社員が見つかりません。'); redirect('admin/users'); }
        if ($user['status'] !== 'active') { flash('error', '有効なアカウントにだけ再設定メールを送信できます。'); redirect('admin/users'); }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$id]);
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())')->execute([$id, $hash]);
            // 管理者によるリセットは緊急対応も兼ねるため、メール発行時点で対象者を全端末から失効させる。
            Auth::revokeAllSessions($id);
            Audit::log('password_reset_requested_by_admin', 'user', $id, null, ['email' => $user['email']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $link = rtrim((string)config('APP_URL'), '/') . url('reset-password') . '&token=' . urlencode($token);
        if (!PasswordMail::send((string)$user['email'], $link)) {
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?')->execute([$hash]);
            flash('error', '対象社員は全端末からログアウトしましたが、再設定メールを送信できませんでした。メール設定を確認して再実行してください。');
        } else {
            flash('success', $user['full_name'] . 'さんを全端末からログアウトし、60分有効のパスワード再設定メールを送信しました。');
        }
        redirect('admin/users');
    }

    private function adminUsersLogoutAll(): void
    {
        Auth::requireAdmin();
        $actorId = (int)Auth::id();
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Audit::log('force_logout_all', 'users', null, null, null, $actorId);
            $count = Auth::revokeEverySession();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        Auth::logout(false);
        session_start();
        flash('success', $count . 'アカウントの全端末をログアウトしました。');
        redirect('login');
    }

    private function adminLeave(): void
    {
        Auth::requireAdmin(); $pdo = Database::connection();
        $employees = $pdo->query('SELECT e.id, e.full_name, e.employee_code FROM employees e JOIN users u ON u.employee_id=e.id ORDER BY e.full_name')->fetchAll();
        $entries = $pdo->query('SELECT le.*, e.full_name FROM leave_entries le JOIN employees e ON e.id=le.employee_id ORDER BY le.leave_date DESC, le.id DESC LIMIT 300')->fetchAll();
        foreach ($employees as &$employee) {
            $employee['summary'] = LeaveService::summary((int)$employee['id']);
            $employee['comp_summary'] = CompLeaveService::summary((int)$employee['id']);
        }
        unset($employee);
        $compGrants = $pdo->query('SELECT g.*, e.full_name FROM comp_leave_grants g JOIN employees e ON e.id=g.employee_id ORDER BY g.occurred_on DESC, g.id DESC LIMIT 300')->fetchAll();
        $compEntries = $pdo->query('SELECT ce.*, e.full_name FROM comp_leave_entries ce JOIN employees e ON e.id=ce.employee_id ORDER BY ce.leave_date DESC, ce.id DESC LIMIT 300')->fetchAll();
        render('admin/leave', ['title' => '有給管理（管理者）', 'employees' => $employees, 'entries' => $entries, 'compGrants' => $compGrants, 'compEntries' => $compEntries, 'approvalRequired' => Settings::bool('leave_approval_required', true), 'importPreview' => $_SESSION['leave_import_preview'] ?? null]);
    }

    private function adminLeaveImportTemplate(): void
    {
        Auth::requireAdmin();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="leave_balance_import_template.csv"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['氏名', '対象年度', '残日数', '付与日', '有効期限', '更新月', '備考'], ',', '"', '\\', "\r\n");
        $exampleGrantDate = date('Y') . '-04-01';
        $exampleExpiry = (new \DateTimeImmutable($exampleGrantDate))->modify('+2 years -1 day')->format('Y-m-d');
        fputcsv($output, ['山田 太郎', date('Y'), '10.0', $exampleGrantDate, $exampleExpiry, '4', '運用開始時点の残数'], ',', '"', '\\', "\r\n");
        fclose($output);
        exit;
    }

    private function adminLeaveImportPreview(): void
    {
        Auth::requireAdmin();
        try {
            $_SESSION['leave_import_preview'] = LeaveImportService::preview($_FILES['csv_file'] ?? []);
            flash('success', 'CSVを読み込みました。内容を確認して取り込みを確定してください。');
        } catch (DomainException $e) {
            unset($_SESSION['leave_import_preview']);
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            unset($_SESSION['leave_import_preview']);
            flash('error', 'CSVを読み込めませんでした。ファイル形式を確認してください。');
        }
        redirect('admin/leave');
    }

    private function adminLeaveImportConfirm(): void
    {
        Auth::requireAdmin();
        try {
            $result = LeaveImportService::import((array)($_SESSION['leave_import_preview'] ?? []), (int)Auth::id());
            unset($_SESSION['leave_import_preview']);
            flash('success', "有給残数を{$result['imported']}件取り込みました。重複{$result['skipped']}件はスキップしました。");
        } catch (DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            flash('error', '有給残数を取り込めませんでした。データは追加されていません。');
        }
        redirect('admin/leave');
    }

    private function adminLeaveImportCancel(): void
    {
        Auth::requireAdmin();
        unset($_SESSION['leave_import_preview']);
        flash('success', 'CSVの取り込み確認を取り消しました。');
        redirect('admin/leave');
    }

    private function adminCalendar(): void
    {
        Auth::requireAdmin();
        $month = CompanyCalendarService::normalizeMonth((string)($_GET['month'] ?? ''));
        $editId = (int)($_GET['edit'] ?? 0);
        $events = Database::connection()->query('SELECT * FROM company_calendar_events ORDER BY start_date DESC, start_time DESC, id DESC LIMIT 300')->fetchAll();
        render('admin/calendar', [
            'title' => '会社カレンダー設定',
            'calendar' => CompanyCalendarService::companyCalendar($month),
            'events' => $events,
            'editEvent' => $editId > 0 ? CompanyCalendarService::find($editId) : null,
            'holidaySyncAt' => HolidayService::lastSyncedAt(),
            'importPreview' => $_SESSION['calendar_import_preview'] ?? null,
        ]);
    }

    private function adminCalendarImportPreview(): void
    {
        Auth::requireAdmin();
        try {
            $_SESSION['calendar_import_preview'] = CalendarImportService::preview(
                $_FILES['calendar_file'] ?? [],
                (string)($_POST['calendar_sheet'] ?? '')
            );
            flash('success', 'Excelを読み込みました。内容を確認して取り込みを確定してください。');
        } catch (DomainException $e) {
            unset($_SESSION['calendar_import_preview']);
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            unset($_SESSION['calendar_import_preview']);
            flash('error', 'Excelを読み込めませんでした。ファイル形式を確認してください。');
        }
        redirect('admin/calendar');
    }

    private function adminCalendarImportConfirm(): void
    {
        Auth::requireAdmin();
        try {
            $result = CalendarImportService::import((array)($_SESSION['calendar_import_preview'] ?? []), (int)Auth::id());
            unset($_SESSION['calendar_import_preview']);
            flash('success', "会社予定を{$result['imported']}件取り込みました。重複{$result['skipped']}件はスキップしました。");
        } catch (DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            flash('error', '会社予定を取り込めませんでした。データは追加されていません。');
        }
        redirect('admin/calendar');
    }

    private function adminCalendarImportCancel(): void
    {
        Auth::requireAdmin();
        unset($_SESSION['calendar_import_preview']);
        flash('success', 'Excelの取り込み確認を取り消しました。');
        redirect('admin/calendar');
    }

    private function adminCalendarSave(): void
    {
        Auth::requireAdmin();
        try {
            $editing = (int)($_POST['event_id'] ?? 0) > 0;
            CompanyCalendarService::save($_POST);
            flash('success', $editing ? '会社予定を更新しました。' : '会社予定を追加しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('admin/calendar');
    }

    private function adminCalendarDelete(): void
    {
        Auth::requireAdmin();
        try {
            CompanyCalendarService::delete((int)($_POST['event_id'] ?? 0));
            flash('success', '会社予定を削除しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('admin/calendar');
    }

    private function adminCompGrantCancel(): void
    {
        Auth::requireAdmin();
        try {
            CompLeaveService::cancelGrant((int)($_POST['grant_id'] ?? 0), (string)($_POST['reason'] ?? ''));
            flash('success', '代休の発生を取り消しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('admin/leave');
    }

    private function adminGrant(): void
    {
        Auth::requireAdmin(); $days = (float)($_POST['days'] ?? 0);
        $grantedOn = (string)($_POST['granted_on'] ?? ''); $expiresOn = (string)($_POST['expires_on'] ?? ''); $reason = trim((string)($_POST['reason'] ?? ''));
        $grantYear = (int)($_POST['grant_year'] ?? date('Y'));
        $maximumExpiry = preg_match('/^\d{4}-\d{2}-\d{2}$/', $grantedOn) ? (new \DateTimeImmutable($grantedOn))->modify('+2 years -1 day')->format('Y-m-d') : '';
        if ($days <= 0 || $grantedOn === '' || $expiresOn === '' || $expiresOn < $grantedOn || $maximumExpiry === '' || $expiresOn > $maximumExpiry || $reason === '' || $grantYear < 2000 || $grantYear > (int)date('Y') + 1) { flash('error', '付与内容を正しく入力してください。有効期限は付与日の2年後の前日までです。'); redirect('admin/leave'); }
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

    private function adminLeaveReview(): void
    {
        Auth::requireAdmin();
        try {
            LeaveService::review((int)($_POST['entry_id'] ?? 0), (string)($_POST['decision'] ?? ''), (string)($_POST['reason'] ?? ''));
            flash('success', ($_POST['decision'] ?? '') === 'approve' ? '有給申請を承認しました。' : '有給申請を却下しました。');
        } catch (DomainException $e) { flash('error', $e->getMessage()); }
        redirect('admin/leave');
    }

    private function adminApprovalSetting(): void
    {
        Auth::requireAdmin();
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $before = Settings::bool('leave_approval_required', true);
            Settings::set('leave_approval_required', $enabled ? '1' : '0', Auth::id());
            $released = 0;
            if (!$enabled) {
                $released = $pdo->exec("UPDATE leave_entries SET status=IF(leave_date < CURDATE(), 'taken', 'registered'), reviewed_by=" . (int)Auth::id() . ", reviewed_at=NOW(), updated_at=NOW() WHERE status='pending'");
            }
            Audit::log('leave_approval_setting_changed', 'app_setting', null, ['enabled' => $before], ['enabled' => $enabled, 'released_pending' => $released]);
            $pdo->commit();
            flash('success', $enabled ? '有給承認フローを有効にしました。' : "有給承認フローを無効にし、承認待ち{$released}件を通常登録へ移しました。");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        redirect('admin/leave');
    }

    private function adminAudit(): void
    {
        Auth::requireAdmin();
        $logs = Database::connection()->query('SELECT a.*, u.email AS actor_email FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.id DESC LIMIT 500')->fetchAll();
        render('admin/audit', ['title' => '操作履歴', 'logs' => $logs]);
    }

    // ---- 閲覧権限（§4.3, §14）----

    /** 自分が閲覧できる社員の一覧（管理者は全社員）。 */
    private function viewablePage(): void
    {
        $pdo = Database::connection();
        $me = Auth::user();
        if ($me['role'] === 'admin') {
            $stmt = $pdo->prepare('SELECT e.id, e.full_name, e.employee_code FROM employees e JOIN users u ON u.employee_id=e.id WHERE e.id <> ? ORDER BY e.full_name');
            $stmt->execute([(int)$me['employee_id']]);
            $employees = $stmt->fetchAll();
        } else {
            $ids = Access::viewableEmployeeIds((int)$me['employee_id']);
            $employees = [];
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, full_name, employee_code FROM employees WHERE id IN ($in) ORDER BY full_name");
                $stmt->execute($ids);
                $employees = $stmt->fetchAll();
            }
        }
        render('viewable', ['title' => '閲覧', 'employees' => $employees, 'isAdmin' => $me['role'] === 'admin']);
    }

    /** 閲覧専用の社員詳細。参照権限をAccessで照合する（本人/管理者/閲覧権限のみ）。 */
    private function viewableShow(): void
    {
        $employeeId = (int)($_GET['employee_id'] ?? 0);
        Access::assertView($employeeId); // 権限が無ければ403で停止する
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, full_name, employee_code FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        if (!$employee) {
            http_response_code(404);
            render('error', ['title' => '見つかりません', 'message' => '対象の社員が見つかりません。']);
            return;
        }
        $stmt = $pdo->prepare("SELECT * FROM leave_entries WHERE employee_id = ? AND status NOT IN ('cancelled','rejected') ORDER BY leave_date DESC LIMIT 20");
        $stmt->execute([$employeeId]);
        $entries = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC LIMIT 20');
        $stmt->execute([$employeeId]);
        $events = $stmt->fetchAll();
        $stmt = $pdo->prepare("SELECT leave_date, days, status FROM comp_leave_entries WHERE employee_id = ? AND status IN ('registered', 'taken') ORDER BY leave_date DESC, id DESC LIMIT 20");
        $stmt->execute([$employeeId]);
        $compEntries = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM attendance_notices WHERE employee_id = ? ORDER BY target_date DESC, id DESC LIMIT 20');
        $stmt->execute([$employeeId]);
        render('viewable_show', [
            'title' => $employee['full_name'] . 'さんの状況',
            'employee' => $employee,
            'summary' => LeaveService::summary($employeeId),
            'compSummary' => CompLeaveService::summary($employeeId),
            'entries' => $entries, 'compEntries' => $compEntries, 'events' => $events, 'notices' => $stmt->fetchAll(),
        ]);
    }

    /** 自分のデータの公開先（本人がセルフサービスで設定）。 */
    private function sharingPage(): void
    {
        $pdo = Database::connection();
        $meEmp = (int)Auth::employeeId();
        $stmt = $pdo->prepare("SELECT vg.id, e.full_name AS viewer_name, e.employee_code, vg.expires_on FROM view_grants vg LEFT JOIN employees e ON e.id = vg.viewer_employee_id WHERE vg.target_type='employee' AND vg.target_employee_id = ? AND vg.viewer_type='employee' ORDER BY vg.id DESC");
        $stmt->execute([$meEmp]);
        $grants = $stmt->fetchAll();
        $stmt = $pdo->prepare("SELECT e.id, e.full_name FROM employees e JOIN users u ON u.employee_id=e.id WHERE e.id <> ? AND u.status = 'active' ORDER BY e.full_name");
        $stmt->execute([$meEmp]);
        render('sharing', ['title' => 'データの公開先', 'grants' => $grants, 'candidates' => $stmt->fetchAll()]);
    }

    /** 本人が自分のデータの閲覧者（個人）を追加する。対象は必ず自分自身。 */
    private function sharingAdd(): void
    {
        $meEmp = (int)Auth::employeeId();
        $viewerEmp = (int)($_POST['viewer_employee_id'] ?? 0);
        if ($viewerEmp <= 0 || $viewerEmp === $meEmp) { flash('error', '閲覧を許可する相手を選んでください。'); redirect('sharing'); }
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT e.id FROM employees e JOIN users u ON u.employee_id=e.id WHERE e.id=? AND u.status='active'");
        $stmt->execute([$viewerEmp]);
        if (!$stmt->fetch()) { flash('error', '対象の社員が見つかりません。'); redirect('sharing'); }
        $dup = $pdo->prepare("SELECT id FROM view_grants WHERE viewer_type='employee' AND viewer_employee_id=? AND target_type='employee' AND target_employee_id=?");
        $dup->execute([$viewerEmp, $meEmp]);
        if ($dup->fetch()) { flash('error', 'すでに許可済みです。'); redirect('sharing'); }
        $stmt = $pdo->prepare("INSERT INTO view_grants (viewer_type, viewer_employee_id, target_type, target_employee_id, granted_by, created_at) VALUES ('employee', ?, 'employee', ?, ?, NOW())");
        $stmt->execute([$viewerEmp, $meEmp, Auth::id()]);
        Audit::log('view_grant_created', 'view_grant', (int)$pdo->lastInsertId(), null, ['viewer_employee_id' => $viewerEmp, 'target_employee_id' => $meEmp, 'self_service' => true]);
        flash('success', 'データの公開先を追加しました。');
        redirect('sharing');
    }

    /** 本人が自分を対象とする閲覧権限のみ削除できる。 */
    private function sharingRemove(): void
    {
        $meEmp = (int)Auth::employeeId();
        $id = (int)($_POST['grant_id'] ?? 0);
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM view_grants WHERE id=? AND target_type='employee' AND target_employee_id=?");
        $stmt->execute([$id, $meEmp]);
        $grant = $stmt->fetch();
        if (!$grant) { flash('error', '対象の設定が見つかりません。'); redirect('sharing'); }
        $pdo->prepare('DELETE FROM view_grants WHERE id=?')->execute([$id]);
        Audit::log('view_grant_deleted', 'view_grant', $id, $grant, null);
        flash('success', 'データの公開先を削除しました。');
        redirect('sharing');
    }

    private function adminAccess(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $employees = $pdo->query('SELECT e.id, e.full_name, e.employee_code, u.role, u.status FROM employees e JOIN users u ON u.employee_id=e.id ORDER BY e.full_name')->fetchAll();
        $groups = $pdo->query('SELECT g.*, (SELECT COUNT(*) FROM group_memberships m WHERE m.group_id=g.id) AS member_count FROM employee_groups g ORDER BY g.name')->fetchAll();
        $members = $pdo->query('SELECT gm.group_id, gm.employee_id, e.full_name FROM group_memberships gm JOIN employees e ON e.id=gm.employee_id ORDER BY e.full_name')->fetchAll();
        $membersByGroup = [];
        foreach ($members as $m) { $membersByGroup[(int)$m['group_id']][] = $m; }
        $grants = $pdo->query("SELECT vg.*, ve.full_name AS viewer_emp_name, vgr.name AS viewer_grp_name, te.full_name AS target_emp_name, tgr.name AS target_grp_name FROM view_grants vg LEFT JOIN employees ve ON ve.id=vg.viewer_employee_id LEFT JOIN employee_groups vgr ON vgr.id=vg.viewer_group_id LEFT JOIN employees te ON te.id=vg.target_employee_id LEFT JOIN employee_groups tgr ON tgr.id=vg.target_group_id ORDER BY vg.id DESC")->fetchAll();
        $allGrants = array_values(array_filter($grants, static fn(array $grant): bool => $grant['target_type'] === 'all'));
        $specificGrants = array_values(array_filter($grants, static fn(array $grant): bool => $grant['target_type'] !== 'all'));
        render('admin/access', ['title' => '閲覧権限・グループ', 'employees' => $employees, 'groups' => $groups, 'membersByGroup' => $membersByGroup, 'grants' => $specificGrants, 'allGrants' => $allGrants]);
    }

    private function adminGroupCreate(): void
    {
        Auth::requireAdmin();
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { flash('error', 'グループ名を入力してください。'); redirect('admin/access'); }
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare('INSERT INTO employee_groups (name, created_by, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
            $stmt->execute([$name, Auth::id()]);
            Audit::log('group_created', 'employee_group', (int)$pdo->lastInsertId(), null, ['name' => $name]);
            flash('success', 'グループを作成しました。');
        } catch (\Throwable $e) { flash('error', '同名のグループが既に存在する可能性があります。'); }
        redirect('admin/access');
    }

    private function adminGroupAddMember(): void
    {
        Auth::requireAdmin();
        $groupId = (int)($_POST['group_id'] ?? 0); $empId = (int)($_POST['employee_id'] ?? 0);
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare('INSERT INTO group_memberships (group_id, employee_id, created_at) VALUES (?, ?, NOW())');
            $stmt->execute([$groupId, $empId]);
            Audit::log('group_member_added', 'group_membership', (int)$pdo->lastInsertId(), null, ['group_id' => $groupId, 'employee_id' => $empId]);
            flash('success', 'グループに追加しました。');
        } catch (\Throwable $e) { flash('error', 'すでに所属しているか、対象が不正です。'); }
        redirect('admin/access');
    }

    private function adminGroupRemoveMember(): void
    {
        Auth::requireAdmin();
        $groupId = (int)($_POST['group_id'] ?? 0); $empId = (int)($_POST['employee_id'] ?? 0);
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM group_memberships WHERE group_id=? AND employee_id=?')->execute([$groupId, $empId]);
        Audit::log('group_member_removed', 'group_membership', null, ['group_id' => $groupId, 'employee_id' => $empId], null);
        flash('success', 'グループから外しました。');
        redirect('admin/access');
    }

    private function adminGroupDelete(): void
    {
        Auth::requireAdmin();
        $groupId = (int)($_POST['group_id'] ?? 0);
        // 所属(group_memberships)と当該グループを参照するview_grantsはON DELETE CASCADEで削除される。
        Database::connection()->prepare('DELETE FROM employee_groups WHERE id=?')->execute([$groupId]);
        Audit::log('group_deleted', 'employee_group', $groupId, null, null);
        flash('success', 'グループを削除しました。');
        redirect('admin/access');
    }

    private function adminViewGrantCreate(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $viewerType = ($_POST['viewer_type'] ?? '') === 'group' ? 'group' : 'employee';
        $targetType = ($_POST['target_type'] ?? '') === 'group' ? 'group' : 'employee';
        $viewerEmp = $viewerType === 'employee' ? (int)($_POST['viewer_employee_id'] ?? 0) : null;
        $viewerGrp = $viewerType === 'group' ? (int)($_POST['viewer_group_id'] ?? 0) : null;
        $targetEmp = $targetType === 'employee' ? (int)($_POST['target_employee_id'] ?? 0) : null;
        $targetGrp = $targetType === 'group' ? (int)($_POST['target_group_id'] ?? 0) : null;
        $expires = trim((string)($_POST['expires_on'] ?? ''));
        if (($viewerType === 'employee' && !$viewerEmp) || ($viewerType === 'group' && !$viewerGrp) || ($targetType === 'employee' && !$targetEmp) || ($targetType === 'group' && !$targetGrp)) {
            flash('error', '閲覧する側と対象を指定してください。'); redirect('admin/access');
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO view_grants (viewer_type, viewer_employee_id, viewer_group_id, target_type, target_employee_id, target_group_id, expires_on, granted_by, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            $stmt->execute([$viewerType, $viewerEmp, $viewerGrp, $targetType, $targetEmp, $targetGrp, $expires !== '' ? $expires : null, Auth::id()]);
            Audit::log('view_grant_created', 'view_grant', (int)$pdo->lastInsertId(), null, ['viewer_type' => $viewerType, 'viewer_employee_id' => $viewerEmp, 'viewer_group_id' => $viewerGrp, 'target_type' => $targetType, 'target_employee_id' => $targetEmp, 'target_group_id' => $targetGrp]);
            flash('success', '閲覧権限を付与しました。');
        } catch (\Throwable $e) { flash('error', '閲覧権限を付与できませんでした。'); }
        redirect('admin/access');
    }

    private function adminAllViewGrantCreate(): void
    {
        Auth::requireAdmin();
        $employeeId = (int)($_POST['viewer_employee_id'] ?? 0);
        $expires = trim((string)($_POST['expires_on'] ?? ''));
        if ($employeeId < 1) { flash('error', '全閲覧権限を付与する社員を選んでください。'); redirect('admin/access'); }
        if ($expires !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
            if (!$date || $date->format('Y-m-d') !== $expires || $expires < date('Y-m-d')) {
                flash('error', '有効期限は本日以降の日付を入力してください。'); redirect('admin/access');
            }
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT e.id FROM employees e JOIN users u ON u.employee_id=e.id WHERE e.id=? AND u.status='active'");
        $stmt->execute([$employeeId]);
        if (!$stmt->fetch()) { flash('error', '有効な社員が見つかりません。'); redirect('admin/access'); }
        $stmt = $pdo->prepare("SELECT id FROM view_grants WHERE viewer_type='employee' AND viewer_employee_id=? AND target_type='all' AND (expires_on IS NULL OR expires_on >= CURDATE()) LIMIT 1");
        $stmt->execute([$employeeId]);
        if ($stmt->fetch()) { flash('error', 'この社員にはすでに有効な全閲覧権限があります。'); redirect('admin/access'); }
        try {
            $stmt = $pdo->prepare("INSERT INTO view_grants (viewer_type, viewer_employee_id, viewer_group_id, target_type, target_employee_id, target_group_id, expires_on, granted_by, created_at) VALUES ('employee', ?, NULL, 'all', NULL, NULL, ?, ?, NOW())");
            $stmt->execute([$employeeId, $expires !== '' ? $expires : null, Auth::id()]);
            Audit::log('all_view_grant_created', 'view_grant', (int)$pdo->lastInsertId(), null, ['viewer_employee_id' => $employeeId, 'target_type' => 'all', 'expires_on' => $expires !== '' ? $expires : null]);
            flash('success', '全社員の閲覧権限を付与しました。');
        } catch (\Throwable $e) { flash('error', '全閲覧権限を付与できませんでした。'); }
        redirect('admin/access');
    }

    private function adminViewGrantDelete(): void
    {
        Auth::requireAdmin();
        $id = (int)($_POST['grant_id'] ?? 0);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM view_grants WHERE id=?'); $stmt->execute([$id]); $grant = $stmt->fetch();
        if ($grant) {
            $pdo->prepare('DELETE FROM view_grants WHERE id=?')->execute([$id]);
            Audit::log('view_grant_deleted', 'view_grant', $id, $grant, null);
            flash('success', '閲覧権限を削除しました。');
        }
        redirect('admin/access');
    }

    private function adminAttendance(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $events = $pdo->query('SELECT ae.*, e.full_name FROM attendance_events ae JOIN employees e ON e.id=ae.employee_id ORDER BY ae.occurred_at DESC LIMIT 500')->fetchAll();
        $notices = $pdo->query('SELECT an.*, e.full_name FROM attendance_notices an JOIN employees e ON e.id=an.employee_id ORDER BY an.target_date DESC, an.id DESC LIMIT 500')->fetchAll();
        render('admin/attendance', ['title' => '全社勤怠', 'events' => $events, 'notices' => $notices, 'exportMonth' => date('Y-m')]);
    }

    /**
     * 指定月の全社員出退勤打刻データをCSVでエクスポートする（§9.4）。
     * 管理者専用。実行を監査ログに記録し、サーバーに一時ファイルを残さず直接ストリームする。
     * 初期版の出力項目は 社員ID・氏名・日付・出勤時刻・退勤時刻（勤怠連絡は含めない）。
     */
    private function adminAttendanceExport(): void
    {
        Auth::requireAdmin();
        $month = (string)($_POST['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            flash('error', '対象月を YYYY-MM 形式で指定してください。');
            redirect('admin/attendance');
        }
        // 実行を操作履歴に記録（実行者・日時・対象月）。ストリーム開始前に行う（§14）。
        Audit::log('attendance_csv_exported', 'attendance_export', null, null, ['month' => $month]);

        // 出退勤ペアリング（業務日=出勤日、日跨ぎ退勤対応）は共通サービスに集約。
        $pairs = AttendanceService::pairs($month);

        // サーバーに一時ファイルを残さず直接ストリーム。文字コードはBOM付きUTF-8。
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="attendance_' . $month . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        $output = fopen('php://output', 'w');
        fputcsv($output, ['社員ID', '氏名', '日付', '出勤時刻', '退勤時刻'], ',', '"', '\\', "\r\n");
        foreach ($pairs as $p) {
            $code = ($p['employee_code'] !== null && $p['employee_code'] !== '') ? (string)$p['employee_code'] : (string)$p['employee_id'];
            fputcsv($output, [
                $code,
                $p['full_name'],
                $p['date'],
                $p['clock_in'] ? substr((string)$p['clock_in'], 11, 8) : '',
                $p['clock_out'] ? substr((string)$p['clock_out'], 11, 8) : '',
            ], ',', '"', '\\', "\r\n");
        }
        fclose($output);
        exit;
    }

    /** 月次集計（社員別の出勤日数・勤務時間）。管理者専用（§3.2）。 */
    private function adminAttendanceMonthly(): void
    {
        Auth::requireAdmin();
        $month = (string)($_GET['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        render('admin/monthly', [
            'title' => '月次集計',
            'month' => $month,
            'summary' => AttendanceService::monthlySummary($month),
        ]);
    }
}

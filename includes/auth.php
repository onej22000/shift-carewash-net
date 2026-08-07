<?php
require_once __DIR__ . '/db.php';

// 最終操作からこの秒数が経過したらログアウトさせる（管理者・従業員共通）。
// session.gc_maxlifetime / session.cookie_lifetime（.user.ini）もこの値に揃えること。
const SESSION_IDLE_TIMEOUT_SECONDS = 7200;

function start_session_once(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

// セッションCookieの有効期限を「今から SESSION_IDLE_TIMEOUT_SECONDS 秒後」に毎リクエスト延長する。
// PHPはセッションID発行時以外は自動でSet-Cookieを再送しないため、これを呼ばないと
// Cookie自体はログイン時刻起点で固定の有効期限になり、「最終操作から1時間」にならない。
function refresh_session_cookie(): void
{
    if (!ini_get('session.use_cookies') || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        session_id(),
        time() + SESSION_IDLE_TIMEOUT_SECONDS,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

function attempt_login(string $loginId, string $password, string $expectedRole): ?array
{
    $pdo = getPdo();
    $stmt = $pdo->prepare(
        'SELECT id, name, role, login_id, password_hash, status, is_shared_account
         FROM employees
         WHERE login_id = :login_id AND role = :role AND status = "active"
         LIMIT 1'
    );
    $stmt->execute([
        ':login_id' => $loginId,
        ':role' => $expectedRole,
    ]);
    $employee = $stmt->fetch();

    if ($employee === false) {
        return null;
    }
    if ($employee['password_hash'] === null || !password_verify($password, $employee['password_hash'])) {
        return null;
    }

    return $employee;
}

function login_employee(array $employee): void
{
    start_session_once();
    session_regenerate_id(true);
    $_SESSION['employee_id'] = (int) $employee['id'];
    $_SESSION['role'] = $employee['role'];
    $_SESSION['name'] = $employee['name'];
    $_SESSION['last_activity_at'] = time();
    refresh_session_cookie();
}

// 共用アカウント（is_shared_account=1）はログイン後、通常のダッシュボードではなく
// 専用の画面（集荷チェックリスト。人数確認・返却準備完了の登録はそこからリンクする）へ遷移させる。
// login_idを条件に使うと将来IDを変更したときに気づかず壊れるため、is_shared_accountで判定する。
function staff_landing_page(array $employee): string
{
    return ((int) ($employee['is_shared_account'] ?? 0) === 1)
        ? '/staff/jiro_dashboard.php'
        : '/staff/dashboard.php';
}

function current_employee(): ?array
{
    start_session_once();

    if (!isset($_SESSION['employee_id'])) {
        return null;
    }

    // 最終操作から SESSION_IDLE_TIMEOUT_SECONDS 秒を超えていれば強制ログアウトする。
    // PHPのファイルGC（gc_maxlifetime）に依存すると、Xserver側のGC挙動次第で
    // 想定より早くセッションが失われる可能性があるため、アプリ側でも明示的に判定する。
    if (isset($_SESSION['last_activity_at'])
        && (time() - (int) $_SESSION['last_activity_at']) > SESSION_IDLE_TIMEOUT_SECONDS
    ) {
        logout_employee();
        return null;
    }

    $_SESSION['last_activity_at'] = time();
    refresh_session_cookie();

    $pdo = getPdo();
    $stmt = $pdo->prepare(
        'SELECT id, name, role, login_id, status, is_shared_account
         FROM employees
         WHERE id = :id AND status = "active"
         LIMIT 1'
    );
    $stmt->execute([':id' => $_SESSION['employee_id']]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function require_login(string $requiredRole): array
{
    $employee = current_employee();

    if ($employee === null || $employee['role'] !== $requiredRole) {
        $loginPage = $requiredRole === 'admin' ? '/admin/login.php' : '/staff/login.php';
        header('Location: ' . $loginPage);
        exit;
    }

    return $employee;
}

function logout_employee(): void
{
    start_session_once();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function csrf_token(): string
{
    start_session_once();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    start_session_once();
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function set_flash(string $type, string $message): void
{
    start_session_once();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pop_flash(): ?array
{
    start_session_once();
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

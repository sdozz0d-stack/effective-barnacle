<?php
// ============================================================
// login.php — Sign-in page
//
// Improvements:
// - Preserves entered login identifier on failed attempts (UX)
// - Centralizes remember-me cookie options and clears invalid tokens
// - Adds accessibility/input hints and safer password-toggle scripting
// - Keeps lockout, CSRF, remember-me rotation, and redirect behavior intact
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

ensure_auth_schema($conn);
purge_expired_tokens($conn);

if (is_logged_in()) {
    header("Location: " . landing_page_for(current_role()));
    exit;
}

$error             = flash_get('error') ?? '';
$success           = flash_get('success');
$loginValue        = '';
$rememberCookieName = 'remember_admin';
$rememberCookieBase = [
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
];

// ---------- Auto-login via remember-me cookie ----------
if (!empty($_COOKIE[$rememberCookieName])) {
    $rememberToken = (string)$_COOKIE[$rememberCookieName];
    $user = verify_remember_token($conn, $rememberToken);
    if ($user) {
        delete_remember_token($conn, $rememberToken);
        $newToken = issue_remember_token($conn, (int)$user['id'], 30);
        setcookie($rememberCookieName, $newToken, $rememberCookieBase + [
            'expires' => time() + (86400 * 30),
        ]);

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['last_activity'] = time();

        header("Location: " . landing_page_for($user['role']));
        exit;
    }

    // Invalid or expired token: remove DB record (if present) and clear cookie.
    delete_remember_token($conn, $rememberToken);
    setcookie($rememberCookieName, '', $rememberCookieBase + [
        'expires' => time() - 3600,
    ]);
}

$lockSecondsLeft   = is_locked_out($conn);
$loginAttemptCount = login_attempt_count($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginValue = trim((string)($_POST['login'] ?? ''));
    $loginValue = mb_substr($loginValue, 0, 254);

    if ($lockSecondsLeft !== null) {
        // Locked out: keep message in sync with current remaining time.
        $error = '';
    } elseif (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $user = null;
        if ($loginValue !== '') {
            $user = find_user_by_email($conn, strtolower($loginValue));
            if ($user === null) {
                $user = find_user_by_username($conn, mb_substr($loginValue, 0, 100));
            }
        }

        if ($user && password_verify($password, $user['password_hash'])) {
            reset_login_attempts($conn);
            session_regenerate_id(true);
            $_SESSION['user_id']       = (int)$user['id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['csrf_token']);

            if (!empty($_POST['remember'])) {
                $token = issue_remember_token($conn, (int)$user['id'], 30);
                setcookie($rememberCookieName, $token, $rememberCookieBase + [
                    'expires' => time() + (86400 * 30),
                ]);
            } else {
                setcookie($rememberCookieName, '', $rememberCookieBase + [
                    'expires' => time() - 3600,
                ]);
            }

            header('Location: ' . landing_page_for($user['role']));
            exit;
        }

        register_failed_attempt($conn);
        // Small delay to slow brute-force attempts without heavily impacting UX.
        sleep(1);
        $error = 'Wrong username or password.';
        $lockSecondsLeft = is_locked_out($conn);
        $loginAttemptCount = login_attempt_count($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="mockup.css?v=<?php echo filemtime(__DIR__ . '/mockup.css'); ?>">
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancement.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancement.js'); ?>" defer></script>
    <style>
        #login {
            min-height: 100vh;
            padding: clamp(20px, 5vh, 48px) 18px;
            background-color: #eef5f0;
            background-image:
                radial-gradient(ellipse 70% 45% at 12% 0%, rgba(16, 124, 65, 0.16), transparent 60%),
                radial-gradient(ellipse 55% 40% at 100% 100%, rgba(16, 124, 65, 0.1), transparent 55%),
                linear-gradient(160deg, #f6faf7 0%, #e7f0ea 52%, #edf4ef 100%);
        }
        #login .lc {
            width: min(100%, 440px);
            padding: 40px 38px 32px;
            background: #ffffff;
            border: 1px solid rgba(16, 124, 65, .14);
            border-top: 4px solid var(--br);
            border-radius: 20px;
            box-shadow: 0 20px 55px rgba(27, 55, 39, .12), 0 0 0 1px rgba(255, 255, 255, .5) inset;
        }
        #login .lc h2 { color: #173b27; font-size: 28px; letter-spacing: -.035em; }
        #login .lc .sub { margin-bottom: 30px; color: #66756b; }
        #login .fl { margin-bottom: 21px; }
        #login .fl label { color: #344b3b; }
        #login .fl input[type="text"], #login .pw input { min-height: 48px; border-color: #d3ded6; background: #fcfefc; }
        #login .fl input::placeholder, #login .pw input::placeholder { color: #89968d; }
        #login .fl input:focus, #login .pw input:focus { border-color: var(--br); box-shadow: 0 0 0 4px rgba(16,124,65,.11); }
        #login .pt { min-width: 34px; min-height: 34px; align-items: center; justify-content: center; border-radius: 7px; color: #52665a; }
        #login .pt:hover { background: #edf5ef; color: var(--bd); }
        #login .rm { margin: 2px 0 22px; color: #536159; }
        #login .rm input { width: 16px; height: 16px; accent-color: var(--br); }
        #login .sb { min-height: 49px; border-radius: 9px; box-shadow: 0 5px 12px rgba(16,124,65,.16); }
        #login .sb:hover:not(:disabled) { box-shadow: 0 7px 16px rgba(16,124,65,.22); }
        #login .sb:disabled { cursor: not-allowed; opacity: .58; box-shadow: none; }
        #login .sw { margin-top: 16px; color: #5e6b62; }
        #login .sw a { text-underline-offset: 3px; }
        #login .sw a:hover { color: var(--bd); text-decoration: underline; }
        #login .bk { margin-top: 22px; color: #6a776e; text-underline-offset: 3px; }
        #login .bk:hover { color: var(--br); text-decoration: underline; }
        .alert { padding: 10px 14px; border: 1px solid transparent; border-radius: 8px; margin-bottom: 18px; font-size: 13px; }
        .alert-error { background: #fdecea; border-color: #f5c2c0; color: #7a1f16; }
        .alert-warn { background: #fff6e0; border-color: #f0dca0; color: #7a5a00; }
        .alert-success { background: #e6f4ea; border-color: #b7e1cd; color: #137333; }
        .login-attempt-warning { padding: 10px 14px; margin-bottom: 18px; border: 1px solid #f0dca0; border-radius: 8px; background: #fff6e0; color: #7a5a00; font-size: 13px; }
        @media (max-width: 480px) {
            #login { padding: 18px 14px; }
            #login .lc { padding: 30px 22px 24px; border-radius: 16px; }
            #login .lc h2 { font-size: 25px; }
        }
        html[data-theme="dark"] #login .lc h2 { color: #f3f7f4; }
        html[data-theme="dark"] #login .lc .sub,
        html[data-theme="dark"] #login .rm,
        html[data-theme="dark"] #login .sw,
        html[data-theme="dark"] #login .bk { color: #b8c9be; }
        html[data-theme="dark"] #login .fl label { color: #e8f0eb; }
        html[data-theme="dark"] #login .fl input[type="text"],
        html[data-theme="dark"] #login .pw input { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
        html[data-theme="dark"] #login .fl input::placeholder,
        html[data-theme="dark"] #login .pw input::placeholder { color: #8fa197; }
        html[data-theme="dark"] #login .pt { color: #b8c9be; }
        html[data-theme="dark"] #login .pt:hover { background: #2d3d34; color: #9fe0b5; }
        html[data-theme="dark"] .alert-error { background: #2a171b; border-color: #5c3036; color: #ffb4ab; }
        html[data-theme="dark"] .alert-warn,
        html[data-theme="dark"] .login-attempt-warning { background: #262010; border-color: #5a4a20; color: #f6d365; }
        html[data-theme="dark"] .alert-success { background: #14261b; border-color: #28543a; color: #9fe0b5; }
    </style>
</head>
<body>
    <section id="login"><div class="lc">
        <h2>Sign In</h2>
        <p class="sub">DENR Interview Scoring Sheet</p>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" role="status" aria-live="polite"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!empty($error) && $lockSecondsLeft === null): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php elseif ($lockSecondsLeft !== null): ?>
            <div class="alert alert-warn" role="alert">
                Too many attempts. Try again in <?php echo (int)max(1, (int)ceil($lockSecondsLeft / 60)); ?> minute(s).
            </div>
        <?php endif; ?>
        <?php if ($lockSecondsLeft === null && $loginAttemptCount === AUTH_MAX_ATTEMPTS - 1): ?>
            <div class="login-attempt-warning" role="status" aria-live="polite">You have one attempt remaining. After another unsuccessful attempt, access will be locked for <?php echo (int)AUTH_LOCKOUT_MINUTES; ?> minutes.</div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="fl">
                <label for="login">Email or username</label>
                <input type="text" id="login" name="login" required
                       value="<?php echo htmlspecialchars($loginValue, ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="you@example.com or username" autocomplete="username"
                       autocapitalize="none" spellcheck="false" autocorrect="off"
                       maxlength="254"
                       <?php echo $lockSecondsLeft === null ? 'autofocus' : ''; ?>
                       <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
            </div>

            <div class="fl">
                <label for="password">Password</label>
                <div class="pw">
                    <input type="password" id="password" name="password" required
                           placeholder="Your password" autocomplete="current-password"
                           <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                    <button type="button" class="pt" id="pwToggle" aria-label="Show password" aria-controls="password" aria-pressed="false"
                            <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                        <svg id="eyeOpen" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg id="eyeClosed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false" style="display:none;">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a18.7 18.7 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>
            </div>

            <label class="rm">
                <input type="checkbox" name="remember" value="1" <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                Remember me on this device
            </label>

            <button type="submit" class="sb" <?php echo $lockSecondsLeft !== null ? 'disabled' : ''; ?>>
                Log In
            </button>
        </form>

        <div class="sw">Forgot your password? <a href="password_reset.php">Reset it by email</a></div>
        <div class="sw">Don't have an account? <a href="register.php">Sign Up</a></div>
        <a href="index.php" class="bk">&larr; Return to Scoring Sheet</a>
    </div></section>

    <script>
        (() => {
            const toggle = document.getElementById('pwToggle');
            const input = document.getElementById('password');
            const eyeOpen = document.getElementById('eyeOpen');
            const eyeClosed = document.getElementById('eyeClosed');
            if (!toggle || !input || !eyeOpen || !eyeClosed) return;

            toggle.addEventListener('click', () => {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                eyeOpen.style.display = show ? 'none' : 'block';
                eyeClosed.style.display = show ? 'block' : 'none';
                toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                toggle.setAttribute('aria-pressed', String(show));
            });
        })();
    </script>
</body>
</html>

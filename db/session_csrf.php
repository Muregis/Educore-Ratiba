<?php
declare(strict_types=1);

/**
 * Session + CSRF helpers that work on Render (HTTPS proxy, ephemeral disk).
 * Required from db/db.php so login and admin forms share one implementation.
 */

if (!function_exists('bootstrapSession')) {
    function bootstrapSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https')
        );
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

if (!function_exists('getCsrfToken')) {
    function getCsrfToken(): string
    {
        bootstrapSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = (string) $_SESSION['csrf_token'];
        if (!headers_sent()) {
            $secure = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            );
            setcookie('csrf_token', $token, [
                'expires'  => 0,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return $token;
    }
}

if (!function_exists('csrfField')) {
    function csrfField(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(getCsrfToken()) . '">';
    }
}

if (!function_exists('verifyCsrf')) {
    function verifyCsrf(): void
    {
        bootstrapSession();
        $submitted = (string) ($_POST['_csrf_token'] ?? '');
        $fromSession = (string) ($_SESSION['csrf_token'] ?? '');
        $fromCookie  = (string) ($_COOKIE['csrf_token'] ?? '');

        $ok = false;
        if ($submitted !== '' && $fromSession !== '' && hash_equals($fromSession, $submitted)) {
            $ok = true;
        } elseif ($submitted !== '' && $fromCookie !== '' && hash_equals($fromCookie, $submitted)) {
            $ok = true;
            $_SESSION['csrf_token'] = $submitted;
        }

        if (!$ok) {
            http_response_code(403);
            die(
                '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Session expired</title></head>'
                . '<body style="font-family:system-ui;max-width:420px;margin:60px auto;padding:0 16px">'
                . '<h1>Session expired</h1>'
                . '<p>Your security token expired or the page was open too long (common after a deploy).</p>'
                . '<p><a href="/login.php">Return to login</a> and try again.</p>'
                . '</body></html>'
            );
        }
    }
}

<?php
// router for `php -S` local testing: serve static files if they exist,
// otherwise route to the requested .php file (or index.php).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;

if ($path === '/' || $path === '') {
    require $root . '/login.php';
    return true;
}

$file = $root . $path;
if (is_file($file)) {
    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?)$/i', $file)) {
        return false; // serve static directly
    }
    require $file;
    return true;
}

http_response_code(404);
echo '404';
return true;

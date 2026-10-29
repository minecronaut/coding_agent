<?php
/**
 * Router for PHP's built-in development server.
 *
 *   php -S localhost:8000 router.php
 *
 * The built-in server ignores .htaccess, so without this the contents of
 * data/ (including password hashes and encrypted API keys) would be served
 * as plain files. Every other request falls through to normal handling.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

$blocked = preg_match('#(^|/)data(/|$)#i', $path)
    || preg_match('#(^|/)\.#', $path)
    || preg_match('#\.(key|tmp|log|json\.bak)$#i', $path);

if ($blocked) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    return true;
}

return false;

<?php
declare(strict_types=1);

/** Escape for HTML. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 400, array $extra = []): never
{
    json_out(array_merge(['ok' => false, 'error' => $message], $extra), $code);
}

function body_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('Malformed JSON body.', 400);
    }
    return $data;
}

function str_field(array $src, string $key, int $max = 5000, string $default = ''): string
{
    $v = $src[$key] ?? $default;
    if (!is_string($v)) {
        return $default;
    }
    $v = trim($v);
    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_name('cairnsid');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_start();
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(?string $supplied): bool
{
    session_boot();
    $good = $_SESSION['csrf'] ?? '';
    return is_string($supplied) && $good !== '' && hash_equals($good, $supplied);
}

/** Guard for every api/*.php entry point. */
function api_boot(): void
{
    session_boot();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? null);
        if (!csrf_ok(is_string($token) ? $token : null)) {
            fail('Session expired. Refresh the page and try again.', 419);
        }
    }
}

/* ------------------------------------------------------------------ */
/* Secrets at rest                                                     */
/* ------------------------------------------------------------------ */

function encrypt_secret(string $plain): string
{
    $key = hash('sha256', app_secret(), true);
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return base64_encode($iv . $tag . $ct);
}

function decrypt_secret(string $blob): string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) {
        return '';
    }
    $key = hash('sha256', app_secret(), true);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $out = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $out === false ? '' : $out;
}

function mask_key(string $key): string
{
    $len = strlen($key);
    if ($len === 0) {
        return '';
    }
    if ($len <= 8) {
        return str_repeat('•', $len);
    }
    return substr($key, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($key, -4);
}

/* ------------------------------------------------------------------ */
/* Server-sent events                                                  */
/* ------------------------------------------------------------------ */

function sse_start(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    echo ": stream open\n\n";
    flush();
}

function sse_event(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

/* ------------------------------------------------------------------ */
/* Misc                                                                */
/* ------------------------------------------------------------------ */

function now_iso(): string
{
    return gmdate('c');
}

function short_id(int $bytes = 8): string
{
    return bin2hex(random_bytes($bytes));
}

/** Strip a fenced ```json block and pull out the outermost object. */
function extract_json(string $text): ?array
{
    $text = trim($text);

    if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $text, $m)) {
        $text = trim($m[1]);
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $candidate = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($candidate, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    // Common model slip: trailing commas.
    $repaired = preg_replace('/,\s*([}\]])/', '$1', $candidate);
    $decoded = json_decode((string)$repaired, true);
    return is_array($decoded) ? $decoded : null;
}

function clamp_text(mixed $v, int $max): string
{
    $s = is_string($v) ? trim($v) : '';
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
}

/** @return string[] */
function str_list(mixed $v, int $maxItems = 8, int $maxLen = 200): array
{
    if (!is_array($v)) {
        return [];
    }
    $out = [];
    foreach ($v as $item) {
        if (is_string($item)) {
            $t = clamp_text($item, $maxLen);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        if (count($out) >= $maxItems) {
            break;
        }
    }
    return $out;
}

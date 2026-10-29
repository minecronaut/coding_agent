<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
$body = body_json();

$history = is_array($body['messages'] ?? null) ? $body['messages'] : [];
$context = is_array($body['context'] ?? null) ? $body['context'] : [];
$settings = llm_settings($user);

try {
    llm_require_key($settings);
    $messages = msgs_chat($user, $history, $context);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 400);
}

// Free the session lock so other tabs aren't blocked while we stream.
session_write_close();

$useStream = !isset($body['stream']) || $body['stream'] !== false;

if (!$useStream) {
    try {
        $res = llm_complete($settings, $messages, ['temperature' => $settings['temperature'], 'max_tokens' => 1800]);
        Store::update($user['id'], function (array &$u) {
            $u['stats']['messages'] = (int)($u['stats']['messages'] ?? 0) + 1;
        });
        json_out(['ok' => true, 'text' => (string)$res['text']]);
    } catch (RuntimeException $e) {
        fail($e->getMessage(), 400);
    }
}

sse_start();
$full = '';

try {
    $text = llm_stream($settings, $messages, function (string $chunk) use (&$full) {
        if (connection_aborted()) {
            throw new RuntimeException('__client_gone');
        }
        $full .= $chunk;
        sse_event('delta', ['t' => $chunk]);
    }, ['temperature' => $settings['temperature'], 'max_tokens' => 1800]);

    sse_event('done', ['text' => $text]);
    sse_event('end', []);
} catch (Throwable $e) {
    if ($e->getMessage() !== '__client_gone') {
        sse_event('error', ['message' => $e->getMessage()]);
    }
}

if ($full !== '') {
    Store::update($user['id'], function (array &$u) use ($full) {
        $u['stats']['messages'] = (int)($u['stats']['messages'] ?? 0) + 1;
        // Keep a very light trail of what the tutor has explained.
        $u['memory']['notes'] = array_slice(
            array_merge($u['memory']['notes'] ?? [], ['Explained: ' . mb_substr(preg_replace('/\s+/', ' ', strip_tags($full)) ?? '', 0, 140)]),
            -10
        );
    });
}

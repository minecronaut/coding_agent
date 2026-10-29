<?php
declare(strict_types=1);

/**
 * Provider-agnostic chat layer.
 *
 * Every provider is normalised down to { role, content } message arrays.
 * Streaming is native for OpenAI-compatible endpoints (NVIDIA NIM, Groq,
 * OpenRouter, DeepSeek, Ollama, ...). Anthropic and Gemini are streamed by
 * chunking a completed response, which keeps this file small and predictable.
 */

/**
 * Build a callable settings bundle from a stored user record.
 *
 * @param array $override  transient values (e.g. an unsaved key from the UI)
 */
function llm_settings(array $user, array $override = []): array
{
    $s = $user['settings'] ?? [];
    $providerId = (string)($override['provider'] ?? $s['provider'] ?? 'nvidia');
    $p = provider($providerId);
    if ($p === null) {
        throw new RuntimeException('Unknown provider: ' . $providerId);
    }

    $key = '';
    if (!empty($override['api_key'])) {
        $key = (string)$override['api_key'];
    } elseif (!empty($s['key_enc'])) {
        $key = decrypt_secret((string)$s['key_enc']);
    }

    $base = (string)($override['base_url'] ?? $s['base_url'] ?? '');
    if ($base === '') {
        $base = (string)($p['base'] ?? '');
    }

    $model = trim((string)($override['model'] ?? $s['model'] ?? ''));
    if ($model === '') {
        $model = (string)($p['models'][0] ?? '');
    }

    return [
        'provider'    => $providerId,
        'kind'        => (string)$p['kind'],
        'base'        => rtrim($base, '/'),
        'key'         => $key,
        'model'       => $model,
        'temperature' => (float)($s['temperature'] ?? 0.7),
        'max_tokens'  => (int)($override['max_tokens'] ?? 4096),
    ];
}

function llm_require_key(array $s): void
{
    if ($s['key'] === '' && $s['provider'] !== 'ollama' && $s['provider'] !== 'custom') {
        throw new RuntimeException('No API key configured. Add one in Settings.');
    }
    if ($s['model'] === '') {
        throw new RuntimeException('No model selected. Pick one in Settings.');
    }
    if ($s['base'] === '') {
        throw new RuntimeException('No API base URL configured.');
    }
}

function curl_handle(string $url, int $timeout = 150): CurlHandle
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialise HTTP client.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Cairn/1.0',
    ]);
    return $ch;
}

function http_post_json(string $url, array $headers, array $body, int $timeout = 150): array
{
    $ch = curl_handle($url, $timeout);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Network error talking to the model provider: ' . $err);
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Provider returned a non-JSON response (HTTP ' . $status . ').');
    }
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? $data['message'] ?? $data['detail'] ?? ('HTTP ' . $status);
        if (is_array($msg)) {
            $msg = json_encode($msg);
        }
        throw new RuntimeException('Provider error: ' . mb_substr((string)$msg, 0, 400));
    }
    return $data;
}

/** @param array<int,array{role:string,content:string}> $messages */
function llm_complete(array $s, array $messages, array $opts = []): array
{
    llm_require_key($s);
    $temperature = (float)($opts['temperature'] ?? $s['temperature']);
    $maxTokens = (int)($opts['max_tokens'] ?? $s['max_tokens']);
    $jsonMode = (bool)($opts['json'] ?? false);

    if ($s['kind'] === 'anthropic') {
        [$system, $turns] = split_system($messages);
        $body = [
            'model'       => $s['model'],
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'messages'    => $turns,
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }
        $data = http_post_json($s['base'] . '/messages', [
            'x-api-key: ' . $s['key'],
            'anthropic-version: 2023-06-01',
        ], $body);
        $text = '';
        foreach (($data['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'text') {
                $text .= (string)$part['text'];
            }
        }
        return ['text' => $text, 'raw' => $data];
    }

    if ($s['kind'] === 'google') {
        [$system, $turns] = split_system($messages);
        $contents = [];
        foreach ($turns as $t) {
            $contents[] = [
                'role'  => $t['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $t['content']]],
            ];
        }
        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'      => $temperature,
                'maxOutputTokens'  => $maxTokens,
                'responseMimeType' => $jsonMode ? 'application/json' : 'text/plain',
            ],
        ];
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        $url = $s['base'] . '/models/' . rawurlencode($s['model']) . ':generateContent?key=' . rawurlencode($s['key']);
        $data = http_post_json($url, [], $body);
        $text = '';
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        return ['text' => $text, 'raw' => $data];
    }

    // OpenAI-compatible
    $body = [
        'model'       => $s['model'],
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
    ];
    if ($jsonMode) {
        $body['response_format'] = ['type' => 'json_object'];
    }
    $data = http_post_json($s['base'] . '/chat/completions', [
        'Authorization: Bearer ' . $s['key'],
    ], $body);

    $text = $data['choices'][0]['message']['content'] ?? '';
    if (is_array($text)) { // some providers return content parts
        $text = implode('', array_map(static fn($p) => is_array($p) ? (string)($p['text'] ?? '') : (string)$p, $text));
    }
    return ['text' => (string)$text, 'raw' => $data];
}

/** @return array{0:string,1:array} */
function split_system(array $messages): array
{
    $system = '';
    $turns = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') {
            $system .= ($system === '' ? '' : "\n\n") . $m['content'];
        } else {
            $turns[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']];
        }
    }
    if ($turns === []) {
        $turns[] = ['role' => 'user', 'content' => 'Hello'];
    }
    return [$system, $turns];
}

/**
 * Stream a chat completion. $onDelta receives plain text fragments.
 * Returns the full text at the end.
 */
function llm_stream(array $s, array $messages, callable $onDelta, array $opts = []): string
{
    llm_require_key($s);

    if ($s['kind'] !== 'openai') {
        $res = llm_complete($s, $messages, $opts + ['json' => false]);
        $text = (string)$res['text'];
        foreach (str_split($text, 24) as $i => $chunk) {
            if ($i > 0) {
                usleep(9000);
            }
            $onDelta($chunk);
        }
        return $text;
    }

    $body = [
        'model'       => $s['model'],
        'messages'    => $messages,
        'temperature' => (float)($opts['temperature'] ?? $s['temperature']),
        'max_tokens'  => (int)($opts['max_tokens'] ?? $s['max_tokens']),
        'stream'      => true,
    ];

    $ch = curl_handle($s['base'] . '/chat/completions');
    $buffer = '';
    $full = '';
    $failure = null;

    curl_setopt_array($ch, [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Authorization: Bearer ' . $s['key'],
        ],
        CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$buffer, &$full, $onDelta, &$failure): int {
            $buffer .= $chunk;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);
                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }
                if (!str_starts_with($line, 'data:')) {
                    // Some gateways return a plain JSON error body.
                    if (str_starts_with($line, '{')) {
                        $j = json_decode($line, true);
                        $failure = $j['error']['message'] ?? $j['message'] ?? null;
                    }
                    continue;
                }
                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    continue;
                }
                $j = json_decode($payload, true);
                if (!is_array($j)) {
                    continue;
                }
                if (isset($j['error'])) {
                    $failure = is_string($j['error']) ? $j['error'] : (string)($j['error']['message'] ?? 'Provider error');
                    continue;
                }
                $delta = $j['choices'][0]['delta']['content'] ?? null;
                if (is_string($delta) && $delta !== '') {
                    $full .= $delta;
                    $onDelta($delta);
                }
            }
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($ok === false) {
        throw new RuntimeException('Network error while streaming: ' . $err);
    }
    if ($failure !== null) {
        throw new RuntimeException('Provider error: ' . mb_substr((string)$failure, 0, 400));
    }
    if ($status >= 400 && $full === '') {
        throw new RuntimeException('Provider returned HTTP ' . $status . '.');
    }
    return $full;
}

/** List models the configured endpoint advertises. */
function llm_models(array $s): array
{
    $headers = [];
    $url = '';
    if ($s['kind'] === 'google') {
        $url = $s['base'] . '/models?key=' . rawurlencode($s['key']);
    } elseif ($s['kind'] === 'anthropic') {
        $url = $s['base'] . '/models?limit=100';
        $headers = ['x-api-key: ' . $s['key'], 'anthropic-version: 2023-06-01'];
    } else {
        $url = $s['base'] . '/models';
        if ($s['key'] !== '') {
            $headers = ['Authorization: Bearer ' . $s['key']];
        }
    }

    $ch = curl_handle($url, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = json_decode((string)$raw, true);
    if ($status >= 400 || !is_array($data)) {
        throw new RuntimeException('Could not list models (HTTP ' . $status . ').');
    }

    $out = [];
    foreach (($data['data'] ?? $data['models'] ?? []) as $m) {
        if (is_string($m)) {
            $out[] = $m;
        } elseif (is_array($m)) {
            $id = $m['id'] ?? $m['name'] ?? null;
            if (is_string($id)) {
                $out[] = preg_replace('/^models\//', '', $id);
            }
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

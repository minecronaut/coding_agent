<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
$body = body_json();

$payload = [
    'kind'   => clamp_text($body['kind'] ?? 'answer', 40),
    'task'   => clamp_text($body['task'] ?? '', 1200),
    'answer' => clamp_text($body['answer'] ?? '', 4000),
    'code'   => clamp_text($body['code'] ?? '', 4000),
    'output' => clamp_text($body['output'] ?? '', 2000),
];

if ($payload['answer'] === '' && $payload['code'] === '') {
    fail('There is nothing to grade yet.');
}

try {
    $res = llm_complete(llm_settings($user), msgs_grade($user, $payload), [
        'json'        => true,
        'temperature' => 0.2,
        'max_tokens'  => 900,
    ]);
    $raw = extract_json((string)$res['text']);
    if ($raw === null) {
        fail('The grader returned something unreadable. Try again.');
    }

    $score = max(0.0, min(1.0, (float)($raw['score'] ?? 0)));
    $out = [
        'score'       => round($score, 2),
        'passed'      => (bool)($raw['passed'] ?? $score >= 0.7),
        'feedback'    => clamp_text($raw['feedback'] ?? '', 1200),
        'strengths'   => str_list($raw['strengths'] ?? [], 4, 200),
        'fixes'       => str_list($raw['fixes'] ?? [], 4, 240),
        'misconception' => clamp_text($raw['misconception'] ?? '', 400),
    ];

    // Remember the mistake so future lessons can target it.
    $updated = Store::update($user['id'], function (array &$u) use ($out, $score, $payload) {
        $u['memory'] = is_array($u['memory'] ?? null) ? $u['memory'] : [];
        $u['memory']['facts'] = is_array($u['memory']['facts'] ?? null) ? $u['memory']['facts'] : [];
        $task = trim((string)($payload['task'] ?? ''));
        $label = $task !== '' ? mb_substr($task, 0, 110) : 'an exercise';
        if (!$out['passed'] && $out['misconception'] !== '') {
            $u['memory']['struggles'][] = mb_substr($out['misconception'], 0, 160);
            $u['memory']['struggles'] = array_slice(array_values(array_unique($u['memory']['struggles'])), -12);
            memory_add($u, 'Misconception: ' . $out['misconception'], 'struggle', 'grader');
        } elseif ($out['passed'] && $score >= 0.85) {
            memory_add($u, 'Handled "' . $label . '" well when graded.', 'strength', 'grader');
        }
    });

    json_out([
        'ok'    => true,
        'state' => $updated !== null ? state_payload($updated) : null,
    ] + $out);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 400);
}

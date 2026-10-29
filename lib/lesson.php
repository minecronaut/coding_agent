<?php
declare(strict_types=1);

/**
 * Turns whatever the model produced into something the front end can trust.
 * Answer keys stay on the server; the browser only ever receives prompts.
 */

function lesson_normalize(array $raw): array
{
    $title = clamp_text($raw['title'] ?? '', 120);
    $objective = clamp_text($raw['objective'] ?? '', 300);
    if ($title === '') {
        throw new RuntimeException('The lesson came back without a title. Try generating again.');
    }

    $blocksIn = is_array($raw['blocks'] ?? null) ? $raw['blocks'] : [];
    $blocks = [];
    $n = 0;
    foreach ($blocksIn as $b) {
        if (!is_array($b)) {
            continue;
        }
        $block = lesson_block($b, $n);
        if ($block !== null) {
            $blocks[] = $block;
            $n++;
        }
        if (count($blocks) >= 10) {
            break;
        }
    }
    if ($blocks === []) {
        throw new RuntimeException('The lesson came back with no usable content. Try again.');
    }

    return [
        'title'     => $title,
        'objective' => $objective,
        'minutes'   => max(3, min(40, (int)($raw['minutes'] ?? 10))),
        'why'       => clamp_text($raw['why'] ?? '', 300),
        'blocks'    => $blocks,
        'takeaways' => str_list($raw['takeaways'] ?? [], 5, 160),
        'next'      => clamp_text($raw['next'] ?? '', 240),
        'generated' => now_iso(),
    ];
}

function lesson_block(array $b, int $index): ?array
{
    $type = (string)($b['type'] ?? '');
    $id = 'b' . $index;

    switch ($type) {
        case 'text':
        case 'callout':
            $md = clamp_text($b['md'] ?? '', 3000);
            if ($md === '') {
                return null;
            }
            if ($type === 'text') {
                return ['id' => $id, 'type' => 'text', 'md' => $md];
            }
            $tone = in_array(($b['tone'] ?? ''), ['key', 'tip', 'warn'], true) ? $b['tone'] : 'tip';
            return ['id' => $id, 'type' => 'callout', 'tone' => $tone, 'md' => $md];

        case 'code':
            $starter = (string)($b['starter'] ?? '');
            if (trim($starter) === '') {
                return null;
            }
            $contains = str_list($b['expect']['contains'] ?? [], 6, 200);
            return [
                'id'      => $id,
                'type'    => 'code',
                'task'    => clamp_text($b['task'] ?? 'Run this, then change something and predict what happens.', 400),
                'starter' => mb_substr($starter, 0, 4000),
                'check'   => ['kind' => 'output', 'contains' => $contains],
            ];

        case 'mcq':
            $options = str_list($b['options'] ?? [], 6, 300);
            $answer = $b['answer'] ?? null;
            if (count($options) < 2 || !is_numeric($answer)) {
                return null;
            }
            $answer = (int)$answer;
            if ($answer < 0 || $answer >= count($options)) {
                return null;
            }
            return [
                'id'       => $id,
                'type'     => 'mcq',
                'question' => clamp_text($b['question'] ?? '', 600),
                'options'  => $options,
                'answer'   => $answer,
                'explain'  => clamp_text($b['explain'] ?? '', 600),
            ];

        case 'fill':
            $code = (string)($b['code'] ?? '');
            $blanks = [];
            foreach ((array)($b['blanks'] ?? []) as $blank) {
                if (!is_array($blank)) {
                    continue;
                }
                $ans = clamp_text($blank['answer'] ?? '', 80);
                if ($ans === '') {
                    continue;
                }
                $choices = str_list($blank['choices'] ?? [], 6, 80);
                $choices[] = $ans;
                $choices = array_values(array_unique($choices));
                shuffle($choices);
                $blanks[] = ['answer' => $ans, 'choices' => $choices];
            }
            $slots = substr_count($code, '___');
            if ($slots === 0 || $slots !== count($blanks)) {
                return null;
            }
            return [
                'id'      => $id,
                'type'    => 'fill',
                'prompt'  => clamp_text($b['prompt'] ?? '', 400),
                'code'    => mb_substr($code, 0, 2500),
                'blanks'  => $blanks,
                'explain' => clamp_text($b['explain'] ?? '', 600),
            ];

        case 'drag':
            $tokens = str_list($b['tokens'] ?? [], 10, 60);
            if (count($tokens) < 2) {
                return null;
            }
            $targets = [];
            foreach ((array)($b['targets'] ?? []) as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $ans = clamp_text($t['answer'] ?? '', 60);
                if ($ans === '' || !in_array($ans, $tokens, true)) {
                    continue;
                }
                $targets[] = [
                    'label'  => clamp_text($t['label'] ?? '', 200),
                    'answer' => $ans,
                ];
            }
            if ($targets === []) {
                return null;
            }
            return [
                'id'      => $id,
                'type'    => 'drag',
                'prompt'  => clamp_text($b['prompt'] ?? '', 400),
                'tokens'  => $tokens,
                'targets' => $targets,
                'explain' => clamp_text($b['explain'] ?? '', 600),
            ];

        case 'task':
            $prompt = clamp_text($b['prompt'] ?? '', 900);
            if ($prompt === '') {
                return null;
            }
            return [
                'id'     => $id,
                'type'   => 'task',
                'prompt' => $prompt,
                'hint'   => clamp_text($b['hint'] ?? '', 400),
                'rubric' => str_list($b['rubric'] ?? [], 5, 160),
            ];

        case 'project':
            $brief = clamp_text($b['brief'] ?? '', 1200);
            if ($brief === '') {
                return null;
            }
            return [
                'id'           => $id,
                'type'         => 'project',
                'title'        => clamp_text($b['title'] ?? 'Build it', 140),
                'brief'        => $brief,
                'requirements' => str_list($b['requirements'] ?? [], 6, 240),
                'stretch'      => str_list($b['stretch'] ?? [], 4, 240),
            ];
    }

    return null;
}

/** Strip every answer key before this leaves the server. */
function lesson_public(array $lesson): array
{
    $blocks = [];
    foreach ($lesson['blocks'] as $b) {
        $type = $b['type'];
        if ($type === 'mcq') {
            $blocks[] = [
                'id' => $b['id'], 'type' => 'mcq',
                'question' => $b['question'], 'options' => $b['options'],
            ];
        } elseif ($type === 'fill') {
            $blocks[] = [
                'id' => $b['id'], 'type' => 'fill',
                'prompt' => $b['prompt'], 'code' => $b['code'],
                'blanks' => array_map(static fn($x) => ['choices' => $x['choices']], $b['blanks']),
            ];
        } elseif ($type === 'drag') {
            $blocks[] = [
                'id' => $b['id'], 'type' => 'drag',
                'prompt' => $b['prompt'], 'tokens' => $b['tokens'],
                'targets' => array_map(static fn($x) => ['label' => $x['label']], $b['targets']),
            ];
        } else {
            $blocks[] = $b;
        }
    }

    return [
        'title'     => $lesson['title'],
        'objective' => $lesson['objective'],
        'minutes'   => $lesson['minutes'],
        'why'       => $lesson['why'],
        'blocks'    => $blocks,
        'takeaways' => $lesson['takeaways'],
        'next'      => $lesson['next'],
    ];
}

/**
 * Ask the model for a lesson, validate it, and retry once with the failure
 * reason attached. Shared by the syllabus and on-demand modules.
 */
function lesson_from_messages(array $settings, array $messages, float $temperature = 0.85): array
{
    $lastError = '';

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $res = llm_complete($settings, $messages, [
            'json'        => true,
            'temperature' => $attempt === 1 ? $temperature : 0.55,
            'max_tokens'  => 4200,
        ]);
        $raw = extract_json((string)$res['text']);
        if ($raw === null) {
            $lastError = 'The response was not valid JSON.';
        } else {
            try {
                return lesson_normalize($raw);
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }
        $messages[] = ['role' => 'assistant', 'content' => mb_substr((string)$res['text'], 0, 2000)];
        $messages[] = [
            'role'    => 'user',
            'content' => 'That was unusable: ' . $lastError . ' Return the corrected JSON object only, following the schema exactly.',
        ];
    }

    throw new RuntimeException('The model kept returning unusable lessons (' . $lastError . '). Try a stronger model in Settings.');
}

function lesson_generate(array $user, array $row): array
{
    return lesson_from_messages(llm_settings($user), msgs_lesson($user, $row));
}

function module_generate(array $user, string $request, string $level): array
{
    return lesson_from_messages(llm_settings($user), msgs_module($user, $request, $level), 0.9);
}

/**
 * Server-side answer checking. Returns per-item results plus an explanation.
 *
 * @return array{ok:bool,detail:array,explain:string}
 */
function lesson_check(array $lesson, string $blockId, array $payload): array
{
    $block = null;
    foreach ($lesson['blocks'] as $b) {
        if (($b['id'] ?? '') === $blockId) {
            $block = $b;
            break;
        }
    }
    if ($block === null) {
        throw new RuntimeException('That exercise no longer exists. Reload the lesson.');
    }

    $detail = [];
    $ok = false;
    $explain = (string)($block['explain'] ?? '');

    if ($block['type'] === 'mcq') {
        $choice = $payload['choice'] ?? null;
        $correct = is_numeric($choice) && (int)$choice === (int)$block['answer'];
        $ok = $correct;
        $detail[] = [
            'ok' => $correct,
            'expected' => $correct ? null : $block['options'][$block['answer']] ?? null,
        ];
    } elseif ($block['type'] === 'fill') {
        $given = is_array($payload['blanks'] ?? null) ? $payload['blanks'] : [];
        $allOk = true;
        foreach ($block['blanks'] as $i => $blank) {
            $got = trim((string)($given[$i] ?? ''));
            $good = norm_answer($got) === norm_answer($blank['answer']);
            $allOk = $allOk && $good;
            $detail[] = ['ok' => $good, 'expected' => $good ? null : $blank['answer']];
        }
        $ok = $allOk;
    } elseif ($block['type'] === 'drag') {
        $given = is_array($payload['targets'] ?? null) ? $payload['targets'] : [];
        $allOk = true;
        foreach ($block['targets'] as $i => $target) {
            $got = trim((string)($given[$i] ?? ''));
            $good = $got === $target['answer'];
            $allOk = $allOk && $good;
            $detail[] = ['ok' => $good, 'expected' => $good ? null : $target['answer']];
        }
        $ok = $allOk;
    } elseif ($block['type'] === 'code') {
        $contains = $block['check']['contains'] ?? [];
        if ($contains === []) {
            $ok = trim((string)($payload['output'] ?? '')) !== '';
            $detail[] = ['ok' => $ok, 'expected' => null];
        } else {
            $output = (string)($payload['output'] ?? '');
            $missing = [];
            foreach ($contains as $needle) {
                if ($needle !== '' && !str_contains($output, $needle)) {
                    $missing[] = $needle;
                }
            }
            $ok = $missing === [];
            $detail[] = ['ok' => $ok, 'expected' => $ok ? null : $missing];
        }
    } else {
        throw new RuntimeException('That block is not an exercise.');
    }

    return ['ok' => $ok, 'detail' => $detail, 'explain' => $explain];
}

function norm_answer(string $s): string
{
    return strtolower(preg_replace('/\s+/', '', trim($s)) ?? '');
}

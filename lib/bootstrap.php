<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/curriculum.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/lesson.php';

@set_time_limit(180);

/** The subset of a user record that is safe to hand to the browser. */
function user_public(array $user): array
{
    $s = $user['settings'] ?? [];
    $key = !empty($s['key_enc']) ? decrypt_secret((string)$s['key_enc']) : '';

    return [
        'id'       => $user['id'],
        'username' => $user['username'],
        'display'  => $user['display'] ?? $user['username'],
        'created'  => $user['created'] ?? null,
        'profile'  => $user['profile'] ?? new stdClass(),
        'placement' => $user['placement'] ?? null,
        'settings' => [
            'provider'    => $s['provider'] ?? 'nvidia',
            'model'       => $s['model'] ?? '',
            'base_url'    => $s['base_url'] ?? '',
            'temperature' => $s['temperature'] ?? 0.7,
            'tutor_style' => $s['tutor_style'] ?? 'socratic',
            'timeout'     => $s['timeout'] ?? 10,
            'auto_build'  => (bool)($s['auto_build'] ?? true),
            'bank_target' => max(0, min(12, (int)($s['bank_target'] ?? 4))),
            'has_key'     => $key !== '',
            'key_hint'    => $key !== '' ? mask_key($key) : '',
        ],
        'stats'    => $user['stats'] ?? ['runs' => 0, 'messages' => 0, 'lessons' => 0],
        'memory'   => [
            'struggles' => (array)($user['memory']['struggles'] ?? []),
            'wins'      => (array)($user['memory']['wins'] ?? []),
            'notes'     => (array)($user['memory']['notes'] ?? []),
            'facts'     => memory_facts($user),
        ],
        'ready'    => $key !== '' || ($s['provider'] ?? '') === 'ollama',
    ];
}

/** Provider catalogue for the settings UI. Never includes secret material. */
function providers_public(): array
{
    $out = [];
    foreach (providers() as $id => $p) {
        $out[$id] = [
            'label'   => $p['label'],
            'kind'    => $p['kind'],
            'base'    => $p['base'],
            'key_url' => $p['key_url'],
            'note'    => $p['note'],
            'models'  => $p['models'],
            'needsKey' => $id !== 'ollama',
        ];
    }
    return $out;
}

/** Everything the single-page front end needs to render itself. */
function state_payload(array $user): array
{
    $projects = [];
    foreach (($user['projects'] ?? []) as $p) {
        $projects[] = [
            'id'         => $p['id'] ?? '',
            'title'      => $p['brief']['title'] ?? '',
            'mode'       => $p['mode'] ?? 'guided',
            'stage'      => $p['stage'] ?? null,
            'checkpoint' => (bool)($p['checkpoint'] ?? false),
            'at'         => $p['at'] ?? '',
            'brief'      => $p['brief'] ?? [],
            'code'       => $p['code'] ?? '',
            'notes'      => $p['notes'] ?? '',
            'review'     => $p['review'] ?? null,
        ];
    }

    return [
        'ok'          => true,
        'csrf'        => csrf_token(),
        'user'        => user_public($user),
        'progress'    => progress_stats($user),
        'mastery'     => mastery_summary($user),
        'roadmap'     => road_map($user),
        'plan'        => plan_public($user),
        'bank'        => curriculum_bank($user),
        'modules'     => module_summaries($user),
        'checkpoints' => $user['checkpoints'] ?? [],
        'syllabus'    => [
            'title'       => syllabus()['title'] ?? 'Python',
            'destination' => syllabus()['destination'] ?? '',
            'language'    => syllabus()['language'] ?? 'python',
        ],
        'cached'    => array_keys($user['lessons'] ?? []),
        'projects'  => $projects,
        'providers' => providers_public(),
    ];
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
$body = body_json();
$action = (string)($body['action'] ?? 'generate');

function clean_project(array $raw): array
{
    $milestones = [];
    foreach ((array)($raw['milestones'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $title = clamp_text($m['title'] ?? '', 120);
        if ($title === '') {
            continue;
        }
        $milestones[] = [
            'title'   => $title,
            'detail'  => clamp_text($m['detail'] ?? '', 300),
            'minutes' => max(5, min(180, (int)($m['minutes'] ?? 20))),
        ];
        if (count($milestones) >= 6) {
            break;
        }
    }

    $rubric = [];
    foreach ((array)($raw['rubric'] ?? []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $criterion = clamp_text($r['criterion'] ?? '', 160);
        if ($criterion !== '') {
            $rubric[] = ['criterion' => $criterion, 'weight' => max(1, min(5, (int)($r['weight'] ?? 3)))];
        }
    }

    return [
        'title'        => clamp_text($raw['title'] ?? 'Untitled project', 140),
        'pitch'        => clamp_text($raw['pitch'] ?? '', 300),
        'brief'        => clamp_text($raw['brief'] ?? '', 1400),
        'milestones'   => $milestones,
        'requirements' => str_list($raw['requirements'] ?? [], 6, 240),
        'stretch'      => str_list($raw['stretch'] ?? [], 4, 240),
        'rubric'       => $rubric,
    ];
}

try {
    if ($action === 'generate') {
        $mode = (string)($body['mode'] ?? 'guided');
        $stageId = clamp_text($body['stage'] ?? '', 40);
        $checkpoint = null;

        if ($stageId !== '') {
            $stageMeta = stage_for($user, $stageId);
            if ($stageMeta === null) {
                fail('Unknown stage.');
            }
            $checkpoint = [
                'stage'      => $stageId,
                'stageTitle' => (string)$stageMeta['title'],
                'project'    => plan_stage_project($user, $stageId),
                'skills'     => array_map(
                    static fn($s) => ['title' => (string)($s['title'] ?? ''), 'outcome' => (string)($s['outcome'] ?? '')],
                    (array)($stageMeta['skills'] ?? [])
                ),
            ];
            $mode = 'checkpoint';
        }

        $res = llm_complete(llm_settings($user), msgs_project($user, $mode, $checkpoint), [
            'json'        => true,
            'temperature' => 0.95,
            'max_tokens'  => 2200,
        ]);
        $raw = extract_json((string)$res['text']);
        if ($raw === null) {
            fail('The model returned an unreadable project. Try again.');
        }
        $project = clean_project($raw);
        if ($project['brief'] === '') {
            fail('The project came back empty. Try again.');
        }

        $record = [
            'id'         => short_id(6),
            'mode'       => $checkpoint !== null ? 'checkpoint' : ($mode === 'stretch' ? 'stretch' : 'guided'),
            'stage'      => $checkpoint['stage'] ?? null,
            'checkpoint' => $checkpoint !== null,
            'at'         => now_iso(),
            'brief'      => $project,
            'code'       => '',
            'notes'      => '',
            'review'     => null,
        ];

        $updated = Store::update($user['id'], function (array &$u) use ($record) {
            $u['projects'] = $u['projects'] ?? [];
            array_unshift($u['projects'], $record);
            $u['projects'] = array_slice($u['projects'], 0, 25);
        });

        json_out(['ok' => true, 'project' => $record, 'user' => user_public($updated ?? $user)]);
    }

    if ($action === 'review') {
        $id = (string)($body['id'] ?? '');
        $code = clamp_text($body['code'] ?? '', 6000);
        $notes = clamp_text($body['notes'] ?? '', 1200);
        if ($code === '') {
            fail('Paste your project code before asking for a review.');
        }

        $project = null;
        foreach (($user['projects'] ?? []) as $p) {
            if (($p['id'] ?? '') === $id) {
                $project = $p;
                break;
            }
        }
        if ($project === null) {
            fail('Project not found.', 404);
        }

        $res = llm_complete(llm_settings($user), msgs_project_review($user, $project['brief'], $code, $notes), [
            'json'        => true,
            'temperature' => 0.3,
            'max_tokens'  => 1600,
        ]);
        $raw = extract_json((string)$res['text']);
        if ($raw === null) {
            fail('The reviewer returned something unreadable. Try again.');
        }

        $review = [
            'score'     => round(max(0.0, min(1.0, (float)($raw['score'] ?? 0))), 2),
            'verdict'   => clamp_text($raw['verdict'] ?? '', 400),
            'works'     => str_list($raw['works'] ?? [], 6, 240),
            'issues'    => [],
            'next_step' => clamp_text($raw['next_step'] ?? '', 400),
            'revisit'   => str_list($raw['concepts_to_revisit'] ?? [], 5, 80),
            'at'        => now_iso(),
        ];
        foreach ((array)($raw['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $sev = in_array(($issue['severity'] ?? ''), ['high', 'medium', 'low'], true) ? $issue['severity'] : 'medium';
            $review['issues'][] = [
                'severity' => $sev,
                'where'    => clamp_text($issue['where'] ?? '', 120),
                'what'     => clamp_text($issue['what'] ?? '', 300),
                'fix'      => clamp_text($issue['fix'] ?? '', 400),
            ];
            if (count($review['issues']) >= 8) {
                break;
            }
        }

        $checkpointStage = (string)($project['stage'] ?? '');
        $checkpointPassed = $checkpointStage !== '' && $review['score'] >= 0.7;

        $updated = Store::update($user['id'], function (array &$u) use ($id, $code, $notes, $review, $project, $checkpointStage, $checkpointPassed) {
            foreach ($u['projects'] as $i => $p) {
                if (($p['id'] ?? '') === $id) {
                    $u['projects'][$i]['code'] = $code;
                    $u['projects'][$i]['notes'] = $notes;
                    $u['projects'][$i]['review'] = $review;
                    break;
                }
            }

            $u['memory'] = is_array($u['memory'] ?? null) ? $u['memory'] : [];
            $u['memory']['facts'] = is_array($u['memory']['facts'] ?? null) ? $u['memory']['facts'] : [];
            $title = (string)($project['brief']['title'] ?? 'project');
            if ($checkpointPassed) {
                $u['checkpoints'] = is_array($u['checkpoints'] ?? null) ? $u['checkpoints'] : [];
                $u['checkpoints'][$checkpointStage] = [
                    'project' => $id,
                    'score'   => $review['score'],
                    'at'      => now_iso(),
                ];
                memory_add($u, 'Passed the stage checkpoint with "' . $title . '".', 'strength', 'project');
                // A passed checkpoint can be the last thing missing for the
                // advanced track, so check for the mastery declaration here too.
                mastery_maybe_declare($u);
            } else {
                $step = trim((string)($review['next_step'] ?? ''));
                $text = 'Project "' . $title . '" scored ' . (int)round($review['score'] * 100) . '%';
                if ($step !== '') {
                    $text .= '; next: ' . $step;
                }
                memory_add($u, $text, 'struggle', 'project');
            }
        });

        json_out([
            'ok'         => true,
            'review'     => $review,
            'checkpoint' => $checkpointStage !== ''
                ? ['stage' => $checkpointStage, 'passed' => $checkpointPassed]
                : null,
            'state'      => $updated !== null ? state_payload($updated) : null,
        ]);
    }

    if ($action === 'list') {
        json_out(['ok' => true, 'projects' => $user['projects'] ?? []]);
    }

    fail('Unknown action.');
} catch (RuntimeException $e) {
    fail($e->getMessage(), 400);
}

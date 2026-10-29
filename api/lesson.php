<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
$body = body_json();
$action = (string)($body['action'] ?? 'get');
$skillId = (string)($body['skill'] ?? '');
$moduleId = (string)($body['module'] ?? '');

$module = null;
if ($moduleId !== '') {
    $module = find_module($user, $moduleId);
    if ($module === null) {
        fail('That module no longer exists.', 404);
    }
}

$row = null;
if ($module === null && $skillId !== '') {
    $row = skill_meta($skillId, $user);
    if ($row === null) {
        fail('Unknown skill.', 404);
    }
}

/** Metadata the lesson header understands for either a skill or a module. */
function lesson_meta_for(?array $module, ?array $row, string $skillId): array
{
    if ($module !== null) {
        return [
            'id'    => (string)$module['id'],
            'title' => (string)($module['lesson']['title'] ?? 'Custom module'),
            'stage' => 'Your module',
        ];
    }
    return [
        'id'    => $skillId,
        'title' => (string)($row['skill']['title'] ?? $skillId),
        'stage' => (string)($row['stage']['title'] ?? ''),
    ];
}

try {
    if ($action === 'generate' || $action === 'regenerate') {
        if ($row === null) {
            fail('Choose a skill to build.');
        }

        if (!empty($user['lessons'][$skillId]) && $action !== 'regenerate') {
            json_out([
                'ok'     => true,
                'cached' => true,
                'lesson' => lesson_public($user['lessons'][$skillId]),
                'skill'  => lesson_meta_for($module, $row, $skillId),
            ]);
        }

        $lesson = lesson_generate($user, $row);

        $updated = Store::update($user['id'], function (array &$u) use ($skillId, $lesson) {
            $u['lessons'][$skillId] = $lesson;
            $u['stats']['lessons'] = (int)($u['stats']['lessons'] ?? 0) + 1;
            $u['journey']['activeSkill'] = $skillId;
            $u['bank'] = is_array($u['bank'] ?? null) ? $u['bank'] : [];
            $u['bank']['last_at'] = now_iso();
            $u['bank']['last_error'] = '';
        });

        json_out([
            'ok'     => true,
            'cached' => false,
            'lesson' => lesson_public($lesson),
            'skill'  => lesson_meta_for($module, $row, $skillId),
            'user'   => user_public($updated ?? $user),
            'bank'   => curriculum_bank($updated ?? $user),
        ]);
    }

    if ($action === 'get') {
        if ($module !== null) {
            json_out([
                'ok'     => true,
                'lesson' => lesson_public($module['lesson']),
                'skill'  => lesson_meta_for($module, null, ''),
                'done'   => (bool)($module['done'] ?? false),
                'module' => true,
            ]);
        }
        if ($row === null) {
            fail('Choose a skill to open.');
        }
        if (!isset($user['lessons'][$skillId])) {
            json_out(['ok' => true, 'lesson' => null]);
        }
        json_out([
            'ok'     => true,
            'lesson' => lesson_public($user['lessons'][$skillId]),
            'skill'  => lesson_meta_for($module, $row, $skillId),
            'done'   => isset(done_skill_ids($user)[$skillId]),
        ]);
    }

    if ($action === 'check') {
        $lesson = $module !== null
            ? (is_array($module['lesson'] ?? null) ? $module['lesson'] : null)
            : ($user['lessons'][$skillId] ?? null);
        if ($lesson === null) {
            fail('Lesson not loaded.', 404);
        }
        $blockId = (string)($body['blockId'] ?? '');
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $result = lesson_check($lesson, $blockId, $payload);
        json_out(['ok' => true] + $result);
    }

    if ($action === 'complete') {
        $score = (float)($body['score'] ?? 0.8);
        $note = (string)($body['note'] ?? '');

        if ($module !== null) {
            $moduleTitle = (string)($module['lesson']['title'] ?? 'Custom module');
            $updated = Store::update($user['id'], function (array &$u) use ($moduleId, $moduleTitle, $score, $note) {
                foreach (($u['modules'] ?? []) as $i => $m) {
                    if ((string)($m['id'] ?? '') === $moduleId) {
                        $u['modules'][$i]['done'] = true;
                        $u['modules'][$i]['completed_at'] = now_iso();
                        $u['modules'][$i]['score'] = round(max(0, min(1, $score)), 2);
                        break;
                    }
                }
                $u['memory'] = is_array($u['memory'] ?? null) ? $u['memory'] : [];
                $u['memory']['facts'] = is_array($u['memory']['facts'] ?? null) ? $u['memory']['facts'] : [];
                if ($score >= 0.8) {
                    memory_add($u, 'Completed their requested module "' . $moduleTitle . '".', 'strength', 'module');
                } else {
                    memory_add($u, 'Requested extra practice on "' . $moduleTitle . '".', 'struggle', 'module');
                }
                if ($note !== '') {
                    $u['memory']['notes'][] = clamp_text($note, 200);
                    $u['memory']['notes'] = array_slice(array_values(array_unique($u['memory']['notes'])), -12);
                }
            });
        } else {
            if ($row === null) {
                fail('Choose a skill to complete.');
            }
            $updated = Store::update($user['id'], function (array &$u) use ($skillId, $score, $note, $row) {
                $u = complete_skill($u, $skillId, $score, $note);
                $title = (string)($row['skill']['title'] ?? $skillId);
                $pct = (int)round(max(0, min(1, $score)) * 100);
                $u['memory'] = is_array($u['memory'] ?? null) ? $u['memory'] : [];
                $u['memory']['facts'] = is_array($u['memory']['facts'] ?? null) ? $u['memory']['facts'] : [];
                if ($score >= 0.8) {
                    $u['memory']['wins'][] = 'Solid grasp of ' . $title;
                    memory_add($u, 'Completed "' . $title . '" confidently (' . $pct . '%).', 'strength', 'lesson');
                } elseif ($score < 0.6) {
                    $u['memory']['struggles'][] = 'Needed help with ' . $title;
                    memory_add($u, 'Needed help with "' . $title . '" (' . $pct . '%). Revisit before moving far ahead.', 'struggle', 'lesson');
                }
                $u['memory']['wins'] = array_slice(array_values(array_unique($u['memory']['wins'])), -12);
                $u['memory']['struggles'] = array_slice(array_values(array_unique($u['memory']['struggles'])), -12);
                $u['journey']['activeSkill'] = null;
            });
        }

        if ($updated === null) {
            fail('Could not record progress.', 500);
        }

        json_out(state_payload($updated));
    }

    fail('Unknown action.');
} catch (RuntimeException $e) {
    fail($e->getMessage(), 400);
}

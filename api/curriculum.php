<?php
declare(strict_types=1);

/**
 * The curriculum forge.
 *
 * Plan action  — ask the model to design the whole course around this learner.
 * Pregen      — write the next few lessons ahead of them and bank them.
 * Extend      — add another AI-written mastery chapter after the core 40.
 * Module      — write a one-off lesson the learner asked for.
 * Settings    — how far ahead to build, and whether to do it automatically.
 */

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
$body = body_json();
$action = (string)($body['action'] ?? 'status');

function curriculum_require_model(array $user): void
{
    $settings = llm_settings($user);
    llm_require_key($settings);
}

try {
    if ($action === 'status') {
        json_out(state_payload($user));
    }

    if ($action === 'plan' || $action === 'replan') {
        $force = $action === 'replan' || !empty($body['force']);
        $existing = $user['plan'] ?? null;
        if (!$force && is_array($existing) && !empty($existing['stages'])) {
            json_out(state_payload($user));
        }

        curriculum_require_model($user);
        try {
            $plan = plan_generate($user);
        } catch (RuntimeException $e) {
            // Keep the interface usable even when the model refuses to plan.
            $plan = plan_default($user);
            $plan['note'] = $e->getMessage();
        }

        // Redesigning the core path must not throw away advanced chapters the
        // learner has already unlocked.
        $mastery = mastery_stages($user);
        if ($mastery !== []) {
            $plan['stages'] = array_merge($plan['stages'], $mastery);
        }

        $updated = Store::update($user['id'], function (array &$u) use ($plan) {
            $u['plan'] = $plan;
            $u['bank'] = is_array($u['bank'] ?? null) ? $u['bank'] : [];
            $u['bank']['last_error'] = (string)($plan['note'] ?? '');
        });
        if ($updated === null) {
            fail('Could not save the plan.', 500);
        }

        json_out(state_payload($updated));
    }

    if ($action === 'pregen') {
        curriculum_require_model($user);

        $count = max(1, min(4, (int)($body['count'] ?? 1)));

        // Make sure there is a structured plan to build from. If the model
        // cannot plan, fall back to the syllabus rather than blocking.
        if (empty($user['plan']['stages'])) {
            $plan = plan_ensure($user);
            $updated = Store::update($user['id'], function (array &$u) use ($plan) {
                $u['plan'] = $plan;
            });
            if ($updated !== null) {
                $user = $updated;
            }
        }

        $lock = curriculum_lock((string)$user['id']);
        if ($lock === null) {
            json_out(['ok' => true, 'busy' => true, 'built' => []] + state_payload($user));
        }

        $built = [];
        $error = '';
        try {
            for ($i = 0; $i < $count; $i++) {
                $next = curriculum_next_skill($user);
                if ($next === null) {
                    break;
                }
                $row = skill_meta($next, $user);
                if ($row === null) {
                    break;
                }

                try {
                    $lesson = lesson_generate($user, $row);
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                    break;
                }

                $skillId = (string)$row['skill']['id'];
                $title = (string)$row['skill']['title'];
                $updated = Store::update($user['id'], function (array &$u) use ($skillId, $lesson) {
                    if (!isset($u['lessons'][$skillId])) {
                        $u['lessons'][$skillId] = $lesson;
                        $u['stats']['lessons'] = (int)($u['stats']['lessons'] ?? 0) + 1;
                    }
                    $u['bank'] = is_array($u['bank'] ?? null) ? $u['bank'] : [];
                    $u['bank']['last_at'] = now_iso();
                    $u['bank']['last_error'] = '';
                });
                if ($updated === null) {
                    $error = 'Could not save the generated lesson.';
                    break;
                }
                $user = $updated;
                $built[] = ['skill' => $skillId, 'title' => $title];
            }
        } finally {
            curriculum_unlock($lock);
        }

        if ($error !== '') {
            Store::update($user['id'], function (array &$u) use ($error) {
                $u['bank'] = is_array($u['bank'] ?? null) ? $u['bank'] : [];
                $u['bank']['last_error'] = mb_substr($error, 0, 300);
            });
            $fresh = Store::load((string)$user['id']);
            if ($fresh !== null) {
                $user = $fresh;
            }
        }

        json_out([
            'ok'     => true,
            'built'  => $built,
            'error'  => $error,
            'state'  => state_payload($user),
        ]);
    }

    if ($action === 'extend') {
        $force = !empty($body['force']);
        $summary = mastery_summary($user);

        if ($summary['chapters'] >= MASTERY_MAX_CHAPTERS) {
            json_out(['ok' => true, 'skipped' => true, 'reason' => 'max'] + state_payload($user));
        }
        if (!$summary['core_done']) {
            fail('The mastery track opens once the core curriculum is finished.');
        }
        if ($force) {
            // Manual "keep going" only makes sense once the current chapters
            // are actually finished — or once mastery has been declared.
            if (!$summary['declared'] && !$summary['all_done']) {
                fail('Finish the current mastery chapter before asking for another.');
            }
        } elseif (!$summary['can_extend']) {
            json_out(['ok' => true, 'skipped' => true, 'reason' => 'not-ready'] + state_payload($user));
        }

        curriculum_require_model($user);

        $lock = curriculum_lock((string)$user['id']);
        if ($lock === null) {
            json_out(['ok' => true, 'busy' => true] + state_payload($user));
        }

        try {
            $chapter = mastery_next_chapter($user);
            $stage = mastery_generate($user, $chapter);
        } finally {
            curriculum_unlock($lock);
        }

        $added = false;
        $updated = Store::update($user['id'], function (array &$u) use ($stage, &$added) {
            $u['plan'] = is_array($u['plan'] ?? null) ? $u['plan'] : plan_default($u);
            $u['plan']['stages'] = is_array($u['plan']['stages'] ?? null) ? $u['plan']['stages'] : [];
            foreach ($u['plan']['stages'] as $existing) {
                // Another tab may have added this chapter while we wrote it.
                if ((string)($existing['id'] ?? '') === (string)$stage['id']) {
                    return;
                }
            }
            $u['plan']['stages'][] = $stage;
            $added = true;
            $u['memory'] = is_array($u['memory'] ?? null) ? $u['memory'] : [];
            $u['memory']['facts'] = is_array($u['memory']['facts'] ?? null) ? $u['memory']['facts'] : [];
            memory_add($u, 'Unlocked mastery chapter "' . (string)($stage['title'] ?? '') . '" — advanced Python beyond the core curriculum.', 'goal', 'mastery');
        });
        if ($updated === null) {
            fail('Could not save that chapter.', 500);
        }

        json_out([
            'ok'      => true,
            'added'   => $added,
            'chapter' => $stage,
            'state'   => state_payload($updated),
        ]);
    }

    if ($action === 'settings') {
        $auto = !empty($body['auto']);
        $target = max(0, min(12, (int)($body['target'] ?? 4)));
        $updated = Store::update($user['id'], function (array &$u) use ($auto, $target) {
            $u['settings']['auto_build'] = $auto;
            $u['settings']['bank_target'] = $target;
        });
        if ($updated === null) {
            fail('Could not save that.', 500);
        }
        json_out(state_payload($updated));
    }

    if ($action === 'module') {
        curriculum_require_model($user);
        $request = clamp_text($body['request'] ?? '', 600);
        if (mb_strlen(trim($request)) < 3) {
            fail('Tell the tutor what you want to learn.');
        }
        $level = in_array(($body['level'] ?? ''), ['easier', 'same', 'harder'], true)
            ? (string)$body['level']
            : 'same';

        $lesson = module_generate($user, $request, $level);
        $module = [
            'id'     => short_id(6),
            'request' => trim($request),
            'level'  => $level,
            'at'     => now_iso(),
            'lesson' => $lesson,
            'done'   => false,
        ];

        $updated = Store::update($user['id'], function (array &$u) use ($module, $request) {
            $u['modules'] = is_array($u['modules'] ?? null) ? $u['modules'] : [];
            array_unshift($u['modules'], $module);
            $u['modules'] = array_slice($u['modules'], 0, 40);
            $u['memory'] = is_array($u['memory'] ?? null) ? $u['memory'] : [];
            $u['memory']['facts'] = is_array($u['memory']['facts'] ?? null) ? $u['memory']['facts'] : [];
            memory_add($u, 'Asked to learn: ' . $request, 'interest', 'request');
        });
        if ($updated === null) {
            fail('Could not save that module.', 500);
        }

        json_out([
            'ok'     => true,
            'module' => $module,
            'state'  => state_payload($updated),
        ]);
    }

    if ($action === 'module_remove') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            fail('Missing module id.');
        }
        $updated = Store::update($user['id'], function (array &$u) use ($id) {
            $u['modules'] = array_values(array_filter(
                is_array($u['modules'] ?? null) ? $u['modules'] : [],
                static fn($m) => (string)($m['id'] ?? '') !== $id
            ));
        });
        if ($updated === null) {
            fail('Could not remove that module.', 500);
        }
        json_out(state_payload($updated));
    }

    fail('Unknown action.');
} catch (RuntimeException $e) {
    fail($e->getMessage(), 400);
}

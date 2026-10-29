<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
$body = body_json();
$action = (string)($body['action'] ?? 'quiz');

/** Validate the model's quiz into something renderable. */
function clean_quiz(array $raw): array
{
    $out = [];
    $i = 0;
    foreach ((array)($raw['questions'] ?? []) as $q) {
        if (!is_array($q)) {
            continue;
        }
        $options = str_list($q['options'] ?? [], 5, 300);
        $question = clamp_text($q['question'] ?? '', 800);
        if ($question === '' || count($options) < 2) {
            continue;
        }
        $i++;
        $out[] = [
            'id'       => 'q' . $i,
            'topic'    => clamp_text($q['topic'] ?? 'general', 60),
            'question' => $question,
            'code'     => clamp_text($q['code'] ?? '', 1200),
            'options'  => $options,
        ];
        if (count($out) >= 10) {
            break;
        }
    }
    return $out;
}

/** Pick the first skill at or after the placement stage. */
function first_skill_of_stage(int $stage): ?string
{
    $stages = syllabus()['stages'];
    if (!isset($stages[$stage])) {
        $stage = 0;
    }
    $fallback = null;
    foreach (all_skills() as $row) {
        $id = (string)$row['skill']['id'];
        if ($fallback === null) {
            $fallback = $id;
        }
        if (($row['stage']['id'] ?? '') === ($stages[$stage]['id'] ?? '')) {
            return $id;
        }
    }
    return $fallback;
}

try {
    if ($action === 'quiz') {
        $msgs = msgs_placement_quiz($user);
        $res = llm_complete(llm_settings($user), $msgs, ['json' => true, 'temperature' => 0.6, 'max_tokens' => 3000]);
        $raw = extract_json((string)$res['text']);
        if ($raw === null) {
            fail('The model did not return a usable quiz. Try again.');
        }
        $questions = clean_quiz($raw);
        if (count($questions) < 4) {
            fail('The model produced too few questions. Try again.');
        }
        $updated = Store::update($user['id'], function (array &$u) use ($questions) {
            $u['quiz'] = ['questions' => $questions, 'at' => now_iso()];
        });
        json_out(['ok' => true, 'questions' => $questions, 'user' => user_public($updated ?? $user)]);
    }

    if ($action === 'submit') {
        $questions = $user['quiz']['questions'] ?? [];
        if (!is_array($questions) || $questions === []) {
            fail('No placement quiz in progress. Start the test again.');
        }
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
        $clean = [];
        foreach ($questions as $q) {
            $v = $answers[$q['id']] ?? null;
            if (is_numeric($v)) {
                $clean[$q['id']] = (int)$v;
            }
        }
        if ($clean === []) {
            fail('Answer at least one question.');
        }

        $msgs = msgs_placement_eval($user, $questions, $clean);
        $res = llm_complete(llm_settings($user), $msgs, ['json' => true, 'temperature' => 0.3, 'max_tokens' => 900]);
        $raw = extract_json((string)$res['text']);
        if ($raw === null) {
            fail('The model could not evaluate the placement. Try again.');
        }

        $stage = max(0, min(count(syllabus()['stages']) - 1, (int)($raw['stage'] ?? 0)));
        $startSkill = (string)($raw['startSkill'] ?? '');
        if (skill_meta($startSkill) === null) {
            $startSkill = (string)first_skill_of_stage($stage);
        }

        $placement = [
            'stage'      => $stage,
            'startSkill' => $startSkill,
            'confidence' => round(max(0.0, min(1.0, (float)($raw['confidence'] ?? 0.5))), 2),
            'summary'    => clamp_text($raw['summary'] ?? '', 600),
            'strengths'  => str_list($raw['strengths'] ?? [], 4, 160),
            'gaps'       => str_list($raw['gaps'] ?? [], 4, 160),
            'at'         => now_iso(),
            'answers'    => $clean,
        ];

        $updated = Store::update($user['id'], function (array &$u) use ($placement, $startSkill) {
            $u['placement'] = $placement;

            // Everything before the placement point is marked as known.
            $skipped = [];
            foreach (all_skills() as $row) {
                if ((string)$row['skill']['id'] === $startSkill) {
                    break;
                }
                $skipped[] = (string)$row['skill']['id'];
            }
            $u['journey']['skipped'] = $skipped;
            $u['journey']['activeSkill'] = $startSkill;
        });

        json_out([
            'ok'        => true,
            'placement' => $placement,
            'user'      => user_public($updated ?? $user),
            'progress'  => progress_stats($updated ?? $user),
            'roadmap'   => road_map($updated ?? $user),
        ]);
    }

    if ($action === 'skip') {
        $startSkill = (string)($body['skill'] ?? first_skill_of_stage(0));
        if (skill_meta($startSkill) === null) {
            $startSkill = (string)first_skill_of_stage(0);
        }
        $updated = Store::update($user['id'], function (array &$u) use ($startSkill) {
            $u['placement'] = [
                'stage'      => 0,
                'startSkill' => $startSkill,
                'confidence' => 0,
                'summary'    => 'Started from the beginning without a placement test.',
                'strengths'  => [],
                'gaps'       => [],
                'at'         => now_iso(),
                'skipped_test' => true,
            ];
            $skipped = [];
            foreach (all_skills() as $row) {
                if ((string)$row['skill']['id'] === $startSkill) {
                    break;
                }
                $skipped[] = (string)$row['skill']['id'];
            }
            $u['journey']['skipped'] = $skipped;
            $u['journey']['activeSkill'] = $startSkill;
        });
        json_out([
            'ok'       => true,
            'user'     => user_public($updated ?? $user),
            'progress' => progress_stats($updated ?? $user),
            'roadmap'  => road_map($updated ?? $user),
        ]);
    }

    fail('Unknown action.');
} catch (RuntimeException $e) {
    fail($e->getMessage(), 400);
}

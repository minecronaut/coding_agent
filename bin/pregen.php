#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Warm the curriculum bank from the command line.
 *
 *   php bin/pregen.php --user=alex --count=20
 *   php bin/pregen.php --all --count=10
 *   php bin/pregen.php --user=alex --stage=s2 --count=7
 *
 * Each run uses the learner's configured provider, builds the structured plan
 * if it is missing, then writes one lesson at a time. Safe to stop and rerun.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$opts = getopt('', ['user::', 'count::', 'stage::', 'all']);

if (!isset($opts['user']) && !isset($opts['all'])) {
    fwrite(STDERR, "Usage: php bin/pregen.php --user=USERNAME [--count=10] [--stage=s2]\n");
    fwrite(STDERR, "       php bin/pregen.php --all [--count=10]\n");
    exit(2);
}

$count = max(1, min(200, (int)($opts['count'] ?? 10)));
$stageFilter = (string)($opts['stage'] ?? '');

$users = [];
if (isset($opts['all'])) {
    foreach (glob(USERS_DIR . '/*.json') ?: [] as $file) {
        $data = json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && !empty($data['id'])) {
            $users[] = $data;
        }
    }
} else {
    $found = Store::findUserByName((string)$opts['user']);
    if ($found === null) {
        fwrite(STDERR, "No such learner: {$opts['user']}\n");
        exit(1);
    }
    $users[] = $found;
}

foreach ($users as $user) {
    $name = $user['username'] ?? $user['id'];
    echo "== {$name} ==\n";

    $settings = llm_settings($user);
    try {
        llm_require_key($settings);
    } catch (RuntimeException $e) {
        echo "  skipped: {$e->getMessage()}\n";
        continue;
    }

    if (empty($user['plan']['stages'])) {
        echo "  planning…\n";
        $plan = plan_ensure($user);
        $updated = Store::update((string)$user['id'], function (array &$u) use ($plan) {
            $u['plan'] = $plan;
        });
        if ($updated !== null) {
            $user = $updated;
        }
        echo empty($plan['fallback']) ? "  plan ready\n" : "  plan fell back to the syllabus\n";
    }

    // Once the core 40 are finished, the CLI should keep the advanced track
    // moving too instead of reporting an empty bank.
    if (mastery_can_extend($user)) {
        $nextChapter = mastery_next_chapter($user);
        echo "  core complete — writing mastery chapter {$nextChapter}…\n";
        $lock = curriculum_lock((string)$user['id']);
        if ($lock !== null) {
            try {
                $stage = mastery_generate($user, $nextChapter);
            } finally {
                curriculum_unlock($lock);
            }
            $updated = Store::update((string)$user['id'], function (array &$u) use ($stage) {
                $u['plan'] = is_array($u['plan'] ?? null) ? $u['plan'] : plan_default($u);
                $u['plan']['stages'] = is_array($u['plan']['stages'] ?? null) ? $u['plan']['stages'] : [];
                $u['plan']['stages'][] = $stage;
            });
            if ($updated !== null) {
                $user = $updated;
                echo "  chapter ready: " . (string)($stage['title'] ?? $stage['id']) . "\n";
            }
        }
    }

    $built = 0;
    for ($i = 0; $i < $count; $i++) {
        $next = null;
        $done = done_skill_ids($user);
        foreach (curriculum_units($user) as $row) {
            $id = (string)$row['skill']['id'];
            if ($stageFilter !== '' && (string)($row['stage']['id'] ?? '') !== $stageFilter) {
                continue;
            }
            if (!isset($done[$id]) && !isset($user['lessons'][$id])) {
                $next = $id;
                break;
            }
        }
        if ($next === null) {
            echo "  nothing left to build" . ($stageFilter !== '' ? " in {$stageFilter}" : '') . "\n";
            break;
        }

        $row = skill_meta($next, $user);
        if ($row === null) {
            break;
        }

        echo "  [{$built}] writing {$next} — {$row['skill']['title']}… ";
        $started = microtime(true);
        try {
            $lesson = lesson_generate($user, $row);
        } catch (RuntimeException $e) {
            echo "failed\n    " . $e->getMessage() . "\n";
            Store::update((string)$user['id'], function (array &$u) use ($e) {
                $u['bank'] = is_array($u['bank'] ?? null) ? $u['bank'] : [];
                $u['bank']['last_error'] = mb_substr($e->getMessage(), 0, 300);
            });
            break;
        }

        $skillId = (string)$row['skill']['id'];
        $updated = Store::update((string)$user['id'], function (array &$u) use ($skillId, $lesson) {
            if (!isset($u['lessons'][$skillId])) {
                $u['lessons'][$skillId] = $lesson;
                $u['stats']['lessons'] = (int)($u['stats']['lessons'] ?? 0) + 1;
            }
            $u['bank'] = is_array($u['bank'] ?? null) ? $u['bank'] : [];
            $u['bank']['last_at'] = now_iso();
            $u['bank']['last_error'] = '';
        });
        if ($updated === null) {
            echo "save failed\n";
            break;
        }
        $user = $updated;
        $built++;
        printf("ok (%d blocks, %.1fs)\n", count($lesson['blocks'] ?? []), microtime(true) - $started);
    }

    $bank = curriculum_bank($user);
    echo "  bank: {$bank['built']}/{$bank['total']} lessons, {$bank['items']} activities, {$bank['ahead']} ahead\n";
}

<?php
declare(strict_types=1);

function syllabus(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = @file_get_contents(CONTENT_DIR . '/syllabus.json');
    $data = $raw ? json_decode($raw, true) : null;
    $cached = is_array($data) ? $data : ['stages' => []];
    return $cached;
}

/** @return array<int,array{skill:array,stage:array}> ordered flat list */
function all_skills(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $out = [];
    foreach (syllabus()['stages'] as $stage) {
        foreach (($stage['skills'] ?? []) as $skill) {
            $out[] = ['skill' => $skill, 'stage' => $stage];
        }
    }
    $cached = $out;
    return $out;
}

function skill_meta(string $skillId, ?array $user = null): ?array
{
    foreach (curriculum_units($user ?? ['plan' => []]) as $row) {
        if (($row['skill']['id'] ?? '') === $skillId) {
            return $row;
        }
    }
    return null;
}

function core_stage_count(): int
{
    return count(syllabus()['stages']);
}

function core_skill_count(): int
{
    return count(all_skills());
}

/**
 * Raw plan stages that are not part of the fixed syllabus. These are the
 * AI-written mastery chapters that take the learner past the core 40.
 *
 * @return array<int,array>
 */
function mastery_stages(array $user): array
{
    $core = [];
    foreach (syllabus()['stages'] as $stage) {
        $core[(string)($stage['id'] ?? '')] = true;
    }

    $out = [];
    foreach (($user['plan']['stages'] ?? []) as $stage) {
        if (!is_array($stage)) {
            continue;
        }
        $id = (string)($stage['id'] ?? '');
        if ($id === '' || isset($core[$id]) || empty($stage['units'])) {
            continue;
        }
        $out[] = $stage;
    }
    return $out;
}

/** Turn a mastery plan stage into the same shape as a syllabus stage. */
function mastery_stage_public(array $stage, int $index): array
{
    $skills = [];
    foreach ((array)($stage['units'] ?? []) as $unit) {
        $id = (string)($unit['skill'] ?? '');
        if ($id === '') {
            continue;
        }
        $skills[] = [
            'id'      => $id,
            'title'   => (string)($unit['title'] ?? ''),
            'outcome' => (string)($unit['focus'] ?? ''),
            'checks'  => array_values(array_filter(
                array_map(static fn($c) => (string)$c, (array)($unit['concepts'] ?? [])),
                static fn($c) => $c !== ''
            )),
        ];
    }

    return [
        'id'      => (string)($stage['id'] ?? 'm' . ($index + 1)),
        'title'   => (string)($stage['title'] ?? 'Mastery chapter'),
        'outcome' => (string)($stage['emphasis'] ?? ''),
        'skills'  => $skills,
        'mastery' => true,
        'index'   => $index,
    ];
}

/**
 * Look up any stage — core or an AI-written mastery chapter — in the same
 * shape, so checkpoints and project prompts do not care which is which.
 */
function stage_for(array $user, string $stageId): ?array
{
    $core = stage_meta($stageId);
    if ($core !== null) {
        return $core;
    }
    foreach (mastery_stages($user) as $i => $stage) {
        if ((string)($stage['id'] ?? '') === $stageId) {
            return mastery_stage_public($stage, core_stage_count() + $i);
        }
    }
    return null;
}

/** Every stage the learner can actually walk through: core first, then mastery. */
function curriculum_stages(array $user): array
{
    $stages = [];
    foreach (syllabus()['stages'] as $stage) {
        $stages[] = $stage;
    }
    foreach (mastery_stages($user) as $stage) {
        $stages[] = mastery_stage_public($stage, count($stages));
    }
    return $stages;
}

/** Flat, ordered list of every lesson unit, core and mastery. */
function curriculum_units(array $user): array
{
    $out = [];
    foreach (curriculum_stages($user) as $stage) {
        foreach (($stage['skills'] ?? []) as $skill) {
            $out[] = ['skill' => $skill, 'stage' => $stage];
        }
    }
    return $out;
}

/** @return array<string,true> */
function done_skill_ids(array $user): array
{
    $done = [];
    foreach (($user['journey']['completed'] ?? []) as $c) {
        if (is_array($c) && isset($c['skill'])) {
            $done[(string)$c['skill']] = true;
        } elseif (is_string($c)) {
            $done[$c] = true;
        }
    }
    foreach (($user['journey']['skipped'] ?? []) as $s) {
        if (is_string($s)) {
            $done[$s] = true;
        }
    }
    return $done;
}

/** The next skill the learner should work on. */
function next_skill(array $user): ?array
{
    $done = done_skill_ids($user);
    foreach (curriculum_units($user) as $row) {
        $id = (string)($row['skill']['id'] ?? '');
        if ($id !== '' && !isset($done[$id])) {
            return $row;
        }
    }
    return null;
}

/** Current stage index, derived from the next unfinished skill. */
function current_stage_index(array $user): int
{
    $next = next_skill($user);
    $stages = curriculum_stages($user);
    if ($next === null) {
        return max(0, count($stages) - 1);
    }
    foreach ($stages as $i => $stage) {
        if (($stage['id'] ?? '') === ($next['stage']['id'] ?? '')) {
            return (int)$i;
        }
    }
    return 0;
}

/** Has this learner unlocked a given skill? Stages behind the frontier are open. */
function skill_unlocked(array $user, string $skillId): bool
{
    $meta = skill_meta($skillId, $user);
    if ($meta === null) {
        return false;
    }
    $done = done_skill_ids($user);
    if (isset($done[$skillId])) {
        return true;
    }
    $frontier = current_stage_index($user);
    foreach (curriculum_stages($user) as $i => $stage) {
        if (($stage['id'] ?? '') === ($meta['stage']['id'] ?? '')) {
            return $i <= $frontier;
        }
    }
    return false;
}

/** @return array<int,array{id:string,title:string,outcome:string,done:bool,current:bool,unlocked:bool,skills:array}> */
function road_map(array $user): array
{
    $done = done_skill_ids($user);
    $frontier = current_stage_index($user);
    $next = next_skill($user);
    $nextId = $next['skill']['id'] ?? null;
    $built = $user['lessons'] ?? [];
    $out = [];

    foreach (curriculum_stages($user) as $i => $stage) {
        $skills = [];
        foreach (($stage['skills'] ?? []) as $skill) {
            $id = (string)$skill['id'];
            $skills[] = [
                'id'       => $id,
                'title'    => (string)$skill['title'],
                'outcome'  => (string)($skill['outcome'] ?? ''),
                'done'     => isset($done[$id]),
                'current'  => $id === $nextId,
                'unlocked' => $i <= $frontier,
                'built'    => isset($built[$id]),
            ];
        }
        $total = count($skills);
        $finished = count(array_filter($skills, static fn($s) => $s['done']));
        $out[] = [
            'id'         => (string)$stage['id'],
            'title'      => (string)$stage['title'],
            'outcome'    => (string)($stage['outcome'] ?? ''),
            'index'      => $i,
            'total'      => $total,
            'finished'   => $finished,
            'unlocked'   => $i <= $frontier,
            'mastery'    => (bool)($stage['mastery'] ?? false),
            'skills'     => $skills,
            'checkpoint' => checkpoint_public($user, $stage, $i),
        ];
    }
    return $out;
}

/* ================================================================== */
/* Curriculum plan: the structured document every lesson is built from */
/* ================================================================== */

function stage_meta(string $stageId): ?array
{
    foreach (syllabus()['stages'] as $stage) {
        if ((string)($stage['id'] ?? '') === $stageId) {
            return $stage;
        }
    }
    return null;
}

function plan_default_project(array $stage, int $index): array
{
    $skills = $stage['skills'] ?? [];
    $names = array_values(array_filter(array_map(
        static fn($s) => (string)($s['title'] ?? ''),
        $skills
    )));
    $lead = $names[0] ?? 'the basics';

    return [
        'title'        => 'Stage ' . ($index + 1) . ' checkpoint',
        'pitch'        => 'Build one small program that puts ' . mb_strtolower($lead) . ' to work.',
        'requirements' => [
            'Use at least three ideas from this stage',
            'Print or show a result someone else could understand',
            'Handle one thing that could go wrong',
        ],
        'skills'       => array_map(static fn($s) => (string)($s['id'] ?? ''), $skills),
    ];
}

function plan_default(array $user): array
{
    $stages = [];
    foreach (syllabus()['stages'] as $i => $stage) {
        $units = [];
        foreach (($stage['skills'] ?? []) as $skill) {
            $units[] = [
                'skill'      => (string)$skill['id'],
                'title'      => (string)$skill['title'],
                'focus'      => (string)($skill['outcome'] ?? ''),
                'concepts'   => array_slice((array)($skill['checks'] ?? []), 0, 4),
                'theme'      => '',
                'mix'        => ['text', 'code', 'mcq', 'fill', 'task'],
                'minutes'    => 12,
                'difficulty' => min(5, $i + 1),
            ];
        }
        $stages[] = [
            'id'       => (string)$stage['id'],
            'emphasis' => (string)($stage['outcome'] ?? ''),
            'units'    => $units,
            'project'  => plan_default_project($stage, $i),
        ];
    }

    return [
        'version'     => 1,
        'at'          => now_iso(),
        'model'       => '',
        'title'       => (string)(syllabus()['title'] ?? 'Your Python curriculum'),
        'throughline' => (string)(syllabus()['destination'] ?? ''),
        'fallback'    => true,
        'stages'      => $stages,
    ];
}

/** Clamp an AI plan onto the fixed syllabus, filling anything it skipped. */
function plan_normalize(array $raw, array $user): array
{
    $default = plan_default($user);
    $allowedMix = ['text', 'callout', 'code', 'mcq', 'fill', 'drag', 'task'];

    $stageById = [];
    foreach ((array)($raw['stages'] ?? []) as $s) {
        if (is_array($s)) {
            $stageById[(string)($s['id'] ?? '')] = $s;
        }
    }
    $unitById = [];
    foreach ($stageById as $s) {
        foreach ((array)($s['units'] ?? []) as $u) {
            if (is_array($u) && isset($u['skill'])) {
                $unitById[(string)$u['skill']] = $u;
            }
        }
    }

    foreach ($default['stages'] as &$stage) {
        $rawStage = $stageById[$stage['id']] ?? [];
        $emphasis = clamp_text($rawStage['emphasis'] ?? '', 400);
        if ($emphasis !== '') {
            $stage['emphasis'] = $emphasis;
        }

        foreach ($stage['units'] as &$unit) {
            $u = $unitById[$unit['skill']] ?? [];
            $unit['title'] = clamp_text($u['title'] ?? '', 120) ?: $unit['title'];
            $unit['focus'] = clamp_text($u['focus'] ?? '', 400) ?: $unit['focus'];
            $unit['theme'] = clamp_text($u['theme'] ?? '', 240);
            $concepts = str_list($u['concepts'] ?? [], 6, 120);
            if ($concepts !== []) {
                $unit['concepts'] = $concepts;
            }
            $mix = [];
            foreach ((array)($u['mix'] ?? []) as $m) {
                if (is_string($m) && in_array($m, $allowedMix, true)) {
                    $mix[] = $m;
                }
            }
            $mix = array_values(array_unique($mix));
            if (count($mix) >= 2) {
                $unit['mix'] = $mix;
            }
            $unit['minutes'] = max(5, min(30, (int)($u['minutes'] ?? $unit['minutes'])));
            $unit['difficulty'] = max(1, min(5, (int)($u['difficulty'] ?? $unit['difficulty'])));
        }
        unset($unit);

        $p = is_array($rawStage['project'] ?? null) ? $rawStage['project'] : [];
        $requirements = str_list($p['requirements'] ?? [], 5, 240);
        $stage['project'] = [
            'title'        => clamp_text($p['title'] ?? '', 140) ?: $stage['project']['title'],
            'pitch'        => clamp_text($p['pitch'] ?? '', 400) ?: $stage['project']['pitch'],
            'requirements' => $requirements !== [] ? $requirements : $stage['project']['requirements'],
            'skills'       => $stage['project']['skills'],
        ];
    }
    unset($stage);

    return [
        'version'     => 1,
        'at'          => now_iso(),
        'model'       => (string)($user['settings']['model'] ?? ''),
        'title'       => clamp_text($raw['title'] ?? '', 140) ?: $default['title'],
        'throughline' => clamp_text($raw['throughline'] ?? '', 500) ?: $default['throughline'],
        'fallback'    => false,
        'stages'      => $default['stages'],
    ];
}

/** Ask the model for the plan. Throws if it cannot produce a usable one. */
function plan_generate(array $user): array
{
    $settings = llm_settings($user);
    llm_require_key($settings);
    $messages = msgs_plan($user);
    $last = '';

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $res = llm_complete($settings, $messages, [
            'json'        => true,
            'temperature' => $attempt === 1 ? 0.8 : 0.45,
            'max_tokens'  => 7000,
        ]);
        $raw = extract_json((string)$res['text']);
        if ($raw !== null) {
            return plan_normalize($raw, $user);
        }
        $last = 'not valid JSON';
        $messages[] = ['role' => 'assistant', 'content' => mb_substr((string)$res['text'], 0, 1800)];
        $messages[] = [
            'role'    => 'user',
            'content' => 'That was unusable (' . $last . '). Return one corrected JSON object covering every skill id exactly once.',
        ];
    }

    throw new RuntimeException('The model could not produce a usable curriculum plan. Try again, or switch to a stronger model in Settings.');
}

function plan_ensure(array $user, bool $force = false): array
{
    $plan = $user['plan'] ?? null;
    if (!$force && is_array($plan) && !empty($plan['stages'])) {
        return $plan;
    }
    try {
        return plan_generate($user);
    } catch (RuntimeException $e) {
        // Never let plan-writing block learning. Fall back to the syllabus,
        // but keep the reason so the interface can explain itself.
        $fallback = plan_default($user);
        $fallback['note'] = $e->getMessage();
        return $fallback;
    }
}

/* ================================================================== */
/* The mastery track: what happens after the core 40                   */
/* ================================================================== */

const MASTERY_UNITS = 8;
const MASTERY_MAX_CHAPTERS = 100;

/** Advanced topics used when the model cannot write a mastery chapter. */
function mastery_topic_bank(): array
{
    return [
        ['Comprehensions that stay readable', 'Turn loops into comprehensions and generator expressions without making the code cryptic.', ['comprehension', 'generator', 'readability']],
        ['Decorators without the magic', 'Write a decorator that wraps a function, preserves its name and takes an argument.', ['first-class functions', 'wrappers', 'functools.wraps']],
        ['Context managers', 'Use with correctly, then write a class-based context manager that cleans up after an error.', ['with', '__enter__', 'cleanup']],
        ['Dataclasses and real models', 'Model a domain object with dataclasses, defaults and validation instead of loose dicts.', ['dataclass', 'defaults', 'validation']],
        ['Type hints that pay for themselves', 'Add hints to a small module and catch a bug before running it.', ['typing', 'Optional', 'mypy thinking']],
        ['Tests you will actually keep', 'Write unittest cases for happy paths, edges and failures.', ['unittest', 'edge cases', 'assertions']],
        ['Packages, imports and layouts', 'Turn a folder of scripts into an importable package with a clear entry point.', ['package', '__init__', 'entry point']],
        ['Profiling before optimising', 'Measure a slow function, find the real cost and make one evidence-based fix.', ['timeit', 'cProfile', 'complexity']],
        ['Async without the fog', 'Understand event loops, await and when async actually helps.', ['async', 'await', 'event loop']],
        ['Threads, processes and the GIL', 'Choose the right concurrency tool for I/O versus CPU work.', ['threading', 'multiprocessing', 'GIL']],
        ['CSV, JSON and messy data', 'Read real files, validate rows and report bad records instead of crashing.', ['csv', 'json', 'validation']],
        ['Talking to an HTTP API', 'Fetch JSON with urllib, handle timeouts and turn the response into objects.', ['urllib', 'status codes', 'timeouts']],
        ['Data structures by trade-off', 'Pick lists, sets, dicts, heaps and deques from their complexity, not habit.', ['complexity', 'heapq', 'deque']],
        ['Recursion and memoisation', 'Solve a recursive problem, then remove repeated work with memoisation.', ['recursion', 'memoisation', 'base case']],
        ['Memory and big files', 'Stream a large file and keep memory flat instead of loading it all.', ['streaming', 'memory', 'generators']],
        ['Architecture of a small app', 'Separate input, logic and output so the program can grow and be tested.', ['separation', 'interfaces', 'refactoring']],
    ];
}

function mastery_fallback(int $chapter): array
{
    $topics = mastery_topic_bank();
    $offset = (($chapter - 1) * MASTERY_UNITS) % max(1, count($topics));
    $units = [];
    for ($i = 0; $i < MASTERY_UNITS; $i++) {
        $topic = $topics[($offset + $i) % count($topics)];
        $round = (int)floor((($offset + $i) / count($topics)));
        $suffix = $round > 0 ? ' (deep dive ' . ($round + 1) . ')' : '';
        $units[] = [
            'skill'      => 'm' . $chapter . '.u' . ($i + 1),
            'title'      => $topic[0] . $suffix,
            'focus'      => $topic[1],
            'concepts'   => $topic[2],
            'theme'      => 'Use the learner\'s own goal as the running example.',
            'mix'        => ['text', 'code', 'mcq', 'task'],
            'minutes'    => 16,
            'difficulty' => 5,
        ];
    }

    return [
        'id'       => 'm' . $chapter,
        'title'    => 'Mastery chapter ' . $chapter . ': beyond the basics',
        'emphasis' => 'Now you are choosing tools by trade-off, not following a recipe. The work gets closer to what a working Python developer does.',
        'units'    => $units,
        'project'  => [
            'title'        => 'Mastery chapter ' . $chapter . ' build',
            'pitch'        => 'Take one advanced idea and make it earn its place in a real program.',
            'requirements' => [
                'Use at least two ideas from this chapter',
                'Keep the program readable without comments explaining the obvious',
                'Handle one realistic failure',
            ],
        ],
        'mastery'  => true,
        'fallback' => true,
        'at'       => now_iso(),
    ];
}

/** Clamp whatever the model wrote for a mastery chapter into our shape. */
function mastery_normalize(array $raw, array $user, int $chapter): array
{
    $fallback = mastery_fallback($chapter);
    $allowedMix = ['text', 'callout', 'code', 'mcq', 'fill', 'drag', 'task'];

    $rawUnits = [];
    foreach ((array)($raw['units'] ?? []) as $u) {
        if (is_array($u)) {
            $rawUnits[] = $u;
        }
    }

    $units = [];
    for ($i = 0; $i < MASTERY_UNITS; $i++) {
        $base = $fallback['units'][$i];
        $u = $rawUnits[$i] ?? [];

        $title = clamp_text($u['title'] ?? '', 120) ?: $base['title'];
        $focus = clamp_text($u['focus'] ?? '', 400) ?: $base['focus'];
        $concepts = str_list($u['concepts'] ?? [], 6, 120) ?: $base['concepts'];

        $mix = [];
        foreach ((array)($u['mix'] ?? []) as $m) {
            if (is_string($m) && in_array($m, $allowedMix, true)) {
                $mix[] = $m;
            }
        }
        $mix = array_values(array_unique($mix));
        if (count($mix) < 2) {
            $mix = $base['mix'];
        }

        $units[] = [
            'skill'      => 'm' . $chapter . '.u' . ($i + 1),
            'title'      => $title,
            'focus'      => $focus,
            'concepts'   => $concepts,
            'theme'      => clamp_text($u['theme'] ?? '', 240) ?: $base['theme'],
            'mix'        => $mix,
            'minutes'    => max(10, min(30, (int)($u['minutes'] ?? 16))),
            'difficulty' => 5,
        ];
    }

    $p = is_array($raw['project'] ?? null) ? $raw['project'] : [];
    $requirements = str_list($p['requirements'] ?? [], 5, 240);

    return [
        'id'       => 'm' . $chapter,
        'title'    => clamp_text($raw['title'] ?? '', 140) ?: $fallback['title'],
        'emphasis' => clamp_text($raw['emphasis'] ?? '', 400) ?: $fallback['emphasis'],
        'units'    => $units,
        'project'  => [
            'title'        => clamp_text($p['title'] ?? '', 140) ?: $fallback['project']['title'],
            'pitch'        => clamp_text($p['pitch'] ?? '', 400) ?: $fallback['project']['pitch'],
            'requirements' => $requirements !== [] ? $requirements : $fallback['project']['requirements'],
        ],
        'mastery'  => true,
        'model'    => (string)($user['settings']['model'] ?? ''),
        'at'       => now_iso(),
    ];
}

/** Write one mastery chapter. Never blocks the learner on a model failure. */
function mastery_generate(array $user, int $chapter): array
{
    try {
        $settings = llm_settings($user);
        llm_require_key($settings);
        $res = llm_complete($settings, msgs_mastery($user, $chapter, MASTERY_UNITS), [
            'json'        => true,
            'temperature' => 0.75,
            'max_tokens'  => 4200,
        ]);
        $raw = extract_json((string)$res['text']);
        if ($raw !== null && !empty($raw['units'])) {
            return mastery_normalize($raw, $user, $chapter);
        }
    } catch (RuntimeException $e) {
        // Fall through to the local topic bank.
    }

    $stage = mastery_fallback($chapter);
    $stage['note'] = 'The model did not return a usable chapter, so this one came from Cairn\'s advanced topic bank.';
    return $stage;
}

/** The next unused chapter number — plan gaps must not cause id collisions. */
function mastery_next_chapter(array $user): int
{
    $next = 1;
    foreach (mastery_stages($user) as $stage) {
        if (preg_match('/^m(\d+)$/', (string)($stage['id'] ?? ''), $m)) {
            $next = max($next, (int)$m[1] + 1);
        }
    }
    return $next;
}

function mastery_can_extend(array $user): bool
{
    $done = done_skill_ids($user);
    $coreDone = true;
    foreach (all_skills() as $row) {
        if (!isset($done[(string)($row['skill']['id'] ?? '')])) {
            $coreDone = false;
            break;
        }
    }
    if (!$coreDone) {
        return false;
    }

    $chapters = mastery_stages($user);
    if ($chapters === []) {
        return true;
    }
    if (count($chapters) >= 2 || !empty($user['mastery']['declared_at'])) {
        return false;
    }

    $last = end($chapters);
    if (!isset($user['checkpoints'][(string)($last['id'] ?? '')])) {
        return false;
    }
    foreach ((array)($last['units'] ?? []) as $unit) {
        if (!isset($done[(string)($unit['skill'] ?? '')])) {
            return false;
        }
    }
    return true;
}

function mastery_summary(array $user): array
{
    $done = done_skill_ids($user);
    $coreTotal = core_skill_count();
    $coreFinished = 0;
    foreach (all_skills() as $row) {
        if (isset($done[(string)($row['skill']['id'] ?? '')])) {
            $coreFinished++;
        }
    }

    $units = 0;
    $finished = 0;
    $checkpoints = 0;
    $passed = 0;
    foreach (mastery_stages($user) as $stage) {
        $checkpoints++;
        if (isset($user['checkpoints'][(string)($stage['id'] ?? '')])) {
            $passed++;
        }
        foreach ((array)($stage['units'] ?? []) as $unit) {
            $units++;
            if (isset($done[(string)($unit['skill'] ?? '')])) {
                $finished++;
            }
        }
    }

    $allDone = $units > 0 && $finished >= $units && $checkpoints > 0 && $passed >= $checkpoints;
    $coreDone = $coreFinished >= $coreTotal;

    return [
        'core_total'         => $coreTotal,
        'core_finished'      => $coreFinished,
        'core_done'          => $coreDone,
        'chapters'           => count(mastery_stages($user)),
        'max_chapters'       => MASTERY_MAX_CHAPTERS,
        'units'              => $units,
        'finished'           => $finished,
        'checkpoints'        => $checkpoints,
        'checkpoints_passed' => $passed,
        'all_done'           => $allDone,
        'can_extend'         => mastery_can_extend($user),
        'declared'           => !empty($user['mastery']['declared_at']),
        'declared_at'        => $user['mastery']['declared_at'] ?? null,
    ];
}

/** Once the core and two mastery chapters are fully passed, say so. */
function mastery_maybe_declare(array &$user): bool
{
    if (!empty($user['mastery']['declared_at'])) {
        return false;
    }
    $status = mastery_summary($user);
    if ($status['core_done'] && $status['chapters'] >= 2 && $status['all_done']) {
        $user['mastery'] = is_array($user['mastery'] ?? null) ? $user['mastery'] : [];
        $user['mastery']['declared_at'] = now_iso();
        $user['memory'] = is_array($user['memory'] ?? null) ? $user['memory'] : [];
        $user['memory']['facts'] = is_array($user['memory']['facts'] ?? null) ? $user['memory']['facts'] : [];
        $user['memory']['wins'][] = 'Mastered the full Python curriculum';
        $user['memory']['wins'] = array_slice(array_values(array_unique($user['memory']['wins'])), -12);
        memory_add($user, 'Finished the core 40 and the advanced mastery track. Treat them as a capable Python developer now.', 'strength', 'mastery');
        return true;
    }
    return false;
}

function unit_plan(array $user, string $skillId): array
{
    foreach (($user['plan']['stages'] ?? []) as $stage) {
        foreach (($stage['units'] ?? []) as $unit) {
            if ((string)($unit['skill'] ?? '') === $skillId) {
                return is_array($unit) ? $unit : [];
            }
        }
    }
    return [];
}

function plan_stage_project(array $user, string $stageId): array
{
    foreach (($user['plan']['stages'] ?? []) as $stage) {
        if ((string)($stage['id'] ?? '') === $stageId && is_array($stage['project'] ?? null)) {
            return $stage['project'];
        }
    }
    $stage = stage_for($user, $stageId);
    return $stage ? plan_default_project($stage, 0) : [];
}

/** The stage project's live status for the journey. */
function checkpoint_public(array $user, array $stage, int $index): array
{
    $stageId = (string)($stage['id'] ?? '');
    $skills = (array)($stage['skills'] ?? []);
    $doneIds = done_skill_ids($user);
    $finished = 0;
    foreach ($skills as $skill) {
        if (isset($doneIds[(string)($skill['id'] ?? '')])) {
            $finished++;
        }
    }
    $total = count($skills);
    $passed = isset($user['checkpoints'][$stageId]);

    $project = null;
    foreach (($user['projects'] ?? []) as $p) {
        if ((string)($p['stage'] ?? '') === $stageId) {
            $project = $p;
            break;
        }
    }

    $state = 'locked';
    if ($passed) {
        $state = 'passed';
    } elseif ($project !== null && !empty($project['review'])) {
        $state = 'reviewed';
    } elseif ($project !== null) {
        $state = 'in_progress';
    } elseif ($total > 0 && $finished >= $total) {
        $state = 'ready';
    }

    $plan = plan_stage_project($user, $stageId);

    return [
        'stage'        => $stageId,
        'title'        => (string)($plan['title'] ?? 'Stage checkpoint'),
        'pitch'        => (string)($plan['pitch'] ?? ''),
        'requirements' => (array)($plan['requirements'] ?? []),
        'state'        => $state,
        'projectId'    => $project['id'] ?? null,
        'score'        => $project['review']['score'] ?? null,
        'finished'     => $finished,
        'total'        => $total,
    ];
}

/** A serialisable view of the plan with live progress and build status. */
function plan_public(array $user): ?array
{
    $plan = $user['plan'] ?? null;
    if (!is_array($plan) || empty($plan['stages'])) {
        return null;
    }
    $done = done_skill_ids($user);
    $lessons = $user['lessons'] ?? [];
    $builtUnits = 0;
    $items = 0;
    $passed = 0;
    $stages = [];

    foreach ($plan['stages'] as $i => $stage) {
        $units = [];
        $finished = 0;
        foreach (($stage['units'] ?? []) as $u) {
            $skillId = (string)($u['skill'] ?? '');
            $isDone = isset($done[$skillId]);
            $isBuilt = isset($lessons[$skillId]);
            if ($isBuilt) {
                $builtUnits++;
                $items += count($lessons[$skillId]['blocks'] ?? []);
            }
            if ($isDone) {
                $finished++;
            }
            $units[] = [
                'skill'      => $skillId,
                'title'      => (string)($u['title'] ?? ''),
                'focus'      => (string)($u['focus'] ?? ''),
                'theme'      => (string)($u['theme'] ?? ''),
                'concepts'   => (array)($u['concepts'] ?? []),
                'mix'        => (array)($u['mix'] ?? []),
                'minutes'    => (int)($u['minutes'] ?? 12),
                'difficulty' => (int)($u['difficulty'] ?? 1),
                'done'       => $isDone,
                'built'      => $isBuilt,
            ];
        }
        $stageMeta = stage_meta((string)($stage['id'] ?? '')) ?? mastery_stage_public($stage, $i);
        $checkpoint = checkpoint_public($user, $stageMeta, $i);
        if ($checkpoint['state'] === 'passed') {
            $passed++;
        }
        $stages[] = [
            'id'       => (string)($stage['id'] ?? ''),
            'index'    => $i,
            'title'    => (string)($stageMeta['title'] ?? $stage['id'] ?? 'Stage'),
            'emphasis' => (string)($stage['emphasis'] ?? ''),
            'mastery'  => (bool)($stageMeta['mastery'] ?? false),
            'units'    => $units,
            'finished' => $finished,
            'total'    => count($units),
            'project'  => $checkpoint,
        ];
    }

    return [
        'title'       => (string)($plan['title'] ?? ''),
        'throughline' => (string)($plan['throughline'] ?? ''),
        'at'          => $plan['at'] ?? null,
        'model'       => (string)($plan['model'] ?? ''),
        'fallback'    => (bool)($plan['fallback'] ?? false),
        'note'        => (string)($plan['note'] ?? ''),
        'stages'      => $stages,
        'stats'       => [
            'units'             => array_sum(array_map(static fn($s) => count($s['units']), $stages)),
            'built'             => $builtUnits,
            'items'             => $items,
            'checkpoints'       => count($stages),
            'checkpointsPassed' => $passed,
        ],
    ];
}

/* ================================================================== */
/* The lesson bank: what is written, what is queued, what is next      */
/* ================================================================== */

function curriculum_bank(array $user): array
{
    $done = done_skill_ids($user);
    $lessons = $user['lessons'] ?? [];
    $units = curriculum_units($user);
    $built = 0;
    $items = 0;
    $pending = [];

    foreach ($units as $row) {
        $id = (string)$row['skill']['id'];
        if (isset($lessons[$id])) {
            $built++;
            $items += count($lessons[$id]['blocks'] ?? []);
        }
        if (!isset($done[$id])) {
            $pending[] = $id;
        }
    }
    foreach (($user['modules'] ?? []) as $module) {
        $items += count($module['lesson']['blocks'] ?? []);
    }

    $ahead = 0;
    $next = null;
    $queued = 0;
    foreach ($pending as $id) {
        if (isset($lessons[$id])) {
            $ahead++;
        } else {
            $queued++;
            if ($next === null) {
                $next = $id;
            }
        }
    }

    return [
        'total'      => count($units),
        'built'      => $built,
        'ahead'      => $ahead,
        'queued'     => $queued,
        'items'      => $items,
        'next'       => $next,
        'target'     => max(0, min(12, (int)($user['settings']['bank_target'] ?? 4))),
        'auto'       => (bool)($user['settings']['auto_build'] ?? true),
        'planning'   => empty($user['plan']['stages']),
        'plan_ready' => !empty($user['plan']['stages']),
        'last_at'    => $user['bank']['last_at'] ?? null,
        'last_error' => (string)($user['bank']['last_error'] ?? ''),
        'modules'    => count($user['modules'] ?? []),
        'mastery'    => count(mastery_stages($user)),
    ];
}

/** The next lesson worth writing ahead of the learner, in roadmap order. */
function curriculum_next_skill(array $user): ?string
{
    $done = done_skill_ids($user);
    $lessons = $user['lessons'] ?? [];
    foreach (curriculum_units($user) as $row) {
        $id = (string)$row['skill']['id'];
        if (!isset($done[$id]) && !isset($lessons[$id])) {
            return $id;
        }
    }
    return null;
}

/* ================================================================== */
/* User-requested modules                                              */
/* ================================================================== */

function find_module(array $user, string $id): ?array
{
    foreach (($user['modules'] ?? []) as $module) {
        if ((string)($module['id'] ?? '') === $id) {
            return $module;
        }
    }
    return null;
}

function module_summaries(array $user): array
{
    $out = [];
    foreach (($user['modules'] ?? []) as $module) {
        $lesson = $module['lesson'] ?? [];
        $out[] = [
            'id'         => (string)($module['id'] ?? ''),
            'title'      => (string)($lesson['title'] ?? 'Custom module'),
            'objective'  => (string)($lesson['objective'] ?? ''),
            'request'    => (string)($module['request'] ?? ''),
            'level'      => (string)($module['level'] ?? 'same'),
            'at'         => $module['at'] ?? null,
            'done'       => (bool)($module['done'] ?? false),
            'minutes'    => (int)($lesson['minutes'] ?? 10),
            'activities' => count(array_filter((array)($lesson['blocks'] ?? []), static fn($b) => in_array($b['type'] ?? '', ['code', 'mcq', 'fill', 'drag', 'task'], true))),
        ];
    }
    return $out;
}

/* ================================================================== */
/* One writer per learner at a time                                    */
/* ================================================================== */

/** @return resource|null */
function curriculum_lock(string $userId)
{
    $dir = DATA_DIR . '/locks';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $path = $dir . '/' . preg_replace('/[^a-f0-9]/', '', $userId) . '.lock';
    $fp = @fopen($path, 'c');
    if ($fp === false) {
        return null;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

function curriculum_unlock($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function progress_stats(array $user): array
{
    $units = curriculum_units($user);
    $done = done_skill_ids($user);
    $total = count($units);
    $finished = 0;
    foreach ($units as $row) {
        if (isset($done[(string)$row['skill']['id']])) {
            $finished++;
        }
    }
    $next = next_skill($user);
    $stages = curriculum_stages($user);
    $stageIndex = current_stage_index($user);
    $stage = $stages[$stageIndex] ?? ['id' => '', 'title' => '', 'mastery' => false];
    $mastery = mastery_summary($user);
    $coreStages = core_stage_count();
    $awaitingMastery = $mastery['core_done'] && $mastery['chapters'] === 0;
    $stageName = $awaitingMastery ? 'Mastery track' : (string)($stage['title'] ?? '');

    return [
        'total'         => $total,
        'finished'      => $finished,
        'percent'       => $total > 0 ? (int)round($finished / $total * 100) : 0,
        'stage'         => $stageIndex,
        'stageName'     => $stageName,
        'stageLabel'    => $stageIndex >= $coreStages
            ? $stageName
            : 'Stage ' . ($stageIndex + 1) . ' · ' . $stageName,
        'stageMastery'  => $stageIndex >= $coreStages,
        'nextSkill'     => $next['skill'] ?? null,
        'nextStage'     => $next['stage'] ?? null,
        'complete'      => $next === null && $mastery['declared'],
        'coreTotal'     => $mastery['core_total'],
        'coreFinished'  => $mastery['core_finished'],
        'masteryChapters' => $mastery['chapters'],
        'mastered'      => $mastery['declared'] || (
            $mastery['core_done']
            && $mastery['chapters'] >= 2
            && $mastery['all_done']
        ),
        'awaitingMastery' => $awaitingMastery,
    ];
}

/** Mark a skill finished and record the evidence. */
function complete_skill(array $user, string $skillId, float $score, string $note = ''): array
{
    $meta = skill_meta($skillId, $user);
    if ($meta === null) {
        return $user;
    }
    $entry = [
        'skill'  => $skillId,
        'title'  => (string)$meta['skill']['title'],
        'score'  => round(max(0, min(1, $score)), 2),
        'at'     => now_iso(),
        'note'   => clamp_text($note, 300),
    ];
    $user['journey']['completed'] = $user['journey']['completed'] ?? [];

    $replaced = false;
    foreach ($user['journey']['completed'] as $i => $c) {
        if (is_array($c) && ($c['skill'] ?? '') === $skillId) {
            $user['journey']['completed'][$i] = $entry;
            $replaced = true;
            break;
        }
    }
    if (!$replaced) {
        $user['journey']['completed'][] = $entry;
    }

    // A completed skill is no longer "skipped".
    $user['journey']['skipped'] = array_values(array_filter(
        $user['journey']['skipped'] ?? [],
        static fn($s) => $s !== $skillId
    ));

    mastery_maybe_declare($user);

    return $user;
}

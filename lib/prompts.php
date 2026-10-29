<?php
declare(strict_types=1);

/**
 * Prompt construction. Everything the model knows about a learner is
 * assembled here, which is what makes two learners' lessons different.
 */

const RUNTIME_NOTE = <<<TXT
RUNTIME FACTS (do not contradict these):
- The learner writes Python in a browser editor powered by Pyodide (CPython compiled to WebAssembly). It executes client-side, instantly.
- Standard library works. `import turtle` works and is rendered on an HTML canvas.
- `tkinter`, `pygame`, and `flet` CANNOT run in the browser. For those topics, write complete, correct code and mark it as "run this on your computer" — teach through reading, tracing and predicting, not through live execution.
- Third-party packages (requests, numpy) are NOT available. Use only the standard library in runnable code.
- `input()` is supported through an inputs box the learner fills in, but prefer programs that need no input.
- Total run time per program is capped at 10 seconds.
TXT;

function learner_brief(array $user): string
{
    $p = $user['profile'] ?? [];
    $placement = $user['placement'] ?? null;
    $memory = $user['memory'] ?? [];
    $stats = progress_stats($user);

    $lines = [];
    $lines[] = 'LEARNER: ' . ($user['display'] ?? $user['username'] ?? 'the learner');
    if (!empty($p['goal'])) {
        $lines[] = 'Their goal: ' . $p['goal'];
    }
    if (!empty($p['experience'])) {
        $lines[] = 'Prior experience: ' . $p['experience'];
    }
    if (!empty($p['time_per_week'])) {
        $lines[] = 'Time available: ' . $p['time_per_week'];
    }
    if (!empty($p['interests'])) {
        $lines[] = 'Interests (use these for examples when natural): ' . implode(', ', (array)$p['interests']);
    }
    if (!empty($p['explained'])) {
        $lines[] = 'How they describe themselves: ' . $p['explained'];
    }
    $lines[] = 'Progress: ' . $stats['finished'] . ' of ' . $stats['total'] . ' skills, currently in "' . $stats['stageName'] . '".';
    if (!empty($stats['mastered'])) {
        $lines[] = 'Mastery: they finished the core 40 and at least two advanced chapters. Treat them as a capable Python developer and skip beginner explanations.';
    } elseif (!empty($stats['stageMastery'])) {
        $lines[] = 'Mastery track: they are past the core 40. Assume fluency with the basics and talk trade-offs, not syntax.';
    } elseif (!empty($stats['awaitingMastery'])) {
        $lines[] = 'The core 40 are complete; their first advanced mastery chapter starts next.';
    }

    $doneTitles = [];
    foreach (($user['journey']['completed'] ?? []) as $c) {
        if (is_array($c) && !empty($c['title'])) {
            $doneTitles[] = $c['title'] . ' (' . (int)round(((float)($c['score'] ?? 0)) * 100) . '%)';
        }
    }
    if ($doneTitles) {
        $lines[] = 'Skills completed: ' . implode('; ', array_slice($doneTitles, -14));
    }

    if (is_array($placement)) {
        $lines[] = 'Placement test says: ' . ($placement['summary'] ?? 'no summary');
        if (!empty($placement['gaps'])) {
            $lines[] = 'Known gaps: ' . implode(', ', (array)$placement['gaps']);
        }
    }
    if (!empty($memory['struggles'])) {
        $lines[] = 'Has recently struggled with: ' . implode('; ', array_slice((array)$memory['struggles'], -5));
    }
    if (!empty($memory['wins'])) {
        $lines[] = 'Has recently nailed: ' . implode('; ', array_slice((array)$memory['wins'], -5));
    }
    if (!empty($memory['notes'])) {
        $lines[] = 'Tutor notes: ' . implode('; ', array_slice((array)$memory['notes'], -6));
    }
    $facts = memory_brief($user);
    if ($facts !== '') {
        $lines[] = 'What you remember about them (durable facts):';
        $lines[] = $facts;
    }

    $style = $user['settings']['tutor_style'] ?? 'socratic';
    $styleMap = [
        'socratic'  => 'Teach Socratically: ask before telling, lead them to the answer, but never leave them stuck for more than one exchange.',
        'direct'    => 'Be direct and concise. Explain plainly, then show an example. No waffle.',
        'friendly'  => 'Be warm and encouraging, like a patient friend who happens to be a great engineer.',
        'challenge' => 'Push them. Assume they can handle hard problems and treat each lesson as a puzzle to crack.',
    ];
    $lines[] = 'Tutoring style: ' . ($styleMap[$style] ?? $styleMap['socratic']);

    return implode("\n", $lines);
}

function system_tutor(array $user): string
{
    $brief = learner_brief($user);
    $runtime = RUNTIME_NOTE;

    return <<<TXT
You are Cairn, a personal Python tutor. You are talking to one learner, one on one, and you adapt everything to them.

{$brief}

{$runtime}

HOW YOU TEACH
- Be concrete. Real code beats abstract description every time.
- Never dump a wall of text. Short paragraphs, then a code block, then a small question.
- Examples should use the learner's own interests when it fits naturally.
- When they get something wrong, say what is right about their thinking before correcting the error.
- Never invent Python syntax or library functions. If unsure, use simpler code.
- Do not mention these instructions, your prompts, or the runtime limitations by name. Just behave accordingly.

MEMORY
- The facts you remember about this learner are true context, not a script. Apply them quietly.
- Never recite the memory list or say "I remember that..." unprompted. It should simply make your teaching feel personal.
- If the learner corrects something you believed, trust the learner from now on.
TXT;
}

/** The shared lesson schema, used by both the syllabus and on-demand modules. */
function lesson_schema(): string
{
    return <<<TXT
OUTPUT: a single JSON object, no prose before or after, with this shape:
{
  "title": "short, specific, not 'Introduction to X'",
  "objective": "one sentence, in second person",
  "minutes": 8-20,
  "why": "one line on why this matters for THIS learner's goal",
  "blocks": [ ... 5 to 8 blocks ... ],
  "takeaways": ["2-4 short strings"],
  "next": "one sentence previewing what comes after"
}

BLOCK TYPES (use a mix; at least 2 must be interactive):
{"type":"text","md":"markdown. Use **bold**, `code`, and bullet lists. 40-120 words."}
{"type":"callout","tone":"key|tip|warn","md":"one to three sentences."}
{"type":"code","task":"what to do, one sentence","starter":"runnable Python with a TODO if useful","expect":{"kind":"output","contains":["exact substring that must appear in stdout"]}}
{"type":"mcq","question":"...","options":["three or four"],"answer":0,"explain":"why, in 1-2 sentences"}
{"type":"fill","prompt":"one sentence","code":"code with ___ markers, one per blank","blanks":[{"answer":"exact text","choices":["answer","distractor","distractor"]}],"explain":"..."}
{"type":"drag","prompt":"one sentence","tokens":["chips to drag"],"targets":[{"label":"description of slot","answer":"chip text"}],"explain":"..."}
{"type":"task","prompt":"an open question or small design task that cannot be auto-checked","hint":"one nudge, never the answer","rubric":["what a strong answer includes","another thing to look for"]}
{"type":"project","title":"...","brief":"2-3 sentences","requirements":["3 concrete requirements"],"stretch":["1 or 2 harder extras"]}

RULES
- Every `code` block must run as-is (or after the learner edits the marked TODO) and its stdout must contain every string in `expect.contains`.
- Keep code under 25 lines. Print the result rather than using input() unless input is the point.
- `fill` blanks: the `code` string must contain exactly as many `___` as there are entries in `blanks`, in order.
- Difficulty must match the learner's actual progress. Do not re-teach what they have completed.
- The first block is always `text` or `callout`, never an activity.
- Vary the examples. Do not use "name = 'Alice'" or "calculate the area of a circle" unless it truly fits.
- When the unit plan says the mix includes task, include exactly one `task` block. The learner's answer is graded by AI, so the prompt must invite reasoning, not a single word.
- When the unit plan says the mix includes drag, fill or mcq, include at least one of those types too.
TXT;
}

/** @return array<int,array{role:string,content:string}> */
function msgs_lesson(array $user, array $row): array
{
    $skill = $row['skill'];
    $stage = $row['stage'];
    $unit = unit_plan($user, (string)$skill['id']);
    $meta = json_encode([
        'stage'   => $stage['title'],
        'outcome' => $stage['outcome'] ?? '',
        'skill'   => $skill['title'],
        'goal'    => $skill['outcome'] ?? '',
        'checks'  => $skill['checks'] ?? [],
        'unit'    => $unit !== [] ? $unit : null,
    ], JSON_UNESCAPED_SLASHES);

    $schema = lesson_schema();
    $system = system_tutor($user) . <<<TXT

TASK: write one lesson. It must be a self-contained learning experience that ends with the learner able to tick every "can do" check below.

{$meta}

{$schema}
TXT;

    $userLine = 'Write the lesson for skill "' . $skill['id'] . '". Respond with JSON only.';
    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userLine],
    ];
}

/** A module the learner explicitly asked for, outside the fixed syllabus. */
function msgs_module(array $user, string $request, string $level): array
{
    $schema = lesson_schema();
    $levelMap = [
        'easier' => 'one notch easier than their current level',
        'harder' => 'one notch harder, but still reachable on their own',
        'same'   => 'exactly at their current level',
    ];
    $levelText = $levelMap[$level] ?? $levelMap['same'];

    $system = system_tutor($user) . <<<TXT

TASK: write a one-off custom mini-lesson because the learner asked for it.
Their request: "{$request}"
Difficulty: {$levelText}.

This sits outside the fixed syllabus, but it must still connect to what they already know.
Open by naming what they will be able to do, teach one focused idea, then let them try it.
Do not pad it with background they did not ask for, and do not drift into a different topic.

{$schema}
TXT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => 'Write the requested module. Respond with JSON only.'],
    ];
}

/** The structured curriculum plan. One call, every skill covered. */
function msgs_plan(array $user): array
{
    $brief = learner_brief($user);

    $skeleton = [];
    foreach (syllabus()['stages'] as $stage) {
        $lines = [
            'STAGE ' . $stage['id'] . ' — ' . $stage['title'] . ' (' . ($stage['outcome'] ?? '') . ')',
        ];
        foreach (($stage['skills'] ?? []) as $skill) {
            $lines[] = '  ' . $skill['id'] . ': ' . $skill['title'] . ' — ' . ($skill['outcome'] ?? '')
                . ' | checks: ' . implode('; ', array_slice((array)($skill['checks'] ?? []), 0, 3));
        }
        $skeleton[] = implode("\n", $lines);
    }
    $skeletonText = implode("\n\n", $skeleton);

    $system = <<<TXT
You are Cairn's curriculum architect. You are designing a complete, personalised Python course for one learner. Another model will later write each lesson from your plan, so the plan must be concrete enough to teach from.

{$brief}

THE FIXED SYLLABUS (every skill id must appear exactly once, in this order):
{$skeletonText}

DESIGN RULES
- This is a spiral curriculum: each stage should reuse earlier ideas while adding one new capability.
- Write the plan for this learner, not a generic learner. Use their goal and interests for themes and examples when they fit.
- A unit is one lesson (8-20 minutes). Its focus must say exactly what the learner will do, not just name the topic.
- Each stage ends with one checkpoint project that integrates that stage's skills. Make the project small, buildable with the standard library (turtle is allowed), and visibly finishable in 45-90 minutes.
- Difficulty rises across the course, but never assume knowledge that is not in an earlier unit.
- Activity mix must suit the skill: include "code" everywhere; use "task" where reasoning matters; use "drag" for ordering; "fill" for recall; "mcq" for prediction; "callout"/"text" for framing.
- Themes must not repeat within a stage.

Return JSON only, exactly this shape:
{
  "title": "short name for this learner's course",
  "throughline": "2-3 sentences describing the arc from first line of code to their goal",
  "stages": [
    {
      "id": "s0",
      "emphasis": "what this stage is really about for this learner",
      "units": [
        {
          "skill": "s0.run",
          "title": "lesson title, specific and human",
          "focus": "the one idea and the one thing they will do with it",
          "concepts": ["2-4 terms or moves this unit teaches"],
          "theme": "the concrete example or context to build the lesson around",
          "mix": ["text","code","mcq","task"],
          "minutes": 12,
          "difficulty": 1
        }
      ],
      "project": {
        "title": "checkpoint project title",
        "pitch": "one sentence that makes them want to build it",
        "requirements": ["3-5 concrete, checkable requirements"]
      }
    }
  ]
}
TXT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => 'Write the complete curriculum plan. JSON only.'],
    ];
}

/**
 * The advanced track that begins after the fixed core. The learner has
 * finished all 40 core skills; this chapter should feel like real developer
 * work, not a re-run of the basics.
 */
function msgs_mastery(array $user, int $chapter, int $count = 8): array
{
    $brief = learner_brief($user);

    $covered = [];
    foreach (($user['journey']['completed'] ?? []) as $c) {
        if (is_array($c) && !empty($c['title'])) {
            $covered[] = (string)$c['title'];
        }
    }
    $coveredText = $covered !== []
        ? implode('; ', array_slice($covered, -60))
        : 'the core Python skills';

    $system = system_tutor($user) . <<<TXT

TASK: design chapter {$chapter} of an advanced mastery track for a learner who has completed the core curriculum.
They can already write, run and debug small programs. Do not re-teach variables, loops, functions, lists or basic classes.

Already covered (do not duplicate these lessons):
{$coveredText}

Write exactly {$count} units that each add one genuinely new capability. Favour the work a developer does after the basics: better structure, testing, files and data, the standard library, concurrency, performance, and the judgment to choose between tools. Use the learner's goal and interests for themes where they fit, but never force it.

Each unit is one 15-25 minute lesson that ends in something the learner can do. Order them so later units can reuse earlier ones. End the chapter with one checkpoint project that combines several units and is buildable in 60-120 minutes with the standard library only (turtle is allowed; no third-party packages).

Return JSON only, exactly this shape:
{
  "title": "chapter name, specific and human",
  "emphasis": "2-3 sentences on what this chapter changes about the way they write Python",
  "units": [
    {
      "title": "lesson title, specific and human",
      "focus": "the one idea and the one thing they will build with it",
      "concepts": ["2-4 terms or moves this unit teaches"],
      "theme": "the concrete example or context to build the lesson around",
      "mix": ["text","code","mcq","task"],
      "minutes": 18
    }
  ],
  "project": {
    "title": "checkpoint project title",
    "pitch": "one sentence that makes them want to build it",
    "requirements": ["3-5 concrete, checkable requirements"]
  }
}
TXT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => "Design mastery chapter {$chapter}. JSON only."],
    ];
}

function msgs_chat(array $user, array $history, array $context): array
{
    $system = system_tutor($user);

    $extra = [];
    if (!empty($context['lessonTitle'])) {
        $extra[] = 'They are in the middle of the lesson "' . clamp_text((string)$context['lessonTitle'], 120) . '".';
    }
    if (!empty($context['skillTitle'])) {
        $extra[] = 'The skill being practised is "' . clamp_text((string)$context['skillTitle'], 120) . '".';
    }
    if (!empty($context['code'])) {
        $code = clamp_text((string)$context['code'], 3000);
        $extra[] = "Their current editor content:\n```python\n" . $code . "\n```";
    }
    if (!empty($context['output'])) {
        $extra[] = "The last run printed:\n```\n" . clamp_text((string)$context['output'], 1500) . "\n```";
    }
    if ($extra !== []) {
        $system .= "\n\nCURRENT CONTEXT\n" . implode("\n", $extra);
    }

    $system .= "\n\nAnswer in markdown. Keep answers under 250 words unless the learner explicitly asks for a full explanation or a mini-lesson.";

    $messages = [['role' => 'system', 'content' => $system]];
    $clean = [];
    foreach ($history as $m) {
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = clamp_text((string)($m['content'] ?? ''), 4000);
        if ($content === '') {
            continue;
        }
        $clean[] = ['role' => $role, 'content' => $content];
    }
    $clean = array_slice($clean, -16);
    foreach ($clean as $m) {
        $messages[] = $m;
    }
    return $messages;
}

function msgs_grade(array $user, array $payload): array
{
    $kind = (string)($payload['kind'] ?? 'answer');
    $task = clamp_text((string)($payload['task'] ?? ''), 1200);
    $answer = clamp_text((string)($payload['answer'] ?? ''), 4000);
    $code = clamp_text((string)($payload['code'] ?? ''), 4000);
    $runs = clamp_text((string)($payload['output'] ?? ''), 2000);

    $system = system_tutor($user) . <<<TXT

TASK: grade one attempt. Be fair, specific and encouraging. Judge whether the learner has actually demonstrated the skill, not whether they matched a model answer.

Return JSON only:
{"score": 0.0, "passed": false, "feedback": "2-4 sentences, addressed to the learner as 'you'", "strengths": ["..."], "fixes": ["one concrete next step"], "misconception": "the specific wrong idea, or empty string"}
score is 0 to 1. passed is true when score >= 0.7.
TXT;

    $userMsg = "KIND: {$kind}\nTASK GIVEN: {$task}\nLEARNER'S ANSWER:\n{$answer}\n";
    if ($code !== '') {
        $userMsg .= "\nCODE THEY WROTE:\n```python\n{$code}\n```\n";
    }
    if ($runs !== '') {
        $userMsg .= "\nWHAT IT PRINTED:\n```\n{$runs}\n```\n";
    }
    $userMsg .= "\nGrade it. JSON only.";

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userMsg],
    ];
}

function msgs_placement_quiz(array $user): array
{
    $p = $user['profile'] ?? [];
    $ctx = 'Goal: ' . ($p['goal'] ?? 'not stated') . '. Experience: ' . ($p['experience'] ?? 'not stated') . '.';

    $system = <<<TXT
You are writing a placement quiz for a learner starting Python. {$ctx}

Write exactly 8 multiple-choice questions that together locate them on this ladder:
0 unscored/absolute beginner, 1 knows variables and printing, 2 control flow, 3 collections, 4 functions, 5 objects and files, 6 builds projects independently.

Rules:
- Order them from easy to hard, roughly: 3 beginner, 3 intermediate, 2 advanced.
- Each question must be answerable by *reading code and predicting behaviour* — never trivia about syntax trivia.
- Four options each, exactly one correct.
- No code longer than 8 lines.
- Do not include the answers anywhere.

Return JSON only:
{"questions":[{"id":"q1","topic":"...","question":"...","options":["...","...","...","..."],"code":"optional python snippet or empty string"}]}
TXT;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => 'Write the placement quiz. JSON only.'],
    ];
}

function msgs_placement_eval(array $user, array $questions, array $answers): array
{
    $lines = [];
    foreach ($questions as $i => $q) {
        $chosen = $answers[$q['id']] ?? null;
        $chosenText = is_int($chosen) ? ('option ' . ($chosen + 1) . ': ' . ($q['options'][$chosen] ?? '?')) : 'no answer';
        $lines[] = ($i + 1) . '. [' . ($q['topic'] ?? '') . '] ' . ($q['question'] ?? '')
            . "\n   chose: " . $chosenText
            . "\n   options: " . implode(' | ', (array)($q['options'] ?? []));
    }
    $transcript = implode("\n", $lines);

    $system = <<<TXT
You are placing a learner on a 6-stage Python curriculum (0 = absolute beginner, 5 = builds projects independently).

You do NOT have the answer key, so infer understanding from the pattern of choices, and be honest about uncertainty.

Return JSON only:
{
  "stage": 0-5,
  "startSkill": "one of the provided skill ids",
  "confidence": 0.0-1.0,
  "summary": "2-3 sentences addressed to the learner, no flattery",
  "strengths": ["up to 3"],
  "gaps": ["up to 3 concrete gaps to close first"]
}
TXT;

    $skillIds = [];
    foreach (all_skills() as $row) {
        $skillIds[] = $row['skill']['id'] . ' (' . $row['skill']['title'] . ')';
    }

    $userMsg = "QUESTIONS AND ANSWERS:\n{$transcript}\n\nVALID startSkill ids:\n" . implode("\n", $skillIds) . "\n\nPlace them. JSON only.";

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userMsg],
    ];
}

function msgs_project(array $user, string $mode, ?array $checkpoint = null): array
{
    $modeText = $mode === 'stretch' ? 'stretch' : ($mode === 'checkpoint' ? 'stage checkpoint' : 'guided');

    $checkpointText = '';
    if ($checkpoint !== null) {
        $plan = $checkpoint['project'] ?? [];
        $skillLines = [];
        foreach ((array)($checkpoint['skills'] ?? []) as $s) {
            $skillLines[] = '- ' . ($s['title'] ?? '') . ': ' . ($s['outcome'] ?? '');
        }
        $checkpointText = "\n\nCHECKPOINT CONTEXT"
            . "\nThis project closes the stage \"" . ($checkpoint['stageTitle'] ?? '') . "\"."
            . "\nThe curriculum plan wants: \"" . ($plan['title'] ?? 'a stage project') . "\" — " . ($plan['pitch'] ?? '')
            . "\nIt should integrate these skills:\n" . implode("\n", $skillLines)
            . "\nKeep it 45-90 minutes, finishable in one sitting, and visibly working at the end."
            . "\nTurn the plan's requirements into concrete, checkable ones.";
    }

    $system = system_tutor($user) . <<<TXT

TASK: design a project brief the learner builds on their own. Mode: {$modeText}
- "guided": a small project that takes 30-60 minutes and uses only what they have already learned.
- "stretch": a project one notch beyond their current level, deliberately requiring something they will have to look up.
- "stage checkpoint": the project at the end of a stage, integrating everything from that stage.{$checkpointText}

The project must be buildable with the Python standard library only (plus turtle). If it fits, it should connect to something they care about.

Return JSON only:
{
  "title": "...",
  "pitch": "one sentence that makes them want to build it",
  "brief": "2-4 sentences of context and scope",
  "milestones": [{"title":"...","detail":"what done looks like","minutes":15}],
  "requirements": ["3-5 concrete, checkable requirements"],
  "stretch": ["1-2 optional hard extras"],
  "rubric": [{"criterion":"...","weight":1-5}]
}
TXT;
    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => 'Design the project. JSON only.'],
    ];
}

function msgs_project_review(array $user, array $project, string $code, string $notes): array
{    $brief = clamp_text((string)($project['brief'] ?? ''), 1200);
    $req = implode('; ', array_slice((array)($project['requirements'] ?? []), 0, 6));

    $system = system_tutor($user) . <<<TXT

TASK: review the learner's project submission like a code reviewer who is also a teacher.

Judge: does it work, does it meet the requirements, is it readable, what is the single highest-leverage improvement.

Return JSON only:
{
  "score": 0.0-1.0,
  "verdict": "one sentence",
  "works": ["what is genuinely good"],
  "issues": [{"severity":"high|medium|low","where":"function or line area","what":"...","fix":"..."}],
  "next_step": "the one thing to do next",
  "concepts_to_revisit": ["skill ids from the curriculum if relevant"]
}
TXT;

    $userMsg = "PROJECT BRIEF: {$brief}\nREQUIREMENTS: {$req}\n\nTHEIR CODE:\n```python\n" . clamp_text($code, 6000) . "\n```\n\nTHEIR NOTES:\n" . clamp_text($notes, 1000) . "\n\nReview it. JSON only.";

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userMsg],
    ];
}

/**
 * The memory pass. Reads a short exchange and decides what is worth keeping
 * about the learner, so the tutor gets more personal without the learner
 * repeating themselves.
 */
function msgs_memory_extract(array $user, array $history, array $existing): array
{
    $p = $user['profile'] ?? [];
    $profile = [
        'goal'          => $p['goal'] ?? '',
        'experience'    => $p['experience'] ?? '',
        'time_per_week' => $p['time_per_week'] ?? '',
        'interests'     => $p['interests'] ?? [],
        'explained'     => $p['explained'] ?? '',
    ];

    $known = [];
    foreach ($existing as $f) {
        $known[] = '- ' . $f['id'] . ' [' . $f['kind'] . '] ' . $f['text'];
    }
    $knownText = $known === [] ? '(nothing yet)' : implode("\n", $known);

    $lines = [];
    foreach (array_slice($history, -8) as $m) {
        $role = ($m['role'] ?? '') === 'assistant' ? 'TUTOR' : 'LEARNER';
        $content = clamp_text((string)($m['content'] ?? ''), 1400);
        if ($content !== '') {
            $lines[] = $role . ': ' . $content;
        }
    }
    $transcript = implode("\n\n", $lines);

    $kinds = implode('|', MEMORY_KINDS);
    $system = <<<TXT
You maintain the long-term memory of a personal Python tutor. Your job is to notice what a human tutor would remember about this learner next week.

RULES
- Keep only durable facts that improve future teaching: goals, preferences, constraints, interests, recurring mistakes, demonstrated strengths, important context.
- Do NOT store lesson content, exercise answers, transient questions, code, or anything already known.
- Write each fact in the third person, under 22 words. Example: "Gets confused by list mutation inside functions."
- Do not infer sensitive traits. No guessing. If nothing is worth keeping, return an empty list.
- Remove a fact only when this exchange explicitly contradicts it.
- At most 4 facts per pass.

Return JSON only:
{"facts":[{"kind":"{$kinds}","text":"..."}],"remove":["fact id"]}
TXT;

    $userMsg = "LEARNER PROFILE:\n" . json_encode($profile, JSON_UNESCAPED_SLASHES)
        . "\n\nALREADY KNOWN (ids are only for removal):\n{$knownText}"
        . "\n\nNEW EXCHANGE:\n{$transcript}"
        . "\n\nWhat should be remembered? JSON only.";

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userMsg],
    ];
}

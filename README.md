# Cairn

An AI-curated Python tutor that runs on plain PHP and JSON files.

One shared curriculum, but no two learners see the same course. Cairn asks a
model you choose to write a structured plan for *this* person, then writes the
lessons ahead of them against what they already know and what they want to
build. It marks a skill complete when they can demonstrate it, remembers what
it learns about them, and sets a project at the end of every stage.

There is no database, no build step, no Composer, no Docker, and no server-side
code execution. Upload the folder to a PHP host and it runs.

---

## What is in the box

- **Placement test.** Eight code-reading questions, written on demand. The model
  places the learner on the ladder and the journey starts there, with earlier
  skills marked as known.
- **A journey, not a course list.** 40 core skills across 6 stages, from `print`
  to shipping a project. Skills unlock as stages are reached.
- **A mastery track beyond the 40.** When the core is finished, the model keeps
  writing advanced chapters of eight units each, with their own checkpoint
  projects. After two full chapters are passed, Cairn declares that the learner
  has mastered Python — and will keep writing more on request. See
  [Beyond the core](#beyond-the-core-the-mastery-track).
- **A curriculum forge.** A model-written plan for the whole course, a bank of
  lessons built several units ahead of the learner, and a workshop for asking
  for extra modules by name. See [The curriculum forge](#the-curriculum-forge).
- **Lessons generated per learner.** Text, callouts, runnable code, multiple
  choice, fill-in-the-blanks and drag-and-drop activities, written explanations
  graded by the model, plus a small project brief. Mixed formats, never the
  same twice.
- **Stage checkpoints.** Every stage ends with a project brief written from the
  learner's data. Passing it (70% or better on an AI review) is recorded and
  shown on the journey and in the forge.
- **A real Python editor.** Monaco, with Python running in the browser through
  Pyodide inside a Web Worker. `input()` works through an inputs box, output
  streams live, tracebacks are shown as Python wrote them.
- **Turtle graphics.** A pure-Python `turtle` shim records draw calls and the
  page replays them onto a canvas. Loops, fills, pen colours, text.
- **Tutor chat.** Streaming, and it can see the lesson title, the current editor
  contents and the last run's output.
- **Projects.** Guided and stretch briefs, with a reviewed submission that
  returns a score, specific issues and one next step.
- **Long-term memory.** The tutor keeps durable facts about the learner — goals,
  preferences, interests, struggles, strengths, context — written by lessons,
  grading, project reviews and a quiet pass over chat. The learner can pin,
  forget or add facts in Settings or from the Tutor header.
- **Bring your own model.** NVIDIA NIM by default; also OpenAI, OpenRouter,
  Groq, DeepSeek, Mistral, Together, Anthropic, Google Gemini, local Ollama, or
  any OpenAI-compatible endpoint.

---

## Requirements

- PHP **8.1+** with the `curl` and `openssl` extensions (both are standard).
- A web server. Apache with `AllowOverride All`, nginx, or the PHP built-in
  server for local use.
- Outbound HTTPS from the server to your model provider.
- Nothing else. Pyodide and Monaco load from jsDelivr in the learner's browser.

---

## Run it locally

```bash
cd cairn
php -S 127.0.0.1:8000 router.php
```

Open <http://127.0.0.1:8000>, create an account, then go to **Settings** and
connect a model.

Use `router.php`, not `php -S ... -t .` on its own: the built-in server ignores
`.htaccess`, and without the router the contents of `data/` are downloadable.

### First five minutes

1. Create an account.
2. **Settings → Model provider.** Pick a provider, paste a key, press
   **Test connection**.
   - NVIDIA NIM is the default and has a free tier: <https://build.nvidia.com/>
   - A fast, cheap model writes a perfectly good lesson. Only reach for a
     stronger one if lessons fail to parse.
3. **Settings → About you.** Fill in the goal, experience and interests. This is
   what makes the lessons yours; vague answers produce vague lessons.
4. **Journey → Take the placement test**, or skip it and start at the beginning.
5. Open the first lesson, run the code, answer the activities, mark it complete.
6. Open **Forge**. The plan is being written, and lessons start filling the bank
   a few units ahead. You do not have to wait for it — opening a unit writes it
   on demand.

---

## The curriculum forge

Cairn does not generate a course one lesson at a time from a blank page. It
first writes a **plan**, then builds a **bank** of lessons along that plan.

### The plan

One model call produces a per-learner plan covering all 40 skills: a title and
throughline, six stage emphases, a short brief for every unit (focus, concepts,
theme, difficulty, activity mix, minutes) and one **checkpoint project** per
stage. The plan is stored on the learner, so lesson generation is anchored to
the same arc every time.

If the model fails to produce a usable plan, Cairn falls back to a plan derived
from the syllabus and marks it clearly in the forge. Learning never blocks on a
failed planning call.

The plan is rebuilt when the learner presses **Redesign**, after a placement
result, or when their goal changes enough that the old plan no longer fits.
Finished skills stay finished; already-written lessons stay in the bank.

### The bank

The bank is the set of lessons already written for skills the learner has not
completed. **Forge → Keep N ahead** controls the target (1–10, default 4).
The background builder:

- runs one lesson at a time, in the learner's browser, while the app is open;
- pauses when the tab is hidden, so a forgotten tab does not spend tokens;
- backs off on errors, and surfaces the failure in the forge;
- uses a per-user file lock, so two tabs or the CLI cannot write at once.

Opening a unit whose lesson is not in the bank writes it on demand. That means
the learner is never blocked by the background builder being behind.

### Checkpoints

A stage's checkpoint unlocks when every skill in that stage is complete. The
project brief comes from the plan (or is written on demand), is reviewed by the
model like any other project, and is recorded as passed at **70% or better**.
The journey, the forge and the projects page all show the state.

### Beyond the core: the mastery track

40 is not a ceiling. The fixed syllabus is the **core**, and once every core
skill is done Cairn starts an AI-written advanced track on top of it.

- A **mastery chapter** is eight units plus one checkpoint project, generated
  from the learner's history. IDs are synthetic (`m1.u1` … `m1.u8`, `m2.u1` …)
  so chapters never collide with the syllabus.
- Chapter 1 is written automatically as soon as the core is complete. Chapter 2
  is written automatically after chapter 1's units **and** checkpoint are done.
  The background builder calls the `extend` action before it looks for lessons.
- With two complete chapters passed, Cairn stores
  `user['mastery']['declared_at']`, adds a memory fact and says plainly on the
  journey: **you have mastered Python**. Progress then shows a "mastered" ring
  rather than a percentage.
- After the declaration, **Keep going** in the journey or the forge asks for
  another chapter. Chapters are generated on demand with no practical ceiling
  (a safety limit of `MASTERY_MAX_CHAPTERS`, 100 chapters, guards the plan
  file), and adding one preserves the declaration.
- If the model cannot write a chapter, a local advanced topic bank fills in —
  decorators, context managers, dataclasses, testing, profiling, concurrency,
  data and layout work — so the track never stalls.
- Redesigning the core plan merges existing mastery chapters back in; they are
  never thrown away.
- The CLI understands the track too: `bin/pregen.php` extends and then warms
  mastery lessons exactly like core ones.

The plan, bank, checkpoints and forge all use the combined unit list, so the
counts grow past 40 (`48`, `56`, `64` …) as chapters are added.

### Modules

**Ask for a module** writes a one-off lesson outside the syllabus, at the
learner's level, with the same block types and a project to finish. Modules are
listed in the forge and marked done when finished.

### CLI: warming the bank

The browser is the normal builder. For a quiet site, or to pre-fill a demo
account, build from the command line:

```bash
php bin/pregen.php --user=alex --count=20
php bin/pregen.php --all --count=10
php bin/pregen.php --user=alex --stage=s2 --count=7
```

Each run uses the learner's configured provider, creates the plan if missing,
and writes one lesson at a time. It is safe to stop and rerun. A nightly cron
job works well:

```cron
0 4 * * * cd /var/www/cairn && php bin/pregen.php --all --count=5 >> data/pregen.log 2>&1
```

### Cost controls

Every generated lesson, module, review and plan is one model call. To keep spend
predictable:

- keep **Keep ahead** low (2–4 is plenty for one learner);
- turn **Build lessons in the background** off when you are not learning;
- use a cheap model for lessons and a stronger one only if parsing fails;
- use the CLI to cap bulk pre-generation at an explicit count;
- the bank never writes lessons for skills the learner has already completed.

---

## What Cairn remembers

`user['memory']['facts']` is a small, capped list of durable facts, sorted
pinned-first then newest. Each fact has a kind (`goal`, `preference`,
`interest`, `struggle`, `strength`, `context`), a source and a pinned flag.

Facts are written by:

- profile saves and placement results;
- lesson completion (strengths and things that needed help);
- AI grading of written answers and code reviews;
- project and checkpoint reviews;
- module requests;
- a quiet extraction pass over chat every other exchange.

Memory is included, as a compact brief, in every tutor, lesson, grading and
project prompt. It is best-effort: an extraction failure never breaks chat.
Learners can pin, forget or add facts in **Settings → What Cairn remembers** or
from the book button in the Tutor header. The list is capped at 60 facts.

---

## Deploying

Copy the whole `cairn/` folder to the host. The web root can be the folder
itself; there is no separate `public/` directory.

### Permissions

The web server needs to **write** to `data/`:

```bash
mkdir -p data/users
chmod 775 data data/users
chown -R www-data:www-data data      # or your host's PHP user
```

### Protect `data/`

Everything sensitive is in `data/`: password hashes, encrypted API keys, and
`secret.key`. It must not be reachable over HTTP.

**Apache** — already handled by `data/.htaccess` (`Require all denied`), as long
as `AllowOverride` is on. `data/secret.key` is created `0600`.

**nginx** — add this inside the `server { }` block:

```nginx
location ^~ /data/ {
    deny all;
    return 404;
}

# Optional: long-running AI calls and SSE streams
proxy_read_timeout 300s;
fastcgi_read_timeout 300s;
fastcgi_buffering off;   # so tutor chat streams token by token
```

If you cannot protect `data/` at the server level, move it above the web root
and change `DATA_DIR` in `config.php`.

### HTTPS

Use it. Session cookies are marked `Secure` automatically when the request is
HTTPS, and API keys travel from the browser to your server once, at setup.

---

## Providers

| Provider | Kind | Notes |
| --- | --- | --- |
| NVIDIA NIM | OpenAI-compatible | Default. Free credits via `build.nvidia.com`. |
| OpenAI | OpenAI-compatible | Paid account required. |
| OpenRouter | OpenAI-compatible | One key, many models, some free. |
| Groq | OpenAI-compatible | Very fast, generous free tier. |
| DeepSeek | OpenAI-compatible | Cheap and strong at code. |
| Mistral | OpenAI-compatible | European, solid code models. |
| Together AI | OpenAI-compatible | Hosts many open models. |
| Anthropic | Anthropic Messages API | Claude models. |
| Google Gemini | Gemini API | Free tier available. |
| Ollama | OpenAI-compatible | Runs on your own machine; no key. |
| Custom | OpenAI-compatible | Any `/v1/chat/completions` endpoint. |

Provider IDs, base URLs and the suggested model lists live in `providers()` in
`config.php`. Add an entry there and it appears in Settings automatically.

Keys are encrypted at rest with AES-256-GCM using `data/secret.key`. **Back that
file up.** If it is lost, every saved key must be re-entered. If it is leaked,
everything else in `data/` should be treated as exposed.

---

## Runtime limits (read this before writing lessons)

The learner's code runs in *their* browser, on Pyodide. That has consequences,
and the prompts tell the model about them explicitly (see `RUNTIME_NOTE` in
`lib/prompts.php`):

| Works | Does not work |
| --- | --- |
| The Python standard library | `tkinter` |
| `import turtle` (custom canvas renderer) | `pygame` |
| `input()` via the Inputs box | `flet` |
| `print`, exceptions, tracebacks | `requests`, `numpy`, `pandas`, any pip package |

Lessons on Pygame and Flet are therefore taught by **reading and predicting**
complete, correct code — not by running it. They are marked "run this on your
computer". If you later want real Pygame/Flet execution, that requires a
server-side sandbox, which this project deliberately does not have.

Other constraints:

- Each run is capped at N seconds (Settings → Run time limit, 10 s default).
  A runaway loop is killed by terminating the Worker, which is then respawned.
- First run downloads Pyodide (~10 MB, cached afterwards). Expect a pause on a
  slow connection.
- The page needs a Content-Security-Policy that allows `cdn.jsdelivr.net`,
  `blob:` workers and `wasm-unsafe-eval` if you set one at all.

---

## How a lesson stays honest

Answer keys never reach the browser. The server stores the full lesson in the
learner's JSON file, sends a stripped version (`lesson_public()` in
`lib/lesson.php`), and grades every activity through `api/lesson.php`
`action=check`. A learner can still read the network tab to see their own
progress, but they cannot read the answers.

Open-ended `task` blocks and code blocks are different: there is no answer key.
They are graded by the model through `api/grade.php`, which returns a score,
what worked, what to fix and any misconception it noticed. A review at 70% or
better counts. The result, and any durable fact worth keeping, is written to
memory.

Progress, memory of struggles and wins, and the placement summary are fed back
into every prompt, so later lessons target earlier mistakes.

---

## Layout

```
cairn/
  index.php              router + auth POST handling, renders login or app
  router.php             dev-server router that blocks data/
  config.php             paths, versions, provider catalogue, secret key
  .htaccess              Options -Indexes
  content/syllabus.json  the 40-skill curriculum: stages, skills, "can do" checks
  bin/
    pregen.php           CLI bank warmer (--user, --all, --count, --stage)
  lib/
    bootstrap.php        loads everything, builds the JSON payload for the UI
    util.php             escaping, CSRF, AES-GCM, SSE, JSON extraction
    store.php            one JSON file per learner, flock on every write
    auth.php             register / login / logout
    curriculum.php       roadmap, unlocking, progress, plan, bank, modules, locks
    memory.php           facts, profile sync, prompt brief, chat extraction
    llm.php              OpenAI-compatible + Anthropic + Gemini, streaming
    prompts.php          every prompt, plus the runtime facts
    lesson.php           validation, answer-stripping, server-side checking
  api/                   state, settings, placement, lesson, grade, chat, project,
                         curriculum (plan/pregen/modules), memory
  views/                 login.php, app.php (shell + boot config)
  assets/
    css/app.css
    js/                  app shell, journey, forge, lesson, playground, chat,
                         projects, memory, builder (background pre-generation)
    js/python-worker.js  Pyodide bootstrap, stdout bridge, run/traceback capture
    js/runner.js         worker lifecycle + timeout + turtle canvas renderer
    py/turtle_shim.py    pure-Python turtle that emits drawing commands
  data/                  created on first run — users, locks, index, secret key
```

---

## Customising

- **Curriculum** — `content/syllabus.json`. Stages, skills, outcomes and the
  "can do" checks the model must satisfy. IDs are referenced by progress, so
  renaming an ID resets that skill for existing learners.
- **Voice and rules** — `lib/prompts.php`. `system_tutor()` is shared by every
  feature; `msgs_lesson()` defines the lesson JSON shape and block types.
- **Activity types** — add a block in `lesson_block()`, strip its answer in
  `lesson_public()`, check it in `lesson_check()`, render it in
  `assets/js/lesson.js`.
- **Look** — `assets/css/app.css`. Colours are CSS variables at the top.

---

## Troubleshooting

**"The model kept returning unusable lessons."**
The lesson schema is strict. Try a stronger model, or lower Settings →
Creativity. The server retries once with the parse error attached.

**Tutor chat arrives all at once.**
Your host is buffering the SSE stream. On nginx set `fastcgi_buffering off;` and
disable gzip for `text/event-stream`. The client falls back to a normal request
if the stream breaks.

**"Could not download the code editor" / Python never starts.**
A firewall, proxy or CSP is blocking `cdn.jsdelivr.net`, or Web Workers. Both
are required.

**A saved key stopped working.**
Check `data/secret.key` still exists and the learner has not pressed **Remove
key**. Re-entering the key is always safe.

**The forge paused / lessons are not building.**
Open **Forge** — the reason is printed above the plan. Common causes: no key,
a provider error, or the model returning unparseable JSON. Press **Build 3 now**
to retry. If the tab was hidden, the builder pauses on purpose; bring it back
and it resumes. You can always open a unit directly, which writes it on demand.
Two tabs might also be contending for the per-user lock; close one.

**Memory keeps filling with things I do not care about.**
Open the book button in the Tutor header (or Settings) and forget the fact.
Extraction is deliberately conservative but not perfect; a corrrection in chat
is remembered too.

**Progress disappeared.**
Each learner is one file in `data/users/`. Restore the folder and the username
index in `data/names.json` together.

**Backups.** Copy `data/` — it is the entire application state. Stop the site or
accept that files copied mid-write may be stale; every write is atomic, so you
will never get a half-written file.

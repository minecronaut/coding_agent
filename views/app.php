<?php
/** @var array $state */
$u = $state['user'];
$initial = mb_strtoupper(mb_substr($u['display'] !== '' ? $u['display'] : $u['username'], 0, 1));

function nav_icon(string $name): string
{
    $paths = [
        'journey'    => '<path d="M9 4 3 6.5v13L9 17l6 2.5 6-2.5v-13L15 6.5 9 4Z"/><path d="M9 4v13M15 6.5v13"/>',
        'curriculum' => '<path d="M4 20 16 8M14 6l4 4M6 4v3M4.5 5.5h3M18 14v3M16.5 15.5h3M11 3l1 2 2 1-2 1-1 2-1-2-2-1 2-1 1-2Z"/>',
        'playground' => '<path d="m8 6-5 6 5 6M16 6l5 6-5 6"/>',
        'chat'       => '<path d="M21 12a8 8 0 0 1-8 8H7l-4 3v-6.5A8 8 0 1 1 21 12Z"/>',
        'projects'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
        'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15a2 2 0 0 1 0-4 1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 4.1V4a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 21 11a2 2 0 0 1 0 4Z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%235eead4'/><path d='M9 22l4-12 5 8 3-5 3 9' stroke='%23071a18' stroke-width='2.4' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">C</span>
      <span><?= h(APP_NAME) ?></span>
    </div>

    <nav class="nav">
      <button class="nav-item active" data-view="journey">
        <?= nav_icon('journey') ?><span>Journey</span><span class="badge" id="nav-badge"><?= (int)($state['progress']['percent'] ?? 0) ?>%</span>
      </button>
      <button class="nav-item" data-view="curriculum">
        <?= nav_icon('curriculum') ?><span>Forge</span><span class="badge" id="nav-bank"><?= (int)($state['bank']['built'] ?? 0) ?> built</span>
      </button>
      <button class="nav-item" data-view="playground">
        <?= nav_icon('playground') ?><span>Playground</span>
      </button>
      <button class="nav-item" data-view="chat">
        <?= nav_icon('chat') ?><span>Tutor</span>
      </button>
      <button class="nav-item" data-view="projects">
        <?= nav_icon('projects') ?><span>Projects</span>
      </button>
      <button class="nav-item" data-view="settings">
        <?= nav_icon('settings') ?><span>Settings</span>
      </button>
    </nav>

    <div class="sidebar-foot">
      <div class="mini-progress">
        <div class="row"><span>Curriculum</span><b id="side-count"><?= (int)($state['progress']['finished'] ?? 0) ?>/<?= (int)($state['progress']['total'] ?? 0) ?></b></div>
        <div class="bar"><i id="side-bar" style="width:<?= (int)($state['progress']['percent'] ?? 0) ?>%"></i></div>
        <div class="row" style="margin-top:9px"><span>Bank ahead</span><b id="side-bank"><?= (int)($state['bank']['ahead'] ?? 0) ?>/<?= (int)($state['bank']['target'] ?? 0) ?></b></div>
      </div>
      <div class="user-chip">
        <span class="avatar" id="side-avatar"><?= h($initial) ?></span>
        <span class="who">
          <span id="side-name"><?= h($u['display']) ?></span>
          <small>@<?= h($u['username']) ?></small>
        </span>
        <a class="icon-btn" href="?page=logout" title="Sign out" style="margin-left:auto">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <main class="main">
    <div id="view"></div>
  </main>
</div>

<script>
window.__CAIRN__ = <?= json_encode(
    ['state' => $state],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
) ?>;
window.CAIRN_CFG = {
  pyodideUrl: 'https://cdn.jsdelivr.net/pyodide/v<?= h(PYODIDE_VERSION) ?>/full/',
  monacoUrl: 'https://cdn.jsdelivr.net/npm/monaco-editor@<?= h(MONACO_VERSION) ?>/min/vs',
  workerUrl: new URL('assets/js/python-worker.js', document.baseURI).href,
  shimUrl: new URL('assets/py/turtle_shim.py', document.baseURI).href,
  timeout: <?= (int)($u['settings']['timeout'] ?? 10) ?>
};
</script>
<script type="module" src="assets/js/app.js"></script>
</body>
</html>

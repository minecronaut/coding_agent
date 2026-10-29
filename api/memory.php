<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
$body = body_json();
$action = (string)($body['action'] ?? 'list');

try {
    if ($action === 'list') {
        json_out(['ok' => true, 'facts' => memory_facts($user)]);
    }

    if ($action === 'add') {
        $text = clamp_text($body['text'] ?? '', 300);
        $kind = (string)($body['kind'] ?? 'context');
        if (trim($text) === '') {
            fail('Write something to remember first.');
        }
        $updated = Store::update($user['id'], function (array &$u) use ($text, $kind) {
            memory_add($u, $text, $kind, 'manual', true);
        });
        if ($updated === null) {
            fail('Could not save that.', 500);
        }
        json_out([
            'ok'    => true,
            'facts' => memory_facts($updated),
            'user'  => user_public($updated),
        ]);
    }

    if ($action === 'update') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            fail('Missing memory id.');
        }
        $patch = [];
        if (isset($body['text'])) {
            $patch['text'] = (string)$body['text'];
        }
        if (isset($body['kind'])) {
            $patch['kind'] = (string)$body['kind'];
        }
        if (isset($body['pinned'])) {
            $patch['pinned'] = (bool)$body['pinned'];
        }
        $updated = Store::update($user['id'], function (array &$u) use ($id, $patch) {
            memory_update($u, $id, $patch);
        });
        if ($updated === null) {
            fail('Could not update that.', 500);
        }
        json_out([
            'ok'    => true,
            'facts' => memory_facts($updated),
            'user'  => user_public($updated),
        ]);
    }

    if ($action === 'delete') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            fail('Missing memory id.');
        }
        $updated = Store::update($user['id'], function (array &$u) use ($id) {
            memory_remove($u, $id);
        });
        if ($updated === null) {
            fail('Could not forget that.', 500);
        }
        json_out([
            'ok'    => true,
            'facts' => memory_facts($updated),
            'user'  => user_public($updated),
        ]);
    }

    if ($action === 'extract') {
        $history = [];
        foreach ((array)($body['history'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = clamp_text($m['content'] ?? '', 1500);
            if ($content !== '') {
                $history[] = ['role' => $role, 'content' => $content];
            }
        }
        if ($history === []) {
            fail('There is no conversation to learn from yet.');
        }

        $report = [];
        $updated = Store::update($user['id'], function (array &$u) use ($history, &$report) {
            $report = memory_extract($u, $history);
        });
        if ($updated === null) {
            fail('Could not update memory.', 500);
        }

        json_out([
            'ok'      => true,
            'added'   => $report['added'] ?? [],
            'removed' => $report['removed'] ?? [],
            'error'   => $report['error'] ?? '',
            'facts'   => memory_facts($updated),
            'user'    => user_public($updated),
        ]);
    }

    fail('Unknown action.');
} catch (RuntimeException $e) {
    fail($e->getMessage(), 400);
}

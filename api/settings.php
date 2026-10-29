<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
api_boot();

$user = require_user();
$body = body_json();
$action = (string)($body['action'] ?? 'save');

try {
    if ($action === 'save') {
        $providerId = (string)($body['provider'] ?? 'nvidia');
        if (provider($providerId) === null) {
            fail('Unknown provider.');
        }
        $model = trim((string)($body['model'] ?? ''));
        $base = trim((string)($body['base_url'] ?? ''));
        $newKey = trim((string)($body['api_key'] ?? ''));
        $temp = (float)($body['temperature'] ?? 0.7);
        $style = (string)($body['tutor_style'] ?? 'socratic');
        $timeout = (int)($body['timeout'] ?? 10);

        if (!in_array($style, ['socratic', 'direct', 'friendly', 'challenge'], true)) {
            $style = 'socratic';
        }

        $updated = Store::update($user['id'], function (array &$u) use ($providerId, $model, $base, $newKey, $temp, $style, $timeout) {
            $u['settings']['provider'] = $providerId;
            $u['settings']['model'] = $model;
            $u['settings']['base_url'] = $base;
            $u['settings']['temperature'] = max(0.0, min(1.2, $temp));
            $u['settings']['tutor_style'] = $style;
            $u['settings']['timeout'] = max(3, min(30, $timeout));
            if ($newKey !== '') {
                $u['settings']['key_enc'] = encrypt_secret($newKey);
            }
        });

        if ($updated === null) {
            fail('Could not save settings.', 500);
        }
        json_out(['ok' => true, 'user' => user_public($updated)]);
    }

    if ($action === 'clear_key') {
        $updated = Store::update($user['id'], function (array &$u) {
            $u['settings']['key_enc'] = '';
        });
        json_out(['ok' => true, 'user' => user_public($updated ?? $user)]);
    }

    if ($action === 'profile') {
        $profile = is_array($body['profile'] ?? null) ? $body['profile'] : [];
        $updated = Store::update($user['id'], function (array &$u) use ($profile) {
            $u['profile'] = [
                'goal'          => clamp_text($profile['goal'] ?? ($u['profile']['goal'] ?? ''), 400),
                'experience'    => clamp_text($profile['experience'] ?? ($u['profile']['experience'] ?? ''), 400),
                'time_per_week' => clamp_text($profile['time_per_week'] ?? ($u['profile']['time_per_week'] ?? ''), 120),
                'interests'     => str_list($profile['interests'] ?? ($u['profile']['interests'] ?? []), 8, 60),
                'explained'     => clamp_text($profile['explained'] ?? ($u['profile']['explained'] ?? ''), 1200),
            ];
            if (!empty($profile['display'])) {
                $u['display'] = clamp_text((string)$profile['display'], 60);
            }
            memory_sync_profile($u);
        });
        if ($updated === null) {
            fail('Could not save profile.', 500);
        }
        json_out(['ok' => true, 'user' => user_public($updated)]);
    }

    if ($action === 'test') {
        $settings = llm_settings($user, [
            'provider'  => (string)($body['provider'] ?? ''),
            'model'     => (string)($body['model'] ?? ''),
            'base_url'  => (string)($body['base_url'] ?? ''),
            'api_key'   => (string)($body['api_key'] ?? ''),
            'max_tokens'=> 32,
        ]);
        $started = microtime(true);
        $res = llm_complete($settings, [
            ['role' => 'system', 'content' => 'Reply with exactly: READY'],
            ['role' => 'user', 'content' => 'Test.'],
        ], ['temperature' => 0, 'max_tokens' => 16]);
        $ms = (int)round((microtime(true) - $started) * 1000);
        json_out([
            'ok'      => true,
            'reply'   => clamp_text(trim((string)$res['text']), 120),
            'ms'      => $ms,
            'model'   => $settings['model'],
        ]);
    }

    if ($action === 'models') {
        $settings = llm_settings($user, [
            'provider' => (string)($body['provider'] ?? ''),
            'base_url' => (string)($body['base_url'] ?? ''),
            'api_key'  => (string)($body['api_key'] ?? ''),
        ]);
        $models = llm_models($settings);
        json_out(['ok' => true, 'models' => $models]);
    }

    fail('Unknown action.');
} catch (RuntimeException $e) {
    fail($e->getMessage(), 400);
}

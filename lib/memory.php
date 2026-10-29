<?php
declare(strict_types=1);

/**
 * Cairn's long-term memory.
 *
 * The tutor is allowed to keep durable facts about the learner: what they want
 * to build, how they like to be taught, what keeps tripping them up. Lessons,
 * grading and project reviews all read from and write to this list, so the
 * curriculum gets more personal the longer somebody stays.
 */

const MEMORY_KINDS = ['goal', 'preference', 'interest', 'struggle', 'strength', 'context'];
const MEMORY_MAX = 60;

function memory_norm(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function memory_fact_public(array $f): array
{
    return [
        'id'     => (string)($f['id'] ?? ''),
        'kind'   => in_array(($f['kind'] ?? ''), MEMORY_KINDS, true) ? (string)$f['kind'] : 'context',
        'text'   => (string)($f['text'] ?? ''),
        'at'     => $f['at'] ?? null,
        'source' => (string)($f['source'] ?? 'tutor'),
        'pinned' => !empty($f['pinned']),
    ];
}

/** @return array<int,array{id:string,kind:string,text:string,at:mixed,source:string,pinned:bool}> */
function memory_facts(array $user): array
{
    $facts = $user['memory']['facts'] ?? [];
    if (!is_array($facts)) {
        return [];
    }
    $out = [];
    foreach ($facts as $f) {
        if (!is_array($f)) {
            continue;
        }
        $text = clamp_text($f['text'] ?? '', 300);
        if ($text === '') {
            continue;
        }
        $out[] = memory_fact_public([
            'id'     => $f['id'] ?? short_id(5),
            'kind'   => $f['kind'] ?? 'context',
            'text'   => $text,
            'at'     => $f['at'] ?? null,
            'source' => $f['source'] ?? 'tutor',
            'pinned' => $f['pinned'] ?? false,
        ]);
    }

    usort($out, static function (array $a, array $b): int {
        if ($a['pinned'] !== $b['pinned']) {
            return $a['pinned'] ? -1 : 1;
        }
        return strcmp((string)$b['at'], (string)$a['at']);
    });
    return $out;
}

function memory_add(array &$user, string $text, string $kind = 'context', string $source = 'tutor', bool $pinned = false): ?array
{
    $text = trim(preg_replace('/\s+/u', ' ', clamp_text($text, 300)) ?? '');
    if (mb_strlen($text) < 4) {
        return null;
    }
    if (!in_array($kind, MEMORY_KINDS, true)) {
        $kind = 'context';
    }

    $facts = is_array($user['memory']['facts'] ?? null) ? $user['memory']['facts'] : [];
    $norm = memory_norm($text);
    foreach ($facts as $i => $f) {
        if (!is_array($f)) {
            continue;
        }
        if ($norm !== '' && memory_norm((string)($f['text'] ?? '')) === $norm) {
            $facts[$i]['at'] = now_iso();
            if ($source !== '') {
                $facts[$i]['source'] = $source;
            }
            if ($pinned) {
                $facts[$i]['pinned'] = true;
            }
            $user['memory']['facts'] = $facts;
            return memory_fact_public($facts[$i]);
        }
    }

    $fact = [
        'id'     => short_id(5),
        'kind'   => $kind,
        'text'   => $text,
        'at'     => now_iso(),
        'source' => $source,
        'pinned' => $pinned,
    ];
    array_unshift($facts, $fact);

    if (count($facts) > MEMORY_MAX) {
        $pinnedFacts = array_values(array_filter($facts, static fn($f) => !empty($f['pinned'])));
        $recent = array_values(array_filter($facts, static fn($f) => empty($f['pinned'])));
        $facts = array_slice(array_merge($pinnedFacts, $recent), 0, MEMORY_MAX);
    }

    $user['memory']['facts'] = $facts;
    return memory_fact_public($fact);
}

function memory_remove(array &$user, string $id): bool
{
    $facts = is_array($user['memory']['facts'] ?? null) ? $user['memory']['facts'] : [];
    $before = count($facts);
    $facts = array_values(array_filter($facts, static fn($f) => !is_array($f) || (string)($f['id'] ?? '') !== $id));
    $user['memory']['facts'] = $facts;
    return count($facts) !== $before;
}

function memory_update(array &$user, string $id, array $patch): ?array
{
    $facts = is_array($user['memory']['facts'] ?? null) ? $user['memory']['facts'] : [];
    foreach ($facts as $i => $f) {
        if (!is_array($f) || (string)($f['id'] ?? '') !== $id) {
            continue;
        }
        if (isset($patch['text'])) {
            $text = trim(preg_replace('/\s+/u', ' ', clamp_text((string)$patch['text'], 300)) ?? '');
            if ($text !== '') {
                $facts[$i]['text'] = $text;
            }
        }
        if (isset($patch['kind']) && in_array($patch['kind'], MEMORY_KINDS, true)) {
            $facts[$i]['kind'] = (string)$patch['kind'];
        }
        if (isset($patch['pinned'])) {
            $facts[$i]['pinned'] = (bool)$patch['pinned'];
        }
        $facts[$i]['at'] = now_iso();
        $user['memory']['facts'] = $facts;
        return memory_fact_public($facts[$i]);
    }
    return null;
}

/**
 * Turn the profile fields into facts. Profile-sourced facts are replaced, not
 * duplicated, so editing the profile updates memory instead of piling up.
 */
function memory_sync_profile(array &$user): void
{
    $facts = is_array($user['memory']['facts'] ?? null) ? $user['memory']['facts'] : [];
    $pinned = [];
    foreach ($facts as $f) {
        if (is_array($f) && ($f['source'] ?? '') === 'profile' && !empty($f['pinned'])) {
            $pinned[memory_norm((string)($f['text'] ?? ''))] = true;
        }
    }
    $facts = array_values(array_filter(
        $facts,
        static fn($f) => !is_array($f) || ($f['source'] ?? '') !== 'profile'
    ));
    $user['memory']['facts'] = $facts;

    $p = $user['profile'] ?? [];
    $add = static function (string $text, string $kind, bool $pin = false) use (&$user, $pinned): void {
        memory_add($user, $text, $kind, 'profile', $pin || isset($pinned[memory_norm($text)]));
    };

    if (!empty($p['goal'])) {
        $add('Wants to build: ' . (string)$p['goal'], 'goal', true);
    }
    if (!empty($p['experience'])) {
        $add('Prior experience: ' . (string)$p['experience'], 'context');
    }
    if (!empty($p['time_per_week'])) {
        $add('Study time: ' . (string)$p['time_per_week'], 'context');
    }
    if (!empty($p['interests'])) {
        $add('Interests: ' . implode(', ', (array)$p['interests']), 'interest');
    }
    if (!empty($p['explained'])) {
        $add('In their own words: ' . (string)$p['explained'], 'context');
    }
}

/** Facts formatted for a system prompt. Pinned first, then newest. */
function memory_brief(array $user, int $limit = 12): string
{
    $facts = memory_facts($user);
    if ($facts === []) {
        return '';
    }
    $lines = [];
    foreach (array_slice($facts, 0, $limit) as $f) {
        $lines[] = '- [' . $f['kind'] . '] ' . $f['text'];
    }
    return implode("\n", $lines);
}

/**
 * Read a short exchange and let the model decide what is worth keeping.
 * Returns the facts added/removed; the caller persists $user.
 *
 * @return array{added:array,removed:array,error:string}
 */
function memory_extract(array &$user, array $history): array
{
    $settings = llm_settings($user);
    llm_require_key($settings);

    $existing = memory_facts($user);
    $res = llm_complete($settings, msgs_memory_extract($user, $history, $existing), [
        'json'        => true,
        'temperature' => 0.2,
        'max_tokens'  => 900,
    ]);
    $raw = extract_json((string)$res['text']);
    if ($raw === null) {
        return ['added' => [], 'removed' => [], 'error' => 'The memory pass returned unreadable output.'];
    }

    $added = [];
    foreach ((array)($raw['facts'] ?? []) as $f) {
        if (!is_array($f)) {
            continue;
        }
        $text = clamp_text($f['text'] ?? '', 240);
        if (mb_strlen($text) < 8) {
            continue;
        }
        $kind = in_array(($f['kind'] ?? ''), MEMORY_KINDS, true) ? (string)$f['kind'] : 'context';
        $fact = memory_add($user, $text, $kind, 'tutor');
        if ($fact !== null) {
            $added[] = $fact;
        }
        if (count($added) >= 5) {
            break;
        }
    }

    $removed = [];
    foreach ((array)($raw['remove'] ?? []) as $id) {
        if (is_string($id) && memory_remove($user, $id)) {
            $removed[] = $id;
        }
    }

    return ['added' => $added, 'removed' => $removed, 'error' => ''];
}

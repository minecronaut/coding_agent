<?php
declare(strict_types=1);

/**
 * Tiny JSON-file store. One file per learner, plus a username index.
 * Safe-ish for a single small site: every write goes through flock().
 */
final class Store
{
    private static function namesPath(): string
    {
        return DATA_DIR . '/names.json';
    }

    private static function userPath(string $id): string
    {
        return USERS_DIR . '/' . preg_replace('/[^a-f0-9]/', '', $id) . '.json';
    }

    private static function readJsonFile(string $path, array $fallback = []): array
    {
        if (!is_file($path)) {
            return $fallback;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $fallback;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $fallback;
    }

    private static function writeJsonFile(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write storage file.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not commit storage file.');
        }
    }

    /** @return array<string,string> lowercased username => user id */
    private static function names(): array
    {
        $path = self::namesPath();
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return [];
        }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    public static function usernameTaken(string $username): bool
    {
        $names = self::names();
        return isset($names[mb_strtolower($username)]);
    }

    public static function findUserByName(string $username): ?array
    {
        $names = self::names();
        $id = $names[mb_strtolower($username)] ?? null;
        return $id ? self::load($id) : null;
    }

    public static function load(string $id): ?array
    {
        $path = self::userPath($id);
        if (!is_file($path)) {
            return null;
        }
        $data = self::readJsonFile($path);
        return $data ?: null;
    }

    public static function save(array $user): void
    {
        if (empty($user['id'])) {
            throw new RuntimeException('User record is missing an id.');
        }
        self::writeJsonFile(self::userPath((string)$user['id']), $user);
    }

    /**
     * Read-modify-write under an exclusive lock. The callback receives the
     * current record by reference and returns nothing.
     */
    public static function update(string $id, callable $mutator): ?array
    {
        $path = self::userPath($id);
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return null;
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $user = json_decode((string)$raw, true);
            if (!is_array($user) || empty($user['id'])) {
                return null;
            }
            $mutator($user);
            $user['updated'] = now_iso();
            $json = json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return null;
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json);
            fflush($fp);
            return $user;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public static function create(string $username, string $password, string $display): array
    {
        $id = short_id(8);
        $user = [
            'id'       => $id,
            'username' => $username,
            'display'  => $display !== '' ? $display : $username,
            'pass'     => password_hash($password, PASSWORD_DEFAULT),
            'created'  => now_iso(),
            'updated'  => now_iso(),
            'settings' => [
                'provider'    => 'nvidia',
                'model'       => '',
                'key_enc'     => '',
                'base_url'    => '',
                'temperature' => 0.7,
                'tutor_style' => 'socratic',
                'timeout'     => 10,
                'language'    => 'python',
                'auto_build'  => true,
                'bank_target' => 4,
            ],
            'profile'   => [
                'goal'          => '',
                'experience'    => '',
                'time_per_week' => '',
                'interests'     => [],
                'explained'     => '',
            ],
            'placement' => null,
            'journey'   => ['completed' => [], 'activeSkill' => null, 'started' => now_iso()],
            'lessons'   => [],
            'modules'   => [],
            'checkpoints' => [],
            'plan'      => null,
            'bank'      => ['last_at' => null, 'last_error' => ''],
            'memory'    => ['struggles' => [], 'wins' => [], 'notes' => [], 'facts' => []],
            'projects'  => [],
            'stats'     => ['runs' => 0, 'messages' => 0, 'lessons' => 0],
        ];

        self::save($user);

        // Register the username under an exclusive lock.
        $path = self::namesPath();
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            throw new RuntimeException('Could not open the username index.');
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $names = json_decode((string)$raw, true);
            if (!is_array($names)) {
                $names = [];
            }
            $key = mb_strtolower($username);
            if (isset($names[$key])) {
                throw new RuntimeException('That username was just taken.');
            }
            $names[$key] = $id;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($names, JSON_PRETTY_PRINT));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return $user;
    }
}

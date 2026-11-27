<?php
declare(strict_types=1);

if (!defined('APP_ROOT') || !defined('DATA_DIR')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!defined('COMPANION_FILE')) {
    define('COMPANION_FILE', DATA_DIR . '/companion.php');
}
if (!defined('COMPANION_GUARD')) {
    define('COMPANION_GUARD', "<?php exit; /* token perangkat Uang - jangan diakses langsung */ ?>\n");
}

/**
 * Penyimpanan token Android Companion terpisah dari data keuangan.
 * Token mentah hanya ditampilkan satu kali ketika perangkat didaftarkan.
 */
function companion_empty_state(): array
{
    return ['version' => 1, 'devices' => []];
}

function companion_decode_state(string $raw): array
{
    $pos = strpos($raw, "?>");
    if ($pos !== false) {
        $raw = substr($raw, $pos + 2);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        if (trim($raw) === '') {
            return companion_empty_state();
        }
        throw new RuntimeException('Berkas token Companion rusak atau tidak dapat dibaca; data tidak ditimpa.');
    }
    if (!isset($data['devices']) || !is_array($data['devices'])) {
        $data['devices'] = [];
    }
    return array_merge(companion_empty_state(), $data);
}

function companion_state(): array
{
    if (!is_file(COMPANION_FILE)) {
        return companion_empty_state();
    }
    $raw = @file_get_contents(COMPANION_FILE);
    if ($raw === false || $raw === '') {
        return companion_empty_state();
    }
    return companion_decode_state($raw);
}

/** Perubahan state dikunci agar dua request perangkat tidak saling menimpa. */
function companion_mutate(callable $callback)
{
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('Folder data tidak dapat dibuat.');
    }
    $denyFile = DATA_DIR . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\nOrder allow,deny\nDeny from all\nOptions -Indexes\n", LOCK_EX);
    }
    $lock = @fopen(COMPANION_FILE . '.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Penyimpanan perangkat sedang tidak tersedia.');
    }

    try {
        $raw = is_file(COMPANION_FILE) ? (@file_get_contents(COMPANION_FILE) ?: '') : '';
        $state = companion_decode_state($raw);
        $result = $callback($state);
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Data perangkat gagal dienkode.');
        }
        $tmp = COMPANION_FILE . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents($tmp, COMPANION_GUARD . $json, LOCK_EX);
        if ($written !== false) {
            @chmod($tmp, 0600);
        }
        if ($written === false || !@rename($tmp, COMPANION_FILE)) {
            @unlink($tmp);
            throw new RuntimeException('Data perangkat gagal disimpan.');
        }
        @chmod(COMPANION_FILE, 0600);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function companion_register_device(string $label): array
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $id = bin2hex(random_bytes(8));
    $label = trim($label);
    if ($label === '') {
        $label = 'Android device';
    }
    $label = function_exists('mb_substr') ? mb_substr($label, 0, 80, 'UTF-8') : substr($label, 0, 80);

    companion_mutate(static function (array &$state) use ($hash, $id, $label): void {
        $active = 0;
        foreach ($state['devices'] as $device) {
            if (empty($device['revokedAt'])) {
                $active++;
            }
        }
        if ($active >= 10) {
            throw new RuntimeException('Maksimal 10 perangkat Companion aktif.');
        }
        $state['devices'][$hash] = [
            'id' => $id,
            'label' => $label,
            'createdAt' => date('c'),
            'lastSeen' => '',
            'revokedAt' => '',
        ];
    });

    return ['id' => $id, 'label' => $label, 'token' => $token];
}

function companion_revoke_device(string $id): bool
{
    return (bool)companion_mutate(static function (array &$state) use ($id): bool {
        foreach ($state['devices'] as &$device) {
            if (($device['id'] ?? '') === $id && empty($device['revokedAt'])) {
                $device['revokedAt'] = date('c');
                unset($device);
                return true;
            }
        }
        unset($device);
        return false;
    });
}

function companion_device_by_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token);
    $device = companion_state()['devices'][$hash] ?? null;
    if (!is_array($device) || !empty($device['revokedAt'])) {
        return null;
    }
    return $device;
}

function companion_touch_device(string $id): void
{
    companion_mutate(static function (array &$state) use ($id): void {
        foreach ($state['devices'] as &$device) {
            if (($device['id'] ?? '') === $id && empty($device['revokedAt'])) {
                $device['lastSeen'] = date('c');
                break;
            }
        }
        unset($device);
    });
}

function companion_list_devices(): array
{
    $rows = [];
    foreach (companion_state()['devices'] as $device) {
        $rows[] = [
            'id' => (string)($device['id'] ?? ''),
            'label' => (string)($device['label'] ?? 'Android device'),
            'createdAt' => (string)($device['createdAt'] ?? ''),
            'lastSeen' => (string)($device['lastSeen'] ?? ''),
            'revokedAt' => (string)($device['revokedAt'] ?? ''),
            'active' => empty($device['revokedAt']),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        return strcmp($b['createdAt'], $a['createdAt']);
    });
    return $rows;
}

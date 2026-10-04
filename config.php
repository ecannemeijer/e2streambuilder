<?php

function appVersion(): string
{
    return '1.0.0-beta';
}

function defaultReceiverSettings(): array
{
    return [
        'host' => '192.168.1.108',
        'webif_port' => 80,
        'stream_port' => 8001,
    ];
}

function settingsPath(): string
{
    return __DIR__ . '/data/settings.json';
}

function loadSettingsRaw(): array
{
    $path = settingsPath();
    if (!is_file($path)) {
        return [];
    }
    $json = json_decode((string) file_get_contents($path), true);

    return is_array($json) ? $json : [];
}

function saveSettingsRaw(array $settings): void
{
    $dir = dirname(settingsPath());
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('The settings folder could not be created.');
    }
    $written = file_put_contents(
        settingsPath(),
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
    if ($written === false) {
        throw new RuntimeException('The settings could not be saved.');
    }
}

function receiverSettings(): array
{
    if (isset($GLOBALS['receiver_override']) && is_array($GLOBALS['receiver_override'])) {
        return $GLOBALS['receiver_override'];
    }

    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $settings = defaultReceiverSettings();
    $json = loadSettingsRaw();
    if ($json !== []) {
        $settings = normalizeReceiverSettings(array_merge($settings, $json));
    }

    $cached = $settings;

    return $cached;
}

function normalizeReceiverSettings(array $input): array
{
    $host = trim((string) ($input['host'] ?? ''));
    if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new InvalidArgumentException('Enter a valid IPv4 address.');
    }

    $webif = (int) ($input['webif_port'] ?? 80);
    $stream = (int) ($input['stream_port'] ?? 8001);
    if ($webif < 1 || $webif > 65535 || $stream < 1 || $stream > 65535) {
        throw new InvalidArgumentException('Ports must be between 1 and 65535.');
    }

    return [
        'host' => $host,
        'webif_port' => $webif,
        'stream_port' => $stream,
    ];
}

function saveReceiverSettings(array $input): array
{
    $settings = normalizeReceiverSettings($input);
    saveSettingsRaw(array_merge(loadSettingsRaw(), $settings));

    return $settings;
}

function epgSettings(): array
{
    $raw = loadSettingsRaw();
    $hours = (int) ($raw['epg_interval_hours'] ?? 24);
    if ($hours < 1 || $hours > 168) {
        $hours = 24;
    }
    $threshold = (float) ($raw['epg_fuzzy_threshold'] ?? 0.9);
    if ($threshold < 0.5 || $threshold > 1) {
        $threshold = 0.9;
    }

    return [
        'epg_interval_hours' => $hours,
        'epg_fuzzy_threshold' => $threshold,
    ];
}

function xtreamSettings(): array
{
    $raw = loadSettingsRaw();
    $user = trim((string) ($raw['xtream_username'] ?? ''));
    if ($user === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user) !== 1) {
        $user = 'tivimate';
    }

    return [
        'xtream_username' => $user,
        'xtream_password' => (string) ($raw['xtream_password'] ?? ''),
    ];
}

function saveXtreamSettings(array $input, bool $generatePassword = false): array
{
    $user = trim((string) ($input['xtream_username'] ?? ''));
    if ($user === '') {
        $user = 'tivimate';
    }
    if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user) !== 1) {
        throw new InvalidArgumentException('The Xtream username may only contain letters, numbers, dots, underscores and hyphens.');
    }
    $password = trim((string) ($input['xtream_password'] ?? ''));
    if ($password === '' && $generatePassword) {
        $password = bin2hex(random_bytes(8));
    }
    if (preg_match('/^[A-Za-z0-9._-]{8,64}$/', $password) !== 1) {
        throw new InvalidArgumentException('The Xtream password must be 8 to 64 characters: letters, numbers, dots, underscores or hyphens.');
    }
    $settings = [
        'xtream_username' => $user,
        'xtream_password' => $password,
    ];
    saveSettingsRaw(array_merge(loadSettingsRaw(), $settings));

    return $settings;
}

function saveEpgSettings(array $input): array
{
    $hours = (int) ($input['epg_interval_hours'] ?? 24);
    $threshold = (float) str_replace(',', '.', (string) ($input['epg_fuzzy_threshold'] ?? '0.9'));
    if ($hours < 1 || $hours > 168) {
        throw new InvalidArgumentException('The update interval must be between 1 and 168 hours.');
    }
    if ($threshold < 0.5 || $threshold > 1) {
        throw new InvalidArgumentException('The confidence threshold must be between 0.50 and 1.00.');
    }
    $settings = [
        'epg_interval_hours' => $hours,
        'epg_fuzzy_threshold' => round($threshold, 2),
    ];
    saveSettingsRaw(array_merge(loadSettingsRaw(), $settings));

    return $settings;
}

function resetReceiverSettingsCache(): void
{
    // Settings are read once per request. A fresh request sees the saved file.
}

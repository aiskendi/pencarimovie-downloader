<?php

declare(strict_types=1);

// Suppress PHP error output to prevent HTML warnings from breaking JSON/header responses.
// Errors are still logged via error_log for debugging.
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// Force MadelineProto to run in-process ($forceFull = true) without attempting to
// start background IPC server processes. On Linux / macOS / Raspberry Pi OS,
// MadelineProto by default tries to spawn an IPC daemon via ProcessRunner / WebRunner,
// which fails in FrankenPHP/embedded environments.
// Setting MadelineSelfRestart forces $forceFull = true across all platforms.
if (!isset($_GET['MadelineSelfRestart'])) {
    $_GET['MadelineSelfRestart'] = '1';
}

/**
 * API credentials are no longer hardcoded here.
 * They are fetched from the WordPress REST API endpoint (/save-bot-token)
 * after successful bot token validation, encrypted with the token as key.
 */
function fd_get_storage_dir(): string
{
    static $storageDir = null;
    if ($storageDir !== null) {
        return $storageDir;
    }

    $cwd = getcwd();
    if ($cwd !== false) {
        $cwdStorage = $cwd . DIRECTORY_SEPARATOR . 'storage';
        if (is_dir($cwdStorage) && is_writable($cwdStorage)) {
            return $storageDir = $cwdStorage;
        }
        if (!file_exists($cwdStorage) && is_writable($cwd)) {
            return $storageDir = $cwdStorage;
        }
    }

    $appStorage = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    $tempDir = realpath(sys_get_temp_dir());
    $realDir = realpath(__DIR__);
    $isTemp = false;
    if ($tempDir !== false && $realDir !== false) {
        if (str_starts_with($realDir, $tempDir)) {
            $isTemp = true;
        }
    }
    if (str_contains($realDir ?: '', 'frankenphp_')) {
        $isTemp = true;
    }

    if (!$isTemp && is_dir($appStorage) && is_writable($appStorage)) {
        return $storageDir = $appStorage;
    }

    $home = getenv('HOME');
    if ($home === false) {
        $home = getenv('USERPROFILE');
    }
    if ($home === false && getenv('HOMEDRIVE') !== false && getenv('HOMEPATH') !== false) {
        $home = getenv('HOMEDRIVE') . getenv('HOMEPATH');
    }

    if ($home !== false && is_dir($home) && is_writable($home)) {
        $homeStorage = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.pencarimovie-downloader' . DIRECTORY_SEPARATOR . 'storage';
        return $storageDir = $homeStorage;
    }

    return $storageDir = $appStorage;
}

function fd_storage_path(string $file): string
{
    $file = ltrim($file, '/\\');
    if (str_starts_with($file, 'storage/') || str_starts_with($file, 'storage\\')) {
        $subPath = substr($file, 7);
        $subPath = ltrim($subPath, '/\\');
        $storageDir = fd_get_storage_dir();

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0777, true);
        }

        $fullPath = $storageDir . DIRECTORY_SEPARATOR . $subPath;
        $parent = dirname($fullPath);
        if (!is_dir($parent)) {
            @mkdir($parent, 0777, true);
        }
        return $fullPath;
    }
    return __DIR__ . '/' . $file;
}

define('FD_SESSION_PATH', fd_storage_path('storage/session.madeline'));
define('FD_WP_API_BASE', 'https://pencarimovie.com/wp-json/fastdownloader/v1');
define('FD_WP_AJAX_URL', 'https://pencarimovie.com/wp-admin/admin-ajax.php');
define('FD_APP_VERSION', '1.0.0');
define('FD_VERSION_CACHE_PATH', fd_storage_path('storage/version_check.json'));
define('FD_VERSION_CACHE_TTL', 3600);
define('FD_WP_VERSION_URL', FD_WP_API_BASE . '/version');
define('FD_API_SECRET_PATH', fd_storage_path('storage/api_secret.key'));
define('FD_BOT_ID_CACHE_PATH', fd_storage_path('storage/bot_id.txt'));

/**
 * Optional DNS resolution mapping for curl (e.g. "example.com:443:1.2.3.4").
 */
define('FD_CURL_RESOLVE', '');
function fd_json(array $data, int $status = 200): never
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Secret');
        if ($status >= 400) {
            header('Connection: close');
        }
        header('Content-Length: ' . strlen($body));
    }
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

function fd_log(string $message, array $context = []): void
{
    $suffix = '';
    if ($context !== []) {
        $suffix = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    error_log('[PencariMovie Downloader] ' . $message . $suffix);
}

/**
 * Get the stored API secret (used for X-API-Secret header on WordPress requests).
 * Returns empty string if no secret has been saved yet.
 */
function fd_get_api_secret(): string
{
    $path = FD_API_SECRET_PATH;
    if (!is_file($path)) {
        return '';
    }
    $secret = trim((string) file_get_contents($path));
    return $secret;
}

/**
 * Save the API secret received from WordPress during bot login.
 */
function fd_save_api_secret(string $secret): void
{
    $path = FD_API_SECRET_PATH;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($path, $secret, LOCK_EX);
}

/**
 * Clear the stored API secret (called during logout / session clear).
 */
function fd_clear_api_secret(): void
{
    $path = FD_API_SECRET_PATH;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Get the cached Bot ID (avoids booting full MadelineProto on lightweight requests).
 */
function fd_get_bot_id(): string
{
    $path = FD_BOT_ID_CACHE_PATH;
    if (is_file($path)) {
        $val = trim((string) file_get_contents($path));
        if ($val !== '') {
            return $val;
        }
    }
    return '';
}

/**
 * Cache the Bot ID to disk.
 */
function fd_save_bot_id(string $botId): void
{
    $path = FD_BOT_ID_CACHE_PATH;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if ($botId !== '') {
        @file_put_contents($path, $botId, LOCK_EX);
    }
}

/**
 * Clear the cached Bot ID.
 */
function fd_clear_bot_id(): void
{
    $path = FD_BOT_ID_CACHE_PATH;
    if (is_file($path)) {
        @unlink($path);
    }
}

function fd_is_local_request(): bool
{
    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remoteAddr === '') {
        return true;
    }

    if (in_array($remoteAddr, ['127.0.0.1', '::1'], true) || str_starts_with($remoteAddr, '::ffff:127.0.0.1')) {
        return true;
    }

    // If client IP matches the server IP (same device/host making request to itself)
    $serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    if ($serverAddr !== '' && $remoteAddr === $serverAddr) {
        return true;
    }

    // Check private & reserved IPv4/IPv6 ranges
    if (filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }

    // Carrier-Grade NAT (CGNAT, RFC 6598: 100.64.0.0/10) used by mobile ISPs
    $long = ip2long($remoteAddr);
    if ($long !== false) {
        $cgnatStart = ip2long('100.64.0.0');
        $cgnatEnd = ip2long('100.127.255.255');
        if ($long >= $cgnatStart && $long <= $cgnatEnd) {
            return true;
        }
    }

    // Host header is localhost or loopback
    $httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $hostOnly = explode(':', $httpHost)[0];
    if (in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }

    return false;
}

function fd_require_local_request(): void
{
    if (!fd_is_local_request()) {
        fd_json(['ok' => 0, 'message' => 'This endpoint is restricted to local requests only.'], 403);
    }
}

function fd_decode_download_payload(string $payload): array
{
    $payload = strtr($payload, '-_', '+/');
    $padding = strlen($payload) % 4;
    if ($padding > 0) {
        $payload .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return [];
    }

    $json = json_decode($decoded, true);
    return is_array($json) ? $json : [];
}

function fd_http_json(string $url, array $payload = [], string $method = 'GET', int $timeout = 20): array
{
    $query = '';
    if ($method === 'GET' && $payload) {
        $query = '?' . http_build_query($payload);
    }

    // Build header array
    $headers = ['Accept: application/json'];

    // Add API secret header for authenticating with WordPress endpoints
    $apiSecret = fd_get_api_secret();
    if ($apiSecret !== '') {
        $headers[] = "X-API-Secret: $apiSecret";
    }

    $body = '';
    if ($method !== 'GET' && $payload) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }

    $response = fd_http_get_contents($url . $query, [
        'method' => $method,
        'headers' => $headers,
        'body' => $body,
        'timeout' => $timeout,
    ]);
    $decoded = json_decode((string) $response, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Low-level HTTP fetch using curl (preferred) or file_get_contents (fallback).
 *
 * Uses curl when available because bundled PHP 8.5 on Windows has unreliable
 * file_get_contents SSL handling (OpenSSL CA bundle issues). Curl works around
 * this by using its own CA store or accepting verify_peer=false when needed.
 *
 * @param string $url     The full URL to request.
 * @param array  $options Optional. {
 *     @var string   $method  HTTP method (GET, POST, etc.). Default 'GET'.
 *     @var string[] $headers Array of raw header strings, e.g. ["Accept: application/json"].
 *     @var string   $body    Request body for POST/PUT requests.
 *     @var int      $timeout Connection timeout in seconds. Default 15.
 * }
 * @return string|false Response body on success, false on failure.
 */
function fd_http_get_contents(string $url, array $options = []): string|false
{
    $method = strtoupper($options['method'] ?? 'GET');
    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? '';
    $timeout = (int) ($options['timeout'] ?? 15);

    // Prefer curl — it has reliable SSL handling on Windows PHP 8.5
    if (function_exists('curl_version')) {
        $ch = curl_init();
        // Build base curl options
        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => max(5, $timeout - 5),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ];

        // Optional custom DNS resolution via CURLOPT_RESOLVE
        if (defined('FD_CURL_RESOLVE') && FD_CURL_RESOLVE !== '') {
            $curlOpts[CURLOPT_RESOLVE] = [FD_CURL_RESOLVE];
        }

        curl_setopt_array($ch, $curlOpts);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $response = curl_exec($ch);
        // In PHP 8.5+ curl_close() is a no-op, just here for readability
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        unset($ch);

        if ($response === false || $response === '') {
            return false;
        }

        return $response;
    }

    // Fallback: file_get_contents with SSL verification disabled
    // (Windows bundled PHP has no valid CA bundle by default)
    $headerStr = '';
    foreach ($headers as $h) {
        $headerStr .= $h . "\r\n";
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => $headerStr,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    if ($body !== '') {
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => $headerStr,
                'content' => $body,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
    }

    return @file_get_contents($url, false, $ctx);
}

function fd_resolve_shortcode(string $shortCode, string $botId = ''): array
{
    $url = FD_WP_API_BASE . '/resolve-file';
    $params = ['short_code' => $shortCode];
    if ($botId !== '') {
        $params['bot_id'] = $botId;
    }

    // Fast 5-second timeout so stream attempts fail quickly instead of hanging
    return fd_http_json($url, $params, 'GET', 5);
}

function fd_load_madeline_autoload(): ?string
{
    $candidates = [
        fd_storage_path('vendor/autoload.php'),
        fd_storage_path('vendor/madelineproto/autoload.php'),
        fd_storage_path('storage/vendor/autoload.php'),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            require_once $candidate;
            return $candidate;
        }
    }

    return null;
}

/**
 * Ensure the Composer/MadelineProto autoloader is loaded.
 * Uses output buffering to suppress the polyfill.php echo warning on Windows.
 * Safe to call multiple times — uses require_once internally.
 */
function fd_ensure_autoload(): bool
{
    if (class_exists('\\danog\\MadelineProto\\API', false)) {
        return true;
    }
    $level = ob_get_level();
    ob_start();
    $result = fd_load_madeline_autoload();
    while (ob_get_level() > $level) {
        ob_end_clean();
    }
    return $result !== null;
}

function fd_require_fileinfo(): bool
{
    if (extension_loaded('fileinfo')) {
        return true;
    }

    if (!headers_sent()) {
        http_response_code(501);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'ok' => 0,
        'message' => 'MadelineProto requires the fileinfo extension to run. Try running sudo apt-get install php8.5-fileinfo.',
        'hint' => 'Install MadelineProto dependencies and ensure a bot session is configured.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Decrypt api_id/api_hash that were encrypted by WordPress using the bot token.
 *
 * @param string $encryptedB64 Base64-encoded ciphertext.
 * @param string $ivB64       Base64-encoded initialization vector.
 * @param string $token       The bot token (used as AES-256-CBC key material).
 * @return array [int|null api_id, string|null error]
 */
function fd_decrypt_credentials(string $encryptedB64, string $ivB64, string $token): array
{
    if ($encryptedB64 === '' || $ivB64 === '') {
        return [null, 'Empty encrypted credentials or IV from WordPress.'];
    }
    if (!function_exists('openssl_decrypt')) {
        return [null, 'openssl extension is required to decrypt credentials.'];
    }

    $key = hash('sha256', $token, true);
    $iv = base64_decode($ivB64, true);
    $ciphertext = base64_decode($encryptedB64, true);
    if ($iv === false || $ciphertext === false) {
        return [null, 'Invalid base64 in encrypted credentials or IV.'];
    }

    $decrypted = @openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        return [null, 'Failed to decrypt credentials from WordPress.'];
    }

    $data = json_decode($decrypted, true);
    if (!is_array($data) || empty($data['api_id']) || empty($data['api_hash'])) {
        return [null, 'Decrypted credentials have invalid structure.'];
    }

    return [(int) $data['api_id'], (string) $data['api_hash']];
}

/**
 * Fetch encrypted api_id/api_hash from the WordPress REST API.
 *
 * Calls POST /save-bot-token with the bot token. WordPress validates the token
 * via Telegram getMe, then returns AES-256-CBC encrypted credentials.
 *
 * @param string $botToken The bot token to authenticate with WordPress.
 * @return array [int|null api_id, string|null error]
 */
function fd_fetch_credentials_from_wordpress(string $botToken): array
{
    try {
        $payload = json_encode(['bot_token' => $botToken], JSON_UNESCAPED_SLASHES);

        $body = fd_http_get_contents(FD_WP_API_BASE . '/save-bot-token', [
            'method' => 'POST',
            'headers' => [
                'Content-Type: application/json',
                'User-Agent: PencariMovie-Downloader/1.0',
            ],
            'body' => $payload,
            'timeout' => 10,
        ]);
        if ($body === false) {
            fd_log('wordpress raw response body: false');
            return [null, 'WordPress connection failed (HTTP request failed).'];
        }

        fd_log('wordpress raw response body', ['body' => $body]);

        // Strip UTF-8 Byte Order Mark (BOM) if present at the start of the response
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $body = trim($body);

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['ok'])) {
            $msg = $data['message'] ?? 'WordPress rejected the bot token.';
            fd_log('wordpress rejected token', ['message' => $msg, 'response_decoded' => $data]);
            return [null, $msg];
        }
        if (empty($data['encrypted_credentials']) || empty($data['encryption_iv'])) {
            return [null, 'WordPress response missing encrypted credentials.'];
        }

        // Save the API secret returned by WordPress for authenticating future requests
        if (!empty($data['api_secret'])) {
            fd_save_api_secret((string) $data['api_secret']);
            fd_log('api secret saved from wordpress');
        }

        return fd_decrypt_credentials(
            $data['encrypted_credentials'],
            $data['encryption_iv'],
            $botToken
        );
    } catch (\Throwable $e) {
        return [null, 'WordPress connection failed: ' . $e->getMessage()];
    }
}

/**
 * Load cached Telegram API credentials from local storage.
 * Created at runtime after first successful WordPress fetch.
 *
 * @return array [int|null api_id, string|null api_hash]
 */
function fd_load_cached_api_credentials(): array
{
    $path = fd_storage_path('storage/api_credentials.json');
    if (!is_file($path)) {
        return [null, null];
    }
    $data = @json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || empty($data['api_id']) || empty($data['api_hash'])) {
        @unlink($path);
        return [null, null];
    }
    return [(int) $data['api_id'], (string) $data['api_hash']];
}

/**
 * Persist decrypted api_id/api_hash to local cache.
 * This avoids calling WordPress on every request during session resume.
 */
function fd_save_cached_api_credentials(int $apiId, string $apiHash): void
{
    $path = fd_storage_path('storage/api_credentials.json');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents(
        $path,
        json_encode(['api_id' => $apiId, 'api_hash' => $apiHash], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * Boot MadelineProto using session-based auth.
 *
 * - If a session file exists and is valid, returns the instance directly.
 * - If session is invalid or missing and $botToken is provided, does botLogin().
 * - If no session and no token, returns an error.
 *
 * API credentials are resolved in this priority:
 *   1. $overrides['api_id'] / $overrides['api_hash'] (emergency manual override)
 *   2. Local cache (storage/api_credentials.json, created after first WordPress fetch)
 *   3. WordPress REST API fetch (only when $botToken is provided for new login)
 *
 * Output buffering suppresses MadelineProto's direct-echo warnings (polyfill.php).
 * Stale lock files are cleaned before construction to prevent 30-second lock contention.
 *
 * @param string|null $botToken Optional bot token for initial login / re-login.
 * @param array       $overrides Optional api_id/api_hash overrides (emergency only).
 * @return array [MadelineProto|null, string|null error]
 */
function fd_boot_madeline(?string $botToken = null, array $overrides = []): array
{
    // ── Suppress direct echo from polyfill.php ──────────────────────────────
    // polyfill.php is loaded as a Composer autoload file (autoload_files.php),
    // which means it executes during vendor/autoload.php require. Its line 10
    // echoes "WARNING: MadelineProto runs around 10x slower on windows..."
    // directly to stdout on every request. We must capture output buffering
    // BEFORE any autoload-triggering call to prevent this from corrupting JSON.
    // Ensure in-process execution mode is forced across all platforms (Linux/Raspberry Pi/macOS/Windows)
    $_GET['MadelineSelfRestart'] = '1';

    $bootObLevel = ob_get_level();
    $bootEntryObLevel = $bootObLevel;
    ob_start();
    fd_log('fd_boot_madeline entry', [
        'ob_level' => $bootObLevel,
        'bot_token_provided' => $botToken !== null && $botToken !== '',
        'session_exists' => is_dir(FD_SESSION_PATH) || is_file(FD_SESSION_PATH),
    ]);

    // Check if MadelineProto is already loaded (e.g., via routing-level pre-load).
    // We use class_exists without the second parameter here to trigger the
    // Composer autoloader to actually load the class file if needed.
    if (!class_exists('\\danog\\MadelineProto\\API')) {
        // Try loading the autoloader if not already done
        if (!fd_ensure_autoload()) {
            while (ob_get_level() > $bootObLevel) {
                ob_end_clean();
            }
            return [null, 'MadelineProto autoload file was not found. Run composer install first.'];
        }

        // After loading autoload, check again — this time class_exists triggers
        // the freshly registered Composer autoloader to load the class.
        if (!class_exists('\\danog\\MadelineProto\\API')) {
            while (ob_get_level() > $bootObLevel) {
                ob_end_clean();
            }
            return [null, 'MadelineProto class is not available after loading autoload.'];
        }
    }

    // ── Resolve api_id / api_hash ─────────────────────────────────────────
    // Priority: 1) POST body overrides, 2) local cache, 3) WordPress (new login)
    $apiId = (int) ($overrides['api_id'] ?? 0);
    $apiHash = trim((string) ($overrides['api_hash'] ?? ''));

    if ($apiId === 0 || $apiHash === '') {
        [$cachedId, $cachedHash] = fd_load_cached_api_credentials();
        if ($cachedId !== null && $cachedHash !== null) {
            $apiId = $cachedId;
            $apiHash = $cachedHash;
        }
    }

    // No overrides and no cache — fetch from WordPress (requires bot token)
    if (($apiId === 0 || $apiHash === '') && $botToken !== null && $botToken !== '') {
        fd_log('fetching api credentials from wordpress', []);
        [$wpId, $wpHashOrError] = fd_fetch_credentials_from_wordpress($botToken);
        if ($wpId === null) {
            while (ob_get_level() > $bootObLevel) {
                ob_end_clean();
            }
            return [null, $wpHashOrError];
        }
        $apiId = $wpId;
        $apiHash = $wpHashOrError;
        fd_save_cached_api_credentials($apiId, $apiHash);
        fd_log('api credentials cached from wordpress', ['api_id' => $apiId]);
    }

    if ($apiId === 0 || $apiHash === '') {
        while (ob_get_level() > $bootObLevel) {
            ob_end_clean();
        }
        return [null, 'No API credentials available. Login via the settings page to fetch from WordPress.'];
    }

    $sessionPath = FD_SESSION_PATH;
    $sessionDir = dirname($sessionPath);

    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0777, true);
    }

    $settings = new \danog\MadelineProto\Settings();
    $settings->getAppInfo()
        ->setApiId($apiId)
        ->setApiHash($apiHash);
    if (method_exists($settings->getRpc(), 'setRpcDropTimeout')) {
        $settings->getRpc()->setRpcDropTimeout(300);
    }
    $settings->getLogger()->setLevel(0);

    // ── Retry construction loop ───────────────────────────────────────────────
    // Under FrankenPHP, multiple workers service requests concurrently.
    // MadelineProto's AsyncTools::flock() uses touch() to create the lock file
    // only when it doesn't exist. If we delete the lock file in cleanup, every
    // worker races to touch() it, causing "Permission denied" on Windows.
    //
    // The correct approach: NEVER delete the lock file. Let it exist permanently.
    // MadelineProto's flock() with LOCK_NB + polling handles contention between
    // workers naturally (100ms poll intervals, up to 30-second timeout).
    //
    // We still clean /lightState.php.lock and /safe.php.lock (non-lock artifacts
    // from stale IPC sessions), but /lock is left alone.
    $lastError = null;
    for ($bootAttempt = 0; $bootAttempt < 3; $bootAttempt++) {
        try {
            // Clean stale state files (NOT /lock — that should persist to
            // prevent concurrent touch() races under FrankenPHP).
            if (is_dir($sessionPath)) {
                foreach (['/lightState.php.lock', '/safe.php.lock'] as $lockName) {
                    $lockPath = $sessionPath . $lockName;
                    if (is_file($lockPath)) {
                        @unlink($lockPath);
                    }
                }
            }

            $t0 = microtime(true);
            $madeline = new \danog\MadelineProto\API($sessionPath, $settings);
            $t1 = microtime(true);
            fd_log('madeline construction', ['ms' => round(($t1 - $t0) * 1000), 'attempt' => $bootAttempt + 1]);

            // Try to resume existing session first.
            // The constructor already deserializes the session if present and logs
            // in via connectToMadelineProto(). We only need getSelf() to verify.
            // MadelineProto v8+ stores sessions as directories, so check both.
            if (is_dir($sessionPath) || is_file($sessionPath)) {
                try {
                    $self = $madeline->getSelf();
                    if ($self && !empty($self['id'])) {
                        fd_save_bot_id((string) $self['id']);
                        fd_log('session resumed', [
                            'bot_id' => $self['id'],
                            'elapsed_ms' => round((microtime(true) - $t0) * 1000),
                            'attempt' => $bootAttempt + 1,
                        ]);
                        while (ob_get_level() > $bootObLevel) {
                            ob_end_clean();
                        }
                        return [$madeline, null];
                    }
                } catch (Throwable $throwable) {
                    fd_log('existing session invalid, will re-login', [
                        'error' => $throwable->getMessage(),
                        'attempt' => $bootAttempt + 1,
                    ]);
                }
            }

            // No valid session — attempt bot login if token is provided
            if ($botToken !== null && $botToken !== '') {
                $t2 = microtime(true);
                $madeline->botLogin($botToken);
                $t3 = microtime(true);
                fd_log('botLogin completed', ['ms' => round(($t3 - $t2) * 1000)]);

                // Verify login — start() is redundant after constructor + botLogin
                $self = $madeline->getSelf();
                $t4 = microtime(true);
                fd_log('getSelf after login', ['ms' => round(($t4 - $t3) * 1000)]);

                if ($self && !empty($self['id'])) {
                    fd_save_bot_id((string) $self['id']);
                    while (ob_get_level() > $bootObLevel) {
                        ob_end_clean();
                    }
                    return [$madeline, null];
                }

                while (ob_get_level() > $bootObLevel) {
                    ob_end_clean();
                }
                return [null, 'botLogin completed but getSelf returned no valid identity.'];
            }

            // No session and no token — discard buffered output
            while (ob_get_level() > $bootObLevel) {
                ob_end_clean();
            }
            return [null, 'No valid session. Call /api/botlogin to authenticate.'];
        } catch (Throwable $throwable) {
            // Clean up output buffer on exception
            while (ob_get_level() > $bootObLevel) {
                ob_end_clean();
            }
            // Log the error and retry if this wasn't the last attempt
            $lastError = $throwable->getMessage();
            fd_log('madeline boot attempt failed', [
                'error' => $lastError,
                'attempt' => $bootAttempt + 1,
            ]);
            // Small delay before retrying
            if ($bootAttempt < 2) {
                usleep(500000); // 500ms
            }
            // Continue to next retry attempt
        }
    }

    // All retry attempts exhausted
    while (ob_get_level() > $bootObLevel) {
        ob_end_clean();
    }
    return [null, $lastError ?? 'Could not boot MadelineProto after 3 attempts.'];
}

/**
 * Clear MadelineProto session files from storage.
 */
function fd_clear_session(): void
{
    $sessionPath = FD_SESSION_PATH;

    // MadelineProto stores sessions as directories with nested files.
    // Use recursive deletion to remove everything, with retries for
    // Windows file-lock edge cases.
    if (is_dir($sessionPath)) {
        // Phase 1: native PHP recursive deletion
        //
        // MadelineProto sets a custom exceptionErrorHandler that converts PHP
        // warnings (even from @-suppressed calls) into exceptions. This means
        // @rmdir() or @unlink() on locked files will throw before Phase 2
        // gets a chance to execute. Wrap the loop body in try/catch so every
        // retry attempt completes and Phase 2 (rename-then-delete fallback)
        // is reached when all 3 attempts fail.
        $deleted = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sessionPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($files as $fileinfo) {
                    if ($fileinfo->isDir()) {
                        @rmdir($fileinfo->getRealPath());
                    } else {
                        @unlink($fileinfo->getRealPath());
                    }
                }
                // Attempt to remove the root session directory
                if (@rmdir($sessionPath)) {
                    $deleted = true;
                    break;
                }
            } catch (\Throwable $e) {
                // @rmdir/@unlink warning caught from MadelineProto's error
                // handler — this is expected when files are locked by another
                // FrankenPHP worker. Continue to next retry attempt.
            }
            // Short delay before retry (some locks may be transient)
            if ($attempt < 2) {
                usleep(200000); // 200ms
            }
        }

        // Phase 2: rename-then-delete fallback (cross-platform)
        //
        // When Phase 1 fails, some files inside the session directory are still
        // locked by another FrankenPHP worker. Attempt a directory-level rename
        // which works on Linux/macOS even with open file handles.
        //
        // On Windows, rename() on a directory fails (Access denied) when child
        // files are open — this is expected. The @rename() is wrapped in a
        // try/catch because MadelineProto's exceptionErrorHandler converts
        // suppressed warnings into exceptions. If rename fails, we silently
        // fall through — all unlockable files were already removed by Phase 1,
        // so the session is effectively cleared.
        if (!$deleted) {
            $tempName = $sessionPath . '.obsolete.' . getmypid() . '.' . time();
            $renamed = false;
            try {
                $renamed = @rename($sessionPath, $tempName);
            } catch (\Throwable $e) {
                // Windows: rename on a directory with open child files fails.
                // This is expected — Phase 1 already removed unlockable files.
                $renamed = false;
            }
            if ($renamed) {
                // Session is now cleared. Best-effort cleanup of the renamed directory.
                try {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($tempName, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $fileinfo) {
                        if ($fileinfo->isDir()) {
                            @rmdir($fileinfo->getRealPath());
                        } else {
                            @unlink($fileinfo->getRealPath());
                        }
                    }
                    @rmdir($tempName);
                } catch (\Throwable $e) {
                    // Non-critical: renamed directory is inert and will be
                    // cleaned up on a subsequent clear_session() call.
                }
            }
        }
    } elseif (is_file($sessionPath)) {
        @unlink($sessionPath);
    }

    // Also clean up any top-level lock artifacts and cached credentials
    $staleLock = $sessionPath . '.lock';
    if (is_file($staleLock)) {
        @unlink($staleLock);
    }

    // Clear cached API credentials — forces re-fetch from WordPress on next login
    $credsCache = fd_storage_path('storage/api_credentials.json');
    if (is_file($credsCache)) {
        @unlink($credsCache);
    }

    // Clear the stored API secret — forces fresh secret on next login
    fd_clear_api_secret();
    fd_clear_bot_id();
}

/**
 * Check the application version against the WordPress minimum required version.
 *
 * Fetches min_version from FD_WP_VERSION_URL and caches the result for
 * FD_VERSION_CACHE_TTL seconds. Uses fd_http_json() which automatically
 * includes the X-API-Secret header. On failure, returns a safe default
 * (update_needed=false) so the app continues to work if WordPress is unreachable.
 *
 * @return array{ok:bool,update_needed:bool,current_version:string,minimum_version:string,update_url:string,release_notes:string}
 */
function fd_check_version(): array
{
    $cachePath = FD_VERSION_CACHE_PATH;

    // Try loading from cache first to avoid hitting WordPress on every request
    if (is_file($cachePath)) {
        $cached = @json_decode((string) file_get_contents($cachePath), true);
        if (is_array($cached) && !empty($cached['ok']) && !empty($cached['cached_at'])) {
            $age = time() - (int) $cached['cached_at'];
            if ($age < FD_VERSION_CACHE_TTL) {
                return $cached;
            }
        }
    }

    // Safe default returned on fetch failure
    $default = [
        'ok' => true,
        'update_needed' => false,
        'current_version' => FD_APP_VERSION,
        'minimum_version' => '',
        'update_url' => '',
        'release_notes' => '',
    ];

    // Fetch version info from WordPress (short timeout — fail fast on mobile/Termux)
    $response = fd_http_json(FD_WP_VERSION_URL, [], 'GET', 3);
    if (empty($response['ok']) || empty($response['min_version'])) {
        fd_log('version check failed — wordpress unreachable or invalid response');
        return $default;
    }

    $minVersion = (string) $response['min_version'];
    $updateUrl = (string) ($response['update_url'] ?? '');
    $releaseNotes = (string) ($response['release_notes'] ?? '');
    $current = FD_APP_VERSION;

    // Compare using PHP's version_compare (handles semver like "0.1.0" vs "0.2.0")
    $updateNeeded = $minVersion !== '' && version_compare($current, $minVersion, '<');

    $result = [
        'ok' => true,
        'update_needed' => $updateNeeded,
        'current_version' => $current,
        'minimum_version' => $minVersion,
        'update_url' => $updateUrl,
        'release_notes' => $releaseNotes,
        'cached_at' => time(),
    ];

    // Persist cache for next request
    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    @file_put_contents($cachePath, json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $result;
}

// ─── Stremio & Nuvio Helpers ─────────────────────────────────────────────────

function fd_format_bytes(int $bytes, int $precision = 1): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

/**
 * Clean Telegram file title from spam prefixes, channel promos, and bot forwarding artifacts.
 */
function fd_clean_media_title(string $title): string
{
    if ($title === '') return '';
    $t = $title;
    // Strip emojis
    $t = preg_replace('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', ' ', $t);
    // Strip website / streaming host prefixes
    $t = preg_replace('/^(?:on9[._\s]stream[._\s]+|stream[._\s]+|www\.[a-z0-9.-]+\.[a-z]{2,}[._\s]+)/i', '', $t);
    // Strip forwarded and join channel spam
    $t = preg_replace('/forwarded[._\s]from.*$/i', '', $t);
    $t = preg_replace('/(?:Join[._\s]Channel|Join[._\s]Group|Join[._\s]us|Join[._\s]@).*$/i', '', $t);
    $t = preg_replace('/kumpulan[._\s]drama.*$/i', '', $t);
    $t = preg_replace('/Please[.\s]Don[\x27\x22.]?t[.\s]Forward.*$/i', '', $t);
    $t = preg_replace('/(?:Req\.By|Request\.By|File\.Request\.By|Requested\.By).*$/i', '', $t);
    $t = preg_replace('/(?:Channel\.Terbaik\.Anda|Filemku\.bot|LayarAsiaBot|filembot).*$/i', '', $t);
    $t = preg_replace('/(?:https?:\/\/|httpst\.me|https?\.?t\.me|\bt\.me\/)[\w.\/\?=&_-]*/i', '', $t);
    $t = preg_replace('/[._\s]+Watch[._\s]Hd[._\s]Video[._\s]Online.*$/i', '', $t);
    $t = preg_replace('/(?:^|[.\s_#-]+)Open[.\s_-]*Mini[.\s_-]*App.*$/iu', '', $t);
    $t = preg_replace('/(?:[.\s_-]*\d+(?:[.,]\d+)?[.\s_-]*(?:MB|GB|KB|TB))+(?:[.\s_-]*https)?(?:[.\s_-]*Open[.\s_-]*Mini[.\s_-]*App)?$/iu', '', $t);

    // Trim trailing and leading punctuation/whitespace
    $t = trim($t, " ._-=\t\n\r\0\x0B");
    return $t;
}

function fd_classify_season_episode(string $title, int $seasonNum = 0, int $episodeNum = 0, string $caption = ''): array
{
    $season = 0;
    $episode = 0;
    $episodeEnd = 0;

    // Clean title from forwarded spam / suffixes before matching
    $title = fd_clean_media_title($title);

    // If filename is generic (e.g. video.2022.08.09... or video.mp4), fallback to caption for parsing
    if ($caption !== '' && preg_match('/^(?:video(?:\.\d+)*|\d+|document|file)\.(?:mp4|mkv|avi|mov|ts|flv)$/i', trim($title))) {
        $firstCaptionLine = trim(explode("\n", $caption)[0]);
        if ($firstCaptionLine !== '') {
            $title = fd_clean_media_title($firstCaptionLine);
        }
    }

    // 1. Explicit SxxExx.Exx (range like S01.E01.E14 or S01E01-E14 or S01E01-14)
    if (preg_match('/(?:^|[^a-z0-9])S(\d{1,2})\s*[ ._-]*E(?:P|PS|PISODE)?\s*[ ._-]*(\d{1,4})\s*(?:[ ._-]+E(?:P|PS|PISODE)?|\s*[-~–—]\s*|\s+(?:to|hingga|sampai)\s+)\s*(\d{1,4})(?:[^a-z0-9]|$)/i', $title, $m)) {
        $season = (int) $m[1];
        $episode = (int) $m[2];
        $episodeEnd = (int) $m[3];
    }
    // 1b. Single SxxExx or Sxx.Exx / SxxEPxx / SxxEpxx in title
    elseif (preg_match('/(?:^|[^a-z0-9])S(\d{1,2})\s*[ ._-]*E(?:P|PS|PISODE)?\s*[ ._-]*(\d{1,4})(?:[^a-z0-9]|$)/i', $title, $m)) {
        $season = (int) $m[1];
        $episode = (int) $m[2];
    }
    // 2. Explicit 1x05 / 01x12
    elseif (preg_match('/(?:^|[^a-z0-9])(\d{1,2})\s*[xX]\s*(\d{1,4})(?![0-9])(?:[^a-z0-9]|$)/', $title, $m)) {
        $season = (int) $m[1];
        $episode = (int) $m[2];
    }
    // 3. Part / Vol / Cour followed by season and episode (e.g. Part 3.01, Vol 2 - 05)
    elseif (preg_match('/(?:^|[^a-z0-9])(?:PART|VOL|VOLUME|COUR)\s*[ ._-]*0*(\d{1,2})[ ._-]+0*(\d{1,4})(?=[ ._\-\]\)]|$)/i', $title, $m)) {
        $season = (int) $m[1];
        $episode = (int) $m[2];
    }

    // 4. Season / Musim / Part / Cour / Vol keywords
    if ($season === 0) {
        if (preg_match('/(?:^|[^a-z0-9])(?:season|musim)\s*[ ._-]*0*(\d{1,2})(?:[^a-z0-9]|$)/i', $title, $m)) {
            $season = (int) $m[1];
        } elseif (preg_match('/(?:^|[^a-z0-9])(?:PART|VOL|VOLUME|COUR)\s*[ ._-]*0*(\d{1,2})(?=[ ._-]+(?:EP|E|\d))/i', $title, $m)) {
            $season = (int) $m[1];
        } elseif (preg_match('/(?:^|[^a-z0-9])S(\d{1,2})(?=[^a-z0-9]|$)/i', $title, $m)) {
            $season = (int) $m[1];
        }
    }

    // 5. Check explicit EP / Episode range tokens (e.g. EP01-EP14, EP01-14, E01.E14, E01-E14)
    if ($episode === 0) {
        if (preg_match('/(?:^|[^a-z0-9])(?:EP|EPS|EPISODE|EPISOD|E)\s*[ ._-]*0*(\d{1,4})\s*(?:[ ._-]+(?:EP|EPS|EPISODE|EPISOD|E)|\s*[-~–—]\s*|\s+(?:to|hingga|sampai)\s+)\s*0*(\d{1,4})(?:[^a-z0-9]|$)/i', $title, $m)) {
            $n1 = (int) $m[1];
            $n2 = (int) $m[2];
            if ($n1 > 0 && ($n1 < 1900 || $n1 > 2100) && $n2 > 0 && ($n2 < 1900 || $n2 > 2100) && $n2 >= $n1 && ($n2 - $n1) <= 150) {
                $episode = $n1;
                $episodeEnd = $n2;
            }
        }
    }

    // 6. Check explicit EP / Episode / Bahagian tokens in title (e.g. EP27, Episode 05)
    if ($episode === 0) {
        if (preg_match('/(?:^|[^a-z0-9])(?:EP|EPS|EPISODE|EPISOD|BAHAGIAN|BABAK)\s*[ ._-]*0*(\d{1,4})(?:[^a-z0-9]|$)/i', $title, $m)) {
            $n = (int) $m[1];
            if ($n > 0 && ($n < 1900 || $n > 2100)) {
                $episode = $n;
            }
        }
    }

    // 7. Token starting with E followed by digits (e.g. kdg.E01, OLD.E32, e27.end.mp4, DramaDaily.720p...E30.mp4)
    if ($episode === 0) {
        if (preg_match('/(?:^|[^a-z0-9])E[ ._-]*0*(\d{1,4})(?:[^a-z0-9]|$)/i', $title, $m)) {
            $n = (int) $m[1];
            if ($n > 0 && ($n < 1900 || $n > 2100)) {
                $episode = $n;
            }
        }
    }

    // 7. Part / Vol as episode fallback ONLY if season wasn't detected from it
    if ($episode === 0 && $season === 0) {
        if (preg_match('/(?:^|[^a-z0-9])(?:PART|VOL|VOLUME)\s*[ ._-]*0*(\d{1,4})(?:[^a-z0-9]|$)/i', $title, $m)) {
            $n = (int) $m[1];
            if ($n > 0 && ($n < 1900 || $n > 2100)) {
                $episode = $n;
            }
        }
    }

    // 6. Bare numbers without E/EP prefix (e.g. Flying.Up.Without.Disturb.32.480p.mp4, Title.06.720p.mp4, Title - 05.mkv)
    if ($episode === 0) {
        $clean = preg_replace('/\.(mp4|mkv|avi|mov|ts|flv|webm)$/i', '', $title);
        if (preg_match('/[ ._\[\(-](\d{1,3})[ ._\]\)-]+(?:2160p|1080p|720p|480p|360p|4k|uhd|fhd|hd|sd|web|bluray|hdtv|malaysub|end|final|x264|x265|hevc|aac)/i', $clean, $m)) {
            $n = (int) $m[1];
            if ($n > 0 && ($n < 1900 || $n > 2100)) {
                $episode = $n;
            }
        } elseif (preg_match('/[ ._\[\(-](\d{1,3})[ ._\]\)]*$/', $clean, $m)) {
            $n = (int) $m[1];
            if ($n > 0 && ($n < 1900 || $n > 2100)) {
                $episode = $n;
            }
        }
    }

    // Fallbacks to DB media-rank columns if not found in title
    if ($season === 0 && $seasonNum > 0) {
        $season = $seasonNum;
    }
    if ($episode === 0 && $episodeNum > 0) {
        $episode = $episodeNum;
    }

    if ($season === 0) {
        $season = 1;
    }

    return ['season' => $season, 'episode' => $episode, 'episode_end' => $episodeEnd];
}

function fd_file_matches_episode(array $parsed, int $targetSeason, int $targetEpisode): bool
{
    $s = $parsed['season'];
    $e = $parsed['episode'];
    $eEnd = $parsed['episode_end'] ?? 0;

    if ($s !== $targetSeason) {
        return false;
    }

    if ($eEnd > 0 && $eEnd >= $e) {
        return $targetEpisode >= $e && $targetEpisode <= $eEnd;
    }

    // If episode is 0 (unclassified/telefilm), it only matches episode 1 if targetEpisode is 1
    if ($e === 0) {
        return $targetEpisode === 1;
    }

    return $e === $targetEpisode;
}

function fd_fetch_stream_ajax(string $action, array $params = []): array
{
    $streamAction = 'stream_' . $action;
    $wpUrl = defined('FD_WP_AJAX_URL') ? FD_WP_AJAX_URL : 'https://pencarimovie.com/wp-admin/admin-ajax.php';
    $queryParams = $params;
    $queryParams['action'] = $streamAction;
    if (empty($queryParams['bot_id'])) {
        $activeBotId = fd_get_bot_id();
        if ($activeBotId !== '') {
            $queryParams['bot_id'] = $activeBotId;
        }
    }
    $wpUrl .= '?' . http_build_query($queryParams);

    try {
        $body = fd_http_get_contents($wpUrl, [
            'method' => 'GET',
            'headers' => ['X-Requested-With: XMLHttpRequest'],
            'timeout' => 15,
        ]);
        if ($body === false) {
            return [];
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['success']) && $decoded['success']) {
            return (array) ($decoded['data'] ?? []);
        }
        return is_array($decoded) ? $decoded : [];
    } catch (\Throwable $e) {
        fd_log('stremio wp ajax fetch failed', ['action' => $streamAction, 'error' => $e->getMessage()]);
        return [];
    }
}

function fd_get_lan_ip(): string
{
    if (stripos(PHP_OS, 'WIN') === 0) {
        $lines = [];
        @exec('route print -4 0.0.0.0', $lines);
        $bestIp = '';
        $bestMetric = 999999;
        foreach ($lines as $line) {
            if (preg_match('/0\.0\.0\.0\s+0\.0\.0\.0\s+(\S+)\s+(\d+\.\d+\.\d+\.\d+)\s+(\d+)/', $line, $m)) {
                $ip = $m[2];
                $metric = (int) $m[3];
                if ($ip !== '127.0.0.1' && !str_starts_with($ip, '169.254.') && !str_starts_with($ip, '192.168.56.')) {
                    if ($metric < $bestMetric) {
                        $bestMetric = $metric;
                        $bestIp = $ip;
                    }
                }
            }
        }
        if ($bestIp !== '') return $bestIp;
    } else {
        $lines = [];
        @exec('ip route get 8.8.8.8 2>/dev/null', $lines);
        foreach ($lines as $line) {
            if (preg_match('/src\s+(\d+\.\d+\.\d+\.\d+)/', $line, $m)) {
                return $m[1];
            }
        }
    }
    return '127.0.0.1';
}

function fd_get_stremio_base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8088';
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function fd_stremio_json(array $data, int $status = 200, ?string $cacheControl = null): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        if ($cacheControl !== null) {
            header('Cache-Control: ' . $cacheControl);
        } else {
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Routing ─────────────────────────────────────────────────────────────────

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ─── Nuvio Addon Routes ──────────────────────────────────────────────────────
// Support /nuvio, /stremio (alias redirect), and root level (/manifest.json, /catalog/..., /meta/..., /stream/...)
$isNuvioRoute = ($path === '/nuvio' || str_starts_with($path, '/nuvio/')) ||
    ($path === '/stremio' || str_starts_with($path, '/stremio/')) ||
    $path === '/manifest.json' ||
    preg_match('#^/(catalog|meta|stream)/#', $path);

if ($isNuvioRoute) {
    // Handle redirect for legacy /stremio to /nuvio
    if ($path === '/stremio' || $path === '/stremio/') {
        header('Location: /nuvio', true, 301);
        exit;
    }

    // Normalize path by stripping /nuvio or /stremio prefix if present so internal matching is uniform
    $addonPath = preg_replace('#^/(nuvio|stremio)#', '', $path);
    if ($addonPath === '') {
        $addonPath = '/';
    }
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        exit;
    }

    $baseUrl = fd_get_stremio_base_url();

    // ── Nuvio Addon Installation / Landing Page ──
    if ($addonPath === '/' && ($path === '/nuvio' || $path === '/nuvio/')) {
        header('Content-Type: text/html; charset=utf-8');
        $manifestUrl = $baseUrl . '/manifest.json';
        $lanIp = fd_get_lan_ip();
        $lanManifestUrl = ($lanIp !== '127.0.0.1') ? preg_replace('#://[^/]+#', '://' . $lanIp . ':8088', $manifestUrl) : $manifestUrl;
?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>PencariMovie Nuvio Addon</title>
            <style>
                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                }

                body {
                    background: #141414;
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    padding: 20px;
                }

                .card {
                    background: #1f1f1f;
                    border-radius: 14px;
                    padding: 40px;
                    max-width: 540px;
                    width: 100%;
                    text-align: center;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6);
                    border: 1px solid #2e2e2e;
                }

                .logo {
                    font-size: 2.2rem;
                    font-weight: 800;
                    color: #ff6b35;
                    margin-bottom: 10px;
                    letter-spacing: -0.5px;
                }

                .badge {
                    display: inline-block;
                    background: rgba(255, 107, 53, 0.15);
                    color: #ff6b35;
                    font-size: 0.8rem;
                    font-weight: 700;
                    padding: 4px 12px;
                    border-radius: 20px;
                    margin-bottom: 16px;
                    border: 1px solid rgba(255, 107, 53, 0.3);
                }

                .tagline {
                    color: #b0b0b0;
                    font-size: 1rem;
                    margin-bottom: 24px;
                    line-height: 1.5;
                }

                .manifest-label {
                    text-align: left;
                    font-size: 0.85rem;
                    color: #888;
                    margin-bottom: 6px;
                    font-weight: 600;
                }

                .manifest-box {
                    background: #121212;
                    padding: 14px;
                    border-radius: 8px;
                    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
                    font-size: 0.9rem;
                    color: #00d26a;
                    word-break: break-all;
                    margin-bottom: 14px;
                    border: 1px solid #2a2a2a;
                    text-align: left;
                    user-select: all;
                }

                .btn {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                    background: #ff6b35;
                    color: #fff;
                    text-decoration: none;
                    padding: 14px 24px;
                    border-radius: 8px;
                    font-weight: 700;
                    font-size: 1.05rem;
                    transition: all 0.2s;
                    margin-bottom: 20px;
                    width: 100%;
                    border: none;
                    cursor: pointer;
                }

                .btn:hover {
                    background: #ff824d;
                    transform: translateY(-1px);
                    box-shadow: 0 4px 14px rgba(255, 107, 53, 0.35);
                }

                .instructions {
                    background: #181818;
                    border-radius: 10px;
                    padding: 20px;
                    text-align: left;
                    border: 1px solid #282828;
                }

                .instructions-title {
                    font-size: 0.95rem;
                    font-weight: 700;
                    color: #fff;
                    margin-bottom: 12px;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .instructions ol {
                    margin-left: 20px;
                    color: #aaa;
                    font-size: 0.88rem;
                    line-height: 1.6;
                }

                .instructions li {
                    margin-bottom: 8px;
                }

                .instructions li strong {
                    color: #eee;
                }

                .status-copied {
                    display: none;
                    background: rgba(46, 204, 113, 0.15);
                    color: #2ecc71;
                    padding: 8px 12px;
                    border-radius: 6px;
                    font-size: 0.85rem;
                    margin-bottom: 16px;
                    border: 1px solid rgba(46, 204, 113, 0.3);
                }
            </style>
        </head>

        <body>
            <div class="card">
                <div class="logo">PencariMovie</div>
                <div class="badge">NUVIO ADDON</div>
                <div class="tagline">Stream movies, series from Telegram server directly in Nuvio</div>

                <div class="manifest-label">Nuvio Manifest URL:</div>
                <div class="manifest-box" id="mUrl"><?= htmlspecialchars($lanManifestUrl) ?></div>

                <div id="copiedNotice" class="status-copied">✓ Copied to clipboard!</div>

                <button class="btn" onclick="copyManifestUrl()">
                    📋 Copy Manifest URL for Nuvio
                </button>

                <div class="instructions">
                    <div class="instructions-title">🚀 How to install in Nuvio:</div>
                    <ol>
                        <li>Open the <strong>Nuvio</strong> app on your Android TV, tablet, or phone.</li>
                        <li>Go to <strong>profile</strong> &rarr; <strong>content & discovery</strong> &rarr; <strong>addons</strong>.</li>
                        <li>Paste the Manifest URL copied above and <strong>Install addon</strong>.</li>
                    </ol>
                </div>
            </div>

            <script>
                function copyManifestUrl() {
                    const val = document.getElementById('mUrl').textContent.trim();
                    navigator.clipboard.writeText(val);
                    const notice = document.getElementById('copiedNotice');
                    notice.style.display = 'block';
                    setTimeout(() => {
                        notice.style.display = 'none';
                    }, 3000);
                }
            </script>
        </body>

        </html>
<?php
        exit;
    }

    // ── Nuvio / Stremio Manifest ──
    if ($addonPath === '/manifest.json') {
        // Fetch genre list from WordPress (matching public/app.js)
        $categories = fd_fetch_stream_ajax('categories');
        $defaultCategories = [
            ['name' => 'Animation', 'slug' => 'animation'],
            ['name' => 'Action', 'slug' => 'action'],
            ['name' => 'Comedy', 'slug' => 'comedy'],
            ['name' => 'Drama', 'slug' => 'drama'],
            ['name' => 'Horror', 'slug' => 'horror'],
            ['name' => 'Sci-Fi', 'slug' => 'sci-fi'],
            ['name' => 'Thriller', 'slug' => 'thriller'],
            ['name' => 'Malay', 'slug' => 'malay'],
            ['name' => 'Indo', 'slug' => 'indonesian'],
            ['name' => 'Korean', 'slug' => 'korean'],
        ];

        $categoryList = (!empty($categories) && is_array($categories)) ? $categories : $defaultCategories;

        // Pure Film & TV Genres for Stremio filter dropdown
        $allGenreOptions = [
            'Action',
            'Adventure',
            'Animation',
            'Anime',
            'Biography',
            'Comedy',
            'Crime',
            'Documentary',
            'Drama',
            'Family',
            'Fantasy',
            'History',
            'Horror',
            'Music',
            'Musical',
            'Mystery',
            'Romance',
            'Sci-Fi',
            'Sport',
            'Thriller',
            'War',
            'Western',
        ];

        // Clean Stremio / Nuvio Catalogs by Country / Origin and Trending:
        // Types: only standard 'movie' and 'series'
        $manifestCatalogs = [
            // Movies Catalogs
            [
                'type' => 'movie',
                'id' => 'pm_search_movie',
                'name' => 'Search Movies',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'search', 'isRequired' => true],
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_search_files',
                'name' => 'Telegram Files',
                'genres' => ['4K', '1080p', '720p', 'BluRay', 'WEB-DL', 'HEVC'],
                'extra' => [
                    ['name' => 'search', 'isRequired' => true],
                    ['name' => 'genre', 'options' => ['4K', '1080p', '720p', 'BluRay', 'WEB-DL', 'HEVC'], 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_trending_movies',
                'name' => 'Trending',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_malay',
                'name' => 'Malaysia',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_indo',
                'name' => 'Indonesia',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_korean',
                'name' => 'Korea',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_japan',
                'name' => 'Japan',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_anime',
                'name' => 'Anime',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_chinese',
                'name' => 'China / HK',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_thai',
                'name' => 'Thailand',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_bollywood',
                'name' => 'Bollywood',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_philippines',
                'name' => 'Philippines',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'movie',
                'id' => 'pm_movies_english',
                'name' => 'English',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],

            // Series Catalogs
            [
                'type' => 'series',
                'id' => 'pm_search_series',
                'name' => 'Search Series',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'search', 'isRequired' => true],
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_trending_series',
                'name' => 'Trending',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_kdrama',
                'name' => 'K-Drama',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_anime',
                'name' => 'Anime',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_japan',
                'name' => 'J-Drama',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_malay',
                'name' => 'Malaysia',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_cdrama',
                'name' => 'C-Drama',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_thai',
                'name' => 'Thailand',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_philippines',
                'name' => 'Philippines',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_english',
                'name' => 'English',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
            [
                'type' => 'series',
                'id' => 'pm_series_indo',
                'name' => 'Indonesia',
                'genres' => $allGenreOptions,
                'extra' => [
                    ['name' => 'genre', 'options' => $allGenreOptions, 'isRequired' => false],
                    ['name' => 'skip', 'isRequired' => false],
                ],
            ],
        ];

        $manifest = [
            'id' => 'org.pencarimovie.addon',
            'version' => '2.0.0',
            'name' => 'PencariMovie',
            'description' => 'Stream movies, series from Telegram server directly in Nuvio',
            'resources' => ['catalog', 'meta', 'stream'],
            'types' => ['movie', 'series'],
            'idPrefixes' => ['pm:', 'tt'],
            'catalogs' => $manifestCatalogs,
            'behaviorHints' => [
                'configurable' => false,
                'configurationRequired' => false,
                'adult' => false,
                'p2p' => false,
            ],
        ];

        fd_stremio_json($manifest, 200, 'max-age=3600, public');
    }

    // ── Nuvio Catalog: /catalog/:type/:id[/:extra].json ──
    if (preg_match('#^/catalog/([^/]+)/([^/]+?)(?:/(.*))?\.json$#', $addonPath, $matches)) {
        $catalogType = $matches[1];
        $catalogId = $matches[2];
        $extraStr = $matches[3] ?? '';

        $extra = [];
        if ($extraStr !== '') {
            $decodedExtra = urldecode($extraStr);
            $parsedJson = json_decode($decodedExtra, true);
            if (is_array($parsedJson)) {
                $extra = $parsedJson;
            } else {
                $pairs = explode('&', $decodedExtra);
                foreach ($pairs as $p) {
                    $kv = explode('=', $p, 2);
                    if (count($kv) === 2) {
                        $extra[$kv[0]] = $kv[1];
                    }
                }
            }
        }
        // Also support query string parameters (?skip=...&genre=...&search=...)
        if (isset($_GET['search'])) $extra['search'] = (string)$_GET['search'];
        if (isset($_GET['genre'])) $extra['genre'] = (string)$_GET['genre'];
        if (isset($_GET['skip'])) $extra['skip'] = (int)$_GET['skip'];

        $searchQuery = $extra['search'] ?? '';
        $genre = $extra['genre'] ?? '';
        $skip = (int) ($extra['skip'] ?? 0);
        $limit = ($searchQuery !== '') ? 60 : 24;

        $metas = [];

        if ($searchQuery !== '') {
            // Search mode - strictly segregate Movie search vs Series search

            // 1. Search posts
            $searchPosts = fd_fetch_stream_ajax('search', [
                'search' => $searchQuery,
                'limit' => 30,
                'offset' => $skip,
            ]);

            if (is_array($searchPosts)) {
                foreach ($searchPosts as $post) {
                    $pId = $post['id'] ?? 0;
                    if (!$pId) continue;
                    $pTitle = $post['title'] ?? '';
                    $pThumb = $post['thumbnail_url'] ?? '';
                    $pExcerpt = $post['excerpt'] ?? '';
                    $pCats = (array) ($post['categories'] ?? []);

                    $isSeries = preg_match('/tvseries|series|season|episode|drama/i', $pTitle . ' ' . implode(' ', $pCats));

                    // Strict filtering: Movies search only shows movies; Series search only shows series
                    if ($catalogType === 'movie' && $isSeries) {
                        continue;
                    }
                    if ($catalogType === 'series' && !$isSeries) {
                        continue;
                    }

                    $itemType = ($catalogType === 'series' || $isSeries) ? 'series' : 'movie';

                    $metas[] = [
                        'id' => 'pm:post:' . $pId,
                        'type' => $itemType,
                        'name' => $pTitle,
                        'poster' => $pThumb,
                        'posterShape' => 'landscape',
                        'description' => $pExcerpt,
                        'genres' => $pCats,
                    ];
                }
            }

            // 2. Search direct Telegram files (included in separate pm_search_files catalog)
            if ($catalogId === 'pm_search_files') {
                $metas = []; // Reset metas to ensure direct Telegram files only
                $searchFiles = fd_fetch_stream_ajax('search_files', [
                    'search' => $searchQuery,
                    'limit' => 50,
                    'offset' => $skip,
                ]);

                if (is_array($searchFiles) && isset($searchFiles['files']) && is_array($searchFiles['files'])) {
                    foreach ($searchFiles['files'] as $file) {
                        $fCode = $file['short_code'] ?? '';
                        if ($fCode === '') continue;
                        $fTitle = $file['title'] ?? 'Telegram File';
                        $fThumb = $file['thumbnail_url'] ?? '';
                        $fSize = (int) ($file['file_size'] ?? 0);

                        // Extract resolution & format tags
                        $pills = [];
                        if (preg_match('/\b(2160p|4[kK]|uhd)\b/i', $fTitle)) $pills[] = '4K';
                        elseif (preg_match('/\b(1080p|fhd)\b/i', $fTitle)) $pills[] = '1080p';
                        elseif (preg_match('/\b(720p|hd)\b/i', $fTitle)) $pills[] = '720p';
                        elseif (preg_match('/\b(480p|360p|sd)\b/i', $fTitle)) $pills[] = 'SD';

                        if (preg_match('/\b(bluray|blu-ray|remux)\b/i', $fTitle)) $pills[] = 'BluRay';
                        elseif (preg_match('/\b(web-?dl|webrip)\b/i', $fTitle)) $pills[] = 'WEB-DL';
                        if (preg_match('/\b(hevc|x265|h265)\b/i', $fTitle)) $pills[] = 'HEVC';

                        if ($fSize > 0) $pills[] = fd_format_bytes($fSize);

                        $pillLine = !empty($pills) ? implode(' · ', $pills) : 'Ready to stream';
                        $genres = array_values(array_unique(array_merge(['Direct File'], $pills)));

                        $metas[] = [
                            'id' => 'pm:file:' . $fCode,
                            'type' => 'movie',
                            'name' => $fTitle,
                            'poster' => $fThumb,
                            'posterShape' => 'landscape',
                            'description' => "⚡ Direct Telegram File · {$pillLine}\n\n{$fTitle}",
                            'genres' => $genres,
                        ];
                    }
                }
            }
        } elseif ($catalogId === 'pm_trending' || $catalogId === 'pm_trending_movies' || $catalogId === 'pm_trending_series') {
            // Trending Catalog - fetch top trending searches from PencariMovie
            $trending = fd_fetch_stream_ajax('trending', ['limit' => 20]);
            $trendingKeywords = [];
            if (is_array($trending)) {
                foreach ($trending as $t) {
                    $kw = trim($t['keyword'] ?? '');
                    if ($kw !== '') $trendingKeywords[] = $kw;
                }
            }

            $seenPostIds = [];
            foreach (array_slice($trendingKeywords, 0, 10) as $kw) {
                $kwPosts = fd_fetch_stream_ajax('search', ['search' => $kw, 'limit' => 4]);
                if (is_array($kwPosts)) {
                    foreach ($kwPosts as $post) {
                        $pId = $post['id'] ?? 0;
                        if (!$pId || isset($seenPostIds[$pId])) continue;
                        $seenPostIds[$pId] = true;

                        $pTitle = $post['title'] ?? '';
                        $pThumb = $post['thumbnail_url'] ?? '';
                        $pExcerpt = $post['excerpt'] ?? '';
                        $pCats = (array) ($post['categories'] ?? []);

                        $isSeries = preg_match('/tvseries|series|season|episode|drama/i', $pTitle . ' ' . implode(' ', $pCats));

                        // Filter strictly for Movie or Series trending rows
                        if ($catalogId === 'pm_trending_series' && !$isSeries) continue;
                        if ($catalogId === 'pm_trending_movies' && $isSeries) continue;

                        $itemType = ($catalogType === 'series' || $isSeries) ? 'series' : 'movie';

                        $metas[] = [
                            'id' => 'pm:post:' . $pId,
                            'type' => $itemType,
                            'name' => $pTitle,
                            'poster' => $pThumb,
                            'posterShape' => 'landscape',
                            'description' => '🔥 Trending: ' . $kw . ($pExcerpt !== '' ? "\n\n" . $pExcerpt : ''),
                            'genres' => array_values(array_unique(array_merge(['Trending'], $pCats))),
                        ];
                    }
                }
            }
        } else {
            // Normal Category / Movies / Series / Specific Genre catalog
            // Fetch extra items so that after filtering out mismatched media types (e.g. series in movie catalog),
            // the returned list remains full.
            $fetchLimit = max($limit * 2, 40);
            $params = [
                'limit' => $fetchLimit,
                'offset' => $skip,
            ];

            $catalogCategoryMap = [
                'pm_movies_malay' => 'malay',
                'pm_movies_indo' => 'indonesian',
                'pm_movies_korean' => 'korea',
                'pm_movies_japan' => 'japan',
                'pm_movies_anime' => 'anime',
                'pm_movies_chinese' => 'china',
                'pm_movies_thai' => 'thai',
                'pm_movies_bollywood' => 'bollywood',
                'pm_movies_philippines' => 'filipino',
                'pm_movies_pinoy' => 'filipino',
                'pm_movies_english' => 'english',
                'pm_series_kdrama' => 'korea',
                'pm_series_anime' => 'anime',
                'pm_series_japan' => 'japan',
                'pm_series_malay' => 'malay',
                'pm_series_cdrama' => 'china',
                'pm_series_thai' => 'thai',
                'pm_series_philippines' => 'filipino',
                'pm_series_pinoy' => 'filipino',
                'pm_series_english' => 'english',
                'pm_series_indo' => 'indonesian',
            ];

            if (isset($catalogCategoryMap[$catalogId])) {
                $params['category'] = $catalogCategoryMap[$catalogId];
            } elseif (str_starts_with($catalogId, 'pm_cat_')) {
                $catSlug = substr($catalogId, strlen('pm_cat_'));
                if ($catSlug === 'indo') $catSlug = 'indonesian';
                $params['category'] = $catSlug;
            } elseif ($genre !== '') {
                $gSlug = strtolower(trim($genre));
                $gSlug = match ($gSlug) {
                    'indo' => 'indonesian',
                    'korean' => 'korea',
                    'japanese' => 'japan',
                    'chinese' => 'china',
                    'philippines', 'pinoy', 'tagalog' => 'filipino',
                    default => $gSlug,
                };
                $params['category'] = $gSlug;
            } elseif ($catalogType === 'series' || $catalogId === 'pm_series') {
                $params['category'] = 'drama';
            }

            $posts = fd_fetch_stream_ajax('posts', $params);
            if (is_array($posts)) {
                foreach ($posts as $post) {
                    $pId = $post['id'] ?? 0;
                    $pTitle = $post['title'] ?? '';
                    $pThumb = $post['thumbnail_url'] ?? '';
                    $pExcerpt = $post['excerpt'] ?? '';
                    $pCats = (array) ($post['categories'] ?? []);

                    $isSeries = preg_match('/tvseries|series|season|episode|drama/i', $pTitle . ' ' . implode(' ', $pCats));

                    // Filter based on catalog type so Movies and Series rows are strictly segregated
                    if ($catalogType === 'series' && !$isSeries) {
                        continue;
                    }
                    if ($catalogType === 'movie' && $isSeries) {
                        continue;
                    }

                    $itemType = ($catalogType === 'series' || $isSeries) ? 'series' : 'movie';

                    $metas[] = [
                        'id' => 'pm:post:' . $pId,
                        'type' => $itemType,
                        'name' => $pTitle,
                        'poster' => $pThumb,
                        'posterShape' => 'landscape',
                        'description' => $pExcerpt,
                        'genres' => $pCats,
                    ];
                }
            }
        }

        fd_stremio_json(['metas' => $metas], 200, 'max-age=600, public');
    }

    // ── Nuvio Meta: /meta/:type/:id.json ──
    if (preg_match('#^/meta/([^/]+)/([^/]+)\.json$#', $addonPath, $matches)) {
        $itemType = urldecode($matches[1]);
        $itemId = urldecode(urldecode($matches[2])); // Handle double-encoded IDs from web clients

        // Format 1: Direct tg_file_new (pm:file:SHORT_CODE)
        if (str_starts_with($itemId, 'pm:file:')) {
            $shortCode = substr($itemId, strlen('pm:file:'));
            $botId = fd_get_bot_id();

            $title = '';
            $thumb = '';
            $size = 0;
            $fileType = '';

            // First check search_files directly (now handles exact short_code lookup via Manticore)
            $sf = fd_fetch_stream_ajax('search_files', ['search' => $shortCode, 'limit' => 1]);
            if (is_array($sf) && !empty($sf['files'])) {
                foreach ($sf['files'] as $f) {
                    if (($f['short_code'] ?? '') === $shortCode || count($sf['files']) === 1) {
                        $title = (string) ($f['title'] ?? '');
                        $thumb = (string) ($f['thumbnail_url'] ?? '');
                        $size = (int) ($f['file_size'] ?? 0);
                        $fileType = (string) ($f['file_type'] ?? ($f['extension'] ?? ''));
                        break;
                    }
                }
            }

            // If missing metadata or thumbnail, call resolve_shortcode
            if ($title === '' || $thumb === '' || $size === 0) {
                $res = fd_resolve_shortcode($shortCode, $botId);
                if ($title === '' && !empty($res['title'])) $title = (string) $res['title'];
                if ($thumb === '' && !empty($res['thumbnail_url'])) $thumb = (string) $res['thumbnail_url'];
                if ($size === 0 && !empty($res['file_size'])) $size = (int) $res['file_size'];
                if ($fileType === '' && !empty($res['file_type'])) $fileType = (string) $res['file_type'];
            }

            if ($title === '') {
                $title = 'File ' . $shortCode;
            }

            $cleanTitle = fd_clean_media_title($title);
            if ($cleanTitle === '') $cleanTitle = $title;

            // Extract tags & details for clean description
            $pills = [];
            if (preg_match('/\b(2160p|4[kK]|uhd)\b/i', $title)) $pills[] = '4K UHD';
            elseif (preg_match('/\b(1080p|fhd)\b/i', $title)) $pills[] = '1080p';
            elseif (preg_match('/\b(720p|hd)\b/i', $title)) $pills[] = '720p';
            elseif (preg_match('/\b(480p|360p|sd)\b/i', $title)) $pills[] = 'SD';

            if (preg_match('/\b(bluray|blu-ray|remux)\b/i', $title)) $pills[] = 'BluRay';
            elseif (preg_match('/\b(web-?dl|webrip)\b/i', $title)) $pills[] = 'WEB-DL';
            elseif (preg_match('/\b(hdtv|tvrip)\b/i', $title)) $pills[] = 'HDTV';

            if (preg_match('/\b(hdr10\+|hdr10|hdr|dolby\s*vision|dovi|dv)\b/i', $title)) $pills[] = 'HDR';
            if (preg_match('/\b(hevc|x265|h265)\b/i', $title)) $pills[] = 'HEVC';
            elseif (preg_match('/\b(avc|x264|h264)\b/i', $title)) $pills[] = 'AVC';

            if ($size > 0) $pills[] = fd_format_bytes($size);

            $pillLine = !empty($pills) ? implode('  •  ', $pills) : 'Ready to stream';
            $genres = array_values(array_unique(array_merge(['Direct Telegram File'], $pills)));

            $meta = [
                'id' => $itemId,
                'type' => 'movie',
                'name' => $cleanTitle,
                'poster' => $thumb,
                'posterShape' => 'landscape',
                'background' => $thumb,
                'logo' => $thumb,
                'description' => "⚡ Direct Telegram Cloud File\n" . $pillLine . "\n\n📄 File: " . $cleanTitle,
                'genres' => $genres,
            ];

            fd_stremio_json(['meta' => $meta], 200, 'max-age=600, public');
        }

        // Format 2: WordPress Post (pm:post:POST_ID)
        if (str_starts_with($itemId, 'pm:post:')) {
            $postId = (int) substr($itemId, strlen('pm:post:'));
            $postData = fd_fetch_stream_ajax('get_post', ['post_id' => $postId]);
            $post = !empty($postData) && is_array($postData) ? ($postData[0] ?? $postData) : [];

            $title = $post['title'] ?? 'PencariMovie Media';
            $thumb = $post['thumbnail_url'] ?? '';
            $excerpt = $post['excerpt'] ?? ($post['content'] ?? '');
            $cats = (array) ($post['categories'] ?? []);
            $tags = (array) ($post['tags'] ?? []);

            // Fetch attached files for this post (support long series with up to 5000 files)
            $filesRes = fd_fetch_stream_ajax('post_files', ['post_id' => $postId, 'limit' => 5000]);
            $files = (array) ($filesRes['files'] ?? []);

            $isSeries = ($itemType === 'series') || preg_match('/tvseries|series|season|episode|drama/i', $title . ' ' . implode(' ', $cats));
            $resolvedType = $isSeries ? 'series' : 'movie';

            $videos = [];
            if ($isSeries && !empty($files)) {
                $groupedEpisodes = [];
                $epIndex = 1;
                $hasExplicitEp = false;

                // First pass: classify season & episode for all files
                foreach ($files as $f) {
                    $fCode = $f['short_code'] ?? '';
                    if ($fCode === '') continue;
                    $fTitle = $f['title'] ?? ('Episode ' . $epIndex);
                    $fThumb = $f['thumbnail_url'] ?? $thumb;
                    $fCaption = $f['caption'] ?? '';
                    $parsed = fd_classify_season_episode($fTitle, (int)($f['season_num'] ?? 0), (int)($f['episode_num'] ?? 0), $fCaption);
                    $s = $parsed['season'];
                    $e = $parsed['episode'];
                    $eEnd = $parsed['episode_end'] ?? 0;

                    if ($e > 0) {
                        $hasExplicitEp = true;
                    }

                    // If file is a combined range (e.g. E01-E14), expand to all covered episodes
                    $epList = ($eEnd > 0 && $eEnd >= $e && ($eEnd - $e) <= 100)
                        ? range($e, $eEnd)
                        : [$e];

                    foreach ($epList as $targetEp) {
                        $key = "{$s}_{$targetEp}";
                        if (!isset($groupedEpisodes[$key])) {
                            $cleanFTitle = fd_clean_media_title($fTitle);
                            $epTitle = ($targetEp > 0 ? "Episode {$targetEp}" : ($cleanFTitle !== '' ? $cleanFTitle : "Episode {$epIndex}"));
                            $groupedEpisodes[$key] = [
                                'season' => $s,
                                'episode' => $targetEp,
                                'title' => $epTitle,
                                'thumbnail' => $fThumb,
                                'added_date' => $f['added_date'] ?? null,
                                'raw_index' => $epIndex,
                            ];
                        }
                    }
                    $epIndex++;
                }

                // If no file had explicit episode numbers (e.g. telefilm with multiple qualities), group under Episode 1
                if (!$hasExplicitEp && count($groupedEpisodes) > 1 && isset($groupedEpisodes['1_0'])) {
                    $firstKey = array_key_first($groupedEpisodes);
                    $firstItem = $groupedEpisodes[$firstKey];
                    $groupedEpisodes = [
                        '1_1' => [
                            'season' => 1,
                            'episode' => 1,
                            'title' => $title,
                            'thumbnail' => $firstItem['thumbnail'] ?? $thumb,
                            'added_date' => $firstItem['added_date'] ?? null,
                            'raw_index' => 1,
                        ]
                    ];
                }

                // Convert grouped episodes to Stremio videos list
                foreach ($groupedEpisodes as $epInfo) {
                    $s = $epInfo['season'];
                    $e = $epInfo['episode'] > 0 ? $epInfo['episode'] : 1;
                    $relDate = !empty($epInfo['added_date']) && is_numeric($epInfo['added_date'])
                        ? date('Y-m-d\TH:i:s\Z', (int)$epInfo['added_date'])
                        : date('Y-m-d\TH:i:s\Z');

                    $videos[] = [
                        'id' => "pm:post:{$postId}:{$s}:{$e}",
                        'title' => $epInfo['title'],
                        'season' => $s,
                        'episode' => $e,
                        'released' => $relDate,
                        'thumbnail' => $epInfo['thumbnail'] ?: $thumb,
                        'raw_index' => $epInfo['raw_index'],
                    ];
                }

                // Sort series videos in natural ascending order: Season ASC, Episode ASC
                usort($videos, function ($a, $b) {
                    if ($a['season'] !== $b['season']) {
                        return $a['season'] <=> $b['season'];
                    }
                    if ($a['episode'] !== $b['episode']) {
                        return $a['episode'] <=> $b['episode'];
                    }
                    return $a['raw_index'] <=> $b['raw_index'];
                });

                // Remove temporary raw_index key
                foreach ($videos as &$v) {
                    unset($v['raw_index']);
                }
                unset($v);
            }

            $meta = [
                'id' => $itemId,
                'type' => $resolvedType,
                'name' => $title,
                'poster' => $thumb,
                'posterShape' => 'landscape',
                'background' => $thumb,
                'description' => strip_tags((string) $excerpt),
                'genres' => $cats,
            ];

            // For Stremio protocol: 'videos' is ONLY provided for 'series'.
            // For 'movie', no 'videos' array is provided, so Stremio shows a single direct Play button without seasons/episodes.
            if ($isSeries && !empty($videos)) {
                $meta['videos'] = $videos;
            }

            fd_stremio_json(['meta' => $meta], 200, 'max-age=600, public');
        }

        fd_stremio_json(['meta' => null], 404);
    }

    // ── Nuvio Stream: /stream/:type/:id.json ──
    if (preg_match('#^/stream/([^/]+)/([^/]+)\.json$#', $addonPath, $matches)) {
        $itemType = urldecode($matches[1]);
        $itemId = urldecode(urldecode($matches[2])); // Handle double-encoded IDs from web clients
        $streams = [];

        $botIdStr = fd_get_bot_id();
        $hasSession = $botIdStr !== '' && (is_file(FD_SESSION_PATH) || is_dir(FD_SESSION_PATH));

        // If no bot is connected / bot is disconnected, return instructional connect stream card
        if (!$hasSession || $botIdStr === '') {
            $streams[] = [
                'name' => '⚠️ Connect Bot',
                'title' => "Telegram Bot not connected!\n👉 Tap to open dashboard & connect bot token",
                'description' => "Telegram Bot not connected!\n👉 Tap to open dashboard & connect bot token",
                'externalUrl' => $baseUrl . '/#settings',
            ];
            fd_stremio_json(['streams' => $streams]);
        }

        // Collect all target files to stream
        $filesToStream = [];

        if (str_starts_with($itemId, 'pm:file:')) {
            $fCode = substr($itemId, strlen('pm:file:'));
            $fileObj = ['short_code' => $fCode];

            // Resolve file details for instant direct playback with rich metadata
            $sf = fd_fetch_stream_ajax('search_files', ['search' => $fCode, 'limit' => 1]);
            if (is_array($sf) && !empty($sf['files'])) {
                foreach ($sf['files'] as $f) {
                    if (($f['short_code'] ?? '') === $fCode || count($sf['files']) === 1) {
                        $fileObj['title'] = (string) ($f['title'] ?? '');
                        $fileObj['file_size'] = (int) ($f['file_size'] ?? 0);
                        $fileObj['mime'] = (string) ($f['file_type'] ?? 'video/mp4');
                        break;
                    }
                }
            }

            if (empty($fileObj['title']) || empty($fileObj['file_size'])) {
                $res = fd_resolve_shortcode($fCode, $botIdStr);
                if (!empty($res['title'])) $fileObj['title'] = (string) $res['title'];
                if (!empty($res['file_size'])) $fileObj['file_size'] = (int) $res['file_size'];
                if (!empty($res['file_type'])) $fileObj['mime'] = (string) $res['file_type'];
            }

            $filesToStream[] = $fileObj;
        } elseif (preg_match('/^pm:post:(\d+):(\d+):(\d+)$/', $itemId, $m)) {
            // Series Episode requested: pm:post:POST_ID:SEASON:EPISODE
            $postId = (int) $m[1];
            $targetSeason = (int) $m[2];
            $targetEpisode = (int) $m[3];

            $filesRes = fd_fetch_stream_ajax('post_files', ['post_id' => $postId, 'limit' => 5000]);
            $postFiles = (array) ($filesRes['files'] ?? []);

            foreach ($postFiles as $pf) {
                if (empty($pf['short_code'])) continue;
                $fTitle = $pf['title'] ?? '';
                $fCaption = $pf['caption'] ?? '';
                $parsed = fd_classify_season_episode($fTitle, (int)($pf['season_num'] ?? 0), (int)($pf['episode_num'] ?? 0), $fCaption);
                if (fd_file_matches_episode($parsed, $targetSeason, $targetEpisode)) {
                    $filesToStream[] = $pf;
                }
            }

            // Fallback: if no exact match (e.g. single unnumbered telefilm file), use all post files
            if (empty($filesToStream)) {
                $filesToStream = $postFiles;
            }

            // Sort episode streams by quality: 4K UHD -> 1080p -> 720p -> SD -> file_size DESC
            usort($filesToStream, function ($a, $b) {
                $getScore = function ($title, $size) {
                    if (preg_match('/\b(2160p|4[kK]|uhd)\b/i', $title)) return 4000000000 + $size;
                    if (preg_match('/\b(1080p|fhd)\b/i', $title)) return 3000000000 + $size;
                    if (preg_match('/\b(720p|hd)\b/i', $title)) return 2000000000 + $size;
                    if (preg_match('/\b(480p|360p|sd)\b/i', $title)) return 1000000000 + $size;
                    return $size;
                };
                return $getScore($b['title'] ?? '', (int)($b['file_size'] ?? 0)) <=> $getScore($a['title'] ?? '', (int)($a['file_size'] ?? 0));
            });
        } elseif (preg_match('/^pm:post:(\d+):([a-zA-Z0-9_-]+)$/', $itemId, $m)) {
            // Legacy / direct file short code within post
            $fCode = $m[2];
            $filesToStream[] = ['short_code' => $fCode];
        } elseif (str_starts_with($itemId, 'pm:post:')) {
            // Whole post requested (e.g. movie post with multiple qualities or video files)
            $postId = (int) substr($itemId, strlen('pm:post:'));
            $postData = fd_fetch_stream_ajax('get_post', ['post_id' => $postId]);
            $post = !empty($postData) && is_array($postData) ? ($postData[0] ?? $postData) : [];
            $postTitle = $post['title'] ?? '';

            $filesRes = fd_fetch_stream_ajax('post_files', ['post_id' => $postId, 'limit' => 5000]);
            $postFiles = (array) ($filesRes['files'] ?? []);

            // For movie streams, strictly filter files to match post title and year
            if ($itemType === 'movie' || (!empty($postTitle) && !preg_match('/tvseries|series|season|episode|drama/i', $postTitle))) {
                $postYear = null;
                if (preg_match('/\b(19\d\d|20\d\d)\b/', $postTitle, $ym)) {
                    $postYear = $ym[1];
                }

                $cleanTitle = preg_replace('/\s*[•··]\s*.+$/u', '', $postTitle);
                if ($postYear) {
                    $cleanTitle = preg_replace('/\b' . $postYear . '\b/', '', $cleanTitle);
                }
                $cleanTitle = trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $cleanTitle));
                $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));
                $postWords = array_values(array_filter(explode(' ', strtolower($cleanTitle)), fn($w) => strlen($w) > 1));

                $matchedFiles = [];
                foreach ($postFiles as $pf) {
                    if (empty($pf['short_code'])) continue;
                    $fTitle = $pf['title'] ?? '';

                    // Exclude series episodes from movie streams
                    if (preg_match('/[sS]\d{1,2}\s*[eE]\d{1,2}|(?:season|episod|episode|ep\.)\s*\d+/i', $fTitle)) {
                        continue;
                    }

                    // Strict year match if both post and file specify a year
                    if ($postYear !== null && preg_match('/\b(19\d\d|20\d\d)\b/', $fTitle, $fym)) {
                        if ($fym[1] !== $postYear) {
                            continue;
                        }
                    }

                    // Strict title word match
                    $cleanFTitle = strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $fTitle));
                    $wordsMatch = true;
                    foreach ($postWords as $pw) {
                        if (!str_contains($cleanFTitle, $pw)) {
                            $wordsMatch = false;
                            break;
                        }
                    }
                    if (!$wordsMatch) {
                        continue;
                    }

                    // Exclude sequels / parts if the main movie title is not a sequel
                    if (!preg_match('/\b(part\s*\d+|part\s*[ivx]+|\d+)\b/i', $cleanTitle)) {
                        if (preg_match('/\b(part\s*\d+|part\s*[ivx]+)\b/i', $fTitle)) {
                            continue;
                        }
                    }

                    $matchedFiles[] = $pf;
                }

                // If matched files found, use them; otherwise fallback to postFiles
                $postFilesToUse = !empty($matchedFiles) ? $matchedFiles : $postFiles;
                foreach ($postFilesToUse as $pf) {
                    if (!empty($pf['short_code'])) {
                        $filesToStream[] = $pf;
                    }
                }
            } else {
                foreach ($postFiles as $pf) {
                    if (!empty($pf['short_code'])) {
                        $filesToStream[] = $pf;
                    }
                }
            }
        } elseif (preg_match('/^(tt\d{6,10})(?::(\d+):(\d+))?$/i', $itemId, $m)) {
            // External Stremio standard IMDb ID requested (e.g. tt1234567 or tt1234567:1:1 for series)
            $imdbId = $m[1] ?? '';
            $targetSeason = isset($m[2]) ? (int)$m[2] : null;
            $targetEpisode = isset($m[3]) ? (int)$m[3] : null;

            // Resolve title name from Cinemeta (standard Stremio metadata)
            $searchedTitle = '';
            $searchedYear = '';

            if ($imdbId !== '') {
                $cinemetaType = ($targetSeason !== null || $itemType === 'series') ? 'series' : 'movie';
                $cinemetaUrl = "https://v3-cinemeta.strem.io/meta/{$cinemetaType}/{$imdbId}.json";
                $cinemetaJson = fd_http_json($cinemetaUrl, [], 'GET', 5);
                if (!empty($cinemetaJson['meta']['name'])) {
                    $searchedTitle = (string) $cinemetaJson['meta']['name'];
                    $searchedYear = (string) ($cinemetaJson['meta']['year'] ?? '');
                }
            }

            // Query search index with resolved title
            $searchQuery = $searchedTitle !== '' ? $searchedTitle : $itemId;

            if ($searchQuery !== '') {
                if ($targetSeason !== null && $targetEpisode !== null) {
                    // For Series Episode: search title with Season/Episode tokens
                    $epQuery = sprintf('%s S%02dE%02d', $searchQuery, $targetSeason, $targetEpisode);
                    $sf = fd_fetch_stream_ajax('search_files', ['search' => $epQuery, 'limit' => 30]);
                    if (is_array($sf) && !empty($sf['files'])) {
                        foreach ($sf['files'] as $f) {
                            $filesToStream[] = $f;
                        }
                    }

                    // Fallback to searching without SxxExx if few files found
                    if (count($filesToStream) === 0) {
                        $epQueryAlt = sprintf('%s E%02d', $searchQuery, $targetEpisode);
                        $sfAlt = fd_fetch_stream_ajax('search_files', ['search' => $epQueryAlt, 'limit' => 30]);
                        if (is_array($sfAlt) && !empty($sfAlt['files'])) {
                            foreach ($sfAlt['files'] as $f) {
                                $filesToStream[] = $f;
                            }
                        }
                    }

                    // Also search post files
                    $sp = fd_fetch_stream_ajax('search', ['search' => $searchQuery, 'limit' => 5]);
                    if (is_array($sp)) {
                        foreach ($sp as $p) {
                            $pId = $p['id'] ?? 0;
                            if (!$pId) continue;
                            $pFilesRes = fd_fetch_stream_ajax('post_files', ['post_id' => $pId, 'limit' => 5000]);
                            $pFiles = (array) ($pFilesRes['files'] ?? []);
                            foreach ($pFiles as $pf) {
                                if (empty($pf['short_code'])) continue;
                                $fTitle = $pf['title'] ?? '';
                                $fCaption = $pf['caption'] ?? '';
                                $parsed = fd_classify_season_episode($fTitle, (int)($pf['season_num'] ?? 0), (int)($pf['episode_num'] ?? 0), $fCaption);
                                if (fd_file_matches_episode($parsed, $targetSeason, $targetEpisode)) {
                                    $filesToStream[] = $pf;
                                }
                            }
                        }
                    }

                    // Fallback: If no files found yet, fetch direct search_files with broad search query and classify
                    if (count($filesToStream) === 0) {
                        for ($sfOffset = 0; $sfOffset < 500; $sfOffset += 100) {
                            $sfBroad = fd_fetch_stream_ajax('search_files', ['search' => $searchQuery, 'limit' => 100, 'offset' => $sfOffset]);
                            $broadFiles = (array) ($sfBroad['files'] ?? []);
                            if (empty($broadFiles)) break;
                            foreach ($broadFiles as $bf) {
                                if (empty($bf['short_code'])) continue;
                                $bfTitle = $bf['title'] ?? '';
                                $parsed = fd_classify_season_episode($bfTitle, (int)($bf['season_num'] ?? 0), (int)($bf['episode_num'] ?? 0));
                                if ($parsed['season'] === $targetSeason && $parsed['episode'] === $targetEpisode) {
                                    $filesToStream[] = $bf;
                                }
                            }
                            if (count($filesToStream) > 0 || count($broadFiles) < 100) break;
                        }
                    }
                } else {
                    // For Movie: search direct files and posts (try with year first, fallback to title only)
                    $queriesToTry = [];
                    if ($searchedTitle !== '' && $searchedYear !== '') {
                        $queriesToTry[] = "{$searchedTitle} {$searchedYear}";
                    }
                    $queriesToTry[] = $searchQuery;

                    foreach ($queriesToTry as $mQuery) {
                        $sf = fd_fetch_stream_ajax('search_files', ['search' => $mQuery, 'limit' => 30]);
                        if (is_array($sf) && !empty($sf['files'])) {
                            foreach ($sf['files'] as $f) {
                                $filesToStream[] = $f;
                            }
                        }

                        $sp = fd_fetch_stream_ajax('search', ['search' => $mQuery, 'limit' => 5]);
                        if (is_array($sp)) {
                            foreach ($sp as $p) {
                                $pId = $p['id'] ?? 0;
                                if (!$pId) continue;
                                $pFilesRes = fd_fetch_stream_ajax('post_files', ['post_id' => $pId, 'limit' => 20]);
                                $pFiles = (array) ($pFilesRes['files'] ?? []);
                                foreach ($pFiles as $pf) {
                                    if (!empty($pf['short_code'])) {
                                        $filesToStream[] = $pf;
                                    }
                                }
                            }
                        }

                        // If files found on first query with year, stop trying fallback
                        if (count($filesToStream) > 0) {
                            break;
                        }
                    }
                }
            }
        }

        // Limit max stream items per request (e.g. 100 files max so all quality/sub releases are visible)
        $maxStreamFiles = 100;
        $filesToStream = array_slice($filesToStream, 0, $maxStreamFiles);

        foreach ($filesToStream as $fItem) {
            $fCode = $fItem['short_code'] ?? '';
            if ($fCode === '') continue;

            $fTitle = $fItem['title'] ?? '';
            $fCaption = $fItem['caption'] ?? '';
            $fSize = (int) ($fItem['file_size'] ?? 0);
            $fMime = $fItem['mime'] ?? 'video/mp4';

            // If title is generic video filename, use caption title for display
            if ($fCaption !== '' && preg_match('/^(?:video(?:\.\d+)*|\d+|document|file)\.(?:mp4|mkv|avi|mov|ts|flv)$/i', trim($fTitle))) {
                $firstCap = trim(explode("\n", $fCaption)[0]);
                if ($firstCap !== '') {
                    $fTitle = $firstCap;
                }
            }

            // Extract resolution / quality / release / codec tags from filename
            $qualityTag = '';
            $icon = '🎬';
            if (preg_match('/\b(2160p|4[kK]|uhd)\b/i', $fTitle)) {
                $qualityTag = '4K UHD';
                $icon = '⭐';
            } elseif (preg_match('/\b(1080p|fhd)\b/i', $fTitle)) {
                $qualityTag = '1080p';
                $icon = preg_match('/\b(bluray|blu-ray|bdrip|remux)\b/i', $fTitle) ? '💿' : (preg_match('/\b(hdr|dolby|dv)\b/i', $fTitle) ? '🌈' : '📺');
            } elseif (preg_match('/\b(720p|hd)\b/i', $fTitle)) {
                $qualityTag = '720p';
                $icon = '📺';
            } elseif (preg_match('/\b(480p|360p|sd)\b/i', $fTitle)) {
                $qualityTag = 'SD';
                $icon = '📱';
            }

            // Detect source type (BluRay, WEB-DL, HDR, etc.)
            $metaPills = [];
            if (preg_match('/\b(bluray|blu-ray|remux)\b/i', $fTitle, $rm)) {
                $metaPills[] = '💿 BluRay';
            } elseif (preg_match('/\b(web-?dl|webrip)\b/i', $fTitle)) {
                $metaPills[] = '🌐 WEB-DL';
            } elseif (preg_match('/\b(hdtv|tvrip)\b/i', $fTitle)) {
                $metaPills[] = '📡 HDTV';
            }
            if (preg_match('/\b(hdr10\+|hdr10|hdr|dolby\s*vision|dovi|dv)\b/i', $fTitle)) {
                $metaPills[] = '🌈 HDR';
            }
            if (preg_match('/\b(hevc|x265|h265)\b/i', $fTitle)) {
                $metaPills[] = '⚡ HEVC';
            } elseif (preg_match('/\b(avc|x264|h264)\b/i', $fTitle)) {
                $metaPills[] = 'AVC';
            }
            if (preg_match('/\b(aac|ac3|eac3|dts|dolby|atmos|5\.1|7\.1)\b/i', $fTitle, $am)) {
                $metaPills[] = '🔊 ' . strtoupper($am[1]);
            }

            $streamName = $icon . ' ' . ($qualityTag !== '' ? $qualityTag : 'Direct') . "\nPencariMovie";

            $cleanFTitle = fd_clean_media_title($fTitle);
            $fileName = $cleanFTitle !== '' ? $cleanFTitle : ($fTitle !== '' ? $fTitle : ($fCode . '.mp4'));
            $payload = [
                'short_code' => $fCode,
                'bot_id' => $botIdStr,
                'file_size' => $fSize,
                'file_name' => $fileName,
                'mime' => $fMime,
            ];

            $d = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
            $localStreamUrl = $baseUrl . '/api/download?d=' . $d;
            $displayName = $cleanFTitle !== '' ? $cleanFTitle : ($fTitle !== '' ? $fTitle : ('File ShortCode: ' . $fCode));

            $pillsLine = !empty($metaPills) ? implode('  •  ', $metaPills) : '';
            $streamDesc = ($pillsLine !== '' ? $pillsLine . "\n" : '') . '📄 ' . $displayName . "\n⚡ Direct Stream · Telegram Cloud";

            $behaviorHints = [
                'notWebReady' => false,
                'filename' => $fileName,
                'headers' => [
                    'User-Agent' => 'VLC/3.0.20 LibVLC/3.0.20',
                ],
            ];
            if ($fSize > 0) {
                $behaviorHints['videoSize'] = $fSize;
            }
            if ($itemType === 'series') {
                $groupTokens = ['pencarimovie'];
                $fullText = strtolower($fTitle . ' ' . $fCaption);

                // 1. Release source / group / encoder
                $groups = [
                    'myfilm4u',
                    'dramaost',
                    'nodrakor',
                    'nodrafilm',
                    'mkvdrama',
                    'ydf',
                    'fanszz',
                    'cdl',
                    'mkvking',
                    'dramadaily',
                    'kdg',
                    'pahe',
                    'psa',
                    'galaxyrg',
                    'ember',
                    'megusta',
                    'ion10',
                    'flux',
                    'ntb',
                    'syncopy',
                    'yts',
                    'yify',
                    'tgx',
                    'bone',
                    'playweb',
                    'nby',
                    'naz',
                    'kaki',
                    'dramaviral',
                    'dfm',
                    'kt',
                    'tvalhijrah',
                    'melia',
                    'mk',
                    'flx',
                    'mkvcinemas'
                ];
                $matchedGroups = [];
                foreach ($groups as $g) {
                    if (preg_match('/(?:^|[._\-\s\[\(])' . preg_quote($g, '/') . '(?:[._\-\s\]\)]|$)/i', $fTitle . ' ' . $fCaption)) {
                        $matchedGroups[] = $g;
                    }
                }
                if (!empty($matchedGroups)) {
                    $groupTokens[] = implode('.', $matchedGroups);
                }

                // 2. Language / subtitle flavor
                if (preg_match('/\b(malaysub|malay\.?sub|sub\.?malay)\b/i', $fullText)) {
                    $groupTokens[] = 'malaysub';
                } elseif (preg_match('/\b(indosub|indo\.?sub|sub\.?indo|indonesian)\b/i', $fullText)) {
                    $groupTokens[] = 'indosub';
                } elseif (preg_match('/\b(engsub|eng\.?sub|sub\.?eng|english)\b/i', $fullText)) {
                    $groupTokens[] = 'engsub';
                } elseif (preg_match('/\b(chinsub|sub\.?chin|chinese)\b/i', $fullText)) {
                    $groupTokens[] = 'chinsub';
                } elseif (preg_match('/\b(multisub|multi\.?sub)\b/i', $fullText)) {
                    $groupTokens[] = 'multisub';
                } elseif (preg_match('/\b(hardsub)\b/i', $fullText)) {
                    $groupTokens[] = 'hardsub';
                } elseif (preg_match('/\b(softsub)\b/i', $fullText)) {
                    $groupTokens[] = 'softsub';
                } elseif (preg_match('/\b(raw)\b/i', $fullText)) {
                    $groupTokens[] = 'raw';
                }

                // 3. Source / Medium
                if (preg_match('/\b(bluray|blu-ray|bdrip|remux)\b/i', $fullText)) {
                    $groupTokens[] = 'bluray';
                } elseif (preg_match('/\b(web-?dl|webrip)\b/i', $fullText)) {
                    $groupTokens[] = 'webdl';
                } elseif (preg_match('/\b(hdtv|tvrip|pdtv)\b/i', $fullText)) {
                    $groupTokens[] = 'hdtv';
                }

                // 4. Resolution / quality
                if ($qualityTag !== '') {
                    $groupTokens[] = strtolower(str_replace(' ', '-', $qualityTag));
                }

                // 5. Codec
                if (preg_match('/\b(hevc|x265|h265)\b/i', $fullText)) {
                    $groupTokens[] = 'x265';
                } elseif (preg_match('/\b(avc|x264|h264)\b/i', $fullText)) {
                    $groupTokens[] = 'x264';
                }

                // 6. Clean title signature fallback to group consistent title releases together
                $sig = preg_replace('/\.(mp4|mkv|avi|ts|flv)$/i', '', $cleanFTitle);
                $sig = preg_replace('/\b(?:19\d\d|20\d\d)\b/', '', $sig);
                $sig = preg_replace('/(?:^|[^a-z0-9])(?:S\d{1,2})?[ ._-]*(?:EP|EPS|EPISODE|EPISOD|E|PART|VOL|BAHAGIAN)[ ._-]*\d{1,4}(?:[^a-z0-9]|$)/i', ' ', $sig);
                $sig = preg_replace('/\b(akhir|final|end)\b/i', '', $sig);
                $sig = preg_replace('/[^a-z0-9]+/i', '-', trim($sig));
                $sig = strtolower(trim($sig, '-'));
                if ($sig !== '') {
                    $groupTokens[] = substr($sig, 0, 30);
                }

                $behaviorHints['bingeGroup'] = implode('-', array_unique($groupTokens));
            }

            $streams[] = [
                'name' => $streamName,
                'title' => $streamDesc,
                'description' => $streamDesc,
                'url' => $localStreamUrl,
                'behaviorHints' => $behaviorHints,
            ];
        }

        // Add sponsored stream link with externalUrl (opens link in browser when tapped in Stremio)
        if (!empty($streams)) {
            $streams[] = [
                'name' => '⭐ Updates',
                'title' => "📢 Join Telegram / Support PencariMovie\n👉 Tap to open official channel & bot updates",
                'description' => "📢 Join Telegram / Support PencariMovie\n👉 Tap to open official channel & bot updates",
                'externalUrl' => 'https://t.me/+du3kFFBH3rUyYjE1',
            ];
        }

        fd_stremio_json(['streams' => $streams]);
    }

    fd_stremio_json(['ok' => 0, 'message' => 'Unknown Nuvio addon route'], 404);
}


if (str_starts_with($path, '/api/')) {
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Secret');
    }

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    // Allow /api/download from any client/player (e.g. Stremio player, external video players)
    if ($path !== '/api/download') {
        fd_require_local_request();
    }
    fd_require_fileinfo();

    // Pre-load Composer autoloader so amphp classes (HttpClient, etc.) are
    // available for all API routes. fd_ensure_autoload() handles the output
    // buffering needed to suppress the polyfill.php echo warning on Windows.
    fd_ensure_autoload();

    // ── GET /api/version — check version against WordPress minimum ──────────
    if ($path === '/api/version') {
        fd_json(fd_check_version());
    }

    // ── Version gate — block all other endpoints if update is required ──────
    $v0 = microtime(true);
    $versionCheck = fd_check_version();
    $v1 = microtime(true);
    fd_log('version check timing', ['ms' => round(($v1 - $v0) * 1000), 'path' => $path]);
    if (!empty($versionCheck['update_needed'])) {
        $minVersion = $versionCheck['minimum_version'] ?? '';
        $currentVersion = $versionCheck['current_version'] ?? FD_APP_VERSION;
        $updateUrl = $versionCheck['update_url'] ?? '';

        fd_log('version gate blocked request', [
            'current' => $currentVersion,
            'minimum' => $minVersion,
            'path' => $path,
        ]);

        fd_json([
            'ok' => 0,
            'message' => 'Update Required. Your version (' . $currentVersion . ') is below the minimum required version (' . $minVersion . ').',
            'update_needed' => true,
            'current_version' => $currentVersion,
            'minimum_version' => $minVersion,
            'update_url' => $updateUrl,
        ], 426);
    }

    // ── GET /api/resolve-shortcode — proxy short_code resolution through
    //     the local backend so the API secret (X-API-Secret header) is
    //     automatically sent to WordPress. The frontend should call this
    //     instead of hitting WordPress directly. ────────────────────────────
    if ($path === '/api/resolve-shortcode' && $method === 'GET') {
        $shortCode = trim((string) ($_GET['short_code'] ?? ''));
        $botId = trim((string) ($_GET['bot_id'] ?? ''));
        if ($shortCode === '') {
            fd_json(['ok' => 0, 'message' => 'short_code is required.'], 400);
        }
        $result = fd_resolve_shortcode($shortCode, $botId);
        // Propagate auth failures as 403 so the frontend can detect and
        // trigger auto-logout / re-login flow.
        if (empty($result['ok'])) {
            fd_json($result, 403);
        }
        fd_json($result);
    }

    // ── GET /api/session — check if MadelineProto session is valid ──────────
    if ($path === '/api/session') {
        [$madeline, $error] = fd_boot_madeline();

        if (!$madeline) {
            fd_json([
                'ok' => 1,
                'has_session' => false,
                'message' => $error,
            ]);
        }

        try {
            $self = $madeline->getSelf();
            fd_json([
                'ok' => 1,
                'has_session' => true,
                'bot_id' => (string) ($self['id'] ?? ''),
                'bot_username' => (string) ($self['username'] ?? ''),
                'bot_name' => (string) ($self['first_name'] ?? ''),
                'api_secret' => fd_get_api_secret(),
            ]);
        } catch (Throwable $throwable) {
            fd_json([
                'ok' => 1,
                'has_session' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    // ── POST /api/botlogin — one-time bot token login ───────────────────────
    if ($path === '/api/botlogin' && $method === 'POST') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            fd_json(['ok' => 0, 'message' => 'Invalid JSON body'], 400);
        }

        $botToken = trim((string) ($input['bot_token'] ?? ''));
        if ($botToken === '') {
            fd_json(['ok' => 0, 'message' => 'bot_token is required.'], 400);
        }

        fd_log('botlogin start', ['token_prefix' => substr($botToken, 0, 8) . '...']);

        // Logging in with a new token — clear any existing session so
        // fd_boot_madeline() cannot resume the old one and must call
        // botLogin() with the newly provided token.
        fd_clear_session();

        // ═══ [DIAGNOSTIC] Capture any stray output before fd_boot_madeline ═══
        $diagObLevel = ob_get_level();
        // If there's already output in the buffer from fd_ensure_autoload or
        // other early code, log its length so we can detect polyfill.php leaks.
        $preBootOutput = '';
        while (ob_get_level() > 0) {
            $preBootOutput .= ob_get_clean();
        }
        // Restore buffering to how it was — fd_boot_madeline will start its own.
        while (ob_get_level() < $diagObLevel) {
            ob_start();
        }
        if ($preBootOutput !== '') {
            fd_log('⚠️  stray output detected BEFORE fd_boot_madeline', [
                'length' => strlen($preBootOutput),
                'preview' => substr($preBootOutput, 0, 500),
            ]);
        }

        // API credentials are resolved internally by fd_boot_madeline():
        //   1. From local cache (storage/api_credentials.json)
        //   2. By fetching from WordPress (encrypted with bot token)
        // Emergency overrides can still be passed via the $overrides parameter
        // but the frontend no longer sends them.
        $tBoot0 = microtime(true);
        [$madeline, $error] = fd_boot_madeline($botToken);
        $tBoot1 = microtime(true);
        fd_log('fd_boot_madeline timing', ['ms' => round(($tBoot1 - $tBoot0) * 1000)]);

        if (!$madeline) {
            fd_log('botlogin failed', ['error' => $error]);
            fd_json(['ok' => 0, 'message' => $error ?: 'Login failed.'], 401);
        }

        // ═══ [DIAGNOSTIC] Check for stray output after fd_boot_madeline ═══
        $postBootOutput = '';
        if (ob_get_level() > $diagObLevel) {
            while (ob_get_level() > $diagObLevel) {
                $postBootOutput .= ob_get_clean();
            }
            if ($postBootOutput !== '') {
                fd_log('⚠️  stray output detected AFTER fd_boot_madeline', [
                    'length' => strlen($postBootOutput),
                    'preview' => substr($postBootOutput, 0, 500),
                ]);
            }
        }

        try {
            $self = $madeline->getSelf();
            $botId = (string) ($self['id'] ?? '');
            if ($botId === '') {
                fd_json(['ok' => 0, 'message' => 'Login succeeded but bot ID is empty.'], 500);
            }

            // ═══ [DIAGNOSTIC] Check output buffer state before fd_json ═══
            $bufBeforeJson = '';
            while (ob_get_level() > 0) {
                $bufBeforeJson .= ob_get_clean();
            }
            if ($bufBeforeJson !== '') {
                fd_log('⚠️  stray output before botlogin success fd_json', [
                    'length' => strlen($bufBeforeJson),
                    'preview' => substr($bufBeforeJson, 0, 500),
                ]);
            }
            // Restore output buffering so fd_json can set headers
            ob_start();

            fd_log('botlogin successful', ['bot_id' => $botId, 'bot_username' => $self['username'] ?? '']);
            fd_json([
                'ok' => 1,
                'bot_id' => $botId,
                'bot_username' => (string) ($self['username'] ?? ''),
                'bot_name' => (string) ($self['first_name'] ?? ''),
                'api_secret' => fd_get_api_secret(),
            ]);
        } catch (Throwable $throwable) {
            fd_log('botlogin getSelf failed', ['error' => $throwable->getMessage()]);
            fd_json(['ok' => 0, 'message' => 'Session validation failed: ' . $throwable->getMessage()], 500);
        }
    }

    // ── POST /api/botlogout — terminate session via Telegram API then clean up ─
    if ($path === '/api/botlogout' && $method === 'POST') {
        // Attempt to boot MadelineProto from the existing session and call
        // logout() which sends auth.logOut to Telegram, properly invalidating
        // the authorization key on Telegram's servers (not just locally).
        try {
            [$madeline, $error] = fd_boot_madeline();
            if ($madeline) {
                $madeline->logout();
                fd_log('madeline logout() completed');
            } else {
                fd_log('no session to logout from', ['error' => $error]);
            }
        } catch (Throwable $throwable) {
            // Don't block cleanup if logout() itself throws
            fd_log('madeline logout() threw', ['error' => $throwable->getMessage()]);
        }

        // Destroy the MadelineProto instance BEFORE removing session files.
        // This triggers the destructor which stops IPC server, releases file
        // handles, and properly closes the session. If we delete files while
        // the destructor is still pending, MadelineProto's shutdown sequence
        // will fail with "No such file or directory" on IPC socket cleanup.
        unset($madeline);

        // Always clean up local files regardless of whether logout() succeeded
        fd_clear_session();
        fd_json(['ok' => 1, 'message' => 'Session cleared.']);
    }


    // ── GET /api/proxy-stream — proxy streaming data from WordPress AJAX ────
    if ($path === '/api/proxy-stream' && $method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? ''));
        if ($action === '') {
            fd_json(['ok' => 0, 'message' => 'action parameter is required.'], 400);
        }

        // Map short action names to stream_* WordPress AJAX actions
        // e.g., "trending" → "stream_trending", "search_files" → "stream_search_files"
        $streamAction = 'stream_' . $action;

        // Build the WordPress admin-ajax.php URL, forwarding all GET params
        // with the action replaced by the stream_* prefixed version
        $wpUrl = defined('FD_WP_AJAX_URL') ? FD_WP_AJAX_URL : 'https://pencarimovie.com/wp-admin/admin-ajax.php';
        $queryParams = $_GET;
        $queryParams['action'] = $streamAction;
        if (empty($queryParams['bot_id'])) {
            $activeBotId = fd_get_bot_id();
            if ($activeBotId !== '') {
                $queryParams['bot_id'] = $activeBotId;
            }
        }
        $wpUrl .= '?' . http_build_query($queryParams);

        try {
            $body = fd_http_get_contents($wpUrl, [
                'method' => 'GET',
                'headers' => ['X-Requested-With: XMLHttpRequest'],
                'timeout' => 15,
            ]);
            if ($body === false) {
                throw new \RuntimeException('fd_http_get_contents failed');
            }
        } catch (\Throwable $e) {
            fd_log('proxy-stream failed', ['action' => $streamAction, 'error' => $e->getMessage()]);
            fd_json(['ok' => 0, 'message' => 'Failed to fetch data from WordPress.'], 502);
        }

        // Try to decode as JSON to return proper Content-Type
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            fd_json($decoded);
        }

        // If not JSON, return raw with correct content type
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        return true;
    }

    // ── GET /api/download — stream Telegram file via MadelineProto ──────────
    if ($path === '/api/download') {
        $encoded = trim((string) ($_GET['d'] ?? $_POST['d'] ?? ''));
        $decodedPayload = $encoded !== '' ? fd_decode_download_payload($encoded) : [];
        $fileId = trim((string) ($decodedPayload['file_id'] ?? ($_GET['file_id'] ?? $_POST['file_id'] ?? '')));
        $shortCode = trim((string) ($decodedPayload['short_code'] ?? ($_GET['short_code'] ?? $_POST['short_code'] ?? $_GET['sc'] ?? '')));
        if ($shortCode === '' && $fileId === '' && $encoded !== '' && empty($decodedPayload)) {
            $shortCode = $encoded;
        }
        $fileSize = (int) ($decodedPayload['file_size'] ?? ($_GET['file_size'] ?? $_POST['file_size'] ?? $_GET['size'] ?? $_POST['size'] ?? 0));
        $fileName = trim((string) ($decodedPayload['file_name'] ?? ($_GET['file_name'] ?? $_POST['file_name'] ?? $_GET['name'] ?? $_POST['name'] ?? '')));
        $fileMime = trim((string) ($decodedPayload['mime'] ?? ($_GET['mime'] ?? $_POST['mime'] ?? $_GET['mime_type'] ?? $_POST['mime_type'] ?? '')));
        $botId = trim((string) ($decodedPayload['bot_id'] ?? ($_GET['bot_id'] ?? $_POST['bot_id'] ?? '')));

        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        fd_log('download request received', [
            'file_id_present' => $fileId !== '',
            'short_code_present' => $shortCode !== '',
            'encoded_present' => $encoded !== '',
            'bot_id_present' => $botId !== '',
            'file_size' => $fileSize,
            'file_name' => $fileName,
            'mime' => $fileMime,
            'ob_level' => ob_get_level(),
        ]);

        // Fast-fail if Telegram Bot is not connected or disconnected
        $activeBotId = fd_get_bot_id();
        if ($activeBotId === '' || (!is_file(FD_SESSION_PATH) && !is_dir(FD_SESSION_PATH))) {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: close');
            fd_json([
                'ok' => 0,
                'message' => 'Telegram Bot is not connected. Please connect your bot token in dashboard settings to stream.',
                'hint' => 'Open dashboard settings and connect your bot token.',
                'short_code' => $shortCode,
                'bot_id' => $botId,
            ], 403);
        }

        if ($fileId === '') {
            if ($shortCode !== '') {
                if ($botId === '') {
                    $botId = $activeBotId;
                }
                $resolved = fd_resolve_shortcode($shortCode, $botId);
                $fileId = trim((string) ($resolved['file_id_mt'] ?? $resolved['file_id'] ?? ''));
                $fileSize = (int) ($resolved['file_size'] ?? $fileSize);
                $fileName = trim((string) ($resolved['title'] ?? $resolved['file_name'] ?? $fileName));
                $fileMime = trim((string) ($resolved['mime'] ?? $resolved['file_type'] ?? $fileMime));

                if ($fileId === '') {
                    $errMsg = !empty($resolved['message'])
                        ? (string) $resolved['message']
                        : (!empty($resolved['description']) ? (string) $resolved['description'] : 'File source is unpopulated, expired, or missing.');

                    $statusCode = 404;
                    if (stripos($errMsg, 'not allowed') !== false || stripos($errMsg, 'unauthorized') !== false || stripos($errMsg, 'forbidden') !== false) {
                        $statusCode = 403;
                    }

                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('Connection: close');
                    fd_json([
                        'ok' => 0,
                        'message' => $errMsg,
                        'short_code' => $shortCode,
                        'bot_id' => $botId,
                    ], $statusCode);
                }
            }
        }

        if ($fileId === '') {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: close');
            fd_json([
                'ok' => 0,
                'message' => 'file_id or valid short_code is required for local browser download.',
                'hint' => 'Pass a Bot API file id or shortcode to resolve the file.',
            ], 400);
        }

        if ($fileSize <= 0 || $fileName === '' || $fileMime === '') {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: close');
            fd_json([
                'ok' => 0,
                'message' => 'For Bot API file_id download, file_size, file_name, and mime are required.',
                'hint' => 'Include file_size, file_name, and mime from your WordPress metadata response.',
            ], 400);
        }

        // fd_boot_madeline() handles its own output buffering internally.
        [$madeline, $error] = fd_boot_madeline();

        if (!$madeline) {
            fd_log('download failed — no valid session', [
                'error' => $error,
            ]);
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: close');
            fd_json([
                'ok' => 0,
                'message' => $error ?: 'No valid MadelineProto session.',
                'hint' => 'Call /api/botlogin first to authenticate.',
            ], 403);
        }

        if (!method_exists($madeline, 'downloadToBrowser')) {
            fd_log('downloadToBrowser unavailable on madeline instance');
            fd_json([
                'ok' => 0,
                'message' => 'MadelineProto downloadToBrowser() is not available.',
            ], 501);
        }

        try {
            fd_log('starting downloadToBrowser', [
                'file_id' => $fileId,
                'file_size' => $fileSize,
                'file_name' => $fileName,
                'mime' => $fileMime,
            ]);
            $madeline->downloadToBrowser($fileId, null, $fileSize, $fileName, $fileMime);
        } catch (Throwable $throwable) {
            fd_log('downloadToBrowser failed', [
                'error' => $throwable->getMessage(),
            ]);
            fd_json([
                'ok' => 0,
                'message' => 'File download failed.',
            ], 500);
        }

        return true;
    }

    fd_json(['ok' => 0, 'message' => 'Unknown API endpoint'], 404);
}

// ─── Static file serving ────────────────────────────────────────────────────

$publicDir = __DIR__ . '/public';
$file = $path === '/' ? '/index.html' : $path;
$full = realpath($publicDir . $file);

if ($full && str_starts_with($full, realpath($publicDir)) && is_file($full)) {
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml; charset=utf-8',
        'ico' => 'image/x-icon',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: no-store');
    readfile($full);
    return true;
}

http_response_code(404);
echo 'Not found';

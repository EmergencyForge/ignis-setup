<?php

/**
 * ignis Setup Script
 *
 * Lädt das Release-ZIP (oder cloned einen Branch), führt `composer install`
 * aus, schreibt die .env, lässt Phinx die DB-Migrations laufen und löscht
 * sich anschließend selbst. Der komplette Flow läuft in einem einzigen
 * POST-Request, damit die Session-Cookies konsistent bleiben — das UI
 * zeigt während der Wartezeit eine mehrstufige Progress-Animation.
 */

// ── Debug / Error-Display ────────────────────────────────────────────
// Produktion: Errors nur loggen, nicht an den Browser ausgeben (sonst
// leaken Pfade/DB-Credentials ins HTML). Mit `?debug` sichtbar machen.
if (isset($_GET['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Setup-Session lebt 4 h: deckt längere Recherche-Pausen zwischen den
// Wizard-Schritten ab, ohne nach Abschluss noch lange offen zu sein.
// `cookie_lifetime` wirkt clientseitig garantiert; `gc_maxlifetime` per
// `ini_set` ist auf Shared-Hosting nicht immer respektiert — der
// Token-Refresh weiter unten dient als Sicherheitsnetz für diesen Fall.
$setupSessionLifetime = 4 * 60 * 60;
ini_set('session.gc_maxlifetime', (string) $setupSessionLifetime);
ini_set('session.cookie_lifetime', (string) $setupSessionLifetime);
session_start();

// ── Exception Handler — styled error page, secrets sanitized ────────
set_exception_handler(function (\Throwable $e) {
    $isDebug = isset($_GET['debug']);
    $message = $e->getMessage();

    // Sanitize secrets from message and trace
    $secrets = ['DB_PASS', 'DISCORD_CLIENT_SECRET', 'APP_KEY'];
    foreach ($secrets as $key) {
        $val = $_ENV[$key] ?? $_POST[$key] ?? null;
        if ($val && is_string($val) && strlen($val) > 2) {
            $message = str_replace($val, '[REDACTED]', $message);
        }
    }

    // AJAX request? Return JSON error
    if (!empty($_SERVER['HTTP_X_SETUP_AJAX'])) {
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'errors' => ['Interner Fehler: ' . ($isDebug ? $message : 'Bitte mit ?debug erneut versuchen.')],
        ]);
        exit;
    }

    http_response_code(500);

    $phpVer = htmlspecialchars(phpversion());
    $reqUri = htmlspecialchars(($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
    $referrer = htmlspecialchars($_SERVER['HTTP_REFERER'] ?? '—');
    $memory = round(memory_get_peak_usage() / 1024 / 1024, 1) . ' MiB';

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Setup-Fehler</title><style>';
    echo '*{margin:0;padding:0;box-sizing:border-box}';
    echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;background:#f5f5f5;color:#333;min-height:100vh}';
    echo '@media(prefers-color-scheme:dark){body{background:#0a0a0f;color:#e4e4ed}.header{background:#b91c1c}.content{color:#e4e4ed}.section{border-color:#2a2a3a}.label{color:#ff6b2c}.trace{background:#16161e;color:#8b8ba0;border-color:#2a2a3a}}';
    echo '.header{background:#c80000;color:#fff;padding:16px 24px;font-size:1.1em;font-weight:600}';
    echo '.content{max-width:100%;padding:24px}';
    echo '.section{border-bottom:1px solid #e2e2ea;padding-bottom:16px;margin-bottom:24px}';
    echo '.section h3{font-size:1.1em;font-weight:300;color:inherit;margin-bottom:12px}';
    echo '.info-grid{display:grid;grid-template-columns:160px 1fr;gap:8px 16px;font-size:0.9em}';
    echo '.label{color:#c80000;font-size:0.82em;font-weight:600}';
    echo '.value{word-break:break-all}';
    echo '.trace{background:#f8f8f8;border:1px solid #e2e2ea;border-radius:4px;padding:12px;font-family:monospace;font-size:0.8em;overflow:auto;max-height:300px;white-space:pre-wrap;margin-top:8px}';
    echo '</style></head><body>';
    echo '<div class="header">Ein Fehler ist aufgetreten</div>';
    echo '<div class="content">';

    // System Information
    echo '<div class="section"><h3>System Information</h3>';
    echo '<div class="info-grid">';
    echo '<span class="label">PHP Version</span><span class="value">' . $phpVer . '</span>';
    echo '<span class="label">Request URI</span><span class="value">' . $reqUri . '</span>';
    echo '<span class="label">Peak Memory</span><span class="value">' . $memory . '</span>';
    echo '<span class="label">Referrer</span><span class="value">' . $referrer . '</span>';
    echo '</div></div>';

    // Error details
    echo '<div class="section"><h3>Error</h3>';
    echo '<div class="info-grid">';
    echo '<span class="label">Error Type</span><span class="value">' . htmlspecialchars(get_class($e)) . '</span>';
    echo '<span class="label">Error Message</span><span class="value">' . htmlspecialchars($message) . '</span>';
    if ($isDebug) {
        echo '<span class="label">File</span><span class="value">' . htmlspecialchars($e->getFile()) . ' (' . $e->getLine() . ')</span>';
    }
    echo '</div>';
    if ($isDebug) {
        echo '<div class="trace">' . htmlspecialchars($e->getTraceAsString()) . '</div>';
    } else {
        echo '<p style="margin-top:12px;font-size:0.9em;opacity:0.7;">Fügen Sie <code style="background:rgba(128,128,128,0.15);padding:2px 6px;border-radius:3px;">?debug</code> an die URL an für den vollständigen Stack Trace.</p>';
    }
    echo '</div>';

    echo '</div></body></html>';
    exit;
});

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});


$devMode = isset($_GET['dev']);

// ── CSRF-Token für das Setup ────────────────────────────────────────
// Das Setup steht temporär öffentlich im Web — ohne Token könnte ein
// fremder Request einen halbfertigen Install auslösen. Token wird per
// Session gebunden und in allen POSTs erwartet.
if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['setup_csrf'];

function verifyCsrf(): bool
{
    $sent = $_POST['_token'] ?? $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '';
    return is_string($sent) && hash_equals($_SESSION['setup_csrf'] ?? '', $sent);
}

/**
 * Erzeugt einen neuen Session-CSRF-Token und gibt ihn zurück. Wird nach
 * einem CSRF-Miss in den AJAX-Endpoints aufgerufen, damit der neue
 * Wert in der Failure-Response mitgereicht und vom Client transparent
 * übernommen werden kann (siehe `postWithCsrfRetry` im JS).
 */
function freshCsrfToken(): string
{
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['setup_csrf'];
}

// ── Basis-Systemchecks ───────────────────────────────────────────────

$phpVersion = phpversion();
// Hinweis: Bump von 8.1 auf 8.2. Runtime nutzt readonly properties,
// first-class-callable-Syntax und Features aus respect/validation ^2.3
// die 8.1 nicht sauber unterstützen. CI-Baseline liegt auf 8.4.
$requiredPhpVersion = '8.2.0';
$phpVersionOk = version_compare($phpVersion, $requiredPhpVersion, '>=');

$execAvailable = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

$gitAvailable = false;
if ($execAvailable) {
    $gitOutput = [];
    $gitReturnVar = 0;
    @exec('git --version 2>&1', $gitOutput, $gitReturnVar);
    $gitAvailable = ($gitReturnVar === 0);
}

// PHP-Extension-Check. Jede Extension hat eine Begründung warum intraRP
// sie braucht — der Screen zeigt das als Tooltip, damit der User weiß
// *warum* `intl` oder `gd` fehlt.
$requiredExtensions = [
    'pdo'       => 'Datenbank-Verbindung (PDO-Basis)',
    'pdo_mysql' => 'MySQL-Treiber',
    'mbstring'  => 'String-Handling (Twig, OAuth2)',
    'openssl'   => 'HTTPS, OAuth2-Tokens',
    'fileinfo'  => 'Upload-Validierung (MIME-Type)',
    'dom'       => 'XML/HTML-Parser (Twig, dompdf)',
    'xml'       => 'XML-Parser',
    'json'      => 'JSON-Serialisierung',
    'session'   => 'Benutzer-Sessions',
    'intl'      => 'Internationalisierung (Validation, Formatierung)',
    'zip'       => 'Release-ZIP entpacken',
    'curl'      => 'HTTPS-Downloads (GitHub, Discord)',
    'filter'    => 'Input-Validierung',
    'ctype'     => 'String-Validierung',
];
$extensionStatus = [];
$missingRequired = [];
foreach ($requiredExtensions as $ext => $purpose) {
    $loaded = extension_loaded($ext);
    $extensionStatus[$ext] = ['ok' => $loaded, 'purpose' => $purpose];
    if (!$loaded) {
        $missingRequired[] = $ext;
    }
}

// GD ODER Imagick — PDF-Rendering braucht eins von beiden
$hasGd = extension_loaded('gd');
$hasImagick = extension_loaded('imagick');
$extensionStatus['gd/imagick'] = [
    'ok'      => $hasGd || $hasImagick,
    'purpose' => 'PDF-Generierung (dompdf) — ' . ($hasGd ? 'gd aktiv' : ($hasImagick ? 'imagick aktiv' : 'FEHLT')),
];
if (!($hasGd || $hasImagick)) {
    $missingRequired[] = 'gd/imagick';
}

$allExtensionsOk = empty($missingRequired);

function parsePhpSize($size)
{
    $size = trim((string) $size);
    $unit = strtolower(substr($size, -1));
    $value = (int) $size;
    return match ($unit) {
        'g' => $value * 1024,
        'm' => $value,
        'k' => $value / 1024,
        default => $value / (1024 * 1024),
    };
}

$phpLimits = [
    'memory_limit'        => ['current' => ini_get('memory_limit'),        'recommended' => 512, 'unit' => 'M'],
    'max_execution_time'  => ['current' => ini_get('max_execution_time'),  'recommended' => 300, 'unit' => 's'],
    'upload_max_filesize' => ['current' => ini_get('upload_max_filesize'), 'recommended' => 256, 'unit' => 'M'],
    'post_max_size'       => ['current' => ini_get('post_max_size'),       'recommended' => 256, 'unit' => 'M'],
];

$phpLimitsOk = true;
$phpLimitWarnings = [];
foreach ($phpLimits as $key => $limit) {
    if ($key === 'max_execution_time') {
        $currentVal = (int) $limit['current'];
        $ok = ($currentVal === 0 || $currentVal >= $limit['recommended']);
        $currentDisplay = $currentVal === 0 ? 'Unbegrenzt' : $currentVal . 's';
    } else {
        $currentVal = parsePhpSize($limit['current']);
        $ok = ($limit['current'] === '-1' || $currentVal >= $limit['recommended']);
        $currentDisplay = $limit['current'] === '-1' ? 'Unbegrenzt' : $limit['current'];
    }
    $phpLimits[$key]['ok'] = $ok;
    $phpLimits[$key]['display'] = $currentDisplay;
    if (!$ok) {
        $phpLimitsOk = false;
        $phpLimitWarnings[] = "{$key}: {$currentDisplay} (empfohlen: {$limit['recommended']}{$limit['unit']})";
    }
}

$curlAvailable = function_exists('curl_init');
$allowUrlFopen = (bool) ini_get('allow_url_fopen');

// Writable-Check auf das Verzeichnis in dem setup.php liegt — dort wird
// die .env geschrieben und das ZIP extrahiert. Wenn das nicht writable
// ist, kann das Setup sofort scheitern, ohne vorher 100MB runterzuladen.
$setupDir = __DIR__;
$setupDirWritable = is_writable($setupDir);

// ── Rate-Limit gegen Setup-Bruteforce ────────────────────────────────
// Token + IP-Rate-Limit verhindern, dass ein Bot das Setup 1000× pro
// Minute aufruft und Downloads triggert.
$rateLimitKey = 'setup_rate_' . sha1($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!isset($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'reset' => time() + 60];
}
if (time() > $_SESSION[$rateLimitKey]['reset']) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'reset' => time() + 60];
}

function hitRateLimit(int $max = 20): bool
{
    global $rateLimitKey;
    $_SESSION[$rateLimitKey]['count']++;
    return $_SESSION[$rateLimitKey]['count'] > $max;
}

// ── HTTP-Helper ──────────────────────────────────────────────────────

function httpGet(string $url, ?string $saveTo = null, int $timeout = 30, array $extraHeaders = []): array
{
    $headers = array_merge(['User-Agent: ignis-Setup'], $extraHeaders);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => false,
        ]);

        if ($saveTo) {
            $fp = fopen($saveTo, 'wb');
            if (!$fp) {
                return ['ok' => false, 'error' => 'Konnte Zieldatei nicht erstellen: ' . $saveTo];
            }
            curl_setopt($ch, CURLOPT_FILE, $fp);

            // Real-time progress reporting via callback
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            $lastProgress = 0;
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
                function ($resource, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$lastProgress) {
                    if ($dlTotal > 0) {
                        $pct = (int)(($dlNow / $dlTotal) * 100);
                        // Only write every 5% to avoid I/O spam
                        if ($pct >= $lastProgress + 5 || $pct === 100) {
                            $lastProgress = $pct;
                            $mbNow = round($dlNow / 1048576, 1);
                            $mbTotal = round($dlTotal / 1048576, 1);
                            writeProgress('download', "{$mbNow} MB / {$mbTotal} MB", $pct);
                        }
                    }
                    return 0; // 0 = continue, non-zero = abort
                }
            );
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        $result    = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if ($saveTo) {
            fclose($fp);
            if ($httpCode >= 400 || !empty($curlError)) {
                @unlink($saveTo);
                return ['ok' => false, 'error' => $curlError ?: "HTTP {$httpCode}", 'http_code' => $httpCode];
            }
            return ['ok' => true];
        }

        if ($httpCode >= 400 || $result === false) {
            return ['ok' => false, 'error' => $curlError ?: "HTTP {$httpCode}", 'http_code' => $httpCode];
        }
        return ['ok' => true, 'body' => $result, 'http_code' => $httpCode];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['ok' => false, 'error' => 'Weder cURL noch allow_url_fopen verfügbar.'];
    }

    $context = stream_context_create(['http' => [
        'header'          => implode("\r\n", $headers),
        'timeout'         => $timeout,
        'follow_location' => true,
        'max_redirects'   => 5,
    ]]);

    if ($saveTo) {
        $source = @fopen($url, 'rb', false, $context);
        if (!$source) {
            return ['ok' => false, 'error' => 'Konnte URL nicht öffnen: ' . $url];
        }
        $dest = @fopen($saveTo, 'wb');
        if (!$dest) {
            fclose($source);
            return ['ok' => false, 'error' => 'Konnte Zieldatei nicht erstellen.'];
        }
        while (!feof($source)) {
            fwrite($dest, fread($source, 8192));
        }
        fclose($source);
        fclose($dest);
        return ['ok' => true];
    }

    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        return ['ok' => false, 'error' => 'Verbindung fehlgeschlagen: ' . $url];
    }
    return ['ok' => true, 'body' => $result];
}

// Defaults für BASE_PATH / DOMAIN
$defaultDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$defaultBasePath = ($scriptDir === '/' || $scriptDir === '\\') ? '/' : rtrim($scriptDir, '/\\') . '/';

// ── Sanitize / Escape-Helper ─────────────────────────────────────────

function sanitizeEnvValue(string $value): string
{
    $value = str_replace(["\r", "\n"], '', $value);
    return trim($value);
}

function formatEnvValue(string $value): string
{
    $value = sanitizeEnvValue($value);
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return '"' . $value . '"';
}

// Das Setup log-buffert Fehler in der Session statt in einer Datei, die
// nach erfolgreichem Setup liegenbleiben könnte und ggf. Credentials/Pfade
// leaked. Bei `?debug` werden die Einträge unten im UI angezeigt.
function setupLog(string $message): void
{
    if (!isset($_SESSION['setup_log'])) {
        $_SESSION['setup_log'] = [];
    }
    $_SESSION['setup_log'][] = '[' . date('Y-m-d H:i:s') . '] ' . $message;
}

// Validator für Custom-Branch-Namen. Whitelist statt escapeshellarg:
// escapeshellarg schützt vor Injection, erlaubt aber weiterhin exotische
// Zeichen die Git verwirren würden.
function isValidBranchName(string $name): bool
{
    return (bool) preg_match('~^[a-zA-Z0-9._/\\-]{1,100}$~', $name)
        && !str_contains($name, '..');
}

// BASE_PATH muss ein absoluter HTTP-Pfad sein. Regex verhindert Traversal
// und Shell-Metazeichen in der .env.
function isValidBasePath(string $path): bool
{
    return (bool) preg_match('~^/[a-zA-Z0-9._/\\-]*$~', $path)
        && !str_contains($path, '..');
}

// Domain — keine Schemes, kein Pfad, erlaubte Zeichen
function isValidDomain(string $domain): bool
{
    return (bool) preg_match('~^[a-zA-Z0-9.\\-]{1,253}(:[0-9]{1,5})?$~', $domain);
}

// ── AJAX: Setup-Fortschritt abfragen ─────────────────────────────────
// Der Setup-POST schreibt Fortschritt in ein Temp-File. Dieses Endpoint
// liest es aus und gibt den aktuellen Stand als JSON zurück.
if (($_GET['action'] ?? '') === 'progress') {
    header('Content-Type: application/json');
    $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'intrarp_setup_progress_' . session_id() . '.json';
    if (file_exists($progressFile)) {
        $data = @file_get_contents($progressFile);
        echo $data ?: '{"phase":"waiting"}';
    } else {
        echo '{"phase":"waiting"}';
    }
    exit;
}

// ── Progress-Helper ─────────────────────────────────────────────────
function writeProgress(string $phase, string $detail = '', int $percent = 0): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'intrarp_setup_progress_' . session_id() . '.json';
    $data = json_encode([
        'phase'   => $phase,
        'detail'  => $detail,
        'percent' => $percent,
        'time'    => time(),
    ]);
    @file_put_contents($file, $data, LOCK_EX);
}

function cleanupProgress(): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'intrarp_setup_progress_' . session_id() . '.json';
    @unlink($file);
}

// ── AJAX: Datenbankverbindung testen ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_db') {
    header('Content-Type: application/json');
    if (!verifyCsrf()) {
        echo json_encode([
            'success'    => false,
            'message'    => 'CSRF-Token ungültig. Seite neu laden.',
            'csrf_token' => freshCsrfToken(),
            'csrf_retry' => true,
        ]);
        exit;
    }
    if (hitRateLimit()) {
        echo json_encode(['success' => false, 'message' => 'Zu viele Anfragen — bitte kurz warten.']);
        exit;
    }

    $host = sanitizeEnvValue($_POST['db_host'] ?? 'localhost');
    $port = (int) ($_POST['db_port'] ?? 3306);
    $user = sanitizeEnvValue($_POST['db_user'] ?? 'root');
    $pass = sanitizeEnvValue($_POST['db_pass'] ?? '');
    $name = sanitizeEnvValue($_POST['db_name'] ?? '');

    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Datenbank-Name ist erforderlich.']);
        exit;
    }

    try {
        $dsn = 'mysql:host=' . $host . ';port=' . ($port ?: 3306) . ';dbname=' . $name . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        echo json_encode(['success' => true, 'message' => 'Verbindung erfolgreich! (Server: MySQL ' . $serverVersion . ')']);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Access denied')) {
            $msg = 'Zugriff verweigert – Benutzername oder Passwort falsch.';
        } elseif (str_contains($msg, 'Unknown database')) {
            $msg = 'Die Datenbank "' . htmlspecialchars($name) . '" existiert nicht.';
        } elseif (str_contains($msg, 'Connection refused') || str_contains($msg, 'No such file or directory')) {
            $msg = 'Verbindung zum Host "' . htmlspecialchars($host) . '" fehlgeschlagen – Server nicht erreichbar.';
        } elseif (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Name or service not known')) {
            $msg = 'Der Host "' . htmlspecialchars($host) . '" konnte nicht aufgelöst werden.';
        }
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

// ── AJAX: Discord Credentials testen ─────────────────────────────────
// Probe gegen den /oauth2/token-Endpoint mit Client-Credentials-Grant.
// Discord gibt hier entweder ein Token oder einen sauberen Fehler
// (invalid_client) zurück — reicht völlig aus, um falsche Credentials
// abzufangen, bevor der User das Setup durchzieht.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_discord') {
    header('Content-Type: application/json');
    if (!verifyCsrf()) {
        echo json_encode([
            'success'    => false,
            'message'    => 'CSRF-Token ungültig. Seite neu laden.',
            'csrf_token' => freshCsrfToken(),
            'csrf_retry' => true,
        ]);
        exit;
    }
    if (hitRateLimit()) {
        echo json_encode(['success' => false, 'message' => 'Zu viele Anfragen — bitte kurz warten.']);
        exit;
    }

    $clientId     = sanitizeEnvValue($_POST['discord_client_id'] ?? '');
    $clientSecret = sanitizeEnvValue($_POST['discord_client_secret'] ?? '');

    if ($clientId === '' || $clientSecret === '') {
        echo json_encode(['success' => false, 'message' => 'Client ID und Secret erforderlich.']);
        exit;
    }
    if (!preg_match('~^\d{17,20}$~', $clientId)) {
        echo json_encode(['success' => false, 'message' => 'Client ID muss eine 17–20-stellige Zahl sein (Discord Snowflake).']);
        exit;
    }

    if (!function_exists('curl_init')) {
        echo json_encode(['success' => true, 'message' => 'Format gültig. cURL nicht verfügbar — Live-Test übersprungen.']);
        exit;
    }

    $ch = curl_init('https://discord.com/api/v10/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ignis-Setup',
        ],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => 'identify',
        ]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    unset($ch);

    if ($body === false || $code === 0) {
        echo json_encode(['success' => false, 'message' => 'Discord nicht erreichbar: ' . ($err ?: 'unbekannt')]);
        exit;
    }

    $data = json_decode((string) $body, true) ?: [];

    if ($code === 200 && !empty($data['access_token'])) {
        echo json_encode(['success' => true, 'message' => 'Discord-Credentials gültig.']);
        exit;
    }

    $reason = $data['error_description'] ?? $data['error'] ?? "HTTP {$code}";
    if (($data['error'] ?? '') === 'invalid_client') {
        $reason = 'Client ID oder Secret ist falsch.';
    }
    echo json_encode(['success' => false, 'message' => 'Discord lehnte ab: ' . $reason]);
    exit;
}

// ── force_delete — manueller Self-Destruct bei Fehlerzuständen ───────
if (isset($_GET['force_delete']) && $_GET['force_delete'] === 'confirm') {
    $setupFile = __FILE__;
    @unlink($setupFile);
    clearstatcache(true, $setupFile);
    if (!file_exists($setupFile)) {
        header('Location: index.php');
        exit;
    }
    die('Fehler: setup.php konnte nicht gelöscht werden. Bitte manuell entfernen.');
}

// ── Flow-Status für das HTML ─────────────────────────────────────────
$errors = [];
$success = [];
$downloadMethodOk = $curlAvailable || $allowUrlFopen;
$canProceed = $phpVersionOk
    && $downloadMethodOk
    && class_exists('ZipArchive')
    && $allExtensionsOk
    && $setupDirWritable;

if (!$phpVersionOk) {
    $errors[] = "PHP Version {$phpVersion} ist zu alt. Mindestens PHP {$requiredPhpVersion} wird benötigt!";
    setupLog("PHP Version Check fehlgeschlagen: {$phpVersion} < {$requiredPhpVersion}");
}
if (!$allExtensionsOk) {
    $errors[] = 'Fehlende PHP-Extensions: ' . implode(', ', $missingRequired);
    setupLog('Fehlende PHP-Extensions: ' . implode(', ', $missingRequired));
}
if (!$setupDirWritable) {
    $errors[] = 'Das Setup-Verzeichnis ist nicht beschreibbar — ZIP/.env können nicht geschrieben werden.';
    setupLog('Setup-Dir nicht writable: ' . $setupDir);
}

$isAjaxSetup = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_SETUP_AJAX']));

/**
 * Versucht GitHub-API → bei 403 (Rate-Limit) Fallback via Redirect-Parse
 * von github.com/<owner>/<repo>/releases/latest, das ohne Auth die Tag
 * in der Location-Header zurückgibt.
 */
function fetchLatestRelease(string $repoOwner, string $repoName): array
{
    $apiUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/releases/latest";
    $apiResult = httpGet($apiUrl, null, 30, ['Accept: application/vnd.github+json']);

    if ($apiResult['ok']) {
        $release = json_decode($apiResult['body'], true);
        if ($release && !empty($release['tag_name'])) {
            return ['ok' => true, 'release' => $release, 'source' => 'api'];
        }
    }

    // Fallback: Non-API redirect. `/releases/latest` HTTP-301t auf
    // `/releases/tag/<tag>` weiter. Wir folgen nicht, parsen den Header.
    if (function_exists('curl_init')) {
        $ch = curl_init("https://github.com/{$repoOwner}/{$repoName}/releases/latest");
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: ignis-Setup'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        unset($ch);

        if ($redirect && preg_match('~/releases/tag/([^/?#]+)~', $redirect, $m)) {
            $tag = $m[1];
            // Asset-Name raten anhand der Konvention "ignis-<tag>.zip"
            // (Legacy-Fallback "intraRP-<tag>.zip" wird vom GitHub-Release parallel mitgeliefert.)
            $zipName = 'ignis-' . ltrim($tag, 'v') . '.zip';
            $zipUrl  = "https://github.com/{$repoOwner}/{$repoName}/releases/download/{$tag}/{$zipName}";
            return [
                'ok'     => true,
                'source' => 'fallback',
                'release' => [
                    'tag_name' => $tag,
                    'assets'   => [[
                        'name' => $zipName,
                        'browser_download_url' => $zipUrl,
                    ]],
                ],
            ];
        }
    }

    return ['ok' => false, 'error' => $apiResult['error'] ?? 'Unbekannter GitHub-Fehler'];
}

/**
 * Bootstrap Phinx programmatisch und führt alle ausstehenden Migrations
 * aus. Liest die DB-Credentials aus der bereits geschriebenen .env.
 * Return: ['ok' => bool, 'output' => string]
 */
function runPhinxMigrations(string $baseDir): array
{
    $autoload = $baseDir . '/vendor/autoload.php';
    $phinxCfg = $baseDir . '/phinx.php';

    if (!file_exists($autoload)) {
        return ['ok' => false, 'output' => 'vendor/autoload.php fehlt — kein Composer-Install vorhanden.'];
    }
    if (!file_exists($phinxCfg)) {
        return ['ok' => false, 'output' => 'phinx.php fehlt im Projekt-Root.'];
    }

    try {
        require_once $autoload;

        if (!class_exists(\Phinx\Console\PhinxApplication::class)) {
            return ['ok' => false, 'output' => 'Phinx ist in vendor/ nicht verfügbar.'];
        }

        // phinx.php liest DB-Credentials aus $_ENV — wir sourcen die .env
        // die gerade geschrieben wurde, damit Phinx die richtigen
        // Credentials sieht.
        if (class_exists(\Dotenv\Dotenv::class) && file_exists($baseDir . '/.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable($baseDir);
            $dotenv->safeLoad();
        }

        $app = new \Phinx\Console\PhinxApplication();
        $app->setAutoExit(false);

        $input = new \Symfony\Component\Console\Input\ArrayInput([
            'command'    => 'migrate',
            '--configuration' => $phinxCfg,
            '--environment'   => 'production',
        ]);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $exitCode = $app->run($input, $output);
        $outputText = $output->fetch();

        return [
            'ok'     => $exitCode === 0,
            'output' => $outputText,
        ];
    } catch (\Throwable $e) {
        return ['ok' => false, 'output' => 'Exception: ' . $e->getMessage()];
    }
}

/**
 * Lädt composer.phar herunter und führt `install --no-dev -o` im
 * Zielverzeichnis aus. Gibt bei fehlendem exec() einen klaren Fehler
 * zurück, damit der Client dem User Anleitung zeigen kann.
 */
function installComposer(string $baseDir, bool $execAvailable): array
{
    if (!$execAvailable) {
        return [
            'ok'     => false,
            'output' => 'exec() ist auf diesem Server deaktiviert.',
            'manual' => true,
        ];
    }

    $composerPhar = $baseDir . '/composer.phar';
    if (!file_exists($composerPhar)) {
        $dl = httpGet('https://getcomposer.org/composer-stable.phar', $composerPhar, 120);
        if (!$dl['ok']) {
            return [
                'ok'     => false,
                'output' => 'composer.phar Download fehlgeschlagen: ' . ($dl['error'] ?? ''),
                'manual' => true,
            ];
        }
    }

    $phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cwd = getcwd();
    @chdir($baseDir);

    $cmd = escapeshellarg($phpBin) . ' '
        . escapeshellarg($composerPhar) . ' install --no-dev --no-interaction --prefer-dist --optimize-autoloader 2>&1';

    $execOutput = [];
    $execReturn = 1;
    @exec($cmd, $execOutput, $execReturn);
    @chdir($cwd);

    return [
        'ok'     => $execReturn === 0,
        'output' => implode("\n", $execOutput),
        'manual' => false,
    ];
}

/**
 * Verifiziert ZIP-Integrität per SHA256 gegen den Digest aus der Release-
 * Response (kommt als "sha256:xxxxx"). Fällt stumm durch, wenn kein
 * Digest bekannt ist — GitHub liefert den nicht bei allen Releases.
 */
function verifyZipDigest(string $zipPath, ?string $expectedDigest): array
{
    if (!$expectedDigest) {
        return ['ok' => true, 'skipped' => true];
    }
    if (!preg_match('~^sha256:([a-f0-9]{64})$~i', $expectedDigest, $m)) {
        return ['ok' => true, 'skipped' => true];
    }
    $actual = hash_file('sha256', $zipPath);
    if (!hash_equals(strtolower($m[1]), strtolower((string) $actual))) {
        return [
            'ok'    => false,
            'error' => 'SHA256-Prüfsumme stimmt nicht — Download könnte manipuliert oder korrupt sein.',
        ];
    }
    return ['ok' => true];
}

/**
 * Prüft nach dem Entpacken, ob alle storage/-Unterverzeichnisse
 * tatsächlich writable sind. Versucht chmod als letzten Ausweg.
 */
function checkStorageWritables(string $baseDir): array
{
    $dirs = [
        'storage/cache',
        'storage/documents',
        'storage/logs',
        'storage/temp',
        'storage/template-assets',
    ];
    $warnings = [];
    foreach ($dirs as $rel) {
        $abs = $baseDir . '/' . $rel;
        if (!is_dir($abs)) {
            @mkdir($abs, 0775, true);
        }
        if (!is_writable($abs)) {
            @chmod($abs, 0775);
            clearstatcache(true, $abs);
            if (!is_writable($abs)) {
                $warnings[] = $rel . ' ist nicht beschreibbar — bitte chmod 0775 setzen.';
            }
        }
    }
    return $warnings;
}

/**
 * Sichere Git-Clone/-Pull-Wrapper mit escapeshellarg + Whitelist-Check,
 * und Safeguard gegen versehentliches reset auf fremde Repos.
 */
function runGitInstall(string $baseDir, string $branchMode, string $customBranch, bool $execAvailable): array
{
    if (!$execAvailable) {
        return ['ok' => false, 'error' => 'exec() ist deaktiviert — Branch-Install nicht möglich.'];
    }

    $repoUrl  = 'https://github.com/EmergencyForge/intraRP.git';
    $expected = 'EmergencyForge/intraRP';

    $branch = $branchMode === 'custom' ? $customBranch : 'main';
    if (!isValidBranchName($branch)) {
        return ['ok' => false, 'error' => 'Ungültiger Branch-Name.'];
    }

    $cwd = getcwd();
    @chdir($baseDir);

    $cmds = [];
    $log  = [];

    if (!is_dir($baseDir . '/.git')) {
        // Frisches Repo — init + remote + fetch + checkout
        $cmds[] = 'git init 2>&1';
        $cmds[] = 'git remote add origin ' . escapeshellarg($repoUrl) . ' 2>&1';
        $cmds[] = 'git fetch origin ' . escapeshellarg($branch) . ' 2>&1';
        $cmds[] = 'git checkout -b ' . escapeshellarg($branch) . ' ' . escapeshellarg('origin/' . $branch) . ' 2>&1';
        $cmds[] = 'git reset --hard ' . escapeshellarg('origin/' . $branch) . ' 2>&1';
    } else {
        // Existierendes .git — prüfen ob Remote wirklich EmergencyForge/intraRP ist
        $remoteOutput = [];
        @exec('git remote get-url origin 2>&1', $remoteOutput);
        $remoteUrl = trim((string) ($remoteOutput[0] ?? ''));
        if (!str_contains($remoteUrl, $expected)) {
            @chdir($cwd);
            return [
                'ok'    => false,
                'error' => 'Bestehendes .git-Verzeichnis zeigt auf ' . htmlspecialchars($remoteUrl) . ' statt ' . $expected . ' — Setup abgebrochen, um fremde Repos nicht zu überschreiben.',
            ];
        }
        $cmds[] = 'git fetch origin ' . escapeshellarg($branch) . ' 2>&1';
        $cmds[] = 'git checkout ' . escapeshellarg($branch) . ' 2>&1';
        $cmds[] = 'git reset --hard ' . escapeshellarg('origin/' . $branch) . ' 2>&1';
    }

    foreach ($cmds as $cmd) {
        $out = [];
        $rc  = 1;
        @exec($cmd, $out, $rc);
        $log[] = '$ ' . $cmd . "\n" . implode("\n", $out);
        if ($rc !== 0) {
            @chdir($cwd);
            return ['ok' => false, 'error' => 'Git-Fehler bei: ' . $cmd, 'log' => implode("\n\n", $log)];
        }
    }

    @chdir($cwd);
    return ['ok' => true, 'log' => implode("\n\n", $log)];
}

// ── Haupt-Setup-Flow (POST ohne `action`) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canProceed && !isset($_POST['action'])) {

    if (!verifyCsrf()) {
        $errors[] = 'CSRF-Token ungültig. Bitte Seite neu laden und erneut versuchen.';
        if ($isAjaxSetup) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }
    }

    if (empty($errors) && hitRateLimit()) {
        $errors[] = 'Zu viele Setup-Versuche in kurzer Zeit. Bitte kurz warten.';
    }

    $gitBranch    = $_POST['git_branch'] ?? 'release';
    $customBranch = trim($_POST['custom_branch'] ?? '');

    // Whitelist
    if (!in_array($gitBranch, ['release', 'main', 'custom'], true)) {
        $errors[] = 'Ungültiger Branch-Modus.';
        $gitBranch = 'release';
    }
    if ($gitBranch === 'custom' && !isValidBranchName($customBranch)) {
        $errors[] = 'Custom Branch-Name enthält ungültige Zeichen.';
    }

    @set_time_limit(600);
    @ini_set('memory_limit', '512M');

    $baseDir = __DIR__;
    $warnings = [];
    $composerOutput = '';
    $migrateOutput  = '';
    $needsManualComposer = false;

    writeProgress('connect', 'Verbindung zu GitHub...', 0);

    // ─── Schritt A: Quellcode installieren ──────────────────────────
    if (empty($errors)) {
        if ($gitBranch === 'release') {
            $rel = fetchLatestRelease('EmergencyForge', 'intraRP');
            if (!$rel['ok']) {
                $errors[] = 'Konnte Release-Informationen nicht abrufen: ' . ($rel['error'] ?? '');
                setupLog('fetchLatestRelease Fehler: ' . ($rel['error'] ?? ''));
            } else {
                $release = $rel['release'];
                $tagName = $release['tag_name'];
                // Asset-Preferenz: ignis-*.zip bevorzugen, Legacy intraRP-*.zip als Fallback.
                $zipAsset = null;
                foreach (($release['assets'] ?? []) as $asset) {
                    $name = (string) ($asset['name'] ?? '');
                    if (!str_ends_with($name, '.zip')) {
                        continue;
                    }
                    if (str_starts_with($name, 'ignis-')) {
                        $zipAsset = $asset;
                        break;
                    }
                    if ($zipAsset === null && str_starts_with($name, 'intraRP-')) {
                        $zipAsset = $asset;
                    }
                }

                if ($zipAsset === null) {
                    $errors[] = 'Kein Release-ZIP (ignis-*.zip oder intraRP-*.zip) in Version ' . htmlspecialchars($tagName) . ' gefunden.';
                } else {
                    $zipUrl  = $zipAsset['browser_download_url'];
                    $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipAsset['name'];

                    $dl = httpGet($zipUrl, $zipPath, 600);
                    if (!$dl['ok']) {
                        $errors[] = 'Download fehlgeschlagen: ' . ($dl['error'] ?? '');
                        setupLog('ZIP Download Fehler: ' . ($dl['error'] ?? ''));
                    } elseif (!file_exists($zipPath) || filesize($zipPath) < 1000) {
                        $errors[] = 'Download unvollständig oder leer.';
                        @unlink($zipPath);
                    } else {
                        // SHA256 verifizieren falls Digest mitgeliefert
                        $digest = $zipAsset['digest'] ?? null;
                        $verify = verifyZipDigest($zipPath, $digest);
                        if (!$verify['ok']) {
                            $errors[] = $verify['error'];
                            @unlink($zipPath);
                        } else {
                            if (!empty($verify['skipped'])) {
                                $warnings[] = 'SHA256-Digest nicht im Release verfügbar — Integritätsprüfung übersprungen.';
                            }
                            $zip = new ZipArchive();
                            $rc = $zip->open($zipPath);
                            if ($rc === true) {
                                $zip->extractTo($baseDir);
                                $zip->close();
                                @unlink($zipPath);
                                $success[] = 'Release ' . htmlspecialchars($tagName) . ' erfolgreich installiert.';
                            } else {
                                $errors[] = 'ZIP-Entpacken fehlgeschlagen (Code: ' . $rc . ').';
                                @unlink($zipPath);
                            }
                        }
                    }
                }
            }
        } else {
            // Main / Custom Branch
            if (!$gitAvailable) {
                $errors[] = 'Git ist nicht verfügbar — Branch-Install nicht möglich.';
            } else {
                $gitRes = runGitInstall($baseDir, $gitBranch, $customBranch, $execAvailable);
                if (!$gitRes['ok']) {
                    $errors[] = 'Git-Fehler: ' . $gitRes['error'];
                    setupLog('Git Install Fehler: ' . ($gitRes['log'] ?? $gitRes['error']));
                } else {
                    $success[] = 'Repository ausgecheckt (' . ($gitBranch === 'custom' ? $customBranch : 'main') . ').';
                }
            }
        }
    }

    writeProgress('composer', 'Abhängigkeiten installieren...', 50);

    // ─── Schritt B: Composer install (nur Branch-Mode) ──────────────
    if (empty($errors) && $gitBranch !== 'release') {
        $composer = installComposer($baseDir, $execAvailable);
        $composerOutput = $composer['output'];
        if (!$composer['ok']) {
            if (!empty($composer['manual'])) {
                $needsManualComposer = true;
                $warnings[] = 'Composer konnte nicht automatisch ausgeführt werden — bitte manuell nachziehen.';
            } else {
                $errors[] = 'Composer install fehlgeschlagen. Details im Log.';
                setupLog('Composer Output: ' . $composerOutput);
            }
        } else {
            $success[] = 'Composer-Abhängigkeiten installiert.';
        }
    }

    writeProgress('config', 'Konfiguration wird erstellt...', 65);

    // ─── Schritt C: Config validieren + .env schreiben ──────────────
    $envConfig = [
        'APP_ENV'               => 'production',
        'DB_HOST'               => sanitizeEnvValue($_POST['db_host'] ?? 'localhost'),
        'DB_PORT'               => sanitizeEnvValue($_POST['db_port'] ?? '3306'),
        'DB_USER'               => sanitizeEnvValue($_POST['db_user'] ?? 'root'),
        'DB_PASS'               => sanitizeEnvValue($_POST['db_pass'] ?? ''),
        'DB_NAME'               => sanitizeEnvValue($_POST['db_name'] ?? 'intrarp'),
        'DISCORD_CLIENT_ID'     => sanitizeEnvValue($_POST['discord_client_id'] ?? ''),
        'DISCORD_CLIENT_SECRET' => sanitizeEnvValue($_POST['discord_client_secret'] ?? ''),
        'BASE_PATH'             => sanitizeEnvValue($_POST['base_path'] ?? '/'),
        'DOMAIN'                => sanitizeEnvValue($_POST['domain'] ?? 'localhost'),
        'APP_KEY'               => 'base64:' . base64_encode(random_bytes(32)),
    ];

    if (empty($envConfig['DB_NAME'])) {
        $errors[] = 'Datenbank-Name ist erforderlich!';
    }
    if (!preg_match('~^\d{17,20}$~', $envConfig['DISCORD_CLIENT_ID'])) {
        $errors[] = 'Discord Client ID muss eine 17–20-stellige Zahl sein.';
    }
    if ($envConfig['DISCORD_CLIENT_SECRET'] === '') {
        $errors[] = 'Discord Client Secret ist erforderlich!';
    }
    if (!isValidDomain($envConfig['DOMAIN'])) {
        $errors[] = 'Domain-Format ungültig.';
    }
    if (!isValidBasePath($envConfig['BASE_PATH'])) {
        $errors[] = 'Base Path muss mit "/" beginnen und darf nur Buchstaben, Zahlen, ., _, -, / enthalten.';
    }

    if (empty($errors)) {
        $envContent = "APP_ENV=" . formatEnvValue($envConfig['APP_ENV']) . "\n";
        $envContent .= "APP_KEY=" . formatEnvValue($envConfig['APP_KEY']) . "\n\n";
        $envContent .= "DB_HOST=" . formatEnvValue($envConfig['DB_HOST']) . "\n";
        $envContent .= "DB_PORT=" . formatEnvValue($envConfig['DB_PORT']) . "\n";
        $envContent .= "DB_USER=" . formatEnvValue($envConfig['DB_USER']) . "\n";
        $envContent .= "DB_PASS=" . formatEnvValue($envConfig['DB_PASS']) . "\n";
        $envContent .= "DB_NAME=" . formatEnvValue($envConfig['DB_NAME']) . "\n\n";
        $envContent .= "DISCORD_CLIENT_ID=" . formatEnvValue($envConfig['DISCORD_CLIENT_ID']) . "\n";
        $envContent .= "DISCORD_CLIENT_SECRET=" . formatEnvValue($envConfig['DISCORD_CLIENT_SECRET']) . "\n\n";
        $envContent .= "# System Configuration\n";
        $envContent .= "BASE_PATH=" . formatEnvValue($envConfig['BASE_PATH']) . "\n";
        $envContent .= "DOMAIN=" . formatEnvValue($envConfig['DOMAIN']) . "\n";

        if (!@file_put_contents($baseDir . '/.env', $envContent)) {
            $errors[] = '.env Datei konnte nicht geschrieben werden — Schreibrechte prüfen.';
            setupLog('file_put_contents(.env) fehlgeschlagen');
        } else {
            $success[] = '.env Datei erstellt.';
        }
    }

    writeProgress('storage', 'Verzeichnisse prüfen...', 75);

    // ─── Schritt D: storage/-Writables prüfen ───────────────────────
    if (empty($errors)) {
        $storageWarnings = checkStorageWritables($baseDir);
        if (!empty($storageWarnings)) {
            $warnings = array_merge($warnings, $storageWarnings);
        } else {
            $success[] = 'storage/-Verzeichnisse beschreibbar.';
        }
    }

    writeProgress('migrate', 'Datenbank-Migrations...', 85);

    // ─── Schritt E: Phinx-Migrations ausführen ──────────────────────
    if (empty($errors) && !$needsManualComposer) {
        $mig = runPhinxMigrations($baseDir);
        $migrateOutput = $mig['output'];
        if (!$mig['ok']) {
            // Migrations-Fehler ist nicht immer ein Hard-Fail — AutoMigrator
            // fängt beim ersten Web-Request nach, aber wir warnen deutlich.
            $warnings[] = 'Migrations konnten nicht automatisch laufen. ıgnıs versucht es beim ersten Request erneut. Details im Log.';
            setupLog('Phinx Output: ' . $migrateOutput);
        } else {
            $success[] = 'Datenbank-Migrations erfolgreich ausgeführt.';
        }
    }

    writeProgress('cleanup', 'Aufräumen...', 95);

    // ─── Schritt F: Self-destruct ───────────────────────────────────
    $setupFile = __FILE__;
    $selfDeleteOk = false;
    if (empty($errors)) {
        @unlink($setupFile);
        clearstatcache(true, $setupFile);
        $selfDeleteOk = !file_exists($setupFile);
        if (!$selfDeleteOk) {
            $warnings[] = 'setup.php konnte nicht automatisch gelöscht werden — bitte manuell entfernen, sonst könnte jemand das System re-installieren.';
            setupLog('Self-delete fehlgeschlagen: ' . $setupFile);
        }
        // Setup-Log aus der Session löschen (enthält ggf. Pfade)
        unset($_SESSION['setup_log']);
    }

    writeProgress(empty($errors) ? 'done' : 'error', empty($errors) ? 'Setup abgeschlossen!' : implode('; ', $errors), 100);

    if ($isAjaxSetup) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'             => empty($errors),
            'errors'              => $errors,
            'warnings'            => $warnings,
            'messages'            => $success,
            'needsManualComposer' => $needsManualComposer,
            'composerOutput'      => $composerOutput !== '' ? mb_substr($composerOutput, -2000) : '',
            'migrateOutput'       => $migrateOutput !== '' ? mb_substr($migrateOutput, -2000) : '',
            'selfDeleted'         => $selfDeleteOk,
        ]);
        cleanupProgress();
        exit;
    }

    if (empty($errors)) {
        header('Location: index.php');
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ıgnıs Setup</title>
    <style>
        :root {
            --color-primary: #ff4d00;
            --color-primary-hover: #e04400;
            --color-primary-subtle: rgba(255, 77, 0, 0.06);
            --color-bg: #1a1a2e;
            --color-surface: #ffffff;
            --color-surface-raised: #fafafa;
            --color-text: #1a1a2e;
            --color-text-muted: #64647a;
            --color-border: #e2e2ea;
            --color-border-hover: #c8c8d4;
            --color-input-bg: #f4f4f8;
            --color-info: #1557b0;
            --color-info-bg: #edf4ff;
            --color-success: #1a7a2e;
            --color-success-bg: #e6f7ea;
            --color-success-border: #34a853;
            --color-error: #b91c1c;
            --color-error-bg: #fef2f2;
            --color-error-border: #ef4444;
            --color-warning: #c2410c;
            --color-warning-bg: #fff7ed;
            --color-secondary-btn: #64647a;
            --color-secondary-btn-hover: #4a4a5e;
            --color-test-btn: #2563eb;
            --color-test-btn-hover: #1d4ed8;
            --color-dev: #7b1fa2;
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 20px;

            /* Spacing scale */
            --space-xs: 4px;
            --space-sm: 8px;
            --space-md: 16px;
            --space-lg: 24px;
            --space-xl: 36px;
            --space-2xl: 48px;

            /* Shadows */
            --shadow-sm: 0 1px 3px rgba(26, 26, 46, 0.08);
            --shadow-md: 0 4px 16px rgba(26, 26, 46, 0.1);
            --shadow-lg: 0 12px 48px rgba(26, 26, 46, 0.18);
            --shadow-xl: 0 24px 64px rgba(26, 26, 46, 0.25);
        }

        /* ═══ Dark mode ═══ */
        @media (prefers-color-scheme: dark) {
            :root {
                --color-primary: #ff6b2c;
                --color-primary-hover: #ff4d00;
                --color-primary-subtle: rgba(255, 107, 44, 0.12);
                --color-bg: #0a0a0f;
                --color-surface: #16161e;
                --color-surface-raised: #1e1e2a;
                --color-text: #e4e4ed;
                --color-text-muted: #8b8ba0;
                --color-border: #2a2a3a;
                --color-border-hover: #3a3a4e;
                --color-input-bg: #1e1e2a;
                --color-info: #60a5fa;
                --color-info-bg: rgba(96, 165, 250, 0.1);
                --color-success: #4ade80;
                --color-success-bg: rgba(74, 222, 128, 0.1);
                --color-success-border: #22c55e;
                --color-error: #f87171;
                --color-error-bg: rgba(248, 113, 113, 0.1);
                --color-error-border: #ef4444;
                --color-warning: #fb923c;
                --color-warning-bg: rgba(251, 146, 60, 0.1);
                --color-secondary-btn: #8b8ba0;
                --color-secondary-btn-hover: #a5a5b8;
                --color-test-btn: #3b82f6;
                --color-test-btn-hover: #2563eb;
                --color-dev: #a855f7;
                --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.3);
                --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.35);
                --shadow-lg: 0 12px 48px rgba(0, 0, 0, 0.45);
                --shadow-xl: 0 24px 64px rgba(0, 0, 0, 0.55);
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            background: var(--color-bg);
            color: var(--color-text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            width: 100%;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* WBB-style: full-width header bar */
        .setup-header {
            background: var(--color-primary);
            color: white;
            padding: var(--space-lg) var(--space-xl);
            display: flex;
            align-items: center;
            gap: var(--space-md);
        }

        .setup-logo {
            height: 32px;
            width: auto;
        }

        .setup-header h1 {
            font-size: 1.2em;
            letter-spacing: -0.01em;
            font-weight: 600;
        }

        .setup-tagline {
            font-size: 0.85em;
            opacity: 0.8;
            margin: 0;
            margin-left: auto;
        }

        /* Content area — full width with horizontal padding */
        .content {
            width: 100%;
            padding: var(--space-lg) var(--space-xl);
            flex: 1;
        }

        /* Page title + thin progress bar (WBB-style, left-aligned) */
        .page-title {
            margin-bottom: var(--space-lg);
        }

        .page-title h2 {
            font-size: 1.3em;
            font-weight: 300;
            color: var(--color-text);
            letter-spacing: -0.01em;
            margin-bottom: var(--space-xs);
        }

        .progress-thin {
            height: 3px;
            background: var(--color-border);
            border-radius: 2px;
            overflow: hidden;
            max-width: 280px;
        }

        .progress-thin-fill {
            height: 100%;
            background: var(--color-primary);
            border-radius: 2px;
            width: 0%;
            transition: width 0.4s ease;
        }

        .form-group {
            margin-bottom: var(--space-lg);
        }

        .form-group label {
            display: block;
            font-weight: 700;
            margin-bottom: var(--space-sm);
            color: var(--color-text);
            font-size: 0.88em;
            letter-spacing: 0.01em;
        }

        .form-group input[type="text"],
        .form-group input[type="url"],
        .form-group select {
            width: 100%;
            padding: 14px var(--space-md);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 0.95em;
            background: var(--color-surface);
            color: var(--color-text);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: 2px solid transparent;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-subtle);
        }

        .form-group select {
            background-color: var(--color-surface);
            color: var(--color-text);
            cursor: pointer;
        }

        .form-group small {
            display: block;
            color: var(--color-text-muted);
            margin-top: var(--space-xs);
            font-size: 0.85em;
            line-height: 1.4;
        }

        .form-group small.indented {
            margin-left: var(--space-xl);
        }

        .form-group code {
            background: var(--color-input-bg);
            padding: 2px var(--space-sm);
            border-radius: var(--radius-sm);
            font-family: 'Courier New', monospace;
            color: var(--color-primary);
            font-size: 0.9em;
        }

        .color-picker-wrapper {
            display: flex;
            gap: var(--space-sm);
            align-items: center;
        }

        .color-picker-wrapper input[type="color"] {
            width: 60px;
            height: 45px;
            border: 2px solid var(--color-border);
            border-radius: var(--radius-md);
            cursor: pointer;
            background: var(--color-surface);
        }

        .color-picker-wrapper input[type="color"]::-webkit-color-swatch-wrapper {
            padding: 4px;
        }

        .color-picker-wrapper input[type="color"]::-webkit-color-swatch {
            border: none;
            border-radius: var(--radius-sm);
        }

        .color-picker-wrapper input[type="text"] {
            flex: 1;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-top: var(--space-sm);
        }

        .checkbox-group input[type="checkbox"] {
            width: 24px;
            height: 24px;
            margin-right: var(--space-sm);
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }

        .section-title {
            font-size: 1.4em;
            color: var(--color-text);
            margin: 0 0 var(--space-lg) 0;
            padding-bottom: 0;
            border-bottom: none;
            letter-spacing: -0.02em;
            font-weight: 800;
        }

        .btn {
            background: var(--color-primary);
            color: white;
            padding: var(--space-md) var(--space-xl);
            border: none;
            border-radius: var(--radius-md);
            font-size: 1.05em;
            cursor: pointer;
            width: 100%;
            font-weight: 700;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
        }

        .btn:hover {
            background: var(--color-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--color-primary-subtle);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn:focus-visible {
            outline: 2px solid var(--color-primary);
            outline-offset: 2px;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: var(--color-secondary-btn);
            margin-top: var(--space-md);
        }

        .btn-secondary:hover {
            background: var(--color-secondary-btn-hover);
        }

        .btn-test-db {
            background: var(--color-test-btn);
            color: white;
            padding: 10px var(--space-lg);
            border: none;
            border-radius: var(--radius-md);
            font-size: 0.9em;
            cursor: pointer;
            font-weight: 700;
            transition: background 0.2s, transform 0.2s;
        }

        .btn-test-db:hover {
            background: var(--color-test-btn-hover);
            transform: translateY(-1px);
        }

        .btn-test-db:focus-visible {
            outline: 2px solid var(--color-test-btn);
            outline-offset: 2px;
        }

        .btn-test-db:active:not(:disabled) {
            transform: translateY(0) scale(0.97);
        }

        .btn-test-db:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-test-db.testing {
            pointer-events: none;
        }

        .btn-test-db.testing::before {
            content: '';
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: var(--space-sm);
            vertical-align: middle;
        }

        .db-test-result {
            padding: var(--space-sm) var(--space-md);
            border-radius: var(--radius-md);
            margin-top: var(--space-sm);
            display: none;
            font-size: 0.95em;
        }

        .db-test-result.success {
            background: var(--color-success-bg);
            border-left: 4px solid var(--color-success-border);
            color: var(--color-success);
        }

        .db-test-result.error {
            background: var(--color-error-bg);
            border-left: 4px solid var(--color-error-border);
            color: var(--color-error);
        }

        .db-test-result.loading {
            background: var(--color-info-bg);
            border-left: 4px solid var(--color-test-btn);
            color: var(--color-info);
        }

        .alert {
            padding: var(--space-md) var(--space-lg);
            border-radius: var(--radius-sm);
            margin-bottom: var(--space-lg);
            border: none;
            border-left: 4px solid transparent;
        }

        .alert-error {
            background: var(--color-error-bg);
            border-left-color: var(--color-error-border);
            color: var(--color-error);
        }

        .alert-success {
            background: var(--color-success-bg);
            border-left-color: var(--color-success-border);
            color: var(--color-success);
        }

        .alert-warning {
            background: var(--color-warning-bg);
            border-left-color: var(--color-warning);
            color: var(--color-warning);
        }

        .alert ul {
            margin-left: var(--space-lg);
            margin-top: var(--space-sm);
        }

        .info-box {
            background: var(--color-info-bg);
            border: 1.5px solid var(--color-test-btn);
            padding: var(--space-md) var(--space-lg);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-lg);
            color: var(--color-info);
            line-height: 1.6;
            font-size: 0.92em;
        }

        .info-box strong {
            display: block;
            margin-bottom: var(--space-xs);
        }

        .radio-group {
            margin-top: var(--space-sm);
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }

        .radio-group label {
            display: flex;
            align-items: center;
            padding: var(--space-md) var(--space-lg);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s ease;
            background: var(--color-surface);
        }

        .radio-group label:hover {
            border-color: var(--color-border-hover);
            background: var(--color-surface-raised);
            box-shadow: var(--shadow-sm);
        }

        .radio-group label:has(input:checked) {
            border-color: var(--color-primary);
            background: var(--color-primary-subtle);
            box-shadow: 0 0 0 1px var(--color-primary);
        }

        .radio-group input[type="radio"] {
            width: 24px;
            height: 24px;
            margin-right: var(--space-md);
            cursor: pointer;
            flex-shrink: 0;
        }

        .radio-group input[type="radio"]:focus-visible {
            outline: 2px solid var(--color-primary);
            outline-offset: 2px;
        }

        .radio-group input[type="radio"]:checked+span {
            font-weight: 600;
            color: var(--color-primary);
        }

        .radio-group label span {
            flex: 1;
        }

        .radio-group label small {
            display: block;
            color: var(--color-text-muted);
            font-size: 0.85em;
            margin-top: var(--space-xs);
        }

        .warning-badge {
            display: inline-block;
            background: var(--color-warning);
            color: white;
            padding: 3px var(--space-sm);
            border-radius: var(--radius-sm);
            font-size: 0.7em;
            font-weight: 700;
            margin-left: var(--space-sm);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .warning-badge--success {
            background: var(--color-success-border);
        }

        .warning-badge--dev {
            background: var(--color-dev);
        }

        .custom-branch-input {
            margin-top: var(--space-sm);
            display: none;
        }

        .custom-branch-input.active {
            display: block;
        }

        .custom-branch-input input {
            width: 100%;
            padding: var(--space-sm) var(--space-md);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 0.95em;
            background: var(--color-surface);
            color: var(--color-text);
        }

        .custom-branch-input input:focus {
            outline: 2px solid transparent;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-subtle);
        }

        .requirement-box {
            padding: var(--space-md);
            border-radius: var(--radius-md);
            display: flex;
            align-items: flex-start;
            gap: var(--space-sm);
            border: 1.5px solid transparent;
            min-width: 0;
        }

        .requirement-box.success {
            background: var(--color-success-bg);
            border-color: var(--color-success-border);
            color: var(--color-success);
        }

        .requirement-box.error {
            background: var(--color-error-bg);
            border-color: var(--color-error-border);
            color: var(--color-error);
        }

        .requirement-box strong {
            font-size: 1.1em;
        }

        .requirement-icon {
            font-size: 1.4em;
            line-height: 1;
            flex-shrink: 0;
        }

        .requirement-detail {
            flex: 1;
            min-width: 0;
        }

        .requirement-title {
            font-size: 0.95em;
            font-weight: 700;
        }

        .requirement-status {
            font-size: 0.85em;
            margin-top: 2px;
        }

        .requirement-sub {
            font-size: 0.8em;
            opacity: 0.75;
            margin-top: 2px;
        }

        .requirement-fix {
            margin-top: var(--space-xs);
        }

        .requirement-fix-inner {
            margin-top: var(--space-sm);
            padding: var(--space-sm) var(--space-md);
            background: rgba(128, 128, 128, 0.1);
            border-radius: var(--radius-sm);
            font-size: 0.9em;
        }

        .requirements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: var(--space-sm);
            margin-bottom: var(--space-lg);
        }

        .alert-error-log {
            margin-top: var(--space-md);
            padding-top: var(--space-md);
            border-top: 1px solid var(--color-error-border);
            opacity: 0.7;
        }

        .info-box a {
            color: var(--color-info);
            font-weight: 600;
        }

        @media (max-width: 768px) {
            :root {
                --space-lg: 20px;
                --space-xl: 28px;
                --space-2xl: 36px;
            }

            body {
                padding: var(--space-md);
            }

            .setup-header {
                padding: var(--space-xl) var(--space-lg);
            }

            .setup-header h1 {
                font-size: 1.7em;
            }

            .btn {
                padding: var(--space-md) var(--space-lg);
                font-size: 1em;
            }

            .password-wrapper {
                flex-direction: column;
            }

            .password-wrapper .toggle-password {
                align-self: flex-start;
            }
        }

        @media (max-width: 480px) {
            :root {
                --space-lg: 16px;
                --space-xl: 24px;
                --space-2xl: 32px;
            }

            body {
                padding: var(--space-sm);
            }

            .container {
                border-radius: var(--radius-md);
            }

            .setup-header {
                text-align: center;
            }

            .radio-group label {
                padding: var(--space-sm) var(--space-md);
            }
        }

        .password-wrapper {
            position: relative;
            display: flex;
            gap: var(--space-sm);
            align-items: center;
        }

        .password-wrapper input {
            flex: 1;
            padding: 14px var(--space-md);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 0.95em;
            background: var(--color-surface);
            color: var(--color-text);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .password-wrapper input:focus {
            outline: 2px solid transparent;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-subtle);
        }

        .toggle-password {
            background: var(--color-input-bg);
            border: 1.5px solid var(--color-border);
            padding: var(--space-md) var(--space-lg);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 0.85em;
            transition: all 0.2s;
            white-space: nowrap;
            font-weight: 600;
            min-height: 44px;
            color: var(--color-text-muted);
        }

        .toggle-password:hover {
            background: var(--color-surface-raised);
            border-color: var(--color-border-hover);
        }

        .toggle-password:focus-visible {
            outline: 2px solid var(--color-primary);
            outline-offset: 2px;
        }

        .toggle-password.visible {
            background: var(--color-primary);
            color: white;
            border-color: var(--color-primary);
        }

        .toggle-password.visible:hover {
            background: var(--color-primary-hover);
            border-color: var(--color-primary-hover);
        }

        .pin-input-wrapper {
            margin-top: var(--space-md);
            padding: var(--space-md);
            background: var(--color-surface-raised);
            border-radius: var(--radius-md);
            border: 1.5px solid var(--color-border);
            display: none;
        }

        .pin-input-wrapper.active {
            display: block;
        }

        .pin-input-wrapper input {
            width: 100%;
            padding: var(--space-md);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 1.2em;
            text-align: center;
            letter-spacing: 0.3em;
            font-family: monospace;
            background: var(--color-surface);
            color: var(--color-text);
        }

        .pin-input-wrapper input:focus {
            outline: 2px solid transparent;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px var(--color-primary-subtle);
        }

        .pin-input-wrapper small {
            display: block;
            margin-top: var(--space-sm);
            color: var(--color-text-muted);
            text-align: center;
        }

        /* ═══ Summary / confirmation step ═══ */

        .summary-grid {
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }

        .summary-section {
            padding: var(--space-md) var(--space-lg);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            transition: border-color 0.2s, background 0.2s;
            cursor: pointer;
        }

        .summary-section:hover {
            border-color: var(--color-border-hover);
            background: var(--color-surface-raised);
        }

        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-xs);
        }

        .summary-header strong {
            font-size: 0.88em;
            color: var(--color-text);
        }

        .summary-edit {
            background: none;
            border: none;
            color: var(--color-primary);
            font-size: 0.8em;
            font-weight: 600;
            cursor: pointer;
            padding: var(--space-xs) var(--space-sm);
            border-radius: var(--radius-sm);
            transition: background 0.2s;
        }

        .summary-edit:hover {
            background: var(--color-primary-subtle);
        }

        .summary-edit:focus-visible {
            outline: 2px solid var(--color-primary);
            outline-offset: 2px;
        }

        .summary-value {
            font-size: 0.88em;
            color: var(--color-text-muted);
            line-height: 1.5;
        }

        .summary-value code {
            background: var(--color-input-bg);
            padding: 1px var(--space-xs);
            border-radius: var(--radius-sm);
            font-size: 0.92em;
        }

        /* ═══ Progress modal ═══ */

        .setup-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: var(--space-lg);
            animation: backdropIn 0.3s ease both;
        }

        .setup-modal-backdrop.visible {
            display: flex;
        }

        @keyframes backdropIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        .setup-modal {
            background: var(--color-surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            padding: var(--space-xl) var(--space-2xl);
            max-width: 440px;
            width: 100%;
            animation: modalIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
            animation-delay: 0.1s;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: translateY(16px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .setup-modal-title {
            font-size: 1.2em;
            font-weight: 800;
            margin-bottom: var(--space-lg);
            color: var(--color-text);
        }

        .setup-modal-steps {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: var(--space-md);
        }

        .setup-modal-step {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            font-size: 0.92em;
            color: var(--color-text-muted);
            transition: color 0.3s;
        }

        .setup-modal-step.active {
            color: var(--color-text);
            font-weight: 600;
        }

        .setup-modal-step.done {
            color: var(--color-success);
        }

        .setup-modal-step.error {
            color: var(--color-error);
            font-weight: 600;
        }

        .step-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8em;
            font-weight: 700;
            flex-shrink: 0;
            border: 2px solid var(--color-border);
            color: var(--color-text-muted);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .setup-modal-step.active .step-icon {
            border-color: var(--color-primary);
            color: var(--color-primary);
            animation: stepPulse 1.2s ease-in-out infinite;
        }

        .setup-modal-step.done .step-icon {
            border-color: var(--color-success-border);
            background: var(--color-success-border);
            color: white;
            animation: none;
            transform: scale(1);
        }

        .setup-modal-step.error .step-icon {
            border-color: var(--color-error-border);
            background: var(--color-error-border);
            color: white;
            animation: none;
        }

        @keyframes stepPulse {
            0%, 100% { transform: scale(1); }
            50%      { transform: scale(1.1); }
        }

        .setup-modal-error {
            margin-top: var(--space-lg);
            padding: var(--space-md);
            background: var(--color-error-bg);
            border: 1.5px solid var(--color-error-border);
            border-radius: var(--radius-md);
            color: var(--color-error);
            font-size: 0.88em;
            line-height: 1.5;
            display: none;
        }

        .setup-modal-error.visible {
            display: block;
            animation: fieldReveal 0.3s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .setup-modal-composer {
            margin-top: var(--space-lg);
            padding: var(--space-md);
            background: var(--color-warning-bg);
            border: 1.5px solid var(--color-warning);
            border-radius: var(--radius-md);
            color: var(--color-text);
            font-size: 0.88em;
            line-height: 1.5;
            display: none;
        }

        .setup-modal-composer.visible {
            display: block;
            animation: fieldReveal 0.3s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .setup-modal-composer code {
            display: block;
            margin-top: var(--space-sm);
            padding: var(--space-sm) var(--space-md);
            background: rgba(128, 128, 128, 0.1);
            border-radius: var(--radius-sm);
            font-family: monospace;
            font-size: 0.95em;
        }

        .setup-modal-warnings {
            margin-top: var(--space-md);
            padding: var(--space-md);
            background: var(--color-warning-bg);
            border: 1.5px solid var(--color-warning);
            border-radius: var(--radius-md);
            color: var(--color-text);
            font-size: 0.85em;
            line-height: 1.5;
            display: none;
        }

        .setup-modal-warnings.visible {
            display: block;
            animation: fieldReveal 0.3s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .setup-modal-warnings strong {
            display: block;
            margin-bottom: var(--space-xs);
            color: var(--color-warning);
        }

        .setup-modal-warnings ul {
            margin: var(--space-xs) 0 0 var(--space-md);
            padding: 0;
        }

        .setup-modal-action {
            margin-top: var(--space-lg);
            display: none;
        }

        .setup-modal-action.visible {
            display: block;
            animation: fieldReveal 0.3s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .setup-modal-action .btn {
            width: 100%;
        }

        @media (max-width: 480px) {
            .setup-modal {
                padding: var(--space-lg);
            }
        }

        /* ═══ Inline help ═══ */

        .field-help {
            margin-top: var(--space-xs);
        }

        .field-help summary {
            font-size: 0.82em;
            color: var(--color-info);
            cursor: pointer;
            font-weight: 600;
            user-select: none;
            list-style: none;
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
        }

        .field-help summary::-webkit-details-marker {
            display: none;
        }

        .field-help summary::before {
            content: '?';
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--color-info-bg);
            color: var(--color-info);
            font-size: 0.85em;
            font-weight: 700;
            flex-shrink: 0;
            transition: background 0.2s;
        }

        .field-help[open] summary::before {
            background: var(--color-info);
            color: white;
        }

        .field-help .help-content {
            margin-top: var(--space-sm);
            padding: var(--space-md);
            background: var(--color-info-bg);
            border-radius: var(--radius-md);
            font-size: 0.84em;
            line-height: 1.6;
            color: var(--color-text);
            animation: fieldReveal 0.25s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .field-help .help-content strong {
            color: var(--color-text);
        }

        .field-help .help-content code {
            background: rgba(128, 128, 128, 0.1);
            padding: 1px var(--space-xs);
            border-radius: var(--radius-sm);
            font-size: 0.92em;
        }

        .field-help .help-content ul {
            margin: var(--space-xs) 0 0 var(--space-lg);
        }

        .ext-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: var(--space-xs);
        }

        .ext-item {
            display: flex;
            align-items: flex-start;
            gap: var(--space-xs);
            padding: var(--space-xs) 0;
        }

        .ext-icon {
            flex-shrink: 0;
            font-weight: 700;
        }

        .ext-item small {
            display: block;
            opacity: 0.7;
            font-size: 0.82em;
        }

        /* ═══ Footer ═══ */

        .setup-footer {
            padding: var(--space-md) var(--space-lg);
            border-top: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-lg);
            flex-wrap: wrap;
            margin-top: auto;
            color: var(--color-text-muted);
            font-size: 0.78em;
        }

        .setup-footer-brand {
            font-size: 0.8em;
            color: var(--color-text-muted);
            font-weight: 600;
        }

        .setup-footer-links {
            display: flex;
            gap: var(--space-lg);
            list-style: none;
        }

        .setup-footer-links a {
            font-size: 0.82em;
            color: var(--color-text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            min-height: 44px;
        }

        .setup-footer-links a:hover {
            color: var(--color-text);
        }

        .setup-footer-links svg {
            width: 16px;
            height: 16px;
            fill: currentColor;
            flex-shrink: 0;
        }

        @media (max-width: 480px) {
            .setup-footer {
                flex-direction: column;
                text-align: center;
                padding: var(--space-md) var(--space-lg);
            }

            .setup-footer-links {
                gap: var(--space-md);
            }
        }

        /* ═══ Inline validation ═══ */

        .form-group.has-error input,
        .form-group.has-error .password-wrapper input {
            border-color: var(--color-error-border);
            box-shadow: 0 0 0 3px var(--color-error-bg);
        }

        .form-group.has-error label {
            color: var(--color-error);
        }

        .validation-msg {
            display: none;
            font-size: 0.84em;
            font-weight: 600;
            color: var(--color-error);
            margin-top: var(--space-xs);
            animation: validationIn 0.25s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .form-group.has-error .validation-msg {
            display: block;
        }

        @keyframes validationIn {
            from {
                opacity: 0;
                transform: translateY(-4px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Shake the input on validation fail */
        .form-group.shake input,
        .form-group.shake .password-wrapper input {
            animation: inputShake 0.4s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
        }

        @keyframes inputShake {

            10%,
            90% {
                transform: translateX(-1px);
            }

            20%,
            80% {
                transform: translateX(2px);
            }

            30%,
            50%,
            70% {
                transform: translateX(-3px);
            }

            40%,
            60% {
                transform: translateX(3px);
            }
        }

        /* ═══ Delight — micro-interactions ═══ */


        /* Form group subtle hover */
        .form-group {
            padding: var(--space-sm) var(--space-md);
            margin-left: calc(-1 * var(--space-md));
            margin-right: calc(-1 * var(--space-md));
            border-radius: var(--radius-md);
            transition: background 0.2s;
        }

        .form-group:focus-within {
            background: var(--color-primary-subtle);
        }

        /* Requirement check animation */
        .requirement-box {
            transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
        }


        /* DB test result slide-in */
        .db-test-result[style*="display: block"] {
            animation: resultSlide 0.35s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        @keyframes resultSlide {
            from {
                opacity: 0;
                transform: translateY(-8px);
                max-height: 0;
            }

            to {
                opacity: 1;
                transform: translateY(0);
                max-height: 100px;
            }
        }


        /* ═══ Optimize — performance hints ═══ */

        .wizard-fill {
            will-change: transform;
        }

        .wizard-step {
            contain: layout style;
        }

        .wizard-dot-icon {
            will-change: transform;
        }

        /* ══════════════════════════════════════
           WIZARD — Cinematic step flow
           ══════════════════════════════════════ */
        :root {
            --spring-bounce: cubic-bezier(0.34, 1.56, 0.64, 1);
            --spring-smooth: cubic-bezier(0.22, 1, 0.36, 1);
        }

        .wizard-progress {
            margin-bottom: var(--space-xl);
            position: relative;
            padding: 0 var(--space-sm);
        }

        .wizard-dots {
            display: flex;
            justify-content: space-between;
            position: relative;
        }

        .wizard-track {
            position: absolute;
            top: 18px;
            left: 36px;
            right: 36px;
            height: 3px;
            background: var(--color-border);
            border-radius: 2px;
            overflow: hidden;
        }

        .wizard-fill {
            height: 100%;
            width: 100%;
            background: var(--color-primary);
            border-radius: 2px;
            transform-origin: left;
            /* Animated by JS spring solver */
        }

        .wizard-dot {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--space-sm);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            z-index: 1;
            outline: none;
            -webkit-tap-highlight-color: transparent;
        }

        .wizard-dot:focus-visible .wizard-dot-icon {
            outline: 2px solid var(--color-primary);
            outline-offset: 3px;
        }

        .wizard-dot[disabled] {
            cursor: default;
            pointer-events: none;
        }

        .wizard-dot-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82em;
            font-weight: 700;
            background: var(--color-surface);
            border: 2.5px solid var(--color-border);
            color: var(--color-text-muted);
            transition: transform 0.5s var(--spring-bounce),
                background 0.3s,
                border-color 0.3s,
                color 0.3s,
                box-shadow 0.3s;
        }

        .wizard-dot.active .wizard-dot-icon {
            border-color: var(--color-primary);
            background: var(--color-primary);
            color: white;
            transform: scale(1.25);
            box-shadow: 0 0 0 4px var(--color-primary-subtle);
        }

        .wizard-dot.completed .wizard-dot-icon {
            border-color: var(--color-success-border);
            background: var(--color-success-border);
            color: white;
            transform: scale(1);
        }

        .wizard-dot-label {
            font-size: 0.7em;
            font-weight: 600;
            color: var(--color-text-muted);
            transition: color 0.3s;
            white-space: nowrap;
        }

        .wizard-dot.active .wizard-dot-label {
            color: var(--color-primary);
        }

        .wizard-dot.completed .wizard-dot-label {
            color: var(--color-success);
        }

        /* Step containers */
        .wizard-step {
            display: none;
        }

        .wizard-step.active {
            display: block;
        }

        /* Step transition animations */
        @keyframes stepExitLeft {
            from {
                opacity: 1;
                transform: translateX(0);
            }

            to {
                opacity: 0;
                transform: translateX(-50px);
            }
        }

        @keyframes stepExitRight {
            from {
                opacity: 1;
                transform: translateX(0);
            }

            to {
                opacity: 0;
                transform: translateX(50px);
            }
        }

        @keyframes stepEnterRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes stepEnterLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Field stagger — children cascade in after step entrance */
        .wizard-step[data-entering]>* {
            animation: fieldReveal 0.45s var(--spring-smooth) both;
        }

        .wizard-step[data-entering]> :nth-child(1) {
            animation-delay: 60ms;
        }

        .wizard-step[data-entering]> :nth-child(2) {
            animation-delay: 120ms;
        }

        .wizard-step[data-entering]> :nth-child(3) {
            animation-delay: 180ms;
        }

        .wizard-step[data-entering]> :nth-child(4) {
            animation-delay: 240ms;
        }

        .wizard-step[data-entering]> :nth-child(5) {
            animation-delay: 300ms;
        }

        .wizard-step[data-entering]> :nth-child(6) {
            animation-delay: 360ms;
        }

        .wizard-step[data-entering]> :nth-child(7) {
            animation-delay: 420ms;
        }

        .wizard-step[data-entering]> :nth-child(8) {
            animation-delay: 480ms;
        }

        @keyframes fieldReveal {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Navigation buttons */
        .wizard-nav {
            display: flex;
            gap: var(--space-md);
            margin-top: var(--space-xl);
            padding-top: var(--space-lg);
            border-top: 1px solid var(--color-border);
        }

        .wizard-nav-btn {
            padding: 14px 32px;
            border: 1.5px solid transparent;
            border-radius: var(--radius-md);
            font-size: 0.95em;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s, color 0.2s, transform 0.2s, box-shadow 0.2s;
            outline: none;
            min-height: 48px;
            letter-spacing: 0.01em;
        }

        .wizard-nav-btn:focus-visible {
            outline: 2px solid var(--color-primary);
            outline-offset: 2px;
        }

        .wizard-nav-btn--back {
            background: transparent;
            border-color: var(--color-border);
            color: var(--color-text-muted);
        }

        .wizard-nav-btn--back:hover {
            border-color: var(--color-text-muted);
            color: var(--color-text);
            background: var(--color-input-bg);
        }

        .wizard-nav-btn--next {
            background: var(--color-primary);
            color: white;
            margin-left: auto;
            position: relative;
            overflow: hidden;
        }

        .wizard-nav-btn--next:hover:not(:disabled) {
            background: var(--color-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--color-primary-subtle);
        }

        .wizard-nav-btn--next:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: none;
        }

        .wizard-nav-btn--next:disabled {
            background: var(--color-border);
            color: var(--color-text-muted);
            cursor: not-allowed;
        }


        /* Submit button special state */
        .wizard-nav-btn--submit {
            padding: 14px 40px;
            font-size: 1.05em;
        }

        .wizard-nav-btn--submit.submitting {
            pointer-events: none;
        }

        .wizard-nav-btn--submit.submitting::before {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: var(--space-sm);
            vertical-align: middle;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Step entrance — initial page load */
        .wizard-step.initial-reveal>* {
            animation: fieldReveal 0.5s var(--spring-smooth) both;
        }

        .wizard-step.initial-reveal> :nth-child(1) {
            animation-delay: 100ms;
        }

        .wizard-step.initial-reveal> :nth-child(2) {
            animation-delay: 200ms;
        }

        .wizard-step.initial-reveal> :nth-child(3) {
            animation-delay: 300ms;
        }

        .wizard-step.initial-reveal> :nth-child(4) {
            animation-delay: 400ms;
        }

        /* Each wizard step is a wide content panel with slight inset */
        .wizard-step.active {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-sm);
            padding: var(--space-lg) var(--space-xl);
        }

        .wizard-step .section-title {
            font-size: 1.15em;
            color: var(--color-primary);
            border-bottom: 1px solid var(--color-border);
            margin-top: 0;
            padding-bottom: var(--space-sm);
            margin-bottom: var(--space-lg);
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        @media (max-width: 768px) {
            .wizard-dot-label {
                font-size: 0.6em;
            }

            .wizard-dot-icon {
                width: 30px;
                height: 30px;
                font-size: 0.75em;
            }

            .wizard-track {
                top: 15px;
                left: 28px;
                right: 28px;
            }

            .wizard-nav {
                flex-direction: column-reverse;
                gap: var(--space-sm);
            }

            .wizard-nav-btn--next {
                margin-left: 0;
                width: 100%;
                text-align: center;
            }

            .wizard-nav-btn--back {
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .wizard-dot-label {
                display: none;
            }

            .wizard-progress {
                margin-bottom: var(--space-lg);
            }
        }

        /* Reduced motion — respect user preference */
        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>
    <main class="container">
        <header class="setup-header">
            <img src="https://web-assets.emergencyforge.de/images/defaultLogo.webp" alt="ıgnıs" class="setup-logo">
            <p class="setup-tagline"><em><strong>ıgnıs</strong></em>. Struktur für jeden Einsatz.</p>
        </header>

        <div class="content">

            <!-- WBB-style page title + thin progress bar -->
            <div class="page-title">
                <h2>ıgnıs Installation</h2>
                <div class="progress-thin">
                    <div class="progress-thin-fill" id="progress-thin-fill"></div>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" role="alert">
                    <strong>Fehler:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="alert-error-log">
                        <small>Fehler wurden in <code>setup_error.log</code> protokolliert</small>
                    </div>
                </div>
                <?php if (!empty($success)): ?>
                    <div class="info-box">
                        <strong>Teilweise erfolgreich</strong>
                        Einige Schritte wurden erfolgreich abgeschlossen. Bitte beheben Sie die oben genannten Fehler oder fahren Sie manuell fort.
                    </div>
                    <?php if ($canProceed): ?>
                        <a href="?force_delete=confirm" class="btn btn-secondary" onclick="return confirm('Sind Sie sicher, dass Sie setup.php löschen möchten? Stellen Sie sicher, dass alle wichtigen Konfigurationen vorgenommen wurden.')">Verstanden, setup.php löschen und fortfahren</a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($success) && empty($errors)): ?>
                <div class="alert alert-success">
                    <strong>Erfolg:</strong>
                    <ul>
                        <?php foreach ($success as $msg): ?>
                            <li><?php echo $msg; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="setup-form" novalidate>
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- Step 0: System-Anforderungen (Gate) -->
                <section class="wizard-step active initial-reveal" data-step="0" aria-label="System-Anforderungen">
                    <h2 class="section-title">System-Anforderungen</h2>

                    <?php if (!$canProceed): ?>
                        <div class="alert alert-error" role="alert">
                            <strong>SETUP BLOCKIERT</strong>
                            <p>Das Setup kann nicht fortgesetzt werden, da wichtige System-Anforderungen nicht erfüllt sind.</p>
                        </div>
                    <?php endif; ?>

                    <div class="requirements-grid">
                        <div class="requirement-box <?php echo $phpVersionOk ? 'success' : 'error'; ?>">
                            <span class="requirement-icon" aria-hidden="true"><?php echo $phpVersionOk ? '✓' : '✗'; ?></span>
                            <div class="requirement-detail">
                                <strong class="requirement-title">PHP Version</strong>
                                <div class="requirement-status">
                                    <?php if ($phpVersionOk): ?>
                                        Installiert: <strong><?php echo $phpVersion; ?></strong>
                                        <div class="requirement-sub">Erforderlich: >= <?php echo $requiredPhpVersion; ?></div>
                                    <?php else: ?>
                                        <div class="requirement-fix">Installiert: <strong><?php echo $phpVersion; ?></strong></div>
                                        <div class="requirement-fix-inner">
                                            <strong>Erforderlich: >= <?php echo $requiredPhpVersion; ?></strong><br>
                                            <small>Bitte aktualisieren Sie PHP über Ihr Hosting-Panel</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="requirement-box <?php echo $gitAvailable ? 'success' : 'error'; ?>">
                            <span class="requirement-icon" aria-hidden="true"><?php echo $gitAvailable ? '✓' : '—'; ?></span>
                            <div class="requirement-detail">
                                <strong class="requirement-title">Git</strong>
                                <div class="requirement-status">
                                    <?php if ($gitAvailable): ?>
                                        <strong>Verfügbar</strong>
                                        <div class="requirement-sub">Nur für Main/Custom Branch benötigt</div>
                                    <?php else: ?>
                                        <strong>Nicht verfügbar</strong>
                                        <div class="requirement-sub">Für Release-Installation nicht erforderlich</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php $dlMethodOk = $curlAvailable || $allowUrlFopen; ?>
                        <div class="requirement-box <?php echo $dlMethodOk ? 'success' : 'error'; ?>">
                            <span class="requirement-icon" aria-hidden="true"><?php echo $dlMethodOk ? '✓' : '✗'; ?></span>
                            <div class="requirement-detail">
                                <strong class="requirement-title">Download</strong>
                                <div class="requirement-status">
                                    <?php if ($curlAvailable): ?>
                                        <strong>cURL verfügbar</strong>
                                        <div class="requirement-sub">Optimale Methode für große Downloads</div>
                                    <?php elseif ($allowUrlFopen): ?>
                                        <strong>allow_url_fopen aktiv</strong>
                                        <div class="requirement-sub">Funktioniert, cURL wäre performanter</div>
                                    <?php else: ?>
                                        <div class="requirement-fix-inner">
                                            <strong>Keine Download-Methode verfügbar!</strong><br>
                                            <small>cURL-Extension aktivieren oder allow_url_fopen=1 setzen</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="requirement-box <?php echo $setupDirWritable ? 'success' : 'error'; ?>">
                            <span class="requirement-icon" aria-hidden="true"><?php echo $setupDirWritable ? '✓' : '✗'; ?></span>
                            <div class="requirement-detail">
                                <strong class="requirement-title">Schreibrechte</strong>
                                <div class="requirement-status">
                                    <?php if ($setupDirWritable): ?>
                                        <strong>Setup-Verzeichnis beschreibbar</strong>
                                        <div class="requirement-sub">ZIP-Extract + .env-Schreiben möglich</div>
                                    <?php else: ?>
                                        <div class="requirement-fix-inner">
                                            <strong>Verzeichnis nicht beschreibbar!</strong><br>
                                            <small>Bitte Schreibrechte auf <code><?php echo htmlspecialchars($setupDir); ?></code> setzen (chmod 0775).</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PHP-Extensions -->
                    <?php if ($allExtensionsOk): ?>
                        <div class="alert alert-success">
                            <strong>PHP-Extensions:</strong> Alle <?php echo count($extensionStatus); ?> benötigten Extensions sind vorhanden.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error">
                            <strong>Fehlende PHP-Extensions:</strong>
                            <span><?php echo implode(', ', array_map('htmlspecialchars', $missingRequired)); ?></span>
                            <small style="display: block; margin-top: var(--space-xs); opacity: 0.8;">Aktivierung über Hosting-Panel oder <code>php.ini</code>.</small>
                        </div>
                    <?php endif; ?>

                    <!-- Extension-Details aufklappbar -->
                    <details class="field-help" style="margin-bottom: var(--space-lg);">
                        <summary>Alle Extensions anzeigen</summary>
                        <div class="help-content">
                            <div class="ext-grid">
                                <?php foreach ($extensionStatus as $ext => $info): ?>
                                    <div class="ext-item">
                                        <span class="ext-icon" style="color: <?php echo $info['ok'] ? 'var(--color-success)' : 'var(--color-error)'; ?>">
                                            <?php echo $info['ok'] ? '✓' : '✗'; ?>
                                        </span>
                                        <div>
                                            <code><?php echo htmlspecialchars($ext); ?></code>
                                            <small><?php echo htmlspecialchars($info['purpose']); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>

                    <!-- PHP-Limits -->
                    <?php if (!$phpLimitsOk): ?>
                        <div class="alert alert-warning">
                            <strong>PHP-Konfiguration anpassen empfohlen</strong> — Das Release-ZIP ist ca. 100 MB groß.
                            <ul style="margin-top: var(--space-xs); margin-left: var(--space-lg);">
                                <?php foreach ($phpLimitWarnings as $w): ?>
                                    <li><code><?php echo $w; ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                            <small style="display: block; margin-top: var(--space-xs); opacity: 0.8;">Anpassung in <code>php.ini</code> oder <code>.htaccess</code>.</small>
                        </div>
                    <?php endif; ?>

                    <div class="wizard-nav">
                        <?php if ($devMode && !$canProceed): ?>
                            <button type="button" class="wizard-nav-btn wizard-nav-btn--back" data-wizard-next style="border-color: var(--color-warning); color: var(--color-warning);" onclick="if(!confirm('DEV: Anforderungen nicht erfüllt — trotzdem fortfahren?'))return false;">
                                DEV: Überspringen
                            </button>
                        <?php endif; ?>
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--next" data-wizard-next <?php echo !$canProceed && !$devMode ? 'disabled' : ''; ?>>
                            <?php echo $canProceed ? 'Weiter' : 'Anforderungen nicht erfüllt'; ?>
                        </button>
                    </div>
                </section>

                <!-- Step 1: Git Repository -->
                <section class="wizard-step" data-step="1" aria-label="Git Repository">
                    <h2 class="section-title">Git Repository</h2>

                    <div class="form-group">
                        <label>Version auswählen</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="git_branch" value="release" checked>
                                <span>
                                    <div>
                                        <strong>Letzter Release</strong>
                                        <span class="warning-badge warning-badge--success">EMPFOHLEN</span>
                                    </div>
                                    <small>Stabile Version als ZIP herunterladen - empfohlen für Produktivumgebungen</small>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="git_branch" value="main">
                                <span>
                                    <div>
                                        <strong>Main Branch</strong>
                                        <span class="warning-badge">EXPERIMENTELL</span>
                                    </div>
                                    <small>Neueste Entwicklungsversion via Git - kann instabil sein (Git + Composer erforderlich)</small>
                                </span>
                            </label>
                            <?php if ($devMode): ?>
                                <label>
                                    <input type="radio" name="git_branch" value="custom" id="custom_branch_radio">
                                    <span>
                                        <div>
                                            <strong>Custom Branch</strong>
                                            <span class="warning-badge warning-badge--dev">DEV</span>
                                        </div>
                                        <small>Eigenen Branch angeben via Git · für Entwicklung (Git + Composer erforderlich)</small>
                                    </span>
                                </label>
                            <?php endif; ?>
                        </div>
                        <?php if ($devMode): ?>
                            <div class="custom-branch-input" id="custom_branch_input">
                                <input type="text" name="custom_branch" placeholder="z.B. feature/neue-funktion" id="custom_branch_field">
                            </div>
                        <?php endif; ?>
                        <small>Repository: <code>github.com/EmergencyForge/intraRP</code></small>
                    </div>

                    <div class="wizard-nav">
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--back" data-wizard-prev>Zurück</button>
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--next" data-wizard-next>Weiter</button>
                    </div>
                </section>

                <!-- Step 2: Datenbank-Konfiguration -->
                <section class="wizard-step" data-step="2" aria-label="Datenbank-Konfiguration">
                    <h2 class="section-title">Datenbank-Konfiguration</h2>

                    <div class="form-group">
                        <label for="db_host">Datenbank-Host *</label>
                        <input type="text" id="db_host" name="db_host" value="localhost" required autocomplete="off" aria-describedby="db_host-error">
                        <small>Host der Datenbank (meistens <code>localhost</code>)</small>
                        <details class="field-help">
                            <summary>Was ist der Datenbank-Host?</summary>
                            <div class="help-content">
                                Die Adresse des MySQL-Servers. Bei den meisten Hostern ist das <code>localhost</code>. Manche Anbieter nutzen eigene Adressen wie <code>db1234.hosting.de</code> — prüfen Sie die Angaben in Ihrem Hosting-Panel.
                            </div>
                        </details>
                        <span class="validation-msg" id="db_host-error" role="alert">Datenbank-Host ist erforderlich.</span>
                    </div>

                    <div class="form-group">
                        <label for="db_port">Datenbank-Port</label>
                        <input type="text" id="db_port" name="db_port" value="3306" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                        <small>Port des MySQL-Servers — Standard ist <code>3306</code>, nur ändern wenn dein Hoster einen anderen Port nutzt</small>
                    </div>

                    <div class="form-group">
                        <label for="db_user">Datenbank-Benutzer *</label>
                        <input type="text" id="db_user" name="db_user" value="root" required autocomplete="off" aria-describedby="db_user-error">
                        <small>Benutzername für die Datenbank</small>
                        <span class="validation-msg" id="db_user-error" role="alert">Datenbank-Benutzer ist erforderlich.</span>
                    </div>

                    <div class="form-group">
                        <label for="db_pass">Datenbank-Passwort</label>
                        <div class="password-wrapper">
                            <input type="password" id="db_pass" name="db_pass" autocomplete="off">
                            <button type="button" class="toggle-password" aria-pressed="false" aria-label="Passwort anzeigen" onclick="togglePassword('db_pass', this)">Anzeigen</button>
                        </div>
                        <small>Passwort für die Datenbank (optional)</small>
                    </div>

                    <div class="form-group">
                        <label for="db_name">Datenbank-Name *</label>
                        <input type="text" id="db_name" name="db_name" value="intrarp" required autocomplete="off" aria-describedby="db_name-error">
                        <small>Name der zu verwendenden Datenbank</small>
                        <details class="field-help">
                            <summary>Muss die Datenbank schon existieren?</summary>
                            <div class="help-content">
                                <strong>Ja</strong> — die Datenbank muss bereits auf dem Server angelegt sein, die Tabellen werden aber automatisch erstellt. Sie können eine neue Datenbank über phpMyAdmin oder das Hosting-Panel anlegen.
                            </div>
                        </details>
                        <span class="validation-msg" id="db_name-error" role="alert">Datenbank-Name ist erforderlich.</span>
                    </div>

                    <div class="form-group" id="db-test-group">
                        <button type="button" class="btn-test-db" id="btn-test-db" onclick="testDatabaseConnection()">Verbindung testen</button>
                        <div class="db-test-result" id="db-test-result"></div>
                        <span class="validation-msg" id="db-test-validation" role="alert">Bitte testen Sie die Datenbankverbindung bevor Sie fortfahren.</span>
                    </div>

                    <div class="wizard-nav">
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--back" data-wizard-prev>Zurück</button>
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--next" data-wizard-next>Weiter</button>
                    </div>
                </section>

                <!-- Step 3: System-Konfiguration -->
                <section class="wizard-step" data-step="3" aria-label="System-Konfiguration">
                    <h2 class="section-title">System-Konfiguration</h2>

                    <div class="form-group">
                        <label for="domain">Domain *</label>
                        <input type="text" id="domain" name="domain" value="<?php echo htmlspecialchars($defaultDomain); ?>" required aria-describedby="domain-error">
                        <small>Die Domain unter der das System erreichbar ist (ohne http/https)</small>
                        <span class="validation-msg" id="domain-error" role="alert">Domain ist erforderlich.</span>
                    </div>

                    <div class="form-group">
                        <label for="base_path">Base Path *</label>
                        <input type="text" id="base_path" name="base_path" value="<?php echo htmlspecialchars($defaultBasePath); ?>" required aria-describedby="base_path-error">
                        <small>Der Pfad zur Installation (z.B. <code>/</code> für Root oder <code>/intrarp/</code> für Unterverzeichnis)</small>
                        <details class="field-help">
                            <summary>Welchen Pfad soll ich eintragen?</summary>
                            <div class="help-content">
                                Der Base Path hängt davon ab, wo die <code>setup.php</code> liegt:
                                <ul>
                                    <li>Direkt im Webroot → <code>/</code></li>
                                    <li>Im Ordner <code>intrarp</code> → <code>/intrarp/</code></li>
                                    <li>In einem Unterordner → <code>/ordner/unterordner/</code></li>
                                </ul>
                                Der Wert wurde automatisch erkannt. Ändern Sie ihn nur, wenn er nicht stimmt.
                            </div>
                        </details>
                        <span class="validation-msg" id="base_path-error" role="alert">Base Path ist erforderlich.</span>
                    </div>

                    <div class="info-box" id="url-preview-box">
                        <strong>Ihre System-URL</strong>
                        <span id="url-preview" style="font-family: monospace; font-size: 1.05em; word-break: break-all;"></span>
                    </div>

                    <div class="wizard-nav">
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--back" data-wizard-prev>Zurück</button>
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--next" data-wizard-next>Weiter</button>
                    </div>
                </section>

                <!-- Step 4: Discord-Integration -->
                <section class="wizard-step" data-step="4" aria-label="Discord-Integration">
                    <h2 class="section-title">Discord-Integration</h2>

                    <div class="info-box">
                        <strong>Discord Applikation benötigt</strong>
                        Für die Discord-Integration muss eine Discord-Applikation erstellt werden. Eine detaillierte Anleitung finden Sie hier:
                        <a href="https://wiki.emergencyforge.de/erste-schritte/discord-app-erstellen/" target="_blank" rel="noopener noreferrer">Discord-Applikation erstellen →</a>
                    </div>

                    <div class="info-box" id="redirect-uri-box" style="border-color: var(--color-success-border); color: var(--color-success);">
                        <strong>Redirect-URI für die Discord-App</strong>
                        Tragen Sie folgende URL als Redirect-URI in Ihrer Discord-Applikation ein:
                        <code id="redirect-uri" style="display: block; margin-top: var(--space-sm); padding: var(--space-sm) var(--space-md); background: rgba(128,128,128,0.1); border-radius: var(--radius-sm); font-size: 0.95em; word-break: break-all; user-select: all; cursor: text;"></code>
                    </div>

                    <div class="form-group">
                        <label for="discord_client_id">Discord Client ID *</label>
                        <input type="text" id="discord_client_id" name="discord_client_id" required autocomplete="off" aria-describedby="discord_client_id-error">
                        <small>Client ID der Discord-Anwendung</small>
                        <details class="field-help">
                            <summary>Wo finde ich die Client ID?</summary>
                            <div class="help-content">
                                Öffnen Sie das <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer" style="color: var(--color-info); font-weight: 600;">Discord Developer Portal</a>, wählen Sie Ihre Applikation und kopieren Sie die <strong>Application ID</strong> von der Übersichtsseite. Das ist eine lange Zahlenkette (z.B. <code>123456789012345678</code>).
                            </div>
                        </details>
                        <span class="validation-msg" id="discord_client_id-error" role="alert">Discord Client ID ist erforderlich.</span>
                    </div>

                    <div class="form-group">
                        <label for="discord_client_secret">Discord Client Secret *</label>
                        <div class="password-wrapper">
                            <input type="password" id="discord_client_secret" name="discord_client_secret" required autocomplete="off" aria-describedby="discord_client_secret-error">
                            <button type="button" class="toggle-password" aria-pressed="false" aria-label="Passwort anzeigen" onclick="togglePassword('discord_client_secret', this)">Anzeigen</button>
                        </div>
                        <small>Client Secret der Discord-Anwendung</small>
                        <details class="field-help">
                            <summary>Wo finde ich das Client Secret?</summary>
                            <div class="help-content">
                                Im Discord Developer Portal unter Ihrer Applikation → <strong>OAuth2</strong> → <strong>Client Secret</strong>. Klicken Sie auf „Reset Secret" wenn Sie es noch nie kopiert haben. <strong>Achtung:</strong> Das Secret wird nur einmal angezeigt — speichern Sie es sicher ab.
                            </div>
                        </details>
                        <span class="validation-msg" id="discord_client_secret-error" role="alert">Discord Client Secret ist erforderlich.</span>
                    </div>

                    <div class="form-group" id="discord-test-group">
                        <button type="button" class="btn-test-db" id="btn-test-discord" onclick="testDiscordCredentials()">Discord-Verbindung testen</button>
                        <div class="db-test-result" id="discord-test-result"></div>
                        <small>Prüft per OAuth2 Client-Credentials-Grant ob Client ID und Secret gültig sind.</small>
                    </div>

                    <div class="wizard-nav">
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--back" data-wizard-prev>Zurück</button>
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--next" data-wizard-next>Weiter</button>
                    </div>
                </section>

                <!-- Step 5: Übersicht + Submit -->
                <section class="wizard-step" data-step="5" aria-label="Übersicht">
                    <h2 class="section-title">Übersicht</h2>

                    <p style="color: var(--color-text-muted); margin-bottom: var(--space-lg); font-size: 0.92em;">Bitte überprüfen Sie Ihre Eingaben. Klicken Sie auf einen Abschnitt, um ihn zu bearbeiten.</p>

                    <div class="summary-grid" id="summary-grid">
                        <div class="summary-section" data-jump="1">
                            <div class="summary-header">
                                <strong>Version</strong>
                                <button type="button" class="summary-edit" aria-label="Version bearbeiten">Ändern</button>
                            </div>
                            <div class="summary-value" id="summary-git"></div>
                        </div>

                        <div class="summary-section" data-jump="2">
                            <div class="summary-header">
                                <strong>Datenbank</strong>
                                <button type="button" class="summary-edit" aria-label="Datenbank bearbeiten">Ändern</button>
                            </div>
                            <div class="summary-value" id="summary-db"></div>
                        </div>

                        <div class="summary-section" data-jump="3">
                            <div class="summary-header">
                                <strong>System</strong>
                                <button type="button" class="summary-edit" aria-label="System bearbeiten">Ändern</button>
                            </div>
                            <div class="summary-value" id="summary-system"></div>
                        </div>

                        <div class="summary-section" data-jump="4">
                            <div class="summary-header">
                                <strong>Discord</strong>
                                <button type="button" class="summary-edit" aria-label="Discord bearbeiten">Ändern</button>
                            </div>
                            <div class="summary-value" id="summary-discord"></div>
                        </div>
                    </div>

                    <div class="wizard-nav">
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--back" data-wizard-prev>Zurück</button>
                        <button type="submit" class="wizard-nav-btn wizard-nav-btn--next wizard-nav-btn--submit" id="submit-btn" <?php echo !$canProceed ? 'disabled' : ''; ?>>Setup durchführen</button>
                    </div>
                </section>

            </form>
        </div>

        <!-- Progress modal -->
        <div class="setup-modal-backdrop" id="setup-modal" role="dialog" aria-modal="true" aria-label="Setup-Fortschritt">
            <div class="setup-modal">
                <div class="setup-modal-title" id="modal-title">Setup wird durchgeführt...</div>
                <ul class="setup-modal-steps">
                    <li class="setup-modal-step" data-modal-step="connect">
                        <span class="step-icon">1</span>
                        <span>Verbindung zu GitHub...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="download">
                        <span class="step-icon">2</span>
                        <span id="modal-step-download">Release wird heruntergeladen (~100 MB)...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="install">
                        <span class="step-icon">3</span>
                        <span id="modal-step-install">Dateien werden installiert...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="composer">
                        <span class="step-icon">4</span>
                        <span id="modal-step-composer">Composer-Pakete werden installiert...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="migrate">
                        <span class="step-icon">5</span>
                        <span>Datenbank-Migrations werden ausgeführt...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="config">
                        <span class="step-icon">6</span>
                        <span>Konfiguration wird geschrieben...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="done">
                        <span class="step-icon">7</span>
                        <span>Abschluss</span>
                    </li>
                </ul>
                <div class="setup-modal-error" id="modal-error" role="alert"></div>
                <div class="setup-modal-composer" id="modal-composer">
                    <strong>Composer muss manuell ausgeführt werden</strong>
                    <p>Der Server konnte Composer nicht automatisch installieren (<code>exec()</code> gesperrt oder Timeout). Bitte führe im Projekt-Root folgenden Befehl aus:</p>
                    <code>php composer.phar install --no-dev --optimize-autoloader</code>
                </div>
                <div class="setup-modal-warnings" id="modal-warnings"></div>
                <div class="setup-modal-action" id="modal-action">
                    <a href="index.php" class="btn">Weiter zum System</a>
                </div>
            </div>
        </div>

        <footer class="setup-footer">
            <ul class="setup-footer-links">
                <li>
                    <a href="https://emergencyforge.de" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" />
                        </svg>
                        Website
                    </a>
                </li>
                <li>
                    <a href="https://github.com/EmergencyForge" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" />
                        </svg>
                        GitHub
                    </a>
                </li>
                <li>
                    <a href="https://discord.gg/emergencyforge" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03z" />
                        </svg>
                        Discord
                    </a>
                </li>
            </ul>
        </footer>
    </main>

    <script>
        (function() {
            'use strict';

            // ─── Configuration ───
            const TOTAL_STEPS = 6;
            const EXIT_DURATION = 220;
            const ENTER_DURATION = 380;
            const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const DEV_MODE = window.location.search.includes('dev');
            // CSRF_TOKEN ist mutable, damit `postWithCsrfRetry` einen
            // vom Server frisch ausgegebenen Token übernehmen kann.
            let CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

            /**
             * POSTet `formData` an das Setup-Skript. Erkennt eine
             * Failure-Response mit `csrf_retry: true`, übernimmt den
             * mitgelieferten neuen Token in `CSRF_TOKEN` und sendet den
             * Request einmal nach. So bleibt der User-Flow nach einem
             * abgelaufenen Session-Token unterbrechungsfrei.
             */
            async function postWithCsrfRetry(formData) {
                formData.set('_token', CSRF_TOKEN);
                let response = await fetch(window.location.href, { method: 'POST', body: formData });
                let data = await response.json();
                if (data && data.csrf_retry === true && data.csrf_token) {
                    CSRF_TOKEN = data.csrf_token;
                    formData.set('_token', CSRF_TOKEN);
                    response = await fetch(window.location.href, { method: 'POST', body: formData });
                    data = await response.json();
                }
                return data;
            }

            // ─── DOM refs ───
            const steps = document.querySelectorAll('.wizard-step');
            const dots = []; // dots removed — using thin progress bar only
            const fill = null;
            const form = document.getElementById('setup-form');
            let currentStep = 0;
            let isTransitioning = false;
            let dbTestPassed = false;
            let discordTestPassed = false;

            // ─── Progress bar ───
            let currentFillValue = 0;

            function updateProgress(targetStep) {
                const targetFill = targetStep / (TOTAL_STEPS - 1);

                // Update thin progress bar only
                var thinFill = document.getElementById('progress-thin-fill');
                if (thinFill) thinFill.style.width = (targetFill * 100) + '%';
                currentFillValue = targetFill;
            }

            // ─── Step navigation ───
            function goToStep(newIndex) {
                if (newIndex === currentStep || newIndex < 0 || newIndex >= TOTAL_STEPS || isTransitioning) return;

                const oldStep = steps[currentStep];
                const newStep = steps[newIndex];
                const forward = newIndex > currentStep;

                if (REDUCED_MOTION) {
                    oldStep.classList.remove('active');
                    oldStep.style.display = 'none';
                    newStep.style.display = 'block';
                    newStep.classList.add('active');
                    currentStep = newIndex;
                    updateProgress(newIndex);
                    if (newIndex === TOTAL_STEPS - 1) populateSummary();
                    if (newIndex === 4) updateUrlPreview();
                    newStep.scrollIntoView({
                        block: 'nearest'
                    });
                    return;
                }

                isTransitioning = true;

                // Animate old step out
                oldStep.style.animation = forward ?
                    'stepExitLeft ' + EXIT_DURATION + 'ms ease-in forwards' :
                    'stepExitRight ' + EXIT_DURATION + 'ms ease-in forwards';

                setTimeout(function() {
                    // Hide old, show new
                    oldStep.classList.remove('active');
                    oldStep.style.display = 'none';
                    oldStep.style.animation = '';
                    oldStep.removeAttribute('data-entering');

                    newStep.style.display = 'block';
                    newStep.classList.add('active');
                    newStep.setAttribute('data-entering', '');

                    newStep.style.animation = forward ?
                        'stepEnterRight ' + ENTER_DURATION + 'ms cubic-bezier(0.22, 1, 0.36, 1) forwards' :
                        'stepEnterLeft ' + ENTER_DURATION + 'ms cubic-bezier(0.22, 1, 0.36, 1) forwards';

                    currentStep = newIndex;
                    updateProgress(newIndex);
                    if (newIndex === TOTAL_STEPS - 1) populateSummary();
                    if (newIndex === 4) updateUrlPreview();

                    // Scroll to top of wizard
                    document.querySelector('.page-title').scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });

                    // Clean up after animation
                    setTimeout(function() {
                        newStep.style.animation = '';
                        newStep.removeAttribute('data-entering');
                        isTransitioning = false;

                        // Focus first input in new step for keyboard users
                        var firstInput = newStep.querySelector('input:not([type="hidden"]):not([type="radio"]), select, textarea');
                        if (firstInput) firstInput.focus({
                            preventScroll: true
                        });
                    }, ENTER_DURATION + 500); // wait for stagger to finish
                }, EXIT_DURATION);
            }

            // ─── Inline validation ───

            function validateStep(stepIndex) {
                var step = steps[stepIndex];
                var requiredInputs = step.querySelectorAll('input[required]');
                var valid = true;

                requiredInputs.forEach(function(input) {
                    var group = input.closest('.form-group');
                    if (!group) return;

                    if (!input.value.trim()) {
                        group.classList.add('has-error', 'shake');
                        valid = false;
                        setTimeout(function() {
                            group.classList.remove('shake');
                        }, 400);
                    } else {
                        group.classList.remove('has-error');
                    }
                });

                // Step 2 (Database): require successful connection test
                if (stepIndex === 2 && valid) {
                    if (!dbTestPassed) {
                        var testGroup = document.getElementById('db-test-group');
                        if (testGroup) {
                            testGroup.classList.add('has-error', 'shake');
                            setTimeout(function() {
                                testGroup.classList.remove('shake');
                            }, 400);
                        }
                        valid = false;
                    }
                }

                // Step 4 (Discord): require successful credential test
                if (stepIndex === 4 && valid) {
                    if (!discordTestPassed) {
                        var dTestGroup = document.getElementById('discord-test-group');
                        if (dTestGroup) {
                            dTestGroup.classList.add('has-error', 'shake');
                            setTimeout(function() {
                                dTestGroup.classList.remove('shake');
                            }, 400);
                        }
                        valid = false;
                    }
                }

                // Dev mode: allow skipping validation with confirmation
                if (!valid && DEV_MODE) {
                    if (confirm('DEV: Validierung fehlgeschlagen — trotzdem fortfahren?')) {
                        return true;
                    }
                }

                if (!valid) {
                    var firstError = step.querySelector('.has-error input, .has-error .btn-test-db');
                    if (firstError) firstError.focus();
                }

                return valid;
            }

            // Clear error state as user types
            document.querySelectorAll('.form-group input[required]').forEach(function(input) {
                input.addEventListener('input', function() {
                    var group = this.closest('.form-group');
                    if (group && this.value.trim()) {
                        group.classList.remove('has-error');
                    }
                });
            });

            // Reset DB test when connection fields change
            ['db_host', 'db_port', 'db_user', 'db_pass', 'db_name'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    el.addEventListener('input', function() {
                        dbTestPassed = false;
                        var testGroup = document.getElementById('db-test-group');
                        if (testGroup) testGroup.classList.remove('has-error');
                        var result = document.getElementById('db-test-result');
                        if (result) result.style.display = 'none';
                    });
                }
            });

            // Reset Discord test when credentials change
            ['discord_client_id', 'discord_client_secret'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    el.addEventListener('input', function() {
                        discordTestPassed = false;
                        var testGroup = document.getElementById('discord-test-group');
                        if (testGroup) testGroup.classList.remove('has-error');
                        var result = document.getElementById('discord-test-result');
                        if (result) result.style.display = 'none';
                    });
                }
            });

            // ─── Event listeners ───

            // Next/Back buttons
            document.querySelectorAll('[data-wizard-next]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (validateStep(currentStep)) {
                        goToStep(currentStep + 1);
                    }
                });
            });

            document.querySelectorAll('[data-wizard-prev]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    goToStep(currentStep - 1);
                });
            });

            // ─── Toggle password ───
            window.togglePassword = function(fieldId, button) {
                var field = document.getElementById(fieldId);
                var isHidden = field.type === 'password';
                field.type = isHidden ? 'text' : 'password';
                button.textContent = isHidden ? 'Verbergen' : 'Anzeigen';
                button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
                button.setAttribute('aria-label', isHidden ? 'Passwort verbergen' : 'Passwort anzeigen');
                button.classList.toggle('visible', isHidden);
            };

            // ─── Database connection test ───
            window.testDatabaseConnection = function() {
                var btn = document.getElementById('btn-test-db');
                var result = document.getElementById('db-test-result');
                var host = document.getElementById('db_host').value;
                var port = document.getElementById('db_port').value;
                var user = document.getElementById('db_user').value;
                var pass = document.getElementById('db_pass').value;
                var name = document.getElementById('db_name').value;

                if (!name) {
                    result.className = 'db-test-result error';
                    result.textContent = 'Bitte einen Datenbank-Namen eingeben.';
                    result.style.display = 'block';
                    return;
                }

                btn.disabled = true;
                btn.classList.add('testing');
                btn.textContent = 'Teste...';
                result.className = 'db-test-result loading';
                result.textContent = 'Verbindung wird getestet...';
                result.style.display = 'block';

                var formData = new FormData();
                formData.append('action', 'test_db');
                formData.append('db_host', host);
                formData.append('db_port', port || '3306');
                formData.append('db_user', user);
                formData.append('db_pass', pass);
                formData.append('db_name', name);

                postWithCsrfRetry(formData)
                    .then(function(data) {
                        dbTestPassed = data.success;
                        result.className = 'db-test-result ' + (data.success ? 'success' : 'error');
                        result.textContent = (data.success ? '✓ ' : '✗ ') + data.message;
                        if (data.success) {
                            var testGroup = document.getElementById('db-test-group');
                            if (testGroup) testGroup.classList.remove('has-error');
                        }
                    })
                    .catch(function() {
                        dbTestPassed = false;
                        result.className = 'db-test-result error';
                        result.textContent = '✗ Fehler beim Testen der Verbindung.';
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.classList.remove('testing');
                        btn.textContent = 'Verbindung testen';
                    });
            };

            // ─── Discord credential test ───
            window.testDiscordCredentials = function() {
                var btn = document.getElementById('btn-test-discord');
                var result = document.getElementById('discord-test-result');
                var clientId = document.getElementById('discord_client_id').value;
                var secret = document.getElementById('discord_client_secret').value;

                if (!clientId || !secret) {
                    result.className = 'db-test-result error';
                    result.textContent = 'Client ID und Secret erforderlich.';
                    result.style.display = 'block';
                    return;
                }

                btn.disabled = true;
                btn.classList.add('testing');
                btn.textContent = 'Teste...';
                result.className = 'db-test-result loading';
                result.textContent = 'Discord wird kontaktiert...';
                result.style.display = 'block';

                var formData = new FormData();
                formData.append('action', 'test_discord');
                formData.append('discord_client_id', clientId);
                formData.append('discord_client_secret', secret);

                postWithCsrfRetry(formData)
                    .then(function(data) {
                        discordTestPassed = data.success;
                        result.className = 'db-test-result ' + (data.success ? 'success' : 'error');
                        result.textContent = (data.success ? '✓ ' : '✗ ') + data.message;
                        if (data.success) {
                            var testGroup = document.getElementById('discord-test-group');
                            if (testGroup) testGroup.classList.remove('has-error');
                        }
                    })
                    .catch(function() {
                        discordTestPassed = false;
                        result.className = 'db-test-result error';
                        result.textContent = '✗ Fehler beim Testen der Discord-Verbindung.';
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.classList.remove('testing');
                        btn.textContent = 'Discord-Verbindung testen';
                    });
            };

            // ─── Custom branch toggle ───
            <?php if ($devMode): ?>
                    (function() {
                        var customInput = document.getElementById('custom_branch_input');
                        var customField = document.getElementById('custom_branch_field');
                        document.querySelectorAll('input[name="git_branch"]').forEach(function(radio) {
                            radio.addEventListener('change', function() {
                                if (this.value === 'custom') {
                                    customInput.classList.add('active');
                                    customField.focus();
                                } else {
                                    customInput.classList.remove('active');
                                }
                            });
                        });
                    })();
            <?php endif; ?>

            // ─── Live URL preview + Redirect URI ───
            function getSystemUrl() {
                var domain = (document.getElementById('domain').value || '').trim();
                var basePath = (document.getElementById('base_path').value || '/').trim();
                if (!domain) return '';
                if (!basePath.startsWith('/')) basePath = '/' + basePath;
                if (!basePath.endsWith('/')) basePath += '/';
                return 'https://' + domain + basePath;
            }

            function updateUrlPreview() {
                var url = getSystemUrl();
                var preview = document.getElementById('url-preview');
                var redirectUri = document.getElementById('redirect-uri');
                if (preview) preview.textContent = url || '—';
                // intraRP's Discord-OAuth Callback ist auth/callback.php
                // (siehe src/Helpers/DiscordOAuth.php + auth/callback.php)
                if (redirectUri) redirectUri.textContent = url ? url + 'auth/callback.php' : '—';
            }

            document.getElementById('domain').addEventListener('input', updateUrlPreview);
            document.getElementById('base_path').addEventListener('input', updateUrlPreview);
            updateUrlPreview();

            // ─── Summary / confirmation step ───
            function populateSummary() {
                var branch = form.querySelector('input[name="git_branch"]:checked');
                var branchLabels = { release: 'Letzter Release (ZIP)', main: 'Main Branch', custom: 'Custom Branch' };
                var branchText = branchLabels[branch ? branch.value : 'release'] || 'Release';
                if (branch && branch.value === 'custom') {
                    var custom = document.getElementById('custom_branch_field');
                    if (custom && custom.value) branchText += ': ' + custom.value;
                }
                document.getElementById('summary-git').textContent = branchText;

                var dbHost = document.getElementById('db_host').value || 'localhost';
                var dbPort = document.getElementById('db_port').value || '3306';
                var dbUser = document.getElementById('db_user').value || 'root';
                var dbName = document.getElementById('db_name').value || 'intrarp';
                document.getElementById('summary-db').innerHTML =
                    '<code>' + dbHost + ':' + dbPort + '</code> · Benutzer: <code>' + dbUser + '</code> · DB: <code>' + dbName + '</code>';

                var url = getSystemUrl();
                document.getElementById('summary-system').innerHTML =
                    url ? '<code>' + url + '</code>' : '—';

                var discordId = document.getElementById('discord_client_id').value;
                var masked = discordId ? discordId.substring(0, 6) + '...' : '—';
                document.getElementById('summary-discord').innerHTML =
                    'Client ID: <code>' + masked + '</code>';
            }

            // Jump back to step when clicking summary section
            document.querySelectorAll('.summary-section').forEach(function(section) {
                section.addEventListener('click', function(e) {
                    if (e.target.closest('.summary-edit') || e.target === section) {
                        var target = parseInt(section.getAttribute('data-jump'));
                        goToStep(target);
                    }
                });
                section.querySelector('.summary-edit').addEventListener('click', function() {
                    var target = parseInt(section.getAttribute('data-jump'));
                    goToStep(target);
                });
            });

            // ─── Form submission with progress modal ───
            // Der Server macht den gesamten Install in einem einzigen POST
            // (Download → Composer → .env → Migrations → Cleanup). Die
            // Modal-Steps sind eine visuelle Orchestrierung der Wartezeit —
            // der eigentliche Fortschritt wird am Ende anhand der Response
            // nachträglich dargestellt (error setzt den ersten möglichen
            // Fehler-Step auf 'error', done markiert alles als abgeschlossen).
            var modalStepNames = ['connect', 'download', 'install', 'composer', 'migrate', 'config', 'done'];
            var modal = document.getElementById('setup-modal');
            var modalTitle = document.getElementById('modal-title');
            var modalError = document.getElementById('modal-error');
            var modalComposer = document.getElementById('modal-composer');
            var modalWarnings = document.getElementById('modal-warnings');
            var modalAction = document.getElementById('modal-action');

            function setModalStep(name, state) {
                var el = document.querySelector('[data-modal-step="' + name + '"]');
                if (!el) return;
                el.classList.remove('active', 'done', 'error');
                if (state) el.classList.add(state);
                if (state === 'done') {
                    el.querySelector('.step-icon').textContent = '✓';
                } else if (state === 'error') {
                    el.querySelector('.step-icon').textContent = '✗';
                }
            }

            function advanceModal(stepIndex, delay) {
                return new Promise(function(resolve) {
                    setTimeout(function() {
                        if (stepIndex > 0) setModalStep(modalStepNames[stepIndex - 1], 'done');
                        if (stepIndex < modalStepNames.length) setModalStep(modalStepNames[stepIndex], 'active');
                        resolve();
                    }, delay);
                });
            }

            function renderWarnings(warnings) {
                if (!warnings || warnings.length === 0) return;
                var html = '<strong>Hinweise</strong><ul>';
                for (var i = 0; i < warnings.length; i++) {
                    var div = document.createElement('div');
                    div.textContent = warnings[i];
                    html += '<li>' + div.innerHTML + '</li>';
                }
                html += '</ul>';
                modalWarnings.innerHTML = html;
                modalWarnings.classList.add('visible');
            }

            // Phase-to-step mapping for the modal
            var phaseToStep = {
                'connect': 'connect',
                'download': 'download',
                'install': 'install',
                'composer': 'composer',
                'config': 'config',
                'storage': 'config',
                'migrate': 'migrate',
                'cleanup': 'done',
                'done': 'done',
            };
            var lastPhase = '';

            function pollProgress() {
                var url = window.location.pathname + '?action=progress&_=' + Date.now();
                return fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data || !data.phase || data.phase === 'waiting') return;
                        if (data.phase === lastPhase && data.phase !== 'download') return;
                        lastPhase = data.phase;

                        // Map phase to modal step
                        var stepName = phaseToStep[data.phase] || data.phase;

                        // Mark all previous steps as done
                        var found = false;
                        for (var i = 0; i < modalStepNames.length; i++) {
                            if (modalStepNames[i] === stepName) {
                                found = true;
                                setModalStep(modalStepNames[i], 'active');
                            } else if (!found) {
                                setModalStep(modalStepNames[i], 'done');
                            }
                        }

                        // Update detail text for download phase
                        if (data.phase === 'download' && data.detail) {
                            var dlEl = document.getElementById('modal-step-download');
                            if (dlEl) dlEl.textContent = 'Download: ' + data.detail;
                        }

                        // Update modal title with current action
                        if (data.detail && data.phase !== 'done' && data.phase !== 'error') {
                            modalTitle.textContent = data.detail;
                        }
                    })
                    .catch(function() {}); // polling errors are non-fatal
            }

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (!validateStep(currentStep)) return;

                var branch = form.querySelector('input[name="git_branch"]:checked');
                var isRelease = branch && branch.value === 'release';

                if (!isRelease) {
                    document.getElementById('modal-step-download').textContent = 'Repository wird geklont...';
                    document.getElementById('modal-step-install').textContent = 'Branch wird ausgecheckt...';
                }
                if (isRelease) {
                    document.getElementById('modal-step-composer').textContent = 'Im Release enthalten';
                }

                modal.classList.add('visible');
                document.body.style.overflow = 'hidden';
                setModalStep('connect', 'active');
                modalTitle.textContent = 'Verbindung zu GitHub...';

                var formData = new FormData(form);

                // Start polling for real progress
                var pollInterval = setInterval(pollProgress, 1500);

                // Fire the actual request
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Setup-Ajax': '1',
                        'X-Setup-Token': formData.get('_token')
                    }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    clearInterval(pollInterval);

                    if (data.success) {
                        // Mark all steps as done
                        for (var i = 0; i < modalStepNames.length; i++) {
                            setModalStep(modalStepNames[i], 'done');
                        }
                        modalTitle.textContent = 'Setup abgeschlossen!';

                        if (data.needsManualComposer) {
                            modalComposer.classList.add('visible');
                        }
                        renderWarnings(data.warnings);
                        if (data.selfDeleted === false) {
                            modalWarnings.classList.add('visible');
                        }
                        modalAction.classList.add('visible');
                    } else {
                        var activeStep = document.querySelector('.setup-modal-step.active');
                        if (activeStep) {
                            setModalStep(activeStep.getAttribute('data-modal-step'), 'error');
                        }
                        modalTitle.textContent = 'Setup fehlgeschlagen';
                        var errs = data.errors || ['Unbekannter Fehler'];
                        var errHtml = '';
                        for (var i = 0; i < errs.length; i++) {
                            var d = document.createElement('div');
                            d.textContent = errs[i];
                            errHtml += d.innerHTML + '<br>';
                        }
                        modalError.innerHTML = errHtml;
                        modalError.classList.add('visible');
                        renderWarnings(data.warnings);
                        modalAction.querySelector('.btn').textContent = 'Zurück';
                        modalAction.querySelector('.btn').href = window.location.href;
                        modalAction.classList.add('visible');
                    }
                })
                .catch(function(err) {
                    clearInterval(pollInterval);
                    var activeStep = document.querySelector('.setup-modal-step.active');
                    if (activeStep) {
                        setModalStep(activeStep.getAttribute('data-modal-step'), 'error');
                    } else {
                        setModalStep('connect', 'error');
                    }
                    modalTitle.textContent = 'Verbindungsfehler';
                    modalError.textContent = 'Fehler bei der Kommunikation mit dem Server.';
                    modalError.classList.add('visible');
                    modalAction.querySelector('.btn').textContent = 'Zurück';
                    modalAction.querySelector('.btn').href = window.location.href;
                    modalAction.classList.add('visible');
                });
            });

            // ─── Initialize ───
            updateProgress(0);

            // Clean up initial-reveal class after animation
            setTimeout(function() {
                var initial = document.querySelector('.initial-reveal');
                if (initial) initial.classList.remove('initial-reveal');
            }, 800);

        })();
    </script>
</body>

</html>
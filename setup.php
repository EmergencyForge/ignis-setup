<?php

/**
 * intraRP Setup Script
 * Führt Git Pull aus, erstellt .env Datei und leitet zum Admin-Panel weiter
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

$devMode = isset($_GET['dev']);

$phpVersion = phpversion();
$requiredPhpVersion = '8.1.0';
$phpVersionOk = version_compare($phpVersion, $requiredPhpVersion, '>=');

$gitAvailable = false;
$gitOutput = [];
$gitReturnVar = 0;
exec('git --version 2>&1', $gitOutput, $gitReturnVar);
$gitAvailable = ($gitReturnVar === 0);

// Check PHP configuration limits for large ZIP downloads (~100MB+)
function parsePhpSize($size) {
    $size = trim($size);
    $unit = strtolower(substr($size, -1));
    $value = (int)$size;
    return match($unit) {
        'g' => $value * 1024,
        'm' => $value,
        'k' => $value / 1024,
        default => $value / (1024 * 1024),
    };
}

$phpLimits = [
    'memory_limit' => ['current' => ini_get('memory_limit'), 'recommended' => 512, 'unit' => 'M'],
    'max_execution_time' => ['current' => ini_get('max_execution_time'), 'recommended' => 300, 'unit' => 's'],
    'upload_max_filesize' => ['current' => ini_get('upload_max_filesize'), 'recommended' => 256, 'unit' => 'M'],
    'post_max_size' => ['current' => ini_get('post_max_size'), 'recommended' => 256, 'unit' => 'M'],
];

$phpLimitsOk = true;
$phpLimitWarnings = [];
foreach ($phpLimits as $key => $limit) {
    if ($key === 'max_execution_time') {
        $currentVal = (int)$limit['current'];
        // 0 = unlimited = ok
        $ok = ($currentVal === 0 || $currentVal >= $limit['recommended']);
        $currentDisplay = $currentVal === 0 ? 'Unbegrenzt' : $currentVal . 's';
    } else {
        $currentVal = parsePhpSize($limit['current']);
        // -1 = unlimited = ok
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
$allowUrlFopen = (bool)ini_get('allow_url_fopen');

// Robust HTTP helper — uses cURL (streaming to disk) with file_get_contents fallback
function httpGet($url, $saveTo = null, $timeout = 30) {
    $headers = ['User-Agent: intraRP-Setup'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($saveTo) {
            // Stream directly to file — no memory spike
            $fp = fopen($saveTo, 'wb');
            if (!$fp) return ['ok' => false, 'error' => 'Konnte Zieldatei nicht erstellen: ' . $saveTo];
            curl_setopt($ch, CURLOPT_FILE, $fp);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if ($saveTo) {
            fclose($fp);
            if ($httpCode >= 400 || !empty($curlError)) {
                @unlink($saveTo);
                return ['ok' => false, 'error' => $curlError ?: "HTTP {$httpCode}"];
            }
            return ['ok' => true];
        }

        if ($httpCode >= 400 || $result === false) {
            return ['ok' => false, 'error' => $curlError ?: "HTTP {$httpCode}"];
        }
        return ['ok' => true, 'body' => $result];
    }

    // Fallback: file_get_contents
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
        if (!$source) return ['ok' => false, 'error' => 'Konnte URL nicht öffnen: ' . $url];
        $dest = @fopen($saveTo, 'wb');
        if (!$dest) { fclose($source); return ['ok' => false, 'error' => 'Konnte Zieldatei nicht erstellen.']; }
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

// Determine default values for BASE_PATH and DOMAIN
$defaultDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$defaultBasePath = ($scriptDir === '/' || $scriptDir === '\\') ? '/' : rtrim($scriptDir, '/\\') . '/';

function logError($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents('setup_error.log', $logMessage, FILE_APPEND);
}

// Sanitize and escape environment variables to prevent injection attacks
// Follows .env file format standards with proper quoting
function sanitizeEnvValue($value)
{
    // Remove any newline characters that could break .env file format
    $value = str_replace(["\r", "\n"], '', $value);
    // Trim whitespace
    $value = trim($value);
    return $value;
}

// Format value for .env file with proper quoting and escaping
function formatEnvValue($value)
{
    // Sanitize first
    $value = sanitizeEnvValue($value);
    // Escape backslashes and double quotes
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    // Wrap in double quotes for safety
    return '"' . $value . '"';
}

// AJAX: Datenbankverbindung testen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_db') {
    header('Content-Type: application/json');
    $host = sanitizeEnvValue($_POST['db_host'] ?? 'localhost');
    $user = sanitizeEnvValue($_POST['db_user'] ?? 'root');
    $pass = sanitizeEnvValue($_POST['db_pass'] ?? '');
    $name = sanitizeEnvValue($_POST['db_name'] ?? '');

    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Datenbank-Name ist erforderlich.']);
        exit;
    }

    try {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        echo json_encode(['success' => true, 'message' => 'Verbindung erfolgreich! (Server: MySQL ' . $serverVersion . ')']);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Bekannte Fehlermeldungen übersetzen
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

if (isset($_GET['force_delete']) && $_GET['force_delete'] === 'confirm') {
    $setupFile = __FILE__;
    if (@unlink($setupFile)) {
        header('Location: index.php');
        exit;
    } else {
        die('Fehler: setup.php konnte nicht gelöscht werden. Bitte manuell löschen.');
    }
}

$errors = [];
$success = [];
// Git is only required for main/custom branches (dev mode), not for release ZIPs
$canProceed = $phpVersionOk && ($curlAvailable || $allowUrlFopen) && class_exists('ZipArchive');

if (!$phpVersionOk) {
    $errors[] = "PHP Version {$phpVersion} ist zu alt. Mindestens PHP {$requiredPhpVersion} wird benötigt!";
    logError("PHP Version Check fehlgeschlagen: {$phpVersion} < {$requiredPhpVersion}");
}

$isAjaxSetup = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_SETUP_AJAX']));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canProceed && !isset($_POST['action'])) {

    $gitBranch = $_POST['git_branch'] ?? 'release';
    $customBranch = trim($_POST['custom_branch'] ?? '');
    $repoOwner = 'EmergencyForge';
    $repoName = 'intraRP';

    // Temporarily raise limits for large downloads
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    if ($gitBranch === 'release') {
        // Step 1: Fetch release info from GitHub API
        $apiUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/releases/latest";
        $apiResult = httpGet($apiUrl);

        if (!$apiResult['ok']) {
            $errors[] = 'Konnte Release-Informationen nicht von GitHub abrufen: ' . $apiResult['error'];
            logError('GitHub API Fehler: ' . $apiResult['error']);
        } else {
            $release = json_decode($apiResult['body'], true);

            if (!$release || empty($release['tag_name'])) {
                $errors[] = 'Ungültige Antwort von der GitHub API.';
                logError('GitHub API: Ungültiges JSON oder kein tag_name');
            } else {
                $tagName = $release['tag_name'];
                $zipAsset = null;

                // Find the intraRP ZIP asset in the release
                if (!empty($release['assets'])) {
                    foreach ($release['assets'] as $asset) {
                        if (str_starts_with($asset['name'], 'intraRP-') && str_ends_with($asset['name'], '.zip')) {
                            $zipAsset = $asset;
                            break;
                        }
                    }
                }

                if ($zipAsset === null) {
                    $errors[] = 'Kein Release-ZIP (intraRP-*.zip) in Version ' . htmlspecialchars($tagName) . ' gefunden.';
                    logError('Kein intraRP-*.zip Asset im Release ' . $tagName);
                } else {
                    // Step 2: Download ZIP — streamed to temp file (not into memory)
                    $zipUrl = $zipAsset['browser_download_url'];
                    $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipAsset['name'];

                    $dlResult = httpGet($zipUrl, $zipPath, 300);

                    if (!$dlResult['ok']) {
                        $errors[] = 'Fehler beim Herunterladen des Release-ZIP: ' . $dlResult['error'];
                        logError('Download fehlgeschlagen: ' . $zipUrl . ' — ' . $dlResult['error']);
                    } else {
                        // Verify file was actually downloaded
                        if (!file_exists($zipPath) || filesize($zipPath) < 1000) {
                            $errors[] = 'Download abgeschlossen, aber Datei ist leer oder unvollständig.';
                            logError('ZIP-Datei leer/unvollständig: ' . $zipPath . ' (' . filesize($zipPath) . ' bytes)');
                            @unlink($zipPath);
                        } else {
                            // Step 3: Extract ZIP
                            $zip = new ZipArchive();
                            $zipOpenResult = $zip->open($zipPath);
                            if ($zipOpenResult === true) {
                                $extractDir = dirname(__FILE__);
                                $zip->extractTo($extractDir);
                                $zip->close();
                                @unlink($zipPath);
                                $success[] = 'Release ' . htmlspecialchars($tagName) . ' erfolgreich installiert.';
                            } else {
                                $errors[] = 'Fehler beim Entpacken des Release-ZIP (Code: ' . $zipOpenResult . ').';
                                logError('ZIP Entpacken fehlgeschlagen: ' . $zipPath . ' (Code: ' . $zipOpenResult . ')');
                                @unlink($zipPath);
                            }
                        }
                    }
                }
            }
        }
    } else {
        // Main or custom branch — requires Git
        if (!$gitAvailable) {
            $errors[] = 'Git ist nicht verfügbar. Git wird für Branch-basierte Installation benötigt!';
            logError('Git ist nicht verfügbar auf diesem System');
        } else {
            $gitOutput = [];
            $gitReturnVar = 0;
            $repoUrl = "https://github.com/{$repoOwner}/{$repoName}.git";

            if (!is_dir('.git')) {
                exec('git init 2>&1', $gitOutput, $gitReturnVar);

                if ($gitReturnVar === 0) {
                    exec("git remote add origin {$repoUrl} 2>&1", $gitOutput, $gitReturnVar);

                    if ($gitBranch === 'custom' && !empty($customBranch)) {
                        exec("git fetch origin {$customBranch} 2>&1", $gitOutput, $gitReturnVar);
                        exec("git checkout -b {$customBranch} origin/{$customBranch} 2>&1", $gitOutput, $gitReturnVar);
                        if ($gitReturnVar === 0) {
                            exec("git reset --hard origin/{$customBranch} 2>&1", $gitOutput, $gitReturnVar);
                            $success[] = "Repository initialisiert (Custom Branch: {$customBranch})";
                        }
                    } else {
                        exec('git fetch origin main 2>&1', $gitOutput, $gitReturnVar);
                        exec('git checkout -b main origin/main 2>&1', $gitOutput, $gitReturnVar);
                        if ($gitReturnVar === 0) {
                            exec('git reset --hard origin/main 2>&1', $gitOutput, $gitReturnVar);
                            $success[] = 'Repository initialisiert (Branch: main - experimentell)';
                        }
                    }
                }

                if ($gitReturnVar !== 0 && empty($success)) {
                    $errors[] = 'Git Fehler: ' . implode('<br>', $gitOutput);
                    logError('Git Init/Clone Fehler: ' . implode(' | ', $gitOutput));
                }
            } else {
                $gitOutput = [];

                if ($gitBranch === 'custom' && !empty($customBranch)) {
                    exec("git checkout {$customBranch} 2>&1", $gitOutput, $gitReturnVar);
                    exec("git pull origin {$customBranch} 2>&1", $gitOutput, $gitReturnVar);
                    $success[] = "Git Pull erfolgreich (Custom Branch: {$customBranch})";
                } else {
                    exec('git checkout main 2>&1', $gitOutput, $gitReturnVar);
                    exec('git pull origin main 2>&1', $gitOutput, $gitReturnVar);
                    $success[] = 'Git Pull erfolgreich (Branch: main)';
                }

                if ($gitReturnVar !== 0) {
                    $errors[] = 'Git Pull/Checkout Fehler: ' . implode('<br>', $gitOutput);
                    logError('Git Pull/Checkout Fehler: ' . implode(' | ', $gitOutput));
                }
            }
        }
    }

    $needsComposer = ($gitBranch === 'main' || $gitBranch === 'custom');

    $envConfig = [
        'DB_HOST' => sanitizeEnvValue($_POST['db_host'] ?? 'localhost'),
        'DB_USER' => sanitizeEnvValue($_POST['db_user'] ?? 'root'),
        'DB_PASS' => sanitizeEnvValue($_POST['db_pass'] ?? ''),
        'DB_NAME' => sanitizeEnvValue($_POST['db_name'] ?? 'intrarp'),
        'DISCORD_CLIENT_ID' => sanitizeEnvValue($_POST['discord_client_id'] ?? ''),
        'DISCORD_CLIENT_SECRET' => sanitizeEnvValue($_POST['discord_client_secret'] ?? ''),
        'BASE_PATH' => sanitizeEnvValue($_POST['base_path'] ?? '/'),
        'DOMAIN' => sanitizeEnvValue($_POST['domain'] ?? 'localhost'),
    ];

    if (empty($envConfig['DB_NAME'])) {
        $errors[] = 'Datenbank-Name ist erforderlich!';
        logError('Validierung fehlgeschlagen: Datenbank-Name fehlt');
    }
    if (empty($envConfig['DISCORD_CLIENT_ID'])) {
        $errors[] = 'Discord Client ID ist erforderlich!';
        logError('Validierung fehlgeschlagen: Discord Client ID fehlt');
    }
    if (empty($envConfig['DISCORD_CLIENT_SECRET'])) {
        $errors[] = 'Discord Client Secret ist erforderlich!';
        logError('Validierung fehlgeschlagen: Discord Client Secret fehlt');
    }
    if ($gitBranch === 'custom' && empty($customBranch)) {
        $errors[] = 'Custom Branch-Name ist erforderlich!';
        logError('Validierung fehlgeschlagen: Custom Branch-Name fehlt');
    }

    if (empty($errors)) {

        $envContent = "DB_HOST=" . formatEnvValue($envConfig['DB_HOST']) . "\n";
        $envContent .= "DB_USER=" . formatEnvValue($envConfig['DB_USER']) . "\n";
        $envContent .= "DB_PASS=" . formatEnvValue($envConfig['DB_PASS']) . "\n";
        $envContent .= "DB_NAME=" . formatEnvValue($envConfig['DB_NAME']) . "\n\n";
        $envContent .= "DISCORD_CLIENT_ID=" . formatEnvValue($envConfig['DISCORD_CLIENT_ID']) . "\n";
        $envContent .= "DISCORD_CLIENT_SECRET=" . formatEnvValue($envConfig['DISCORD_CLIENT_SECRET']) . "\n\n";
        $envContent .= "# System Configuration\n";
        $envContent .= "BASE_PATH=" . formatEnvValue($envConfig['BASE_PATH']) . "\n";
        $envContent .= "DOMAIN=" . formatEnvValue($envConfig['DOMAIN']);

        if (file_put_contents('.env', $envContent)) {
            $success[] = '.env Datei erfolgreich erstellt!';

            $setupFile = __FILE__;

            if (empty($errors)) {
                @unlink($setupFile);

                if ($isAjaxSetup) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'needsComposer' => $needsComposer,
                        'messages' => $success,
                    ]);
                    exit;
                }

                // Fallback for non-JS
                header('Location: index.php');
                exit;
            }
        } else {
            $errors[] = 'Fehler beim Schreiben der .env Datei. Prüfen Sie die Schreibrechte!';
            logError('Fehler beim Schreiben der .env Datei - Schreibrechte prüfen');
        }
    }

    // Return errors as JSON for AJAX requests
    if ($isAjaxSetup && !empty($errors)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>intraRP Setup</title>
    <style>
        :root {
            --color-primary: #c80000;
            --color-primary-hover: #9e0000;
            --color-primary-subtle: rgba(200, 0, 0, 0.06);
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
                --color-primary: #ef4444;
                --color-primary-hover: #dc2626;
                --color-primary-subtle: rgba(239, 68, 68, 0.1);
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
            padding: var(--space-xl);
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }

        .container {
            max-width: 720px;
            width: 100%;
            background: var(--color-surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
        }

        .setup-header {
            background: var(--color-primary);
            color: white;
            padding: 56px var(--space-2xl) var(--space-2xl);
            text-align: left;
            position: relative;
            overflow: hidden;
        }

        .setup-header::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
        }

        .setup-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            right: 15%;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.04);
        }

        .setup-header h1 {
            font-size: 2.2em;
            margin-bottom: var(--space-sm);
            letter-spacing: -0.03em;
            font-weight: 800;
            position: relative;
        }

        .setup-header p {
            opacity: 0.8;
            font-size: 0.95em;
            font-weight: 500;
            position: relative;
        }

        .content {
            padding: clamp(var(--space-lg), 5vw, var(--space-2xl));
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
            border-radius: var(--radius-md);
            margin-bottom: var(--space-lg);
            border: 1.5px solid transparent;
        }

        .alert-error {
            background: var(--color-error-bg);
            border-color: var(--color-error-border);
            color: var(--color-error);
        }

        .alert-success {
            background: var(--color-success-bg);
            border-color: var(--color-success-border);
            color: var(--color-success);
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
            display: flex;
            flex-direction: column;
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

        /* ═══ Footer ═══ */

        .setup-footer {
            padding: var(--space-lg) var(--space-2xl);
            border-top: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
            flex-wrap: wrap;
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

        /* Container entrance */
        .container {
            animation: containerIn 0.6s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        @keyframes containerIn {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

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

        .requirement-box.success .requirement-icon {
            animation: checkPop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) both;
            animation-delay: 0.3s;
        }

        @keyframes checkPop {
            0% {
                transform: scale(0.6);
                opacity: 0.5;
            }

            60% {
                transform: scale(1.2);
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
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

        /* Success pulse on DB test result */
        .db-test-result.success {
            animation: resultSlide 0.35s cubic-bezier(0.22, 1, 0.36, 1) both,
                successPulse 0.6s cubic-bezier(0.22, 1, 0.36, 1) 0.35s both;
        }

        @keyframes successPulse {
            0% {
                box-shadow: 0 0 0 0 var(--color-success-border);
            }

            50% {
                box-shadow: 0 0 0 6px transparent;
            }

            100% {
                box-shadow: none;
            }
        }

        /* Completed wizard dot pop */
        .wizard-dot.completed .wizard-dot-icon {
            animation: dotComplete 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes dotComplete {
            0% {
                transform: scale(1);
            }

            40% {
                transform: scale(1.3);
            }

            100% {
                transform: scale(1);
            }
        }

        /* Password toggle tactile click */
        .toggle-password:active {
            transform: scale(0.95);
        }

        /* Radio card selection pop */
        .radio-group label:has(input:checked) {
            animation: cardSelect 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes cardSelect {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.01);
            }

            100% {
                transform: scale(1);
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

        /* Gate shimmer on unlocked next button */
        .wizard-gate-shimmer:not(:disabled)::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(105deg, transparent 40%, rgba(255, 255, 255, 0.25) 50%, transparent 60%);
            animation: shimmer 2.5s ease-in-out infinite;
        }

        @keyframes shimmer {

            0%,
            100% {
                transform: translateX(-120%);
            }

            50% {
                transform: translateX(120%);
            }
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

        /* Section title inside wizard steps — progress bar replaces the border */
        .wizard-step .section-title {
            border-bottom: none;
            margin-top: 0;
            padding-bottom: 0;
            margin-bottom: var(--space-lg);
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
            <h1>intraRP Setup</h1>
            <p>Tool zum Aufsetzen & Konfigurieren von intraRP</p>
        </header>

        <div class="content">

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

            <!-- Wizard progress bar -->
            <nav class="wizard-progress" aria-label="Setup-Fortschritt">
                <div class="wizard-dots">
                    <div class="wizard-track">
                        <div class="wizard-fill" id="wizard-fill"></div>
                    </div>
                    <button type="button" class="wizard-dot active" data-dot="0" aria-current="step">
                        <span class="wizard-dot-icon">1</span>
                        <span class="wizard-dot-label">Prüfung</span>
                    </button>
                    <button type="button" class="wizard-dot" data-dot="1" disabled>
                        <span class="wizard-dot-icon">2</span>
                        <span class="wizard-dot-label">Git</span>
                    </button>
                    <button type="button" class="wizard-dot" data-dot="2" disabled>
                        <span class="wizard-dot-icon">3</span>
                        <span class="wizard-dot-label">Datenbank</span>
                    </button>
                    <button type="button" class="wizard-dot" data-dot="3" disabled>
                        <span class="wizard-dot-icon">4</span>
                        <span class="wizard-dot-label">System</span>
                    </button>
                    <button type="button" class="wizard-dot" data-dot="4" disabled>
                        <span class="wizard-dot-icon">5</span>
                        <span class="wizard-dot-label">Discord</span>
                    </button>
                    <button type="button" class="wizard-dot" data-dot="5" disabled>
                        <span class="wizard-dot-icon">6</span>
                        <span class="wizard-dot-label">Übersicht</span>
                    </button>
                </div>
            </nav>

            <form method="POST" action="" id="setup-form" novalidate>

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

                        <?php $zipExtOk = class_exists('ZipArchive'); ?>
                        <div class="requirement-box <?php echo $zipExtOk ? 'success' : 'error'; ?>">
                            <span class="requirement-icon" aria-hidden="true"><?php echo $zipExtOk ? '✓' : '✗'; ?></span>
                            <div class="requirement-detail">
                                <strong class="requirement-title">ZIP-Extension</strong>
                                <div class="requirement-status">
                                    <?php if ($zipExtOk): ?>
                                        <strong>Verfügbar</strong>
                                        <div class="requirement-sub">Zum Entpacken des Release-ZIP benötigt</div>
                                    <?php else: ?>
                                        <div class="requirement-fix-inner">
                                            <strong>Nicht verfügbar!</strong><br>
                                            <small>PHP-Extension <code>zip</code> muss aktiviert sein</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!$phpLimitsOk): ?>
                        <div class="info-box" style="border-color: var(--color-warning); color: var(--color-warning);">
                            <strong>PHP-Konfiguration anpassen empfohlen</strong>
                            Das Release-ZIP ist ca. 100 MB groß. Folgende PHP-Einstellungen sollten erhöht werden:
                            <ul style="margin-top: var(--space-sm); margin-left: var(--space-lg);">
                                <?php foreach ($phpLimitWarnings as $w): ?>
                                    <li><code><?php echo $w; ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                            <small style="display: block; margin-top: var(--space-sm); opacity: 0.8;">Anpassung in der <code>php.ini</code> oder <code>.htaccess</code> Ihres Hosters.</small>
                        </div>
                    <?php endif; ?>

                    <div class="wizard-nav">
                        <button type="button" class="wizard-nav-btn wizard-nav-btn--next wizard-gate-shimmer" data-wizard-next <?php echo !$canProceed ? 'disabled' : ''; ?>>
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
                        <span id="modal-step-download">Release wird heruntergeladen...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="install">
                        <span class="step-icon">3</span>
                        <span id="modal-step-install">Dateien werden installiert...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="config">
                        <span class="step-icon">4</span>
                        <span>Konfiguration wird erstellt...</span>
                    </li>
                    <li class="setup-modal-step" data-modal-step="done">
                        <span class="step-icon">5</span>
                        <span>Abschluss</span>
                    </li>
                </ul>
                <div class="setup-modal-error" id="modal-error" role="alert"></div>
                <div class="setup-modal-composer" id="modal-composer">
                    <strong>Composer erforderlich</strong>
                    <p>Bitte führen Sie folgenden Befehl manuell aus:</p>
                    <code>composer install --no-dev --optimize-autoloader</code>
                </div>
                <div class="setup-modal-action" id="modal-action">
                    <a href="index.php" class="btn">Weiter zum System</a>
                </div>
            </div>
        </div>

        <footer class="setup-footer">
            <span class="setup-footer-brand">EmergencyForge</span>
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

            // ─── DOM refs ───
            const steps = document.querySelectorAll('.wizard-step');
            const dots = document.querySelectorAll('.wizard-dot');
            const fill = document.getElementById('wizard-fill');
            const form = document.getElementById('setup-form');
            let currentStep = 0;
            let isTransitioning = false;
            let dbTestPassed = false;

            // ─── Spring physics solver ───
            function createSpring(config = {}) {
                const {
                    stiffness = 170, damping = 14, mass = 1, precision = 0.01
                } = config;
                return {
                    animate(from, to, onUpdate, onComplete) {
                        let position = from;
                        let velocity = 0;
                        let lastTime = performance.now();
                        let raf;

                        function tick(now) {
                            const dt = Math.min((now - lastTime) / 1000, 0.064); // cap at ~16fps minimum
                            lastTime = now;

                            const displacement = position - to;
                            const springForce = -stiffness * displacement;
                            const dampingForce = -damping * velocity;
                            const acceleration = (springForce + dampingForce) / mass;

                            velocity += acceleration * dt;
                            position += velocity * dt;

                            onUpdate(position);

                            if (Math.abs(velocity) < precision && Math.abs(position - to) < precision) {
                                onUpdate(to);
                                if (onComplete) onComplete();
                                return;
                            }

                            raf = requestAnimationFrame(tick);
                        }

                        raf = requestAnimationFrame(tick);
                        return () => cancelAnimationFrame(raf);
                    }
                };
            }

            const progressSpring = createSpring({
                stiffness: 120,
                damping: 16,
                mass: 1
            });
            let cancelSpring = null;

            // ─── Progress bar ───
            let currentFillValue = 0;

            function updateProgress(targetStep) {
                const targetFill = targetStep / (TOTAL_STEPS - 1);

                if (REDUCED_MOTION) {
                    fill.style.transform = 'scaleX(' + targetFill + ')';
                    currentFillValue = targetFill;
                } else {
                    if (cancelSpring) cancelSpring();
                    cancelSpring = progressSpring.animate(
                        currentFillValue,
                        targetFill,
                        function(v) {
                            fill.style.transform = 'scaleX(' + v + ')';
                        },
                        function() {
                            currentFillValue = targetFill;
                        }
                    );
                    currentFillValue = targetFill;
                }

                // Update dots
                dots.forEach(function(dot, i) {
                    const icon = dot.querySelector('.wizard-dot-icon');
                    dot.classList.remove('active', 'completed');
                    dot.removeAttribute('aria-current');

                    if (i === targetStep) {
                        dot.classList.add('active');
                        dot.setAttribute('aria-current', 'step');
                        icon.textContent = String(i + 1);
                    } else if (i < targetStep) {
                        dot.classList.add('completed');
                        icon.textContent = '✓';
                        dot.disabled = false;
                    } else {
                        icon.textContent = String(i + 1);
                        dot.disabled = true;
                    }
                });
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
                    document.querySelector('.wizard-progress').scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
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
            ['db_host', 'db_user', 'db_pass', 'db_name'].forEach(function(id) {
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

            // Dot navigation (click on completed dots)
            dots.forEach(function(dot) {
                dot.addEventListener('click', function() {
                    var target = parseInt(this.getAttribute('data-dot'));
                    if (!this.disabled && target !== currentStep) {
                        goToStep(target);
                    }
                });
            });

            // Keyboard: allow arrow keys on dots
            document.querySelector('.wizard-dots').addEventListener('keydown', function(e) {
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    var next = currentStep + 1;
                    if (next < TOTAL_STEPS && !dots[next].disabled) goToStep(next);
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (currentStep > 0) goToStep(currentStep - 1);
                }
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
                formData.append('db_user', user);
                formData.append('db_pass', pass);
                formData.append('db_name', name);

                fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        dbTestPassed = data.success;
                        result.className = 'db-test-result ' + (data.success ? 'success' : 'error');
                        result.textContent = (data.success ? '✓ ' : '✗ ') + data.message;
                        // Clear validation error if test passed
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
                if (redirectUri) redirectUri.textContent = url ? url + 'auth/discord/callback' : '—';
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
                var dbUser = document.getElementById('db_user').value || 'root';
                var dbName = document.getElementById('db_name').value || 'intrarp';
                document.getElementById('summary-db').innerHTML =
                    '<code>' + dbHost + '</code> · Benutzer: <code>' + dbUser + '</code> · DB: <code>' + dbName + '</code>';

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
            var modalSteps = document.querySelectorAll('.setup-modal-step');
            var modalStepNames = ['connect', 'download', 'install', 'config', 'done'];
            var modal = document.getElementById('setup-modal');
            var modalTitle = document.getElementById('modal-title');
            var modalError = document.getElementById('modal-error');
            var modalComposer = document.getElementById('modal-composer');
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

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                if (!validateStep(currentStep)) return;

                var branch = form.querySelector('input[name="git_branch"]:checked');
                var isRelease = branch && branch.value === 'release';

                // Customize step labels for branch mode
                if (!isRelease) {
                    document.getElementById('modal-step-download').textContent = 'Repository wird geklont...';
                    document.getElementById('modal-step-install').textContent = 'Branch wird ausgecheckt...';
                }

                // Show modal
                modal.classList.add('visible');
                document.body.style.overflow = 'hidden';

                var formData = new FormData(form);

                // Start progress animation, then fire the request
                advanceModal(0, 300)
                    .then(function() { return advanceModal(1, 800); })
                    .then(function() {
                        // Fire the actual request
                        return fetch(window.location.href, {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Setup-Ajax': '1' }
                        });
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            return advanceModal(2, 400)
                                .then(function() { return advanceModal(3, 600); })
                                .then(function() { return advanceModal(4, 500); })
                                .then(function() {
                                    modalTitle.textContent = 'Setup abgeschlossen!';
                                    if (data.needsComposer) {
                                        modalComposer.classList.add('visible');
                                    }
                                    modalAction.classList.add('visible');
                                });
                        } else {
                            // Show error
                            setModalStep(modalStepNames[1], 'error');
                            modalTitle.textContent = 'Setup fehlgeschlagen';
                            modalError.innerHTML = (data.errors || ['Unbekannter Fehler']).join('<br>');
                            modalError.classList.add('visible');
                            modalAction.querySelector('.btn').textContent = 'Zurück';
                            modalAction.querySelector('.btn').href = window.location.href;
                            modalAction.classList.add('visible');
                        }
                    })
                    .catch(function(err) {
                        setModalStep(modalStepNames[0], 'error');
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
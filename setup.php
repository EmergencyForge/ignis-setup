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

if (isset($_GET['force_delete']) && $_GET['force_delete'] === 'confirm') {
    $setupFile = __FILE__;
    if (@unlink($setupFile)) {
        header('Location: index.php');
        exit;
    } else {
        die('Fehler: setup.php konnte nicht gelöscht werden. Bitte manuell löschen.');
    }
}

if (isset($_GET['composer_confirmed']) && $_GET['composer_confirmed'] === '1') {
    $setupFile = __FILE__;
    @unlink($setupFile);
    header('Location: index.php');
    exit;
}

$errors = [];
$success = [];
$canProceed = $phpVersionOk && $gitAvailable;

if (!$phpVersionOk) {
    $errors[] = "PHP Version {$phpVersion} ist zu alt. Mindestens PHP {$requiredPhpVersion} wird benötigt!";
    logError("PHP Version Check fehlgeschlagen: {$phpVersion} < {$requiredPhpVersion}");
}

if (!$gitAvailable) {
    $errors[] = "Git ist nicht verfügbar. Git wird für das Setup benötigt!";
    logError("Git ist nicht verfügbar auf diesem System");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canProceed) {

    $gitOutput = [];
    $gitReturnVar = 0;
    $gitBranch = $_POST['git_branch'] ?? 'release';
    $customBranch = trim($_POST['custom_branch'] ?? '');

    exec('git --version 2>&1', $gitOutput, $gitReturnVar);

    if ($gitReturnVar === 0) {
        if (!is_dir('.git')) {
            $gitOutput = [];
            $repoUrl = 'https://github.com/EmergencyForge/intraRP.git';

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
                } elseif ($gitBranch === 'main') {
                    exec('git fetch origin main 2>&1', $gitOutput, $gitReturnVar);
                    exec('git checkout -b main origin/main 2>&1', $gitOutput, $gitReturnVar);

                    if ($gitReturnVar === 0) {
                        exec('git reset --hard origin/main 2>&1', $gitOutput, $gitReturnVar);
                        $success[] = 'Repository initialisiert (Branch: main - experimentell)';
                    }
                } else {
                    exec('git fetch --tags origin 2>&1', $gitOutput, $gitReturnVar);
                    exec('git describe --tags `git rev-list --tags --max-count=1` 2>&1', $latestTag, $gitReturnVar);

                    if ($gitReturnVar === 0 && !empty($latestTag[0])) {
                        exec("git checkout -b release {$latestTag[0]} 2>&1", $gitOutput, $gitReturnVar);
                        exec("git reset --hard {$latestTag[0]} 2>&1", $gitOutput, $gitReturnVar);
                        $success[] = 'Repository initialisiert (Letzter Release: ' . $latestTag[0] . ')';
                    } else {
                        $errors[] = 'Konnte letzten Release-Tag nicht ermitteln.';
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
            } elseif ($gitBranch === 'main') {
                exec('git checkout main 2>&1', $gitOutput, $gitReturnVar);
                exec('git pull origin main 2>&1', $gitOutput, $gitReturnVar);
                $success[] = 'Git Pull erfolgreich (Branch: main - experimentell): ' . implode('<br>', $gitOutput);
            } else {
                exec('git fetch --tags 2>&1', $gitOutput, $gitReturnVar);
                exec('git describe --tags `git rev-list --tags --max-count=1` 2>&1', $latestTag, $gitReturnVar);
                if ($gitReturnVar === 0 && !empty($latestTag[0])) {
                    exec("git checkout {$latestTag[0]} 2>&1", $gitOutput, $gitReturnVar);
                    $success[] = 'Zum letzten Release gewechselt: ' . $latestTag[0];
                } else {
                    $errors[] = 'Konnte letzten Release nicht ermitteln.';
                }
            }

            if ($gitReturnVar !== 0) {
                $errors[] = 'Git Pull/Checkout Fehler: ' . implode('<br>', $gitOutput);
                logError('Git Pull/Checkout Fehler: ' . implode(' | ', $gitOutput));
            }
        }
    }

    // Sanitize and escape environment variables to prevent injection attacks
    // Follows .env file format standards with proper quoting
    function sanitizeEnvValue($value) {
        // Remove any newline characters that could break .env file format
        $value = str_replace(["\r", "\n"], '', $value);
        // Trim whitespace
        $value = trim($value);
        return $value;
    }

    // Format value for .env file with proper quoting and escaping
    function formatEnvValue($value) {
        // Sanitize first
        $value = sanitizeEnvValue($value);
        // Escape backslashes and double quotes
        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        // Wrap in double quotes for safety
        return '"' . $value . '"';
    }

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

            $composerOutput = [];
            $composerReturnVar = 0;
            $composerFailed = false;

            exec('composer --version 2>&1', $composerOutput, $composerReturnVar);

            if ($composerReturnVar === 0) {
                $composerOutput = [];
                exec('composer install --no-dev --optimize-autoloader 2>&1', $composerOutput, $composerReturnVar);

                if ($composerReturnVar === 0) {
                    $success[] = 'Composer Abhängigkeiten erfolgreich installiert!';
                } else {
                    $composerFailed = true;
                    $success[] = 'Composer ist verfügbar, aber Installation fehlgeschlagen. Bitte führen Sie "composer install" manuell aus.';
                    logError('Composer Install Fehler: ' . implode(' | ', $composerOutput));
                }
            } else {
                $composerFailed = true;
                $success[] = 'Composer ist nicht verfügbar. Bitte führen Sie "composer install" manuell aus, bevor Sie das System nutzen.';
            }

            $setupFile = __FILE__;

            if (empty($errors)) {
                if ($composerFailed) {
                    // Composer Warnung anzeigen
                    echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - Composer Warnung</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .warning { color: #ff9800; font-size: 1.5em; margin-bottom: 20px; text-align: center; }
        .message { margin: 20px 0; line-height: 1.6; }
        .code-box { background: #f5f5f5; padding: 15px; border-radius: 6px; border-left: 4px solid #ff9800; margin: 20px 0; font-family: monospace; }
        .buttons { display: flex; gap: 10px; margin-top: 30px; }
        .btn { flex: 1; padding: 15px; border: none; border-radius: 6px; font-size: 1em; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .btn-primary { background: #d10000; color: white; }
        .btn-primary:hover { background: #a00000; }
        .btn-secondary { background: #666; color: white; }
        .btn-secondary:hover { background: #555; }
    </style>
</head>
<body>
    <div class="container">
        <div class="warning">⚠️ Composer-Warnung</div>
        <div class="message">
            <p><strong>Die Composer-Abhängigkeiten konnten nicht automatisch installiert werden.</strong></p>
            <p style="margin-top: 15px;">Das System benötigt Composer-Pakete, um ordnungsgemäß zu funktionieren. Bitte führen Sie folgenden Befehl manuell aus:</p>
            <div class="code-box">composer install --no-dev --optimize-autoloader</div>
            <p><strong>Wichtig:</strong> Das System wird erst nach der Installation der Composer-Abhängigkeiten vollständig funktionieren.</p>
        </div>
        <div class="buttons">
            <form method="GET" action="" style="flex: 1;">
                <input type="hidden" name="composer_confirmed" value="1">
                <button type="submit" class="btn btn-primary">Verstanden, fortfahren</button>
            </form>
            <button onclick="window.location.reload();" class="btn btn-secondary">Zurück zum Setup</button>
        </div>
    </div>
</body>
</html>';
                    exit;
                }

                header('refresh:3;url=index.php');

                echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup erfolgreich</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        .success { color: #28a745; font-size: 1.5em; margin-bottom: 20px; }
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #d10000; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">✓ Setup erfolgreich abgeschlossen!</div>
        <div class="spinner"></div>
        <p>Sie werden in Kürze zum Admin-Panel weitergeleitet...</p>
        <p><small>setup.php wird automatisch gelöscht.</small></p>
    </div>
</body>
</html>';

                @unlink($setupFile);
                exit;
            }
        } else {
            $errors[] = 'Fehler beim Schreiben der .env Datei. Prüfen Sie die Schreibrechte!';
            logError('Fehler beim Schreiben der .env Datei - Schreibrechte prüfen');
        }
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .header {
            background: #d10000;
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
        }

        .content {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }

        .form-group input[type="text"],
        .form-group input[type="url"],
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1em;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #d10000;
        }

        .form-group select {
            background-color: white;
            cursor: pointer;
        }

        .form-group small {
            display: block;
            color: #666;
            margin-top: 5px;
            font-size: 0.9em;
        }

        .form-group small.indented {
            margin-left: 30px;
        }

        .form-group code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            color: #d10000;
        }

        .color-picker-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .color-picker-wrapper input[type="color"] {
            width: 60px;
            height: 45px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            background: white;
        }

        .color-picker-wrapper input[type="color"]::-webkit-color-swatch-wrapper {
            padding: 4px;
        }

        .color-picker-wrapper input[type="color"]::-webkit-color-swatch {
            border: none;
            border-radius: 4px;
        }

        .color-picker-wrapper input[type="text"] {
            flex: 1;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-top: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }

        .section-title {
            font-size: 1.3em;
            color: #d10000;
            margin: 30px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }

        .section-title:first-child {
            margin-top: 0;
        }

        .btn {
            background: #d10000;
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            cursor: pointer;
            width: 100%;
            font-weight: 600;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #a00000;
        }

        .btn-secondary {
            background: #666;
            margin-top: 15px;
        }

        .btn-secondary:hover {
            background: #555;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee;
            border-left: 4px solid #d00;
            color: #c00;
        }

        .alert-success {
            background: #efe;
            border-left: 4px solid #0d0;
            color: #0a0;
        }

        .alert ul {
            margin-left: 20px;
            margin-top: 10px;
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            color: #1976d2;
        }

        .info-box strong {
            display: block;
            margin-bottom: 5px;
        }

        .radio-group {
            margin-top: 10px;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .radio-group label:hover {
            border-color: #d10000;
            background: #fff5f5;
        }

        .radio-group input[type="radio"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
        }

        .radio-group input[type="radio"]:checked+span {
            font-weight: 600;
            color: #d10000;
        }

        .radio-group label span {
            flex: 1;
        }

        .radio-group label small {
            display: block;
            color: #666;
            font-size: 0.85em;
            margin-top: 4px;
        }

        .warning-badge {
            display: inline-block;
            background: #ff9800;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            font-weight: 600;
            margin-left: 8px;
        }

        .custom-branch-input {
            margin-top: 10px;
            display: none;
        }

        .custom-branch-input.active {
            display: block;
        }

        .custom-branch-input input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.95em;
        }

        .requirement-box {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .requirement-box.success {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            color: #2e7d32;
        }

        .requirement-box.error {
            background: #ffebee;
            border-left: 4px solid #f44336;
            color: #c62828;
        }

        .requirement-box strong {
            font-size: 1.1em;
        }

        .requirements-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .requirements-grid {
                grid-template-columns: 1fr;
            }
        }

        .password-wrapper {
            position: relative;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .password-wrapper input {
            flex: 1;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1em;
            transition: border-color 0.3s;
        }

        .password-wrapper input:focus {
            outline: none;
            border-color: #d10000;
        }

        .toggle-password {
            background: #f5f5f5;
            border: 2px solid #e0e0e0;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.3s;
            white-space: nowrap;
            font-weight: 500;
        }

        .toggle-password:hover {
            background: #e8e8e8;
            border-color: #d0d0d0;
        }

        .toggle-password.visible {
            background: #d10000;
            color: white;
            border-color: #d10000;
        }

        .toggle-password.visible:hover {
            background: #a00000;
            border-color: #a00000;
        }

        .pin-input-wrapper {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 2px solid #e0e0e0;
            display: none;
        }

        .pin-input-wrapper.active {
            display: block;
        }

        .pin-input-wrapper input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1.2em;
            text-align: center;
            letter-spacing: 0.3em;
            font-family: monospace;
        }

        .pin-input-wrapper input:focus {
            outline: none;
            border-color: #d10000;
        }

        .pin-input-wrapper small {
            display: block;
            margin-top: 8px;
            color: #666;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>intraRP Setup</h1>
            <p>Konfigurieren Sie Ihr Intranet-System</p>
        </div>

        <div class="content">
            <?php if (!$canProceed): ?>
                <div class="alert alert-error" style="font-size: 1.1em;">
                    <strong>⚠️ SETUP BLOCKIERT</strong>
                    <p style="margin-top: 10px; margin-bottom: 0;">Das Setup kann nicht fortgesetzt werden, da wichtige System-Anforderungen nicht erfüllt sind. Bitte beheben Sie die unten aufgeführten Probleme.</p>
                </div>
            <?php endif; ?>

            <div class="section-title" style="margin-top: <?php echo !$canProceed ? '20px' : '0'; ?>;">System-Anforderungen</div>

            <div class="requirements-grid">
                <div class="requirement-box <?php echo $phpVersionOk ? 'success' : 'error'; ?>">
                    <span style="font-size: 2em;"><?php echo $phpVersionOk ? '✓' : '✗'; ?></span>
                    <div style="flex: 1;">
                        <strong style="font-size: 1.2em;">PHP Version</strong>
                        <div style="font-size: 1em; margin-top: 5px;">
                            <?php if ($phpVersionOk): ?>
                                Installiert: <strong><?php echo $phpVersion; ?></strong>
                                <div style="font-size: 0.85em; opacity: 0.8; margin-top: 2px;">Erforderlich: >= <?php echo $requiredPhpVersion; ?></div>
                            <?php else: ?>
                                <div style="margin-top: 3px;">Installiert: <strong><?php echo $phpVersion; ?></strong></div>
                                <div style="margin-top: 5px; padding: 8px; background: rgba(255,255,255,0.3); border-radius: 4px; font-size: 0.9em;">
                                    <strong>Erforderlich: >= <?php echo $requiredPhpVersion; ?></strong><br>
                                    <small>Bitte aktualisieren Sie PHP über Ihr Hosting-Panel</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="requirement-box <?php echo $gitAvailable ? 'success' : 'error'; ?>">
                    <span style="font-size: 2em;"><?php echo $gitAvailable ? '✓' : '✗'; ?></span>
                    <div style="flex: 1;">
                        <strong style="font-size: 1.2em;">Git</strong>
                        <div style="font-size: 1em; margin-top: 5px;">
                            <?php if ($gitAvailable): ?>
                                <strong>Verfügbar</strong>
                                <div style="font-size: 0.85em; opacity: 0.8; margin-top: 2px;">Git ist installiert und funktionsfähig</div>
                            <?php else: ?>
                                <div style="margin-top: 5px; padding: 8px; background: rgba(255,255,255,0.3); border-radius: 4px; font-size: 0.9em;">
                                    <strong>Nicht verfügbar!</strong><br>
                                    <small>Git muss auf dem Server installiert sein</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Fehler:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #fcc;">
                        <small>📝 Fehler wurden in <code>setup_error.log</code> protokolliert</small>
                    </div>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="info-box" style="margin-bottom: 20px;">
                        <strong>ℹ️ Teilweise erfolgreich</strong>
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

            <form method="POST" action="">
                <div class="section-title">Git Repository</div>

                <div class="form-group">
                    <label>Version auswählen</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="git_branch" value="release" checked>
                            <span>
                                <div>
                                    <strong>Letzter Release</strong>
                                    <span class="warning-badge" style="background: #4caf50;">EMPFOHLEN</span>
                                </div>
                                <small>Stabile Version - empfohlen für Produktivumgebungen</small>
                            </span>
                        </label>
                        <label>
                            <input type="radio" name="git_branch" value="main">
                            <span>
                                <div>
                                    <strong>Main Branch</strong>
                                    <span class="warning-badge">EXPERIMENTELL</span>
                                </div>
                                <small>Neueste Entwicklungsversion - kann instabil sein</small>
                            </span>
                        </label>
                        <?php if ($devMode): ?>
                            <label>
                                <input type="radio" name="git_branch" value="custom" id="custom_branch_radio">
                                <span>
                                    <div>
                                        <strong>Custom Branch</strong>
                                        <span class="warning-badge" style="background: #9c27b0;">DEV</span>
                                    </div>
                                    <small>Eigenen Branch angeben · für Entwicklung</small>
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

                <script>
                    <?php if ($devMode): ?>
                        const customRadio = document.getElementById('custom_branch_radio');
                        const customInput = document.getElementById('custom_branch_input');
                        const customField = document.getElementById('custom_branch_field');
                        const allRadios = document.querySelectorAll('input[name="git_branch"]');

                        allRadios.forEach(radio => {
                            radio.addEventListener('change', function() {
                                if (this.value === 'custom') {
                                    customInput.classList.add('active');
                                    customField.focus();
                                } else {
                                    customInput.classList.remove('active');
                                }
                            });
                        });
                    <?php endif; ?>
                </script>

                <div class="section-title">Datenbank-Konfiguration</div>

                <div class="form-group">
                    <label for="db_host">Datenbank-Host</label>
                    <input type="text" id="db_host" name="db_host" value="localhost" required>
                    <small>Host der Datenbank (meistens <code>localhost</code>)</small>
                </div>

                <div class="form-group">
                    <label for="db_user">Datenbank-Benutzer</label>
                    <input type="text" id="db_user" name="db_user" value="root" required>
                    <small>Benutzername für die Datenbank</small>
                </div>

                <div class="form-group">
                    <label for="db_pass">Datenbank-Passwort</label>
                    <div class="password-wrapper">
                        <input type="password" id="db_pass" name="db_pass">
                        <button type="button" class="toggle-password" onclick="togglePassword('db_pass', this)">Anzeigen</button>
                    </div>
                    <small>Passwort für die Datenbank (optional)</small>
                </div>

                <div class="form-group">
                    <label for="db_name">Datenbank-Name *</label>
                    <input type="text" id="db_name" name="db_name" value="intrarp" required>
                    <small>Name der zu verwendenden Datenbank</small>
                </div>

                <div class="section-title">Discord-Integration</div>

                <div class="info-box">
                    <strong>ℹ️ Discord Applikation benötigt</strong>
                    Für die Discord-Integration muss eine Discord-Applikation erstellt werden. Eine detaillierte Anleitung finden Sie hier:
                    <a href="https://emergencyforge.de/wiki.html#discord-app-erstellen" target="_blank" style="color: #1976d2; font-weight: 600;">Discord-Applikation erstellen →</a>
                </div>

                <div class="form-group">
                    <label for="discord_client_id">Discord Client ID *</label>
                    <input type="text" id="discord_client_id" name="discord_client_id" required>
                    <small>Client ID der Discord-Anwendung</small>
                </div>

                <div class="form-group">
                    <label for="discord_client_secret">Discord Client Secret *</label>
                    <div class="password-wrapper">
                        <input type="password" id="discord_client_secret" name="discord_client_secret" required>
                        <button type="button" class="toggle-password" onclick="togglePassword('discord_client_secret', this)">Anzeigen</button>
                    </div>
                    <small>Client Secret der Discord-Anwendung</small>
                </div>

                <div class="section-title">System-Konfiguration</div>

                <div class="form-group">
                    <label for="domain">Domain</label>
                    <input type="text" id="domain" name="domain" value="<?php echo htmlspecialchars($defaultDomain); ?>" required>
                    <small>Die Domain unter der das System erreichbar ist (ohne http/https)</small>
                </div>

                <div class="form-group">
                    <label for="base_path">Base Path</label>
                    <input type="text" id="base_path" name="base_path" value="<?php echo htmlspecialchars($defaultBasePath); ?>" required>
                    <small>Der Pfad zur Installation (z.B. <code>/</code> für Root oder <code>/intrarp/</code> für Unterverzeichnis)</small>
                </div>

                <script>
                    function togglePassword(fieldId, button) {
                        const field = document.getElementById(fieldId);
                        if (field.type === 'password') {
                            field.type = 'text';
                            button.textContent = 'Verbergen';
                            button.classList.add('visible');
                        } else {
                            field.type = 'password';
                            button.textContent = 'Anzeigen';
                            button.classList.remove('visible');
                        }
                    }
                </script>

                <div class="info-box">
                    <strong>ℹ️ Hinweis:</strong>
                    Die hier eingegebenen Datenbank- und Discord-Credentials werden in der <code>/.env</code> Datei gespeichert und können später dort angepasst werden. Alle weiteren System-Einstellungen (z.B. System-Name, Farben, Server-Informationen) werden nach dem Setup über das Admin-Panel in der Datenbank konfiguriert.
                </div>

                <button type="submit" class="btn" <?php echo !$canProceed ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Setup durchführen</button>
            </form>
        </div>
    </div>
</body>

</html>
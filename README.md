# intraRP Setup

> [!IMPORTANT]
> **Dieses Repository ist archiviert.** Der Setup-Wizard wird nicht mehr separat heruntergeladen — er liegt jedem ignis-Release als Install-Package bei: einfach `ignis-<version>-install.zip` von der [Release-Seite](https://github.com/EmergencyForge/ignis/releases/latest) laden, entpacken und der `readme.txt` folgen. Der Release-Build bezieht `setup.php` weiterhin aus diesem Repo.

Setup-Wizard für [ignis](https://github.com/EmergencyForge/ignis) (früher intraRP) — ein Intranet-Rollenspielsystem mit Discord-Integration für FiveM-Feuerwehr- und Rettungsdienst-Communities.

Das Script entpackt das mitgelieferte Release-Archiv (bzw. lädt es herunter, wenn keines beiliegt), führt die Datenbank-Migrations aus, erstellt die `.env` und löscht sich anschließend selbst.

## Voraussetzungen

### PHP

- **PHP ≥ 8.2** (empfohlen: 8.3 oder 8.4)
- Folgende Extensions müssen aktiviert sein:

| Extension               | Zweck                          |
|-------------------------|--------------------------------|
| `pdo`                   | Datenbank-Basis                |
| `pdo_mysql`             | MySQL-Treiber                  |
| `mbstring`              | String-Handling (Twig, OAuth2) |
| `openssl`               | HTTPS, OAuth2-Tokens           |
| `fileinfo`              | Upload-MIME-Erkennung          |
| `dom`                   | XML/HTML-Parser (Twig, dompdf) |
| `xml`                   | XML-Parser                     |
| `json`                  | JSON-Serialisierung            |
| `session`               | Benutzer-Sessions              |
| `intl`                  | Internationalisierung          |
| `zip`                   | Release-ZIP entpacken          |
| `curl`                  | HTTPS-Downloads                |
| `filter`                | Input-Validierung              |
| `ctype`                 | String-Validierung             |
| `gd` **oder** `imagick` | PDF-Generierung (dompdf)       |

### PHP-Konfiguration

Das Release-ZIP ist ca. 100 MB groß. Folgende `php.ini`-Werte werden empfohlen:

- `memory_limit = 512M`
- `max_execution_time = 300`
- `upload_max_filesize = 256M`
- `post_max_size = 256M`

### Sonstiges

- MySQL-Datenbank (muss vorab angelegt werden — Tabellen werden automatisch erstellt)
- [Discord-Applikation](https://wiki.emergencyforge.de/erste-schritte/discord-app-erstellen/) (Client ID + Secret)
- Git — nur für Main/Custom Branch (Entwicklermodus)
- `exec()`-Berechtigung — nur für Main/Custom Branch (Composer-Autoinstall)

## Installation

1. `setup.php` in das gewünschte Webserver-Verzeichnis hochladen
2. Sicherstellen dass das Verzeichnis für PHP **beschreibbar** ist (`chmod 0775`)
3. Im Browser aufrufen (z.B. `https://example.com/setup.php`)
4. Dem 6-Schritt-Wizard folgen:
   - **Prüfung** — PHP-Version, Extensions, Schreibrechte, Download-Methode
   - **Version** — Release-ZIP oder Branch auswählen
   - **Datenbank** — Zugangsdaten eingeben und Verbindung testen
   - **System** — Domain und Base Path bestätigen
   - **Discord** — Client ID und Secret eintragen + live testen
   - **Übersicht** — Eingaben prüfen und abschicken
5. Setup abschicken — der Wizard lädt das ZIP, führt die DB-Migrations aus, schreibt die `.env` und löscht sich selbst

Die Discord-Redirect-URI wird im System-Step automatisch angezeigt — diese muss in der Discord-Applikation unter **OAuth2 → Redirects** eingetragen werden.

## Entwicklermodus

Über `setup.php?dev` kann ein Custom Branch gewählt werden. Dafür muss Git auf dem Server installiert sein. Der Wizard versucht automatisch, Composer herunterzuladen und `composer install --no-dev -o` auszuführen — wenn `exec()` gesperrt ist, wird eine manuelle Anleitung gezeigt.

## Debug-Modus

Mit `setup.php?debug` wird `display_errors` aktiviert und PHP-Fehler werden direkt im Browser ausgegeben. Für Produktivumgebungen **nicht** verwenden.

## Troubleshooting

**„Verzeichnis nicht beschreibbar"**
Der PHP-User (meistens `www-data` oder der FPM-Pool-User) muss ins Setup-Verzeichnis schreiben dürfen. Lösung: `chown www-data .` und `chmod 0775 .` im Setup-Verzeichnis.

**„Extension XY fehlt"**
Shared-Hoster: im Kundenpanel nach „PHP-Module" suchen und die fehlende Extension aktivieren. VPS: `apt install php8.2-<name>` bzw. das Äquivalent der Distribution, dann PHP-FPM neu starten.

**„GitHub API Fehler" / Rate-Limit**
Das Setup kontaktiert `api.github.com/repos/EmergencyForge/intraRP/releases/latest`. Bei Rate-Limit-Überschreitung fällt es automatisch auf die nicht-authenticated Redirect-Route zurück. Wenn das auch scheitert: kurz warten (GitHub limitiert 60 requests/h pro IP) oder mit `?dev` den Branch-Modus wählen.

**„Discord lehnte ab: invalid_client"**
Die Client ID oder das Secret ist falsch. Im Discord Developer Portal unter der App → OAuth2 → Reset Secret, dann den neuen Wert eintragen.

**„setup.php konnte nicht automatisch gelöscht werden"**
Nach erfolgreichem Setup muss `setup.php` manuell aus dem Webroot entfernt werden — sonst könnte jemand das System re-installieren und dabei vorhandene Daten überschreiben.

**Migration-Warnung im Abschluss-Screen**
Wenn die Phinx-Migrations nicht automatisch liefen (z.B. weil `vendor/` fehlt), versucht intraRP sie beim ersten Web-Request erneut auszuführen. Falls das ebenfalls scheitert: im Projekt-Root `vendor/bin/phinx migrate -e production` manuell ausführen.

## Sicherheitshinweise

- Das Setup nutzt einen **CSRF-Token** und ein **Rate-Limit** (20 Requests/Minute), um automatisierte Angriffe abzuwehren
- Der generierte `APP_KEY` ist ein 32-Byte Zufallswert aus `random_bytes()` — wird für künftige Token-Signaturen verwendet
- Das Setup loggt Fehler ausschließlich in der PHP-Session, **nicht** in eine Datei — damit nach dem Setup keine Logs mit Pfaden/Credentials zurückbleiben
- Beim Branch-Update prüft das Setup, ob ein vorhandenes `.git`-Verzeichnis tatsächlich auf `EmergencyForge/intraRP` zeigt, bevor es `git reset --hard` ausführt

## Lizenz

[GPL-3.0](LICENSE.md)

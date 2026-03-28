# intraRP Setup

Setup-Wizard für [intraRP](https://github.com/EmergencyForge/intraRP) — ein Intranet-Rollenspielsystem mit Discord-Integration.

Dieses Script klont das Repository, konfiguriert die Datenbankverbindung und Discord-Anbindung und erstellt die `.env`-Datei. Nach Abschluss löscht es sich selbst.

## Voraussetzungen

- PHP >= 8.1
- Git (auf dem Server installiert)
- MySQL-Datenbank
- [Discord-Applikation](https://emergencyforge.de/wiki/discord-app-erstellen) (Client ID + Secret)

## Installation

1. `setup.php` in das gewünschte Webserver-Verzeichnis hochladen
2. Im Browser aufrufen (z.B. `https://example.com/setup.php`)
3. Dem 5-Schritt-Wizard folgen:
   - **Prüfung** — PHP-Version und Git werden automatisch geprüft
   - **Git** — Release-Branch auswählen
   - **Datenbank** — Zugangsdaten eingeben und Verbindung testen
   - **Discord** — Client ID und Secret eintragen
   - **System** — Domain und Base Path bestätigen
4. Setup abschicken — fertig

## Lizenz

[GPL-3.0](LICENSE.md)

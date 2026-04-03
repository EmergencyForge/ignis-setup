# intraRP Setup

Setup-Wizard für [intraRP](https://github.com/EmergencyForge/intraRP) — ein Intranet-Rollenspielsystem mit Discord-Integration.

Dieses Script lädt das aktuelle Release-ZIP herunter, konfiguriert die Datenbankverbindung und Discord-Anbindung und erstellt die `.env`-Datei. Die Datenbank wird vom System automatisch erstellt. Nach Abschluss löscht sich das Script selbst.

## Voraussetzungen

- PHP >= 8.1
- MySQL-Datenbank
- [Discord-Applikation](https://wiki.emergencyforge.de/erste-schritte/discord-app-erstellen/) (Client ID + Secret)
- Git — nur für Main/Custom Branch (Entwicklermodus)

## Installation

1. `setup.php` in das gewünschte Webserver-Verzeichnis hochladen
2. Im Browser aufrufen (z.B. `https://example.com/setup.php`)
3. Dem 5-Schritt-Wizard folgen:
   - **Prüfung** — PHP-Version wird automatisch geprüft
   - **Version** — Release-ZIP oder Branch auswählen
   - **Datenbank** — Zugangsdaten eingeben und Verbindung testen
   - **Discord** — Client ID und Secret eintragen
   - **System** — Domain und Base Path bestätigen
4. Setup abschicken — fertig

Das Release-ZIP (`intraRP-{version}.zip`) wird direkt von GitHub heruntergeladen und enthält alle Abhängigkeiten.

## Entwicklermodus

Über `setup.php?dev` kann ein Custom Branch gewählt werden. Dafür muss Git auf dem Server installiert sein.

## Lizenz

[GPL-3.0](LICENSE.md)

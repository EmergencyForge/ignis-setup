# ignis Setup

Der Installationsassistent für [ignis](https://github.com/EmergencyForge/ignis).

> [!IMPORTANT]
> **Du willst ignis installieren?** Lade das Install-Package
> (`ignis-<version>-install.zip`) vom [aktuellen Release](https://github.com/EmergencyForge/ignis/releases/latest)
> herunter. Es enthält `setup.php`, die Anwendung und eine
> Schritt-für-Schritt-Anleitung (`readme.txt`) — die Datei aus diesem Repo
> musst du nicht mehr einzeln herunterladen.

## Was dieses Repo ist

Hier lebt der Quellcode von `setup.php`. Der Release-Build von ignis holt die
Datei bei jedem Release automatisch von hier und packt sie ins Install-Package.

Der Assistent kennt zwei Modi:

- **Install-Package** (Standard): entpackt das mitgelieferte `ignis-*.zip`
  neben sich — kein Download nötig. Nach erfolgreicher Installation löschen
  sich Archiv, `readme.txt` und `setup.php` selbst.
- **Standalone**: liegt kein Archiv daneben, lädt der Assistent das aktuelle
  Release von GitHub (alternativ main/Custom-Branch via Git, `setup.php?dev`).

In beiden Fällen führt der Wizard durch Anforderungs-Check, Datenbank,
Domain/Base-Path und Discord-OAuth, führt die Migrations aus und schreibt die
`.env`. Die Discord-Redirect-URI wird im System-Schritt angezeigt und muss in
der Discord-Applikation unter **OAuth2 → Redirects** eingetragen werden.

## Troubleshooting

**„Verzeichnis nicht beschreibbar"** — der PHP-User (`www-data` o. ä.) braucht
Schreibrechte im Setup-Verzeichnis: `chown www-data . && chmod 0775 .`

**„Extension XY fehlt"** — Shared-Hosting: im Kundenpanel unter „PHP-Module"
aktivieren. VPS: `apt install php8.4-<name>`, dann PHP-FPM neu starten.

**„setup.php konnte nicht automatisch gelöscht werden"** — dann bitte manuell
entfernen; das Lockfile `.setup-locked` verhindert zwar Re-Runs, aber die
Datei gehört trotzdem nicht dauerhaft in den Webroot.

**Migration-Warnung im Abschluss-Screen** — ignis versucht die Migrations beim
ersten Web-Request erneut; falls auch das scheitert:
`vendor/bin/phinx migrate -e production` im Projekt-Root.

## Entwicklung

```bash
php -l setup.php   # Syntax-Check (läuft als CI auf jedem Push)
```

Zum Testen des Install-Package-Modus ein Release-ZIP als `ignis-test.zip`
neben die `setup.php` legen.

## Lizenz

[GPL-3.0](LICENSE.md) — wie ignis selbst.

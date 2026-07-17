# Changelog

## 0.6.0 - 2026-07-16

### Added
- Berechtigungen: 6 neue Schalter im Control Panel („Berechtigungen — was darf Deon AI ändern?"), mit denen der Kunde selbst entscheidet, was Deon AI ändern darf — `allowSeoMeta` (Standard an), `allowContentEdit`, `allowPageCreate`, `allowFiles`, `allowAssets` (Standard jeweils aus), `allowSelfUpdate` (Standard an, siehe Remote-Self-Update)
- Schreibende Endpoints prüfen jetzt die passende Berechtigung und liefern `403 { ok: false, error: "consent_required", permission: "…" }`, solange sie nicht freigegeben ist: `/deon-ai/seo` → `seo_meta`, `/deon-ai/faq` → `content_edit`, `/deon-ai/entry` + `/deon-ai/page` → `page_create`, `/deon-ai/files` + `/deon-ai/hygiene` → `files`, `/deon-ai/asset` → `assets`, `/deon-ai/self-update` → `self_update`. Lese-Endpoints (`ping`, `seo-list`, `entries`, `hygiene-list`, `rollback/*`) bleiben ungegated — Rückgängig machen funktioniert immer.
- `/deon-ai/ping` liefert jetzt ein `permissions`-Objekt mit dem aktuellen Freigabe-Stand aller sechs Kategorien, damit Deon AI nicht freigegebene 1-Klick-Fixes im Dashboard ausgrauen kann. Zusätzlich `plugin_version`/`craft_version`/`php_version` (Aliase der bestehenden Felder), ein Fähigkeits-Flag `self_update` (kann dieser Server technisch überhaupt selbst updaten — proc_open, Speicher, composer.phar) und `sections_ok` (prüft, ob die konfigurierten Section-Handles für Blog/Seiten tatsächlich existieren).
- Remote-Self-Update: `POST /deon-ai/self-update` (`{ version }`) hebt das Plugin per Composer auf eine konkrete Zielversion an (nur das eigene Paket, nie `craft update all`), danach `POST /deon-ai/up` in einem neuen Request, um die Plugin-Migrationen auszuführen — zwei Phasen, weil nach dem Composer-Swap im selben Request noch der alte Klassen-Code geladen ist. Preflight prüft `proc_open`, `memory_limit` und `composer.phar`, bevor überhaupt etwas angefasst wird; schlägt er fehl, bleibt die Installation unverändert (`422 self_update_unavailable`). Fail-soft DB-Backup vor dem Swap. Konsolen-Fallback `php craft deon-ai-connect/update <version>` für Hosting, auf dem Composer im Web-Request an exec-/Speicher-Limits scheitert.

## 0.5.0 - 2026-07-16

### Added
- `/deon-ai/files` — robots.txt/llms.txt direkt im Webroot lesen/schreiben (strikte Dateinamen-Whitelist, Backup vor jedem Schreiben)
- `/deon-ai/faq` — FAQ-Block sichtbar in den Entry-Body einbauen, idempotent über einen `data-deon-faq`-Marker (ersetzt statt zu duplizieren)
- `/deon-ai/page` — native Seiten anlegen (Standortseiten, KI-Faktenseite), eigene Section-Auflösung über neues Setting `pagesSectionHandle`, geht standardmäßig als Entwurf raus
- Neue Tabelle `deonai_content_backups` — Vorher-Inhalt wird vor jeder `/files`- oder `/faq`-Änderung fail-soft gesichert

### Fixed
- **Install.php enthielt nur die ursprüngliche `deonai_seo_overrides`-Tabelle.** Da Craft bei einer Neuinstallation ausschließlich `Install.php` ausführt und alle zu dem Zeitpunkt bereits vorhandenen nummerierten Migrationen ungeprüft als "erledigt" markiert (ohne sie laufen zu lassen), fehlten frischen Installationen bisher `deonai_seo_hygiene` und `deonai_change_log` komplett. `Install.php` enthält jetzt den vollständigen Tabellenstand.

## 0.4.2 - 2026-07-15

### Changed
- Plugin-Store-Vorbereitung: `composer.json` ohne `version`-Feld (Versionen kommen aus Git-Tags), `support.source` ergänzt; `LICENSE.md` → `LICENSE.txt`; `.github/workflows/create-release.yml` für automatische GitHub-Releases bei neuen, vom Craft Plugin Store erkannten Tags. Keine funktionale Änderung am Plugin selbst.

## 0.4.1 - 2026-07-15

### Fixed
- **Kritisch:** Bootstrap-Save (v0.4.0) hat beim Speichern nur `siteId`/`sdkKey`/`verificationUuid` an `savePluginSettings()` übergeben — Craft merged dabei nicht mit den bestehenden Settings, wodurch `apiKey` (und alle anderen Felder) auf ihre Defaults zurückgesetzt wurden. Der Connection-Key erschien danach leer, obwohl die Verbindung erfolgreich war. Betroffene Installationen (Key wurde geleert) müssen den Connection-Key einmal neu eintragen; ab diesem Fix bleibt er erhalten.

## 0.4.0 - 2026-07-15

### Changed
- Ein-Key-Onboarding: Setup verlangt jetzt nur noch den "Deon AI Connection-Key" statt vier separaten Feldern (API-Key, Site-ID, SDK-Key, Verifizierungs-UUID). Beim Speichern holt das Plugin Site-ID, SDK-Key und Verifizierungs-UUID automatisch per Bootstrap-Call (`GET https://audit.deon-ai.de/api/plugin/craft/bootstrap`, authentifiziert über denselben Key) — analog zum WordPress-Plugin-Flow. Fail-soft: schlägt der Bootstrap fehl, bleiben gespeicherte Settings unverändert, nur eine CP-Meldung informiert.

## 0.3.0 - 2026-07-15

### Added
- Änderungsprotokoll mit Rollback: jede Deon-AI-Änderung (SEO-Override, Entry, robots.txt/llms.txt) speichert automatisch den Vorher-Zustand — kein separater Backup-Schritt, funktioniert auf jedem Hosting (reines SQL, kein `shell_exec`/`mysqldump` nötig)
- `/deon-ai/rollback/list`, `/rollback/<rb_id>`, `/rollback/<rb_id>/preview`, `/rollback/<rb_id>/restore` — folgt derselben Proxy-Konvention wie das WordPress-/TYPO3-Plugin, erscheint damit im bestehenden "Änderungs-Journal"-Tab des Dashboards statt eines eigenen, unverbundenen Endpoints
- `/deon-ai/rollback/restore-point` — kompletter Sicherungspunkt (Snapshot aller SEO-Overrides, robots.txt/llms.txt, Entries) als reines SQL-Snapshot
- Konflikt-Erkennung: `restore` bricht ab (HTTP 409), wenn der Live-Zustand seit der Deon-AI-Änderung manuell verändert wurde — überschreibbar mit `force: true`
- Alle schreibenden Endpoints (`/deon-ai/seo`, `/deon-ai/entry`, `/deon-ai/hygiene`) akzeptieren optional `note` und geben `rollback_id` in der Antwort zurück

## 0.2.0 - 2026-07-14

### Added
- robots.txt/llms.txt-Verwaltung am Origin: neues Setting `manageRobotsLlms`, Endpoints `/deon-ai/hygiene` (setzen) und `/deon-ai/hygiene-list` (auslesen), ausgeliefert über `/robots.txt` und `/llms.txt`
- `/deon-ai/entries` — bestehende Entries einer Section auflisten (Duplikat-Check vor dem Anlegen)
- `/deon-ai/asset` — Bild-Upload (URL oder Base64) für Featured Images, Settings `assetVolumeHandle` + `featuredImageFieldHandle`
- `/deon-ai/entry` unterstützt jetzt `section`/`body_field`-Override sowie `image_url`/`asset_id` für Featured Images — Multi-Section-Publishing ohne separate Plugin-Installation

### Changed
- Feature-Parität zum WordPress-Plugin (AideonConnect) angenähert: SEO-Hygiene (robots/llms) und erweiterte Content-API waren zuvor WP/TYPO3-exklusiv

## 0.1.1 - 2026-07-10

### Fixed
- Falsche Repo-URLs in composer.json (`support.issues`, `extra.changelogUrl`) korrigiert — der 0.1.0-Tag zeigte noch auf `github.com/baestmarketing/craft-connect` statt `baestmarketing-dot/craft-connect`

## 0.1.0 - 2026-07-02

### Added
- SEO-Overrides (Title, Meta-Description, Canonical, Schema.org) — serverseitig ins Frontend-HTML gepatcht, sichtbar für alle Crawler inkl. KI-Bots
- Automatische SDK- und Verifizierungs-Tag-Injection (kein Template-Edit nötig)
- REST-Endpoints für Deon AI: `/deon-ai/ping`, `/deon-ai/seo`, `/deon-ai/seo-list`, `/deon-ai/entry`
- Blog-Publishing in konfigurierbare Section (Entwurf/live)
- Settings-Seite im Control Panel (Env-Var-Support)

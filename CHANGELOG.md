# Changelog

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

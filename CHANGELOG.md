# Changelog

## 0.11.0 - 2026-07-20

### Added
- Plugin liefert jetzt ein eigenes, self-contained Artikel-Template (`templates/entry.twig`, adressierbar als `deon-ai/entry` über einen registrierten Site-Template-Root) — kein Theme-Dependency, kein `{% extends %}`, eigenes Minimal-CSS, keine Deon-Werbung. Behebt den Pilot-Fall, dass ein Custom-Theme-Template Entry-Variablen gar nicht ausgibt und Blog-Artikel dadurch leer bleiben (Titel/Body/Bild liegen korrekt im Entry, nur das Section-Template rendert nichts davon).
- `setup-blog` setzt dieses Template jetzt direkt auf neu angelegte Sections (`deonBlog`/`deonPages`), statt sie leer zu lassen und nur `template_missing` zu melden.
- `setup-blog` repariert außerdem **bestehende** Blog-/Seiten-Sections mit leerem Template automatisch — und mit optionalem Body-Flag `{ "fix_template": true }` auch Sections mit einem gesetzten, aber offensichtlich kaputten Template (der Worker prüft das serverseitig, bevor er das Flag sendet). Eine funktionierende Custom-Konfiguration wird nie stillschweigend überschrieben. Response neu: `template`/`previous_template` (Blog-Section, primärer Contract-Wert) sowie additiv `template_pages`/`previous_template_pages`. Jede Template-Änderung ist rollback-fähig (neuer Change-Log-Typ `section_template`).

### Fixed
- **Kritisch, seit v0.1.0:** Sämtliche Section-Operationen (`getSectionByHandle`, `saveSection`, `saveEntryType`, `getAllSections`) riefen `Craft::$app->getEntries()` auf. Das funktioniert nur auf Craft 5 — dort wurden Section-Methoden in den Entries-Service gemergt. Auf Craft 4 existieren sie ausschließlich im separaten `craft\services\Sections` (`Craft::$app->getSections()`); `craft\services\Entries` kennt dort nur Entry-Element-Methoden. Jeder schreibende Endpoint, der Sections anfasst (`/entry`, `/page`, `/entries`, `/setup-blog`, `/duplicate-page`, `/pages`, `/nav`-Structure-Fallback u. a.), war auf Craft 4 dadurch ein Fatal Error — trotz `composer.json`-Anspruch `craftcms/cms: ^4.0.0|^5.0.0`. Neuer versionsübergreifender Helper `sectionsService()` löst den richtigen Service anhand des registrierten Component-Namens auf, ohne `version_compare`.

## 0.10.0 - 2026-07-19

### Added
- `POST /deon-ai/audit-fix` — Content-Write-Fixes vom Deon-AI-Worker (Contract = WP `/audit-fix`, beschränkt auf die zwei für Craft nötigen Actions; alle anderen Fix-Actions laufen bereits über `/deon-ai/seo` bzw. `/deon-ai/faq`):
  - `replace_content` — kompletten Body-HTML ersetzen (Freshness-Refresh). Bewusst ohne HTML-Stripping — authentifizierter Plugin-Kontext, der CKEditor-/Redactor-Purifier greift beim Rendern.
  - `append_html_box` — HTML-Box idempotent anhängen (interne Verlinkung, Pillar-Backrefs): existiert bereits ein `<aside class="…{box_marker}…">`-Block (Default-Marker `deon-cluster-ref`, konfigurierbar über `payload.box_marker`), wird er ersetzt statt dupliziert — Marker-Sanitize und Regex identisch zum WP-Plugin.
  - Beide Actions mit Rollback-Snapshot (Pflichtfeld `rollback_id` in der Response), Entry-Auflösung per `page_url` (URI, Fallback Slug), Berechtigung `allowContentEdit`.
  - Response liefert `meta_keys_applied` (exakter WP-Key) **und** `applied` als Alias, inkl. `no_change`-Erkennung wie im WP-Original.

## 0.9.0 - 2026-07-17

### Added
Section-Tests + A/B-Varianten — der letzte fehlende Funktionsblock aus dem WordPress-Plugin, Craft-nativ adaptiert (WP arbeitet auf Gutenberg-/Elementor-/Fusion-Strukturen; in Craft sind die „Sections" die Top-Level-Elemente des Body-HTML, builder `html`):

- `POST /deon-ai/section-test/create` — Variante als geklonter, deaktivierter Entry (`duplicateElement`), Section-Änderungen (`insert`/`remove`/`move`/`replace`, Selector = Index oder `tag[n]`) per DOM-Engine auf den Body angewandt. Neue Tabelle `deonai_section_tests`.
- Server-seitiger 50/50-Split beim Ausspielen: Cookie `aideon_st_<id>` (Name identisch zu WP, damit die SDK-Conversion-Attribution gleich funktioniert), Bot-Ausschluss, `Cache-Control: no-store` + `Vary: Cookie`, Besucher-Zähler. Variante B ersetzt den Original-Body im gerenderten HTML.
- `GET /deon-ai/section-test/list/<id>`, `POST /deon-ai/section-test/preview` (anwenden ohne speichern), `POST /deon-ai/section-test/stop` — Winner B wird mit Rollback-Snapshot ins Original gemerged, die Variante wandert in den Craft-Papierkorb (weiches Löschen statt WP-Force-Delete).
- `POST /deon-ai/publish-winner` — Änderungs-Liste anwenden: `seo_meta` → SEO-Override, `content_replace` → Body-Austausch, `html_section` → Section-Replace (Craft-Pendant zu `elementor_section`, das einen klaren Fehler meldet). Immer mit Rollback-Snapshot.
- `POST /deon-ai/ab-variant/create`, `GET /deon-ai/ab-variant/list/<id>`, `POST /deon-ai/ab-variant/stop` — Selector-basierte A/B-Varianten (alle WP-Modi: `text`/`html`/`attr`/`link`/`style`/`form`), neue Tabelle `deonai_ab_variants`. Ausspielung über ein 1:1 portiertes Frontend-Snippet (Cookie `aideon_ab_assign`, `?aideon_force=`-Preview mit Banner, `sendBeacon`-Impression-Tracking).
- `POST /deon-ai/configure-ab` + `GET /deon-ai/ab-status`, `POST /deon-ai/configure-tracker` + `GET /deon-ai/tracker-status` — Remote-Konfiguration (WP-Shapes); `tracker_enabled=false` schaltet zusätzlich zur CP-Einstellung die SDK-Injection ab.
- `/deon-ai/ping`-`capabilities` um `content_replace`, `section_test`, `ab_variant_split`, `ab_script_inject`, `tracker_inject` erweitert.

## 0.8.0 - 2026-07-17

### Added
Seiten-Anbindung — Contract-Parität zum WordPress-Plugin aideon-connect (v3.67.0), damit Deon AI Craft-Seiten lesen, im Original-Design klonen und texturieren kann. Neue Endpoints (Response-Shapes bewusst identisch zu WP, damit der Worker 1:1 durchreichen kann):

- `GET /deon-ai/match-url?url=` — Entry per URL finden (inkl. Slug-Fallback)
- `GET /deon-ai/pages` — Entries aller Sections, nach Änderungsdatum (ergänzt `/entries`, das nur eine Section pro Aufruf listet)
- `GET /deon-ai/page-structure/<id>` — kompletter Seiteninhalt: Titel, Slug, Body-HTML, SEO-Override, plus walkbare Text-Blöcke (`content_blocks` mit `pc-N`-IDs: h1–h3 = `title`, p = `editor` — identisch zum builder-agnostischen WP-Contract)
- `POST /deon-ai/set-widget-texts` — gezielte Text-Sets per `pc-N` auf den Entry-Body (SEO-Texturierung geklonter Standortseiten), mit Rollback-Protokoll
- `POST /deon-ai/duplicate-page` — 1:1-Seiten-Klon mit find/replace-Textaustausch, `h1_override`, SEO-Metas, idempotent per `page_id` (der Standortseiten-Pfad im Original-Design)
- `GET /deon-ai/render-preview` — gerendertes Frontend-HTML für die Dashboard-Preview; HMAC-Preview-Token (gleiches Format wie WP: 60s TTL) + Same-Origin-Guard
- `POST /deon-ai/publish-lp` — Full-Page-Landingpage aus Roh-HTML (inkl. `<style>`/`<script>`), neue Tabelle `deonai_landing_pages`, ausgeliefert über eigene Route pro Slug, idempotent per `page_id`/Slug
- `GET /deon-ai/theme-tokens` — Design-Tokens (Farben, Fonts, Radius, Palette). Craft hat kein theme.json, daher CSS-Extraktion aus der gerenderten Startseite inkl. einstufiger `var(--x)`-Auflösung (`source: "css_extract"`)
- `POST /deon-ai/site-schema` — Site-weites JSON-LD (Organization/LocalBusiness), ausgespielt im `<head>` aller Seiten
- `GET /deon-ai/sitemap-discover` — Sitemap-URL-Kandidaten
- `GET|POST /deon-ai/footer-links` — Plugin-eigener Footer-Block („Servicegebiete") vor `</body>`, Markup identisch zum WP-Pendant
- `/deon-ai/ping` liefert jetzt eine `capabilities`-Liste (Namensschema wie WP `/capabilities`) für einheitliches Feature-Gating im Worker

### Changed
- `/deon-ai/hygiene-list` liefert nur noch die Typen `robots`/`llms` (die Tabelle speichert jetzt zusätzlich `site_schema`/`footer_links`)

## 0.7.0 - 2026-07-17

### Added
- `POST /deon-ai/setup-blog` — Blog-/Seiten-Bootstrap für Pilotinnen ohne bestehendes Schema: legt bei Bedarf ein Body-Feld (`deonBody`, CKEditor falls installiert → Redactor falls installiert → PlainText multiline), ein Featured-Image-Feld (`deonFeaturedImage`, auf das erste vorhandene Volume beschränkt, wird übersprungen statt ein Volume zu erfinden) sowie die Sections `deonBlog` (Channel, `blog/{slug}`) und `deonPages` (Structure, `{slug}`) an — jeweils idempotent, bestehende Handles werden nie überschrieben. Verdrahtet leere/ungültige Plugin-Settings automatisch mit den neuen Handles. Meldet fehlende Section-Templates statt sie selbst anzulegen (liefert stattdessen ein Beispiel-Template im Response mit).
- `/deon-ai/entry` und `/deon-ai/page` unterstützen jetzt beide `image_url`/`asset_id` fürs Featured Image (bisher nur `/entry`) — fail-soft, ein Bildfehler blockiert den Entry/die Seite nie.
- `/deon-ai/ping` liefert zusätzlich `fields_ok: { body, featured_image }` (echte Feld-Existenz, nicht nur ob das Setting gesetzt ist) sowie `nav: { verbb, editable }`.
- `POST /deon-ai/nav` (DEO-80) — verlinkt eine generierte Seite in Hauptnavigation oder Footer. Neuer Consent-Schalter `allowNavEdit` (Standard aus). Strategie-Kaskade, da Craft keine Kern-Navigation hat: (1) [verbb/navigation](https://plugins.craftcms.com/navigation), falls installiert — Nav per Handle/Name-Heuristik wählen, Node anlegen, Dedupe über URL/verlinkte Entry; (2) Structure-Section mit Handle `nav`/`menu` und einem `linkUrl`/`url`-Feld, falls vorhanden; (3) sonst `422 nav_not_automatable` mit Hinweis zur manuellen Verlinkung bzw. Tipp auf das verbb-Plugin.

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

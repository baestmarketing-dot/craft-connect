# Deon AI Connect für Craft CMS

Verbindet deine Craft-Site mit dem [Deon AI Marketing-OS](https://deon-ai.de): SEO-Audit mit 1-Klick-Fixes, KI-Sichtbarkeits-Tracking (ChatGPT, Google AI Overview & Co.), Besucher-Tracking und automatisches Blog-Publishing.

## Was das Plugin macht

- **SEO-Fixes nativ am Origin** — Title, Meta-Description, Canonical und Schema.org-Markup werden serverseitig ins ausgelieferte HTML geschrieben. Kein Client-Overlay: Googlebot **und** KI-Crawler (GPTBot, ClaudeBot, PerplexityBot) sehen die Optimierungen im rohen HTML.
- **Automatische Einbindung** — SDK-Script (Tracking, Conversions, A/B-Varianten) und Domain-Verifizierungs-Tag werden injiziert, ohne dass Templates angepasst werden müssen.
- **robots.txt / llms.txt** — Deon AI kann KI-Crawler-Freigaben und llms.txt optional direkt am Origin ausliefern (Setting `manageRobotsLlms`).
- **Blog-Publishing** — Deon AI legt generierte Artikel direkt als Entries an (Entwurf oder live), inkl. Featured-Image-Upload und Duplikat-Check über bestehende Entries. Section und Body-Feld können pro Request überschrieben werden (Multi-Section-Publishing).
- **Rollback-fähiges Änderungsprotokoll** — jede Deon-AI-Änderung speichert automatisch ihren Vorher-Zustand, bevor sie geschrieben wird. Kein separater Backup-Job, der ausfallen könnte: die Sicherung ist untrennbarer Teil derselben Datenbank-Transaktion wie die Änderung selbst und funktioniert auf jedem Hosting (reines SQL, kein `shell_exec`/`mysqldump` nötig).
- **Native Content-Bausteine** — FAQ-Blöcke idempotent in bestehende Entries einbauen (`/deon-ai/faq`), Standort-/Faktenseiten als eigene Section anlegen (`/deon-ai/page`, Setting `pagesSectionHandle`), robots.txt/llms.txt direkt im Webroot lesen/schreiben (`/deon-ai/files`) — jeweils mit Backup vor dem Überschreiben.
- **Berechtigungen** — der Kunde entscheidet im Control Panel selbst, was Deon AI ändern darf. Nicht freigegebene 1-Klick-Fixes werden im Deon-AI-Dashboard ausgegraut statt einen Fehler zu werfen.
- **Remote-Self-Update** — Deon AI kann das Plugin bei neuen Versionen selbstständig per Composer aktualisieren, ohne dass jemand ins Control Panel muss. Craft spielt Plugin-Updates sonst nie automatisch ein.
- **Blog-/Seiten-Bootstrap** — legt bei Bedarf Body-/Bildfeld und Blog-/Seiten-Section selbst an (`/deon-ai/setup-blog`), damit das Publishing auch auf einer frischen Craft-Installation ohne bestehendes Content-Schema funktioniert.
- **Navigation** — verlinkt generierte Seiten automatisch in Hauptnavigation oder Footer (`/deon-ai/nav`), wenn das kostenlose Plugin [verbb/navigation](https://plugins.craftcms.com/navigation) installiert ist. Zusätzlich ein Plugin-eigener Footer-Block („Servicegebiete", `/deon-ai/footer-links`), der ohne Zusatz-Plugin funktioniert.
- **Seiten-Anbindung** — Deon AI kann Craft-Seiten vollständig lesen (`/deon-ai/page-structure`), per URL finden (`/deon-ai/match-url`), im Original-Design klonen und texturieren (`/deon-ai/duplicate-page`, `/deon-ai/set-widget-texts`), als Full-Page-Landingpage publizieren (`/deon-ai/publish-lp`) und die Design-Tokens der Site extrahieren (`/deon-ai/theme-tokens`) — Contract-Parität zum WordPress-Plugin, gleiche Response-Shapes.
- **Section-Tests & A/B-Varianten** — komplette Seiten-Varianten mit Server-Cookie-Split (`/deon-ai/section-test/*`, Winner-Merge mit Rollback) sowie Selector-basierte A/B-Änderungen per Frontend-Snippet (`/deon-ai/ab-variant/*`), inkl. Remote-Konfiguration (`/deon-ai/configure-ab`, `/deon-ai/configure-tracker`).

## Voraussetzungen — vor der Installation prüfen

Das Plugin bringt eine eigene Datenbank-Migration mit (neue Tabellen für SEO-Overrides und robots.txt/llms.txt). Damit die Installation nicht die Live-Seite lahmlegt, **vorher sicherstellen**:

- **Craft CMS 4.0+ oder 5.0+, PHP 8.0.2+**
- **DB-User mit vollen DDL-Rechten** (`CREATE`, `ALTER`, `DROP`, `INDEX`) — nicht nur Lese-/Schreibrechte. Viele Shared-Hoster (z. B. Hetzner, All-Inkl) vergeben standardmäßig einen eingeschränkten DB-User ohne `ALTER`. Ohne dieses Recht schlägt **jede** Craft-Migration fehl — nicht nur unsere, auch reine Craft-Core-Updates — und reißt beim nächsten Control-Panel-Aufruf die komplette Seite mit runter (kein Plugin-spezifischer Bug, sondern Craft-Grundvoraussetzung). Bei getrennten DB-Usern nach Rechte-Stufe (z. B. `xyz_1` = Admin/Full, `xyz_1_w` = R/W ohne ALTER) vorübergehend auf den Admin-User umstellen, Migration laufen lassen, danach zurückstellen.
- **Admin-Account für die Einrichtung**: Craft verlangt für **jede** Plugin-Einstellungsseite pauschal einen Admin-Account (`PluginsController::requireAdmin()`) — es gibt bei Craft keine granulare, an Nicht-Admins vergebbare Berechtigung dafür. Wer das Plugin unter Einstellungen → Plugins → Deon AI Connect konfiguriert, muss also Admin sein; das lässt sich nicht per Rolle einschränken oder an z. B. einen SEO-Manager-Account delegieren.
- **Für die automatisierten Deon-AI-Aktionen selbst (SEO-Fixes, Blog-Publishing, robots.txt/llms.txt) ist dagegen keine Craft-Berechtigung nötig**: Die REST-Endpoints laufen anonym (`allowAnonymous`) und werden ausschließlich über den `X-Deon-Key`-Header authentifiziert, nicht über einen eingeloggten Craft-Nutzer — sie rufen die Craft-Services direkt auf und umgehen damit das CP-Berechtigungssystem komplett.

### Migrationen sicher ausführen

Nach der Installation (und bei jedem künftigen Update, egal ob Plugin oder Craft-Core) Migrationen **immer per Konsole** laufen lassen, nicht über den Browser-Updater im Control Panel:

```bash
composer require deon-ai/craft-connect
php craft migrate/all
```

So bleibt die Seite live erreichbar, falls eine Migration fehlschlägt — der Fehler passiert in der SSH-Session, nicht mitten in einem Besucher-Request. Für Produktivumgebungen empfiehlt sich zusätzlich `CRAFT_ALLOW_ADMIN_CHANGES=false` in der Env, damit der Browser-Updater generell deaktiviert ist und Updates nur noch kontrolliert über die Konsole laufen.

## Einrichtung

1. In [Deon AI](https://audit.deon-ai.de) → **Website verknüpfen** → Plattform **Craft CMS** wählen.
2. Den angezeigten **Connection-Key** in Craft unter **Einstellungen → Plugins → Deon AI Connect** eintragen (einziges Pflichtfeld — Tipp: als Env-Variable hinterlegen, das Feld unterstützt Env-Autosuggest) und speichern.
3. Beim Speichern holt sich das Plugin automatisch Site-ID, SDK-Key und Verifizierungs-UUID von Deon AI ab (Bootstrap-Call, authentifiziert über denselben Key) — die Einstellungsseite zeigt danach "✓ Verbunden — Site-ID: …". Schlägt das fehl (z. B. falscher Key), bleibt eine Meldung im Control Panel stehen; die restlichen Settings gehen dabei nicht verloren.
4. Zurück im Deon-AI-Wizard auf **Verifizieren** klicken — fertig.

Für das Blog-Publishing: Section-Handle (Standard `blog`) und Body-Feld-Handle (Standard `body`) in den Plugin-Einstellungen an dein Schema anpassen. Für Featured Images zusätzlich Asset-Volume- und Bildfeld-Handle eintragen. Für robots.txt/llms.txt-Verwaltung den entsprechenden Schalter aktivieren (nur wirksam, wenn im Webroot noch keine physische robots.txt-Datei liegt).

## Berechtigungen

Unter **Einstellungen → Plugins → Deon AI Connect → Berechtigungen** legt der Kunde fest, was Deon AI eigenständig ändern darf — pro Kategorie ein Schalter:

| Schalter | Standard | Gatet |
| --- | --- | --- |
| Title/Meta-Description/Canonical/Schema (`allowSeoMeta`) | an | `/deon-ai/seo` |
| Inhalte bestehender Seiten bearbeiten (`allowContentEdit`) | aus | `/deon-ai/faq` |
| Neue Seiten anlegen (`allowPageCreate`) | aus | `/deon-ai/entry`, `/deon-ai/page` |
| robots.txt / llms.txt (`allowFiles`) | aus | `/deon-ai/files`, `/deon-ai/hygiene` |
| Bild-Uploads (`allowAssets`) | aus | `/deon-ai/asset` |
| Plugin automatisch aktualisieren (`allowSelfUpdate`) | an | `/deon-ai/self-update` |
| Navigation bearbeiten (`allowNavEdit`) | aus | `/deon-ai/nav` |
| A/B-Tests & Tracking umkonfigurieren (`allowAbTest`) | aus | `/deon-ai/configure-ab`, `/deon-ai/configure-tracker` |

`/deon-ai/setup-blog` läuft unter derselben Berechtigung wie neue Seiten (`allowPageCreate`), da es im Kern ebenfalls Content-Struktur anlegt. `/deon-ai/publish-winner` prüft `allowContentEdit` als Basis-Berechtigung, für enthaltene `seo_meta`-Changes zusätzlich `allowSeoMeta` (sonst wird nur dieser Change-Type übersprungen, der Rest angewendet).

Nur SEO-Overrides und Self-Update sind standardmäßig aktiv — SEO-Overrides, da sie rein serverseitig wirken und keinen Inhalt verändern; Self-Update, da Updates auch Sicherheitsfixes enthalten können. Ein Aufruf gegen einen nicht freigegebenen Endpoint liefert `403 { "ok": false, "error": "consent_required", "permission": "<key>" }`. `/deon-ai/ping` gibt den aktuellen Freigabe-Stand aller Kategorien im Feld `permissions` zurück, damit Deon AI nicht freigegebene 1-Klick-Fixes im Dashboard ausgrauen kann. Lese-Endpoints (`ping`, `seo-list`, `entries`, `hygiene-list`, `rollback/*`) sind bewusst nicht gegated — Rückgängig machen (Rollback) funktioniert unabhängig von diesen Schaltern immer.

## Remote-Self-Update

Craft spielt Plugin-Updates nie automatisch ein — jemand muss im Control Panel klicken. Deon AI kann das stattdessen selbst anstoßen:

1. **`POST /deon-ai/self-update`** (`{ "version": "0.6.1" }`) — hebt **ausschließlich** `deon-ai/craft-connect` per Composer auf die angegebene Zielversion an (nie `craft update all` oder andere Pakete). Ziel bereits installiert → `{ ok: true, already: true }`. Ein Preflight-Check prüft vorher, ob der Server das technisch kann (`proc_open` verfügbar, `memory_limit` ≥ 256M, `composer.phar` vorhanden) — schlägt er fehl, bleibt die Installation unangetastet und die Antwort ist `422 { ok: false, error: "self_update_unavailable", reason: "…" }`. Vor dem Swap wird fail-soft ein DB-Backup versucht (`Craft::$app->getDb()->backup()`, braucht `mysqldump`/`pg_dump`). Antwort bei Erfolg: `{ ok: true, from: "0.4.0", to: "0.6.1", needs_migration: true }`.
2. **`POST /deon-ai/up`** — führt danach, in einem **neuen** Request, die Plugin-Migrationen der frisch installierten Version aus. Zwei getrennte Requests sind nötig, weil direkt nach dem Composer-Swap im selben PHP-Prozess noch der alte Klassen-Code geladen ist. Antwort: `{ ok: true, migrated: true, version: "0.6.1" }`.

`/deon-ai/ping` meldet zusätzlich ein Fähigkeits-Flag `self_update` (kann dieser Server technisch selbst updaten, unabhängig vom `allowSelfUpdate`-Schalter) sowie `plugin_version`/`craft_version`/`php_version` und `sections_ok` (ob die konfigurierten Section-Handles für Blog und Seiten tatsächlich existieren).

Funktioniert Composer im Web-Request nicht (manches Shared-Hosting sperrt `proc_open` oder begrenzt `memory_limit`/`max_execution_time` zu knapp), bleibt als Fallback die Konsole — z. B. per Cron:

```bash
php craft deon-ai-connect/update 0.6.1
```

Gleicher Code-Pfad wie die REST-Endpoints (Composer-Swap + Migrationen in einem Lauf), nur ohne die Web-Request-Limits.

## Blog-/Seiten-Bootstrap

Auf einer frischen Craft-Installation ohne bestehendes Blog-Schema scheitert das Publishing an `section_not_found` oder fehlenden Feld-Handles. **`POST /deon-ai/setup-blog`** behebt das: legt bei Bedarf ein Body-Feld (`deonBody` — CKEditor, falls `craftcms/ckeditor` installiert ist, sonst Redactor, sonst ein mehrzeiliges Klartext-Feld), ein Featured-Image-Feld (`deonFeaturedImage`, auf das erste vorhandene Volume beschränkt) sowie die Blog- und Seiten-Section an. Ziel-Handles sind dabei immer `settings.blogSectionHandle`/`pagesSectionHandle` (Standard `blog`/`pages`), falls die schon auf eine existierende Section zeigen — nur wenn sie leer oder ungültig sind, legt das Plugin eigene Sections (`deonBlog`/`deonPages`) an. Idempotent: bereits vorhandene, gültige Handles werden nie überschrieben, nur leere oder kaputte Plugin-Settings automatisch mit den neuen Handles verdrahtet. Fehlt ein Volume, wird das Bildfeld übersprungen (`featured_image: "no_volume"`) — das Plugin legt nie selbst ein Volume/Filesystem an, das ist hosting-abhängig.

`settings.assetVolumeHandle` wird dabei automatisch mitgesetzt — ohne dieses Setting hängen `/deon-ai/entry` und `/deon-ai/page` trotz vorhandenem `featuredImageFieldHandle` nie ein Bild an. Für neu angelegte Bildfelder kommt der Handle vom verwendeten Volume; für ein bereits bestehendes `deonFeaturedImage`-Feld wird er aus dessen `restrictedLocationSource` abgeleitet (heilt auch Alt-Setups, ohne das Feld selbst anzufassen). `/deon-ai/ping` prüft in `fields_ok.featured_image` beide Settings inkl. echter Volume-Existenz — nur so erkennt der Worker-Self-Heal betroffene Sites zuverlässig und ruft `setup-blog` erneut auf.

**Fallback-Templates**: Das Plugin bringt zwei eigene, self-contained Templates mit — `deon-ai/entry` fürs Blog, `deon-ai/page` für Standort-/Leistungsseiten (beide leben im Plugin, nichts wird in das `templates/`-Verzeichnis der Site geschrieben). Neu angelegte Sections bekommen ihr passendes Template direkt gesetzt. Bestehende Sections mit leerem Template werden automatisch repariert; bestehende Sections mit einem gesetzten, aber erkennbar leeren/kaputten Custom-Template nur mit explizitem `{ "fix_template": true }` im Request — eine funktionierende Konfiguration wird nie stillschweigend überschrieben. Response meldet `template`/`previous_template` fürs Blog sowie additiv `template_pages`/`previous_template_pages` für die Seiten-Section (`"kept"`/`"set"`/`"fixed"`), jede Änderung ist über `/deon-ai/rollback` rückgängig machbar.

**Titel-Feld-Check**: Craft überschreibt automatisch jeden gesetzten Titel mit leer, wenn der Entry-Type einer Section weder ein Titel-Feld noch ein `titleFormat` hat (`craft\elements\Entry::updateTitle()`, läuft unumgehbar bei jedem Speichern) — Ergebnis wäre „Eintrag ohne Titel" im CP. `/deon-ai/entry` und `/deon-ai/page` brechen deshalb mit `422 title_field_missing` ab, statt eine unsichtbar untitled Seite zu veröffentlichen. `setup-blog` prüft das für Blog- und Seiten-Section (Response `title_field`/`title_field_pages`: `"ok"|"missing"|"fixed"`) und repariert es mit `{ "fix_template": true }`.

## Navigation

**`POST /deon-ai/nav`** (`{ target: "main"|"footer", url, title, entry_id? }`) verlinkt eine generierte Seite in Hauptnavigation oder Footer. Craft hat keine Kern-Navigation, daher eine Strategie-Kaskade:

1. Ist [verbb/navigation](https://plugins.craftcms.com/navigation) installiert (der De-facto-Standard für Craft-Navigationen), wählt das Plugin die passende Nav per Handle-/Namens-Heuristik (`main`/`haupt`/`primary` bzw. `footer`/`fuss`, sonst die erste vorhandene Nav) und legt einen Node an — dedupliziert über die Ziel-URL bzw. den verlinkten Entry. Antwort: `{ ok, via: "verbb", nav: { handle } }`.
2. Sonst, falls eine Structure-Section mit Handle `nav`/`menu` existiert **und** deren Entry-Type ein Feld `linkUrl` oder `url` hat, wird dort ein Entry angelegt. Ohne ein eindeutiges Link-Feld wird nicht geraten.
3. Sonst `422 { ok: false, error: "nav_not_automatable", hint: "…" }` mit dem Hinweis, den Link manuell im CP/Template zu setzen — und dem Tipp, dass Deon AI die Navigation mit dem kostenlosen verbb-Plugin automatisch pflegen kann.

## Seiten-Anbindung

Damit Deon AI Craft-Seiten analysieren, im Original-Design klonen und texturieren kann — Response-Shapes bewusst identisch zum WordPress-Plugin (aideon-connect), damit der Deon-AI-Worker beide Plattformen einheitlich anspricht:

- `GET /deon-ai/match-url?url=…` — findet den Entry zu einer URL (`{ matched, id, title, slug, … }`)
- `GET /deon-ai/pages?per_page=` — Entries **aller** Sections, nach Änderungsdatum absteigend
- `GET /deon-ai/page-structure/<id>` — kompletter Seiteninhalt inkl. Body-HTML und walkbaren Text-Blöcken (`content_blocks`, IDs `pc-N`: h1–h3 = `title`, p = `editor`) — funktioniert nur für das eine `deonBody`-Feld (Deons eigene Blog-/Standortseiten)
- `GET /deon-ai/site-inventory` — alle Sections der Site (`handle`/`type`/`name`/`entry_count`), damit der Worker Section-Handles nicht raten muss, um z. B. eine bestehende Leistungsseite als Vorbild für eine neue zu finden
- `GET /deon-ai/entry-sections/<id>` — strukturierte Block-Inhalte eines **beliebigen** Entries (nicht nur Deons eigene Seiten): native Matrix-Felder (Craft 5: verschachtelte Entries, Craft 4: MatrixBlocks) sowie Neo-Felder (`spicyweb/craft-neo`, sofern installiert), rekursiv aufgelöst — jeder Block mit `block_id` (stabile Element-ID), `block_type`/`block_label` (Handle/Name des Block-Typs) und seinen Feldwerten (Text, Bilder inkl. Alt-Text, Entry-/Category-Relationen). Feature-detected über die Feld-Klasse, nicht über Feld-/Block-Namen. Ohne Matrix-/Neo-Feld am Entry-Type greift `legacy_body_fallback` (identisch zu `page-structure`). Tiefen-/Blockzahl-Limit: 4 Ebenen / 200 Blöcke.
- `POST /deon-ai/entry-sections/<id>` — `{ patches: [{ field_handle, block_id?, block_index?, field, value }] }` (max. 50 pro Call) schreibt Text in einzelne Sub-Felder von Matrix-/Neo-Blöcken — das Gegenstück zum GET oben, dieselbe Route, per HTTP-Methode verzweigt. Schließt die Lücke im "Kopieren & Umbauen"-Pfad: `/duplicate-page` klont eine Seite strukturell perfekt (echtes Theme, alle Blöcke), aber ohne diesen Endpoint blieb der geklonte Text 1:1 identisch zum Original. Schreibt bewusst nur "textartige" Sub-Felder (PlainText/CKEditor/Redactor oder ein Feld mit aktuell einfachem String-Wert) — Assets/Relationen/verschachtelte Matrix-Neo-Felder werden nie angefasst. `block_id` bevorzugt (stabil), `block_index` nur als Fallback direkt nach einem `duplicate-page`-Klon ohne zwischenzeitlichen Read — bei genesteten Neo-Blöcken ist `block_index` beim GET pro Verschachtelungsebene neu bei 0 gezählt, beim POST-Fallback dagegen ein Index in die flache Blockliste; dort immer `block_id` verwenden. Jeder Patch einzeln im Change-Log erfasst, granulares Rollback pro Block-Feld. Berechtigung: `allowContentEdit`.
- `POST /deon-ai/set-widget-texts` — `{ post_id, texts: [{ id: "pc-N", title?|editor? }] }` setzt Texte punktgenau in den Body (Reihenfolge identisch zu `page-structure`), mit Rollback-Protokoll. Berechtigung: `allowContentEdit`.
- `POST /deon-ai/duplicate-page` — `{ source_post_id|source_page_url, title, replacements: [{find, replace}], h1_override?, page_id?, … }` klont eine Seite 1:1 (Craft-natives `duplicateElement`, alle Felder inkl. Bilder), tauscht Texte und legt sie als Entwurf an — der Standortseiten-Pfad. Idempotent per `page_id`. Berechtigung: `allowPageCreate`.
- `GET /deon-ai/render-preview?post_id|url&token=` — liefert das gerenderte Frontend-HTML (für die Dashboard-Preview). Zweifach gesichert: `X-Deon-Key` **plus** kurzlebiges HMAC-Token (60 s), nur Same-Origin-URLs.
- `POST /deon-ai/publish-lp` — Full-Page-Landingpage aus Roh-HTML inkl. `<style>`/`<script>` (eigene Tabelle + Route pro Slug, kein Entry). Hinweis: `chrome: "bare"` wird gespeichert, gerendert wird in Craft immer das Roh-HTML als eigenständiges Dokument — es gibt kein Theme, in das sich „bare" einbetten ließe. `slug` ist strikt auf `[a-z0-9-/]` beschränkt und darf weder mit `deon-ai/` kollidieren noch einer bestehenden Entry-URI entsprechen (`422 slug_invalid`/`slug_reserved`/`slug_collides_with_entry`) — die LP-Routen werden intern als Yii-URL-Rules registriert, ein ungefilterter Slug könnte sonst Kern-Routen des Plugins überschreiben. Berechtigung: `allowPageCreate`.
- `GET /deon-ai/theme-tokens` — Farben/Fonts/Radius/Palette der Site. Craft hat kein theme.json wie WordPress-Block-Themes, deshalb extrahiert das Plugin die Tokens aus dem CSS der gerenderten Startseite (`<style>`-Blöcke + Same-Origin-Stylesheets, `var(--x)` wird eine Ebene aufgelöst) — `source: "css_extract"`, best-effort; der Worker-Normalizer wählt aus der Palette notfalls selbst.
- `POST /deon-ai/site-schema` — Site-weites JSON-LD, ausgespielt im `<head>` aller Seiten. Berechtigung: `allowSeoMeta`.
- `GET|POST /deon-ai/footer-links` — Plugin-eigener Footer-Block („Servicegebiete"-Links), gerendert vor `</body>`, ohne Zusatz-Plugin. Berechtigung (POST): `allowNavEdit`.
- `GET /deon-ai/media?per_page=` — Bild-Bibliothek der Site über alle Asset-Volumes (`url`, `alt`, `title`, `filename`, `w`, `h`), Pendant zu WPs `wp-json/wp/v2/media?media_type=image`. Damit kann Deon AI passende Bilder aus dem vorhandenen Bestand in generierte Sektionen matchen, statt sie neu hochzuladen.
- `POST /deon-ai/audit-fix` — Content-Write-Fixes: `{ action: "replace_content"|"append_html_box", page_url, new_content?|custom_value?, payload?: { box_marker? } }`. `replace_content` ersetzt den kompletten Body (Freshness-Refresh), `append_html_box` hängt eine HTML-Box idempotent an (existiert ein `<aside>` mit der Marker-Klasse bereits, wird er ersetzt statt dupliziert — für interne Verlinkung/Pillar-Backrefs). Beide mit Rollback-Snapshot (`rollback_id`). Berechtigung: `allowContentEdit`.

## Section-Tests & A/B-Varianten

Craft-natives Pendant zur Test-Engine des WordPress-Plugins. WP manipuliert dort Gutenberg-Blocks/Elementor-JSON — in Craft sind die „Sections" die **Top-Level-Elemente des Body-HTML** (builder `html`, Selector = Index oder `tag[n]`, z. B. `section[1]`):

- `POST /deon-ai/section-test/create` — `{ original_post_id, name?, sections_changes: [{action, selector?, position?, html?, target_selector?}] }`. Legt die Variante als geklonten, **deaktivierten** Entry an (fürs Frontend unsichtbar) und startet den Test. Berechtigung: `allowContentEdit`.
- **Ausspielung**: server-seitiger 50/50-Split per Cookie `aideon_st_<id>` (30 Tage, für das SDK lesbar — Conversion-Attribution wie bei WordPress). Bots (Googlebot & Co.) sehen immer das Original. Antworten werden mit `Cache-Control: no-store` + `Vary: Cookie` markiert, damit CDNs nicht eine Variante für alle einfrieren. Variante B ersetzt den Original-Body im gerenderten HTML — transformiert das Twig-Template den Feld-Inhalt so stark, dass er im HTML nicht wiedergefunden wird, wird fail-soft das Original ausgespielt (Warnung im Log).
- `POST /deon-ai/section-test/preview` — wendet die Änderungen an, ohne zu speichern. `GET /deon-ai/section-test/list/<id>` — Tests inkl. Besucher-Zählern.
- `POST /deon-ai/section-test/stop` — `{ original_post_id, test_id, winner: "a"|"b"|"none" }`. Winner B wird mit Rollback-Snapshot ins Original gemerged; die Variante wandert in den Craft-Papierkorb.
- `POST /deon-ai/publish-winner` — Änderungs-Liste direkt anwenden (`seo_meta`, `content_replace`, `html_section`), immer mit Rollback-Snapshot. Berechtigung: `allowContentEdit`.
- `POST /deon-ai/ab-variant/create` — Selector-basierte A/B-Variante (Modi `text`/`html`/`attr`/`link`/`style`/`form`, `percentage` 1–99). Ausspielung über ein Frontend-Snippet (1:1 vom WP-Plugin portiert): Cookie `aideon_ab_assign`, Preview-Forcing per `?aideon_force=a|b|<id>:b` mit Banner, Impression-Tracking per `sendBeacon` an Deon AI.
- `POST /deon-ai/configure-ab` / `GET /deon-ai/ab-status` und `POST /deon-ai/configure-tracker` / `GET /deon-ai/tracker-status` — Remote-Konfiguration. `tracker_enabled=false` schaltet die SDK-Injection zusätzlich zur CP-Einstellung ab.

## Änderungsprotokoll & Rollback

Jeder schreibende Endpoint (`/deon-ai/seo`, `/deon-ai/entry`, `/deon-ai/hygiene`) speichert vor jeder Änderung automatisch den bisherigen Zustand und gibt eine `rollback_id` (Format `rb_123`) zurück. Das ist **keine separate Backup-Aktion**, die vergessen oder übersprungen werden könnte — das Protokollieren passiert atomar mit der Änderung selbst, von der allerersten Aktion an.

Die Endpoints folgen derselben `/rollback/*`-Konvention wie das WordPress-/TYPO3-Plugin, damit sie im bestehenden "Änderungs-Journal"-Tab des Deon-AI-Dashboards erscheinen (der Worker leitet dorthin 1:1 durch):

- `GET /deon-ai/rollback/list` (`?limit=`) — Journal auflisten
- `GET /deon-ai/rollback/<rb_id>` — einzelnen Eintrag abrufen
- `POST /deon-ai/rollback/<rb_id>/preview` — zeigt, was ein Rollback wiederherstellen würde (bricht bei erkanntem Konflikt ab, wenn der Live-Zustand seit der Änderung manuell verändert wurde)
- `POST /deon-ai/rollback/<rb_id>/restore` (optional Body `{ "force": true }`, um einen Konflikt zu überschreiben) — macht die Änderung rückgängig:
  - SEO-Override/robots.txt/llms.txt: alter Inhalt wird wiederhergestellt (oder die Zeile gelöscht, falls sie vorher nicht existierte)
  - Entry: Titel/Slug/Status/Body/Featured Image werden zurückgesetzt — war der Entry neu von Deon AI angelegt, wandert er stattdessen in den Craft-Papierkorb (weiches Löschen, jederzeit wiederherstellbar)
  - Block-Feld (`/entry-sections`-Patch): einzelner Feldwert im betroffenen Matrix-/Neo-Block wird zurückgesetzt, unabhängig vom Rest des Entries
- `POST /deon-ai/rollback/restore-point` (Body `{ "label"? }`) — kompletter Sicherungspunkt: Snapshot aller aktuell verwalteten SEO-Overrides, robots.txt/llms.txt-Inhalte und Blog-Entries als ein wiederherstellbarer Punkt. Das ist die "einmal alles gesichert, bevor sich was ändert"-Aktion — als reines SQL-Snapshot, kein `shell_exec`/`mysqldump` nötig.

Optional bei jedem Schreibaufruf ein `note`-Feld mitgeben (Freitext, z. B. "Grund der Änderung") — erscheint im Protokoll. Für einen kompletten Datenbank-Snapshot außerhalb dessen, was das Plugin selbst anfasst, bleibt zusätzlich Craft's eigenes `php craft db/backup` empfehlenswert — das braucht allerdings `mysqldump`/`pg_dump` per `shell_exec`, was auf manchen Shared-Hosting-Umgebungen gesperrt ist.

## Native Content-Endpoints

- `POST /deon-ai/files` — `{ op: "read"|"write", filename: "llms.txt"|"robots.txt", content? }`. Strikte Dateinamen-Whitelist, liest/schreibt direkt im Webroot. `write` sichert den bisherigen Inhalt vor dem Überschreiben.
- `POST /deon-ai/faq` — `{ uri, faq_html, body_field? }`. Hängt einen FAQ-Block an den Entry-Body an (leere `uri`/`"/"` = Startseite). Idempotent: ein bereits vorhandener Block mit `data-deon-faq`-Marker wird ersetzt statt dupliziert.
- `POST /deon-ai/page` — `{ title, slug?, body_html, status?, section?, entry_id? }`. Legt native Seiten an (Standortseiten, KI-Faktenseite). Section-Auflösung: `section`-Param → Setting `pagesSectionHandle` (Standard `pages`) → `blogSectionHandle`. Geht standardmäßig als Entwurf raus (`status` explizit `"live"` setzen für sofortige Veröffentlichung).

`/deon-ai/entry` und `/deon-ai/page` legen ohne `entry_id` immer einen neuen Entry an; mit `entry_id` aktualisieren sie den bestehenden. Zeigt eine explizit übergebene `entry_id` auf keinen (mehr) existierenden Entry, liefern beide `404 { "ok": false, "error": "entry_not_found" }` statt still ein Duplikat anzulegen.

Alle drei sichern den bisherigen Inhalt fail-soft in einer eigenen Tabelle, bevor sie etwas überschreiben — ein Backup-Fehler blockiert dabei nie den eigentlichen Fix.

## Sicherheit

Eingehende Deon-AI-Calls werden über den `X-Deon-Key`-Header authentifiziert (Konstantzeit-Vergleich). Das Plugin bricht die Seitenauslieferung nie: Jeder Patch-Schritt ist fail-soft.

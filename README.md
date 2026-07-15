# Deon AI Connect für Craft CMS

Verbindet deine Craft-Site mit dem [Deon AI Marketing-OS](https://deon-ai.de): SEO-Audit mit 1-Klick-Fixes, KI-Sichtbarkeits-Tracking (ChatGPT, Google AI Overview & Co.), Besucher-Tracking und automatisches Blog-Publishing.

## Was das Plugin macht

- **SEO-Fixes nativ am Origin** — Title, Meta-Description, Canonical und Schema.org-Markup werden serverseitig ins ausgelieferte HTML geschrieben. Kein Client-Overlay: Googlebot **und** KI-Crawler (GPTBot, ClaudeBot, PerplexityBot) sehen die Optimierungen im rohen HTML.
- **Automatische Einbindung** — SDK-Script (Tracking, Conversions, A/B-Varianten) und Domain-Verifizierungs-Tag werden injiziert, ohne dass Templates angepasst werden müssen.
- **robots.txt / llms.txt** — Deon AI kann KI-Crawler-Freigaben und llms.txt optional direkt am Origin ausliefern (Setting `manageRobotsLlms`).
- **Blog-Publishing** — Deon AI legt generierte Artikel direkt als Entries an (Entwurf oder live), inkl. Featured-Image-Upload und Duplikat-Check über bestehende Entries. Section und Body-Feld können pro Request überschrieben werden (Multi-Section-Publishing).

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
2. Die angezeigten Werte (API-Key, Site-ID, SDK-Key, Verifizierungs-UUID) in Craft unter **Einstellungen → Plugins → Deon AI Connect** eintragen. Tipp: als Env-Variablen (`$DEON_API_KEY` …) hinterlegen — die Felder unterstützen Env-Autosuggest.
3. Zurück im Deon-AI-Wizard auf **Verifizieren** klicken — fertig.

Für das Blog-Publishing: Section-Handle (Standard `blog`) und Body-Feld-Handle (Standard `body`) in den Plugin-Einstellungen an dein Schema anpassen. Für Featured Images zusätzlich Asset-Volume- und Bildfeld-Handle eintragen. Für robots.txt/llms.txt-Verwaltung den entsprechenden Schalter aktivieren (nur wirksam, wenn im Webroot noch keine physische robots.txt-Datei liegt).

## Sicherheit

Eingehende Deon-AI-Calls werden über den `X-Deon-Key`-Header authentifiziert (Konstantzeit-Vergleich). Das Plugin bricht die Seitenauslieferung nie: Jeder Patch-Schritt ist fail-soft.

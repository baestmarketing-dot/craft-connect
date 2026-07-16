<?php

namespace deonai\craftconnect\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Deon AI Connection-Key — einziges Feld, das der Nutzer einträgt.
     * Authentifiziert eingehende Deon-AI-Calls (X-Deon-Key Header) UND den
     * Bootstrap-Call beim Speichern (siehe Plugin::bootstrapFromDeonAi()).
     */
    public string $apiKey = '';

    /** Deon AI Site-ID — automatisch per Bootstrap befüllt, nicht direkt editierbar. */
    public string $siteId = '';

    /** SDK Public Key (dpk_…) — automatisch per Bootstrap befüllt, nicht direkt editierbar. */
    public string $sdkKey = '';

    /** Verifizierungs-UUID (== siteId) — automatisch per Bootstrap befüllt, wird als Meta-Tag ausgespielt. */
    public string $verificationUuid = '';

    /** SDK-Script automatisch injizieren. */
    public bool $injectSdk = true;

    /** Section-Handle, in dem Blog-Entries angelegt werden (z.B. "blog"). */
    public string $blogSectionHandle = 'blog';

    /** Feld-Handle für den Artikel-Body (Rich-Text/CKEditor-Feld). */
    public string $blogBodyFieldHandle = 'body';

    /**
     * Section-Handle für native Seiten (Standortseiten, KI-Faktenseiten) via
     * /deon-ai/page. Fallback-Kette dort: Request-Param > dieses Setting >
     * blogSectionHandle.
     */
    public string $pagesSectionHandle = 'pages';

    /** Volume-Handle für Bild-Uploads (Featured Images aus Deon AI). Leer = deaktiviert. */
    public string $assetVolumeHandle = '';

    /** Feld-Handle für das Featured-Image-Feld im Entry-Type. Leer = deaktiviert. */
    public string $featuredImageFieldHandle = '';

    /** robots.txt + llms.txt am Origin ausliefern (Deon AI verwaltet den Inhalt). */
    public bool $manageRobotsLlms = false;

    // ─── Berechtigungen — was darf Deon AI ändern? ────────────────────────
    // Gaten die schreibenden Endpoints (siehe ApiController::checkPermission()).
    // Lese-Endpoints (ping, seo-list, entries, hygiene-list, rollback/*) sind
    // davon bewusst ausgenommen.

    /** Title/Meta-Description/Canonical/Schema setzen (/deon-ai/seo). */
    public bool $allowSeoMeta = true;

    /** Body bestehender Entries ändern, z. B. FAQ-Einbau (/deon-ai/faq). */
    public bool $allowContentEdit = false;

    /** Neue Entries anlegen: Blog sowie Standort-/Leistungs-/Faktenseiten (/deon-ai/entry, /deon-ai/page). */
    public bool $allowPageCreate = false;

    /** robots.txt/llms.txt lesen/schreiben (/deon-ai/files, /deon-ai/hygiene). */
    public bool $allowFiles = false;

    /** Bild-Uploads als Assets (/deon-ai/asset). */
    public bool $allowAssets = false;

    public function defineRules(): array
    {
        return [
            [['apiKey', 'siteId', 'sdkKey', 'verificationUuid', 'blogSectionHandle', 'blogBodyFieldHandle', 'pagesSectionHandle', 'assetVolumeHandle', 'featuredImageFieldHandle'], 'string'],
            [['injectSdk', 'manageRobotsLlms', 'allowSeoMeta', 'allowContentEdit', 'allowPageCreate', 'allowFiles', 'allowAssets'], 'boolean'],
        ];
    }
}

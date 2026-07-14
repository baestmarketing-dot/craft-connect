<?php

namespace deonai\craftconnect\models;

use craft\base\Model;

class Settings extends Model
{
    /** API-Key für eingehende Deon-AI-Calls (X-Deon-Key Header). */
    public string $apiKey = '';

    /** Deon AI Site-ID (UUID aus dem Dashboard). */
    public string $siteId = '';

    /** SDK Public Key (dpk_…) — für Tracking/Design-Tokens. */
    public string $sdkKey = '';

    /** Verifizierungs-UUID — wird als Meta-Tag ausgespielt. */
    public string $verificationUuid = '';

    /** SDK-Script automatisch injizieren. */
    public bool $injectSdk = true;

    /** Section-Handle, in dem Blog-Entries angelegt werden (z.B. "blog"). */
    public string $blogSectionHandle = 'blog';

    /** Feld-Handle für den Artikel-Body (Rich-Text/CKEditor-Feld). */
    public string $blogBodyFieldHandle = 'body';

    /** Volume-Handle für Bild-Uploads (Featured Images aus Deon AI). Leer = deaktiviert. */
    public string $assetVolumeHandle = '';

    /** Feld-Handle für das Featured-Image-Feld im Entry-Type. Leer = deaktiviert. */
    public string $featuredImageFieldHandle = '';

    /** robots.txt + llms.txt am Origin ausliefern (Deon AI verwaltet den Inhalt). */
    public bool $manageRobotsLlms = false;

    public function defineRules(): array
    {
        return [
            [['apiKey', 'siteId', 'sdkKey', 'verificationUuid', 'blogSectionHandle', 'blogBodyFieldHandle', 'assetVolumeHandle', 'featuredImageFieldHandle'], 'string'],
            [['injectSdk', 'manageRobotsLlms'], 'boolean'],
        ];
    }
}

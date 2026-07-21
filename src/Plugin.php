<?php
/**
 * Deon AI Connect für Craft CMS
 *
 * Dünner API-Client zum Deon AI Marketing-OS (audit.deon-ai.de):
 *  - injiziert SDK-Script + Domain-Verifizierungs-Meta-Tag automatisch
 *  - wendet SEO-Overrides (Title/Meta-Description/Canonical/Schema) nativ am
 *    Origin an — kein Client-Overlay, echtes HTML für Googlebot UND KI-Crawler
 *  - REST-Endpoints für Deon AI: Ping, SEO-Fix setzen, Blog-Entry anlegen
 *
 * Die gesamte Analyse-/AI-Logik bleibt im Deon-AI-Worker; das Plugin ist
 * bewusst schlank (analog zum WordPress-Plugin aideon-connect).
 */

namespace deonai\craftconnect;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\PluginEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Plugins as PluginsService;
use craft\web\Application as WebApplication;
use craft\web\UrlManager;
use craft\web\View;
use deonai\craftconnect\models\Settings;
use yii\base\Event;
use yii\web\Response;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.5.0';
    public bool $hasCpSettings = true;

    private const BOOTSTRAP_URL = 'https://audit.deon-ai.de/api/plugin/craft/bootstrap';

    /** Verhindert Endlosschleife: bootstrapFromDeonAi() speichert selbst wieder Settings. */
    private static bool $bootstrapping = false;

    public static function config(): array
    {
        return [
            'components' => [],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Console-Fallback für Self-Update (`php craft deon-ai-connect/update <version>`),
        // falls Composer im Web-Request an exec/memory-Limits scheitert.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'deonai\\craftconnect\\console\\controllers';
        }

        // Ein-Key-Onboarding: Nutzer trägt nur den Connection-Key ein, der Rest
        // (Site-ID, SDK-Key, Verifizierungs-UUID) wird beim Speichern automatisch
        // von Deon AI abgeholt (Bootstrap) — analog zum WordPress-Plugin-Flow.
        Event::on(
            PluginsService::class,
            PluginsService::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
            function (PluginEvent $event) {
                if ($event->plugin === $this && !self::$bootstrapping && Craft::$app instanceof WebApplication) {
                    $this->bootstrapFromDeonAi();
                }
            }
        );

        // Eigenes Artikel-Template (templates/entry.twig) unter "deon-ai/entry"
        // adressierbar machen — lebt im Plugin (vendor), nichts wird in das
        // templates/-Verzeichnis der Site geschrieben. setup-blog setzt dieses
        // Template auf den von ihr verwalteten Sections (siehe ApiController).
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['deon-ai'] = __DIR__ . '/templates';
            }
        );

        // REST-Routen für den Deon-AI-Worker (Auth via X-Deon-Key Header).
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['deon-ai/ping'] = 'deon-ai-connect/api/ping';
                $event->rules['deon-ai/self-update'] = 'deon-ai-connect/api/self-update';
                $event->rules['deon-ai/up'] = 'deon-ai-connect/api/up';
                $event->rules['deon-ai/setup-blog'] = 'deon-ai-connect/api/setup-blog';
                $event->rules['deon-ai/nav'] = 'deon-ai-connect/api/nav';
                // v0.8.0 — Seiten-Anbindung (Contract-Parität zum WordPress-Plugin aideon-connect)
                $event->rules['deon-ai/match-url'] = 'deon-ai-connect/api/match-url';
                $event->rules['deon-ai/pages'] = 'deon-ai-connect/api/pages';
                $event->rules['deon-ai/page-structure/<id:\d+>'] = 'deon-ai-connect/api/page-structure';
                $event->rules['deon-ai/set-widget-texts'] = 'deon-ai-connect/api/set-widget-texts';
                $event->rules['deon-ai/duplicate-page'] = 'deon-ai-connect/api/duplicate-page';
                $event->rules['deon-ai/render-preview'] = 'deon-ai-connect/api/render-preview';
                $event->rules['deon-ai/publish-lp'] = 'deon-ai-connect/api/publish-lp';
                $event->rules['deon-ai/theme-tokens'] = 'deon-ai-connect/api/theme-tokens';
                $event->rules['deon-ai/site-schema'] = 'deon-ai-connect/api/site-schema';
                $event->rules['deon-ai/sitemap-discover'] = 'deon-ai-connect/api/sitemap-discover';
                $event->rules['deon-ai/footer-links'] = 'deon-ai-connect/api/footer-links';
                // v0.9.0 — Section-Tests + A/B-Varianten (Craft-natives Pendant zu WP)
                $event->rules['deon-ai/section-test/create'] = 'deon-ai-connect/api/section-test-create';
                $event->rules['deon-ai/section-test/list/<id:\d+>'] = 'deon-ai-connect/api/section-test-list';
                $event->rules['deon-ai/section-test/stop'] = 'deon-ai-connect/api/section-test-stop';
                $event->rules['deon-ai/section-test/preview'] = 'deon-ai-connect/api/section-test-preview';
                $event->rules['deon-ai/publish-winner'] = 'deon-ai-connect/api/publish-winner';
                $event->rules['deon-ai/ab-variant/create'] = 'deon-ai-connect/api/ab-variant-create';
                $event->rules['deon-ai/ab-variant/list/<id:\d+>'] = 'deon-ai-connect/api/ab-variant-list';
                $event->rules['deon-ai/ab-variant/stop'] = 'deon-ai-connect/api/ab-variant-stop';
                $event->rules['deon-ai/configure-ab'] = 'deon-ai-connect/api/configure-ab';
                $event->rules['deon-ai/ab-status'] = 'deon-ai-connect/api/ab-status';
                $event->rules['deon-ai/configure-tracker'] = 'deon-ai-connect/api/configure-tracker';
                $event->rules['deon-ai/tracker-status'] = 'deon-ai-connect/api/tracker-status';
                // v0.10.0 — Content-Write-Fixes (Freshness-Refresh, interne Verlinkung)
                $event->rules['deon-ai/audit-fix'] = 'deon-ai-connect/api/audit-fix';
                // v0.13.0 — Asset-Bibliothek für Bild-Matching (Pendant zu WP wp-json/wp/v2/media)
                $event->rules['deon-ai/media'] = 'deon-ai-connect/api/media';

                // Full-Page-Landingpages (/deon-ai/publish-lp): eine Route pro
                // aktivem Slug. Fail-soft: Tabelle existiert vor der Migration
                // noch nicht — dann einfach keine LP-Routen.
                try {
                    $lpSlugs = (new \craft\db\Query())
                        ->select(['slug'])
                        ->from('{{%deonai_landing_pages}}')
                        ->where(['enabled' => true])
                        ->column();
                    foreach ($lpSlugs as $lpSlug) {
                        $event->rules[$lpSlug] = ['route' => 'deon-ai-connect/api/render-lp', 'params' => ['slug' => $lpSlug]];
                    }
                } catch (\Throwable $e) {
                    // Tabelle fehlt (Migration nicht gelaufen) → keine LP-Routen.
                }
                $event->rules['deon-ai/seo'] = 'deon-ai-connect/api/set-seo';
                $event->rules['deon-ai/seo-list'] = 'deon-ai-connect/api/list-seo';
                $event->rules['deon-ai/entry'] = 'deon-ai-connect/api/upsert-entry';
                $event->rules['deon-ai/entries'] = 'deon-ai-connect/api/list-entries';
                $event->rules['deon-ai/asset'] = 'deon-ai-connect/api/upload-asset';
                $event->rules['deon-ai/files'] = 'deon-ai-connect/api/files';
                $event->rules['deon-ai/faq'] = 'deon-ai-connect/api/faq';
                $event->rules['deon-ai/page'] = 'deon-ai-connect/api/page';
                $event->rules['deon-ai/hygiene'] = 'deon-ai-connect/api/set-hygiene';
                $event->rules['deon-ai/hygiene-list'] = 'deon-ai-connect/api/hygiene-list';
                // Rollback-Journal: Proxy-Konvention des Deon-AI-Workers (analog zum
                // WordPress-/TYPO3-Plugin) — feste Unterpfade vor dem <id>-Catch-all,
                // sonst würde z. B. "list" fälschlich als <id> interpretiert.
                $event->rules['deon-ai/rollback/list'] = 'deon-ai-connect/api/rollback-list';
                $event->rules['deon-ai/rollback/restore-point'] = 'deon-ai-connect/api/rollback-create-restore-point';
                $event->rules['deon-ai/rollback/<id:[^\/]+>/preview'] = 'deon-ai-connect/api/rollback-preview';
                $event->rules['deon-ai/rollback/<id:[^\/]+>/restore'] = 'deon-ai-connect/api/rollback-restore';
                $event->rules['deon-ai/rollback/<id:[^\/]+>'] = 'deon-ai-connect/api/rollback-get';

                // robots.txt/llms.txt nur ausliefern, wenn explizit aktiviert — sonst
                // würde eine leere Tabelle jede physische robots.txt-Route verdecken.
                if ($this->getSettings()->manageRobotsLlms) {
                    $event->rules['robots.txt'] = 'deon-ai-connect/api/robots-txt';
                    $event->rules['llms.txt'] = 'deon-ai-connect/api/llms-txt';
                }
            }
        );

        // Frontend-HTML patchen: SDK + Verifizierungs-Tag + SEO-Overrides.
        // Response-Patch statt Template-Hook → funktioniert mit JEDEM Twig-
        // Layout, ohne dass der Kunde Templates anfassen muss.
        if (Craft::$app->getRequest()->getIsSiteRequest() && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            Event::on(
                Response::class,
                Response::EVENT_AFTER_PREPARE,
                function (Event $event) {
                    /** @var Response $response */
                    $response = $event->sender;
                    $this->patchHtmlResponse($response);
                }
            );
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('deon-ai-connect/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Speichert einen Teil-Satz an Settings-Änderungen (z. B. aus
     * ApiController::actionSetupBlog()), ohne EVENT_AFTER_SAVE_PLUGIN_SETTINGS
     * erneut einen Bootstrap-Call auszulösen. Merged IMMER mit den
     * bestehenden Settings (siehe v0.4.1-Fix: savePluginSettings() merged
     * selbst NICHT — ein Teil-Array würde alle anderen Felder zurücksetzen).
     */
    public function saveSettingsWithoutBootstrap(array $updates): bool
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();
        self::$bootstrapping = true;
        try {
            return Craft::$app->getPlugins()->savePluginSettings($this, array_merge($settings->getAttributes(), $updates));
        } finally {
            self::$bootstrapping = false;
        }
    }

    // ─── Ein-Key-Bootstrap ──────────────────────────────────────────────────

    /**
     * Holt Site-ID/SDK-Key/Verifizierungs-UUID von Deon AI ab, authentifiziert
     * über denselben Connection-Key, den eingehende Deon-AI-Calls prüfen
     * (X-Deon-Key). Fail-soft: schlägt der Call fehl, bleiben die Settings wie
     * zuvor gespeichert — nur eine CP-Meldung informiert den Nutzer.
     */
    private function bootstrapFromDeonAi(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();
        if (empty($settings->apiKey)) {
            return;
        }

        try {
            $client = Craft::createGuzzleClient(['timeout' => 10]);
            $response = $client->request('GET', self::BOOTSTRAP_URL, [
                'headers' => ['X-Deon-Key' => $settings->apiKey],
                'http_errors' => false,
            ]);
            $body = json_decode((string)$response->getBody(), true);

            if ($response->getStatusCode() !== 200 || empty($body['ok'])) {
                Craft::warning('deon-ai-connect bootstrap failed: HTTP ' . $response->getStatusCode(), __METHOD__);
                Craft::$app->getSession()->setError('Deon AI: Verbindung fehlgeschlagen — Connection-Key prüfen.');
                return;
            }

            self::$bootstrapping = true;
            try {
                // WICHTIG: savePluginSettings() merged NICHT mit den bestehenden
                // Settings — ein Teil-Array (nur siteId/sdkKey/verificationUuid)
                // hätte apiKey & alle anderen Felder auf ihre Defaults zurückgesetzt.
                // Deshalb immer das komplette aktuelle Settings-Array mitschicken.
                $updated = array_merge($settings->getAttributes(), [
                    'siteId' => (string)($body['site_id'] ?? $settings->siteId),
                    'sdkKey' => (string)($body['sdk_public_key'] ?? $settings->sdkKey),
                    'verificationUuid' => (string)($body['verification_uuid'] ?? $settings->verificationUuid),
                ]);
                Craft::$app->getPlugins()->savePluginSettings($this, $updated);
            } finally {
                self::$bootstrapping = false;
            }

            Craft::$app->getSession()->setNotice('Deon AI erfolgreich verbunden (Site-ID: ' . ($body['site_id'] ?? '?') . ').');
        } catch (\Throwable $e) {
            // Fail-soft: Netzwerkfehler o. Ä. dürfen das Speichern der Settings nie verhindern.
            Craft::warning('deon-ai-connect bootstrap error: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError('Deon AI: Verbindung fehlgeschlagen — ' . $e->getMessage());
        }
    }

    // ─── Frontend-Patching ────────────────────────────────────────────────

    private function patchHtmlResponse(Response $response): void
    {
        try {
            if ($response->format !== Response::FORMAT_HTML && $response->format !== 'template') {
                // Craft-Seiten laufen als 'template'; alles andere unangetastet lassen.
                if (!is_string($response->data) || stripos((string)($response->headers->get('content-type') ?? ''), 'text/html') === false) {
                    return;
                }
            }
            $html = $response->data;
            if (!is_string($html) || $html === '' || stripos($html, '</head>') === false) {
                return;
            }

            /** @var Settings $settings */
            $settings = $this->getSettings();
            $inject = '';

            // 1) Domain-Verifizierungs-Meta-Tag (macht den Wizard-Schritt automatisch)
            if (!empty($settings->verificationUuid)) {
                $inject .= '<meta name="deon-ai-verification" content="'
                    . htmlspecialchars($settings->verificationUuid, ENT_QUOTES) . '">';
            }

            // 2) SEO-Overrides für die aktuelle URI (Deon-AI-1-Klick-Fixes)
            $uri = '/' . ltrim(Craft::$app->getRequest()->getPathInfo(), '/');
            $override = $this->getOverrideForUri($uri);
            if ($override) {
                if (!empty($override['title'])) {
                    $safeTitle = htmlspecialchars($override['title'], ENT_QUOTES);
                    $count = 0;
                    $html = preg_replace('~<title>[\s\S]*?</title>~i', '<title>' . $safeTitle . '</title>', $html, 1, $count);
                    if ($count === 0) {
                        $inject .= '<title>' . $safeTitle . '</title>';
                    }
                }
                if (!empty($override['metaDescription'])) {
                    $safeDesc = htmlspecialchars($override['metaDescription'], ENT_QUOTES);
                    if (preg_match('~<meta[^>]+name=("|\')description\1~i', $html)) {
                        $html = preg_replace(
                            '~(<meta[^>]+name=("|\')description\2[^>]*content=)("|\')[^"\']*\3~i',
                            '$1$3' . $safeDesc . '$3',
                            $html,
                            1
                        );
                    } else {
                        $inject .= '<meta name="description" content="' . $safeDesc . '">';
                    }
                }
                if (!empty($override['canonical'])) {
                    $html = preg_replace('~<link[^>]+rel=("|\')canonical\1[^>]*>~i', '', $html);
                    $inject .= '<link rel="canonical" href="' . htmlspecialchars($override['canonical'], ENT_QUOTES) . '">';
                }
                if (!empty($override['schemaJson'])) {
                    // Bereits als JSON validiert beim Speichern (ApiController).
                    $inject .= '<script type="application/ld+json">' . $override['schemaJson'] . '</script>';
                }
            }

            // 3) Deon SDK (Tracking, Conversions, A/B-Varianten). Zusätzlich zum
            // CP-Setting remote abschaltbar über /deon-ai/configure-tracker.
            $trackerEnabled = (bool)($this->getJsonConfig('tracker_config')['enabled'] ?? true);
            if ($settings->injectSdk && $trackerEnabled && !empty($settings->siteId)) {
                $inject .= '<script src="https://audit.deon-ai.de/sdk.js" data-site-id="'
                    . htmlspecialchars($settings->siteId, ENT_QUOTES) . '"'
                    . (!empty($settings->sdkKey) ? ' data-key="' . htmlspecialchars($settings->sdkKey, ENT_QUOTES) . '"' : '')
                    . ' async></script>';
            }

            // 4) Site-weites JSON-LD (/deon-ai/site-schema) — Organization/LocalBusiness etc.
            $inject .= $this->renderSiteSchema();

            if ($inject !== '') {
                $html = preg_replace('~</head>~i', $inject . '</head>', $html, 1);
            }

            // 5) Footer-Links-Block (/deon-ai/footer-links) — Servicegebiete o. Ä.,
            // vor </body>, analog zum wp_footer-Hook des WordPress-Plugins.
            $footerHtml = $this->renderFooterLinks();
            if ($footerHtml !== '' && stripos($html, '</body>') !== false) {
                $html = preg_replace('~</body>~i', $footerHtml . '</body>', $html, 1);
            }

            // 6) Section-Test: läuft für den gerenderten Entry ein Test, sieht die
            // Hälfte der Besucher (Server-Cookie-Split, 50/50) den Varianten-Body.
            $html = $this->applySectionTestSplit($html, $response);

            // 7) A/B-Varianten-Snippet: Selector-basierte Frontend-Änderungen,
            // Cookie-Split im Browser (Port des WP-wp_head-Snippets).
            $abSnippet = $this->renderAbVariantSnippet();
            if ($abSnippet !== '' && stripos($html, '</head>') !== false) {
                $html = preg_replace('~</head>~i', $abSnippet . '</head>', $html, 1);
            }

            $response->data = $html;
        } catch (\Throwable $e) {
            // Fail-soft: das Plugin darf NIE die Seitenauslieferung brechen.
            Craft::warning('deon-ai-connect patch skipped: ' . $e->getMessage(), __METHOD__);
        }
    }

    private function getOverrideForUri(string $uri): ?array
    {
        try {
            $row = (new \craft\db\Query())
                ->from('{{%deonai_seo_overrides}}')
                ->where(['uri' => $uri, 'enabled' => true])
                ->orderBy(['dateUpdated' => SORT_DESC])
                ->one();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null; // Tabelle fehlt (Migration nicht gelaufen) → kein Patch
        }
    }

    /**
     * JSON-Config-Zeile aus deonai_seo_hygiene lesen (type z. B. 'ab_config',
     * 'tracker_config', 'footer_links'). Fail-soft: leeres Array bei Fehlern.
     */
    public function getJsonConfig(string $type): array
    {
        try {
            $json = (new \craft\db\Query())
                ->select(['content'])
                ->from('{{%deonai_seo_hygiene}}')
                ->where(['type' => $type])
                ->scalar();
            $data = $json ? json_decode((string)$json, true) : null;
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Bot-UAs, die nie in einen A/B-/Section-Split fallen dürfen (Regex identisch zum WP-Plugin). */
    private const BOT_UA_PATTERN = '/(googlebot|bingbot|baiduspider|yandexbot|duckduckbot|slurp|facebookexternalhit|twitterbot|linkedinbot|whatsapp|applebot|petalbot|ahrefsbot|semrushbot|mj12bot|dotbot)/i';

    /**
     * Server-seitiger Section-Test-Split (Craft-Pendant zum WP-template_redirect-
     * Router): läuft für den gerenderten Entry ein Test, wird per Cookie
     * "aideon_st_<id>" (Name identisch zu WP, damit SDK/Conversion-Attribution
     * gleich funktioniert) 50/50 gewürfelt. Variante B = der Body der geklonten
     * Varianten-Entry ersetzt den Original-Body im fertigen HTML.
     */
    private function applySectionTestSplit(string $html, Response $response): string
    {
        try {
            $element = Craft::$app->getUrlManager()->getMatchedElement();
            if (!$element instanceof \craft\elements\Entry || !$element->id) {
                return $html;
            }
            $test = (new \craft\db\Query())
                ->from('{{%deonai_section_tests}}')
                ->where(['originalId' => $element->id, 'status' => 'running'])
                ->orderBy(['dateCreated' => SORT_ASC])
                ->one();
            if (!$test) {
                return $html;
            }

            // Bots ausschließen — kein Split, Original ausspielen
            $ua = (string)(Craft::$app->getRequest()->getUserAgent() ?? '');
            if (preg_match(self::BOT_UA_PATTERN, $ua)) {
                return $html;
            }

            $cookieName = 'aideon_st_' . $element->id;
            $bucket = (string)($_COOKIE[$cookieName] ?? '');
            if ($bucket !== 'a' && $bucket !== 'b') {
                $bucket = (random_int(0, 1) === 1) ? 'b' : 'a';
                // Rohes setcookie() statt Yii-Response-Cookie: Yii würde den Wert
                // signieren (Cookie-Validation) — dann käme beim nächsten Request
                // ein Hash-Blob statt "a"/"b" an und der Split würde jedes Mal neu
                // würfeln. httponly=false, damit das SDK den Bucket für die
                // Conversion-Attribution lesen kann (identisch zum WP-Plugin).
                setcookie($cookieName, $bucket, [
                    'expires' => time() + 30 * 86400,
                    'path' => '/',
                    'samesite' => 'Lax',
                    'secure' => Craft::$app->getRequest()->getIsSecureConnection(),
                    'httponly' => false,
                ]);
                $_COOKIE[$cookieName] = $bucket;
                Craft::$app->getDb()->createCommand()
                    ->update('{{%deonai_section_tests}}', [
                        ($bucket === 'b' ? 'visitorsB' : 'visitorsA') => new \yii\db\Expression(($bucket === 'b' ? '[[visitorsB]]' : '[[visitorsA]]') . ' + 1'),
                    ], ['id' => $test['id']])->execute();
            }

            // Cache-Bypass, damit CDN/Proxies nicht eine Variante für alle einfrieren
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->add('Vary', 'Cookie');

            if ($bucket !== 'b') {
                return $html;
            }

            $variant = \craft\elements\Entry::find()->id((int)$test['variantId'])->status(null)->one();
            if (!$variant) {
                return $html;
            }
            [$originalBody, $variantBody] = [$this->entryBodyHtml($element), $this->entryBodyHtml($variant)];
            $originalBody = trim($originalBody);
            if ($originalBody === '' || trim($variantBody) === '') {
                return $html;
            }
            $pos = strpos($html, $originalBody);
            if ($pos === false) {
                // Template transformiert den Feld-HTML (z. B. Filter) — Split nicht möglich, Original ausspielen.
                Craft::warning('deon-ai-connect section-test: Original-Body im gerenderten HTML nicht gefunden (Entry ' . $element->id . ')', __METHOD__);
                return $html;
            }
            return substr_replace($html, $variantBody, $pos, strlen($originalBody));
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /** Body-HTML eines Entries (Settings-Handle, Fallback "deonBody"), fail-soft leer. */
    public function entryBodyHtml(\craft\elements\Entry $entry): string
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();
        foreach (array_unique(array_filter([$settings->blogBodyFieldHandle, 'deonBody'])) as $handle) {
            try {
                return (string)$entry->getFieldValue($handle);
            } catch (\Throwable $e) {
                continue;
            }
        }
        return '';
    }

    /**
     * Featured-Image-URL eines Entries, fail-soft (leerer String, falls kein
     * Feld/Bild vorhanden) — für templates/entry.twig, damit das Template
     * crasht nie, auch wenn das Bildfeld auf dieser Section fehlt.
     */
    public function entryFeaturedImageUrl(\craft\elements\Entry $entry): string
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();
        foreach (array_unique(array_filter([$settings->featuredImageFieldHandle, 'deonFeaturedImage'])) as $handle) {
            try {
                $value = $entry->getFieldValue($handle);
                if ($value && method_exists($value, 'one')) {
                    $asset = $value->one();
                    if ($asset && method_exists($asset, 'getUrl')) {
                        $url = $asset->getUrl();
                        if ($url) {
                            return (string)$url;
                        }
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return '';
    }

    /**
     * A/B-Varianten-Snippet für den gerenderten Entry — JS-Port des
     * wp_head-Snippets aus dem WP-Plugin (Cookie "aideon_ab_assign",
     * ?aideon_force=-Preview, sendBeacon-Tracking, Modi text/html/attr/
     * link/style/form). Leerer String, wenn kein laufender Test existiert
     * oder A/B via /deon-ai/configure-ab deaktiviert wurde.
     */
    private function renderAbVariantSnippet(): string
    {
        try {
            $abConfig = $this->getJsonConfig('ab_config');
            if (isset($abConfig['enabled']) && !$abConfig['enabled']) {
                return '';
            }
            $element = Craft::$app->getUrlManager()->getMatchedElement();
            if (!$element instanceof \craft\elements\Entry || !$element->id) {
                return '';
            }
            $rows = (new \craft\db\Query())
                ->from('{{%deonai_ab_variants}}')
                ->where(['entryId' => $element->id, 'status' => 'running'])
                ->all();
            if (empty($rows)) {
                return '';
            }
            $variants = [];
            foreach ($rows as $row) {
                $config = json_decode((string)$row['config'], true);
                if (is_array($config)) {
                    $variants[] = $config;
                }
            }
            if (empty($variants)) {
                return '';
            }
            $payload = json_encode($variants, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $postId = (int)$element->id;
            $trackUrl = json_encode('https://audit.deon-ai.de/api/ab-track');

            return <<<HTML
<!-- Deon AI A/B-Variant Splitting (Craft-Port des WP-Snippets v3.8) -->
<script>
(function(){
  var variants = $payload;
  var COOKIE = "aideon_ab_assign";
  function getCookie(n){var m=document.cookie.match(new RegExp("(?:^|; )"+n+"=([^;]*)"));return m?decodeURIComponent(m[1]):"";}
  function setCookie(n,v,d){var e=new Date(Date.now()+d*864e5);document.cookie=n+"="+encodeURIComponent(v)+"; expires="+e.toUTCString()+"; path=/; SameSite=Lax";}
  var qs = new URLSearchParams(window.location.search);
  var forceParam = qs.get("aideon_force");
  var forceBucket = null, forceVariantId = null;
  if (forceParam) {
    if (forceParam.indexOf(":") >= 0) { var parts = forceParam.split(":"); forceVariantId = parts[0]; forceBucket = parts[1]; }
    else { forceBucket = forceParam; }
  }
  var assigned = getCookie(COOKIE);
  var assignMap = {};
  try { assignMap = assigned ? JSON.parse(assigned) : {}; } catch(_) { assignMap = {}; }
  if (forceBucket) {
    var banner = document.createElement("div");
    banner.style.cssText = "position:fixed;bottom:16px;right:16px;z-index:2147483646;background:#0a0a0f;color:#c5f75e;padding:10px 16px;border-radius:10px;border:1px solid #c5f75e;font:600 13px system-ui;box-shadow:0 8px 24px rgba(0,0,0,0.4);";
    banner.textContent = "🔬 Preview: Variant " + forceBucket.toUpperCase() + (forceVariantId ? " (" + forceVariantId + ")" : "");
    if (document.body) document.body.appendChild(banner);
    else document.addEventListener("DOMContentLoaded", function(){ document.body.appendChild(banner); });
  }
  variants.forEach(function(v){
    if(!assignMap[v.id]) { assignMap[v.id] = (Math.random()*100 < (v.percentage||50)) ? "b" : "a"; }
    var bucket = assignMap[v.id];
    if (forceBucket && (!forceVariantId || forceVariantId === v.id)) { bucket = forceBucket; }
    try {
      navigator.sendBeacon($trackUrl, JSON.stringify({ variant_id: v.id, post_id: $postId, bucket: bucket, event: "impression" }));
    } catch(e) {}
    if (bucket === "b") {
      var apply = function(){
        try {
          var els = v.selector ? document.querySelectorAll(v.selector) : [];
          if (!els.length && v.find) {
            var html = document.body.innerHTML;
            if (html.indexOf(v.find) >= 0) document.body.innerHTML = html.split(v.find).join(v.replace || "");
            return;
          }
          els.forEach(function(el){
            if (v.mode === "html" && v.variant_b_html) {
              el.innerHTML = v.variant_b_html;
            } else if (v.mode === "text") {
              if (v.find && v.replace) {
                var t = el.innerHTML;
                if (t.indexOf(v.find) >= 0) el.innerHTML = t.split(v.find).join(v.replace);
              } else if (v.replace) { el.textContent = v.replace; }
            } else if (v.mode === "attr" && v.attr && v.value) {
              if (v.attr === "style") { el.style.backgroundImage = "url('" + v.value + "')"; }
              else {
                el.setAttribute(v.attr, v.value);
                if (v.attr === "src" && v.alt) el.setAttribute("alt", v.alt);
              }
            } else if (v.mode === "link") {
              var linkEl = el.tagName.toLowerCase() === "a" || el.tagName.toLowerCase() === "button" ? el : el.querySelector("a, button") || el;
              if (v.href) {
                if (linkEl.tagName.toLowerCase() === "a") linkEl.setAttribute("href", v.href);
                else linkEl.dataset.deonHref = v.href;
              }
              if (v.target) linkEl.setAttribute("target", v.target);
              if (v.text) linkEl.textContent = v.text;
            } else if (v.mode === "style") {
              if (v.bg_color) el.style.setProperty("background-color", v.bg_color, "important");
              if (v.color) el.style.setProperty("color", v.color, "important");
              if (v.custom_css) {
                v.custom_css.split(";").forEach(function(rule){
                  var parts = rule.split(":");
                  if (parts.length === 2) el.style.setProperty(parts[0].trim(), parts[1].trim(), "important");
                });
              }
            } else if (v.mode === "form") {
              var form = el.tagName.toLowerCase() === "form" ? el : el.closest("form") || el.querySelector("form") || el;
              if (form.tagName.toLowerCase() === "form") {
                if (v.form_action) form.setAttribute("action", v.form_action);
                if (v.form_method) form.setAttribute("method", v.form_method);
                if (v.submit_text) {
                  var submits = form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])');
                  submits.forEach(function(s){
                    if (s.tagName.toLowerCase() === "input") s.value = v.submit_text;
                    else s.textContent = v.submit_text;
                  });
                }
                if (Array.isArray(v.field_updates)) {
                  v.field_updates.forEach(function(fu){
                    var fields = form.querySelectorAll(fu.selector || ('[name="' + (fu.name||"") + '"]'));
                    fields.forEach(function(f){
                      if (fu.placeholder !== undefined) f.setAttribute("placeholder", fu.placeholder);
                      if (fu.label !== undefined) { var lbl = form.querySelector('label[for="'+f.id+'"]'); if (lbl) lbl.textContent = fu.label; }
                      if (fu.required !== undefined) { if (fu.required) f.setAttribute("required", "required"); else f.removeAttribute("required"); }
                      if (fu.value !== undefined) f.value = fu.value;
                      if (fu.type !== undefined && (f.tagName.toLowerCase() === "input")) f.setAttribute("type", fu.type);
                    });
                  });
                }
              }
            } else if (v.find && v.replace) {
              var t = el.innerHTML;
              if (t.indexOf(v.find) >= 0) el.innerHTML = t.split(v.find).join(v.replace);
            }
          });
        } catch(e){}
      };
      if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", apply);
      else apply();
    }
  });
  if (!forceBucket) setCookie(COOKIE, JSON.stringify(assignMap), 30);
})();
</script>
HTML;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Site-weites JSON-LD als <script>-Tags (gespeichert via /deon-ai/site-schema). */
    private function renderSiteSchema(): string
    {
        try {
            $json = (new \craft\db\Query())
                ->select(['content'])
                ->from('{{%deonai_seo_hygiene}}')
                ->where(['type' => 'site_schema'])
                ->scalar();
            if (!$json) {
                return '';
            }
            $schemas = json_decode((string)$json, true);
            if (!is_array($schemas)) {
                return '';
            }
            $out = '';
            foreach (array_slice($schemas, 0, 10) as $schema) {
                if (!is_array($schema)) {
                    continue;
                }
                $out .= '<script type="application/ld+json">'
                    . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . '</script>';
            }
            return $out;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Footer-Links-Block (gespeichert via /deon-ai/footer-links) — Markup und
     * Inline-Styles bewusst identisch zum wp_footer-Hook des WordPress-Plugins,
     * damit Standortseiten-Verlinkung auf beiden Plattformen gleich aussieht.
     */
    private function renderFooterLinks(): string
    {
        try {
            $json = (new \craft\db\Query())
                ->select(['content'])
                ->from('{{%deonai_seo_hygiene}}')
                ->where(['type' => 'footer_links'])
                ->scalar();
            if (!$json) {
                return '';
            }
            $data = json_decode((string)$json, true);
            if (!is_array($data) || empty($data['links']) || !is_array($data['links'])) {
                return '';
            }
            $items = [];
            foreach (array_slice($data['links'], 0, 20) as $link) {
                if (!is_array($link) || empty($link['page_id'])) {
                    continue;
                }
                $entry = \craft\elements\Entry::find()->id((int)$link['page_id'])->one(); // nur live-Entries (Default-Status-Filter)
                if (!$entry || !$entry->getUrl()) {
                    continue;
                }
                $label = !empty($link['label']) ? (string)$link['label'] : (string)$entry->title;
                if ($label === '') {
                    continue;
                }
                $items[] = '<a href="' . htmlspecialchars($entry->getUrl(), ENT_QUOTES) . '" style="color:inherit;text-decoration:underline;text-underline-offset:2px;">'
                    . htmlspecialchars($label, ENT_QUOTES) . '</a>';
            }
            if (empty($items)) {
                return '';
            }
            $heading = !empty($data['heading']) ? (string)$data['heading'] : 'Servicegebiete';
            return "\n" . '<nav class="deon-footer-links" aria-label="' . htmlspecialchars($heading, ENT_QUOTES) . '" style="max-width:1140px;margin:0 auto;padding:18px 16px;font-size:13px;line-height:2.1;opacity:.78;text-align:center;color:inherit;">'
                . '<span style="font-weight:600;margin-right:10px;">' . htmlspecialchars($heading, ENT_QUOTES) . ':</span>'
                . implode(' <span aria-hidden="true">&middot;</span> ', $items)
                . '</nav>' . "\n";
        } catch (\Throwable $e) {
            return '';
        }
    }
}

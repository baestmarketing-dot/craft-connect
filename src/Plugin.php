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
use craft\events\RegisterUrlRulesEvent;
use craft\services\Plugins as PluginsService;
use craft\web\Application as WebApplication;
use craft\web\UrlManager;
use deonai\craftconnect\models\Settings;
use yii\base\Event;
use yii\web\Response;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.3.0';
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

            // 3) Deon SDK (Tracking, Conversions, A/B-Varianten)
            if ($settings->injectSdk && !empty($settings->siteId)) {
                $inject .= '<script src="https://audit.deon-ai.de/sdk.js" data-site-id="'
                    . htmlspecialchars($settings->siteId, ENT_QUOTES) . '"'
                    . (!empty($settings->sdkKey) ? ' data-key="' . htmlspecialchars($settings->sdkKey, ENT_QUOTES) . '"' : '')
                    . ' async></script>';
            }

            if ($inject !== '') {
                $html = preg_replace('~</head>~i', $inject . '</head>', $html, 1);
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
}

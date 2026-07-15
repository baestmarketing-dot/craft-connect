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
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use deonai\craftconnect\models\Settings;
use yii\base\Event;
use yii\web\Response;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [],
        ];
    }

    public function init(): void
    {
        parent::init();

        // REST-Routen für den Deon-AI-Worker (Auth via X-Deon-Key Header).
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['deon-ai/ping'] = 'deon-ai-connect/api/ping';
                $event->rules['deon-ai/seo'] = 'deon-ai-connect/api/set-seo';
                $event->rules['deon-ai/seo-list'] = 'deon-ai-connect/api/list-seo';
                $event->rules['deon-ai/entry'] = 'deon-ai-connect/api/upsert-entry';
                $event->rules['deon-ai/entries'] = 'deon-ai-connect/api/list-entries';
                $event->rules['deon-ai/asset'] = 'deon-ai-connect/api/upload-asset';
                $event->rules['deon-ai/hygiene'] = 'deon-ai-connect/api/set-hygiene';
                $event->rules['deon-ai/hygiene-list'] = 'deon-ai-connect/api/hygiene-list';
                $event->rules['deon-ai/changes'] = 'deon-ai-connect/api/list-changes';
                $event->rules['deon-ai/rollback'] = 'deon-ai-connect/api/rollback';

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

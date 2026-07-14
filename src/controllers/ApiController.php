<?php

namespace deonai\craftconnect\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Controller;
use deonai\craftconnect\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * REST-Endpoints für den Deon-AI-Worker.
 * Auth: Header "X-Deon-Key" muss dem Plugin-Setting apiKey entsprechen.
 */
class ApiController extends Controller
{
    protected array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    private function requireDeonKey(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $sent = Craft::$app->getRequest()->getHeaders()->get('X-Deon-Key') ?? '';
        if (empty($settings->apiKey) || !hash_equals($settings->apiKey, (string)$sent)) {
            throw new ForbiddenHttpException('Invalid API key');
        }
    }

    /** GET /deon-ai/ping — Health + Versionen (für "Verbindung prüfen"). */
    public function actionPing(): Response
    {
        $this->requireDeonKey();
        return $this->asJson([
            'ok' => true,
            'plugin' => 'deon-ai-connect',
            'version' => Plugin::getInstance()->getVersion(),
            'craft' => Craft::$app->getVersion(),
            'php' => PHP_VERSION,
        ]);
    }

    /**
     * POST /deon-ai/seo — SEO-Override setzen (1-Klick-Fix aus Deon AI).
     * Body: { uri, title?, meta_description?, canonical?, schema_json?, enabled? }
     */
    public function actionSetSeo(): Response
    {
        $this->requireDeonKey();
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $uri = '/' . ltrim((string)($body['uri'] ?? ''), '/');
        if ($uri === '/' && empty($body['allow_homepage'])) {
            // Homepage nur mit explizitem Flag — Schutz vor versehentlichem Root-Patch.
            if (($body['uri'] ?? '') === '') {
                return $this->asJson(['ok' => false, 'error' => 'uri required'])->setStatusCode(400);
            }
        }

        $schemaJson = null;
        if (!empty($body['schema_json'])) {
            $decoded = json_decode((string)$body['schema_json'], true);
            if ($decoded === null) {
                return $this->asJson(['ok' => false, 'error' => 'schema_json is not valid JSON'])->setStatusCode(400);
            }
            $schemaJson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $db = Craft::$app->getDb();
        $table = '{{%deonai_seo_overrides}}';
        $existing = (new \craft\db\Query())->from($table)->where(['uri' => $uri])->one();

        $columns = [
            'title' => isset($body['title']) ? mb_substr((string)$body['title'], 0, 200) : ($existing['title'] ?? null),
            'metaDescription' => isset($body['meta_description']) ? mb_substr((string)$body['meta_description'], 0, 300) : ($existing['metaDescription'] ?? null),
            'canonical' => isset($body['canonical']) ? mb_substr((string)$body['canonical'], 0, 500) : ($existing['canonical'] ?? null),
            'schemaJson' => $schemaJson ?? ($existing['schemaJson'] ?? null),
            'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : true,
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $db->createCommand()->update($table, $columns, ['id' => $existing['id']])->execute();
        } else {
            $columns['uri'] = $uri;
            $columns['dateCreated'] = $columns['dateUpdated'];
            $columns['uid'] = \craft\helpers\StringHelper::UUID();
            $db->createCommand()->insert($table, $columns)->execute();
        }

        return $this->asJson(['ok' => true, 'uri' => $uri, 'applied' => array_keys(array_filter([
            'title' => isset($body['title']),
            'meta_description' => isset($body['meta_description']),
            'canonical' => isset($body['canonical']),
            'schema_json' => isset($body['schema_json']),
        ]))]);
    }

    /** GET /deon-ai/seo-list — alle Overrides (für Änderungsjournal/Rollback). */
    public function actionListSeo(): Response
    {
        $this->requireDeonKey();
        $rows = (new \craft\db\Query())
            ->from('{{%deonai_seo_overrides}}')
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit(200)
            ->all();
        return $this->asJson(['ok' => true, 'overrides' => $rows]);
    }

    /**
     * POST /deon-ai/entry — Blog-Entry anlegen/aktualisieren (Deon-Blog-Publish).
     * Body: { title, slug?, body_html, status? ("live"|"disabled"), entry_id?,
     *         section?, body_field?, image_url?, asset_id? }
     * section/body_field überschreiben die Plugin-Settings, damit Deon AI auch in
     * andere Sections (z. B. lokale Landingpages) publishen kann.
     */
    public function actionUpsertEntry(): Response
    {
        $this->requireDeonKey();
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $settings = Plugin::getInstance()->getSettings();

        $title = trim((string)($body['title'] ?? ''));
        $html = (string)($body['body_html'] ?? '');
        if ($title === '' || $html === '') {
            return $this->asJson(['ok' => false, 'error' => 'title + body_html required'])->setStatusCode(400);
        }

        $sectionHandle = !empty($body['section']) ? (string)$body['section'] : $settings->blogSectionHandle;
        $bodyFieldHandle = !empty($body['body_field']) ? (string)$body['body_field'] : $settings->blogBodyFieldHandle;

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            return $this->asJson([
                'ok' => false,
                'error' => 'section_not_found',
                'hint' => 'Section-Handle "' . $sectionHandle . '" existiert nicht — im Plugin-Setting anpassen oder "section" im Request mitgeben.',
            ])->setStatusCode(422);
        }

        $entry = null;
        if (!empty($body['entry_id'])) {
            $entry = Entry::find()->id((int)$body['entry_id'])->status(null)->one();
        }
        if (!$entry) {
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entryTypes = $section->getEntryTypes();
            $entry->typeId = $entryTypes[0]->id;
        }

        $entry->title = mb_substr($title, 0, 255);
        if (!empty($body['slug'])) {
            $entry->slug = mb_substr((string)$body['slug'], 0, 200);
        }
        $entry->enabled = (($body['status'] ?? 'disabled') === 'live');

        // Body-Feld setzen (fail-soft, falls Handle nicht existiert)
        try {
            $entry->setFieldValue($bodyFieldHandle, $html);
        } catch (\Throwable $e) {
            return $this->asJson([
                'ok' => false,
                'error' => 'body_field_not_found',
                'hint' => 'Feld-Handle "' . $bodyFieldHandle . '" existiert im Entry-Type nicht — im Plugin-Setting anpassen oder "body_field" im Request mitgeben.',
            ])->setStatusCode(422);
        }

        // Featured Image (fail-soft: Volume/Feld nicht konfiguriert oder Upload
        // fehlgeschlagen → Entry trotzdem ohne Bild speichern).
        if (!empty($settings->featuredImageFieldHandle) && !empty($settings->assetVolumeHandle)) {
            $assetId = null;
            if (!empty($body['asset_id'])) {
                $assetId = (int)$body['asset_id'];
            } elseif (!empty($body['image_url'])) {
                $bytes = $this->fetchUrlBytes((string)$body['image_url']);
                if ($bytes !== null) {
                    $assetId = $this->createAssetFromBytes($bytes, basename((string)$body['image_url']) ?: 'deon-ai-image.jpg', $settings->assetVolumeHandle);
                }
            }
            if ($assetId) {
                try {
                    $entry->setFieldValue($settings->featuredImageFieldHandle, [$assetId]);
                } catch (\Throwable $e) {
                    // Feld-Handle falsch konfiguriert — Entry trotzdem speichern.
                }
            }
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return $this->asJson(['ok' => false, 'error' => 'save_failed', 'details' => $entry->getErrors()])->setStatusCode(500);
        }

        return $this->asJson([
            'ok' => true,
            'entry_id' => $entry->id,
            'url' => $entry->getUrl(),
            'status' => $entry->enabled ? 'live' : 'disabled',
        ]);
    }

    /**
     * GET /deon-ai/entries — bestehende Entries einer Section auflisten
     * (Duplikat-Check vor dem Anlegen). Query: ?section=&limit=
     */
    public function actionListEntries(): Response
    {
        $this->requireDeonKey();
        $settings = Plugin::getInstance()->getSettings();
        $sectionHandle = (string)(Craft::$app->getRequest()->getQueryParam('section') ?: $settings->blogSectionHandle);
        $limit = min((int)(Craft::$app->getRequest()->getQueryParam('limit') ?: 50), 200);

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            return $this->asJson([
                'ok' => false,
                'error' => 'section_not_found',
                'hint' => 'Section-Handle "' . $sectionHandle . '" existiert nicht.',
            ])->setStatusCode(422);
        }

        $entries = Entry::find()
            ->sectionId($section->id)
            ->status(null)
            ->limit($limit)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->all();

        return $this->asJson(['ok' => true, 'entries' => array_map(static function (Entry $e) {
            return [
                'id' => $e->id,
                'title' => $e->title,
                'slug' => $e->slug,
                'url' => $e->getUrl(),
                'status' => $e->getStatus(),
                'dateUpdated' => $e->dateUpdated?->format(\DateTime::ATOM),
            ];
        }, $entries)]);
    }

    /**
     * POST /deon-ai/asset — Bild-Asset anlegen (z. B. Featured Image).
     * Body: { filename?, image_url } ODER { filename?, data_base64 }
     */
    public function actionUploadAsset(): Response
    {
        $this->requireDeonKey();
        $this->requirePostRequest();
        $settings = Plugin::getInstance()->getSettings();
        $body = Craft::$app->getRequest()->getBodyParams();

        if (empty($settings->assetVolumeHandle)) {
            return $this->asJson(['ok' => false, 'error' => 'asset_volume_not_configured'])->setStatusCode(422);
        }

        $filename = (string)($body['filename'] ?? 'deon-ai-image.jpg');

        if (!empty($body['image_url'])) {
            $bytes = $this->fetchUrlBytes((string)$body['image_url']);
            if ($bytes === null) {
                return $this->asJson(['ok' => false, 'error' => 'image_url nicht erreichbar oder zu groß'])->setStatusCode(400);
            }
        } elseif (!empty($body['data_base64'])) {
            $bytes = base64_decode((string)$body['data_base64'], true);
            if ($bytes === false) {
                return $this->asJson(['ok' => false, 'error' => 'data_base64 ist kein gültiges Base64'])->setStatusCode(400);
            }
        } else {
            return $this->asJson(['ok' => false, 'error' => 'image_url oder data_base64 erforderlich'])->setStatusCode(400);
        }

        $assetId = $this->createAssetFromBytes($bytes, $filename, $settings->assetVolumeHandle);
        if (!$assetId) {
            return $this->asJson([
                'ok' => false,
                'error' => 'volume_not_found_or_save_failed',
                'hint' => 'Volume-Handle "' . $settings->assetVolumeHandle . '" prüfen.',
            ])->setStatusCode(422);
        }

        $asset = Asset::find()->id($assetId)->one();
        return $this->asJson(['ok' => true, 'asset_id' => $assetId, 'url' => $asset?->getUrl()]);
    }

    /**
     * POST /deon-ai/hygiene — robots.txt/llms.txt Inhalt setzen.
     * Body: { type ("robots"|"llms"), content }
     */
    public function actionSetHygiene(): Response
    {
        $this->requireDeonKey();
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $type = (string)($body['type'] ?? '');
        if (!in_array($type, ['robots', 'llms'], true)) {
            return $this->asJson(['ok' => false, 'error' => 'type must be "robots" or "llms"'])->setStatusCode(400);
        }
        $content = (string)($body['content'] ?? '');
        if ($content === '') {
            return $this->asJson(['ok' => false, 'error' => 'content required'])->setStatusCode(400);
        }
        $content = mb_substr($content, 0, 20000);

        $db = Craft::$app->getDb();
        $table = '{{%deonai_seo_hygiene}}';
        $existing = (new \craft\db\Query())->from($table)->where(['type' => $type])->one();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        if ($existing) {
            $db->createCommand()->update($table, ['content' => $content, 'dateUpdated' => $now], ['id' => $existing['id']])->execute();
        } else {
            $db->createCommand()->insert($table, [
                'type' => $type,
                'content' => $content,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        return $this->asJson(['ok' => true, 'type' => $type]);
    }

    /** GET /deon-ai/hygiene-list — aktuelle robots.txt/llms.txt Inhalte (für den Editor). */
    public function actionHygieneList(): Response
    {
        $this->requireDeonKey();
        $rows = (new \craft\db\Query())
            ->select(['type', 'content', 'dateUpdated'])
            ->from('{{%deonai_seo_hygiene}}')
            ->all();
        return $this->asJson(['ok' => true, 'items' => $rows]);
    }

    /** GET /robots.txt — nur aktiv, wenn Plugin-Setting manageRobotsLlms an ist. */
    public function actionRobotsTxt(): Response
    {
        return $this->serveHygiene('robots');
    }

    /** GET /llms.txt — nur aktiv, wenn Plugin-Setting manageRobotsLlms an ist. */
    public function actionLlmsTxt(): Response
    {
        return $this->serveHygiene('llms');
    }

    private function serveHygiene(string $type): Response
    {
        $content = (new \craft\db\Query())
            ->select(['content'])
            ->from('{{%deonai_seo_hygiene}}')
            ->where(['type' => $type])
            ->scalar();

        if ($content === false) {
            throw new NotFoundHttpException();
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->data = $content;
        return $response;
    }

    /**
     * Lädt eine externe Bild-URL fail-soft (Timeout + 15 MB Limit), null bei Fehler.
     * SSRF-Schutz: nur http/https, keine privaten/internen IPs, keine Redirects
     * (verhindert, dass eine Redirect-Kette den Host-Check umgeht).
     */
    private function fetchUrlBytes(string $url, int $maxBytes = 15 * 1024 * 1024): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $context = stream_context_create([
            'http' => ['timeout' => 15, 'follow_location' => 0],
            'https' => ['timeout' => 15, 'follow_location' => 0],
        ]);
        $bytes = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if ($bytes === false || strlen($bytes) > $maxBytes) {
            return null;
        }
        return $bytes;
    }

    /** Legt ein Asset aus Roh-Bytes im konfigurierten Volume an, gibt die Asset-ID zurück (null bei Fehler). */
    private function createAssetFromBytes(string $bytes, string $filename, string $volumeHandle): ?int
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        if (!$volume) {
            return null;
        }

        $filename = AssetsHelper::prepareAssetName($filename ?: 'deon-ai-image.jpg');
        $tmpPath = Craft::$app->getPath()->getTempPath() . '/' . StringHelper::UUID() . '-' . $filename;
        FileHelper::writeToFile($tmpPath, $bytes);

        try {
            $asset = new Asset();
            $asset->tempFilePath = $tmpPath;
            $asset->filename = $filename;
            $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id;
            $asset->volumeId = $volume->id;
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                return null;
            }
            return $asset->id;
        } finally {
            if (is_file($tmpPath)) {
                FileHelper::unlink($tmpPath);
            }
        }
    }
}

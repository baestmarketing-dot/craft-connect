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

        $changeId = $this->logChange('seo_override', $uri, $existing ?: null, $columns, isset($body['note']) ? (string)$body['note'] : null);

        return $this->asJson(['ok' => true, 'uri' => $uri, 'rollback_id' => 'rb_' . $changeId, 'applied' => array_keys(array_filter([
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
        $isNewEntry = !$entry;
        $beforeState = null;
        if (!$isNewEntry) {
            $beforeState = [
                'title' => $entry->title,
                'slug' => $entry->slug,
                'enabled' => $entry->enabled,
                'bodyFieldHandle' => $bodyFieldHandle,
                'bodyValue' => $this->safeFieldString($entry, $bodyFieldHandle),
            ];
            if (!empty($settings->featuredImageFieldHandle)) {
                $beforeState['imageFieldHandle'] = $settings->featuredImageFieldHandle;
                $beforeState['imageAssetIds'] = $this->safeFieldAssetIds($entry, $settings->featuredImageFieldHandle);
            }
        } else {
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

        $afterState = [
            'title' => $entry->title,
            'slug' => $entry->slug,
            'enabled' => $entry->enabled,
            'bodyFieldHandle' => $bodyFieldHandle,
            'bodyValue' => $this->safeFieldString($entry, $bodyFieldHandle),
        ];
        if (!empty($settings->featuredImageFieldHandle)) {
            $afterState['imageFieldHandle'] = $settings->featuredImageFieldHandle;
            $afterState['imageAssetIds'] = $this->safeFieldAssetIds($entry, $settings->featuredImageFieldHandle);
        }
        $changeId = $this->logChange('entry', (string)$entry->id, $beforeState, $afterState, isset($body['note']) ? (string)$body['note'] : null);

        return $this->asJson([
            'ok' => true,
            'entry_id' => $entry->id,
            'rollback_id' => 'rb_' . $changeId,
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

        $changeId = $this->logChange('hygiene', $type, $existing ?: null, ['content' => $content], isset($body['note']) ? (string)$body['note'] : null);

        return $this->asJson(['ok' => true, 'type' => $type, 'rollback_id' => 'rb_' . $changeId]);
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

    /**
     * GET /deon-ai/rollback/list — Änderungs-Journal (Proxy-Konvention: der
     * Deon-AI-Worker leitet den Rollback-Tab 1:1 an diese Unterpfade weiter,
     * analog zum WordPress-/TYPO3-Plugin-Journal).
     * Query: ?limit=
     */
    public function actionRollbackList(): Response
    {
        $this->requireDeonKey();
        $limit = min((int)(Craft::$app->getRequest()->getQueryParam('limit') ?: 50), 200);
        $rows = (new \craft\db\Query())
            ->from('{{%deonai_change_log}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();

        $entries = array_map(function (array $row) {
            return [
                'rollback_id' => 'rb_' . $row['id'],
                'operation_type' => $row['beforeJson'] === null ? 'creation' : 'mutation',
                'endpoint' => self::TARGET_LABELS[$row['targetType']] ?? $row['targetType'],
                'action' => $row['targetKey'],
                'status' => $row['rolledBack'] ? 'restored' : 'applied',
                'created_at' => $row['dateCreated'],
            ];
        }, $rows);

        return $this->asJson(['ok' => true, 'entries' => $entries]);
    }

    /** GET /deon-ai/rollback/<id> — Einzelnen Journal-Eintrag abrufen. */
    public function actionRollbackGet(string $id): Response
    {
        $this->requireDeonKey();
        $log = $this->findChangeLog($id);
        if (!$log) {
            return $this->asJson(['ok' => false, 'error' => 'not_found'])->setStatusCode(404);
        }
        return $this->asJson(['ok' => true] + $this->changeLogToEntry($log));
    }

    /** POST /deon-ai/rollback/<id>/preview — zeigt, was ein Rollback wiederherstellen würde. */
    public function actionRollbackPreview(string $id): Response
    {
        $this->requireDeonKey();
        $log = $this->findChangeLog($id);
        if (!$log) {
            return $this->asJson(['ok' => false, 'error' => 'not_found'])->setStatusCode(404);
        }
        if ($log['rolledBack']) {
            return $this->asJson(['ok' => false, 'error' => 'already_restored']);
        }

        [$conflict] = $this->checkConflict($log);
        if ($conflict) {
            return $this->asJson(['conflict' => true, 'message' => 'Diese Seite wurde nach der Deon-AI-Änderung erneut bearbeitet.']);
        }

        return $this->asJson(['will_do' => $this->describeChange($log)]);
    }

    /**
     * POST /deon-ai/rollback/<id>/restore — Änderung rückgängig machen.
     * Body: { force? } — überschreibt einen erkannten Konflikt.
     */
    public function actionRollbackRestore(string $id): Response
    {
        $this->requireDeonKey();
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $force = !empty($body['force']);

        $log = $this->findChangeLog($id);
        if (!$log) {
            return $this->asJson(['ok' => false, 'error' => 'not_found'])->setStatusCode(404);
        }
        if ($log['rolledBack']) {
            return $this->asJson(['ok' => false, 'error' => 'already_restored'])->setStatusCode(409);
        }

        if (!$force) {
            [$conflict] = $this->checkConflict($log);
            if ($conflict) {
                return $this->asJson(['ok' => false, 'error' => 'conflict', 'message' => 'Diese Seite wurde nach der Deon-AI-Änderung erneut bearbeitet.'])->setStatusCode(409);
            }
        }

        $before = $log['beforeJson'] !== null ? json_decode($log['beforeJson'], true) : null;

        try {
            $result = match ($log['targetType']) {
                'seo_override' => $this->rollbackSeoOverride($log['targetKey'], $before),
                'hygiene' => $this->rollbackHygiene($log['targetKey'], $before),
                'entry' => $this->rollbackEntry($log['targetKey'], $before),
                'restore_point' => $this->restoreSnapshot(json_decode($log['afterJson'], true) ?: []),
                default => ['ok' => false, 'error' => 'unknown_target_type'],
            };
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'error' => 'rollback_failed', 'message' => $e->getMessage()])->setStatusCode(500);
        }

        if (!$result['ok']) {
            return $this->asJson($result)->setStatusCode(422);
        }

        Craft::$app->getDb()->createCommand()->update('{{%deonai_change_log}}', [
            'rolledBack' => true,
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $log['id']])->execute();

        return $this->asJson(array_merge(['success' => true], $result));
    }

    /**
     * POST /deon-ai/rollback/restore-point — kompletten Sicherungspunkt anlegen
     * (alle aktuell verwalteten SEO-Overrides, robots.txt/llms.txt und Entries
     * der konfigurierten Section). Erfüllt "einmal komplett gesichert, bevor
     * sich etwas ändert" — als reines SQL-Snapshot, kein shell_exec/mysqldump.
     * Body: { label? }
     */
    public function actionRollbackCreateRestorePoint(): Response
    {
        $this->requireDeonKey();
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $label = (string)($body['label'] ?? 'Sicherungspunkt');
        $settings = Plugin::getInstance()->getSettings();

        $snapshot = [
            'seo_overrides' => (new \craft\db\Query())->from('{{%deonai_seo_overrides}}')->limit(500)->all(),
            'hygiene' => (new \craft\db\Query())->from('{{%deonai_seo_hygiene}}')->all(),
            'entries' => [],
        ];

        $section = Craft::$app->getEntries()->getSectionByHandle($settings->blogSectionHandle);
        if ($section) {
            $entries = Entry::find()->sectionId($section->id)->status(null)->limit(200)->all();
            foreach ($entries as $entry) {
                $snapshot['entries'][] = [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'slug' => $entry->slug,
                    'enabled' => $entry->enabled,
                    'bodyFieldHandle' => $settings->blogBodyFieldHandle,
                    'bodyValue' => $this->safeFieldString($entry, $settings->blogBodyFieldHandle),
                ];
            }
        }

        $changeId = $this->logChange('restore_point', $label, null, $snapshot, $label);

        return $this->asJson([
            'ok' => true,
            'rollback_id' => 'rb_' . $changeId,
            'seo_overrides' => count($snapshot['seo_overrides']),
            'hygiene' => count($snapshot['hygiene']),
            'entries' => count($snapshot['entries']),
        ]);
    }

    /** Menschlich lesbare Labels je Ziel-Typ, für Journal-Anzeige + Preview-Text. */
    private const TARGET_LABELS = [
        'seo_override' => 'SEO-Override',
        'hygiene' => 'robots.txt/llms.txt',
        'entry' => 'Blog-Entry',
        'restore_point' => 'Sicherungspunkt',
    ];

    private function findChangeLog(string $rawId): ?array
    {
        if (!preg_match('/^(?:rb_)?(\d+)$/', $rawId, $m)) {
            return null;
        }
        return (new \craft\db\Query())->from('{{%deonai_change_log}}')->where(['id' => (int)$m[1]])->one() ?: null;
    }

    private function changeLogToEntry(array $log): array
    {
        return [
            'rollback_id' => 'rb_' . $log['id'],
            'operation_type' => $log['beforeJson'] === null ? 'creation' : 'mutation',
            'endpoint' => self::TARGET_LABELS[$log['targetType']] ?? $log['targetType'],
            'action' => $log['targetKey'],
            'status' => $log['rolledBack'] ? 'restored' : 'applied',
            'created_at' => $log['dateCreated'],
        ];
    }

    private function describeChange(array $log): string
    {
        $label = self::TARGET_LABELS[$log['targetType']] ?? $log['targetType'];
        if ($log['targetType'] === 'restore_point') {
            $snapshot = json_decode($log['afterJson'], true) ?: [];
            return sprintf(
                'Stellt %d SEO-Override(s), %d robots.txt/llms.txt-Eintrag/Einträge und %d Entry/Entries auf den Stand von "%s" zurück.',
                count($snapshot['seo_overrides'] ?? []),
                count($snapshot['hygiene'] ?? []),
                count($snapshot['entries'] ?? []),
                $log['targetKey']
            );
        }
        $verb = $log['beforeJson'] === null ? 'entfernt' : 'setzt zurück';
        return $label . ' für "' . $log['targetKey'] . '" wird ' . $verb . '.';
    }

    /**
     * Grober Konflikt-Check: hat sich der Live-Zustand seit dieser Deon-AI-
     * Änderung verändert (jemand hat manuell danach editiert)? Vergleicht den
     * damals gespeicherten Nachher-Zustand mit dem aktuellen.
     * @return array{0: bool} [conflict]
     */
    private function checkConflict(array $log): array
    {
        $after = json_decode($log['afterJson'], true) ?: [];
        $current = match ($log['targetType']) {
            'seo_override' => (new \craft\db\Query())->from('{{%deonai_seo_overrides}}')->where(['uri' => $log['targetKey']])->one() ?: null,
            'hygiene' => (new \craft\db\Query())->from('{{%deonai_seo_hygiene}}')->where(['type' => $log['targetKey']])->one() ?: null,
            'entry' => null, // Entries ändern sich zu leicht (dateUpdated etc.) für einen sinnvollen Diff — kein Konflikt-Check.
            default => null,
        };
        if ($current === null) {
            return [false];
        }
        $fields = match ($log['targetType']) {
            'seo_override' => ['title', 'metaDescription', 'canonical', 'schemaJson'],
            'hygiene' => ['content'],
            default => [],
        };
        foreach ($fields as $field) {
            if (($current[$field] ?? null) !== ($after[$field] ?? null)) {
                return [true];
            }
        }
        return [false];
    }

    private function rollbackSeoOverride(string $uri, ?array $before): array
    {
        $db = Craft::$app->getDb();
        $table = '{{%deonai_seo_overrides}}';
        if ($before === null) {
            $db->createCommand()->delete($table, ['uri' => $uri])->execute();
            return ['ok' => true, 'action' => 'deleted'];
        }
        $db->createCommand()->update($table, [
            'title' => $before['title'] ?? null,
            'metaDescription' => $before['metaDescription'] ?? null,
            'canonical' => $before['canonical'] ?? null,
            'schemaJson' => $before['schemaJson'] ?? null,
            'enabled' => $before['enabled'] ?? true,
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['uri' => $uri])->execute();
        return ['ok' => true, 'action' => 'restored'];
    }

    private function rollbackHygiene(string $type, ?array $before): array
    {
        $db = Craft::$app->getDb();
        $table = '{{%deonai_seo_hygiene}}';
        if ($before === null) {
            $db->createCommand()->delete($table, ['type' => $type])->execute();
            return ['ok' => true, 'action' => 'deleted'];
        }
        $db->createCommand()->update($table, [
            'content' => $before['content'] ?? '',
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['type' => $type])->execute();
        return ['ok' => true, 'action' => 'restored'];
    }

    private function rollbackEntry(string $entryId, ?array $before): array
    {
        $entry = Entry::find()->id((int)$entryId)->status(null)->one();
        if (!$entry) {
            return ['ok' => false, 'error' => 'entry_no_longer_exists'];
        }

        if ($before === null) {
            // Entry wurde von Deon AI neu angelegt — Rollback = zurück in den Papierkorb
            // (Craft löscht Entries standardmäßig weich, nicht endgültig).
            if (!Craft::$app->getElements()->deleteElement($entry)) {
                return ['ok' => false, 'error' => 'delete_failed'];
            }
            return ['ok' => true, 'action' => 'deleted'];
        }

        $entry->title = (string)($before['title'] ?? $entry->title);
        if (!empty($before['slug'])) {
            $entry->slug = (string)$before['slug'];
        }
        $entry->enabled = (bool)($before['enabled'] ?? $entry->enabled);

        if (!empty($before['bodyFieldHandle'])) {
            try {
                $entry->setFieldValue((string)$before['bodyFieldHandle'], $before['bodyValue'] ?? '');
            } catch (\Throwable $e) {
                // Feld existiert nicht mehr — restlichen Rollback trotzdem durchführen.
            }
        }
        if (!empty($before['imageFieldHandle'])) {
            try {
                $entry->setFieldValue((string)$before['imageFieldHandle'], $before['imageAssetIds'] ?? []);
            } catch (\Throwable $e) {
                // Feld existiert nicht mehr — restlichen Rollback trotzdem durchführen.
            }
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return ['ok' => false, 'error' => 'save_failed', 'details' => $entry->getErrors()];
        }
        return ['ok' => true, 'action' => 'restored'];
    }

    /** Spielt einen kompletten Sicherungspunkt zurück (siehe actionRollbackCreateRestorePoint). */
    private function restoreSnapshot(array $snapshot): array
    {
        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Kein upsert(): deonai_seo_overrides.uri hat keinen echten Unique-Constraint
        // (nur einen Index) — manuelles Select-dann-Insert-oder-Update wie im Rest
        // des Controllers, statt uns auf DB-seitige ON-DUPLICATE-KEY-Semantik zu
        // verlassen, die ohne Unique-Key nicht zuverlässig greift.
        foreach ($snapshot['seo_overrides'] ?? [] as $row) {
            $uri = (string)$row['uri'];
            $existing = (new \craft\db\Query())->from('{{%deonai_seo_overrides}}')->where(['uri' => $uri])->one();
            $columns = array_diff_key($row, ['id' => true, 'uid' => true, 'dateCreated' => true]);
            $columns['dateUpdated'] = $now;
            if ($existing) {
                $db->createCommand()->update('{{%deonai_seo_overrides}}', $columns, ['id' => $existing['id']])->execute();
            } else {
                $columns['dateCreated'] = $now;
                $columns['uid'] = StringHelper::UUID();
                $db->createCommand()->insert('{{%deonai_seo_overrides}}', $columns)->execute();
            }
        }
        foreach ($snapshot['hygiene'] ?? [] as $row) {
            $type = (string)$row['type'];
            $existing = (new \craft\db\Query())->from('{{%deonai_seo_hygiene}}')->where(['type' => $type])->one();
            $columns = array_diff_key($row, ['id' => true, 'uid' => true, 'dateCreated' => true]);
            $columns['dateUpdated'] = $now;
            if ($existing) {
                $db->createCommand()->update('{{%deonai_seo_hygiene}}', $columns, ['id' => $existing['id']])->execute();
            } else {
                $columns['dateCreated'] = $now;
                $columns['uid'] = StringHelper::UUID();
                $db->createCommand()->insert('{{%deonai_seo_hygiene}}', $columns)->execute();
            }
        }
        $restoredEntries = 0;
        foreach ($snapshot['entries'] ?? [] as $entrySnapshot) {
            $entry = Entry::find()->id((int)$entrySnapshot['id'])->status(null)->one();
            if (!$entry) {
                continue; // Entry existiert nicht mehr — überspringen statt neu anzulegen (ID nicht wiederverwendbar).
            }
            $entry->title = (string)($entrySnapshot['title'] ?? $entry->title);
            $entry->enabled = (bool)($entrySnapshot['enabled'] ?? $entry->enabled);
            if (!empty($entrySnapshot['bodyFieldHandle'])) {
                try {
                    $entry->setFieldValue((string)$entrySnapshot['bodyFieldHandle'], $entrySnapshot['bodyValue'] ?? '');
                } catch (\Throwable $e) {
                    continue;
                }
            }
            if (Craft::$app->getElements()->saveElement($entry)) {
                $restoredEntries++;
            }
        }

        return ['ok' => true, 'action' => 'restored', 'entries_restored' => $restoredEntries];
    }

    /** Protokolliert eine Änderung (Vorher-/Nachher-Zustand) fürs Rollback. Gibt die change_id zurück. */
    private function logChange(string $targetType, string $targetKey, ?array $before, array $after, ?string $note = null): int
    {
        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $db->createCommand()->insert('{{%deonai_change_log}}', [
            'targetType' => $targetType,
            'targetKey' => mb_substr($targetKey, 0, 255),
            'beforeJson' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'afterJson' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'rolledBack' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        return (int)$db->getLastInsertID();
    }

    /** Best-effort String-Snapshot eines Feldwerts fürs Änderungsprotokoll (fail-soft). */
    private function safeFieldString(Entry $entry, string $fieldHandle): string
    {
        try {
            $value = $entry->getFieldValue($fieldHandle);
            return (string)$value;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Best-effort Asset-ID-Liste eines Bild-Feldwerts fürs Änderungsprotokoll (fail-soft). */
    private function safeFieldAssetIds(Entry $entry, string $fieldHandle): array
    {
        try {
            $value = $entry->getFieldValue($fieldHandle);
            return method_exists($value, 'ids') ? $value->ids() : [];
        } catch (\Throwable $e) {
            return [];
        }
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

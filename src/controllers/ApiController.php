<?php

namespace deonai\craftconnect\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Assets as AssetsField;
use craft\fields\Matrix as MatrixField;
use craft\fields\PlainText;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
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

    /** Grenzen für resolveEntrySections()/den Section-Reader — gegen teure, tief verschachtelte Neo-Strukturen. */
    private const SECTION_READER_MAX_DEPTH = 4;
    private const SECTION_READER_MAX_BLOCKS = 200;

    /** Ordnet die kurzen Berechtigungs-Keys (siehe /deon-ai/ping) den Settings-Properties zu. */
    private const PERMISSION_PROPERTIES = [
        'seo_meta' => 'allowSeoMeta',
        'content_edit' => 'allowContentEdit',
        'page_create' => 'allowPageCreate',
        'files' => 'allowFiles',
        'assets' => 'allowAssets',
        'self_update' => 'allowSelfUpdate',
        'nav_edit' => 'allowNavEdit',
        'ab_test' => 'allowAbTest',
    ];

    /** Prüft eine Berechtigung; gibt bei fehlender Freigabe die fertige 403-Response zurück, sonst null. */
    private function checkPermission(string $key): ?Response
    {
        $property = self::PERMISSION_PROPERTIES[$key] ?? null;
        $settings = Plugin::getInstance()->getSettings();
        if ($property !== null && !empty($settings->$property)) {
            return null;
        }
        return $this->asJson(['ok' => false, 'error' => 'consent_required', 'permission' => $key])->setStatusCode(403);
    }

    /**
     * Section-Service versionsübergreifend auflösen. KRITISCH: In Craft 5
     * wurden Section-Methoden (getSectionByHandle/saveSection/saveEntryType/
     * getAllSections/…) in den Entries-Service gemergt — craft\services\Entries
     * hat sie dort. In Craft 4 existieren sie NUR im separaten
     * craft\services\Sections (Craft::$app->getSections()); craft\services\
     * Entries kennt in Craft 4 ausschließlich Entry-Element-Methoden.
     * Craft::$app->getEntries()->getSectionByHandle() ist auf Craft 4 also ein
     * Fatal Error ("Call to undefined method") — composer.json erlaubt aber
     * ^4.0.0|^5.0.0. Erkennung über den registrierten Component-Namen statt
     * version_compare, damit es robust gegen künftige Verschiebungen bleibt.
     */
    private function sectionsService(): object
    {
        return Craft::$app->has('sections') ? Craft::$app->get('sections') : Craft::$app->getEntries();
    }

    /**
     * Verhindert "Eintrag ohne Titel" im CP: Craft überschreibt jeden von uns
     * gesetzten Titel automatisch mit leer/berechnet, sobald der Entry-Type
     * kein Titel-Feld hat (craft\elements\Entry::updateTitle(), läuft
     * unumgehbar in beforeSave()) — außer ein titleFormat ist konfiguriert
     * und liefert selbst einen Wert. Betrifft nur Entry-Types, die NICHT über
     * createDeonSection() angelegt wurden (die setzen hasTitleField immer),
     * also bestehende, extern konfigurierte Sections. Statt eine Deon-AI-
     * Seite scheinbar erfolgreich, aber untitled zu veröffentlichen, brechen
     * wir hier mit einer klaren Anleitung ab.
     */
    private function checkEntryTypeTitleField(EntryType $entryType): ?Response
    {
        if ($entryType->hasTitleField || !empty($entryType->titleFormat)) {
            return null;
        }
        return $this->asJson([
            'ok' => false,
            'error' => 'title_field_missing',
            'hint' => 'Entry-Type "' . $entryType->handle . '" hat weder ein Titel-Feld noch ein titleFormat — Craft würde jeden gesetzten Titel beim Speichern automatisch leeren (Ergebnis: "Eintrag ohne Titel" im CP). Mit POST /deon-ai/setup-blog { "fix_template": true } beheben (aktiviert das Titel-Feld für diesen Entry-Type).',
        ])->setStatusCode(422);
    }

    /** GET /deon-ai/ping — Health + Versionen (für "Verbindung prüfen"). */
    public function actionPing(): Response
    {
        $this->requireDeonKey();
        $settings = Plugin::getInstance()->getSettings();
        $version = Plugin::getInstance()->getVersion();
        [$selfUpdateCapable] = $this->selfUpdatePreflight();

        return $this->asJson([
            'ok' => true,
            'plugin' => 'deon-ai-connect',
            'version' => $version,
            'craft' => Craft::$app->getVersion(),
            'php' => PHP_VERSION,
            // Duplikate der obigen Felder unter den vom Worker erwarteten Namen (Self-Update-Erkennung).
            'plugin_version' => $version,
            'craft_version' => Craft::$app->getVersion(),
            'php_version' => PHP_VERSION,
            // Fähigkeits-Flag: kann dieser Server technisch überhaupt selbst updaten
            // (proc_open, Speicher, composer.phar) — unabhängig von der Freigabe
            // durch die Kundin, die unter permissions.self_update steht.
            'self_update' => $selfUpdateCapable,
            // Feature-Liste dieser Plugin-Version — Namensschema wie WP /capabilities,
            // damit der Worker beide Plattformen einheitlich gaten kann.
            'capabilities' => [
                'seo_meta', 'create_post', 'faq_inject', 'seo_files', 'rollback',
                'setup_blog', 'nav_edit', 'self_update',
                'url_match', 'page_structure', 'render_preview', 'duplicate_page',
                'widget_texts', 'publish_lp', 'theme_tokens', 'seo_schema',
                'sitemap_discover', 'footer_links',
                'content_replace', 'section_test', 'ab_variant_split',
                'ab_script_inject', 'tracker_inject', 'audit_fix', 'media_inventory',
                'site_inventory', 'entry_sections',
            ],
            'sections_ok' => [
                'blog' => (bool)$this->sectionsService()->getSectionByHandle($settings->blogSectionHandle),
                'pages' => (bool)$this->sectionsService()->getSectionByHandle($settings->pagesSectionHandle ?: $settings->blogSectionHandle),
            ],
            // Echte Handle-Existenz, nicht nur "ist das Setting nicht leer" —
            // ein Setting kann auf einen längst gelöschten Feld-Handle zeigen.
            'fields_ok' => [
                'body' => (bool)Craft::$app->getFields()->getFieldByHandle($settings->blogBodyFieldHandle),
                // assetVolumeHandle MUSS mitgeprüft werden — /entry und /page hängen
                // Featured Images nur an, wenn beide Settings gesetzt sind (siehe
                // dortiges empty(featuredImageFieldHandle)||empty(assetVolumeHandle)).
                // Ohne diese Prüfung meldet ping fälschlich true, obwohl Bild-Uploads
                // faktisch nie ausgeführt werden — und der Worker-Self-Heal ruft
                // setup-blog dann nie erneut auf, weil er genau dieses Flag prüft.
                'featured_image' => !empty($settings->featuredImageFieldHandle)
                    && (bool)Craft::$app->getFields()->getFieldByHandle($settings->featuredImageFieldHandle)
                    && !empty($settings->assetVolumeHandle)
                    && (bool)Craft::$app->getVolumes()->getVolumeByHandle($settings->assetVolumeHandle),
            ],
            'nav' => [
                'verbb' => $this->isVerbbNavigationInstalled(),
                'editable' => $this->isVerbbNavigationInstalled() && (bool)$settings->allowNavEdit,
            ],
            'permissions' => [
                'seo_meta' => (bool)$settings->allowSeoMeta,
                'content_edit' => (bool)$settings->allowContentEdit,
                'page_create' => (bool)$settings->allowPageCreate,
                'files' => (bool)$settings->allowFiles,
                'assets' => (bool)$settings->allowAssets,
                'self_update' => (bool)$settings->allowSelfUpdate,
                'nav_edit' => (bool)$settings->allowNavEdit,
                'ab_test' => (bool)$settings->allowAbTest,
            ],
        ]);
    }

    /**
     * POST /deon-ai/self-update — Plugin per Composer auf eine konkrete Zielversion
     * anheben (Phase A: Composer-Swap). Body: { version: "0.6.1" }
     * Führt bewusst KEINE Migrationen im selben Request aus — der alte Klassen-
     * Code ist nach dem Composer-Swap noch geladen. Der Worker ruft danach
     * POST /deon-ai/up in einem NEUEN Request auf (siehe dort).
     */
    public function actionSelfUpdate(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('self_update')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $target = trim((string)($body['version'] ?? ''));
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $target)) {
            return $this->asJson(['ok' => false, 'error' => 'version required (semver, e.g. "0.6.1")'])->setStatusCode(400);
        }

        $current = Plugin::getInstance()->getVersion();
        if ($target === $current) {
            return $this->asJson(['ok' => true, 'already' => true, 'version' => $current]);
        }

        [$capable, $reason] = $this->selfUpdatePreflight();
        if (!$capable) {
            return $this->asJson(['ok' => false, 'error' => 'self_update_unavailable', 'reason' => $reason])->setStatusCode(422);
        }

        \craft\helpers\App::maxPowerCaptain();

        try {
            Craft::$app->getDb()->backup();
        } catch (\Throwable $e) {
            // Fail-soft: DB-Dump braucht mysqldump/pg_dump per shell_exec, das ist auf
            // manchem Shared-Hosting gesperrt — darf das eigentliche Update nie blockieren.
            Craft::warning('deon-ai-connect self-update: DB-Backup fehlgeschlagen: ' . $e->getMessage(), __METHOD__);
        }

        try {
            // Nur das eigene Paket anfassen — niemals "craft update all" o. Ä.
            // Nur EIN Argument übergeben: Composer::install()'s zweiter Parameter ist in
            // Craft 5 ein callable, in Craft 4 ein Composer\IO\IOInterface — inkompatible
            // Signaturen. Ohne zweites Argument funktioniert der Aufruf auf beiden.
            Craft::$app->getComposer()->install(['deon-ai/craft-connect' => '==' . $target]);
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'error' => 'self_update_failed', 'message' => $e->getMessage()])->setStatusCode(500);
        }

        return $this->asJson(['ok' => true, 'from' => $current, 'to' => $target, 'needs_migration' => true]);
    }

    /**
     * POST /deon-ai/up — Phase B: Plugin-Migrationen nach einem Composer-Swap
     * ausführen (neuer Request, damit der neue Code bereits geladen ist).
     * Bewusst NICHT über checkPermission('self_update') gegated: an dieser
     * Stelle liegt der neue Code bereits auf der Platte, ein verweigertes
     * Migrieren würde die Seite mit neuem Code + altem DB-Schema zurücklassen.
     */
    public function actionUp(): Response
    {
        $this->requireDeonKey();
        $this->requirePostRequest();

        try {
            Craft::$app->getUpdates()->runMigrations(['deon-ai-connect']);
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'error' => 'migration_failed', 'message' => $e->getMessage()])->setStatusCode(500);
        }

        return $this->asJson(['ok' => true, 'migrated' => true, 'version' => Plugin::getInstance()->getVersion()]);
    }

    /**
     * Fähigkeits-Check fürs Self-Update: kann dieser Server technisch Composer
     * als Subprozess starten? (Craft nutzt intern Symfony Process/proc_open,
     * siehe craft\services\Composer::runComposerCommand().)
     * @return array{0: bool, 1: ?string} [capable, reason]
     */
    private function selfUpdatePreflight(): array
    {
        if (!function_exists('proc_open')) {
            return [false, 'proc_open ist nicht verfügbar.'];
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return [false, 'proc_open ist über disable_functions gesperrt (typisch bei restriktivem Shared-Hosting).'];
        }
        $memoryLimit = \craft\helpers\App::phpConfigValueInBytes('memory_limit');
        if ($memoryLimit !== -1 && $memoryLimit < 256 * 1024 * 1024) {
            return [false, 'memory_limit ist kleiner als 256M.'];
        }
        $pharPath = Craft::getAlias('@lib/composer.phar');
        if (!is_file($pharPath)) {
            return [false, 'composer.phar wurde in dieser Craft-Installation nicht gefunden.'];
        }
        return [true, null];
    }

    /**
     * POST /deon-ai/seo — SEO-Override setzen (1-Klick-Fix aus Deon AI).
     * Body: { uri, title?, meta_description?, canonical?, schema_json?, enabled? }
     */
    public function actionSetSeo(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('seo_meta')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $uri = '/' . ltrim((string)($body['uri'] ?? ''), '/');
        if ($uri === '/' && empty($body['allow_homepage'])) {
            // Homepage nur mit explizitem Flag — Schutz vor versehentlichem Root-Patch.
            // Greift unabhängig davon, ob uri fehlte oder explizit "/" gesendet wurde —
            // beides normalisiert oben auf "/" und muss gleich behandelt werden.
            return $this->asJson(['ok' => false, 'error' => 'homepage_requires_allow_homepage_flag'])->setStatusCode(400);
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
            // Fällt auf den BESTEHENDEN Wert zurück, nicht auf true — sonst würde
            // jedes Teil-Update (z. B. nur "title" ändern) einen bewusst deaktivierten
            // Override stillschweigend wieder aktivieren.
            'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : (bool)($existing['enabled'] ?? true),
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
        if ($response = $this->checkPermission('page_create')) {
            return $response;
        }
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

        $section = $this->sectionsService()->getSectionByHandle($sectionHandle);
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
            if (!$entry) {
                // Bewusst 404 statt still ein neues Entry anzulegen — sonst würde ein
                // Worker-Retry mit veralteter/gelöschter entry_id (statt eines Updates)
                // fortlaufend Duplikate erzeugen.
                return $this->asJson(['ok' => false, 'error' => 'entry_not_found'])->setStatusCode(404);
            }
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
            $targetEntryType = $entry->getType();
        } else {
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entryTypes = $section->getEntryTypes();
            $entry->typeId = $entryTypes[0]->id;
            $targetEntryType = $entryTypes[0];
        }

        if ($response = $this->checkEntryTypeTitleField($targetEntryType)) {
            return $response;
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

        $section = $this->sectionsService()->getSectionByHandle($sectionHandle);
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
        if ($response = $this->checkPermission('assets')) {
            return $response;
        }
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

    /** Physische Dateien, auf die /deon-ai/files zugreifen darf — strikte Whitelist. */
    private const ALLOWED_FILES = ['llms.txt', 'robots.txt'];

    /**
     * POST /deon-ai/files — robots.txt/llms.txt direkt im Webroot lesen/schreiben.
     * Body: { op: "read"|"write", filename: "llms.txt"|"robots.txt", content? }
     */
    public function actionFiles(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('files')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $filename = (string)($body['filename'] ?? '');
        if (!in_array($filename, self::ALLOWED_FILES, true)) {
            return $this->asJson(['ok' => false, 'error' => 'filename must be one of: ' . implode(', ', self::ALLOWED_FILES)])->setStatusCode(400);
        }
        $path = rtrim(Craft::getAlias('@webroot'), '/') . '/' . $filename;
        $op = (string)($body['op'] ?? '');

        if ($op === 'read') {
            $exists = is_file($path);
            $content = $exists ? (string)file_get_contents($path) : '';
            return $this->asJson(['ok' => true, 'exists' => $exists, 'filename' => $filename, 'content' => $content]);
        }

        if ($op === 'write') {
            $content = (string)($body['content'] ?? '');
            if (is_file($path)) {
                $this->backupContent('file:' . $filename, (string)file_get_contents($path));
            }
            try {
                FileHelper::writeToFile($path, $content);
            } catch (\Throwable $e) {
                return $this->asJson(['ok' => false, 'error' => 'write_failed', 'message' => $e->getMessage()])->setStatusCode(500);
            }
            return $this->asJson(['ok' => true, 'filename' => $filename, 'bytes' => strlen($content)]);
        }

        return $this->asJson(['ok' => false, 'error' => 'op must be "read" or "write"'])->setStatusCode(400);
    }

    /**
     * POST /deon-ai/faq — FAQ-Block sichtbar in den Entry-Body einbauen.
     * Body: { uri, faq_html, body_field? }
     * Idempotent: enthält der Body bereits einen Block mit data-deon-faq,
     * wird dieser ersetzt statt einen zweiten anzuhängen.
     */
    public function actionFaq(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('content_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $settings = Plugin::getInstance()->getSettings();

        $rawUri = trim((string)($body['uri'] ?? ''), '/');
        $uri = $rawUri === '' ? '__home__' : $rawUri;
        $faqHtml = (string)($body['faq_html'] ?? '');
        if ($faqHtml === '') {
            return $this->asJson(['ok' => false, 'error' => 'faq_html required'])->setStatusCode(400);
        }
        $bodyFieldHandle = !empty($body['body_field']) ? (string)$body['body_field'] : $settings->blogBodyFieldHandle;

        $entry = Entry::find()->uri($uri)->status(null)->one();
        if (!$entry) {
            return $this->asJson(['ok' => false, 'error' => 'entry_not_found'])->setStatusCode(404);
        }

        try {
            $currentBody = (string)$entry->getFieldValue($bodyFieldHandle);
        } catch (\Throwable $e) {
            return $this->asJson([
                'ok' => false,
                'error' => 'body_field_not_found',
                'hint' => 'Feld-Handle "' . $bodyFieldHandle . '" existiert im Entry-Type nicht — im Plugin-Setting anpassen oder "body_field" im Request mitgeben.',
            ])->setStatusCode(422);
        }

        $this->backupContent('entry:' . $entry->id . ':' . $bodyFieldHandle, $currentBody);

        $faqBlockPattern = '~<section\b[^>]*\bdata-deon-faq\b[^>]*>.*?</section>~is';
        $replacedExisting = (bool)preg_match($faqBlockPattern, $currentBody);
        // preg_replace_callback statt preg_replace: $faqHtml als Replacement-STRING
        // würde PHP als Backreference-Syntax interpretieren ($1, \1, ...) — enthält
        // der generierte FAQ-Text z. B. einen Preis ("Kosten: $29/Monat"), würde "$29"
        // stillschweigend durch einen Leerstring ersetzt (Pattern hat keine Gruppen).
        $newBody = $replacedExisting
            ? preg_replace_callback($faqBlockPattern, static fn() => $faqHtml, $currentBody, 1)
            : $currentBody . $faqHtml;

        try {
            $entry->setFieldValue($bodyFieldHandle, $newBody);
        } catch (\Throwable $e) {
            return $this->asJson([
                'ok' => false,
                'error' => 'body_field_not_found',
                'hint' => 'Feld-Handle "' . $bodyFieldHandle . '" existiert im Entry-Type nicht.',
            ])->setStatusCode(422);
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return $this->asJson(['ok' => false, 'error' => 'save_failed', 'details' => $entry->getErrors()])->setStatusCode(500);
        }

        return $this->asJson([
            'ok' => true,
            'entry_id' => $entry->id,
            'url' => $entry->getUrl(),
            'replaced_existing' => $replacedExisting,
        ]);
    }

    /**
     * POST /deon-ai/page — native Seite anlegen/aktualisieren (Standortseiten,
     * KI-Faktenseite). Body: { title, slug?, body_html, status?, section?, entry_id? }
     * Section-Auflösung: section-Param > Setting pagesSectionHandle > blogSectionHandle.
     * Default-Status "disabled" (Entwurf) — geht nie ungefragt live.
     */
    public function actionPage(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('page_create')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $settings = Plugin::getInstance()->getSettings();

        $title = trim((string)($body['title'] ?? ''));
        $html = (string)($body['body_html'] ?? '');
        if ($title === '' || $html === '') {
            return $this->asJson(['ok' => false, 'error' => 'title + body_html required'])->setStatusCode(400);
        }

        $sectionHandle = !empty($body['section'])
            ? (string)$body['section']
            : (!empty($settings->pagesSectionHandle) ? $settings->pagesSectionHandle : $settings->blogSectionHandle);

        $section = $this->sectionsService()->getSectionByHandle($sectionHandle);
        if (!$section) {
            return $this->asJson([
                'ok' => false,
                'error' => 'section_not_found',
                'hint' => 'Section-Handle "' . $sectionHandle . '" existiert nicht — "pagesSectionHandle" in den Plugin-Settings oder "section" im Request anpassen.',
            ])->setStatusCode(422);
        }

        $entry = null;
        if (!empty($body['entry_id'])) {
            $entry = Entry::find()->id((int)$body['entry_id'])->status(null)->one();
            if (!$entry) {
                // Bewusst 404 statt still ein neues Entry anzulegen — sonst würde ein
                // Worker-Retry mit veralteter/gelöschter entry_id (statt eines Updates)
                // fortlaufend Duplikate erzeugen.
                return $this->asJson(['ok' => false, 'error' => 'entry_not_found'])->setStatusCode(404);
            }
        }
        if (!$entry) {
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entryTypes = $section->getEntryTypes();
            $entry->typeId = $entryTypes[0]->id;
            $targetEntryType = $entryTypes[0];
        } else {
            $targetEntryType = $entry->getType();
        }

        if ($response = $this->checkEntryTypeTitleField($targetEntryType)) {
            return $response;
        }

        $entry->title = mb_substr($title, 0, 255);
        if (!empty($body['slug'])) {
            $entry->slug = mb_substr((string)$body['slug'], 0, 200);
        }
        $entry->enabled = (($body['status'] ?? 'disabled') === 'live');

        try {
            $entry->setFieldValue($settings->blogBodyFieldHandle, $html);
        } catch (\Throwable $e) {
            return $this->asJson([
                'ok' => false,
                'error' => 'body_field_not_found',
                'hint' => 'Feld-Handle "' . $settings->blogBodyFieldHandle . '" existiert im Entry-Type nicht.',
            ])->setStatusCode(422);
        }

        // Featured Image (fail-soft: Volume/Feld nicht konfiguriert oder Upload
        // fehlgeschlagen → Seite trotzdem ohne Bild speichern).
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
                    // Feld-Handle falsch konfiguriert — Seite trotzdem speichern.
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
            'uri' => $entry->uri,
            'section' => $sectionHandle,
            'status' => $entry->enabled ? 'live' : 'disabled',
        ]);
    }

    // ─── Feature 1: Blog-/Seiten-Bootstrap ─────────────────────────────────

    private const DEON_BODY_FIELD_HANDLE = 'deonBody';
    private const DEON_IMAGE_FIELD_HANDLE = 'deonFeaturedImage';
    private const DEON_BLOG_SECTION_HANDLE = 'deonBlog';
    private const DEON_PAGES_SECTION_HANDLE = 'deonPages';

    /** Handle des Site-Template-Roots + Template, das das Plugin selbst mitliefert (siehe templates/entry.twig). */
    private const DEON_ENTRY_TEMPLATE = 'deon-ai/entry';
    private const DEON_PAGE_TEMPLATE = 'deon-ai/page';

    /**
     * POST /deon-ai/setup-blog — legt Blog-/Seiten-Section, Body- und
     * Featured-Image-Feld an, falls sie fehlen, und verdrahtet die
     * Plugin-Settings automatisch damit. Idempotent: bestehende, gültige
     * Handles werden NIE angetastet — nur leere/kaputte Settings werden
     * (um)geschrieben. Body optional: { fix_template?: bool } — siehe
     * ensureSectionTemplate().
     */
    public function actionSetupBlog(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('page_create')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $fixTemplate = !empty($body['fix_template']);

        $fieldsService = Craft::$app->getFields();
        $entriesService = $this->sectionsService();
        $result = [
            'ok' => true,
            'fields' => [],
            'sections' => [],
            'featured_image' => 'ok',
            'template_missing' => [],
        ];

        // 1) Body-Feld
        $bodyField = $fieldsService->getFieldByHandle(self::DEON_BODY_FIELD_HANDLE);
        if ($bodyField) {
            $result['fields']['body'] = 'existing';
        } else {
            $bodyField = $this->createDeonBodyField();
            if (!$fieldsService->saveField($bodyField)) {
                return $this->asJson(['ok' => false, 'error' => 'body_field_save_failed', 'details' => $bodyField->getErrors()])->setStatusCode(500);
            }
            $result['fields']['body'] = 'created';
        }

        // 2) Featured-Image-Feld — braucht ein Volume; niemals selbst eins anlegen
        // (Filesystem/Storage ist hosting-abhängig), stattdessen überspringen.
        // $resolvedVolumeHandle wird unten in Schritt 4 in settings.assetVolumeHandle
        // geschrieben — ohne das bleibt das Bildfeld für /entry und /page tot, weil
        // beide zusätzlich zu featuredImageFieldHandle auch assetVolumeHandle prüfen.
        $imageField = $fieldsService->getFieldByHandle(self::DEON_IMAGE_FIELD_HANDLE);
        $resolvedVolumeHandle = null;
        if ($imageField) {
            $result['fields']['featured_image'] = 'existing';
            // Volume-Handle aus dem bereits existierenden Feld ableiten (heilt auch
            // Alt-Setups, bei denen das Feld schon vor diesem Fix angelegt wurde).
            // createDeonImageField() beschränkt über restrictLocation +
            // restrictedLocationSource ("volume:<uid>") — NICHT über das ererbte
            // BaseRelationField::$sources, das bleibt bei uns unangetastet auf "*".
            if ($imageField instanceof AssetsField && $imageField->restrictLocation && !empty($imageField->restrictedLocationSource)) {
                $source = $imageField->restrictedLocationSource;
                if (str_starts_with($source, 'volume:')) {
                    $vol = Craft::$app->getVolumes()->getVolumeByUid(substr($source, 7));
                    if ($vol) {
                        $resolvedVolumeHandle = $vol->handle;
                    }
                }
            }
        } else {
            $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
            if (!$volume) {
                $result['fields']['featured_image'] = 'skipped';
                $result['featured_image'] = 'no_volume';
            } else {
                $imageField = $this->createDeonImageField($volume);
                if (!$fieldsService->saveField($imageField)) {
                    return $this->asJson(['ok' => false, 'error' => 'image_field_save_failed', 'details' => $imageField->getErrors()])->setStatusCode(500);
                }
                $result['fields']['featured_image'] = 'created';
                $resolvedVolumeHandle = $volume->handle;
            }
        }

        // 3) Sections. Effektiven Section-Handle bestimmen: den bereits in den
        // Settings konfigurierten Handle, falls er auf eine wirklich
        // existierende Section zeigt — sonst unseren Bootstrap-Handle
        // (deonBlog/deonPages). WICHTIG: /entry und /page bespielen
        // settings.blogSectionHandle/pagesSectionHandle (Default "blog"/
        // "pages"), NICHT die deonBlog/deonPages-Konstanten — ohne diese
        // Auflösung würde die Template-Reparatur eine ungenutzte
        // deonBlog/deonPages-Section anlegen/fixen, während die Site
        // weiterhin über "blog"/"pages" ausliefert und optisch kaputt bleibt.
        $settings = Plugin::getInstance()->getSettings();
        $blogHandle = (!empty($settings->blogSectionHandle) && $entriesService->getSectionByHandle($settings->blogSectionHandle))
            ? $settings->blogSectionHandle
            : self::DEON_BLOG_SECTION_HANDLE;
        $pagesHandle = (!empty($settings->pagesSectionHandle) && $entriesService->getSectionByHandle($settings->pagesSectionHandle))
            ? $settings->pagesSectionHandle
            : self::DEON_PAGES_SECTION_HANDLE;

        // Neue Sections bekommen sofort unser eigenes, funktionierendes
        // Template (templates/entry.twig bzw. templates/page.twig) statt leer
        // + template_missing. Bestehende Sections: Template nur anfassen,
        // wenn leer oder fix_template explizit gesetzt ist — siehe
        // ensureSectionTemplate().
        $blogSection = $entriesService->getSectionByHandle($blogHandle);
        if ($blogSection) {
            $result['sections']['blog'] = 'existing';
            [$blogTemplateOutcome, $blogPreviousTemplate] = $this->ensureSectionTemplate($blogSection, $fixTemplate, self::DEON_ENTRY_TEMPLATE);
        } else {
            $blogSection = $this->createDeonSection($blogHandle, 'Blog', Section::TYPE_CHANNEL, 'blog/{slug}', $bodyField, $imageField, self::DEON_ENTRY_TEMPLATE);
            if (!$blogSection) {
                return $this->asJson(['ok' => false, 'error' => 'blog_section_save_failed'])->setStatusCode(500);
            }
            $result['sections']['blog'] = 'created';
            $blogTemplateOutcome = 'set';
            $blogPreviousTemplate = null;
        }
        if (!$this->sectionHasTemplate($blogSection)) {
            $result['template_missing'][] = 'blog';
        }
        $result['title_field'] = $this->ensureEntryTypeHasTitleField($blogSection, $fixTemplate);

        $pagesSection = $entriesService->getSectionByHandle($pagesHandle);
        if ($pagesSection) {
            $result['sections']['pages'] = 'existing';
            [$pagesTemplateOutcome, $pagesPreviousTemplate] = $this->ensureSectionTemplate($pagesSection, $fixTemplate, self::DEON_PAGE_TEMPLATE);
        } else {
            $pagesSection = $this->createDeonSection($pagesHandle, 'Seiten', Section::TYPE_STRUCTURE, '{slug}', $bodyField, $imageField, self::DEON_PAGE_TEMPLATE);
            if (!$pagesSection) {
                return $this->asJson(['ok' => false, 'error' => 'pages_section_save_failed'])->setStatusCode(500);
            }
            $result['sections']['pages'] = 'created';
            $pagesTemplateOutcome = 'set';
            $pagesPreviousTemplate = null;
        }
        if (!$this->sectionHasTemplate($pagesSection)) {
            $result['template_missing'][] = 'pages';
        }
        $result['title_field_pages'] = $this->ensureEntryTypeHasTitleField($pagesSection, $fixTemplate);

        // Primärer Contract-Wert = Blog-Section (der Pilot-Fall, für den dieses
        // Feature gebaut wurde). Pages additiv unter *_pages, damit ein Worker,
        // der nur "template"/"previous_template" liest, unverändert funktioniert.
        $result['template'] = $blogTemplateOutcome;
        if ($blogPreviousTemplate !== null) {
            $result['previous_template'] = $blogPreviousTemplate;
        }
        $result['template_pages'] = $pagesTemplateOutcome;
        if ($pagesPreviousTemplate !== null) {
            $result['previous_template_pages'] = $pagesPreviousTemplate;
        }

        // 4) Settings auf die tatsächlich verwendeten Handles verdrahten (oben
        // bereits aufgelöst) — eine bestehende, funktionierende Konfiguration
        // wird dabei nie überschrieben, da $blogHandle/$pagesHandle bereits
        // deren aktuellen Wert übernehmen, wenn er gültig war.
        $updates = [];
        if ($settings->blogSectionHandle !== $blogHandle) {
            $updates['blogSectionHandle'] = $blogHandle;
        }
        if ($settings->pagesSectionHandle !== $pagesHandle) {
            $updates['pagesSectionHandle'] = $pagesHandle;
        }
        if (empty($settings->blogBodyFieldHandle) || !$fieldsService->getFieldByHandle($settings->blogBodyFieldHandle)) {
            $updates['blogBodyFieldHandle'] = self::DEON_BODY_FIELD_HANDLE;
        }
        if ($imageField && (empty($settings->featuredImageFieldHandle) || !$fieldsService->getFieldByHandle($settings->featuredImageFieldHandle))) {
            $updates['featuredImageFieldHandle'] = self::DEON_IMAGE_FIELD_HANDLE;
        }
        if ($resolvedVolumeHandle && (empty($settings->assetVolumeHandle) || !Craft::$app->getVolumes()->getVolumeByHandle($settings->assetVolumeHandle))) {
            $updates['assetVolumeHandle'] = $resolvedVolumeHandle;
        }
        if (!empty($updates)) {
            Plugin::getInstance()->saveSettingsWithoutBootstrap($updates);
        }
        $result['settings_updated'] = array_keys($updates);

        if (!empty($result['template_missing'])) {
            $result['sample_template'] = $this->sampleDeonTemplate();
        }

        return $this->asJson($result);
    }

    /** CKEditor, falls installiert, sonst Redactor, sonst PlainText (multiline) — in dieser Reihenfolge. */
    private function createDeonBodyField(): \craft\base\FieldInterface
    {
        $fieldsService = Craft::$app->getFields();
        if (Craft::$app->getPlugins()->getPlugin('ckeditor') !== null && class_exists('craft\\ckeditor\\Field')) {
            $field = $fieldsService->createField('craft\\ckeditor\\Field');
        } elseif (Craft::$app->getPlugins()->getPlugin('redactor') !== null && class_exists('craft\\redactor\\Field')) {
            $field = $fieldsService->createField('craft\\redactor\\Field');
        } else {
            /** @var PlainText $field */
            $field = $fieldsService->createField(PlainText::class);
            $field->multiline = true;
        }
        $field->name = 'Deon Body';
        $field->handle = self::DEON_BODY_FIELD_HANDLE;
        return $field;
    }

    /** Assets-Feld, auf genau ein bestehendes Volume beschränkt (kein automatisches Volume-Anlegen — hosting-abhängig). */
    private function createDeonImageField(Volume $volume): AssetsField
    {
        /** @var AssetsField $field */
        $field = Craft::$app->getFields()->createField(AssetsField::class);
        $field->name = 'Deon Featured Image';
        $field->handle = self::DEON_IMAGE_FIELD_HANDLE;
        $field->maxRelations = 1;
        $field->restrictLocation = true;
        $field->restrictedLocationSource = 'volume:' . $volume->uid;
        $field->allowSubfolders = true;
        return $field;
    }

    /** Legt eine Section (Channel oder Structure) samt Entry-Type + Field-Layout (Title + Body [+ Bild]) an. */
    private function createDeonSection(string $handle, string $name, string $type, string $uriFormat, \craft\base\FieldInterface $bodyField, ?AssetsField $imageField, string $template): ?Section
    {
        $entryType = new EntryType();
        $entryType->name = $name;
        $entryType->handle = $handle;
        $entryType->hasTitleField = true;

        $elements = [new CustomField($bodyField)];
        if ($imageField) {
            $elements[] = new CustomField($imageField);
        }
        $fieldLayout = new FieldLayout(['type' => Entry::class]);
        $fieldLayout->setTabs([
            ['name' => 'Content', 'elements' => $elements],
        ]);
        $entryType->setFieldLayout($fieldLayout);

        // saveEntryType() und saveSection() in einer Transaktion: schlägt
        // saveSection() fehl (z. B. Uri-Format-Konflikt), bliebe der EntryType sonst
        // als Datenleiche mit dem Handle ("deonBlog"/"deonPages") zurück — ein
        // erneuter setup-blog-Lauf (als idempotent dokumentiert) würde dann an der
        // Handle-Kollision scheitern statt sich selbst zu heilen.
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            if (!$this->sectionsService()->saveEntryType($entryType)) {
                $transaction->rollBack();
                return null;
            }

            $siteSettings = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $siteSettings[] = new Section_SiteSettings([
                    'siteId' => $site->id,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    'uriFormat' => $uriFormat,
                    'template' => $template,
                ]);
            }

            $section = new Section();
            $section->name = $name;
            $section->handle = $handle;
            $section->type = $type;
            $section->setEntryTypes([$entryType]);
            $section->setSiteSettings($siteSettings);

            if (!$this->sectionsService()->saveSection($section)) {
                $transaction->rollBack();
                return null;
            }
            $transaction->commit();
            return $section;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function sectionHasTemplate(Section $section): bool
    {
        foreach ($section->getSiteSettings() as $siteSettings) {
            if (!empty($siteSettings->template)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Setzt das Site-Template einer BESTEHENDEN Section auf unser Artikel-
     * Template — aber nur, wenn es leer ist oder $force (Body-Flag
     * "fix_template") gesetzt wurde. Überschreibt nie stillschweigend eine
     * funktionierende Custom-Konfiguration. Protokolliert die Änderung
     * rollback-fähig (Ziel-Typ "section_template").
     * @return array{0: string, 1: ?string} [outcome ("kept"|"set"|"fixed"), previousTemplate]
     */
    private function ensureSectionTemplate(Section $section, bool $force, string $targetTemplate): array
    {
        $siteSettingsList = $section->getSiteSettings();
        $anyEmpty = false;
        $previousTemplate = null;
        foreach ($siteSettingsList as $s) {
            if (empty($s->template)) {
                $anyEmpty = true;
            } else {
                $previousTemplate = $s->template;
            }
        }

        if ($previousTemplate === $targetTemplate && !$anyEmpty) {
            // Läuft schon über unser eigenes Template — nichts zu tun.
            return ['kept', null];
        }
        if (!$anyEmpty && !$force) {
            return ['kept', null];
        }

        $outcome = $anyEmpty ? 'set' : 'fixed';
        // Nur leere Sites befüllen, außer $force ist gesetzt — sonst würde eine
        // Multi-Site-Section mit gemischtem Zustand (eine Site leer, eine andere
        // mit funktionierendem Custom-Template) die funktionierende Site
        // stillschweigend mit überschreiben, obwohl der Docblock genau das ausschließt.
        foreach ($siteSettingsList as $s) {
            if ($force || empty($s->template)) {
                $s->template = $targetTemplate;
            }
        }
        $section->setSiteSettings($siteSettingsList);
        if (!$this->sectionsService()->saveSection($section)) {
            return ['kept', null];
        }

        // rollback_id wird hier nicht direkt zurückgegeben (setup-blog ändert
        // potenziell zwei Sections in einem Call) — Rollback läuft über
        // /rollback/list wie bei jeder anderen protokollierten Änderung auch.
        $this->logChange(
            'section_template',
            $section->handle,
            ['template' => $previousTemplate],
            ['template' => $targetTemplate],
            'setup-blog: Template ' . $outcome
        );

        return [$outcome, $previousTemplate];
    }

    /**
     * Prüft, ob der (erste) Entry-Type der Section überhaupt einen Titel
     * tragen kann (hasTitleField oder titleFormat) — sonst würde Craft jeden
     * von /entry bzw. /page gesetzten Titel beim Speichern automatisch leeren
     * (siehe checkEntryTypeTitleField()). Repariert nur bei $force
     * (Body-Flag "fix_template", dieselbe Freigabe wie fürs Section-Template)
     * durch Setzen von hasTitleField=true — nie stillschweigend, das würde
     * ggf. ein bewusst gewähltes titleFormat-Schema überschreiben. Bewusst
     * nicht im Rollback-Journal protokolliert (wie die Feld-/Section-Anlage
     * in Schritt 1/2/3 auch): additiv, keine bestehenden Werte gehen verloren.
     * @return string "ok"|"missing"|"fixed"
     */
    private function ensureEntryTypeHasTitleField(Section $section, bool $force): string
    {
        $entryTypes = $section->getEntryTypes();
        $entryType = $entryTypes[0] ?? null;
        if (!$entryType || $entryType->hasTitleField || !empty($entryType->titleFormat)) {
            return 'ok';
        }
        if (!$force) {
            return 'missing';
        }
        $entryType->hasTitleField = true;
        if (!$this->sectionsService()->saveEntryType($entryType)) {
            return 'missing';
        }
        return 'fixed';
    }

    /** Minimales Beispiel-Template als String im Response — wird NICHT selbst ins templates/-Verzeichnis geschrieben. */
    private function sampleDeonTemplate(): string
    {
        return <<<'TWIG'
{% extends "_layout" %}
{% block content %}
    <article>
        <h1>{{ entry.title }}</h1>
        {% if entry.deonFeaturedImage is defined and entry.deonFeaturedImage.one() %}
            <img src="{{ entry.deonFeaturedImage.one().url }}" alt="{{ entry.title }}">
        {% endif %}
        <div>{{ entry.deonBody }}</div>
    </article>
{% endblock %}
TWIG;
    }

    // ─── Feature 2: Navigation ──────────────────────────────────────────────

    private function isVerbbNavigationInstalled(): bool
    {
        return Craft::$app->getPlugins()->getPlugin('navigation') !== null;
    }

    /**
     * POST /deon-ai/nav — generierte Seite in Hauptnav oder Footer verlinken.
     * Body: { target: "main"|"footer", url, title, entry_id? }
     * Strategie-Kaskade, da Craft keine Kern-Navigation hat: verbb/navigation
     * (De-facto-Standard) → Structure-Section mit linkUrl/url-Feld → 422 mit
     * Hinweis zur manuellen Verlinkung (bzw. Upgrade-Tipp auf verbb).
     */
    public function actionNav(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('nav_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $target = (string)($body['target'] ?? '');
        if (!in_array($target, ['main', 'footer'], true)) {
            return $this->asJson(['ok' => false, 'error' => 'target must be "main" or "footer"'])->setStatusCode(400);
        }
        $url = trim((string)($body['url'] ?? ''));
        $title = trim((string)($body['title'] ?? ''));
        $entryId = !empty($body['entry_id']) ? (int)$body['entry_id'] : null;
        if ($title === '' || ($url === '' && $entryId === null)) {
            return $this->asJson(['ok' => false, 'error' => 'title + (url or entry_id) required'])->setStatusCode(400);
        }

        if ($this->isVerbbNavigationInstalled()) {
            return $this->navViaVerbb($target, $url, $title, $entryId);
        }

        $structureResult = $this->navViaStructureSection($url, $title, $entryId);
        if ($structureResult !== null) {
            return $structureResult;
        }

        return $this->asJson([
            'ok' => false,
            'error' => 'nav_not_automatable',
            'hint' => 'Navigation dieser Craft-Site wird im Template gepflegt — Link im CP/Template einfügen. Tipp: Mit dem kostenlosen Plugin "Navigation" (verbb) kann Deon die Navigation automatisch pflegen.',
        ])->setStatusCode(422);
    }

    /**
     * Navigation über das verbb/navigation-Plugin (De-facto-Standard). API
     * gegen den echten Plugin-Quellcode (craft-4/craft-5-Branches) verifiziert.
     */
    private function navViaVerbb(string $target, string $url, string $title, ?int $entryId): Response
    {
        /** @var \verbb\navigation\Navigation $navigation */
        $navigation = \verbb\navigation\Navigation::$plugin;
        $navs = $navigation->getNavs()->getAllNavs();
        if (empty($navs)) {
            return $this->asJson([
                'ok' => false,
                'error' => 'nav_not_found',
                'hint' => 'Im Plugin "Navigation" ist noch keine Navigation angelegt.',
            ])->setStatusCode(422);
        }

        $pattern = $target === 'main' ? '/main|haupt|primary/i' : '/footer|fuss/i';
        $nav = null;
        foreach ($navs as $candidate) {
            if (preg_match($pattern, $candidate->handle) || preg_match($pattern, $candidate->name)) {
                $nav = $candidate;
                break;
            }
        }
        $nav = $nav ?? $navs[0];

        $entry = null;
        if ($entryId !== null) {
            $entry = Entry::find()->id($entryId)->status(null)->one();
            if (!$entry) {
                return $this->asJson(['ok' => false, 'error' => 'entry_not_found'])->setStatusCode(404);
            }
        }

        $site = Craft::$app->getSites()->getPrimarySite();

        // Dedupe über URL (bzw. verlinkte Entry) — kein zweiter Node fürs selbe Ziel.
        $existingNodes = $navigation->getNodes()->getNodesForNav($nav->id, $site->id);
        foreach ($existingNodes as $existing) {
            $isSameTarget = $entry !== null
                ? $existing->elementId === $entry->id
                : $existing->getUrl() === $url;
            if ($isSameTarget) {
                return $this->asJson(['ok' => true, 'via' => 'verbb', 'nav' => ['handle' => $nav->handle], 'node_id' => $existing->id, 'deduped' => true]);
            }
        }

        $node = new \verbb\navigation\elements\Node();
        $node->navId = $nav->id;
        $node->siteId = $site->id;
        $node->title = $title;
        if ($entry !== null) {
            $node->type = Entry::class;
            $node->elementId = $entry->id;
            $node->setElement($entry);
        } else {
            $node->type = \verbb\navigation\nodetypes\CustomType::class;
            $node->setUrl($url);
        }

        try {
            $saved = Craft::$app->getElements()->saveElement($node);
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'error' => 'nav_save_failed', 'message' => $e->getMessage()])->setStatusCode(500);
        }
        if (!$saved) {
            return $this->asJson(['ok' => false, 'error' => 'nav_save_failed', 'details' => $node->getErrors()])->setStatusCode(500);
        }

        return $this->asJson(['ok' => true, 'via' => 'verbb', 'nav' => ['handle' => $nav->handle], 'node_id' => $node->id]);
    }

    /**
     * Fallback ohne verbb/navigation: manche Sites bauen ihre Nav aus einer
     * Structure-Section mit Handle ~ /nav|menu/. Nur automatisierbar, wenn
     * deren Entry-Type tatsächlich ein Feld "linkUrl" oder "url" hat — sonst
     * NICHT raten (gibt null zurück, Aufrufer fällt auf 422 zurück).
     */
    private function navViaStructureSection(string $url, string $title, ?int $entryId): ?Response
    {
        $navSection = null;
        foreach ($this->sectionsService()->getAllSections() as $section) {
            if ($section->type === Section::TYPE_STRUCTURE && preg_match('/nav|menu/i', $section->handle)) {
                $navSection = $section;
                break;
            }
        }
        if (!$navSection) {
            return null;
        }

        $entryTypes = $navSection->getEntryTypes();
        if (empty($entryTypes)) {
            return null;
        }
        $entryType = $entryTypes[0];
        $linkFieldHandle = null;
        foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
            if (in_array($field->handle, ['linkUrl', 'url'], true)) {
                $linkFieldHandle = $field->handle;
                break;
            }
        }
        if ($linkFieldHandle === null) {
            return null;
        }

        $entry = new Entry();
        $entry->sectionId = $navSection->id;
        $entry->typeId = $entryType->id;
        $entry->title = $title;
        $entry->enabled = true;
        try {
            $entry->setFieldValue($linkFieldHandle, $url);
        } catch (\Throwable $e) {
            return null;
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return $this->asJson(['ok' => false, 'error' => 'nav_save_failed', 'details' => $entry->getErrors()])->setStatusCode(500);
        }

        return $this->asJson(['ok' => true, 'via' => 'structure', 'section' => $navSection->handle, 'entry_id' => $entry->id]);
    }

    // ─── v0.8.0: Seiten-Anbindung (Contract-Parität zu aideon-connect/WP) ───

    /**
     * GET /deon-ai/match-url?url=… — Entry per URL finden.
     * Response-Shape wie WP /match-url: { matched, id, title, slug, type,
     * status, link, modified } bzw. { matched: false, searched_url, message }.
     */
    public function actionMatchUrl(): Response
    {
        $this->requireDeonKey();
        $url = trim((string)Craft::$app->getRequest()->getQueryParam('url'));
        if ($url === '') {
            return $this->asJson(['ok' => false, 'error' => 'url parameter required'])->setStatusCode(400);
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $uri = trim((string)$path, '/');
        $entry = Entry::find()->uri($uri === '' ? '__home__' : $uri)->status(null)->one();
        if (!$entry && $uri !== '') {
            // Fallback: letztes Pfadsegment als Slug (analog WP get_page_by_path-Fallback)
            $entry = Entry::find()->slug(basename($uri))->status(null)->one();
        }

        if (!$entry) {
            return $this->asJson([
                'matched' => false,
                'searched_url' => $url,
                'message' => 'Keine Seite gefunden — versuche manuelle Auswahl aus /pages',
            ]);
        }
        return $this->asJson($this->entrySummary($entry) + ['matched' => true]);
    }

    /**
     * GET /deon-ai/pages?per_page=… — Entries ALLER Sections (WP-/pages-Shape),
     * nach Änderungsdatum absteigend. Ergänzt /entries (eine Section pro Aufruf).
     */
    public function actionPages(): Response
    {
        $this->requireDeonKey();
        $perPage = min((int)(Craft::$app->getRequest()->getQueryParam('per_page') ?: 100), 200);
        $entries = Entry::find()
            ->status(null)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($perPage)
            ->all();
        return $this->asJson(array_map(fn(Entry $e) => $this->entrySummary($e), $entries));
    }

    /**
     * GET /deon-ai/media?per_page= — Bild-Bibliothek der Site (alle Volumes),
     * für Bild-Matching in generierten Sektionen. Pendant zu WP
     * wp-json/wp/v2/media?media_type=image. Read-only, nicht consent-gated
     * (wie /pages, /entries).
     */
    public function actionMedia(): Response
    {
        $this->requireDeonKey();
        $perPage = min((int)(Craft::$app->getRequest()->getQueryParam('per_page') ?: 100), 200);
        $assets = Asset::find()
            ->kind('image')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($perPage)
            ->all();
        $items = array_map(static function (Asset $asset) {
            return [
                'url' => $asset->getUrl(),
                'alt' => (string)($asset->alt ?? ''),
                'title' => (string)$asset->title,
                'filename' => (string)$asset->filename,
                'w' => $asset->getWidth(),
                'h' => $asset->getHeight(),
            ];
        }, $assets);
        return $this->asJson(['items' => $items]);
    }

    /**
     * GET /deon-ai/site-inventory — Überblick über ALLE Sections der Site
     * (Handle, Typ, Name, Entry-Anzahl). Grundlage für /deon-ai/entry-sections:
     * der Worker muss Section-Handles einer Kunden-Site nicht raten oder
     * vorher kennen, um z. B. eine bestehende Leistungsseite als Vorbild für
     * eine neue zu finden. Read-only, nicht consent-gated (wie /pages, /media).
     */
    public function actionSiteInventory(): Response
    {
        $this->requireDeonKey();
        $sections = [];
        foreach ($this->sectionsService()->getAllSections() as $section) {
            $sections[] = [
                'handle' => $section->handle,
                'type' => $section->type,
                'name' => $section->name,
                'entry_count' => (int)Entry::find()->sectionId($section->id)->status(null)->count(),
            ];
        }
        return $this->asJson(['ok' => true, 'sections' => $sections]);
    }

    /**
     * GET /deon-ai/entry-sections/<id> — strukturierte Block-/Sektionsinhalte
     * eines beliebigen Entries: native Matrix-Felder (Craft 5: verschachtelte
     * Entries, Craft 4: MatrixBlocks) sowie Neo-Felder (spicyweb/craft-neo,
     * falls installiert), rekursiv aufgelöst. Anders als /page-structure (das
     * nur das eine deonBody-HTML-Feld kennt) funktioniert das für JEDE Section/
     * jeden Entry-Type der Site — echte, handgebaute Seiten bestehen i. d. R.
     * aus benannten Block-Typen (Hero, FAQ, CTA, …), nicht aus einem HTML-Blob.
     * Feature-detected über die tatsächliche Feld-Klasse, nicht über
     * Feld-/Block-Namen — jede Kunden-Site kann eigene Handles verwenden.
     * Hat der Entry-Type kein Matrix-/Neo-Feld (heutiger Fall bei Deons
     * eigenen Blog-/Standortseiten), greift legacy_body_fallback (identisch
     * zu /page-structure), damit nichts kaputt geht, was heute schon läuft.
     * Read-only, nicht consent-gated (wie /page-structure).
     */
    public function actionEntrySections(int $id): Response
    {
        $this->requireDeonKey();
        $entry = Entry::find()->id($id)->status(null)->one();
        if (!$entry) {
            return $this->asJson(['ok' => false, 'error' => 'not_found'])->setStatusCode(404);
        }

        $budget = ['count' => 0];
        $sections = $this->resolveEntrySections($entry, 0, $budget);

        $section = $entry->getSection();
        $data = [
            'ok' => true,
            'entry_id' => $entry->id,
            'section_handle' => $section?->handle,
            'entry_type' => $entry->getType()->handle,
            'sections' => $sections,
            'legacy_body_fallback' => null,
        ];

        if (empty($sections)) {
            [$bodyHandle, $bodyHtml] = $this->resolveEntryBody($entry);
            if ($bodyHandle !== null && trim($bodyHtml) !== '') {
                $blocks = $this->contentBlocks($bodyHtml);
                $out = [];
                foreach ($blocks as $i => $block) {
                    $text = trim(strip_tags($block['inner']));
                    if ($text === '') {
                        continue;
                    }
                    $out[] = ['id' => 'pc-' . $i, 'kind' => $block['kind'], 'text' => $text];
                }
                $data['legacy_body_fallback'] = ['body_field' => $bodyHandle, 'content_blocks' => $out];
            }
        }

        return $this->asJson($data);
    }

    /**
     * GET /deon-ai/page-structure/<id> — kompletter Seiteninhalt zum Analysieren/
     * Nachbauen: Titel, Slug, Body-HTML, SEO-Override der URI sowie walkbare
     * Text-Blöcke (id "pc-N", Contract identisch zum WP-Plugin: h1–h3 = "title",
     * p = "editor") für die builder-agnostische Standortseiten-Texturierung.
     */
    public function actionPageStructure(int $id): Response
    {
        $this->requireDeonKey();
        $entry = Entry::find()->id($id)->status(null)->one();
        if (!$entry) {
            return $this->asJson(['ok' => false, 'error' => 'not_found'])->setStatusCode(404);
        }

        [$bodyHandle, $bodyHtml] = $this->resolveEntryBody($entry);

        $data = $this->entrySummary($entry);
        $data['content'] = $bodyHtml;
        $data['body_field'] = $bodyHandle;

        $uri = '/' . ltrim((string)($entry->uri === '__home__' ? '' : $entry->uri), '/');
        $override = (new \craft\db\Query())->from('{{%deonai_seo_overrides}}')->where(['uri' => $uri])->one();
        if ($override) {
            $data['seo'] = [
                'title' => $override['title'],
                'description' => $override['metaDescription'],
                'canonical' => $override['canonical'],
                'robots_noindex' => '',
            ];
        }

        $blocks = $this->contentBlocks($bodyHtml);
        if (!empty($blocks)) {
            $out = [];
            foreach ($blocks as $i => $block) {
                $text = trim(strip_tags($block['inner']));
                if ($text === '') {
                    continue;
                }
                $out[] = ['id' => 'pc-' . $i, 'kind' => $block['kind'], 'text' => $text];
            }
            if (!empty($out)) {
                $data['content_blocks'] = $out;
                $data['content_builder'] = 'html';
            }
        }

        return $this->asJson($data);
    }

    /**
     * POST /deon-ai/set-widget-texts — Text-Sets per Block-ID "pc-N" auf den
     * Entry-Body anwenden (N-ter h1–h3- bzw. p-Block, Reihenfolge identisch zu
     * /page-structure). Body: { post_id|entry_id, texts: [{id, title?|editor?}], body_field? }
     * Contract = WP-Plugin aideon_apply_content_texts (html-Modus).
     */
    public function actionSetWidgetTexts(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('content_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $entryId = (int)($body['post_id'] ?? $body['entry_id'] ?? 0);
        $texts = is_array($body['texts'] ?? null) ? $body['texts'] : [];
        if (!$entryId) {
            return $this->asJson(['ok' => false, 'error' => 'post_id fehlt/unbekannt'])->setStatusCode(404);
        }
        if (empty($texts)) {
            return $this->asJson(['ok' => false, 'error' => 'texts[] erforderlich'])->setStatusCode(400);
        }
        $entry = Entry::find()->id($entryId)->status(null)->one();
        if (!$entry) {
            return $this->asJson(['ok' => false, 'error' => 'post_id fehlt/unbekannt'])->setStatusCode(404);
        }

        [$bodyHandle, $content] = $this->resolveEntryBody($entry, !empty($body['body_field']) ? (string)$body['body_field'] : null);
        if ($bodyHandle === null) {
            return $this->asJson(['ok' => false, 'error' => 'body_field_not_found'])->setStatusCode(422);
        }

        $beforeState = [
            'title' => $entry->title, 'slug' => $entry->slug, 'enabled' => $entry->enabled,
            'bodyFieldHandle' => $bodyHandle, 'bodyValue' => $content,
        ];

        $blocks = $this->contentBlocks($content);
        $map = [];
        foreach ($texts as $t) {
            if (is_array($t) && !empty($t['id']) && str_starts_with((string)$t['id'], 'pc-')) {
                $map[(int)substr((string)$t['id'], 3)] = $t;
            }
        }

        $applied = [];
        // Absteigend anwenden, damit die vorherigen pos-Offsets gültig bleiben.
        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            if (!isset($map[$i])) {
                continue;
            }
            $block = $blocks[$i];
            $t = $map[$i];
            $newInner = null;
            if ($block['kind'] === 'title' && isset($t['title']) && is_string($t['title'])) {
                $newInner = htmlspecialchars(trim(strip_tags($t['title'])), ENT_QUOTES);
            } elseif ($block['kind'] === 'editor' && isset($t['editor']) && is_string($t['editor'])) {
                $newInner = $t['editor'];
            }
            if ($newInner === null || $newInner === '') {
                continue;
            }
            $innerPos = strpos($block['full'], $block['inner']);
            if ($innerPos === false) {
                continue;
            }
            $newFull = substr_replace($block['full'], $newInner, $innerPos, strlen($block['inner']));
            $content = substr_replace($content, $newFull, $block['pos'], strlen($block['full']));
            $applied[] = 'pc-' . $i;
        }

        if (!empty($applied)) {
            try {
                $entry->setFieldValue($bodyHandle, $content);
            } catch (\Throwable $e) {
                return $this->asJson(['ok' => false, 'error' => 'body_field_not_found'])->setStatusCode(422);
            }
            if (!Craft::$app->getElements()->saveElement($entry)) {
                return $this->asJson(['ok' => false, 'error' => 'save_failed', 'details' => $entry->getErrors()])->setStatusCode(500);
            }
            $afterState = $beforeState;
            $afterState['bodyValue'] = $content;
            $this->logChange('entry', (string)$entry->id, $beforeState, $afterState, 'set-widget-texts');
        }

        return $this->asJson([
            'success' => true,
            'applied' => $applied,
            'count' => count($applied),
            'mode' => 'html',
            'plugin_version' => Plugin::getInstance()->getVersion(),
        ]);
    }

    /**
     * POST /deon-ai/duplicate-page — 1:1-Klon einer Seite mit Textaustausch
     * (Standortseiten im Original-Design). Contract = WP /duplicate-page:
     * Body: { source_post_id?|source_page_url?, title, slug?, replacements:
     * [{find, replace}], h1_override?, status?, page_id?, meta_description?,
     * seo_title?, schema_json? }. page_id = idempotentes Re-Run (Update).
     */
    public function actionDuplicatePage(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('page_create')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $source = null;
        if (!empty($body['source_post_id'])) {
            $source = Entry::find()->id((int)$body['source_post_id'])->status(null)->one();
        } elseif (!empty($body['source_page_url'])) {
            $path = trim((string)(parse_url((string)$body['source_page_url'], PHP_URL_PATH) ?? ''), '/');
            $source = Entry::find()->uri($path === '' ? '__home__' : $path)->status(null)->one();
        }
        if (!$source) {
            return $this->asJson(['ok' => false, 'error' => 'not_found', 'message' => 'Quellseite nicht gefunden'])->setStatusCode(404);
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return $this->asJson(['ok' => false, 'error' => 'title erforderlich'])->setStatusCode(400);
        }
        $slug = trim((string)($body['slug'] ?? ''));
        $status = (string)($body['status'] ?? 'draft');
        $replacements = [];
        foreach ((is_array($body['replacements'] ?? null) ? $body['replacements'] : []) as $r) {
            if (is_array($r) && isset($r['find']) && $r['find'] !== '' && isset($r['replace'])) {
                $replacements[] = ['find' => (string)$r['find'], 'replace' => (string)$r['replace']];
            }
        }
        $h1Override = trim((string)($body['h1_override'] ?? ''));

        [$bodyHandle, $sourceHtml] = $this->resolveEntryBody($source);

        // Textaustausch auf Body-HTML (alle find/replace-Paare)
        $newHtml = $sourceHtml;
        foreach ($replacements as $r) {
            $newHtml = str_replace($r['find'], $r['replace'], $newHtml);
        }
        // h1_override: inneren Text des ERSTEN h1–h3 ersetzen (wie WP: erstes Heading-Widget)
        if ($h1Override !== '') {
            $newHtml = preg_replace(
                '~(<h([1-3])\b[^>]*>).*?(</h\2>)~is',
                '$1' . str_replace(['\\', '$'], ['\\\\', '\$'], htmlspecialchars($h1Override, ENT_QUOTES)) . '$3',
                $newHtml,
                1
            );
        }

        $existing = null;
        if (!empty($body['page_id'])) {
            $existing = Entry::find()->id((int)$body['page_id'])->status(null)->one();
        }

        $beforeState = null;
        try {
            if ($existing) {
                // Idempotentes Re-Run: bestehenden Klon aktualisieren statt neu anlegen
                $entry = $existing;
                $beforeState = [
                    'title' => $entry->title, 'slug' => $entry->slug, 'enabled' => $entry->enabled,
                    'bodyFieldHandle' => $bodyHandle, 'bodyValue' => $bodyHandle !== null ? $this->safeFieldString($entry, $bodyHandle) : '',
                ];
            } else {
                $entry = Craft::$app->getElements()->duplicateElement($source, [
                    'title' => mb_substr($title, 0, 255),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'error' => 'duplicate_failed', 'message' => $e->getMessage()])->setStatusCode(500);
        }

        $entry->title = mb_substr($title, 0, 255);
        $entry->slug = mb_substr($slug !== '' ? $slug : \craft\helpers\ElementHelper::generateSlug($title), 0, 200);
        $entry->enabled = ($status === 'publish' || $status === 'live');
        if ($bodyHandle !== null) {
            try {
                $entry->setFieldValue($bodyHandle, $newHtml);
            } catch (\Throwable $e) {
                // Feld existiert im Ziel nicht mehr — Klon trotzdem speichern.
            }
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return $this->asJson(['ok' => false, 'error' => 'save_failed', 'details' => $entry->getErrors()])->setStatusCode(500);
        }

        // SEO-Metas als Override auf die neue URI (unser natives Pendant zu Yoast-Postmeta)
        if ($entry->uri && (!empty($body['meta_description']) || !empty($body['seo_title']) || !empty($body['schema_json']))) {
            $this->upsertSeoOverrideRow('/' . ltrim($entry->uri === '__home__' ? '' : $entry->uri, '/'), [
                'title' => !empty($body['seo_title']) ? mb_substr((string)$body['seo_title'], 0, 200) : null,
                'metaDescription' => !empty($body['meta_description']) ? mb_substr((string)$body['meta_description'], 0, 300) : null,
                'schemaJson' => !empty($body['schema_json']) ? json_encode($body['schema_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);
        }

        $changeId = $this->logChange('entry', (string)$entry->id, $beforeState, [
            'title' => $entry->title, 'slug' => $entry->slug, 'enabled' => $entry->enabled,
            'bodyFieldHandle' => $bodyHandle, 'bodyValue' => $bodyHandle !== null ? $this->safeFieldString($entry, $bodyHandle) : '',
        ], 'duplicate-page von #' . $source->id);

        return $this->asJson([
            'success' => true,
            'post_id' => $entry->id,
            'source_post_id' => $source->id,
            'builder' => 'craft',
            'page_url' => $entry->getUrl(),
            'status' => $entry->enabled ? 'publish' : 'draft',
            'rollback_id' => 'rb_' . $changeId,
            'plugin_version' => Plugin::getInstance()->getVersion(),
        ]);
    }

    /**
     * GET /deon-ai/render-preview?post_id|url&token — gerendertes Frontend-HTML
     * für die Dashboard-Preview. Token-Format identisch zum WP-Plugin:
     * base64url(hmac_sha256("<url>:<exp>", apiKey)) . "." . exp (60s-TTL,
     * max. 10 min in der Zukunft). Nur Same-Origin-URLs.
     */
    public function actionRenderPreview(): Response
    {
        $this->requireDeonKey();
        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getQueryParam('post_id');
        $urlParam = trim((string)$request->getQueryParam('url'));
        $token = (string)$request->getQueryParam('token');

        $entry = $entryId ? Entry::find()->id($entryId)->status(null)->one() : null;
        if (!$entry && $urlParam === '') {
            return $this->asJson(['ok' => false, 'error' => 'post_id or url required'])->setStatusCode(400);
        }
        $url = $entry ? (string)$entry->getUrl() : $urlParam;
        if ($url === '') {
            return $this->asJson(['ok' => false, 'error' => 'entry_has_no_url'])->setStatusCode(422);
        }

        // Same-Origin-Guard: nur der eigene Site-Host
        $ownHost = strtolower((string)parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST));
        $urlHost = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($ownHost === '' || $urlHost === '' || preg_replace('/^www\./', '', $urlHost) !== preg_replace('/^www\./', '', $ownHost)) {
            return $this->asJson(['ok' => false, 'error' => 'ssrf_blocked', 'message' => 'Nur Same-Origin-URLs erlaubt'])->setStatusCode(403);
        }

        if (!$this->verifyPreviewToken($token, $url)) {
            return $this->asJson(['ok' => false, 'error' => 'invalid_token', 'message' => 'Preview-Token fehlt oder ist abgelaufen (60s TTL). Worker muss neues Token erzeugen.'])->setStatusCode(401);
        }

        $html = '';
        try {
            $client = Craft::createGuzzleClient(['timeout' => 15]);
            $fetched = $client->request('GET', $url, [
                'http_errors' => false,
                'headers' => ['User-Agent' => 'DeonAiConnect/' . Plugin::getInstance()->getVersion() . ' (preview)'],
            ]);
            if ($fetched->getStatusCode() < 400) {
                $html = (string)$fetched->getBody();
            }
        } catch (\Throwable $e) {
            // Fallback unten
        }
        if (strlen($html) < 200 && $entry) {
            // Fallback: Body-Feld ohne Theme-Wrap (analog aideon_render_post_internal)
            [, $bodyHtml] = $this->resolveEntryBody($entry);
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars((string)$entry->title, ENT_QUOTES) . '</title></head><body>' . $bodyHtml . '</body></html>';
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' https://audit.deon-ai.de https://deon-ai.de");
        $response->data = $html;
        return $response;
    }

    /**
     * POST /deon-ai/publish-lp — Full-Page-Landingpage aus Roh-HTML (inkl.
     * <style>/<script>), ausgeliefert über eine eigene Route pro Slug.
     * Contract = WP /publish-lp; "chrome" wird gespeichert, gerendert wird
     * immer das Roh-HTML als komplettes Dokument (Craft hat kein Theme, in
     * das sich "bare" einbetten ließe — im Response dokumentiert).
     */
    public function actionPublishLp(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('page_create')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $title = trim((string)($body['title'] ?? ''));
        $rawHtml = is_string($body['html'] ?? null) ? $body['html'] : '';
        if ($title === '' || strlen($rawHtml) < 20) {
            return $this->asJson(['ok' => false, 'error' => 'title und html (min. 20 Zeichen) erforderlich'])->setStatusCode(400);
        }
        $chrome = in_array($body['chrome'] ?? 'full', ['full', 'bare'], true) ? (string)$body['chrome'] : 'full';
        $status = (string)($body['status'] ?? 'draft');
        $enabled = ($status === 'publish' || $status === 'live');
        $slug = trim((string)($body['slug'] ?? ''), '/');
        if ($slug === '') {
            $slug = \craft\helpers\ElementHelper::generateSlug($title);
        }
        $slug = mb_substr(strtolower($slug), 0, 200);

        // Sicherheitskritisch: $slug wird in Plugin::init() 1:1 als Array-Key von
        // $event->rules verwendet — das ist eine Yii-UrlManager-Rule, deren Key-Syntax
        // Platzhalter wie "<id:\d+>" unterstützt (siehe die Kern-Routen weiter oben in
        // derselben Datei). Ungefilterte Zeichen wären also nicht nur eine simple
        // Kollisionsgefahr, sondern potenzielles Routing-Pattern-Injection. Deshalb
        // strikt auf ein sicheres Segment-Alphabet beschränken, BEVOR die Reserviert-
        // Prüfung greift.
        if (!preg_match('/^[a-z0-9]+(?:[\/-][a-z0-9]+)*$/', $slug)) {
            return $this->asJson([
                'ok' => false,
                'error' => 'slug_invalid',
                'hint' => 'Slug darf nur Kleinbuchstaben, Ziffern, "-" und "/" enthalten.',
            ])->setStatusCode(422);
        }
        // Reserviert: ALLE Plugin-eigenen Routen leben unter "deon-ai/" (siehe
        // Plugin::init()), robots.txt/llms.txt sind zwei weitere feste Top-Level-
        // Routen. Die LP-Routen werden in Plugin::init() NACH den Kern-Routen in
        // derselben $event->rules-Array-Instanz registriert — PHP überschreibt
        // Array-Keys bei Kollision —, ein Slug wie "deon-ai/self-update" würde also
        // sonst die gleichnamige Kern-Route kapern.
        if ($slug === 'deon-ai' || str_starts_with($slug, 'deon-ai/') || $slug === 'robots.txt' || $slug === 'llms.txt') {
            return $this->asJson([
                'ok' => false,
                'error' => 'slug_reserved',
                'hint' => 'Der Slug "' . $slug . '" ist für interne Deon-AI-Routen reserviert.',
            ])->setStatusCode(422);
        }
        // Kollision mit einer bestehenden, echten Craft-Seite vermeiden — Site-URL-
        // Rules (inkl. dieser LP-Route) greifen vor der regulären Element-URI-Auflösung.
        if (Entry::find()->uri($slug)->status(null)->exists()) {
            return $this->asJson([
                'ok' => false,
                'error' => 'slug_collides_with_entry',
                'hint' => 'Unter "/' . $slug . '" existiert bereits ein Craft-Entry.',
            ])->setStatusCode(422);
        }

        $db = Craft::$app->getDb();
        $table = '{{%deonai_landing_pages}}';
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $existing = null;
        if (!empty($body['page_id'])) {
            $existing = (new \craft\db\Query())->from($table)->where(['id' => (int)$body['page_id']])->one();
        }
        if (!$existing) {
            $existing = (new \craft\db\Query())->from($table)->where(['slug' => $slug])->one();
        }

        try {
            if ($existing) {
                $this->backupContent('lp:' . $existing['slug'], (string)$existing['html']);
                $db->createCommand()->update($table, [
                    'slug' => $slug, 'title' => mb_substr($title, 0, 255), 'html' => $rawHtml,
                    'chrome' => $chrome, 'enabled' => $enabled, 'dateUpdated' => $now,
                ], ['id' => $existing['id']])->execute();
                $lpId = (int)$existing['id'];
            } else {
                $db->createCommand()->insert($table, [
                    'slug' => $slug, 'title' => mb_substr($title, 0, 255), 'html' => $rawHtml,
                    'chrome' => $chrome, 'enabled' => $enabled,
                    'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
                ])->execute();
                $lpId = (int)$db->getLastInsertID();
            }
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'error' => 'lp_save_failed', 'message' => $e->getMessage(), 'hint' => 'Migration gelaufen? (deonai_landing_pages)'])->setStatusCode(500);
        }

        $baseUrl = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
        return $this->asJson([
            'success' => true,
            'post_id' => $lpId,
            'page_url' => $baseUrl . '/' . $slug,
            'status' => $enabled ? 'publish' : 'draft',
            'chrome' => $chrome,
            'render_mode' => 'fullpage_raw',
            'plugin_version' => Plugin::getInstance()->getVersion(),
        ]);
    }

    /** Liefert eine Landingpage aus (Site-Route pro Slug, siehe Plugin::init()). */
    public function actionRenderLp(string $slug): Response
    {
        $row = (new \craft\db\Query())
            ->from('{{%deonai_landing_pages}}')
            ->where(['slug' => $slug, 'enabled' => true])
            ->one();
        if (!$row) {
            throw new NotFoundHttpException();
        }
        $html = (string)$row['html'];
        if (stripos($html, '<html') === false) {
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars((string)$row['title'], ENT_QUOTES) . '</title><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>' . $html . '</body></html>';
        }
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->data = $html;
        return $response;
    }

    /**
     * GET /deon-ai/theme-tokens — Design-Tokens der Site. Craft hat kein
     * theme.json (WP-Weg: deklarierte Tokens auslesen) — stattdessen werden
     * die Tokens aus dem CSS der gerenderten Startseite extrahiert: <style>-
     * Blöcke + Same-Origin-Stylesheets, :root-Custom-Properties werden eine
     * Ebene aufgelöst. Response-Shape wie WP (source: "css_extract").
     */
    public function actionThemeTokens(): Response
    {
        $this->requireDeonKey();
        $out = ['is_block_theme' => false, 'palette' => [], 'source' => 'none'];

        $css = $this->collectSiteCss();
        if ($css !== '') {
            $out['source'] = 'css_extract';

            // :root-Custom-Properties einsammeln (eine Auflösungs-Ebene für var(--x))
            $vars = [];
            if (preg_match_all('/--([\w-]+)\s*:\s*([^;}]+)[;}]/', $css, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $vars[strtolower($m[1])] = trim($m[2]);
                }
            }
            $resolve = static function (string $value) use ($vars): string {
                $value = trim($value);
                if (preg_match('/var\(\s*--([\w-]+)\s*(?:,\s*([^)]+))?\)/i', $value, $m)) {
                    $value = trim($vars[strtolower($m[1])] ?? ($m[2] ?? ''));
                }
                return $value;
            };
            $isColor = static fn(string $v): bool => (bool)preg_match('/^#[0-9a-f]{3,8}$/i', $v) || stripos($v, 'rgb') === 0 || stripos($v, 'hsl') === 0;

            // body-Regel: Hintergrund, Textfarbe, Body-Font
            if (preg_match('/(?:^|[}\s,])body\s*(?:,[^{]*)?\{([^}]*)\}/is', $css, $m)) {
                $decl = $m[1];
                if (preg_match('/background(?:-color)?\s*:\s*([^;}]+)/i', $decl, $mm) && $isColor($v = $resolve($mm[1]))) {
                    $out['color_bg'] = $v;
                }
                if (preg_match('/(?<![\w-])color\s*:\s*([^;}]+)/i', $decl, $mm) && $isColor($v = $resolve($mm[1]))) {
                    $out['color_text'] = $v;
                }
                if (preg_match('/font-family\s*:\s*([^;}]+)/i', $decl, $mm) && ($v = $resolve($mm[1])) !== '') {
                    $out['font_body'] = $v;
                }
            }
            // Heading-Font (h1/h2/h3-Regeln)
            if (preg_match('/h[1-3][^{]*\{[^}]*font-family\s*:\s*([^;}]+)/is', $css, $m) && ($v = $resolve($m[1])) !== '') {
                $out['font_heading'] = $v;
            }
            // Button: Akzentfarbe + Radius
            if (preg_match('/(?:button|\.btn|\.button)[^{]*\{([^}]*)\}/is', $css, $m)) {
                $decl = $m[1];
                if (preg_match('/background(?:-color)?\s*:\s*([^;}]+)/i', $decl, $mm) && $isColor($v = $resolve($mm[1]))) {
                    $out['color_accent'] = $v;
                }
                if (preg_match('/border-radius\s*:\s*([^;}]+)/i', $decl, $mm) && ($v = $resolve($mm[1])) !== '') {
                    $out['radius'] = $v;
                }
            }
            // Akzent-Fallback: Link-Farbe
            if (empty($out['color_accent']) && preg_match('/(?:^|[}\s,])a\s*(?:,[^{]*)?\{[^}]*(?<![\w-])color\s*:\s*([^;}]+)/is', $css, $m) && $isColor($v = $resolve($m[1]))) {
                $out['color_accent'] = $v;
            }

            // Palette: häufigste Hex-Farben im CSS (Worker-Normalizer wählt selbst)
            if (preg_match_all('/#[0-9a-f]{6}\b|#[0-9a-f]{3}\b/i', $css, $m)) {
                $counts = array_count_values(array_map('strtolower', $m[0]));
                arsort($counts);
                $i = 1;
                foreach (array_slice(array_keys($counts), 0, 8) as $color) {
                    $out['palette'][] = ['slug' => 'c' . $i++, 'color' => $color];
                }
            }
        }

        $out['plugin_version'] = Plugin::getInstance()->getVersion();
        return $this->asJson($out);
    }

    /**
     * POST /deon-ai/site-schema — Site-weites JSON-LD (Organization/
     * LocalBusiness/WebSite), ausgespielt im <head> aller Seiten.
     * Body: { schemas: [ {...}, ... ] } (max. 10). Contract = WP /site-schema.
     */
    public function actionSiteSchema(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('seo_meta')) {
            return $response;
        }
        $this->requirePostRequest();
        $schemas = Craft::$app->getRequest()->getBodyParam('schemas');
        if (!is_array($schemas)) {
            return $this->asJson(['ok' => false, 'error' => 'Feld "schemas" muss ein Array von JSON-LD-Objekten sein.'])->setStatusCode(400);
        }
        $clean = [];
        foreach (array_slice($schemas, 0, 10) as $schema) {
            if (is_array($schema)) {
                $clean[] = $schema;
            }
        }

        $db = Craft::$app->getDb();
        $table = '{{%deonai_seo_hygiene}}';
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $existing = (new \craft\db\Query())->from($table)->where(['type' => 'site_schema'])->one();
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $this->backupContent('site_schema', (string)$existing['content']);
            $db->createCommand()->update($table, ['content' => $json, 'dateUpdated' => $now], ['id' => $existing['id']])->execute();
        } else {
            $db->createCommand()->insert($table, [
                'type' => 'site_schema', 'content' => $json,
                'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
            ])->execute();
        }

        return $this->asJson(['ok' => true, 'count' => count($clean)]);
    }

    /** GET /deon-ai/sitemap-discover — wahrscheinlichste Sitemap-URL (Contract = WP /seo/sitemap/discover). */
    public function actionSitemapDiscover(): Response
    {
        $this->requireDeonKey();
        $base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/') . '/';
        // Craft-Core hat keine Sitemap; SEOmatic (De-facto-Standard) liefert /sitemaps-1-sitemap.xml + sitemap.xml-Redirect.
        $candidates = [$base . 'sitemap.xml', $base . 'sitemaps-1-sitemap.xml', $base . 'sitemap_index.xml'];
        return $this->asJson(['ok' => true, 'sitemap_url' => $candidates[0], 'candidates' => $candidates]);
    }

    /**
     * GET|POST /deon-ai/footer-links — Plugin-eigener Footer-Block
     * ("Servicegebiete"), gerendert vor </body> auf allen Seiten.
     * POST-Body: { heading?, links: [{page_id, label}], merge? } — Contract = WP.
     */
    public function actionFooterLinks(): Response
    {
        $this->requireDeonKey();
        $table = '{{%deonai_seo_hygiene}}';
        $existing = (new \craft\db\Query())->from($table)->where(['type' => 'footer_links'])->one();
        $current = $existing ? (json_decode((string)$existing['content'], true) ?: []) : [];

        if (Craft::$app->getRequest()->getIsGet()) {
            return $this->asJson($current ?: ['heading' => '', 'links' => []]);
        }

        if ($response = $this->checkPermission('nav_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $merge = !empty($body['merge']);
        $heading = trim((string)($body['heading'] ?? ''));
        $in = is_array($body['links'] ?? null) ? $body['links'] : [];
        $links = [];
        if ($merge && !empty($current['links']) && is_array($current['links'])) {
            foreach ($current['links'] as $l) {
                if (is_array($l) && !empty($l['page_id'])) {
                    $links[(int)$l['page_id']] = ['page_id' => (int)$l['page_id'], 'label' => (string)($l['label'] ?? '')];
                }
            }
        }
        foreach ($in as $l) {
            if (!is_array($l)) {
                continue;
            }
            $pid = (int)($l['page_id'] ?? $l['entry_id'] ?? 0);
            $label = trim((string)($l['label'] ?? ''));
            if ($pid > 0 && $label !== '' && Entry::find()->id($pid)->status(null)->exists()) {
                $links[$pid] = ['page_id' => $pid, 'label' => $label];
            }
        }
        $links = array_slice(array_values($links), 0, 20);
        $data = [
            'heading' => $heading !== '' ? $heading : (!empty($current['heading']) ? (string)$current['heading'] : 'Servicegebiete'),
            'links' => $links,
        ];

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($existing) {
            $db->createCommand()->update($table, ['content' => $json, 'dateUpdated' => $now], ['id' => $existing['id']])->execute();
        } else {
            $db->createCommand()->insert($table, [
                'type' => 'footer_links', 'content' => $json,
                'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
            ])->execute();
        }

        return $this->asJson(['success' => true, 'count' => count($links), 'heading' => $data['heading'], 'plugin_version' => Plugin::getInstance()->getVersion()]);
    }

    // ─── v0.8.0-Helfer ──────────────────────────────────────────────────────

    /** Einheitliches Entry-Summary im WP-/pages-Shape. */
    private function entrySummary(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'type' => $entry->getSection()?->handle ?? '',
            'status' => $entry->getStatus(),
            'link' => $entry->getUrl(),
            'modified' => $entry->dateUpdated?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Body-Feld eines Entries auflösen: expliziter Request-Param > Plugin-
     * Setting > "deonBody"-Bootstrap-Feld. Gibt [handle|null, html] zurück —
     * handle null, wenn keines der Felder am Entry existiert.
     * @return array{0: ?string, 1: string}
     */
    private function resolveEntryBody(Entry $entry, ?string $explicitHandle = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $candidates = array_values(array_unique(array_filter([
            $explicitHandle,
            $settings->blogBodyFieldHandle,
            self::DEON_BODY_FIELD_HANDLE,
        ])));
        foreach ($candidates as $handle) {
            try {
                $value = (string)$entry->getFieldValue($handle);
                return [$handle, $value];
            } catch (\Throwable $e) {
                continue;
            }
        }
        return [null, ''];
    }

    /**
     * Walkbare Text-Blöcke im Body-HTML — Contract identisch zum WP-Plugin
     * (aideon_content_blocks, html-Modus): h1–h3 = "title", p = "editor",
     * sortiert nach Position, IDs entstehen als "pc-<Index>".
     * @return array<int, array{pos: int, full: string, inner: string, kind: string}>
     */
    private function contentBlocks(string $content): array
    {
        $specs = [
            ['title', '/<h[1-3]\b[^>]*>(.*?)<\/h[1-3]>/is'],
            ['editor', '/<p\b[^>]*>(.*?)<\/p>/is'],
        ];
        $found = [];
        foreach ($specs as $spec) {
            if (preg_match_all($spec[1], $content, $matches, PREG_OFFSET_CAPTURE)) {
                $n = count($matches[0]);
                for ($i = 0; $i < $n; $i++) {
                    $found[] = [
                        'pos' => (int)$matches[0][$i][1],
                        'full' => (string)$matches[0][$i][0],
                        'inner' => (string)$matches[1][$i][0],
                        'kind' => $spec[0],
                    ];
                }
            }
        }
        usort($found, static fn(array $a, array $b) => $a['pos'] - $b['pos']);
        return $found;
    }

    /**
     * Läuft über das Field-Layout eines Entrys und liefert dessen Matrix-/
     * Neo-Felder strukturiert (siehe actionEntrySections). Nur Matrix/Neo
     * werden betrachtet — normale Felder des Entrys selbst (Titel, SEO,
     * legacy Body-Feld) sind keine "Sektionen" im Sinn dieses Endpoints.
     */
    private function resolveEntrySections(Entry $owner, int $depth, array &$budget): array
    {
        if ($depth >= self::SECTION_READER_MAX_DEPTH) {
            return [];
        }
        $layout = $owner->getFieldLayout();
        if (!$layout) {
            return [];
        }

        $out = [];
        foreach ($layout->getCustomFields() as $field) {
            if ($budget['count'] >= self::SECTION_READER_MAX_BLOCKS) {
                break;
            }
            try {
                $value = $owner->getFieldValue($field->handle);
            } catch (\Throwable $e) {
                continue;
            }
            if ($field instanceof MatrixField) {
                $out = array_merge($out, $this->resolveMatrixBlocks($field->handle, $value, $depth, $budget));
            } elseif ($this->isNeoField($field)) {
                $out = array_merge($out, $this->resolveNeoBlocks($field->handle, $value, $depth, $budget));
            }
        }
        return $out;
    }

    /**
     * Matrix-Blöcke eines Feldwerts auflösen. $value ist je nach Craft-Version
     * eine EntryQuery (Craft 5, verschachtelte Entries) oder eine
     * MatrixBlockQuery (Craft 4, MatrixBlock-Elemente) — beide liefern über
     * all() Elemente, deren getType() ein Handle/Name-Paar hat (EntryType
     * bzw. MatrixBlockType), daher hier bewusst ohne Versionsverzweigung.
     */
    private function resolveMatrixBlocks(string $fieldHandle, mixed $query, int $depth, array &$budget): array
    {
        if ($depth >= self::SECTION_READER_MAX_DEPTH || !is_object($query) || !method_exists($query, 'all')) {
            return [];
        }
        try {
            $blocks = $query->all();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($blocks as $index => $block) {
            if ($budget['count'] >= self::SECTION_READER_MAX_BLOCKS) {
                break;
            }
            $budget['count']++;
            try {
                $blockType = $block->getType();
            } catch (\Throwable $e) {
                continue;
            }
            $out[] = [
                'field_handle' => $fieldHandle,
                'block_index' => $index,
                'block_type' => (string)($blockType->handle ?? ''),
                'block_label' => (string)($blockType->name ?? ''),
                'fields' => $this->resolveBlockFields($block, $depth, $budget),
            ];
        }
        return $out;
    }

    /**
     * Neo-Feld (spicyweb/craft-neo, Field-Klasse benf\neo\Field) erkennen,
     * OHNE die Klasse zu importieren — Neo ist kein Composer-Dependency
     * dieses Plugins und auf den meisten Sites gar nicht installiert. Erst
     * per Plugin-Handle prüfen, dann per Klassenname vergleichen, damit
     * niemals gegen eine potenziell ungeladene Klasse geprüft wird (analog
     * zur bestehenden CKEditor-/Redactor-Erkennung in createDeonBodyField()).
     */
    private function isNeoField(mixed $field): bool
    {
        return Craft::$app->getPlugins()->getPlugin('neo') !== null
            && class_exists('benf\\neo\\Field')
            && get_class($field) === 'benf\\neo\\Field';
    }

    /**
     * Neo liefert über die Feldabfrage IMMER alle Ebenen flach (kein
     * automatischer level-Filter) — hier auf Top-Level (level 1) einschränken,
     * Kind-Blöcke kommen rekursiv über getChildren() dazu (siehe
     * resolveNeoBlockList()). Fail-soft: liefert [] statt Fatal, wenn Neo
     * intern anders reagiert als erwartet (Drittanbieter-Plugin).
     */
    private function resolveNeoBlocks(string $fieldHandle, mixed $query, int $depth, array &$budget): array
    {
        if ($depth >= self::SECTION_READER_MAX_DEPTH || !is_object($query) || !method_exists($query, 'all')) {
            return [];
        }
        try {
            $all = $query->all();
        } catch (\Throwable $e) {
            return [];
        }
        $topLevel = array_values(array_filter($all, static function ($block) {
            try {
                return !isset($block->level) || (int)$block->level === 1;
            } catch (\Throwable $e) {
                return true;
            }
        }));
        return $this->resolveNeoBlockList($fieldHandle, $topLevel, $depth, $budget);
    }

    /** Rekursiver Teil von resolveNeoBlocks() — verarbeitet eine bereits auf die richtige Ebene gefilterte Block-Liste. */
    private function resolveNeoBlockList(string $fieldHandle, array $blocks, int $depth, array &$budget): array
    {
        $out = [];
        foreach ($blocks as $index => $block) {
            if ($budget['count'] >= self::SECTION_READER_MAX_BLOCKS) {
                break;
            }
            $budget['count']++;
            try {
                $blockType = $block->getType();
            } catch (\Throwable $e) {
                continue;
            }
            $entry = [
                'field_handle' => $fieldHandle,
                'block_index' => $index,
                'block_type' => (string)($blockType->handle ?? ''),
                'block_label' => (string)($blockType->name ?? ''),
                'fields' => $this->resolveBlockFields($block, $depth, $budget),
            ];
            try {
                if ($depth + 1 < self::SECTION_READER_MAX_DEPTH && method_exists($block, 'getChildren')) {
                    $children = $block->getChildren()->all();
                    if (!empty($children)) {
                        $childOut = $this->resolveNeoBlockList($fieldHandle, $children, $depth + 1, $budget);
                        if (!empty($childOut)) {
                            $entry['children'] = $childOut;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Kind-Blöcke fail-soft weglassen, Block selbst bleibt gültig.
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Feld-Layout eines Matrix-/Neo-Blocks auflösen: normale Feldwerte per
     * describeFieldValue(), verschachtelte Matrix-/Neo-Felder rekursiv eine
     * Ebene tiefer. $block ist je nach Kontext ein Entry (Craft 5), ein
     * MatrixBlock (Craft 4) oder ein Neo-Block — alle drei erweitern
     * craft\base\Element und haben daher getFieldLayout()/getFieldValue().
     */
    private function resolveBlockFields(mixed $block, int $depth, array &$budget): array
    {
        try {
            $layout = $block->getFieldLayout();
        } catch (\Throwable $e) {
            return [];
        }
        if (!$layout) {
            return [];
        }
        $fields = [];
        foreach ($layout->getCustomFields() as $field) {
            try {
                $value = $block->getFieldValue($field->handle);
            } catch (\Throwable $e) {
                continue;
            }
            if ($field instanceof MatrixField) {
                $nested = $this->resolveMatrixBlocks($field->handle, $value, $depth + 1, $budget);
                if (!empty($nested)) {
                    $fields[$field->handle] = $nested;
                }
                continue;
            }
            if ($this->isNeoField($field)) {
                $nested = $this->resolveNeoBlocks($field->handle, $value, $depth + 1, $budget);
                if (!empty($nested)) {
                    $fields[$field->handle] = $nested;
                }
                continue;
            }
            $fields[$field->handle] = $this->describeFieldValue($field, $value);
        }
        return $fields;
    }

    /**
     * Best-effort Repräsentation eines einzelnen Block-Feldwerts.
     * Typklassifikation über die tatsächliche Feld-Klasse, nicht über den
     * Feld-Namen — jede Kunden-Site kann eigene Handle-Namen verwenden.
     */
    private function describeFieldValue(mixed $field, mixed $value): mixed
    {
        try {
            if ($field instanceof AssetsField) {
                $assets = method_exists($value, 'all') ? $value->all() : [];
                $items = array_map(static fn(Asset $a) => [
                    'url' => $a->getUrl(),
                    'alt' => (string)($a->alt ?? ''),
                ], $assets);
                return count($items) === 1 ? $items[0] : $items;
            }
            if ($value instanceof \craft\elements\db\ElementQueryInterface) {
                $items = [];
                foreach ($value->all() as $el) {
                    $item = ['title' => (string)($el->title ?? '')];
                    if (method_exists($el, 'getUrl')) {
                        try {
                            $item['url'] = $el->getUrl();
                        } catch (\Throwable $e) {
                        }
                    }
                    $items[] = $item;
                }
                return count($items) === 1 ? $items[0] : $items;
            }
            if (is_scalar($value) || $value === null) {
                return (string)$value;
            }
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Preview-Token verifizieren — Format identisch zum WP-Plugin:
     * base64url(hmac_sha256("<url>:<exp>", apiKey, raw)) . "." . exp.
     */
    private function verifyPreviewToken(string $token, string $url): bool
    {
        if ($token === '' || $url === '') {
            return false;
        }
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$sigB64, $exp] = $parts;
        $exp = (int)$exp;
        if ($exp < time() - 5 || $exp > time() + 600) {
            return false;
        }
        $apiKey = Plugin::getInstance()->getSettings()->apiKey;
        if (empty($apiKey)) {
            return false;
        }
        $expected = hash_hmac('sha256', $url . ':' . $exp, $apiKey, true);
        $expectedB64 = rtrim(strtr(base64_encode($expected), '+/', '-_'), '=');
        return hash_equals($expectedB64, $sigB64);
    }

    /** Upsert einer SEO-Override-Zeile (nur nicht-null-Felder), fail-soft. */
    private function upsertSeoOverrideRow(string $uri, array $fields): void
    {
        try {
            $fields = array_filter($fields, static fn($v) => $v !== null);
            if (empty($fields)) {
                return;
            }
            $db = Craft::$app->getDb();
            $table = '{{%deonai_seo_overrides}}';
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $existing = (new \craft\db\Query())->from($table)->where(['uri' => $uri])->one();
            if ($existing) {
                $db->createCommand()->update($table, $fields + ['dateUpdated' => $now], ['id' => $existing['id']])->execute();
            } else {
                $db->createCommand()->insert($table, $fields + [
                    'uri' => $uri, 'enabled' => true,
                    'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
                ])->execute();
            }
        } catch (\Throwable $e) {
            Craft::warning('deon-ai-connect seo override upsert failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * CSS der Startseite einsammeln: <style>-Blöcke + bis zu 3 Same-Origin-
     * Stylesheets (je max. 300 KB). Fail-soft: leerer String bei Fehlern.
     */
    private function collectSiteCss(): string
    {
        try {
            $baseUrl = (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
            if ($baseUrl === '') {
                return '';
            }
            $client = Craft::createGuzzleClient(['timeout' => 15]);
            $response = $client->request('GET', $baseUrl, ['http_errors' => false]);
            if ($response->getStatusCode() >= 400) {
                return '';
            }
            $html = (string)$response->getBody();
            $css = '';

            if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches)) {
                $css .= implode("\n", $matches[1]);
            }

            $ownHost = preg_replace('/^www\./', '', strtolower((string)parse_url($baseUrl, PHP_URL_HOST)));
            if (preg_match_all('/<link\b[^>]*rel=("|\')stylesheet\1[^>]*>/i', $html, $matches)) {
                $fetched = 0;
                foreach ($matches[0] as $tag) {
                    if ($fetched >= 3 || !preg_match('/href=("|\')([^"\']+)\1/i', $tag, $href)) {
                        continue;
                    }
                    $cssUrl = html_entity_decode($href[2]);
                    if (str_starts_with($cssUrl, '//')) {
                        $cssUrl = 'https:' . $cssUrl;
                    } elseif (str_starts_with($cssUrl, '/')) {
                        $cssUrl = rtrim($baseUrl, '/') . $cssUrl;
                    }
                    $cssHost = preg_replace('/^www\./', '', strtolower((string)parse_url($cssUrl, PHP_URL_HOST)));
                    if ($cssHost !== $ownHost) {
                        continue;
                    }
                    try {
                        $cssResponse = $client->request('GET', $cssUrl, ['http_errors' => false]);
                        if ($cssResponse->getStatusCode() < 400) {
                            $body = (string)$cssResponse->getBody();
                            if (strlen($body) <= 300 * 1024) {
                                $css .= "\n" . $body;
                                $fetched++;
                            }
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
            return $css;
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ─── v0.10.0: Content-Write-Fixes ───────────────────────────────────────

    /**
     * POST /deon-ai/audit-fix — Content-Write-Fixes vom Deon-AI-Worker
     * (Freshness-Refresh, interne Verlinkung). Contract = WP /audit-fix,
     * beschränkt auf die zwei Actions, die Craft braucht — alle anderen
     * Fix-Actions (set_title/set_meta_desc/inject_faq/…) laufen bei Craft
     * bereits über /deon-ai/seo bzw. /deon-ai/faq.
     * Body: { action: "replace_content"|"append_html_box", page_url,
     *         new_content?, custom_value?, payload?: { box_marker? } }
     */
    public function actionAuditFix(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('content_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $action = (string)($body['action'] ?? '');
        if (!in_array($action, ['replace_content', 'append_html_box'], true)) {
            return $this->asJson(['ok' => false, 'error' => 'unknown_action', 'message' => 'Unbekannte Fix-Action: ' . $action])->setStatusCode(400);
        }

        // Entry auflösen wie actionMatchUrl: Pfad → URI, Fallback letztes Slug-Segment
        $pageUrl = trim((string)($body['page_url'] ?? ''));
        $path = trim((string)(parse_url($pageUrl, PHP_URL_PATH) ?? ''), '/');
        $entry = Entry::find()->uri($path === '' ? '__home__' : $path)->status(null)->one();
        if (!$entry && $path !== '') {
            $entry = Entry::find()->slug(basename($path))->status(null)->one();
        }
        if (!$entry) {
            return $this->asJson(['ok' => false, 'error' => 'not_found'])->setStatusCode(404);
        }

        [$bodyHandle, $content] = $this->resolveEntryBody($entry);
        if ($bodyHandle === null) {
            return $this->asJson(['ok' => false, 'error' => 'body_field_not_found'])->setStatusCode(422);
        }

        // Snapshot VOR der Änderung — rollback_id ist Pflichtfeld der Response
        $beforeState = [
            'title' => $entry->title, 'slug' => $entry->slug, 'enabled' => $entry->enabled,
            'bodyFieldHandle' => $bodyHandle, 'bodyValue' => $content,
        ];
        $changeId = $this->logChange('entry', (string)$entry->id, $beforeState, $beforeState, 'audit-fix: ' . $action);

        $applied = [];
        $newContent = $content;

        if ($action === 'replace_content') {
            if (!isset($body['new_content'])) {
                return $this->asJson(['ok' => false, 'error' => 'missing_content', 'message' => 'new_content erforderlich (string mit vollem Body-HTML)'])->setStatusCode(400);
            }
            // Bewusst KEIN HTML-Stripping à la wp_kses_post: authentifizierter
            // Plugin-Kontext, und der CKEditor-/Redactor-Purifier greift beim Rendern.
            $newContent = (string)$body['new_content'];
            $applied[] = 'post_content_replaced';
        } else { // append_html_box
            $customValue = (string)($body['custom_value'] ?? '');
            if ($customValue === '') {
                return $this->asJson(['ok' => false, 'error' => 'missing_box', 'message' => 'custom_value (Box-HTML) erforderlich'])->setStatusCode(400);
            }
            $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            // Marker-Sanitize + Regex identisch zum WP-Plugin (v3.24): eigener
            // Marker pro Box-Typ verhindert Kollisionen auf derselben Seite.
            $boxMarker = isset($payload['box_marker']) ? preg_replace('/[^a-z0-9_-]/i', '', (string)$payload['box_marker']) : 'deon-cluster-ref';
            if ($boxMarker === '') {
                $boxMarker = 'deon-cluster-ref';
            }
            $markerRe = '#<aside[^>]*class=["\'][^"\']*' . preg_quote($boxMarker, '#') . '[^"\']*["\'][\s\S]*?</aside>#i';
            if (preg_match($markerRe, $content)) {
                $newContent = preg_replace($markerRe, str_replace(['\\', '$'], ['\\\\', '\$'], $customValue), $content, 1);
                $actionTaken = 'replaced';
            } else {
                $newContent = rtrim($content) . "\n\n" . $customValue . "\n";
                $actionTaken = 'appended';
            }
            $applied[] = ($newContent === $content) ? 'no_change' : 'post_content_' . $actionTaken;
        }

        if ($newContent !== $content) {
            try {
                $entry->setFieldValue($bodyHandle, $newContent);
            } catch (\Throwable $e) {
                return $this->asJson(['ok' => false, 'error' => 'body_field_not_found'])->setStatusCode(422);
            }
            if (!Craft::$app->getElements()->saveElement($entry)) {
                return $this->asJson(['ok' => false, 'error' => 'update_failed', 'message' => 'Entry-Update fehlgeschlagen', 'details' => $entry->getErrors()])->setStatusCode(500);
            }
            // Nachher-Zustand in den Snapshot (Konflikt-Erkennung beim Restore)
            Craft::$app->getDb()->createCommand()->update('{{%deonai_change_log}}', [
                'afterJson' => json_encode(array_merge($beforeState, ['bodyValue' => $newContent]), JSON_UNESCAPED_UNICODE),
                'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
            ], ['id' => $changeId])->execute();
        }

        return $this->asJson([
            'success' => true,
            'post_id' => $entry->id,
            'page_url' => $entry->getUrl(),
            'edit_url' => $entry->getCpEditUrl(),
            'action' => $action,
            'rollback_id' => 'rb_' . $changeId,
            'rollback_available' => true,
            // Exakter WP-Response-Key ist meta_keys_applied — "applied" (Handoff-
            // Wortlaut) zusätzlich als Alias, damit beide Worker-Lesarten funktionieren.
            'meta_keys_applied' => $applied,
            'applied' => $applied,
            'plugin_version' => Plugin::getInstance()->getVersion(),
        ]);
    }

    // ─── v0.9.0: Section-Tests + A/B-Varianten (Craft-nativ) ────────────────

    /**
     * POST /deon-ai/section-test/create — Variante einer Seite anlegen und
     * 50/50-Test starten. Body: { original_post_id, name?, sections_changes:
     * [{action: insert|remove|move|replace, selector?, position?, html?,
     * target_selector?}] } — Contract wie WP; "Sections" sind in Craft die
     * Top-Level-Elemente des Body-HTML (builder "html"), Selector = Index
     * oder "tag[n]" (z. B. "section[1]").
     */
    public function actionSectionTestCreate(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('content_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $originalId = (int)($body['original_post_id'] ?? 0);
        if (!$originalId) {
            return $this->asJson(['ok' => false, 'error' => 'original_post_id required'])->setStatusCode(400);
        }
        $original = Entry::find()->id($originalId)->status(null)->one();
        if (!$original) {
            return $this->asJson(['ok' => false, 'error' => 'not_found', 'message' => 'Original-Post nicht gefunden'])->setStatusCode(404);
        }
        $name = trim((string)($body['name'] ?? 'Variant ' . date('Y-m-d H:i')));
        $sectionsChanges = is_array($body['sections_changes'] ?? null) ? $body['sections_changes'] : [];

        [$bodyHandle, $content] = $this->resolveEntryBody($original);
        if ($bodyHandle === null) {
            return $this->asJson(['ok' => false, 'error' => 'body_field_not_found'])->setStatusCode(422);
        }

        $applyResult = $this->applySectionChanges($content, $sectionsChanges);
        if (isset($applyResult['error'])) {
            return $this->asJson(['ok' => false, 'error' => $applyResult['error']])->setStatusCode(500);
        }

        try {
            $variant = Craft::$app->getElements()->duplicateElement($original, [
                'title' => mb_substr($original->title . ' [Deon-Variant]', 0, 255),
                'enabled' => false, // versteckt fürs Frontend — ausgespielt wird sie nur über den Server-Split
            ]);
            $variant->slug = mb_substr($original->slug . '-deon-v', 0, 200);
            $variant->setFieldValue($bodyHandle, $applyResult['content']);
            if (!Craft::$app->getElements()->saveElement($variant)) {
                return $this->asJson(['ok' => false, 'error' => 'clone_failed', 'details' => $variant->getErrors()])->setStatusCode(500);
            }
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'error' => 'clone_failed', 'message' => $e->getMessage()])->setStatusCode(500);
        }

        $testId = 'st_' . substr(bin2hex(random_bytes(9)), 0, 12);
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert('{{%deonai_section_tests}}', [
            'testId' => $testId,
            'originalId' => $original->id,
            'variantId' => $variant->id,
            'name' => mb_substr($name, 0, 255),
            'builder' => 'html',
            'status' => 'running',
            'sectionsChanges' => json_encode($sectionsChanges, JSON_UNESCAPED_UNICODE),
            'warnings' => json_encode($applyResult['warnings'], JSON_UNESCAPED_UNICODE),
            'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
        ])->execute();

        return $this->asJson([
            'success' => true,
            'test_id' => $testId,
            'variant_post_id' => $variant->id,
            'variant_edit_url' => $variant->getCpEditUrl(),
            'original_url' => $original->getUrl(),
            'builder' => 'html',
            'apply_warnings' => $applyResult['warnings'],
            'cache_hint' => [],
        ]);
    }

    /** GET /deon-ai/section-test/list/<id> — laufende/gestoppte Tests eines Entries (WP-Shape). */
    public function actionSectionTestList(int $id): Response
    {
        $this->requireDeonKey();
        $rows = (new \craft\db\Query())
            ->from('{{%deonai_section_tests}}')
            ->where(['originalId' => $id])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
        $tests = array_map(static function (array $row) {
            return [
                'id' => $row['testId'],
                'variant_post_id' => (int)$row['variantId'],
                'name' => $row['name'],
                'builder' => $row['builder'],
                'status' => $row['status'],
                'winner' => $row['winner'],
                'started_at' => strtotime((string)$row['dateCreated']) ?: null,
                'visitors_a' => (int)$row['visitorsA'],
                'visitors_b' => (int)$row['visitorsB'],
                'conversions_a' => (int)$row['conversionsA'],
                'conversions_b' => (int)$row['conversionsB'],
                'sections_changes' => json_decode((string)$row['sectionsChanges'], true) ?: [],
                'apply_warnings' => json_decode((string)$row['warnings'], true) ?: [],
            ];
        }, $rows);
        return $this->asJson(['tests' => $tests, 'cache_plugins_detected' => []]);
    }

    /**
     * POST /deon-ai/section-test/stop — Test beenden. Body: { original_post_id,
     * test_id, winner: "a"|"b"|"none" }. Winner "b" → Varianten-Body wird ins
     * Original gemerged (mit Rollback-Snapshot davor). Die Varianten-Entry
     * wandert in den Craft-Papierkorb (weiches Löschen statt WP-Force-Delete).
     */
    public function actionSectionTestStop(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('content_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $originalId = (int)($body['original_post_id'] ?? 0);
        $testId = (string)($body['test_id'] ?? '');
        $winner = in_array($body['winner'] ?? 'none', ['a', 'b', 'none'], true) ? (string)$body['winner'] : 'none';
        if (!$originalId || $testId === '') {
            return $this->asJson(['ok' => false, 'error' => 'original_post_id + test_id required'])->setStatusCode(400);
        }
        $test = (new \craft\db\Query())->from('{{%deonai_section_tests}}')->where(['testId' => $testId, 'originalId' => $originalId])->one();
        if (!$test) {
            return $this->asJson(['ok' => false, 'error' => 'not_found', 'message' => 'Test nicht gefunden'])->setStatusCode(404);
        }

        $rollbackId = null;
        if ($winner === 'b') {
            $original = Entry::find()->id($originalId)->status(null)->one();
            $variant = Entry::find()->id((int)$test['variantId'])->status(null)->one();
            if ($original && $variant) {
                [$bodyHandle, $originalBody] = $this->resolveEntryBody($original);
                [, $variantBody] = $this->resolveEntryBody($variant);
                if ($bodyHandle !== null) {
                    $changeId = $this->logChange('entry', (string)$original->id, [
                        'title' => $original->title, 'slug' => $original->slug, 'enabled' => $original->enabled,
                        'bodyFieldHandle' => $bodyHandle, 'bodyValue' => $originalBody,
                    ], [
                        'title' => $original->title, 'slug' => $original->slug, 'enabled' => $original->enabled,
                        'bodyFieldHandle' => $bodyHandle, 'bodyValue' => $variantBody,
                    ], 'section-test/stop — Variante B gemerged (' . $testId . ')');
                    $rollbackId = 'rb_' . $changeId;
                    try {
                        $original->setFieldValue($bodyHandle, $variantBody);
                        if (!Craft::$app->getElements()->saveElement($original)) {
                            return $this->asJson(['ok' => false, 'error' => 'merge_failed', 'details' => $original->getErrors()])->setStatusCode(500);
                        }
                    } catch (\Throwable $e) {
                        return $this->asJson(['ok' => false, 'error' => 'merge_failed', 'message' => $e->getMessage()])->setStatusCode(500);
                    }
                }
            }
        }

        // Varianten-Entry in den Papierkorb (fail-soft)
        try {
            $variant = Entry::find()->id((int)$test['variantId'])->status(null)->one();
            if ($variant) {
                Craft::$app->getElements()->deleteElement($variant);
            }
        } catch (\Throwable $e) {
            Craft::warning('deon-ai-connect section-test: Varianten-Löschen fehlgeschlagen: ' . $e->getMessage(), __METHOD__);
        }

        Craft::$app->getDb()->createCommand()->update('{{%deonai_section_tests}}', [
            'status' => 'stopped',
            'winner' => $winner,
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $test['id']])->execute();

        return $this->asJson([
            'success' => true,
            'winner' => $winner,
            'merged_into_original' => ($winner === 'b'),
            'rollback_id' => $rollbackId,
            'rollback_available' => $rollbackId !== null,
        ]);
    }

    /** POST /deon-ai/section-test/preview — Section-Changes anwenden ohne zu speichern (WP-Shape). */
    public function actionSectionTestPreview(): Response
    {
        $this->requireDeonKey();
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $originalId = (int)($body['original_post_id'] ?? 0);
        if (!$originalId) {
            return $this->asJson(['ok' => false, 'error' => 'original_post_id required'])->setStatusCode(400);
        }
        $original = Entry::find()->id($originalId)->status(null)->one();
        if (!$original) {
            return $this->asJson(['ok' => false, 'error' => 'not_found', 'message' => 'Original-Post nicht gefunden'])->setStatusCode(404);
        }
        $sectionsChanges = is_array($body['sections_changes'] ?? null) ? $body['sections_changes'] : [];
        [, $content] = $this->resolveEntryBody($original);
        $applyResult = $this->applySectionChanges($content, $sectionsChanges);
        if (isset($applyResult['error'])) {
            return $this->asJson(['ok' => false, 'error' => $applyResult['error']])->setStatusCode(500);
        }
        return $this->asJson([
            'success' => true,
            'builder' => 'html',
            'preview_html' => $applyResult['content'],
            'preview_content' => $applyResult['content'],
            'warnings' => $applyResult['warnings'],
        ]);
    }

    /**
     * POST /deon-ai/publish-winner — Änderungs-Liste auf eine Seite anwenden
     * (WP-Contract). changes[]-Typen: seo_meta {title, description} →
     * SEO-Override; content_replace {find, replace} → Body-Austausch;
     * html_section {selector, new_content} → Section-Replace (Craft-Pendant
     * zu elementor_section — das selbst meldet einen Fehler, Elementor gibt
     * es in Craft nicht).
     */
    public function actionPublishWinner(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('content_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();

        $entryId = (int)($body['post_id'] ?? 0);
        if (!$entryId) {
            return $this->asJson(['ok' => false, 'error' => 'post_id required'])->setStatusCode(400);
        }
        $entry = Entry::find()->id($entryId)->status(null)->one();
        if (!$entry) {
            return $this->asJson(['ok' => false, 'error' => 'not_found'])->setStatusCode(404);
        }
        $changes = is_array($body['changes'] ?? null) ? $body['changes'] : [];
        $publishMode = (string)($body['publish_mode'] ?? 'draft');

        [$bodyHandle, $content] = $this->resolveEntryBody($entry);
        $beforeState = [
            'title' => $entry->title, 'slug' => $entry->slug, 'enabled' => $entry->enabled,
            'bodyFieldHandle' => $bodyHandle, 'bodyValue' => $content,
        ];
        $changeId = $this->logChange('entry', (string)$entry->id, $beforeState, $beforeState, 'publish-winner (Snapshot vor Merge)');
        $rollbackId = 'rb_' . $changeId;

        $applied = [];
        $errors = [];
        $bodyDirty = false;
        $uri = '/' . ltrim((string)($entry->uri === '__home__' ? '' : $entry->uri), '/');

        foreach ($changes as $change) {
            $type = is_array($change) ? (string)($change['type'] ?? '') : '';
            try {
                switch ($type) {
                    case 'seo_meta':
                        // actionPublishWinner() selbst prüft nur 'content_edit' — SEO-Overrides
                        // laufen sonst ausschließlich über actionSetSeo() hinter 'seo_meta'.
                        // Ohne diesen Check könnte 'content_edit' allein (ohne 'seo_meta')
                        // trotzdem SEO-Title/-Description überschreiben.
                        if (empty(Plugin::getInstance()->getSettings()->allowSeoMeta)) {
                            $errors[] = 'seo_meta: consent_required';
                            break;
                        }
                        $fields = [];
                        if (isset($change['title'])) {
                            $fields['title'] = mb_substr((string)$change['title'], 0, 200);
                            $applied[] = 'seo_title';
                        }
                        if (isset($change['description'])) {
                            $fields['metaDescription'] = mb_substr((string)$change['description'], 0, 300);
                            $applied[] = 'seo_description';
                        }
                        if (!empty($fields)) {
                            $this->upsertSeoOverrideRow($uri, $fields);
                        }
                        break;

                    case 'content_replace':
                        $find = (string)($change['find'] ?? '');
                        if ($find !== '' && $bodyHandle !== null) {
                            $newContent = str_replace($find, (string)($change['replace'] ?? ''), $content);
                            if ($newContent !== $content) {
                                $content = $newContent;
                                $bodyDirty = true;
                                $applied[] = 'content_replace';
                            }
                        }
                        break;

                    case 'html_section':
                        $selector = $change['selector'] ?? null;
                        $newContent = (string)($change['new_content'] ?? '');
                        if ($selector !== null && $newContent !== '' && $bodyHandle !== null) {
                            $result = $this->applySectionChanges($content, [[
                                'action' => 'replace', 'selector' => $selector, 'html' => $newContent,
                            ]]);
                            if (!isset($result['error']) && $result['content'] !== $content) {
                                $content = $result['content'];
                                $bodyDirty = true;
                                $applied[] = 'html_section_' . (is_scalar($selector) ? $selector : '?');
                            }
                        }
                        break;

                    case 'elementor_section':
                        $errors[] = 'Elementor not available on Craft — use html_section';
                        break;
                }
            } catch (\Throwable $e) {
                $errors[] = $type . ': ' . $e->getMessage();
            }
        }

        if ($publishMode === 'publish' && !empty($body['allow_direct_publish'])) {
            $entry->enabled = true;
            $applied[] = 'published';
        }
        if ($bodyDirty && $bodyHandle !== null) {
            try {
                $entry->setFieldValue($bodyHandle, $content);
            } catch (\Throwable $e) {
                $errors[] = 'body_write: ' . $e->getMessage();
                $bodyDirty = false;
            }
        }
        if (($bodyDirty || in_array('published', $applied, true)) && !Craft::$app->getElements()->saveElement($entry)) {
            return $this->asJson(['ok' => false, 'error' => 'save_failed', 'details' => $entry->getErrors()])->setStatusCode(500);
        }

        // Nachher-Zustand in den Snapshot schreiben (Rollback stellt den Vorher-Stand wieder her)
        Craft::$app->getDb()->createCommand()->update('{{%deonai_change_log}}', [
            'afterJson' => json_encode([
                'title' => $entry->title, 'slug' => $entry->slug, 'enabled' => $entry->enabled,
                'bodyFieldHandle' => $bodyHandle, 'bodyValue' => $content,
            ], JSON_UNESCAPED_UNICODE),
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $changeId])->execute();

        return $this->asJson([
            'success' => true,
            'post_id' => $entry->id,
            'applied' => $applied,
            'errors' => $errors,
            'rollback_id' => $rollbackId,
            'rollback_available' => true,
        ]);
    }

    /** Erlaubte A/B-Varianten-Modi (identisch zum WP-Plugin v3.9). */
    private const AB_MODES = ['text', 'html', 'meta', 'attr', 'style', 'link', 'form'];

    /**
     * POST /deon-ai/ab-variant/create — Selector-basierte A/B-Variante anlegen
     * (WP-Contract, alle Felder). Ausspielung über das Frontend-Snippet
     * (siehe Plugin::renderAbVariantSnippet()).
     */
    public function actionAbVariantCreate(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('content_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $entryId = (int)($body['post_id'] ?? 0);
        if (!$entryId || !Entry::find()->id($entryId)->status(null)->exists()) {
            return $this->asJson(['ok' => false, 'error' => 'post_id required'])->setStatusCode(400);
        }

        $variantId = 'av_' . substr(bin2hex(random_bytes(8)), 0, 10);
        $config = [
            'id' => $variantId,
            'name' => mb_substr(trim((string)($body['name'] ?? 'Variant B')), 0, 255),
            'selector' => (string)($body['selector'] ?? ''),
            'mode' => in_array($body['mode'] ?? 'text', self::AB_MODES, true) ? (string)$body['mode'] : 'text',
            'find' => (string)($body['find'] ?? ''),
            'replace' => (string)($body['replace'] ?? ''),
            'variant_b_html' => (string)($body['variant_b_html'] ?? ''),
            'attr' => (string)($body['attr'] ?? ''),
            'value' => (string)($body['value'] ?? ''),
            'alt' => (string)($body['alt'] ?? ''),
            'href' => (string)($body['href'] ?? ''),
            'target' => (string)($body['target'] ?? ''),
            'text' => (string)($body['text'] ?? ''),
            'bg_color' => (string)($body['bg_color'] ?? ''),
            'color' => (string)($body['color'] ?? ''),
            'custom_css' => (string)($body['custom_css'] ?? ''),
            'form_action' => (string)($body['form_action'] ?? ''),
            'form_method' => (string)($body['form_method'] ?? ''),
            'submit_text' => (string)($body['submit_text'] ?? ''),
            'field_updates' => is_array($body['field_updates'] ?? null) ? $body['field_updates'] : [],
            'percentage' => max(1, min(99, (int)($body['percentage'] ?? 50))),
            'created_at' => time(),
            'visitors_a' => 0, 'visitors_b' => 0, 'conversions_a' => 0, 'conversions_b' => 0,
            'status' => 'running',
        ];

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert('{{%deonai_ab_variants}}', [
            'variantId' => $variantId,
            'entryId' => $entryId,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'status' => 'running',
            'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
        ])->execute();

        return $this->asJson([
            'success' => true,
            'variant_id' => $variantId,
            'variant' => $config,
            'frontend_snippet_active' => true,
        ]);
    }

    /** GET /deon-ai/ab-variant/list/<id> — Varianten eines Entries (WP-Shape: keyed Object). */
    public function actionAbVariantList(int $id): Response
    {
        $this->requireDeonKey();
        $rows = (new \craft\db\Query())->from('{{%deonai_ab_variants}}')->where(['entryId' => $id])->all();
        $variants = [];
        foreach ($rows as $row) {
            $config = json_decode((string)$row['config'], true) ?: [];
            $config['status'] = $row['status'];
            if ($row['winner'] !== null) {
                $config['winner'] = $row['winner'];
            }
            $variants[$row['variantId']] = $config;
        }
        return $this->asJson(['variants' => $variants]);
    }

    /** POST /deon-ai/ab-variant/stop — Variante beenden. Body: { post_id, variant_id, winner? }. */
    public function actionAbVariantStop(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('content_edit')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $entryId = (int)($body['post_id'] ?? 0);
        $variantId = (string)($body['variant_id'] ?? '');
        $winner = in_array($body['winner'] ?? null, ['a', 'b'], true) ? (string)$body['winner'] : null;

        $row = (new \craft\db\Query())->from('{{%deonai_ab_variants}}')->where(['variantId' => $variantId, 'entryId' => $entryId])->one();
        if (!$row) {
            return $this->asJson(['ok' => false, 'error' => 'not_found', 'message' => 'Variant not found'])->setStatusCode(404);
        }
        $config = json_decode((string)$row['config'], true) ?: [];
        $config['status'] = 'stopped';
        $config['winner'] = $winner;
        Craft::$app->getDb()->createCommand()->update('{{%deonai_ab_variants}}', [
            'status' => 'stopped',
            'winner' => $winner,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $row['id']])->execute();

        return $this->asJson(['success' => true]);
    }

    /** POST /deon-ai/configure-ab — A/B-Ausspielung remote konfigurieren (WP-Shape). */
    public function actionConfigureAb(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('ab_test')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $config = Plugin::getInstance()->getJsonConfig('ab_config');
        if (isset($body['enabled'])) {
            $config['enabled'] = (bool)$body['enabled'];
        }
        if (isset($body['anti_flicker'])) {
            $config['anti_flicker'] = (bool)$body['anti_flicker'];
        }
        if (isset($body['store_id'])) {
            $config['store_id'] = (string)$body['store_id'];
        }
        $this->saveJsonConfig('ab_config', $config);
        return $this->asJson([
            'success' => true,
            'ab_enabled' => (bool)($config['enabled'] ?? true),
            'anti_flicker' => (bool)($config['anti_flicker'] ?? false),
            'store_id' => (string)($config['store_id'] ?? ''),
        ]);
    }

    /** GET /deon-ai/ab-status — aktueller A/B-Konfigurationsstand (WP-Shape). */
    public function actionAbStatus(): Response
    {
        $this->requireDeonKey();
        $config = Plugin::getInstance()->getJsonConfig('ab_config');
        return $this->asJson([
            'ab_enabled' => (bool)($config['enabled'] ?? true),
            'anti_flicker' => (bool)($config['anti_flicker'] ?? false),
            'store_id' => (string)($config['store_id'] ?? ''),
            'script_url' => 'https://audit.deon-ai.de/sdk.js',
        ]);
    }

    /** POST /deon-ai/configure-tracker — SDK-/Tracker-Ausspielung remote konfigurieren (WP-Shape). */
    public function actionConfigureTracker(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('ab_test')) {
            return $response;
        }
        $this->requirePostRequest();
        $body = Craft::$app->getRequest()->getBodyParams();
        $config = Plugin::getInstance()->getJsonConfig('tracker_config');
        if (isset($body['enabled'])) {
            $config['enabled'] = (bool)$body['enabled'];
        }
        if (isset($body['consent_mode'])) {
            $config['consent_mode'] = (string)$body['consent_mode'];
        }
        $this->saveJsonConfig('tracker_config', $config);
        return $this->asJson([
            'success' => true,
            'tracker_enabled' => (bool)($config['enabled'] ?? true),
            'consent_mode' => (string)($config['consent_mode'] ?? 'auto'),
            'cmp_detected' => '',
        ]);
    }

    /** GET /deon-ai/tracker-status — aktueller Tracker-Stand (WP-Shape). */
    public function actionTrackerStatus(): Response
    {
        $this->requireDeonKey();
        $config = Plugin::getInstance()->getJsonConfig('tracker_config');
        return $this->asJson([
            'tracker_enabled' => (bool)($config['enabled'] ?? true),
            'consent_mode' => (string)($config['consent_mode'] ?? 'auto'),
            'cmp_detected' => '',
        ]);
    }

    /** JSON-Config-Zeile in deonai_seo_hygiene schreiben (Pendant zu Plugin::getJsonConfig()). */
    private function saveJsonConfig(string $type, array $config): void
    {
        $db = Craft::$app->getDb();
        $table = '{{%deonai_seo_hygiene}}';
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $existing = (new \craft\db\Query())->from($table)->where(['type' => $type])->one();
        if ($existing) {
            $db->createCommand()->update($table, ['content' => $json, 'dateUpdated' => $now], ['id' => $existing['id']])->execute();
        } else {
            $db->createCommand()->insert($table, [
                'type' => $type, 'content' => $json,
                'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }

    /**
     * Section-Engine (builder "html"): behandelt die Top-Level-Elemente des
     * Body-HTML als Sections und wendet insert/remove/move/replace an.
     * Selector: Integer-Index oder "tag[n]" (n-tes Vorkommen des Tags,
     * 0-basiert; "tag" allein = erstes). Craft-natives Pendant zum
     * DOM-Fallback des WP-Plugins, dort aber auf alle 4 Aktionen ausgebaut.
     * @return array{content: string, warnings: array}|array{error: string}
     */
    private function applySectionChanges(string $content, array $sectionsChanges): array
    {
        $warnings = [];
        if (empty($sectionsChanges)) {
            return ['content' => $content, 'warnings' => ['no_changes_passed']];
        }
        if (!class_exists('DOMDocument')) {
            return ['error' => 'no_dom'];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $content . '</body></html>');
        libxml_clear_errors();
        if (!$loaded) {
            return ['error' => 'dom_load_failed'];
        }
        $domBody = $dom->getElementsByTagName('body')->item(0);
        if (!$domBody) {
            return ['error' => 'dom_load_failed'];
        }

        $topLevelElements = static function () use ($domBody): array {
            $out = [];
            foreach ($domBody->childNodes as $node) {
                if ($node instanceof \DOMElement) {
                    $out[] = $node;
                }
            }
            return $out;
        };
        $resolveIndex = static function (array $sections, $selector): ?int {
            if ($selector === null || $selector === '') {
                return null;
            }
            if (is_int($selector) || (is_string($selector) && ctype_digit($selector))) {
                $idx = (int)$selector;
                return ($idx >= 0 && $idx < count($sections)) ? $idx : null;
            }
            if (is_string($selector) && preg_match('/^([a-z][a-z0-9]*)(?:\[(\d+)\])?$/i', $selector, $m)) {
                $want = strtolower($m[1]);
                $nth = isset($m[2]) ? (int)$m[2] : 0;
                $count = 0;
                foreach ($sections as $i => $node) {
                    if (strtolower($node->tagName) === $want) {
                        if ($count === $nth) {
                            return $i;
                        }
                        $count++;
                    }
                }
            }
            return null;
        };
        $importFragment = static function (string $html) use ($dom): array {
            $tmp = new \DOMDocument();
            libxml_use_internal_errors(true);
            $ok = $tmp->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
            libxml_clear_errors();
            if (!$ok) {
                return [];
            }
            $tmpBody = $tmp->getElementsByTagName('body')->item(0);
            if (!$tmpBody) {
                return [];
            }
            $nodes = [];
            foreach ($tmpBody->childNodes as $node) {
                $nodes[] = $dom->importNode($node, true);
            }
            return $nodes;
        };
        $insertNodes = static function (array $nodes, ?\DOMElement $ref, string $position) use ($domBody): void {
            if ($ref === null) {
                foreach ($nodes as $node) {
                    $domBody->appendChild($node);
                }
                return;
            }
            if ($position === 'before') {
                foreach ($nodes as $node) {
                    $domBody->insertBefore($node, $ref);
                }
                return;
            }
            $next = $ref->nextSibling;
            foreach ($nodes as $node) {
                if ($next) {
                    $domBody->insertBefore($node, $next);
                } else {
                    $domBody->appendChild($node);
                }
            }
        };

        foreach ($sectionsChanges as $change) {
            if (!is_array($change)) {
                continue;
            }
            $action = (string)($change['action'] ?? '');
            $sections = $topLevelElements();
            $idx = $resolveIndex($sections, $change['selector'] ?? null);
            $position = (string)($change['position'] ?? 'after');

            if ($action === 'insert') {
                $nodes = !empty($change['html']) ? $importFragment((string)$change['html']) : [];
                if (empty($nodes)) {
                    $warnings[] = empty($change['html']) ? 'insert_skipped_no_payload' : 'fragment_invalid';
                    continue;
                }
                $insertNodes($nodes, $idx !== null ? $sections[$idx] : null, $position);
            } elseif ($action === 'remove') {
                if ($idx !== null) {
                    $domBody->removeChild($sections[$idx]);
                } else {
                    $warnings[] = 'remove_skipped';
                }
            } elseif ($action === 'move') {
                $targetIdx = $resolveIndex($sections, $change['target_selector'] ?? null);
                if ($idx === null || $targetIdx === null || $idx === $targetIdx) {
                    $warnings[] = 'move_skipped';
                    continue;
                }
                $moved = $domBody->removeChild($sections[$idx]);
                $insertNodes([$moved], $sections[$targetIdx], $position);
            } elseif ($action === 'replace') {
                if ($idx === null) {
                    $warnings[] = 'replace_skipped';
                    continue;
                }
                $nodes = !empty($change['html']) ? $importFragment((string)$change['html']) : [];
                if (empty($nodes)) {
                    $warnings[] = 'replace_skipped_no_payload';
                    continue;
                }
                $insertNodes($nodes, $sections[$idx], 'before');
                $domBody->removeChild($sections[$idx]);
            }
        }

        $newContent = '';
        foreach ($domBody->childNodes as $child) {
            $newContent .= $dom->saveHTML($child);
        }
        return ['content' => $newContent, 'warnings' => $warnings];
    }

    /** Sichert Inhalt fail-soft vor einer destruktiven /files- oder /faq-Änderung. */
    private function backupContent(string $ref, string $content): void
    {
        try {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            Craft::$app->getDb()->createCommand()->insert('{{%deonai_content_backups}}', [
                'ref' => mb_substr($ref, 0, 255),
                'content' => $content,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            // Fail-soft: ein Backup-Fehler darf den eigentlichen Fix nie blockieren.
            Craft::warning('deon-ai-connect backup failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * POST /deon-ai/hygiene — robots.txt/llms.txt Inhalt setzen.
     * Body: { type ("robots"|"llms"), content }
     */
    public function actionSetHygiene(): Response
    {
        $this->requireDeonKey();
        if ($response = $this->checkPermission('files')) {
            return $response;
        }
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
            // Nur echte Hygiene-Typen — die Tabelle speichert seit v0.8.0 auch
            // site_schema/footer_links, die hier nichts verloren haben.
            ->where(['type' => ['robots', 'llms']])
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
                'section_template' => $this->rollbackSectionTemplate($log['targetKey'], $before),
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

        $section = $this->sectionsService()->getSectionByHandle($settings->blogSectionHandle);
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
        'section_template' => 'Section-Template',
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

    private function rollbackSectionTemplate(string $handle, ?array $before): array
    {
        $section = $this->sectionsService()->getSectionByHandle($handle);
        if (!$section) {
            return ['ok' => false, 'error' => 'section_no_longer_exists'];
        }
        $siteSettingsList = $section->getSiteSettings();
        foreach ($siteSettingsList as $s) {
            $s->template = $before['template'] ?? null;
        }
        $section->setSiteSettings($siteSettingsList);
        if (!$this->sectionsService()->saveSection($section)) {
            return ['ok' => false, 'error' => 'save_failed', 'details' => $section->getErrors()];
        }
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
            // slug fehlte hier bisher (im Gegensatz zum analogen rollbackEntry()) —
            // der Snapshot sicherte ihn zwar, ein Restore-Point-Restore stellte die
            // URL also nie wieder her, obwohl entries_restored fälschlich Erfolg meldete.
            if (!empty($entrySnapshot['slug'])) {
                $entry->slug = (string)$entrySnapshot['slug'];
            }
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

        // TOCTOU-/DNS-Rebinding-Schutz: die oben geprüfte IP wird für die TATSÄCHLICHE
        // Verbindung verwendet, statt den Hostnamen bei file_get_contents() erneut
        // auflösen zu lassen — sonst könnte ein Angreifer mit kurzer DNS-TTL zwischen
        // Check und Fetch auf eine private/interne IP umschwenken und die Prüfung
        // oben wäre wirkungslos. "Host"-Header + ssl.peer_name halten virtuelles
        // Hosting bzw. TLS-Zertifikatsprüfung trotzdem korrekt (validiert gegen den
        // echten Hostnamen, nicht gegen die IP).
        $authority = (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $pinnedUrl = $scheme . '://' . $authority
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        $context = stream_context_create([
            'http' => ['timeout' => 15, 'follow_location' => 0, 'header' => "Host: $host\r\n"],
            'ssl' => ['peer_name' => $host],
        ]);
        $bytes = @file_get_contents($pinnedUrl, false, $context, 0, $maxBytes + 1);
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

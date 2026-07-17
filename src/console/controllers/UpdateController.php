<?php

namespace deonai\craftconnect\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use deonai\craftconnect\Plugin;
use yii\console\ExitCode;

/**
 * Cron-Fallback für /deon-ai/self-update + /deon-ai/up, falls Composer im
 * Web-Request an exec/memory-Limits scheitert (Shared-Hosting-Realität).
 * Aufruf: php craft deon-ai-connect/update <version>
 */
class UpdateController extends Controller
{
    public function actionIndex(string $version): int
    {
        /** @var \deonai\craftconnect\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->allowSelfUpdate) {
            $this->stderr("Self-Update ist in den Plugin-Settings deaktiviert (\"Plugin automatisch aktualisieren\").\n");
            return ExitCode::CONFIG;
        }

        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
            $this->stderr("Ungültige Version: \"$version\" (erwartet z. B. \"0.6.1\").\n");
            return ExitCode::USAGE;
        }

        $current = Plugin::getInstance()->getVersion();
        if ($version === $current) {
            $this->stdout("Bereits auf Version $version.\n");
            return ExitCode::OK;
        }

        App::maxPowerCaptain();

        try {
            Craft::$app->getDb()->backup();
        } catch (\Throwable $e) {
            // Fail-soft: mysqldump/pg_dump per shell_exec ist auf manchem Hosting gesperrt.
            $this->stderr('Warnung: DB-Backup fehlgeschlagen (fahre trotzdem fort): ' . $e->getMessage() . "\n");
        }

        $this->stdout("Aktualisiere deon-ai/craft-connect von $current auf $version …\n");
        try {
            // Nur EIN Argument: Composer::install()'s zweiter Parameter ist in Craft 5
            // ein callable, in Craft 4 ein Composer\IO\IOInterface — inkompatible
            // Signaturen. Ohne zweites Argument funktioniert der Aufruf auf beiden.
            Craft::$app->getComposer()->install(['deon-ai/craft-connect' => '==' . $version]);
        } catch (\Throwable $e) {
            $this->stderr('Composer-Update fehlgeschlagen: ' . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Composer-Swap erfolgreich, führe Migrationen aus …\n");
        try {
            Craft::$app->getUpdates()->runMigrations(['deon-ai-connect']);
        } catch (\Throwable $e) {
            $this->stderr('Migration fehlgeschlagen: ' . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Erfolgreich aktualisiert auf ' . Plugin::getInstance()->getVersion() . ".\n");
        return ExitCode::OK;
    }
}

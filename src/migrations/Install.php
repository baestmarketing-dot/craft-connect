<?php

namespace deonai\craftconnect\migrations;

use craft\db\Migration;

/**
 * Install-Migration: legt den kompletten aktuellen Tabellenstand an.
 *
 * WICHTIG: Craft führt bei einer Neuinstallation NUR safeUp() hier aus und
 * markiert alle zu diesem Zeitpunkt bereits vorhandenen nummerierten
 * Migrationen als "bereits angewendet", OHNE deren safeUp() laufen zu
 * lassen (craft\base\Plugin::install()). Deshalb muss diese Datei immer den
 * vollständigen Schema-Stand enthalten, nicht nur den ursprünglichen —
 * sonst fehlen Neuinstallationen Tabellen, die nur über spätere
 * m*-Migrationen kämen.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%deonai_seo_overrides}}')) {
            $this->createTable('{{%deonai_seo_overrides}}', [
                'id' => $this->primaryKey(),
                'uri' => $this->string(500)->notNull(),
                'title' => $this->string(255),
                'metaDescription' => $this->string(500),
                'canonical' => $this->string(500),
                'schemaJson' => $this->text(),
                'enabled' => $this->boolean()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%deonai_seo_overrides}}', ['uri'], false);
        }

        if (!$this->db->tableExists('{{%deonai_seo_hygiene}}')) {
            $this->createTable('{{%deonai_seo_hygiene}}', [
                'id' => $this->primaryKey(),
                'type' => $this->string(20)->notNull(),
                'content' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%deonai_seo_hygiene}}', ['type'], true);
        }

        if (!$this->db->tableExists('{{%deonai_change_log}}')) {
            $this->createTable('{{%deonai_change_log}}', [
                'id' => $this->primaryKey(),
                'targetType' => $this->string(30)->notNull(),
                'targetKey' => $this->string(255)->notNull(),
                'beforeJson' => $this->text(),
                'afterJson' => $this->text()->notNull(),
                'note' => $this->string(500),
                'rolledBack' => $this->boolean()->defaultValue(false)->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%deonai_change_log}}', ['targetType', 'targetKey'], false);
        }

        if (!$this->db->tableExists('{{%deonai_content_backups}}')) {
            $this->createTable('{{%deonai_content_backups}}', [
                'id' => $this->primaryKey(),
                'ref' => $this->string(255)->notNull(),
                'content' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%deonai_content_backups}}', ['ref'], false);
        }

        if (!$this->db->tableExists('{{%deonai_landing_pages}}')) {
            $this->createTable('{{%deonai_landing_pages}}', [
                'id' => $this->primaryKey(),
                'slug' => $this->string(200)->notNull(),
                'title' => $this->string(255)->notNull(),
                'html' => $this->longText()->notNull(),
                'chrome' => $this->string(10)->defaultValue('full')->notNull(),
                'enabled' => $this->boolean()->defaultValue(false)->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%deonai_landing_pages}}', ['slug'], true);
        }

        if (!$this->db->tableExists('{{%deonai_section_tests}}')) {
            $this->createTable('{{%deonai_section_tests}}', [
                'id' => $this->primaryKey(),
                'testId' => $this->string(40)->notNull(),
                'originalId' => $this->integer()->notNull(),
                'variantId' => $this->integer()->notNull(),
                'name' => $this->string(255),
                'builder' => $this->string(20)->defaultValue('html')->notNull(),
                'status' => $this->string(20)->defaultValue('running')->notNull(),
                'winner' => $this->string(5),
                'sectionsChanges' => $this->text(),
                'warnings' => $this->text(),
                'visitorsA' => $this->integer()->defaultValue(0)->notNull(),
                'visitorsB' => $this->integer()->defaultValue(0)->notNull(),
                'conversionsA' => $this->integer()->defaultValue(0)->notNull(),
                'conversionsB' => $this->integer()->defaultValue(0)->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%deonai_section_tests}}', ['testId'], true);
            $this->createIndex(null, '{{%deonai_section_tests}}', ['originalId'], false);
        }

        if (!$this->db->tableExists('{{%deonai_ab_variants}}')) {
            $this->createTable('{{%deonai_ab_variants}}', [
                'id' => $this->primaryKey(),
                'variantId' => $this->string(40)->notNull(),
                'entryId' => $this->integer()->notNull(),
                'config' => $this->text()->notNull(),
                'status' => $this->string(20)->defaultValue('running')->notNull(),
                'winner' => $this->string(5),
                'visitorsA' => $this->integer()->defaultValue(0)->notNull(),
                'visitorsB' => $this->integer()->defaultValue(0)->notNull(),
                'conversionsA' => $this->integer()->defaultValue(0)->notNull(),
                'conversionsB' => $this->integer()->defaultValue(0)->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%deonai_ab_variants}}', ['variantId'], true);
            $this->createIndex(null, '{{%deonai_ab_variants}}', ['entryId'], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%deonai_ab_variants}}');
        $this->dropTableIfExists('{{%deonai_section_tests}}');
        $this->dropTableIfExists('{{%deonai_landing_pages}}');
        $this->dropTableIfExists('{{%deonai_content_backups}}');
        $this->dropTableIfExists('{{%deonai_change_log}}');
        $this->dropTableIfExists('{{%deonai_seo_hygiene}}');
        $this->dropTableIfExists('{{%deonai_seo_overrides}}');
        return true;
    }
}

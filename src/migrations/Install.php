<?php

namespace deonai\craftconnect\migrations;

use craft\db\Migration;

/**
 * Install-Migration: Tabelle für SEO-Overrides (Deon-AI-1-Klick-Fixes).
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
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%deonai_seo_overrides}}');
        return true;
    }
}

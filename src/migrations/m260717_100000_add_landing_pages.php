<?php

namespace deonai\craftconnect\migrations;

use craft\db\Migration;

/**
 * v0.8.0: Tabelle für Full-Page-Landingpages (/deon-ai/publish-lp).
 * Roh-HTML inkl. <style>/<script>, ausgeliefert über eine eigene Site-Route
 * pro Slug — analog zum _aideon_lp_html-Mechanismus des WordPress-Plugins.
 */
class m260717_100000_add_landing_pages extends Migration
{
    public function safeUp(): bool
    {
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
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%deonai_landing_pages}}');
        return true;
    }
}

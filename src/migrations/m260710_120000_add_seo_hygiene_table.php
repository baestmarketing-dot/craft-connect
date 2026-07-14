<?php

namespace deonai\craftconnect\migrations;

use craft\db\Migration;

/**
 * Fügt die Tabelle für robots.txt/llms.txt-Inhalte hinzu (Deon-AI-SEO-Hygiene).
 */
class m260710_120000_add_seo_hygiene_table extends Migration
{
    public function safeUp(): bool
    {
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
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%deonai_seo_hygiene}}');
        return true;
    }
}

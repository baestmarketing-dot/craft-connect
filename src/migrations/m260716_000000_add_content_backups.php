<?php

namespace deonai\craftconnect\migrations;

use craft\db\Migration;

/**
 * Fügt die Tabelle für Content-Backups hinzu (vor jeder /files- oder
 * /faq-Änderung wird der bisherige Inhalt hier gesichert).
 */
class m260716_000000_add_content_backups extends Migration
{
    public function safeUp(): bool
    {
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
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%deonai_content_backups}}');
        return true;
    }
}

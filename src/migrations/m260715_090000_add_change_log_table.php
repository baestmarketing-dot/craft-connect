<?php

namespace deonai\craftconnect\migrations;

use craft\db\Migration;

/**
 * Änderungsprotokoll für Rollback-fähige Deon-AI-Aktionen (SEO-Overrides,
 * Entries, robots.txt/llms.txt). Der Vorher-Zustand wird atomar mit jeder
 * Änderung mitgespeichert — kein separater Backup-Schritt, der ausfallen
 * könnte.
 */
class m260715_090000_add_change_log_table extends Migration
{
    public function safeUp(): bool
    {
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
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%deonai_change_log}}');
        return true;
    }
}

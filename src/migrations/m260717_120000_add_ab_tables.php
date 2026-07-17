<?php

namespace deonai\craftconnect\migrations;

use craft\db\Migration;

/**
 * v0.9.0: Section-Tests (Entry-Klon als Variante, Server-Cookie-Split) und
 * A/B-Varianten (Selector-basierte Frontend-Änderungen per JS-Snippet) —
 * Craft-natives Pendant zu den WP-Postmetas _aideon_section_tests /
 * _aideon_ab_variants.
 */
class m260717_120000_add_ab_tables extends Migration
{
    public function safeUp(): bool
    {
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
        return true;
    }
}

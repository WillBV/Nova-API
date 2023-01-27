<?php

namespace nova\utilities\database\migrations;

use nova\Nova;

class M220614171406BaseTableSetup
{
    /**
     * This method contains the logic to be executed when applying this migration.
     *
     * @return boolean
     */
    public function up(): bool {
        Nova::$db->createTable("auth_tokens", [
            "token_id" => "char(36) NOT NULL",
            "token"    => "text NOT NULL",
            "expiry"   => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            "meta"     => "longtext"
        ]);
        Nova::$db->createTable("idempotent_requests", [
            "idempotency_key" => "char(36) NOT NULL",
            "route"           => "longtext NOT NULL",
            "body"            => "longtext NOT NULL",
            "headers"         => "longtext",
            "status_code"     => "varchar(20) NOT NULL",
            "expiry"          => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            "meta"            => "longtext"
        ]);
        Nova::$db->createTable("info", [
            "id"           => "int NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "name"         => "varchar(255)",
            "value"        => "longtext",
            "date_created" => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            "date_updated" => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            "meta"         => "longtext"
        ]);
        Nova::$db->createTable("migrations", [
            "migration_id"    => "int NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "migration_name"  => "varchar(255) NOT NULL",
            "migration_batch" => "int NOT NULL",
            "date_applied"    => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            "meta"            => "longtext"
        ]);
        Nova::$db->addPrimaryKey("auth_tokens", "token_id");
        Nova::$db->addPrimaryKey("idempotent_requests", "idempotency_key");
        Nova::$db->addPrimaryKey("info", "id");
        Nova::$db->addPrimaryKey("migrations", "migration_id");
        Nova::$db->createIndex("info", "name");
        Nova::$db->createIndexes("migrations", [
            "migration_batch" => FALSE,
            "migration_name"  => TRUE
        ]);
        return TRUE;
    }

    /**
     * This method contains the logic to be executed when removing this migration.
     *
     * @return boolean
     */
    public function down(): bool {
        // Remove & add logic to revert the migration here.
        echo "M220614171406BaseTableSetup cannot be rolled back.\n";
        return FALSE;
    }
}

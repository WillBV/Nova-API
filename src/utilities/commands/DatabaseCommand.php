<?php

namespace nova\utilities\commands;

use nova\Nova;
use nova\models\MigrationModel;

/**
 * Database
 *
 * Performs Database operations.
 */
class DatabaseCommand
{
    public $requiresDb = TRUE;

    /**
     * Creates a new migration template.
     *
     * @param string $logResponse The response to be logged.
     *
     * @return void
     */
    public function newMigrationCommand(string &$logResponse): void {
        $migrationId   = "m" . (new \DateTime())->format("ymdHis") . " ";
        $migrationText = strtolower(readline("Migration name: "));
        $migrationName = preg_replace("^\s^", "", ucwords($migrationId . $migrationText));
        $migrationPath = BASE_PATH . "/src/utilities/database/migrations/{$migrationName}.php";
        $success       = strtolower(readline("Create new migration '{$migrationPath}'? (y|n): "));
        if ($success === "y") {
            $migrationTemplate = "<?php\n\n" .
            "namespace nova\utilities\database\migrations;\n\n" .
            "use nova\Nova;\n\n" .
            "class {$migrationName}\n" .
            "{\n" .
            "\t/**\n" .
            "\t * This method contains the logic to be executed when applying this migration.\n" .
            "\t *\n" .
            "\t * @return boolean\n" .
            "\t */\n" .
            "\tpublic function up(): bool {\n" .
            "\t\t// Add migration logic here.\n" .
            "\t\treturn true;\n" .
            "\t}\n\n" .
            "\t/**\n" .
            "\t * This method contains the logic to be executed when removing this migration.\n" .
            "\t *\n" .
            "\t * @return boolean\n" .
            "\t */\n" .
            "\tpublic function down(): bool {\n" .
            "\t\t// Remove & add logic to revert the migration here.\n" .
            "\t\techo \"{$migrationName} cannot be rolled back.\\n\";\n" .
            "\t\treturn false;\n" .
            "\t}\n" .
            "}";
            file_put_contents($migrationPath, $migrationTemplate);
            $logResponse = "Migration file created.\n";
            echo $logResponse;
        }
    }

    /**
     * Runs all database migrations.
     *
     * @param string $logResponse The response to be logged.
     *
     * @return void
     */
    public function migrateCommand(string &$logResponse): void {
        $processedMigrations = [];
        if (Nova::$db->tableExists("migrations")) {
            $processedMigrations = Nova::$db
                ->select(["migrations" => ["migration_name", "migration_batch"]])
                ->from("migrations")
                ->all();
        }
        $batch          = $processedMigrations ? max(array_column($processedMigrations, "migration_batch")) + 1 : 1;
        $migrationFiles = glob("src/utilities/database/migrations/M*");
        $newMigrations  = [];
        foreach ($migrationFiles as $filePath) {
            $migration     = substr($filePath, strrpos($filePath, '/') + 1, -4);
            $migrationPath = "nova\utilities\database\migrations\\{$migration}";
            if (array_search($migration, array_column($processedMigrations, "migration_name")) === FALSE) {
                $newMigrations[$migration] = $migrationPath;
            }
        }
        if ($newMigrations) {
            foreach ($newMigrations as $migration => $class) {
                $migrationClass = new $class();
                $start          = microtime(TRUE);
                $success        = $migrationClass->up();
                $elapsed        = round(microtime(TRUE) - $start, 2);
                if ($success) {
                    $migrationModel                 = new MigrationModel();
                    $migrationModel->migrationName  = $migration;
                    $migrationModel->migrationBatch = $batch;
                    $migrationModel->create();
                    $response = "Applied {$migration} in {$elapsed} secs\n";
                    echo $response;
                    $logResponse .= $response;
                } else {
                    throw new \Exception("Migration Error in: {$migration}", 1);
                }
            }
            $returnMessage = "All Migrations Completed Successfully\n";
        } else {
            $returnMessage = "No new migrations found.\n";
        }
        echo $returnMessage;
        $logResponse .= $returnMessage;
    }

    /**
     * Rolls back the latest migration batch.
     *
     * @param string $logResponse The response to be logged.
     *
     * @return void
     */
    public function rollBackLatestCommand(string &$logResponse): void {
        $latestMigrations = [];
        if (Nova::$db->tableExists("migrations")) {
            $latestMigrations = Nova::$db
                ->select(["m1" => ["migration_name"]])
                ->from("migrations m1")
                ->innerJoin(
                    "(SELECT migration_batch batch FROM migrations ORDER BY migration_batch DESC LIMIT 1) m2",
                    ["m1.migration_batch" => "m2.batch"]
                )
                ->orderBy(["migration_id" => "DESC"])
                ->columnAll();
        }
        foreach ($latestMigrations as $migration) {
            $classPath      = "nova\utilities\database\migrations\\{$migration}";
            $migrationClass = new $classPath();
            $start          = microtime(TRUE);
            $success        = $migrationClass->down();
            $elapsed        = round(microtime(TRUE) - $start, 2);
            if ($success) {
                $migrationModel = (new MigrationModel())->find($migration);
                $migrationModel->delete();
                $response     = "Rolled Back {$migration} in {$elapsed} secs\n";
                $logResponse .= $response;
            } else {
                $response     = "Migration Roll Back Error in: {$migration}";
                $logResponse .= $response;
                echo "{$response}\n";
                Nova::log($response, "error");
            }
        }
    }
}

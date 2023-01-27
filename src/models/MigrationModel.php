<?php

namespace nova\models;

use nova\Nova;
use nova\utilities\Utilities;

class MigrationModel
{
    public $migrationId;
    public $migrationName;
    public $migrationBatch;
    public $dateApplied;
    public $meta;

    /**
     * Formats the model into an array output.
     *
     * @return array
     */
    public function format(): array {
        $data = [];
        if ($this->migrationId) {
            $data = [
                "migrationId"    => $this->migrationId,
                "migrationName"  => $this->migrationName,
                "migrationBatch" => $this->migrationBatch,
                "dateApplied"    => $this->dateApplied,
                "meta"           => json_decode($this->meta, TRUE)
            ];
        }
        return $data;
    }

    /**
     * Populate the model with the given database record data.
     *
     * @param array $record The database record data.
     *
     * @return self
     */
    public function populateModel(array $record): self {
        $this->migrationId    = $record["migration_id"] ?? NULL;
        $this->migrationName  = $record["migration_name"] ?? NULL;
        $this->migrationBatch = $record["migration_batch"] ?? NULL;
        $this->dateApplied    = $record["date_applied"] ?? NULL;
        $this->meta           = $record["meta"] ?? NULL;
        extract($this->validate());
        if (!$valid) {
            $exception         = new \Exception("Bad Request", 400);
            $exception->detail = $errors['detail'];
            throw $exception;
        }
        return $this;
    }

    /**
     * Populate an array of models for multiple database records.
     *
     * @param array $records An array of database records.
     *
     * @return array
     */
    public function populateModels(array $records): array {
        $models = [];
        foreach ($records as $record) {
            $models[] = (new self())->populateModel($record);
        }
        return $models;
    }
    
    /**
     * Find a record from the primary key
     *
     * @param string $primaryKey Primary Key to search by.
     *
     * @return self
     */
    public function find(string $primaryKey): self {
        $migration = Nova::$db
            ->select()
            ->from("migrations")
            ->where(["=", 'migration_name'], ['migration_name' => $primaryKey])
            ->one();

        if ($migration) {
            $this->migrationId    = $migration["migration_id"];
            $this->migrationName  = $migration["migration_name"];
            $this->migrationBatch = $migration["migration_batch"];
            $this->dateApplied    = $migration["date_applied"];
            $this->meta           = $migration["meta"];
        }
        return $this;
    }

    /**
     * Create a new row in the database based off the current model.
     *
     * @return array
     */
    public function create(): array {
        $values = [
            "migration_name"  => $this->migrationName,
            "migration_batch" => $this->migrationBatch,
            "meta"            => $this->meta
        ];
        extract($this->validate());
        if ($valid) {
            try {
                Nova::$db
                    ->insert('migrations', $values)
                    ->execute();
            } catch (\Throwable $e) {
                $errors = Utilities::parseException($e);
            }
        }
        $response = [
            'valid'  => $errors ? FALSE : TRUE,
            'errors' => $errors
        ];
        return $response;
    }

    /**
     * Updates the model's matching DB record
     *
     * @return array
     */
    public function save(): array {
        $values = [
            "migration_name"  => $this->migrationName,
            "migration_batch" => $this->migrationBatch,
            "meta"            => $this->meta
        ];
        extract($this->validate());
        if ($valid) {
            try {
                Nova::$db
                    ->update('migrations', $values)
                    ->where(["=", 'migration_id'], ['migration_id' => $this->migrationId])
                    ->execute();
            } catch (\Throwable $e) {
                $errors = Utilities::parseException($e);
            }
        }
        $response = [
            'valid'  => $errors ? FALSE : TRUE,
            'errors' => $errors
        ];
        return $response;
    }

    /**
     * Deletes the model's matching DB record
     *
     * @return array
     */
    public function delete(): array {
        extract($this->validate());
        if ($valid) {
            try {
                Nova::$db
                    ->delete()
                    ->from('migrations')
                    ->where(["=", 'migration_name'], ['migration_name' => $this->migrationName])
                    ->execute();
            } catch (\Throwable $e) {
                $errors = Utilities::parseException($e);
            }
        }
        $response = [
            'valid'  => $errors ? FALSE : TRUE,
            'errors' => $errors
        ];
        return $response;
    }
    /**
     * Define and run validation rules against the model
     *
     * @return array
     */
    public function validate(): array {
        $properties  = get_object_vars($this);
        $validFormat = [
            "migrationId"    => [
                "type" => "numeric"
            ],
            "migrationName"  => [
                "required" => TRUE,
                "type"     => "string"
            ],
            "migrationBatch" => [
                "required" => TRUE,
                "type"     => "numeric"
            ],
            "dateApplied"    => [
                "type"  => "string",
                "regex" => Utilities::DATE_TIME_REGEX
            ],
            "meta"           => []
        ];
        $errors      = Nova::$db->validateModel($properties, $validFormat);
        $valid       = [
            'valid'  => $errors ? FALSE : TRUE,
            'errors' => $errors
        ];
        return $valid;
    }
}

<?php

namespace nova\models;

use nova\Nova;
use nova\utilities\Utilities;

class InfoModel
{
    public $id;
    public $name;
    public $value;
    public $dateCreated;
    public $dateUpdated;
    public $meta;

    /**
     * Formats the model into an array output.
     *
     * @return array
     */
    public function format(): array {
        $data = [];
        if ($this->id) {
            $data = [
                "id"          => $this->id,
                "name"        => $this->name,
                "value"       => $this->value,
                "dateCreated" => $this->dateCreated,
                "dateUpdated" => $this->dateUpdated,
                "meta"        => json_decode($this->meta, TRUE)
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
        $this->id          = $record["id"] ?? NULL;
        $this->name        = $record["name"] ?? NULL;
        $this->value       = $record["value"] ?? NULL;
        $this->dateCreated = $record["date_created"] ?? NULL;
        $this->dateUpdated = $record["date_updated"] ?? NULL;
        $this->meta        = $record["meta"] ?? NULL;
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
        $info = Nova::$db
            ->select()
            ->from("info")
            ->where(["=", 'name'], ['name' => $primaryKey])
            ->one();

        if ($info) {
            $this->id          = $info["id"];
            $this->name        = $info["name"];
            $this->value       = $info["value"];
            $this->dateCreated = $info["date_created"];
            $this->dateUpdated = $info["date_updated"];
            $this->meta        = $info["meta"];
        }
        return $this;
    }

    /**
     * Create a new row in the database based off the current model.
     *
     * @return array
     */
    public function create(): array {
        $dateTime = (new \DateTime())->format("Y-m-d H:i:s");
        $values   = [
            "name"         => $this->name,
            "value"        => $this->value,
            "date_created" => $dateTime,
            "meta"         => $this->meta
        ];
        extract($this->validate());
        if ($valid) {
            try {
                Nova::$db
                    ->insert('info', $values)
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
        $dateTime = (new \DateTime())->format("Y-m-d H:i:s");
        $values   = [
            "name"         => $this->name,
            "value"        => $this->value,
            "date_updated" => $dateTime,
            "meta"         => $this->meta
        ];
        extract($this->validate());
        if ($valid) {
            try {
                Nova::$db
                    ->update('info', $values)
                    ->where(["=", 'id'], ['id' => $this->id])
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
                    ->from('info')
                    ->where(["=", 'id'], ['id' => $this->id])
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
            "id"          => [
                "type" => "numeric"
            ],
            "name"        => [
                "required" => TRUE,
                "type"     => "string"
            ],
            "value"       => [
                "required" => TRUE,
                "type"     => "string"
            ],
            "dateCreated" => [],
            "dateUpdated" => [],
            "meta"        => []
        ];
        $errors      = Nova::$db->validateModel($properties, $validFormat);
        $valid       = [
            'valid'  => $errors ? FALSE : TRUE,
            'errors' => $errors
        ];
        return $valid;
    }
}

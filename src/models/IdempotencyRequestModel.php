<?php

namespace nova\models;

use nova\Nova;
use nova\utilities\Utilities;

class IdempotencyRequestModel
{
    public $idempotencyKey;
    public $route;
    public $body;
    public $headers = '';
    public $statusCode;
    public $expiry;
    public $meta;

    /**
     * Formats the model into an array output.
     *
     * @return array
     */
    public function format(): array {
        $data = [];
        if ($this->idempotencyKey) {
            $data = [
                "idempotencyKey" => $this->idempotencyKey,
                "route"          => $this->route,
                "body"           => json_decode($this->body, TRUE),
                "headers"        => json_decode($this->headers, TRUE),
                "statusCode"     => $this->statusCode,
                "expiry"         => $this->expiry,
                "meta"           => json_decode($this->meta, TRUE)
            ];
        }
        return $data;
    }

    /**
     * Find a record from the primary key
     *
     * @param string $primaryKey Primary Key to search by.
     *
     * @return self
     */
    public function find(string $primaryKey): self {
        $account = Nova::$db
            ->select()
            ->from("idempotent_requests")
            ->where(["=", 'idempotency_key'], ['idempotency_key' => $primaryKey])
            ->one();

        if ($account) {
            $this->idempotencyKey = $account["idempotency_key"];
            $this->route          = $account["route"];
            $this->body           = json_decode($account["body"]);
            $this->headers        = json_decode($account["headers"]);
            $this->statusCode     = $account["status_code"];
            $this->expiry         = $account["expiry"];
            $this->meta           = $account["meta"];
        }
        return $this;
    }

    /**
     * Populate the model with the given database record data.
     *
     * @param array $record The database record data.
     *
     * @return self
     */
    public function populateModel(array $record): self {
        $this->idempotencyKey = $record["idempotency_key"] ?? NULL;
        $this->route          = $record["route"] ?? NULL;
        $this->body           = $record["body"] ?? NULL;
        $this->headers        = $record["headers"] ?? NULL;
        $this->statusCode     = $record["status_code"] ?? NULL;
        $this->expiry         = $record["expiry"] ?? NULL;
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
     * Create a new row in the database based off the current model.
     *
     * @return array
     */
    public function create(): array {
        $values = [
            "idempotency_key" => $this->idempotencyKey,
            "route"           => $this->route,
            "body"            => $this->body,
            "headers"         => $this->headers,
            "status_code"     => $this->statusCode,
            "expiry"          => $this->expiry,
            "meta"            => $this->meta
        ];
        extract($this->validate());
        if ($valid) {
            try {
                Nova::$db
                    ->insert('idempotent_requests', $values)
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
            "idempotency_key" => $this->idempotencyKey,
            "route"           => $this->route,
            "body"            => $this->body,
            "headers"         => $this->headers,
            "status_code"     => $this->statusCode,
            "expiry"          => $this->expiry,
            "meta"            => $this->meta
        ];
        extract($this->validate());
        if ($valid) {
            try {
                Nova::$db
                    ->update('idempotent_requests', $values)
                    ->where(["=", "idempotency_key"], ["idempotency_key" => $this->idempotencyKey])
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
                    ->from('idempotent_requests')
                    ->where(["=", "idempotency_key"], ["idempotency_key" => $this->idempotencyKey])
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
            "idempotencyKey" => [
                "required" => TRUE,
                "type"     => "string",
                "length"   => "36"
            ],
            "route"          => [
                "type" => "string"
            ],
            "body"           => [
                "required" => TRUE,
                "type"     => "string"
            ],
            "headers"        => [
                "type" => "string"
            ],
            "statusCode"     => [
                "required"  => TRUE,
                "type"      => "string",
                "minLength" => "3",
                "maxLength" => "20"
            ],
            "expiry"         => [
                "required" => TRUE,
                "type"     => "string",
                "regex"    => Utilities::DATE_TIME_REGEX
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

<?php

namespace nova\models;

interface ModelInterface
{
    /**
     * Formats the model into an array output.
     *
     * @return array
     */
    public function format(): array;

    /**
     * Find a record from the primary key
     *
     * @param string $primaryKey Primary Key to search by.
     *
     * @return self
     */
    public function find(string $primaryKey): self;

    /**
     * Create a new row in the database based off the current model.
     *
     * @return array
     */
    public function create(): array;

    /**
     * Updates the model's matching DB record
     *
     * @return array
     */
    public function save(): array;

    /**
     * Deletes the model's matching DB record
     *
     * @return array
     */
    public function delete(): array;

    /**
     * Define and run validation rules against the model
     *
     * @return array
     */
    public function validate(): array;
}

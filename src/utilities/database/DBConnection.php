<?php

namespace nova\utilities\database;

use nova\Nova;
use nova\utilities\Utilities;

class DBConnection
{
    public static $instance;
    public $connection;
    private $select                   = [];
    private $distinct                 = FALSE;
    private $delete                   = FALSE;
    private $insert                   = FALSE;
    private $update                   = FALSE;
    private $from                     = FALSE;
    private $join                     = [];
    private $where                    = [];
    private $and                      = [];
    private $or                       = [];
    private $order                    = [];
    private $group                    = [];
    private $limit                    = FALSE;
    private $offset                   = FALSE;
    private $parameters               = [];
    private $resultType               = FALSE;
    private $rawSql                   = "";
    private const COND_MATCH_ONE      = [
        "and",
        "or"
    ];
    private const COND_MATCH_TWO      = [
        "in",
        "not in"
    ];
    private const COND_MATCH_THREE    = [
        "like",
        "not like"
    ];
    private const REFERENTIAL_ACTIONS = [
        "RESTRICT",
        "CASCADE",
        "NO ACTION",
        "SET DEFAULT",
        "SET NULL"
    ];

    /**
     * Construct a new Database instance
     *
     * @param string $host     The database host.
     * @param string $database The database name.
     * @param string $dbun     The database username.
     * @param string $dbpwd    The database password.
     */
    private function __construct(string $host, string $database, string $dbun, string $dbpwd) {
        try {
            $db = new \PDO(
                "mysql:host={$host};
                dbname={$database};
                charset=utf8",
                $dbun,
                $dbpwd,
                [
                    \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
                    \PDO::ATTR_PERSISTENT       => FALSE
                ]
            );
            $db->setAttribute(
                \PDO::ATTR_ERRMODE,
                \PDO::ERRMODE_EXCEPTION
            );
            $this->connection = $db;
        } catch (\PDOException $pdo) {
            throw $pdo;
        }
    }
    
    /**
     * Connect to a database
     *
     * @param string $host     Database IP address \ host name.
     * @param string $database Database name.
     * @param string $dbun     Database username.
     * @param string $dbpwd    Database password.
     *
     * @return self
     */
    public static function connect(string $host, string $database, string $dbun, string $dbpwd): self {
        if (self::$instance == NULL) {
            self::$instance = new DBConnection($host, $database, $dbun, $dbpwd);
        }
        return self::$instance;
    }

    /**
     * Checks if a transaction is currently active.
     *
     * @return boolean
     */
    public function inTransaction(): bool {
        return $this->connection->inTransaction();
    }

    /**
     * Initiates a transaction.
     *
     * @return self
     */
    public function beginTransaction(): self {
        if (!$this->inTransaction()) {
            $attempts = 0;
            while (TRUE) {
                try {
                    $this->connection->beginTransaction();
                    break;
                } catch (\Exception $e) {
                    $parsed = Utilities::parseException($e);
                    if ($attempts < 3) {
                        $attempts++;
                        continue;
                    }
                    Nova::log(
                        "---MySQL Failed Query--- " . json_encode($parsed),
                        "error"
                    );
                    $this->resetConnection();
                    throw $e;
                }
            }
        }
        return $this;
    }

    /**
     * Commits a transaction.
     *
     * @return self
     */
    public function commit(): self {
        if ($this->inTransaction()) {
            $this->connection->commit();
        }
        $this->resetConnection();
        return $this;
    }

    /**
     * Rolls back a transaction.
     *
     * @return self
     */
    public function rollBack(): self {
        if ($this->inTransaction()) {
            $this->connection->rollBack();
        }
        $this->resetConnection();
        return $this;
    }

    /**
     * Boolean check to verify DB connection.
     *
     * @return boolean
     */
    public function isConnected(): bool {
        $connected = (bool)$this->connection;
        return $connected;
    }

    /**
     * Check whether a table exists.
     *
     * @param string $table The table name to check for.
     *
     * @return boolean
     */
    public function tableExists(string $table): bool {
        return $this->select()
            ->from("INFORMATION_SCHEMA.TABLES")
            ->where(["=", "table_schema"], ["table_schema" => $_ENV["DB_DATABASE"]])
            ->andWhere(["=", "table_name"], ["table_name" => $table])
            ->exists();
    }

    /**
     * Create a new table.
     * The columns array should be in the format key=name, value=type e.g.
     * ["username" => "varchar(255)"]
     *
     * @param string  $table       The name of the new table.
     * @param array   $columns     An array of the tables columns.
     * @param boolean $ifNotExists Flag to add the new table only if it doesn't currently exist.
     *
     * @return boolean
     */
    public function createTable(string $table, array $columns, bool $ifNotExists = TRUE): bool {
        $cols = [];
        foreach ($columns as $name => $type) {
            $cols[] = $name . " " . $type;
        }
        $sql = "CREATE TABLE " . ($ifNotExists ? "IF NOT EXISTS " : "") . "{$table} (" . implode(", ", $cols) . ")";
        try {
            $response = ($this->setRawSQL($sql)->execute()) === 0;
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }

    /**
     * Rename a table.
     *
     * @param string $oldName The current table name.
     * @param string $newName The new table name.
     *
     * @return boolean
     */
    public function renameTable(string $oldName, string $newName): bool {
        $sql = "RENAME TABLE {$oldName} TO {$newName}";
        try {
            $response = ($this->setRawSQL($sql)->execute()) === 0;
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }

    /**
     * Drop a table.
     *
     * @param string $table The name of the table to drop.
     *
     * @return boolean
     */
    public function dropTable(string $table): bool {
        $sql = "DROP TABLE {$table}";
        try {
            $response = ($this->setRawSQL($sql)->execute()) === 0;
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }

    /**
     * Check whether a column exists on a table.
     *
     * @param string $table  The table to check for the column.
     * @param string $column The column name to check for.
     *
     * @return boolean
     */
    public function columnExists(string $table, string $column): bool {
        return $this->select()
            ->from("INFORMATION_SCHEMA.COLUMNS")
            ->where(["=", "table_schema"], ["table_schema" => $_ENV["DB_DATABASE"]])
            ->andWhere(["=", "table_name"], ["table_name" => $table])
            ->andWhere(["=", "column_name"], ["column_name" => $column])
            ->exists();
    }

    /**
     * Rename a table column.
     *
     * @param string $table   The table to rename the column on.
     * @param string $oldName The current column name.
     * @param string $newName The new column name.
     *
     * @return boolean
     */
    public function renameColumn(string $table, string $oldName, string $newName): bool {
        $sql = "ALTER TABLE {$table} RENAME COLUMN {$oldName} TO {$newName}";
        try {
            $response = ($this->setRawSQL($sql)->execute()) === 0;
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }

    /**
     * Add a new column to a table.
     *
     * @param string  $table       The table to add a new column to.
     * @param string  $column      The new column name.
     * @param string  $type        The new column type.
     * @param boolean $ifNotExists Flag to add the new column only if it doesn't currently exist.
     *
     * @return boolean
     */
    public function addColumn(string $table, string $column, string $type, bool $ifNotExists = TRUE): bool {
        $response = FALSE;
        $sql      = "ALTER TABLE {$table} ADD {$column} {$type}";
        try {
            if (($ifNotExists && !$this->columnExists($table, $column) || !$ifNotExists)) {
                $response = $this->setRawSQL($sql)->execute() === 0;
            }
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }

    /**
     * Drop a column from a table.
     *
     * @param string $table  The table to drop a column from.
     * @param string $column The column to drop.
     *
     * @return boolean
     */
    public function dropColumn(string $table, string $column): bool {
        $sql = "ALTER TABLE {$table} DROP COLUMN {$column}";
        try {
            $response = ($this->setRawSQL($sql)->execute()) === 0;
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }

    /**
     * Find the primary key column on a given table.
     *
     * @param string $table The table to check.
     *
     * @return string
     */
    public function findPrimaryKey(string $table): string {
        return $this->select(["INFORMATION_SCHEMA.COLUMNS" => ["column_name"]])
            ->from("INFORMATION_SCHEMA.COLUMNS")
            ->where(["=", "table_schema"], ["table_schema" => $_ENV["DB_DATABASE"]])
            ->andWhere(["=", "table_name"], ["table_name" => $table])
            ->andWhere(["=", "column_key"], ["column_key" => "PRI"])
            ->column();
    }

    /**
     * Check whether a primary key exists on the given table column.
     *
     * @param string $table  The table to check.
     * @param string $column The column to check.
     *
     * @return boolean
     */
    public function primaryKeyExists(string $table, string $column = NULL): bool {
        $primaryKey = $this->findPrimaryKey($table);
        return $column ? $primaryKey == $column : $primaryKey;
    }

    /**
     * Add a primary key to a table column.
     *
     * @param string $table  The table to add the primary key to.
     * @param string $column The column to add the primary key to.
     *
     * @return boolean
     */
    public function addPrimaryKey(string $table, string $column): bool {
        $response = FALSE;
        if (!$this->primaryKeyExists($table, $column)) {
            $sql = "ALTER TABLE {$table} ADD PRIMARY KEY ({$column})";
            try {
                $response = ($this->setRawSQL($sql)->execute()) === 0;
            } catch (\Exception $exception) {
                $response = FALSE;
            }
        }
        return $response;
    }
    
    /**
     * Drop the primary key on a table.
     *
     * @param string $table The table to drop the primary key from.
     *
     * @return boolean
     */
    public function dropPrimaryKey(string $table): bool {
        $sql = "ALTER TABLE {$table} DROP PRIMARY KEY";
        try {
            $response = ($this->setRawSQL($sql)->execute()) === 0;
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }
    
    /**
     * Find the name of a foreign key based on given tables & columns.
     *
     * @param string $table     The child table to check.
     * @param string $column    The child column to check.
     * @param string $refTable  The parent table to check.
     * @param string $refColumn The parent column to check.
     *
     * @return string
     */
    public function findForeignKey(string $table, string $column, string $refTable, string $refColumn): string {
        return $this->select([
            "tc" => ["CONSTRAINT_NAME"]
        ])
            ->from("INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc")
            ->leftJoin("INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu", ["tc.CONSTRAINT_NAME" => "kcu.CONSTRAINT_NAME"])
            ->where(["=", "CONSTRAINT_TYPE"], ["CONSTRAINT_TYPE" => "FOREIGN KEY"])
            ->andWhere(["=", "tableSchema" => "tc.table_schema"], ["tableSchema" => $_ENV["DB_DATABASE"]])
            ->andWhere(["=", "tableName" => "tc.table_name"], ["tableName" => $table])
            ->andWhere(["=", "columnName" => "kcu.column_name"], ["columnName" => $column])
            ->andWhere(["=", "refTableName" => "kcu.referenced_table_name"], ["refTableName" => $refTable])
            ->andWhere(["=", "refColumnName" => "kcu.referenced_column_name"], ["refColumnName" => $refColumn])
            ->column();
    }
    
    /**
     * Find all foreign keys on the given table & column.
     *
     * @param string $table  The table to find foreign keys on.
     * @param string $column The optional column on the table to check for foreign keys.
     *
     * @return array
     */
    public function findForeignKeys(string $table, string $column = NULL): array {
        $foreignKeys = [];
        $query       = $this->select([
            "tc"  => ["CONSTRAINT_NAME"],
            "kcu" => [
                "COLUMN_NAME",
                "REFERENCED_TABLE_NAME",
                "REFERENCED_COLUMN_NAME"
            ]
        ])
            ->from("INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc")
            ->leftJoin("INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu", ["tc.CONSTRAINT_NAME" => "kcu.CONSTRAINT_NAME"])
            ->where(["=", "CONSTRAINT_TYPE"], ["CONSTRAINT_TYPE" => "FOREIGN KEY"])
            ->andWhere(["=", "tableSchema" => "tc.table_schema"], ["tableSchema" => $_ENV["DB_DATABASE"]])
            ->andWhere(["=", "tableName" => "tc.table_name"], ["tableName" => $table]);
        if ($column) {
            $query->andWhere(["=", "columnName" => "kcu.column_name"], ["columnName" => $column]);
        }
        foreach ($query->all() as $key) {
            $foreignKeys[$key['CONSTRAINT_NAME']] = [
                "table"     => $table,
                "column"    => $key['COLUMN_NAME'],
                "refTable"  => $key['REFERENCED_TABLE_NAME'],
                "refColumn" => $key['REFERENCED_COLUMN_NAME']
            ];
        }
        return $foreignKeys;
    }
    
    /**
     * Find all foreign keys pointing to the given table & column.
     *
     * @param string $table  The table to find foreign keys pointing to.
     * @param string $column The optional column on the table to check for foreign keys.
     *
     * @return array
     */
    public function findForeignKeysTo(string $table, string $column = NULL): array {
        $foreignKeys = [];
        $query       = $this->select([
            "tc"  => ["CONSTRAINT_NAME"],
            "kcu" => [
                "COLUMN_NAME",
                "REFERENCED_TABLE_NAME",
                "REFERENCED_COLUMN_NAME"
            ]
        ])
            ->from("INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc")
            ->leftJoin("INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu", ["tc.CONSTRAINT_NAME" => "kcu.CONSTRAINT_NAME"])
            ->where(["=", "CONSTRAINT_TYPE"], ["CONSTRAINT_TYPE" => "FOREIGN KEY"])
            ->andWhere(["=", "tableSchema" => "tc.table_schema"], ["tableSchema" => $_ENV["DB_DATABASE"]])
            ->andWhere(["=", "tableName" => "kcu.referenced_table_name"], ["tableName" => $table]);
        if ($column) {
            $query->andWhere(["=", "columnName" => "kcu.column_name"], ["columnName" => $column]);
        }
        foreach ($query->all() as $key) {
            $foreignKeys[$key["CONSTRAINT_NAME"]] = [
                "table"     => $table,
                "column"    => $key["COLUMN_NAME"],
                "refTable"  => $key["REFERENCED_TABLE_NAME"],
                "refColumn" => $key["REFERENCED_COLUMN_NAME"]
            ];
        }
        return $foreignKeys;
    }
    
    /**
     * Check whether a foreign key exists on the given table columns.
     *
     * @param string $table     The child table to check.
     * @param string $column    The child column to check.
     * @param string $refTable  The parent table to check.
     * @param string $refColumn The parent column to check.
     *
     * @return boolean
     */
    public function foreignKeyExists(string $table, string $column, string $refTable, string $refColumn): bool {
        return (bool)$this->findForeignKey($table, $column, $refTable, $refColumn);
    }
    
    /**
     * Add a foreign key link between two tables.
     *
     * @param string $table     The child table to add the foreign key to.
     * @param string $column    The child column to be the foreign key.
     * @param string $refTable  The parent table to link the foreign key to.
     * @param string $refColumn The parent column primary key to link to.
     * @param string $delete    The referential action to be taken on a delete clause.
     * @param string $update    The referential action to be taken on a update clause.
     *
     * @return boolean
     */
    public function addForeignKey(
        string $table,
        string $column,
        string $refTable,
        string $refColumn,
        string $delete = NULL,
        string $update = NULL
    ): bool {
        $response = FALSE;
        if (!$this->foreignKeyExists($table, $column, $refTable, $refColumn)) {
            $fkName = "FK_" . strtolower(implode("_", [$table, $refTable]));
            $sql    = "ALTER TABLE {$table} " .
                "ADD CONSTRAINT {$fkName} " .
                "FOREIGN KEY ({$column}) REFERENCES {$refTable}({$refColumn})";
            if ($delete !== NULL && in_array(strtoupper($delete), self::REFERENTIAL_ACTIONS)) {
                $sql .= " ON DELETE " . $delete;
            }
            if ($update !== NULL && in_array(strtoupper($update), self::REFERENTIAL_ACTIONS)) {
                $sql .= " ON UPDATE " . $update;
            }
            try {
                $this->setRawSQL($sql)->execute();
                $response = TRUE;
            } catch (\Exception $exception) {
                $response = FALSE;
            }
        }
        return $response;
    }
    
    /**
     * Drops a foreign key from a table.
     *
     * @param string $table The table to drop the foreign key from.
     * @param string $name  The name of the foreign key to drop.
     *
     * @return boolean
     */
    public function dropForeignKey(string $table, string $name): bool {
        $sql = "ALTER TABLE {$table} DROP FOREIGN KEY {$name}";
        try {
            $response = ($this->setRawSQL($sql)->execute()) === 0;
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }
    
    /**
     * Find the name of an index on a tables column.
     *
     * @param string $table  The table to check for the index.
     * @param string $column The column to check for the index.
     *
     * @return string
     */
    public function findIndex(string $table, string $column): string {
        return $this->select([
            "INFORMATION_SCHEMA.STATISTICS" => ["INDEX_NAME"]
        ])
            ->from("INFORMATION_SCHEMA.STATISTICS")
            ->where(["=", "table_schema"], ["table_schema" => $_ENV["DB_DATABASE"]])
            ->andWhere(["=", "table_name"], ["table_name" => $table])
            ->andWhere(["=", "column_name"], ["column_name" => $column])
            ->andWhere(["!=", "index_name"], ["index_name" => "PRIMARY"])
            ->column();
    }
    
    /**
     * Find all indexes on a table.
     *
     * @param string $table The table to search for indexes.
     *
     * @return array
     */
    public function findIndexes(string $table): array {
        $indexes = [];
        $query   = $this->select([
            "INFORMATION_SCHEMA.STATISTICS" => [
                "INDEX_NAME",
                "COLUMN_NAME"
            ]
        ])
            ->from("INFORMATION_SCHEMA.STATISTICS")
            ->where(["=", "table_schema"], ["table_schema" => $_ENV["DB_DATABASE"]])
            ->andWhere(["=", "table_name"], ["table_name" => $table])
            ->andWhere(["!=", "index_name"], ["index_name" => "PRIMARY"]);
        foreach ($query->all() as $value) {
            $indexes[$value["INDEX_NAME"]] = $value["COLUMN_NAME"];
        }
        return $indexes;
    }
    
    /**
     * Check whether an index exists on a column of a table,
     *
     * @param string $table  The table to check for an index.
     * @param string $column The column to check for an index.
     *
     * @return boolean
     */
    public function indexExists(string $table, string $column): bool {
        return (bool)$this->findIndex($table, $column);
    }
    
    /**
     * Create a column index on a table.
     *
     * @param string  $table  The table to create the index on.
     * @param string  $column The column to be indexed.
     * @param boolean $unique Whether the index should be unique.
     *
     * @return boolean
     */
    public function createIndex(string $table, string $column, bool $unique = TRUE): bool {
        $response = FALSE;
        if (!$this->indexExists($table, $column)) {
            $index = "{$column}_index";
            $sql   = "CREATE " . ($unique ? "UNIQUE " : "") . "INDEX {$index} ON {$table} ({$column})";
            try {
                $response = ($this->setRawSQL($sql)->execute()) === 0;
            } catch (\Exception $exception) {
                $response = FALSE;
            }
        }
        return $response;
    }
    
    /**
     * Create multiple column indexes on a table.
     *
     * @param string $table   The table to create the indexes on.
     * @param array  $columns The columns to be indexed & whether unique. e.g.
     * ["column_one" => true, "column_two" => false].
     *
     * @return boolean
     */
    public function createIndexes(string $table, array $columns): bool {
        $success = TRUE;
        foreach ($columns as $column => $unique) {
            $success = $this->createIndex($table, $column, $unique);
            if (!$success) {
                break;
            }
        }
        return $success;
    }

    /**
     * Drop an index from a table.
     *
     * @param string $table     The table being indexed.
     * @param string $indexName The name of the index to drop.
     *
     * @return boolean
     */
    public function dropIndex(string $table, string $indexName): bool {
        $sql = "DROP INDEX {$indexName} ON {$table}";
        try {
            $response = ($this->setRawSQL($sql)->execute()) === 0;
        } catch (\Exception $exception) {
            $response = FALSE;
        }
        return $response;
    }

    /**
     * Initialises a SELECT query.
     *
     * Columns are selected based on an array with the table name as its index.
     * Column aliases are set by the corresponding array key e.g.["name" => "users.firstname"].
     * e.g. ['table_one' => ['column_one', 'alias' => 'column_two'], 'table_two' => []]
     * If nothing or an empty array is used then the query defaults to selecting everything.
     *
     * @param array $selects Array of column names to select.
     *
     * @return self
     */
    public function select(array $selects = []): self {
        $select = [];
        foreach ($selects as $table => $columns) {
            $select[$table] = [];
            foreach ($columns as $alias => $column) {
                if (is_string($alias)) {
                    $select[$table][$alias] = $column;
                } else {
                    $select[$table][$column] = $column;
                }
            }
        }
        $this->select = $select;
        return $this;
    }

    /**
     * Initialises a DISTINCT SELECT query.
     *
     * @see DBConnection::select()
     *
     * @param array $columns Array of column names to select.
     *
     * @return self
     */
    public function selectDistinct(array $columns = []): self {
        $this->select($columns);
        $this->distinct = TRUE;
        return $this;
    }

    /**
     * Initialised a DELETE query.
     *
     * @return self
     */
    public function delete(): self {
        $this->delete = TRUE;
        return $this;
    }

    /**
     * Initialises an UPDATE query.
     *
     * Updates the database table based on an array of columns and values, (array key = column & array value = value)
     * e.g. ["email" => "example@nova.co.uk"]
     *
     * @param string $table  Database table to update.
     * @param array  $values Array of values to update.
     *
     * @return self
     */
    public function update(string $table, array $values): self {
        $update[$table] = $values;
        $this->update   = $update;
        return $this;
    }

    /**
     * Initialises an INSERT query.
     *
     * Inserts a row into the database table provided.
     * Array of values must follow the order of the table columns
     *
     * @param string $table  Database table to insert.
     * @param array  $values Array of values to insert.
     *
     * @return self
     */
    public function insert(string $table, array $values): self {
        $insert[$table] = $values;
        $this->insert   = $insert;
        return $this;
    }

    /**
     * Sets the FROM part of the query
     *
     * @param string $table The table to SELECT or DELETE data from.
     *
     * @return self
     */
    public function from(string $table): self {
        $this->from = $table;
        return $this;
    }

    /**
     * Sets a WHERE clause on the query.
     *
     * The conditions array must follow a format of ONE 'conditional operator' followed by a single column name e.g. ["=", "firstname"].
     * Custom bind names are set as the key to the column name e.g. ["=", "name" => "firstname"].
     *
     * Only one WHERE clause can be set, for multiple clauses use the andWhere() \ orWhere() methods.
     * If the order of operations needs to be taken into account the $condition array can be expanded to include further conditions set in COND_MATCH.
     * e.g. ["or", ["=", "lastname" => "surname"], ["=", "firstname"]].
     * Additionally if a query is being dynamically built with optional (and|or)Where queries, you can set where 1 to help the logic flow
     * e.g. ["1"]
     *
     * Bind parameters can optionally be set here or at once using addParameters().
     *
     * @see DBConnection::COND_MATCH_ONE
     * @see DBConnection::COND_MATCH_TWO
     * @see DBConnection::andWhere()
     * @see DBConnection::orWhere()
     * @see DBConnection::addParameters()
     *
     * @param array $condition  The conditions for the WHERE clause.
     * @param array $parameters Optional parameters to bind to the WHERE clause.
     *
     * @return self
     */
    public function where(array $condition, array $parameters = []): self {
        $this->addParameters($parameters);
        $this->where = $this->formatWhereClauses($condition);
        return $this;
    }

    /**
     * Sets an AND 'WHERE' clause to the query.
     *
     * @see DBConnection:where()
     *
     * @param array $condition  The conditions for the AND clause.
     * @param array $parameters Optional parameters to bind to the AND clause.
     *
     * @return self
     */
    public function andWhere(array $condition, array $parameters = []): self {
        $this->addParameters($parameters);
        $this->and[] = $this->formatWhereClauses($condition);
        return $this;
    }

    /**
     * Sets an OR 'WHERE' clause to the query.
     *
     * @see DBConnection:where()
     *
     * @param array $condition  The conditions for the OR clause.
     * @param array $parameters Optional parameters to bind to the OR clause.
     *
     * @return self
     */
    public function orWhere(array $condition, array $parameters = []): self {
        $this->addParameters($parameters);
        $this->or[] = $this->formatWhereClauses($condition);
        return $this;
    }

    /**
     * Sets a LEFT JOIN clause to the query.
     *
     * The related columns to join are set as key => value in the $conditions array e.g. ["table1.id" => "table2.refId"]
     * If the join is based on conditional relations, this can be expanded in the $conditions array.
     * * e.g. ["or", ["table1.id" => "table2.refId"], ["table1.id" => "table2.secondaryId"]].
     *
     * @param string $table      The table to JOIN to the query.
     * @param array  $conditions Associative array of columns to join.
     *
     * @return self
     */
    public function leftJoin(string $table, array $conditions): self {
        $this->join[] = $this->formatJoinClauses("", $table, $conditions);
        return $this;
    }

    /**
     * Sets a RIGHT JOIN clause to the query.
     *
     * @see DBConnection::leftJoin()
     *
     * @param string $table      The table to JOIN to the query.
     * @param array  $conditions Associative array of columns to join.
     *
     * @return self
     */
    public function rightJoin(string $table, array $conditions): self {
        $this->join[] = $this->formatJoinClauses("", $table, $conditions);
        return $this;
    }

    /**
     * Sets an INNER JOIN clause to the query.
     *
     * @see DBConnection::leftJoin()
     *
     * @param string $table      The table to JOIN to the query.
     * @param array  $conditions Associative array of columns to join.
     *
     * @return self
     */
    public function innerJoin(string $table, array $conditions): self {
        $this->join[] = $this->formatJoinClauses("", $table, $conditions);
        return $this;
    }

    /**
     * Sets a FULL OUTER JOIN clause to the query.
     *
     * @see DBConnection::leftJoin()
     *
     * @param string $table      The table to JOIN to the query.
     * @param array  $conditions Associative array of columns to join.
     *
     * @return self
     */
    public function fullJoin(string $table, array $conditions): self {
        $this->join[] = $this->formatJoinClauses("FULL OUTER", $table, $conditions);
        return $this;
    }

    /**
     * Adds parameters to be bound to the query.
     *
     * The parameters array must have the bind names set as keys with the corresponding value as array value
     * e.g. ["email" => "example@willvachon.co.uk", ":secondaryEmail" => "secondary@vowillvachonly.co.uk"]
     * The bind name can inclue or omit the ":" required by SQL syntax
     *
     * @param array $parameters Array of parameters to be bound.
     *
     * @return self
     */
    public function addParameters(array $parameters): self {
        if (!empty($parameters)) {
            foreach ($parameters as $bind => $value) {
                if (is_array($value)) {
                    foreach ($value as $key => $val) {
                        $this->addParameters(["{$bind}_{$key}" => $val]);
                    }
                    continue;
                }
                if ($bind[0] !== ":") {
                    $bind = ":{$bind}";
                }
                $this->parameters[$bind] = $value;
            }
        }
        return $this;
    }

    /**
     * Sets an ORDER BY clause on the query.
     *
     * The conditions array have the column name to sort as the array key and the direction (asc/desc) as the value
     * e.g. ["users.email" => "desc"]
     *
     * @param array $conditions The conditions used to order the results.
     *
     * @return self
     */
    public function orderBy(array $conditions): self {
        foreach ($conditions as $condition => $sort) {
            $this->order[$condition] = strtoupper($sort);
        }
        return $this;
    }

    /**
     * Sets an GROUP BY clause on the query.
     *
     * The conditions array have the column name to group by
     * e.g. ["users.email", "username"]
     *
     * @param array $conditions The conditions used to group the results.
     *
     * @return self
     */
    public function groupBy(array $conditions): self {
        $this->group = $conditions;
        return $this;
    }

    /**
     * Sets a LIMIT of results to be returned on the query.
     *
     * @param integer $limit The max number of results to return.
     *
     * @return self
     */
    public function limit(int $limit): self {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Sets the OFFSET from where queried results are returned - Requires a LIMIT to be set.
     *
     * @see DBConnection::limit()
     *
     * @param integer $offset The value to offset the returned results.
     *
     * @return self
     */
    public function offset(int $offset): self {
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * Executes the set query and returns a boolean value depending on any results matching or not.
     *
     * @return boolean
     */
    public function exists(): bool {
        $this->resultType = "exists";
        $query            = $this->buildQuery();
        $statement        = $this->prepare($query);
        $results          = (bool) $statement->fetchColumn();
        $this->resetConnection();
        return $results;
    }

    /**
     * Executes the set query and returns the count value of matching results.
     *
     * @return integer
     */
    public function count(): int {
        $this->resultType = "count";
        $query            = $this->buildQuery();
        $statement        = $this->prepare($query);
        $results          = $statement->fetchColumn();
        $this->resetConnection();
        return $results;
    }

    /**
     * Executes the set query and returns all matching results.
     *
     * @return array
     */
    public function all(): array {
        $query     = $this->buildQuery();
        $statement = $this->prepare($query);
        $results   = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $this->resetConnection();
        return $results;
    }

    /**
     * Executes the set query and returns the first result.
     *
     * @return array
     */
    public function one(): array {
        $query     = $this->buildQuery();
        $statement = $this->prepare($query);
        $results   = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$results) {
            $results = [];
        }
        $this->resetConnection();
        return $results;
    }

    /**
     * Executes the set query and returns the first column result.
     *
     * @return string
     */
    public function column(): string {
        $query     = $this->buildQuery();
        $statement = $this->prepare($query);
        $results   = $statement->fetchColumn();
        if (!$results) {
            $results = "";
        }
        $this->resetConnection();
        return $results;
    }

    /**
     * Executes the set query and returns all results with the first column.
     *
     * @return string
     */
    public function columnAll(): array {
        $query     = $this->buildQuery();
        $statement = $this->prepare($query);
        $results   = $statement->fetchAll(\PDO::FETCH_COLUMN);
        if (!$results) {
            $results = "";
        }
        $this->resetConnection();
        return $results;
    }

    /**
     * Executes the set query and returns a count of matching rows.
     * Catches MySQL transaction deadlocks & retries a number of times before giving up.
     *
     * This method is used to execute CREATE, DELETE, INSERT and UPDATE queries which don't return standard datasets.
     *
     * @return integer
     */
    public function execute(): int {
        $query    = "";
        $attempts = 1;
        while (TRUE) {
            try {
                $query     = $this->buildQuery();
                $statement = $this->prepare($query);
                $this->resetConnection();
                break;
            } catch (\Exception $e) {
                // 40001: Serialization failure, e.g. timeout or deadlock.
                // 1213: ER_LOCK_DEADLOCK.
                if ($e->errorInfo[0] == 40001 && $e->errorInfo[1] == 1213 && $attempts <= 3) {
                    $attempts++;
                    continue;
                }
                Nova::log(
                    "---MySQL Failed Query--- ATTEMPTS:" . $attempts . json_encode($query),
                    "error"
                );
                $this->resetConnection();
                throw $e;
            }
        }
        return $statement->rowCount();
    }

    /**
     * Sets a raw SQL query to be executed
     *
     * @param string $sql        A raw SQL query to execute.
     * @param array  $parameters Array of parameters to be bound.
     *
     * @return object
     */
    public function setRawSQL(string $sql, array $parameters = []): self {
        $this->rawSql = $sql;
        $this->addParameters($parameters);
        return $this;
    }

    /**
     * Returns the built SQL query.
     *
     * @param boolean $reset Whether the object is reset on call.
     *
     * @return string
     */
    public function getRawSQL(bool $reset = FALSE): string {
        $query = $this->buildQuery();
        if ($reset) {
            $this->resetConnection();
        }
        return $query;
    }

    /**
     * Validates a model's data based on provided format.
     *
     * @param array $properties  The model data to validate.
     * @param array $validFormat The validation rules.
     *
     * @return array
     */
    public function validateModel(array $properties, array $validFormat): array {
        $response = [];
        if (isset($properties["db"])) {
            unset($properties["db"]);
        }
        $errors = (new Utilities())->dataValidator($properties, $validFormat, TRUE);
        if ($errors) {
            $response = [
                "code"    => 400,
                "message" => "Model data validation error.",
                "detail"  => $errors
            ];
        }
        return $response;
    }

    /**
     * Prepares and executes the given query.
     *
     * @param string $query The SQL query to prepare & execute.
     *
     * @return object
     */
    private function prepare(string $query): object {
        try {
            $statement = $this->connection->prepare($query);
            $statement->execute($this->parameters);
        } catch (\PDOException $pdo) {
            throw $pdo;
        }
        return $statement;
    }

    /**
     * Formats and stores the given WHERE data to later be parsed into SQL syntax.
     *
     * @see DBConnection::where()
     *
     * @param array $conditions The conditions for the WHERE clause.
     *
     * @return array
     */
    private function formatWhereClauses(array $conditions): array {
        $query     = [];
        $condition = array_shift($conditions);
        if (in_array($condition, self::COND_MATCH_ONE)) {
            foreach ($conditions as $conds) {
                $query[$condition][] = $this->formatWhereClauses($conds);
            }
        } else {
            $col  = reset($conditions);
            $bind = key($conditions);
            if (!is_string($bind)) {
                $bind = ":{$col}";
            } elseif ($bind[0] !== ":") {
                $bind = ":{$bind}";
            }
            $query[$condition] = [$col => $bind];
        }
        return $query;
    }

    /**
     * Formats and stores the given JOIN data to later be parsed into SQL syntax.
     *
     * @param string  $type          The type of JOIN being used.
     * @param string  $table         The table to JOIN to the query.
     * @param array   $joins         The conditions for the JOIN.
     * @param boolean $nestCondition A flag used for identifying a recursive call.
     *
     * @return array
     */
    private function formatJoinClauses(string $type, string $table, array $joins, bool $nestCondition = FALSE): array {
        $query     = [];
        $condition = reset($joins);
        if (is_string($condition) && in_array($condition, self::COND_MATCH_ONE)) {
            array_shift($joins);
            foreach ($joins as $join) {
                $query[$type][$table][$condition][] = $this->formatJoinClauses($type, $table, $join, TRUE);
            }
        } elseif (is_string($condition) && in_array($condition, self::COND_MATCH_THREE)) {
            array_shift($joins);
            $join  = reset($joins);
            $left  = reset($join);
            $right = key($join);
            if ($nestCondition) {
                $query = [$condition => [$left => $right]];
            } else {
                $query[$type][$table] = [$condition => [$left => $right]];
            }
        } else {
            $left  = reset($joins);
            $right = key($joins);
            if ($nestCondition) {
                $query = ["=" => [$left => $right]];
            } else {
                $query[$type][$table] = ["=" => [$left => $right]];
            }
        }
        return $query;
    }

    /**
     * Builds the WHERE, AND & OR clauses in SQL syntax.
     *
     * @return string
     */
    private function getWhereQuery(): string {
        $sql   = "";
        $where = $this->formatWhere($this->where);
        if ($where) {
            $sql .= "WHERE {$where}";
            foreach ($this->and as $and) {
                $sql .= " AND " . $this->formatWhere($and);
            }
            foreach ($this->or as $or) {
                $sql .= " OR " . $this->formatWhere($or);
            }
        }
        return $sql;
    }

    /**
     * Formats the WHERE data into SQL syntax
     *
     * @param array $conditions The conditions for the WHERE clause.
     *
     * @return string
     */
    private function formatWhere(array $conditions): string {
        $query        = "";
        $defaultWhere = FALSE;
        if (count($conditions) === 1 && array_keys($conditions)[0] === 1) {
            $conditions   = NULL;
            $defaultWhere = TRUE;
        }
        if ($conditions) {
            foreach ($conditions as $cond => $conds) {
                if (in_array($cond, self::COND_MATCH_ONE)) {
                    $query   .= "(";
                    $subQuery = [];
                    foreach ($conds as $c) {
                        $subQuery[] = $this->formatWhere($c);
                    }
                    $subQuery = implode(" $cond ", $subQuery);
                    $query   .= "{$subQuery}";
                    $query   .= ")";
                } elseif (in_array($cond, self::COND_MATCH_TWO)) {
                    $col    = reset($conds);
                    $cols   = array_filter($this->parameters, function ($key) use ($col) {
                        return preg_match($col . "_[.]*:", $key);
                    }, ARRAY_FILTER_USE_KEY);
                    $cols   = implode(",", array_keys($cols));
                    $bind   = key($conds);
                    $query .= "{$bind} {$cond} ({$cols})";
                } else {
                    $col  = reset($conds);
                    $bind = key($conds);
                    if (is_null($this->parameters[$col]) && $cond === "=") {
                        $cond = "is";
                    }
                    $query .= "{$bind} {$cond} {$col}";
                }
            }
        } elseif ($defaultWhere && $this->delete === FALSE && $this->insert === FALSE && $this->update === FALSE) {
            $query = "1";
        }
        return $query;
    }

    /**
     * Builds the JOIN clauses in SQL syntax.
     *
     * @return string
     */
    private function getJoinQuery(): string {
        $sql = [];
        foreach ($this->join as $joinCondition) {
            foreach ($joinCondition as $type => $joinCond) {
                foreach ($joinCond as $table => $join) {
                    $sql[] = "{$type} JOIN {$table} ON " . $this->formatJoin($join);
                }
            }
        }
        $sql = implode(' ', $sql);
        return $sql;
    }

    /**
     * Formats the JOIN data into SQL syntax.
     *
     * @param array $conditions The conditions for the JOIN clause.
     *
     * @return string
     */
    private function formatJoin(array $conditions): string {
        $query = "";
        foreach ($conditions as $cond => $conds) {
            if (in_array($cond, self::COND_MATCH_ONE)) {
                $query   .= "(";
                $subQuery = [];
                foreach ($conds as $j) {
                    $subQuery[] = $this->formatJoin($j);
                }
                $subQuery = implode(" $cond ", $subQuery);
                $query   .= "{$subQuery}";
                $query   .= ")";
            } else {
                $left  = reset($conds);
                $right = key($conds);
                if (in_array($cond, self::COND_MATCH_THREE)) {
                    $right = "CONCAT('%', {$right}, '%')";
                }
                $query .= "{$left} {$cond} {$right}";
            }
        }
        return $query;
    }

    /**
     * Builds the GROUP BY clauses in SQL syntax.
     *
     * @return string
     */
    private function formatGroup(): string {
        $query = "";
        if ($this->group) {
            $query = "GROUP BY " . implode(', ', $this->group);
        }
        return $query;
    }

    /**
     * Builds the ORDER BY clauses in SQL syntax.
     *
     * @return string
     */
    private function formatOrder(): string {
        $query    = "";
        $subQuery = [];
        foreach ($this->order as $order => $sort) {
            $subQuery[] = "{$order} {$sort}";
        }
        if ($subQuery) {
            $query = "ORDER BY " . implode(', ', $subQuery);
        }
        return $query;
    }

    /**
     * Builds the LIMIT & OFFSET data in SQL syntax
     *
     * @return string
     */
    private function formatLimit(): string {
        $query = "";
        if ($this->limit) {
            $query .= "LIMIT {$this->limit}";
            if ($this->offset) {
                $query .= " OFFSET {$this->offset}";
            }
        }
        return $query;
    }
    
    /**
     * Builds the query operation (SELECT, DELETE, INSERT, UPDATE).
     *
     * @param string $opType An identifier for which operation type to build.
     *
     * @return string
     */
    private function formatOperation(string $opType): string {
        $query = "";
        switch ($opType) {
            case "SELECT":
                $query = $this->formatSelect();
                break;
            case "DELETE":
                $query = $this->formatDelete();
                break;
            case "INSERT":
                $query = $this->formatInsert();
                break;
            case "UPDATE":
                $query = $this->formatUpdate();
                break;
        }
        return $query;
    }

    /**
     * Formats the SELECT query operation.
     *
     * @return string
     */
    private function formatSelect(): string {
        if ($this->resultType === "count") {
            $query = "SELECT COUNT(*)";
        } elseif ($this->resultType === "exists") {
            $query = "SELECT 1";
        } else {
            $query    = [];
            $distinct = $this->distinct ? "DISTINCT " : "";
            foreach ($this->select as $table => $columns) {
                if (count($columns) == 0) {
                    $query[] = "{$table}.*";
                }
                foreach ($columns as $alias => $column) {
                    $select = "{$table}.{$column}";
                    if ($column != $alias) {
                        $select .= " AS {$alias}";
                    }
                    $query[] = $select;
                }
            }
            $query = implode(", ", $query);
            if (!$query) {
                $query = "*";
            }
            $query = "SELECT {$distinct}" . $query;
        }
        $query .= " FROM {$this->from}";
        return $query;
    }

    /**
     * Formats the DELETE query operation.
     *
     * @return string
     */
    private function formatDelete(): string {
        $query = "DELETE FROM {$this->from}";
        return $query;
    }

    /**
     * Formats the INSERT query operation.
     *
     * @return string
     */
    private function formatInsert(): string {
        $rawValues = reset($this->insert);
        $table     = key($this->insert);
        $columns   = [];
        $values    = [];
        foreach ($rawValues as $column => $value) {
            $columns[] = $column;
            $values[]  = $value !== NULL ? $this->connection->quote($value) : "null";
        }
        $key     = $columns[0];
        $columns = implode(", ", $columns);
        $values  = implode(", ", $values);
        $query   = "INSERT INTO {$table} ({$columns}) VALUES ({$values}) ON DUPLICATE KEY UPDATE {$key}={$key}";
        return $query;
    }

    /**
     * Formats the UPDATE query operation.
     *
     * @return string
     */
    private function formatUpdate(): string {
        $rawValues = reset($this->update);
        $table     = key($this->update);
        $values    = [];
        foreach ($rawValues as $col => $value) {
            $values[] = "{$col} = " . ($value !== NULL ? "{$this->connection->quote($value)}" : "null");
        }
        $values = implode(", ", $values);
        $query  = "UPDATE {$table} SET {$values}";
        return $query;
    }
    
    /**
     * Builds the full SQL query to execute.
     *
     * @return string
     */
    // @codingStandardsIgnoreStart - Ignored due to complex query building
    private function buildQuery(): string {
        $sql = [];
        if ($this->rawSql) {
            $sql = $this->rawSql;
        } else {
            if ($this->delete === true) {
                $opType = "DELETE";
            } elseif ($this->insert !== false) {
                $opType = "INSERT";
            } elseif ($this->update !== false) {
                $opType = "UPDATE";
            } else {
                $opType = "SELECT";
                if (Nova::appType() === "api") {
                    $this->andWhere(
                        [
                            "or",
                            [
                                "is", "meta_null_{$this->from}" => "{$this->from}.meta"
                            ],
                            [
                                "not like", "meta_archived_{$this->from}" => "{$this->from}.meta"
                            ]
                        ],
                        [
                            "meta_null_{$this->from}"     => null,
                            "meta_archived_{$this->from}" => '%"archived":true%'
                        ]
                    );
                    foreach ($this->join as $joinCondition) {
                        foreach ($joinCondition as $joinCond) {
                            foreach (array_keys($joinCond) as $table) {
                                $this->andWhere(
                                    [
                                        "or",
                                        [
                                            "is", "meta_null_{$table}" => "{$table}.meta"
                                        ],
                                        [
                                            "not like", "meta_archived_{$table}" => "{$table}.meta"
                                        ]
                                    ],
                                    [
                                        "meta_null_{$table}"     => null,
                                        "meta_archived_{$table}" => '%"archived":true%'
                                    ]
                                );
                            }
                        }
                    }
                }
            }
            $sql[] = $this->formatOperation($opType);
            $sql[] = $this->getJoinQuery();
            $sql[] = $this->getWhereQuery();
            $sql[] = $this->formatGroup();
            $sql[] = $this->formatOrder();
            $sql[] = $this->formatLimit();
            $sql   = array_filter($sql, function ($value) {
                return !is_null($value) && !empty($value) && $value !== '';
            });
            $sql   = implode(" ", $sql);
            if ($this->resultType === "exists") {
                $sql = "SELECT EXISTS ({$sql})";
            }
        }
        return $sql;
    }
    // @codingStandardsIgnoreEnd

    /**
     * Resets class defaults on execution.
     *
     * @return void
     */
    private function resetConnection(): void {
        $this->select     = [];
        $this->distinct   = FALSE;
        $this->delete     = FALSE;
        $this->insert     = FALSE;
        $this->update     = FALSE;
        $this->from       = FALSE;
        $this->join       = [];
        $this->where      = [];
        $this->and        = [];
        $this->or         = [];
        $this->order      = [];
        $this->group      = [];
        $this->limit      = FALSE;
        $this->offset     = FALSE;
        $this->parameters = [];
        $this->resultType = FALSE;
        $this->rawSql     = FALSE;
    }
}

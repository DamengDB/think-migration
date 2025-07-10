<?php

/**
 * MIT License
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Phinx\Db\Adapter;

use Cake\Database\Connection;
use InvalidArgumentException;
use PDO;
use PDOException;
use Phinx\Db\Table\Column;
use Phinx\Db\Table\ForeignKey;
use Phinx\Db\Table\Index;
use Phinx\Db\Table\Table;
use Phinx\Db\Util\AlterInstructions;
use Phinx\Util\Literal;
use RuntimeException;

class DmAdapter extends PdoAdapter
{
    protected $version = '3.0.1';

    /**
     * @var string[]
     */
    protected static $specificColumnTypes = [
        self::PHINX_TYPE_JSON,
        self::PHINX_TYPE_JSONB,
        self::PHINX_TYPE_GEOGRAPHY
    ];

    /**
     * Columns with comments
     *
     * @var \Phinx\Db\Table\Column[]
     */
    protected $columnsWithComments = [];

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @return void
     */
    public function connect(): void
    {
        if ($this->connection === null) {
            if (!class_exists('PDO') || !in_array('dm', PDO::getAvailableDrivers(), true)) {
                // @codeCoverageIgnoreStart
                throw new RuntimeException('You need to enable the PDO_Dm extension for Phinx to run properly.');
                // @codeCoverageIgnoreEnd
            }

            $options = $this->getOptions();
            $dsn = 'dm:';

            if (isset($options['host'])) {
                $dsn .= 'host=' . $options['host'];
            }

            // if custom port is specified use it
            if (isset($options['port'])) {
                $dsn .= ';port=' . $options['port'];
            }

            $driverOptions = [];

            // use custom data fetch mode
            if (!empty($options['fetch_mode'])) {
                $driverOptions[PDO::ATTR_DEFAULT_FETCH_MODE] =
                    constant('\PDO::FETCH_' . strtoupper($options['fetch_mode']));
            }

            // pass \PDO::ATTR_PERSISTENT to driver options instead of useless setting it after instantiation
            if (isset($options['attr_persistent'])) {
                $driverOptions[PDO::ATTR_PERSISTENT] = $options['attr_persistent'];
            }

            if (isset($options['charset'])) {
                $driverOptions[PDO::CHARSET] = $options['charset'];
            }

            $db = $this->createPdoConnection($dsn, $options['user'] ?? null, $options['pass'] ?? null, $driverOptions);

            $this->setConnection($db);
        }
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * @inheritDoc
     */
    public function hasTransactions(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->execute('START TRANSACTION');
    }

    /**
     * @inheritDoc
     */
    public function commitTransaction(): void
    {
        $this->execute('COMMIT');
    }

    /**
     * @inheritDoc
     */
    public function rollbackTransaction(): void
    {
        $this->execute('ROLLBACK');
    }

    /**
     * Quotes a schema name for use in a query.
     *
     * @param string $schemaName Schema Name
     * @return string
     */
    public function quoteSchemaName(string $schemaName): string
    {
        return $this->quoteColumnName($schemaName);
    }

    /**
     * @inheritDoc
     */
    public function quoteTableName(string $tableName): string
    {
        $parts = $this->getSchemaName($tableName);

        return $this->quoteSchemaName($parts['schema']) . '.' . $this->quoteColumnName($parts['table']);
    }

    /**
     * @inheritDoc
     */
    public function quoteColumnName(string $columnName): string
    {
        return '"' . $columnName . '"';
    }

    /**
     * @inheritDoc
     */
    public function hasTable(string $tableName): bool
    {
        if ($this->hasCreatedTable($tableName)) {
            return true;
        }

        $parts = $this->quoteTableName($tableName);
        $result = $this->getConnection()->query(
            sprintf(
                "select (case when object_id(%s, 'U') is null then 0 else 1 end) ", $this->getConnection()->quote($parts)
            )
        );

        $column = $result->fetchColumn();
        return $column === 1;
    }

    /**
     * Get table id
     *
     * @param string $tableName Table name
     * @return int
     */
    public function getTableId(string $tableName): int
    {
        $parts = $this->getSchemaName($tableName);

        $result = $this->getConnection()->query(
            sprintf(
                "select ID 
                from SYS.VSYSOBJECTS 
                where name = %s and SCHID = (select ID from SYS.VSYSOBJECTS where name = %s and TYPE$ = 'SCH')",
                $this->getConnection()->quote($parts['table']),
                $this->getConnection()->quote($parts['schema'])
            )
        );

        $id = $result->fetchColumn();
        return $id;
    }
    /**
     * @inheritDoc
     */
    public function createTable(Table $table, array $columns = [], array $indexes = []): void
    {
        $queries = [];

        $options = $table->getOptions();
        $parts = $this->getSchemaName($table->getName());

        // Add the default primary key
        if (!isset($options['id']) || (isset($options['id']) && $options['id'] === true)) {
            $options['id'] = 'id';
        }

        if (isset($options['id']) && is_string($options['id'])) {
            // Handle id => "field_name" to support AUTO_INCREMENT
            $column = new Column();
            $column->setName($options['id'])
                ->setType('integer')
                ->setOptions(['identity' => true]);

            array_unshift($columns, $column);
            if (isset($options['primary_key']) && (array)$options['id'] !== (array)$options['primary_key']) {
                throw new InvalidArgumentException('You cannot enable an auto incrementing ID field and a primary key');
            }
            $options['primary_key'] = $options['id'];
        }

        // TODO - process table options like collation etc
        $sql = 'CREATE TABLE ';
        $sql .= $this->quoteTableName($table->getName()) . ' (';

        $this->columnsWithComments = [];
        foreach ($columns as $column) {
            $sql .= $this->quoteColumnName($column->getName()) . ' ' . $this->getColumnSqlDefinition($column);
            $sql .= ', ';

            // set column comments, if needed
            if ($column->getComment()) {
                $this->columnsWithComments[] = $column;
            }
        }

        // set the primary key(s)
        if (isset($options['primary_key'])) {
            $sql = rtrim($sql);
            $sql .= sprintf(' CONSTRAINT %s PRIMARY KEY (', $this->quoteColumnName($parts['table'] . '_pkey'));
            if (is_string($options['primary_key'])) { // handle primary_key => 'id'
                $sql .= $this->quoteColumnName($options['primary_key']);
            } elseif (is_array($options['primary_key'])) { // handle primary_key => array('tag_id', 'resource_id')
                $sql .= implode(',', array_map([$this, 'quoteColumnName'], $options['primary_key']));
            }
            $sql .= ')';
        } else {
            $sql = rtrim($sql, ', '); // no primary keys
        }

        $sql .= ')';
        $queries[] = $sql;

        // process column comments
        if (!empty($this->columnsWithComments)) {
            foreach ($this->columnsWithComments as $column) {
                $queries[] = $this->getColumnCommentSqlDefinition($column, $table->getName());
            }
        }

        // set the indexes
        if (!empty($indexes)) {
            foreach ($indexes as $index) {
                $queries[] = $this->getIndexSqlDefinition($index, $table->getName());
            }
        }

        // process table comments
        if (isset($options['comment'])) {
            $queries[] = sprintf(
                'COMMENT ON TABLE %s IS %s',
                $this->quoteTableName($table->getName()),
                $this->getConnection()->quote($options['comment'])
            );
        }

        foreach ($queries as $query) {
            $this->execute($query);
        }

        $this->addCreatedTable($table->getName());
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     */
    protected function getChangePrimaryKeyInstructions(Table $table, $newColumns): AlterInstructions
    {
        $parts = $this->getSchemaName($table->getName());

        $instructions = new AlterInstructions();

        // Drop the existing primary key
        $primaryKey = $this->getPrimaryKey($table->getName());
        if (!empty($primaryKey['constraint'])) {
            $sql = sprintf(
                'DROP CONSTRAINT %s',
                $this->quoteColumnName($primaryKey['constraint'])
            );
            $dopInstructions = new AlterInstructions();
            $dopInstructions->addAlter($sql);
            $this->executeAlterSteps($table->getName(), $dopInstructions);
        }

        // Add the new primary key
        if (!empty($newColumns)) {
            $sql = sprintf(
                'ADD CONSTRAINT %s PRIMARY KEY (',
                $this->quoteColumnName($parts['table'] . '_pkey')
            );
            if (is_string($newColumns)) { // handle primary_key => 'id'
                $sql .= $this->quoteColumnName($newColumns);
            } elseif (is_array($newColumns)) { // handle primary_key => array('tag_id', 'resource_id')
                $sql .= implode(',', array_map([$this, 'quoteColumnName'], $newColumns));
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Invalid value for primary key: %s',
                    json_encode($newColumns)
                ));
            }
            $sql .= ')';
            $instructions->addAlter($sql);
        }

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    protected function getChangeCommentInstructions(Table $table, ?string $newComment): AlterInstructions
    {
        $instructions = new AlterInstructions();

        // passing 'null' is to remove table comment
        $newComment = $newComment !== null
            ? $this->getConnection()->quote($newComment)
            : 'NULL';
        $sql = sprintf(
            'COMMENT ON TABLE %s IS %s',
            $this->quoteTableName($table->getName()),
            $newComment
        );
        $instructions->addPostStep($sql);

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    protected function getRenameTableInstructions(string $tableName, string $newTableName): AlterInstructions
    {
        $this->updateCreatedTableName($tableName, $newTableName);
        $sql = sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $this->quoteTableName($tableName),
            $this->quoteColumnName($newTableName)
        );

        return new AlterInstructions([], [$sql]);
    }

    /**
     * @inheritDoc
     */
    protected function getDropTableInstructions(string $tableName): AlterInstructions
    {
        $this->removeCreatedTable($tableName);
        $sql = sprintf('DROP TABLE %s', $this->quoteTableName($tableName));

        return new AlterInstructions([], [$sql]);
    }

    /**
     * @inheritDoc
     */
    public function truncateTable(string $tableName): void
    {
        $sql = sprintf(
            'TRUNCATE TABLE %s',
            $this->quoteTableName($tableName)
        );

        $this->execute($sql);
    }

    /**
     * @inheritDoc
     */
    public function getColumns(string $tableName): array
    {
        $tableId = $this->getTableId($tableName);
        $columns = array();
        $sql = sprintf(
            "select COLUMN_NAME, DATA_TYPE, DATA_LENGTH, DATA_PRECISION, DATA_SCALE, NULLABLE, DATA_DEFAULT,
           (case when (INFO2 & 0x01 = 0x01) then 1 else 0 end) COLUMN_IDENTITY  
            from ALL_TAB_COLUMNS, SYS.VSYSCOLUMNS 
            where TABLE_NAME = %s and id = %d and NAME = COLUMN_NAME",
            $this->getConnection()->quote($tableName),
            $tableId
        );
        $columnsInfo = $this->fetchAll($sql);

        foreach ($columnsInfo as $columnInfo) {
            $column = new Column();
            $column->setName($columnInfo['COLUMN_NAME'])
                ->setType($this->getPhinxType($columnInfo['DATA_TYPE']))
                ->setNull($columnInfo['NULLABLE'] === 'Y')
                ->setDefault($columnInfo['DATA_DEFAULT'])
                ->setIdentity($columnInfo['COLUMN_IDENTITY'])
                ->setLimit($columnInfo['DATA_LENGTH'])
                ->setPrecision($columnInfo['DATA_PRECISION'])
                ->setScale($columnInfo['DATA_SCALE']);

            if (preg_match('/\bwith time zone$/', $columnInfo['DATA_TYPE'])) {
                $column->setTimezone(true);
            }

            $columns[] = $column;
        }
        return $columns;
    }

    /**
     * @inheritDoc
     */
    public function hasColumn(string $tableName, string $columnName): bool
    {
        $sql = sprintf(
            "SELECT count(*) as COUNT 
            FROM ALL_TAB_COLUMNS 
            WHERE TABLE_NAME=%s AND COLUMN_NAME=%s",
            $this->getConnection()->quote($tableName),
            $this->getConnection()->quote($columnName)
        );

        $result = $this->fetchRow($sql);
        return $result['COUNT'] > 0;
    }

    /**
     * @inheritDoc
     */
    protected function getAddColumnInstructions(Table $table, Column $column): AlterInstructions
    {
        $instructions = new AlterInstructions();
        $instructions->addAlter(sprintf(
            'ADD COLUMN(%s %s)',
            $this->quoteColumnName($column->getName()),
            $this->getColumnSqlDefinition($column)
        ));

        if ($column->getComment()) {
            $instructions->addPostStep($this->getColumnCommentSqlDefinition($column, $table->getName()));
        }

        return $instructions;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     */
    protected function getRenameColumnInstructions(
        string $tableName,
        string $columnName,
        string $newColumnName
    ): AlterInstructions {
        $sql = sprintf(
            'SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END AS column_exists 
            FROM ALL_TAB_COLUMNS 
            WHERE TABLE_NAME=%s and COLUMN_NAME=%s',
            $this->getConnection()->quote($tableName),
            $this->getConnection()->quote($columnName)
        );

        $result = $this->fetchRow($sql);
        if (!(bool)$result['COLUMN_EXISTS']) {
            throw new InvalidArgumentException("The specified column does not exist: $columnName");
        }

        $instructions = new AlterInstructions();
        $instructions->addPostStep(
            sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName),
                $this->quoteColumnName($newColumnName)
            )
        );

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    protected function getChangeColumnInstructions(
        string $tableName,
        string $columnName,
        Column $newColumn
    ): AlterInstructions {
        $quotedColumnName = $this->quoteColumnName($columnName);
        $column = $this->getColumn($tableName, $columnName);

        if ($column->getType() !== $newColumn->getType()) {
            $sql = sprintf(
                'modify %s %s',
                $quotedColumnName,
                $this->getColumnSqlDefinition($newColumn)
            );

            $instructions = new AlterInstructions();
            $instructions->addAlter($sql);
            $this->executeAlterSteps($tableName, $instructions);
        }

        // process identity
        if ($column->isIdentity() && !$newColumn->isIdentity()) {
            $sql = sprintf(' DROP IDENTITY');
            $instructions = new AlterInstructions();
            $instructions->addAlter($sql);
            $this->executeAlterSteps($tableName, $instructions);

            // process null
            $sql = sprintf(
                'ALTER COLUMN %s',
                $quotedColumnName
            );

            if (!$newColumn->getIdentity() && !$column->getIdentity() && $newColumn->isNull()) {
                $sql .= ' SET NULL';
            } else {
                $sql .= ' SET NOT NULL';
            }

            $instructions = new AlterInstructions();
            $instructions->addAlter($sql);
            $this->executeAlterSteps($tableName, $instructions);
        } elseif (!$column->isIdentity() && $newColumn->isIdentity()) {
            // process null
            $sql = sprintf(
                'ALTER COLUMN %s',
                $quotedColumnName
            );

            if (!$newColumn->getIdentity() && !$column->getIdentity() && $newColumn->isNull()) {
                $sql .= ' SET NULL';
            } else {
                $sql .= ' SET NOT NULL';
            }

            $instructions = new AlterInstructions();
            $instructions->addAlter($sql);
            $this->executeAlterSteps($tableName, $instructions);

            $sql = sprintf('add column %s identity', $quotedColumnName);
            $instructions = new AlterInstructions();
            $instructions->addAlter($sql);
            $this->executeAlterSteps($tableName, $instructions);
        } else {
            // process null
            $sql = sprintf(
                'ALTER COLUMN %s',
                $quotedColumnName
            );

            if (!$newColumn->getIdentity() && !$column->getIdentity() && $newColumn->isNull()) {
                $sql .= ' SET NULL';
            } else {
                $sql .= ' SET NOT NULL';
            }

            $instructions = new AlterInstructions();
            $instructions->addAlter($sql);
            $this->executeAlterSteps($tableName, $instructions);
        }

        $instructions = new AlterInstructions();
        if ($newColumn->getDefault() !== null) {
            $instructions->addAlter(sprintf(
                'ALTER COLUMN %s SET %s',
                $quotedColumnName,
                $this->getDefaultValueDefinition($newColumn->getDefault(), $newColumn->getType())
            ));
        } elseif (!$newColumn->getIdentity()) {
            //drop default
            $instructions->addAlter(sprintf(
                'ALTER COLUMN %s DROP DEFAULT',
                $quotedColumnName
            ));
        }

        // change column comment if needed
        if ($newColumn->getComment()) {
            $instructions->addPostStep($this->getColumnCommentSqlDefinition($newColumn, $tableName));
        }

        return $instructions;
    }

    /**
     * @param string $tableName Table name
     * @param string $columnName Column name
     * @return ?\Phinx\Db\Table\Column
     */
    protected function getColumn(string $tableName, string $columnName): ?Column
    {
        $columns = $this->getColumns($tableName);
        foreach ($columns as $column) {
            if ($column->getName() === $columnName) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    protected function getDropColumnInstructions(string $tableName, string $columnName): AlterInstructions
    {
        $alter = sprintf(
            'DROP COLUMN %s',
            $this->quoteColumnName($columnName)
        );

        return new AlterInstructions([$alter]);
    }

    /**
     * Get an array of indexes from a particular table.
     *
     * @param string $tableName Table name
     * @return array
     */
    protected function getIndexes($tableName)
    {
        $parts = $this->getSchemaName($tableName);

        $indexes = [];
        $sql = sprintf(
            "SELECT COLUMN_NAME ,INDEX_NAME from ALL_IND_COLUMNS WHERE TABLE_NAME= %s and table_owner = %s",
            $this->getConnection()->quote($parts['table']),
            $this->getConnection()->quote($parts['schema'])
        );
        $rows = $this->fetchAll($sql);
        foreach ($rows as $row) {
            if (!isset($indexes[$row['INDEX_NAME']])) {
                $indexes[$row['INDEX_NAME']] = ['columns' => []];
            }
            $indexes[$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
        }

        return $indexes;
    }

    /**
     * @inheritDoc
     */
    public function hasIndex(string $tableName, $columns): bool
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        $indexes = $this->getIndexes($tableName);
        foreach ($indexes as $index) {
            if (array_diff($index['columns'], $columns) === array_diff($columns, $index['columns'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function hasIndexByName(string $tableName, string $indexName): bool
    {
        $indexes = $this->getIndexes($tableName);
        foreach ($indexes as $name => $index) {
            if ($name === $indexName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function getAddIndexInstructions(Table $table, Index $index): AlterInstructions
    {
        $instructions = new AlterInstructions();
        $instructions->addPostStep($this->getIndexSqlDefinition($index, $table->getName()));

        return $instructions;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     */
    protected function getDropIndexByColumnsInstructions(string $tableName, $columns): AlterInstructions
    {
        $parts = $this->getSchemaName($tableName);

        if (is_string($columns)) {
            $columns = [$columns]; // str to array
        }

        $indexes = $this->getIndexes($tableName);
        foreach ($indexes as $indexName => $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                return new AlterInstructions([], [sprintf(
                    'DROP INDEX IF EXISTS %s',
                    '"' . ($parts['schema'] . '".' . $this->quoteColumnName($indexName))
                )]);
            }
        }

        throw new InvalidArgumentException(sprintf(
            "The specified index on columns '%s' does not exist",
            implode(',', $columns)
        ));
    }

    /**
     * @inheritDoc
     */
    protected function getDropIndexByNameInstructions(string $tableName, string $indexName): AlterInstructions
    {
        $parts = $this->getSchemaName($tableName);

        $sql = sprintf(
            'DROP INDEX IF EXISTS %s',
            '"' . ($parts['schema'] . '".' . $this->quoteColumnName($indexName))
        );

        return new AlterInstructions([], [$sql]);
    }

    /**
     * @inheritDoc
     */
    public function hasPrimaryKey(string $tableName, $columns, ?string $constraint = null): bool
    {
        $primaryKey = $this->getPrimaryKey($tableName);

        if (empty($primaryKey)) {
            return false;
        }

        if ($constraint) {
            return $primaryKey['constraint'] === $constraint;
        } else {
            if (is_string($columns)) {
                $columns = [$columns]; // str to array
            }
            $missingColumns = array_diff($columns, $primaryKey['columns']);

            return empty($missingColumns);
        }
    }

    /**
     * Get the primary key from a particular table.
     *
     * @param string $tableName Table name
     * @return array
     */
    public function getPrimaryKey(string $tableName): array
    {
        $tableId = $this->getTableId($tableName);

        $rows = $this->fetchAll(sprintf(
            "select CON_OBJ.NAME constraint_name, COLS.NAME column_name from SYS.VSYSCOLUMNS COLS, (select * from SYS.VSYSCONS WHERE TYPE$ = 'P') CONS, SYS.VSYSINDEXES INDS, 
            (select ID, PID from SYS.VSYSOBJECTS where SUBTYPE$='INDEX') IND_OBJ, 
            (select NAME, ID, PID from SYS.VSYSOBJECTS where SUBTYPE$='CONS') CON_OBJ
            where CONS.TABLEID = %d and INDS.ID = IND_OBJ.ID and COLS.ID = CONS.TABLEID and CONS.INDEXID = INDS.ID 
            and SF_COL_IS_IDX_KEY(INDS.KEYNUM, INDS.KEYINFO, COLS.COLID) = 1 and CON_OBJ.ID = CONS.ID",
            $tableId
        ));

        $primaryKey = [
            'columns' => [],
        ];
        foreach ($rows as $row) {
            $primaryKey['constraint'] = $row['CONSTRAINT_NAME'];
            $primaryKey['columns'][] = $row['COLUMN_NAME'];
        }

        return $primaryKey;
    }

    /**
     * @inheritDoc
     */
    public function hasForeignKey(string $tableName, $columns, ?string $constraint = null): bool
    {
        if (is_string($columns)) {
            $columns = [$columns]; // str to array
        }
        $foreignKeys = $this->getForeignKeys($tableName);
        if ($constraint) {
            if (isset($foreignKeys[$constraint])) {
                return !empty($foreignKeys[$constraint]);
            }

            return false;
        }

        foreach ($foreignKeys as $key) {
            $a = array_diff($columns, $key['columns']);
            if (empty($a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an array of foreign keys from a particular table.
     *
     * @param string $tableName Table name
     * @return array
     */
    protected function getForeignKeys(string $tableName): array
    {
        $tableId = $this->getTableId($tableName);
        $foreignKeys = [];
        $rows = $this->fetchAll(sprintf(
            "select CON_OBJ.NAME, CON_OBJ.ID from (select * from SYS.VSYSCONS where TYPE$='F') CONS, 
            (select NAME, ID, CRTDATE from SYS.VSYSOBJECTS where SUBTYPE$ = 'CONS') CON_OBJ,
            (select ID, NAME, SCHID from SYS.VSYSOBJECTS where TYPE$='SCHOBJ' and SUBTYPE$ like '_TAB' and  ID = %d) TAB_OBJ
            where CON_OBJ.ID = CONS.ID and CONS.TABLEID = TAB_OBJ.ID",
            $tableId
        ));

        foreach ($rows as $row) {
            $constrainName = $row['NAME'];
            $foreignKeysId = $row['ID'];
        }

        $rows = $this->fetchAll(sprintf(
            "select TAB_OBJ.NAME table_name, COLS.NAME column_name from  SYS.VSYSCONS CONS,  SYS.VSYSCOLUMNS COLS, SYS.VSYSINDEXES INDS, SYS.VSYSOBJECTS OBJS,(select NAME, ID from SYS.VSYSOBJECTS) TAB_OBJ 
            where CONS.ID = %d and CONS.INDEXID = INDS.ID and COLS.ID = OBJS.PID and TAB_OBJ.ID = OBJS.PID
            and OBJS.ID = INDS.ID AND SF_COL_IS_IDX_KEY(INDS.KEYNUM, INDS.KEYINFO, COLS.COLID) = 1  
            ORDER BY SF_GET_INDEX_KEY_SEQ(INDS.KEYNUM, INDS.KEYINFO, COLS.COLID);",
            $foreignKeysId
        ));

        foreach ($rows as $row) {
            $foreignKeys[$constrainName]['table'] = $row['TABLE_NAME'];
            $foreignKeys[$constrainName]['columns'][] = $row['COLUMN_NAME'];
        }

        $rows = $this->fetchAll(sprintf(
            "select TAB_OBJ.NAME referenced_table_name, COLS.NAME referenced_column_name from  SYS.VSYSCONS CONS,  SYS.VSYSCOLUMNS COLS, SYS.VSYSINDEXES INDS, SYS.VSYSOBJECTS OBJS,(select NAME, ID from SYS.VSYSOBJECTS) TAB_OBJ 
            where CONS.ID = %d and CONS.FINDEXID = INDS.ID and COLS.ID = OBJS.PID and TAB_OBJ.ID = OBJS.PID
            and OBJS.ID = INDS.ID AND SF_COL_IS_IDX_KEY(INDS.KEYNUM, INDS.KEYINFO, COLS.COLID) = 1  
            ORDER BY SF_GET_INDEX_KEY_SEQ(INDS.KEYNUM, INDS.KEYINFO, COLS.COLID);",
            $foreignKeysId
        ));

        foreach ($rows as $row) {
            $foreignKeys[$constrainName]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
            $foreignKeys[$constrainName]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        return $foreignKeys;
    }

    /**
     * @inheritDoc
     */
    protected function getAddForeignKeyInstructions(Table $table, ForeignKey $foreignKey): AlterInstructions
    {
        $alter = sprintf(
            'ADD %s',
            $this->getForeignKeySqlDefinition($foreignKey, $table->getName())
        );

        return new AlterInstructions([$alter]);
    }

    /**
     * @inheritDoc
     */
    protected function getDropForeignKeyInstructions($tableName, $constraint): AlterInstructions
    {
        $alter = sprintf(
            'DROP CONSTRAINT %s',
            $this->quoteColumnName($constraint)
        );

        return new AlterInstructions([$alter]);
    }

    /**
     * @inheritDoc
     */
    protected function getDropForeignKeyByColumnsInstructions(string $tableName, array $columns): AlterInstructions
    {
        foreach ($columns as $column) {
            $rows = $this->fetchAll(sprintf(
                "SELECT a.constraint_name
                FROM
                  user_cons_columns a
                JOIN
                  user_constraints c ON a.owner = c.owner AND a.constraint_name = c.constraint_name
                JOIN
                  user_cons_columns b ON c.r_owner = b.owner AND c.r_constraint_name = b.constraint_name
                WHERE
                  c.constraint_type = 'R' AND a.table_name = %s AND a.column_name = %s;",
                $this->getConnection()->quote($tableName),
                $this->getConnection()->quote($column),
            ));

            foreach ($rows as $row) {
                $instructions = new AlterInstructions();
                $newInstr = $this->getDropForeignKeyInstructions($tableName, $row['CONSTRAINT_NAME']);
                $instructions->merge($newInstr);
                $this->executeAlterSteps($tableName, $instructions);
            }
        }

        return new AlterInstructions();
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Phinx\Db\Adapter\UnsupportedColumnTypeException
     */
    public function getSqlType($type, ?int $limit = null): array
    {
        switch ($type) {
            case static::PHINX_TYPE_FLOAT:
            case static::PHINX_TYPE_DOUBLE:
            case static::PHINX_TYPE_DECIMAL:
            case static::PHINX_TYPE_DATE:
            case static::PHINX_TYPE_TEXT:
            case static::PHINX_TYPE_JSON:
            case static::PHINX_TYPE_JSONB:
                return ['name' => $type];
            case static::PHINX_TYPE_DATETIME:
            case static::PHINX_TYPE_TIMESTAMP:
            case static::PHINX_TYPE_TIME:
                return ['name' => $type, 'limit' => $limit];
            case static::PHINX_TYPE_STRING:
                return ['name' => 'varchar', 'limit' => ($limit === null) ? 8188 : $limit];
            case static::PHINX_TYPE_CHAR:
                return ['name' => 'char', 'limit' => ($limit === null) ? 1 : $limit];
            case static::PHINX_TYPE_BINARY:
                return ['name' => 'binary', 'limit' => $limit];
            case static::PHINX_TYPE_VARBINARY:
                return ['name' => 'varbinary', 'limit' => $limit];
            case static::PHINX_TYPE_BLOB:
                return ['name' => 'blob'];
            case static::PHINX_TYPE_BOOLEAN:
                return ['name' => 'tinyint', 'limit' => 1];
            case static::PHINX_TYPE_BIT:
                return ['name' => 'bit'];
            case static::PHINX_TYPE_BIG_INTEGER:
                return ['name' => 'bigint'];
            case static::PHINX_TYPE_SMALL_INTEGER:
                return ['name' => 'smallint'];
            case static::PHINX_TYPE_TINY_INTEGER:
                return ['name' => 'tinyint'];
            case static::PHINX_TYPE_INTEGER:
            case static::PHINX_TYPE_MEDIUM_INTEGER:
                return ['name' => 'int'];
            case static::PHINX_TYPE_UUID:
                return ['name' => 'char', 'limit' => 36];
            // Geometry types
            case static::PHINX_TYPE_GEOMETRY:
                return ['name' => 'sysgeo2.st_geometry', 'type' => 'geometry'];
            case static::PHINX_TYPE_POINT:
                return ['name' => 'sysgeo2.st_geometry', 'type' => 'point'];
            case static::PHINX_TYPE_LINESTRING:
                return ['name' => 'sysgeo2.st_geometry', 'type' => 'linestring'];
            case static::PHINX_TYPE_POLYGON:
                return ['name' => 'sysgeo2.st_geometry', 'type' => 'polygon'];
            case static::PHINX_TYPE_GEOGRAPHY:
                return ['name' => 'sysgeo2.st_geography'];
            default:
                throw new UnsupportedColumnTypeException('Column type "' . $type . '" is not supported by Dm.');
        }
    }

    /**
     * Returns Phinx type by SQL type
     *
     * @internal param string $sqlType SQL type
     * @param string $sqlTypeDef SQL Type definition
     * @throws \Phinx\Db\Adapter\UnsupportedColumnTypeException
     * @return array Phinx type
     */
    public function getPhinxType($sqlTypeDef)
    {
        $matches = [];
        if (!preg_match('/^([\w]+)(\(([\d]+)*(,([\d]+))*\))*(.+)*$/', $sqlTypeDef, $matches)) {
            throw new UnsupportedColumnTypeException('Column type "' . $sqlTypeDef . '" is not supported by Dm.');
        }

        $limit = null;
        $scale = null;
        $type = $matches[1];
        if (count($matches) > 2) {
            $limit = $matches[3] ? (int)$matches[3] : null;
        }
        if (count($matches) > 4) {
            $scale = (int)$matches[5];
        }
        switch ($type) {
            case 'varchar':
                $type = static::PHINX_TYPE_STRING;
                break;
            case 'char':
                $type = static::PHINX_TYPE_CHAR;
                if ($limit === 36) {
                    $type = static::PHINX_TYPE_UUID;
                }
                break;
            case 'tinyint':
                $type = static::PHINX_TYPE_TINY_INTEGER;
                break;
            case 'smallint':
                $type = static::PHINX_TYPE_SMALL_INTEGER;
                break;
            case 'int':
                $type = static::PHINX_TYPE_INTEGER;
                break;
            case 'bigint':
                $type = static::PHINX_TYPE_BIG_INTEGER;
                break;
            case 'bit':
                $type = static::PHINX_TYPE_BIT;
                break;
            case 'blob':
                $type = static::PHINX_TYPE_BLOB;
                break;
            case 'binary':
                $type = static::PHINX_TYPE_BINARY;
                break;
        }

        try {
            $this->getSqlType($type, $limit);
        } catch (UnsupportedColumnTypeException $e) {
            $type = Literal::from($type);
        }

        $phinxType = [
            'name' => $type,
            'limit' => $limit,
            'scale' => $scale,
        ];

        return $phinxType;
    }
    /**
     * @inheritDoc
     */
    public function createDatabase(string $name, array $options = []): void
    {
        throw new UnsupportedColumnTypeException('Create database is not supported by Dm.');
    }

    /**
     * @inheritDoc
     */
    public function hasDatabase(string $name): bool
    {
        throw new UnsupportedColumnTypeException('Search database is not supported by Dm.');
    }

    /**
     * @inheritDoc
     */
    public function dropDatabase($name): void
    {
        throw new UnsupportedColumnTypeException('Drop database is not supported by Dm.');
    }

    /**
     * Gets the Column Definition for a Column object.
     *
     * @param \Phinx\Db\Table\Column $column Column
     * @return string
     */
    protected function getColumnSqlDefinition(Column $column): string
    {
        $buffer = [];

        if ($column->isIdentity()) {
            if ($column->getType() === 'smallinteger') {
                $buffer[] = 'SMALLINT IDENTITY';
            } elseif ($column->getType() === 'biginteger') {
                $buffer[] = 'BIGINT IDENTITY';
            } else {
                $buffer[] = 'INT IDENTITY';
            }
        } elseif ($column->getType() instanceof Literal) {
            $buffer[] = (string)$column->getType();
        } else {
            $sqlType = $this->getSqlType($column->getType(), $column->getLimit());
            $buffer[] = strtoupper($sqlType['name']);

            if ($sqlType['name'] === static::PHINX_TYPE_DECIMAL && ($column->getPrecision() || $column->getScale())) {
                $buffer[] = sprintf(
                    '(%s, %s)',
                    $column->getPrecision() ?: $sqlType['precision'],
                    $column->getScale() ?: $sqlType['scale']
                );
            } elseif ($sqlType['name'] === 'sysgeo2.st_geometry') {
                $buffer[] = sprintf(
                    ' %s %s',
                    'CHECK(type = '.strtoupper($sqlType['type']).')',
                    $column->getSrid() ? 'CHECK(srid = '.$column->getSrid().')' : ''
                );
            } elseif (in_array($sqlType['name'], [self::PHINX_TYPE_TIME, self::PHINX_TYPE_TIMESTAMP, self::PHINX_TYPE_DATETIME], true)) {
                if (is_numeric($column->getPrecision())) {
                    $buffer[] = sprintf('(%s)', $column->getPrecision());
                }

                if ($column->isTimezone()) {
                    $buffer[] = strtoupper('with time zone');
                }
            } elseif (
                !in_array($column->getType(), [
                    self::PHINX_TYPE_TINY_INTEGER,
                    self::PHINX_TYPE_SMALL_INTEGER,
                    self::PHINX_TYPE_INTEGER,
                    self::PHINX_TYPE_BIG_INTEGER,
                    self::PHINX_TYPE_BOOLEAN,
                    self::PHINX_TYPE_TEXT,
                ], true)
            ) {
                if ($column->getLimit() || isset($sqlType['limit'])) {
                    $buffer[] = sprintf('(%s)', $column->getLimit() ?: $sqlType['limit']);
                }
            }
        }

        $buffer[] = $column->isNull() ? 'NULL' : 'NOT NULL';

        if ($column->getDefault() !== null) {
            $buffer[] = $this->getDefaultValueDefinition($column->getDefault(), $column->getType());
        }

        return implode(' ', $buffer);
    }

    /**
     * Gets the Column Comment Definition for a column object.
     *
     * @param \Phinx\Db\Table\Column $column Column
     * @param string $tableName Table name
     * @return string
     */
    protected function getColumnCommentSqlDefinition(Column $column, string $tableName): string
    {
        // passing 'null' is to remove column comment
        $comment = strcasecmp($column->getComment(), 'NULL') !== 0
            ? $this->getConnection()->quote($column->getComment())
            : 'NULL';

        return sprintf(
            'COMMENT ON COLUMN %s.%s IS %s;',
            $this->quoteTableName($tableName),
            $this->quoteColumnName($column->getName()),
            $comment
        );
    }

    /**
     * Gets the Index Definition for an Index object.
     *
     * @param \Phinx\Db\Table\Index $index Index
     * @param string $tableName Table name
     * @return string
     */
    protected function getIndexSqlDefinition(Index $index, string $tableName): string
    {
        $parts = $this->getSchemaName($tableName);
        $columnNames = $index->getColumns();

        if (is_string($index->getName())) {
            $indexName = $index->getName();
        } else {
            $indexName = sprintf('%s_%s', $parts['table'], implode('_', $columnNames));
        }

        $order = $index->getOrder() ?? [];
        $columnNames = array_map(function ($columnName) use ($order) {
            $ret = '"' . $columnName . '"';
            if (isset($order[$columnName])) {
                $ret .= ' ' . $order[$columnName];
            }

            return $ret;
        }, $columnNames);

        $createIndexSentence = 'CREATE %s INDEX %s ON %s(%s) ';

        return sprintf(
            $createIndexSentence,
            ($index->getType() === Index::UNIQUE ? 'UNIQUE' : ''),
            $this->quoteColumnName($indexName),
            $this->quoteTableName($tableName),
            implode(',', $columnNames),
        );
    }

    /**
     * Gets the Dm Foreign Key Definition for an ForeignKey object.
     *
     * @param \Phinx\Db\Table\ForeignKey $foreignKey Foreign key
     * @param string $tableName Table name
     * @return string
     */
    protected function getForeignKeySqlDefinition(ForeignKey $foreignKey, string $tableName): string
    {
        $parts = $this->getSchemaName($tableName);

        $constraintName = $foreignKey->getConstraint() ?: (
            $parts['table'] . '_' . implode('_', $foreignKey->getColumns()) . '_fkey'
        );
        $def = ' CONSTRAINT ' . $this->quoteColumnName($constraintName) .
            ' FOREIGN KEY ("' . implode('", "', $foreignKey->getColumns()) . '")' .
            " REFERENCES {$this->quoteTableName($foreignKey->getReferencedTable()->getName())} (\"" .
            implode('", "', $foreignKey->getReferencedColumns()) . '")';
        if ($foreignKey->getOnDelete()) {
            $def .= " ON DELETE {$foreignKey->getOnDelete()}";
        }
        if ($foreignKey->getOnUpdate()) {
            $def .= " ON UPDATE {$foreignKey->getOnUpdate()}";
        }

        return $def;
    }

    /**
     * @inheritDoc
     */
    public function createSchemaTable(): void
    {
        // Create the custom schema if it doesn't already exist
        if ($this->hasSchema($this->getGlobalSchemaName()) === false) {
            $this->createSchema($this->getGlobalSchemaName());
        }

        parent::createSchemaTable();
    }

    /**
     * Creates the specified schema.
     *
     * @param string $schemaName Schema Name
     * @return void
     */
    public function createSchema(string $schemaName = 'public'): void
    {
        $sql = sprintf('CREATE SCHEMA %s', $this->quoteSchemaName($schemaName));
        $this->execute($sql);
    }

    /**
     * Checks to see if a schema exists.
     *
     * @param string $schemaName Schema Name
     * @return bool
     */
    public function hasSchema(string $schemaName): bool
    {
        $sql = sprintf(
            'select COUNT(*) as COUNT from SYS.VSYSOBJECTS where TYPE$=\'SCH\' and NAME=%s',
            $this->getConnection()->quote($schemaName)
        );
        $result = $this->fetchRow($sql);

        return $result['COUNT'] > 0;
    }

    /**
     * Drops the specified schema table.
     *
     * @param string $schemaName Schema name
     * @return void
     */
    public function dropSchema(string $schemaName): void
    {
        $sql = sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $this->quoteSchemaName($schemaName));
        $this->execute($sql);

        foreach ($this->createdTables as $idx => $createdTable) {
            if ($this->getSchemaName($createdTable)['schema'] === $this->quoteSchemaName($schemaName)) {
                unset($this->createdTables[$idx]);
            }
        }
    }

    /**
     * Drops all schemas.
     *
     * @return void
     */
    public function dropAllSchemas(): void
    {
        foreach ($this->getAllSchemas() as $schema) {
            $this->dropSchema($schema);
        }
    }

    /**
     * Returns schemas.
     *
     * @return array
     */
    public function getAllSchemas(): array
    {
        $sql = "select NAME from SYS.VSYSOBJECTS where TYPE$='SCH'";
        $items = $this->fetchAll($sql);
        $schemaNames = [];
        foreach ($items as $item) {
            $schemaNames[] = $item['NAME'];
        }

        return $schemaNames;
    }

    /**
     * @inheritDoc
     */
    public function getColumnTypes(): array
    {
        return array_merge(parent::getColumnTypes(), static::$specificColumnTypes);
    }

    /**
     * @inheritDoc
     */
    public function isValidColumnType(Column $column): bool
    {
        // If not a standard column type, maybe it is array type?
        return parent::isValidColumnType($column) || $this->isArrayType($column->getType());
    }

    /**
     * Check if the given column is an array of a valid type.
     *
     * @param string|\Phinx\Util\Literal $columnType Column type
     * @return bool
     */
    protected function isArrayType($columnType): bool
    {
        if (!preg_match('/^([a-z]+)(?:\[\]){1,}$/', $columnType, $matches)) {
            return false;
        }

        $baseType = $matches[1];

        return in_array($baseType, $this->getColumnTypes(), true);
    }

    /**
     * @param string $tableName Table name
     * @return array
     */
    protected function getSchemaName(string $tableName): array
    {
        $schema = $this->getGlobalSchemaName();
        $table = $tableName;
        if (strpos($tableName, '.') !== false) {
            [$schema, $table] = explode('.', $tableName);
        }

        return [
            'schema' => $schema,
            'table' => $table,
        ];
    }

    /**
     * Gets the schema name.
     *
     * @return string
     */
    protected function getGlobalSchemaName(): string
    {
        $options = $this->getOptions();

        return empty($options['schema']) ? $options['user'] : $options['schema'];
    }

    /**
     * @inheritDoc
     */
    public function castToBool($value)
    {
        return (bool)$value ? 'TRUE' : 'FALSE';
    }

    /**
     * @inheritDoc
     */
    public function getDecoratedConnection(): Connection
    {
        return new Connection();
    }
}

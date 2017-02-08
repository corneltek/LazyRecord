<?php

namespace LazyRecord\SqlBuilder;

use SQLBuilder\Driver\BaseDriver;
use SQLBuilder\ArgumentArray;
use SQLBuilder\Universal\Query\CreateIndexQuery;
use SQLBuilder\Universal\Syntax\Constraint;
use LazyRecord\Schema\DynamicSchemaDeclare;
use LazyRecord\Schema\SchemaInterface;
use LazyRecord\Schema\Relationship\Relationship;
use LazyRecord\BaseModel;
use LazyRecord\Schema\DeclareColumn;

abstract class BaseBuilder
{
    protected $rebuild;

    protected $clean;

    protected $driver;

    public function __construct(BaseDriver $driver, array $options = array())
    {
        $this->driver = $driver;
        if (isset($options['rebuild'])) {
            $this->rebuild = $options['rebuild'];
        }
        if (isset($options['clean'])) {
            $this->clean = $options['clean'];
        }
    }

    abstract public function buildColumnSql(SchemaInterface $schema, DeclareColumn $column);

    public function setClean($clean = true)
    {
        $this->clean = true;
    }

    public function setRebuild($rebuild = true)
    {
        $this->rebuild = $rebuild;
    }

    public function prepare()
    {
        return [];
    }

    public function finalize()
    {
        return [];
    }

    public function createTable(SchemaInterface $schema)
    {
        $sql = 'CREATE TABLE '
            .$this->driver->quoteIdentifier($schema->getTable())." ( \n";

        $columnSqls = array();
        foreach ($schema->columns as $name => $column) {
            if ($column->virtual) {
                continue;
            }
            $columnSqls[] = '  '.$this->buildColumnSql($schema, $column);
        }
        // $referencesSqls = $this->buildForeignKeys($schema);
        $sql .= implode(",\n", array_merge($columnSqls, []));

        $sql .= "\n);\n";

        return $sql;
    }

    public function build(SchemaInterface $schema)
    {
        if ($schema instanceof BaseModel) {
            $model = $schema;
            $schema = new DynamicSchemaDeclare($model);
        }
        $sqls = [];
        $tableSqls = $this->buildTable($schema);
        $sqls = array_merge($sqls, $tableSqls);

        $indexSqls = $this->buildIndex($schema);
        $sqls = array_merge($sqls, $indexSqls);

        return $sqls;
    }

    public function buildTable(SchemaInterface $schema)
    {
        $sqls = array();
        if ($this->clean || $this->rebuild) {
            $sqls[] = $this->dropTable($schema);
        }
        if ($this->clean) {
            return $sqls;
        }
        $sqls[] = $this->createTable($schema);

        return $sqls;
    }

    public function buildIndex(SchemaInterface $schema)
    {
        // build single column index
        $sqls = array();
        foreach ($schema->columns as $name => $column) {
            if ($column->index) {
                $table = $schema->getTable();
                $indexName = is_string($column->index) ? $column->index
                    : 'idx_'.$table.'_'.$name;
                $query = new CreateIndexQuery($indexName);
                $query->on($table, [$name]);
                if ($column->index_using) {
                    $query->using($column->index_using);
                }
                $sqls[] = $query->toSql($this->driver, new ArgumentArray());
            }
        }
        if ($queries = $schema->getIndexQueries()) {
            foreach ($queries as $query) {
                $sqls[] = $query->toSql($this->driver, new ArgumentArray());
            }
        }

        return $sqls;
    }

    public function buildForeignKeyConstraint(Relationship $rel)
    {
        $constraint = new Constraint();
        $constraint->foreignKey($rel['self_column']);

        $fSchema = new $rel['foreign_schema']();
        $references = $constraint->references($fSchema->getTable(), (array) $rel['foreign_column']);

        return $constraint;
    }

    public function buildForeignKeys(SchemaInterface $schema)
    {
        $sqls = [];
        foreach ($schema->relations as $rel) {
            switch ($rel['type']) {
                case Relationship::BELONGS_TO:
                case Relationship::HAS_MANY:
                case Relationship::HAS_ONE:
                    if ($rel['foreign_schema'] == $rel['self_schema']) {
                        continue;
                    }
                    if (isset($rel['self_column']) && $rel['self_column'] != 'id') {
                        if ($constraint = $this->buildForeignKeyConstraint($rel)) {
                            $sqls[] = $constraint->toSql($this->driver, new ArgumentArray());
                        }
                    }
                break;
            }
        }

        return $sqls;
    }
}

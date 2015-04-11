<?php
namespace LazyRecord\TableParser;
use PDO;
use Exception;
use LazyRecord\Schema\SchemaDeclare;

class MysqlTableParser extends BaseTableParser
{
    public function getTables()
    {
        $stm = $this->connection->query('show tables;');
        $rows = $stm->fetchAll( PDO::FETCH_NUM);
        return array_map(function($row) { return $row[0]; },$rows);
    }

    public function getTableSchema($table)
    {
        $stm = $this->connection->query("show columns from $table;");
        $schema = new SchemaDeclare;
        $schema->columnNames = $schema->columns = array();
        $rows = $stm->fetchAll();
        foreach ($rows as $row) {
            $type = $row['Type'];
            $typeInfo = $this->parseTypeInfo($type);
            $isa = $typeInfo->isa;

            $column = $schema->column($row['Field']);
            $column->type($type);
            $column->null( $row['Null'] === 'YES' );

            if ('PRI' === $row['Key']) {
                $column->primary(true);
                $schema->primaryKey = $row['Field'];
            } else if ('UNI' === $row['Key']) {
                $column->unique(true);
            }

            if ($typeInfo->isa) {
                $column->isa($typeInfo->isa);
            }

            if (NULL !== $row['Default']) {
                // $column->default( array($row['Default']) );
            }
        }
        return $schema;
    }


}

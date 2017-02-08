<?php

namespace LazyRecord\SqlBuilder;

use LazyRecord\Schema\SchemaInterface;
use LazyRecord\Schema\Relationship\Relationship;
use LazyRecord\Schema\DeclareColumn;
use SQLBuilder\ArgumentArray;
use SQLBuilder\Universal\Syntax\Constraint;

class MysqlBuilder extends BaseBuilder
{
    public function prepare()
    {
        return [
            'SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0',
            'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0',
        ];
    }

    public function finalize()
    {
        return [
            'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS',
            'SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS',
        ];
    }

    public function createTable(SchemaInterface $schema)
    {
        $sql = 'CREATE TABLE ';

        $sql .= ' IF NOT EXISTS ';

        $sql .= $this->driver->quoteIdentifier($schema->getTable());

        $sql .= " (\n";

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

    /**
     Please refer to http://dev.mysql.com/doc/refman/5.7/en/innodb-foreign-key-constraints.html for correct foreign key definition.
     */
    public function buildForeignKeyConstraint(Relationship $rel)
    {
        $schemaClass = $rel['foreign_schema'];
        $fSchema = new $schemaClass();
        $constraint = new Constraint();
        $constraint->foreignKey($rel['self_column']);
        $references = $constraint->references($fSchema->getTable(), (array) $rel['foreign_column']);
        if ($act = $rel->onUpdate) {
            $references->onUpdate($act);
        }
        if ($act = $rel->onDelete) {
            $references->onDelete($act);
        }

        return $constraint;
    }

    /**
     * Override buildColumnSql to support inline reference.
     *
     *  MySQL Syntax:
     *  
     *      reference_definition:
     *      REFERENCES tbl_name (index_col_name,...)
     *          [MATCH FULL | MATCH PARTIAL | MATCH SIMPLE]
     *          [ON DELETE reference_option]
     *          [ON UPDATE reference_option]
     *      reference_option:
     *          RESTRICT | CASCADE | SET NULL | NO ACTION
     *  A reference example:
     *
     *      PRIMARY KEY (`idEmployee`) ,
     *      CONSTRAINT `fkEmployee_Addresses`
     *      FOREIGN KEY `fkEmployee_Addresses` (`idAddresses`)
     *      REFERENCES `schema`.`Addresses` (`idAddresses`)
     *      ON DELETE NO ACTION
     *      ON UPDATE NO ACTION
     *
     *  FOREIGN KEY (`order_uuid`) REFERENCES orders(`uuid`)
     */
    public function buildColumnSql(SchemaInterface $schema, DeclareColumn $column)
    {
        $name = $column->name;
        $isa = $column->isa ?: 'str';
        if (!$column->type && $isa == 'str') {
            $column->type = 'text';
        }

        $args = new ArgumentArray();
        $sql = $column->buildDefinitionSql($this->driver, $args);

        /*
        BUILD COLUMN REFERENCE

        track(
        	FOREIGN KEY(trackartist) REFERENCES artist(artistid)
            artist_id INTEGER REFERENCES artist
        )


        And here is the important part:

        Furthermore, MySQL parses but ignores “inline REFERENCES
        specifications” (as defined in the SQL standard) where the references
        are defined as part of the column specification.

        MySQL accepts REFERENCES clauses only when specified as part of a
        separate FOREIGN KEY specification. For storage engines that do not
        support foreign keys (such as MyISAM), MySQL Server parses and ignores
        foreign key specifications.

        A column with foreign key should not be nullable.
        @see http://stackoverflow.com/questions/10028214/add-foreign-key-to-existing-table
        */
        foreach ($schema->relations as $rel) {
            switch ($rel['type']) {
                case Relationship::BELONGS_TO:
                if ($name != 'id' && $rel['self_column'] == $name) {
                    $fSchema = new $rel['foreign_schema']();
                    $fColumn = $rel['foreign_column'];
                }
                break;
            }
        }

        return $sql;
    }

    public function dropTable(SchemaInterface $schema)
    {
        return 'DROP TABLE IF EXISTS '
            .$this->driver->quoteIdentifier($schema->getTable())
            .';';
    }
}

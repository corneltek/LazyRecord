<?php

namespace LazyRecord\Schema\Factory;

use ReflectionClass;
use ReflectionMethod;

use ClassTemplate\ClassFile;
use LazyRecord\Schema\DeclareSchema;
use LazyRecord\ConnectionManager;
use Doctrine\Common\Inflector\Inflector;

// used for SQL generator
use SQLBuilder\Universal\Query\SelectQuery;
use SQLBuilder\Universal\Query\DeleteQuery;
use SQLBuilder\Bind;
use SQLBuilder\ParamMarker;
use SQLBuilder\ArgumentArray;
use CodeGen\Statement\RequireStatement;
use CodeGen\Statement\RequireOnceStatement;
use CodeGen\Expr\ConcatExpr;
use CodeGen\Raw;


/**
 * Base Model class generator.
 *
 * Some rules for generating code:
 *
 * - Mutable values should be generated as propertes.
 * - Immutable values should be generated as constants.
 */
class BaseModelClassFactory
{
    public static function create(DeclareSchema $schema, $baseClass)
    {
        // get data source ids
        $readFrom = $schema->getReadSourceId();
        $writeTo  = $schema->getWriteSourceId();

        // get read connection
        $readConnection = ConnectionManager::getInstance()->getConnection($readFrom);
        $readQueryDriver = $readConnection->getQueryDriver();

        // get write connection
        $writeConnection = ConnectionManager::getInstance()->getConnection($writeTo);
        $writeQueryDriver = $writeConnection->getQueryDriver();

        $primaryKey = $schema->primaryKey;

        $cTemplate = new ClassFile($schema->getBaseModelClass());

        // Generate a require statement here to prevent spl autoload when
        // loading the model class.
        //
        // If the user pre-loaded the schema proxy file by the user himself,
        // then this line will cause error.
        //
        // By design, users shouldn't use the schema proxy class, it 
        // should be only used by model/collection class.
        $schemaProxyFileName = $schema->getModelName() . 'SchemaProxy.php';
        $cTemplate->prependStatement(new RequireOnceStatement(
            new ConcatExpr(new Raw('__DIR__'), DIRECTORY_SEPARATOR . $schemaProxyFileName)
        ));

        $cTemplate->useClass('LazyRecord\\Schema\\SchemaLoader');
        $cTemplate->useClass('LazyRecord\\Result');
        $cTemplate->useClass('LazyRecord\\Inflator');
        $cTemplate->useClass('SQLBuilder\\Bind');
        $cTemplate->useClass('SQLBuilder\\ArgumentArray');
        $cTemplate->useClass('PDO');
        $cTemplate->useClass('SQLBuilder\\Universal\\Query\\InsertQuery');

        $cTemplate->addConsts(array(
            'SCHEMA_CLASS'       => get_class($schema),
            'SCHEMA_PROXY_CLASS' => $schema->getSchemaProxyClass(),
            'COLLECTION_CLASS'   => $schema->getCollectionClass(),
            'MODEL_CLASS'        => $schema->getModelClass(),
            'TABLE'              => $schema->getTable(),
            'READ_SOURCE_ID'     => $schema->getReadSourceId(),
            'WRITE_SOURCE_ID'    => $schema->getWriteSourceId(),
            'PRIMARY_KEY'        => $schema->primaryKey,
            'TABLE_ALIAS'              => 'm',
        ));

        $cTemplate->addProtectedProperty('table', $schema->getTable());
        $cTemplate->addPublicProperty('readSourceId', $schema->getReadSourceId() ?: 'default');
        $cTemplate->addPublicProperty('writeSourceId', $schema->getWriteSourceId() ?: 'default');


        $cTemplate->addStaticVar('column_names',  $schema->getColumnNames());
        $cTemplate->addStaticVar('column_hash',  array_fill_keys($schema->getColumnNames(), 1));
        $cTemplate->addStaticVar('mixin_classes', array_reverse($schema->getMixinSchemaClasses()));

        $cTemplate->addStaticMethod('public', 'getSchema', [], function() use ($schema) {
            return [
                "static \$schema;",
                "if (\$schema) {",
                "   return \$schema;",
                "}",
                "return \$schema = new \\{$schema->getSchemaProxyClass()};",
            ];
        });

        if ($traitClasses = $schema->getModelTraitClasses()) {
            foreach ($traitClasses as $traitClass) {
                $cTemplate->useTrait($traitClass);
            }
        }

        $cTemplate->addStaticMethod('public', 'createRepo', ['$write', '$read'], function() use ($schema) {
            return "return new \\{$schema->getBaseRepoClass()}(\$write, \$read);";
        });


        $cTemplate->extendClass('\\'.$baseClass);

        // interfaces
        if ($ifs = $schema->getModelInterfaces()) {
            foreach ($ifs as $iface) {
                $cTemplate->implementClass($iface);
            }
        }

        // Create column accessor
        $properties = [];
        foreach ($schema->getColumns(false) as $columnName => $column) {
            $propertyName = Inflector::camelize($columnName);
            $properties[] = [$columnName, $propertyName];

            $cTemplate->addPublicProperty($columnName, NULL);

            if ($schema->enableColumnAccessors) {

                if (preg_match('/^is[A-Z]/', $propertyName)) {
                    $accessorMethodName = $propertyName;
                } else if ($column->isa === "bool") {
                    // for column names like "is_confirmed", don't prepend another "is" prefix to the accessor name.
                    $accessorMethodName = 'is'.ucfirst($propertyName);
                } else {
                    $accessorMethodName = 'get'.ucfirst($propertyName);
                }

                $cTemplate->addMethod('public', $accessorMethodName, [], function() use ($column, $columnName, $propertyName) {
                    if ($column->get('inflator')) {
                        return [
                            "if (\$c = \$this->getSchema()->getColumn(\"$columnName\")) {",
                            "     return \$c->inflate(\$this->$columnName, \$this);",
                            "}",
                            "return \$this->$columnName;",
                        ];
                    }
                    if ($column->isa === "int") {
                        return ["return intval(\$this->$columnName);"];
                    } else if ($column->isa === "str") {
                        return ["return \$this->$columnName;"];
                    } else if ($column->isa === "bool") {
                        return [
                            "\$value = \$this->$columnName;",
                            "if (\$value === '' || \$value === null) {",
                            "   return null;",
                            "}",
                            "return boolval(\$value);",
                        ];
                    } else if ($column->isa === "float") {
                        return ["return floatval(\$this->$columnName);"];
                    } else if ($column->isa === "json") {
                        return ["return json_decode(\$this->$columnName);"];
                    }
                    return ["return Inflator::inflate(\$this->$columnName, '{$column->isa}');"];
                });
            }
        }

        $cTemplate->addMethod('public', 'getKeyName', [], function() use ($primaryKey) {
            return
                "return " . var_export($primaryKey, true) . ';'
            ;
        });

        $cTemplate->addMethod('public', 'getKey', [], function() use ($primaryKey) {
            return 
                "return \$this->$primaryKey;"
            ;
        });

        $cTemplate->addMethod('public', 'hasKey', [], function() use ($primaryKey) {
            return 
                "return isset(\$this->$primaryKey);"
            ;
        });

        $cTemplate->addMethod('public', 'setKey', ['$key'], function() use ($primaryKey) {
            return 
                "return \$this->$primaryKey = \$key;"
            ;
        });

        $cTemplate->addMethod('public', 'getData', [], function() use ($properties) {
            return 
                'return [' . join(", ", array_map(function($p) {
                    list($columnName, $propertyName) = $p;
                    return "\"$columnName\" => \$this->$columnName";
                }, $properties)) . '];'
            ;
        });

        $cTemplate->addMethod('public', 'setData', ['array $data'], function() use ($properties) {
            return array_map(function($p) {
                    list($columnName, $propertyName) = $p;
                    return "if (array_key_exists(\"$columnName\", \$data)) { \$this->$columnName = \$data[\"$columnName\"]; }";
                }, $properties);
        });

        $cTemplate->addMethod('public', 'clear', [], function() use ($properties) {
            return array_map(function($p) {
                    list($columnName, $propertyName) = $p;
                    return "\$this->$columnName = NULL;";
                }, $properties);
        });

        return $cTemplate;
    }
}

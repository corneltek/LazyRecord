<?php
namespace LazyRecord\Model;
use LazyRecord\Schema\SchemaLoader;
use LazyRecord\Result;
use SQLBuilder\Bind;
use SQLBuilder\ArgumentArray;
use PDO;
use SQLBuilder\Universal\Query\InsertQuery;
use LazyRecord\BaseModel;
class MetadataBase
    extends BaseModel
{
    const SCHEMA_CLASS = 'LazyRecord\\Model\\MetadataSchema';
    const SCHEMA_PROXY_CLASS = 'LazyRecord\\Model\\MetadataSchemaProxy';
    const COLLECTION_CLASS = 'LazyRecord\\Model\\MetadataCollection';
    const MODEL_CLASS = 'LazyRecord\\Model\\Metadata';
    const TABLE = '__meta__';
    const READ_SOURCE_ID = 'default';
    const WRITE_SOURCE_ID = 'default';
    const PRIMARY_KEY = 'id';
    const FIND_BY_PRIMARY_KEY_SQL = 'SELECT * FROM __meta__ WHERE id = :id LIMIT 1';
    public static $column_names = array (
      0 => 'id',
      1 => 'name',
      2 => 'value',
    );
    public static $column_hash = array (
      'id' => 1,
      'name' => 1,
      'value' => 1,
    );
    public static $mixin_classes = array (
    );
    protected $table = '__meta__';
    public $readSourceId = 'default';
    public $writeSourceId = 'default';
    public function getSchema()
    {
        if ($this->_schema) {
           return $this->_schema;
        }
        return $this->_schema = SchemaLoader::load('LazyRecord\\Model\\MetadataSchemaProxy');
    }
    public function getKeyName()
    {
        return 'id';
    }
    public function getKey()
    {
        return $this->id;
    }
    public function hasKey()
    {
        return isset($this->id);
    }
    public function setKey($key)
    {
        return $this->id = $key;
    }
    public function getStashedData()
    {
        return ["id" => $this->id, "name" => $this->name, "value" => $this->value];
    }
    public function setStashedData(array $data)
    {
        if (array_key_exists("id", $data)) { $this->id = $data["id"]; }
        if (array_key_exists("name", $data)) { $this->name = $data["name"]; }
        if (array_key_exists("value", $data)) { $this->value = $data["value"]; }
    }
}

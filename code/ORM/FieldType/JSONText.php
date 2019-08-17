<?php

/**
 * A text-based database field intended for the storage and querying of JSON
 * structured data.
 *
 * JSON data can be queried in a variety of ways:
 *
 * 1. Simple:            `first()`, `last()` and `nth()`
 * 2. Postgres style:    `->`, `->>` and `#>`
 * 3. JSONPath style:    `$..`, `$.store.book[*].author`, `$..book[?(@.price<10)]` (etc)
 *
 * Note: The extraction techniques employed here are simple key / value comparisons.
 * They do not use any native JSON features of your project's underlying RDBMS,
 * e.g. those found either in PostGreSQL >= v9.3 or MySQL >= v5.7. As such any
 * JSON queries you construct are unlikely to be as performant as a native implementation.
 *
 * Example definition via {@link DataObject::$db} static:
 *
 * <code>
 * private static $db = [
 *  'MyJSON' => JSONText::class
 * ];
 * </code>
 *
 * When using the JSONTextExtension, you also need a `json_field_map` config static:
 *
 * <code>
 * private static $json_field_map = [
 *  'MyJSONField1' => ['SomeField1', 'SomeField2'],
 *  'MyJSONField2' => ['SomeField3', 'SomeField4'],
 * ];
 * </code>
 *
 * See the README and docs/en/usage.md for setup and example queries.
 *
 * @package silverstripe-jsontext
 * @author Russell Michell 2016-2019 <russ@theruss.com>
 */

namespace PhpTek\JSONText\ORM\FieldType;

use SilverStripe\ORM\DB;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBString;
use SilverStripe\Forms\HiddenField;
use Peekmo\JsonPath\JsonStore;
use PhpTek\JSONText\Exception\JSONTextException;
use PhpTek\JSONText\Exception\JSONTextInvalidArgsException;
use PhpTek\JSONText\Exception\JSONTextDataException;
use PhpTek\JSONText\Exception\JSONTextConfigException;

class JSONText extends DBString
{
    /**
     * @var integer
     */
    const JSONTEXT_QUERY_OPERATOR = 1;

    /**
     * @var integer
     */
    const JSONTEXT_QUERY_JSONPATH = 2;

    /**
     * Which RDBMS backend are we using? The value set here changes the actual operators and operator-routines for the
     * given backend.
     *
     * @var string
     * @config
     */
    private static $backend = 'postgres';

    /**
     * Legitimate query return types.
     *
     * @var array
     * @config
     */
    private static $return_types = [
        'json', 'array', 'silverstripe'
    ];

    /**
     * @var boolean
     */
    protected $nullifyEmpty = false;

    /**
     * Default query result return type if nothing different is set via setReturnType().
     *
     * @var string
     */
    protected $returnType = 'json';

    /**
     * A representation of this field's data as a {@link JSONStore} object.
     *
     * @var \Peekmo\JsonPath\JsonStore
     */
    protected $jsonStore;

    /**
     * Taken from {@link Text}.
     *
     * @see    DBField::requireField()
     * @return void
     */
    public function requireField()
    {
        $parts = [
            'datatype'      => 'mediumtext',
            'character set' => 'utf8',
            'collate'       => 'utf8_general_ci',
            'arrayValue'    => $this->arrayValue
        ];

        $values = [
            'type'  => 'text',
            'parts' => $parts
        ];

        DB::require_field($this->tableName, $this->name, $values);
    }

    /**
     * @param  string $title
     * @return HiddenField
     */
    public function scaffoldSearchField($title = null)
    {
        return HiddenField::create($this->getName());
    }

    /**
     * @param  string $title
     * @param  string $params
     * @return HiddenField
     */
    public function scaffoldFormField($title = null, $params = null)
    {
        return HiddenField::create($this->getName());
    }

    /**
     * Tell all class methods to return data as JSON , an array or an array of SilverStripe DBField subtypes.
     *
     * @param  string $type
     * @return JSONText
     * @throws JSONTextInvalidArgsException
     */
    public function setReturnType($type)
    {
        if (!in_array($type, $this->config()->get('return_types'))) {
            $msg = 'Bad type: ' . $type . ' passed to ' . __FUNCTION__ . '()';
            throw new JSONTextInvalidArgsException($msg);
        }

        $this->returnType = $type;

        return $this;
    }

    /**
     * Returns the value of this field as an iterable.
     *
     * @return \Peekmo\JsonPath\JsonStore
     * @throws JSONTextException
     */
    public function getJSONStore()
    {
        if (!$value = $this->getValue()) {
            return new JsonStore('[]');
        }

        if (!$this->isValidJson($value)) {
            $msg = 'DB data is munged.';
            throw new JSONTextException($msg);
        }

        $this->jsonStore = new JsonStore($value);

        return $this->jsonStore;
    }

    /**
     * Returns the JSON value of this field as an array.
     *
     * @return array
     */
    public function getStoreAsArray()
    {
        $store = $this->getJSONStore();

        if (!is_array($store)) {
            return $store->toArray();
        }

        return $store;
    }

    /**
     * Convert an array to JSON via json_encode().
     *
     * @param  array $value
     * @return string null|string
     */
    public function toJson(array $value)
    {
        if (!is_array($value)) {
            $value = (array) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convert an array's values into an array of SilverStripe DBField subtypes ala:
     *
     * - {@link DBInt}
     * - {@link DBFloat}
     * - {@link DBBoolean}
     * - {@link DBVarchar}
     *
     * @param  array $data
     * @return array
     */
    public function toSSTypes(array $data)
    {
        $newList = [];

        foreach ($data as $key => $val) {
            if (is_array($val)) {
                $newList[$key] = $this->toSSTypes($val);
            } else {
                $newList[$key] = $this->castToDBField($val);
            }
        }

        return $newList;
    }

    /**
     * @param  mixed $value
     * @return array
     * @throws JSONTextDataException
     */
    public function toArray($value = null)
    {
        $output = [];
        $value = $value ?: $this->getValue();

        // Check for value
        if (!empty($value)) {
            $output = json_decode($value, true);

            // Check for parse errors
            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new JSONTextDataException('Unable to parse JSONText value into array: ' . json_last_error());
            }
        }

        return $output;
    }

    /**
     * Return an array of the JSON key + value represented as first (top-level) JSON node.
     *
     * @return array
     */
    public function first()
    {
        $data = $this->getStoreAsArray();

        if (empty($data)) {
            return $this->returnAsType([]);
        }

        $key = array_keys($data)[0];
        $val = array_values($data)[0];

        return $this->returnAsType([$key => $val]);
    }

    /**
     * Return an array of the JSON key + value represented as last JSON node.
     *
     * @return array
     */
    public function last()
    {
        $data = $this->getStoreAsArray();

        if (empty($data)) {
            return $this->returnAsType([]);
        }

        $count = count($data) -1;
        $key = array_keys($data)[$count];
        $val = array_values($data)[$count];

        return $this->returnAsType([$key => $val]);
    }

    /**
     * Return an array of the JSON key + value represented as the $n'th JSON node.
     *
     * @param  int $n
     * @return mixed array
     * @throws JSONTextInvalidArgsException
     */
    public function nth($n)
    {
        $data = $this->getStoreAsArray();

        if (empty($data)) {
            return $this->returnAsType([]);
        }

        if (!is_int($n)) {
            $msg = 'Argument passed to ' . __FUNCTION__ . '() must be an integer.';
            throw new JSONTextInvalidArgsException($msg);
        }

        $i = 0;
        foreach ($data as $key => $val) {
            if ($i === $n) {
                return $this->returnAsType([$key => $val]);
            }
            $i++;
        }

        return $this->returnAsType($data);
    }

    /**
     * Return the key(s) + value(s) represented by $operator extracting relevant result from the source JSON's structure.
     * N.b when using the path match operator '#>' with duplicate keys, an indexed array of results is returned.
     *
     * @param  string $operator One of the legitimate operators for the current backend or a valid JSONPath expression.
     * @param  string $operand
     * @return mixed null|array
     * @throws JSONTextInvalidArgsException
     */
    public function query($operator, $operand = null)
    {
        $data = $this->getStoreAsArray();

        if (empty($data)) {
            return $this->returnAsType([]);
        }

        $isOperator = $this->isValidOperator($operator);
        $isExpression = $this->isValidExpression($operator);

        if ($isExpression && !empty($operand)) {
            $msg = 'Cannot pass 2nd param when in JSONPath context in ' . __FUNCTION__ . '()';
            throw new JSONTextInvalidArgsException($msg);
        }

        if ($isOperator) {
            $type = self::JSONTEXT_QUERY_OPERATOR;
        } else if ($isExpression) {
            $type = self::JSONTEXT_QUERY_JSONPATH;
        } else {
            $msg = 'Cannot use: "' . $operator . '" as operand or expression in ' . __FUNCTION__ . '()';
            throw new JSONTextInvalidArgsException($msg);
        }

        if ($marshalled = $this->marshallQuery(func_get_args(), $type)) {
            return $this->returnAsType($marshalled);
        }

        return $this->returnAsType([]);
    }

    /**
     * Based on the passed operator or expression, it marshalls the correct backend
     * matcher method into account.
     *
     * @param  array $args
     * @param  integer $type
     * @return array
     */
    private function marshallQuery($args, $type = 1)
    {
        $operator = $expression = $args[0];
        $operand = isset($args[1]) ? $args[1] : null;
        $operatorParamIsValid = $type === self::JSONTEXT_QUERY_OPERATOR;
        $expressionParamIsValid = $type === self::JSONTEXT_QUERY_JSONPATH;

        if ($operatorParamIsValid) {
            $dbBackendInst = $this->backendFactory($operand);
            $operators = $dbBackendInst->config()->get('allowed_operators');

            foreach ($operators as $routine => $backendOperator) {
                if ($operator === $backendOperator && $result = $dbBackendInst->$routine()) {
                    return $result;
                }
            }
        } else if($expressionParamIsValid) {
            $dbBackendInst = $this->backendFactory($expression);

            if ($result = $dbBackendInst->matchOnExpr()) {
                return $result;
            }
        }

        return [];
    }

    /**
     * Same as standard setValue() method except we can also accept a JSONPath expression. This expression will
     * conditionally update the parts of the field's source JSON referenced by $expr with $value
     * then re-set the entire JSON string as the field's new value.
     *
     * Note: The $expr parameter can only accept JSONPath expressions. Using Postgres operators will not work and will
     * throw an instance of JSONTextException.
     *
     * @param  mixed $value
     * @param  array $record
     * @param  string $expr  A valid JSONPath expression.
     * @return JSONText
     * @throws JSONTextException
     */
    public function setValue($value, $record = null, $expr = '')
    {
        if (is_null($value)) {
            return null;
        }

        if (empty($expr)) {
            if (!$this->isValidDBValue($value)) {
                $msg = 'Invalid data passed to ' . __FUNCTION__ . '()';
                throw new JSONTextInvalidArgsException($msg);
            }

            $this->value = $value;
        } else {
            if (!$this->isValidExpression($expr)) {
                $msg = 'Invalid JSONPath expression: ' . $expr . ' passed to ' . __FUNCTION__ . '()';
                throw new JSONTextInvalidArgsException($msg);
            }

            if (!$this->getJSONStore()->set($expr, $value)) {
                $msg = 'Failed to properly set custom data to the JSONStore in ' . __FUNCTION__ . '()';
                throw new JSONTextDataException($msg);
            }

            $this->value = $this->jsonStore->toString();
        }

        parent::setValue($this->value, $record);

        return $this;
    }

    /**
     * Determine the desired userland format to return all query API method results in.
     *
     * @param  mixed $data
     * @return mixed array|null
     * @throws JSONTextInvalidArgsException
     */
    private function returnAsType($data)
    {
        $data = (array) $data;
        $type = $this->returnType;

        if ($type === 'array') {
            if (!count($data)) {
                return [];
            }

            return $data;
        }

        if ($type === 'json') {
            if (!count($data)) {
                return '[]';
            }

            return $this->toJson($data);
        }

        if ($type === 'silverstripe') {
            if (!count($data)) {
                return null;
            }

            return $this->toSSTypes($data);
        }

        $msg = 'Bad argument passed to ' . __FUNCTION__ . '()';
        throw new JSONTextInvalidArgsException($msg);
    }

    /**
     * Create an instance of {@link JSONBackend} according to the value of JSONText::backend defined in SS config.
     *
     * @param  string operand
     * @return JSONBackend
     * @throws JSONTextConfigException
     */
    protected function backendFactory($operand = '')
    {
        $backend = $this->config()->get('backend');
        $dbBackendClass = '\PhpTek\JSONText\Backend\\' . ucfirst($backend) . 'JSONBackend';

        if (!class_exists($dbBackendClass)) {
            $msg = $dbBackendClass . ' not found.';
            throw new JSONTextConfigException($msg);
        }

        return Injector::inst()->createWithArgs(
            $dbBackendClass, [
            $operand,
            $this
        ]);
    }

    /**
     * Utility method to determine whether a value is really valid JSON or not.
     * The Peekmo JSONStore lib won't accept normally valid JSON values like
     * `true`, `false` & `""` so these need to be explicitly disallowed.
     *
     * @param  string $value
     * @return boolean
     * @todo   Deal with true, false and null then!
     */
    public function isValidJson($value)
    {
        if (!isset($value)) {
            return false;
        }

        return !is_null(json_decode($value, true));
    }

    /**
     * @return boolean
     */
    public function isValidDBValue($value) {
        if (in_array($value, ['true', 'false'])) {
            return false;
        }

        if (is_string($value) && strlen($value) === 0) {
            return true;
        }

        return $this->isValidJson($value);
    }

    /**
     * Is the passed JSON operator valid?
     *
     * @param  string $operator
     * @return boolean
     */
    public function isValidOperator($operator)
    {
        $dbBackendInst = $this->backendFactory();

        return $operator && in_array(
            $operator,
            $dbBackendInst->config()->get('allowed_operators'),
            true
        );
    }

    /**
     * Is the passed JSPONPath expression valid?
     *
     * @param  string $expression
     * @return bool
     */
    public function isValidExpression($expression)
    {
        return (bool) preg_match("#^(\\*|\[\d:\d:\d\]|\\$\.+[^\d]+)#", $expression);
    }

    /**
     * Casts a value to a {@link DBField} subclass.
     *
     * @param  mixed $val
     * @return mixed DBField|array
     */
    private function castToDBField($val)
    {
        if (is_float($val)) {
            return DBField::create_field('Float', $val);
        } else if (is_bool($val)) {
            $value = ($val === true ? 1 : 0);
            return DBField::create_field('Boolean', $value);
        } else if (is_int($val)) {
            return DBField::create_field('Int', $val);
        } else if (is_string($val)) {
            return DBField::create_field('Varchar', $val);
        } else {
            // Default to just returning empty val (castToDBField() is used exclusively from within a loop)
            return $val;
        }
    }

}

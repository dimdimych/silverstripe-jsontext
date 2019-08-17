# Usage

In the examples below, when passed invalid queries, expressions or malformed JSON (where applicable), then an instance of `JSONTextException` is thrown.

## General

You can stipulate the format you want your query results back as by passing: **json**, **array** or **silverstripe** to the `setReturnType()` method:

**JSON**
```
$field = JSONText::create('MyJSON');
$field->setValue('{"a": {"b":{"c": "foo"}}}');
$field->setReturnType('json');
```

**Array**
```
$field = JSONText::create('MyJSON');
$field->setValue('{"a": {"b":{"c": "foo"}}}');
$field->setReturnType('array');
```

**SilverStripe**
```
// Will give you DBVarchar instances for each scalar value
$field = JSONText::create('MyJSON');
$field->setValue('{"a": {"b":{"c": "foo"}}}');
$field->setReturnType('silverstripe');
```

The module's overloaded `setValue()` method is also chainable for a slightly cleaner syntax:

**Chaining**
```
$field = JSONText::create('MyJSON')
    ->setValue('{"a": {"b":{"c": "foo"}}}')
    ->setReturnType('array');
```

## Simple Queries

A small handful of convenience methods exist for querying with: `first()`, `last()` and `nth()` exist for when your source JSON is a simple JSON array:

```
use PhpTek\JSONText\ORM\FieldType\JSONText;

class MyDataObject extends DataObject
{

    /**
     * @var array
     */
    private static $db = [
        'MyJSON'    => JSONText::class
    ];

    /*
     * Returns the first key=>value pair found in the source JSON
     */
    public function getFirstJSONVal()
    {
        return $this->dbObject('MyJSON')->first();
    }

    /*
     * Returns the last key=>value pair found in the source JSON
     */
    public function getLastJSONVal()
    {
        return $this->dbObject('MyJSON')->last();
    }

    /*
     * Returns the 44th key=>value pair found in the source JSON
     * For nested hashes use the Postgres int matcher ("->") or string matcher(s) ("->>").
     */
    public function getNthJSONVal()
    {
        return $this->dbObject('MyJSON')->nth(44);
    }
}
```
    
## Postgres Operators

You can also use Postgres-like JSON querying syntax, for querying more complex JSON data as nested JSON objects:


```
use PhpTek\JSONText\ORM\FieldType\JSONText;

class MyOtherDataObject extends DataObject
{
    /**
     * @var array
     */
    private static $db = [
        'MyJSON'    => JSONText::class
    ];

    /**
     * Returns a key=>value pair based on a strict integer -> key match.
     * If a string is passed, an empty array is returned.
     */
    public function getNestedByIntKey($int)
    {
        return $this->dbObject('MyJSON')->query('->', $int);
    }

    /**
     * Returns a key=>value pair based on a strict string -> key match.
     * If an integer is passed, an empty array is returned.
     */
    public function getNestedByStrKey($str)
    {
        return $this->dbObject('MyJSON')->query('->>', $str);
    }

    /**
     * Returns a value based on a strict string/int match of the key-as-array
     * Given source JSON ala: '{"a": {"b":{"c": "foo"}}}' will return '{"c": "foo"}'
     */
    public function getByPathMatch('{"a":"b"}')
    {
        return $this->dbObject('MyJSON')->query('#>', '{"a":"b"}'; 
    }
}
```
    
## JSONPath Expressions

The most power and control over your source JSON comes from using [JSONPath](http://goessner.net/articles/JsonPath/) expressions.
JSONPath is an XPath-like syntax but specific to traversing JSON.

See: [Table of JSONPath expressions](jsonpath.md)

```
use PhpTek\JSONText\ORM\FieldType\JSONText;

class MyDataObject extends DataObject
{
    /*
     * @var string
     */
     protected $stubJSON = '{
        "store": {
            "book": [ 
                    { "category": "reference",
                      "author": "Nigel Rees",
                    },
                    { "category": "fiction",
                      "author": "Evelyn Waugh",
                    }
              ]
        }';

    /**
     * @var array
     */
    private static $db = [
        'MyJSON'    => JSONText::class
    ];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        if (!$this->MyJSON) {
            $this->setField($this->MyJSON, $this->stubJSON);
        }
    }

    public function doStuffWithMyJSON()
    {
        // Query as Array
        $expr = '$.store.book[*].author'; // The authors of all books in the store 
        $result = $this->dbObject('MyJSON')->query($expr);
        $result->setReturnType('array');
        var_dump($result); // Returns ['Nigel Rees', 'Evelyn Waugh']

        // Query as Array
        $expr = '$..book[1]'; // The second book 
        $result = $this->dbObject('MyJSON')->query($expr);
        $result->setReturnType('array');
        var_dump($this->dbObject('MyJSON')->query($expr)); // Returns ['book' => ['category' => 'reference'], ['author' => 'Nigel Rees']]

        // Query as JSON
        $expr = '$..book[1]'; // The second book 
        $result = $this->dbObject('MyJSON')->query($expr);
        $result->setReturnType('json');
        var_dump($this->dbObject('MyJSON')->query($expr));
        /* Returns:
          {"book": [ 
            { 
                "category": "reference", 
                "author": "Nigel Rees", 
            },
            { 
                "category": "fiction",
                "author": "Evelyn Waugh"
            } ] }
        */
    }
}
```

## Updating and Modifying JSON

No self-respecting JSON query solution would be complete without the ability to selectively modify
nested JSON data. The module overloads `setValue()` to accept an optional 3rd parameter, a valid JSONPath
expression.

If the expression matches >1 JSON nodes, then that result is expressed as an indexed array, and each matching
node will be modified with the data passed to `setValue()` as the standard `$value` (first) param.

Example:

```
use PhpTek\JSONText\ORM\FieldType\JSONText;

class MyDataObject extends DataObject
{
    /*
     * @var string
     */
     protected $stubJSON = '{
        "store": {
            "book": [ 
                { "category": "reference",
                  "author": "Nigel Rees",
                },
                { "category": "fiction",
                  "author": "Evelyn Waugh",
                }
            ]
        }';

    /**
     * @var array
     */
    private static $db = [
        'MyJSON'    => JSONText::class
    ];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        if (!$this->MyJSON) {
            $this->setField('MyJSON', $this->stubJSON);
        }
    }

    /**
     * @param array $update
     * @return mixed void | null
     */
    public function updateMyStuff(array $update = [])
    {
        if (empty($update)) {
            return;
        }

        // Perform a multiple node update
        $newReference = [
            'category'  => $update[0],
            'author'    => $update[1]
        ];

        $field->setValue($newReference, null, '$.store.book.[0]');
    }

}
```

## In the CMS

Using the `JSONTextExtension` we can subvert SilverStripe's default behaviour of
a single `DBField` mapped to a single UI field in `getCMSFields()`. Instead, we can actually map
non DB-backed UI fields, such as a `TextField` for example, to individual JSON values stored in a single `JSONtext` DB field.
In this scenario, the UI field's name becomes a JSON key, and its value, the JSON value.

Simply declare a `$json_field_map` static in your `DataObject` subclasses or directly via YML.
The array's keys are the name(s) of your `JSONText` DB fields and values
are arrays of non UI fields who's data should be stored as JSON in the `JSONText` field.

Alternatively, you can also declare a method called `jsonFieldMap()` in place of `$json_field_map`.
This is useful if you need to be able to generate this list programmatically.

Obviously, your JSON data can only be simple and single dimensional so that there's
an easy to manage relationship between UI field and JSON key=>value pairs.

### Example 1 - Uses Config Static

```
use PhpTek\JSONText\ORM\FieldType\JSONText;

private static $db = [
    'MyJSON' => JSONText::class,
];

private static $json_field_map = [
    'MyJSON' => ['Test1', 'Test2']
];

public function getCMSFields()
{
    $fields = parent::getCMSFields();
    $fields->addFieldsToTab('Root.Main', [
        TextField::create('Test1', 'Test 1'),  // Look no DB!
        TextField::create('Test2', 'Test 2'),  // Look no DB!
        TextField::create('MyJSON', 'My JSON') // Use a TextField just to visualize
    ]);

    return $fields;
}
```

### Example 2 - Uses Method

```
use PhpTek\JSONText\ORM\FieldType\JSONText;
use SilverStripe\Forms\FormField;

private static $db = [
     'MyJSON' => JSONText::class,
];

public function jsonFieldMap() : array
{
     return [
        'MyJSON' => [
            'Test1',
            'Test2',
        ];
}

public function getCMSFields()
{
    $fields = parent::getCMSFields();

    foreach ($this->jsonFieldMap()['MyJSON'] as $uiFieldName) {
        $fields->addFieldToTab('Root.Main', [
            TextField::create($uiFieldName, FormField::name_to_label($uiFieldName)),
        ]);
    }

    return $fields;
}
```

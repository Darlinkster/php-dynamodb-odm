<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-09-08
 * Time: 14:50
 */

namespace Oasis\Mlib\ODM\Dynamodb;

use Doctrine\Common\Annotations\Reader;
use Oasis\Mlib\ODM\Dynamodb\Annotations\CASTimestamp;
use Oasis\Mlib\ODM\Dynamodb\Annotations\Field;
use Oasis\Mlib\ODM\Dynamodb\Annotations\Item;
use Oasis\Mlib\ODM\Dynamodb\Exceptions\AnnotationParsingException;
use Oasis\Mlib\ODM\Dynamodb\Exceptions\ODMException;

class ItemReflection
{
    protected $itemClass;
    
    /** @var  \ReflectionClass */
    protected $reflectionClass;
    /** @var  Item */
    protected $itemDefinition;
    /**
     * @var  array
     * Maps each dynamodb attribute key to its corresponding class property name
     */
    protected $propertyMapping;
    /**
     * @var array
     * Maps each dynamodb attribute key to its type
     */
    protected $attributeTypes;
    /** @var string CAS field name */
    protected $casField;
    /**
     * @var  Field[]
     * Maps class property name to its field definition
     */
    protected $fieldDefinitions;
    /**
     * @var \ReflectionProperty[]
     * Maps each class property name to its reflection property
     */
    protected $reflectionProperties;
    
    public function __construct($itemClass)
    {
        $this->itemClass = $itemClass;
    }
    
    public function dehydrate($obj)
    {
        if (!is_object($obj)) {
            throw new ODMException("You may only dehydrate an object!");
        }
        
        if (!$obj instanceof $this->itemClass) {
            throw new ODMException(
                "Object dehydrated is not of correct type, expected: " . $this->itemClass . ", got: " . get_class($obj)
            );
        }
        
        $array = [];
        foreach ($this->fieldDefinitions as $propertyName => $field) {
            $relfectionProperty = $this->reflectionProperties[$propertyName];
            $oldAccessibility   = $relfectionProperty->isPublic();
            $relfectionProperty->setAccessible(true);
            $value = $relfectionProperty->getValue($obj);
            $relfectionProperty->setAccessible($oldAccessibility);
            $key         = $field->name ? : $propertyName;
            $array[$key] = $value;
        }
        
        return $array;
    }
    
    public function hydrate(array $array, $obj = null)
    {
        if ($obj === null) {
            $obj = $this->getReflectionClass()->newInstanceWithoutConstructor();
        }
        elseif (!is_object($obj) || !$obj instanceof $this->itemClass) {
            throw new ODMException("You can not hydrate an object of wrong type, expected: " . $this->itemClass);
        }
        
        foreach ($array as $key => $value) {
            if (!isset($this->propertyMapping[$key])) {
                // this property is not defined, skip it
                mwarning("Got an unknown attribute: %s with value %s", $key, print_r($value, true));
                continue;
            }
            $propertyName    = $this->propertyMapping[$key];
            $fieldDefinition = $this->fieldDefinitions[$propertyName];
            if ($fieldDefinition->type == "string") {
                // cast to string because dynamo stores "" as null
                $value = strval($value);
            }
            $relfectionProperty = $this->reflectionProperties[$propertyName];
            $oldAccessibility   = $relfectionProperty->isPublic();
            $relfectionProperty->setAccessible(true);
            $relfectionProperty->setValue($obj, $value);
            $relfectionProperty->setAccessible($oldAccessibility);
        }
        
        return $obj;
    }
    
    public function parse(Reader $reader)
    {
        // initialize class annotation info
        $this->reflectionClass = new \ReflectionClass($this->itemClass);
        $this->itemDefinition  = $reader->getClassAnnotation($this->reflectionClass, Item::class);
        if (!$this->itemDefinition) {
            throw new AnnotationParsingException("Class " . $this->itemClass . " is not configured as an Item");
        }
        
        // initialize property annotation info
        $this->propertyMapping      = [];
        $this->fieldDefinitions     = [];
        $this->reflectionProperties = [];
        $this->attributeTypes       = [];
        $this->casField             = '';
        foreach ($this->reflectionClass->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }
            $propertyName                              = $reflectionProperty->getName();
            $this->reflectionProperties[$propertyName] = $reflectionProperty;
            
            /** @var Field $field */
            $field = $reader->getPropertyAnnotation($reflectionProperty, Field::class);
            if (!$field) {
                continue;
            }
            $fieldName                             = $field->name ? : $propertyName;
            $this->propertyMapping[$fieldName]     = $propertyName;
            $this->fieldDefinitions[$propertyName] = $field;
            $this->attributeTypes[$fieldName]      = $field->type;
            if ($reader->getPropertyAnnotation($reflectionProperty, CASTimestamp::class)) {
                if ($this->casField) {
                    throw new AnnotationParsingException(
                        "Duplicate CASTimestamp field: " . $this->casField . ", " . $fieldName
                    );
                }
                $this->casField = $fieldName;
            }
        }
    }
    
    /**
     * @return mixed
     */
    public function getAttributeTypes()
    {
        return $this->attributeTypes;
    }
    
    /**
     * @return mixed
     */
    public function getCasField()
    {
        return $this->casField;
    }
    
    /**
     * @return mixed
     */
    public function getItemClass()
    {
        return $this->itemClass;
    }
    
    public function getPrimaryIdentifier($obj)
    {
        $id = '';
        foreach ($this->getPrimaryKeys($obj) as $key => $value) {
            $id .= md5($value);
        }
        
        return md5($id);
    }
    
    public function getPrimaryKeys($obj)
    {
        $keys = [];
        foreach ($this->itemDefinition->primaryIndex as $key) {
            if (is_array($obj)) {
                if (!isset($obj[$key])) {
                    throw new ODMException("Cannot get identifier for incomplete object! <" . $key . "> is empty!");
                }
                $value = $obj[$key];
            }
            else {
                if (!isset($this->propertyMapping[$key])) {
                    throw new AnnotationParsingException("Primary field " . $key . " is not defined.");
                }
                $propertyName       = $this->propertyMapping[$key];
                $relfectionProperty = $this->reflectionProperties[$propertyName];
                $oldAccessibility   = $relfectionProperty->isPublic();
                $relfectionProperty->setAccessible(true);
                $value = $relfectionProperty->getValue($obj);
                $relfectionProperty->setAccessible($oldAccessibility);
            }
            
            $keys[$key] = $value;
        }
        
        return $keys;
    }
    
    /**
     * @return \ReflectionClass
     */
    public function getReflectionClass()
    {
        return $this->reflectionClass;
    }
    
    public function getRepositoryClass()
    {
        return $this->itemDefinition->repository ? : ItemRepository::class;
    }
    
    public function getTableName()
    {
        return $this->itemDefinition->table;
    }
}
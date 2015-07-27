<?php
namespace Drest;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\Common\Persistence\ManagerRegistry;
use \Doctrine\Common\Persistence\Mapping\ClassMetadata as ORMClassMetadata;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator;

/**
 * Class generator used to create client classes
 * @author Lee
 *
 */
class ClassGenerator
{

    /**
     * Header parameter to look for if a request for class info has be done
     * @var string HEADER_PARAM
     */
    const HEADER_PARAM = 'X-DrestCG';

    /**
     * Param types - used in setter method generators
     * @var integer
     */
    const PARAM_TYPE_ITEM = 1;
    const PARAM_TYPE_RELATION_SINGLE = 2;
    const PARAM_TYPE_RELATION_COLLECTION = 3;

    /**
     * CG classes generated from routeMetaData
     * @var array $classes - uses className as the key
     */
    protected $classes = array();

    /**
     * Entity manager - required to detect relation types and classNames on expose data
     * @param EntityManagerRegistry $emr
     */
    protected $emr;

    /**
     * Create an class generator instance
     * @param ManagerRegistry $emr
     */
    public function __construct(ManagerRegistry $emr)
    {
        $this->emr = $emr;
    }

    /**
     * Create a class generator instance from provided route metadata.
     * Each route will generate it's own unique version of the class (as it will have its own exposure definitions)
     * @param  array $classMetadatas
     * @return array $object - an array of ClassGenerator objects
     */
    public function create(array $classMetadatas)
    {
        foreach ($classMetadatas as $classMetaData) {
            /* @var \Drest\Mapping\ClassMetaData $classMetaData */
            $expose = array();
            foreach ($classMetaData->getRoutesMetaData() as $routeMetaData) {
                /* @var \Drest\Mapping\RouteMetaData $routeMetaData */
                $expose = array_merge_recursive($expose, $routeMetaData->getExpose());
            }
            $this->recurseParams($expose, $classMetaData->getClassName());
        }

        serialize($this->classes);
    }

    /**
     * Return the generated classes in serialized form
     * @return string $serialized
     */
    public function serialize()
    {
        return serialize($this->classes);
    }


    /**
     * Recurse the expose parameters - pass the entities full class name (including namespace)
     * @param array  $expose
     * @param string $fullClassName
     */
    protected function recurseParams(array $expose, $fullClassName)
    {
        // get ORM metadata for the current class
        /** @var \Doctrine\ORM\Mapping\ClassMetadata $ormClassMetaData */
        $ormClassMetaData = $this->emr->getManagerForClass($fullClassName)->getClassMetadata($fullClassName);

        if (isset($this->classes[$fullClassName])) {
            $cg = $this->classes[$fullClassName];
        } else {
            $cg = new Generator\ClassGenerator();
            $cg->setName($fullClassName);

            $short = 'This class was generated by the drest-client tool, and should be treated as a plain data object';
            $long = <<<EOT
ANY ALTERATIONS WILL BE OVERWRITTEN if the classes are regenerated
The variables declared are exposed by the rest endpoint provided when generating these classes.
However depending on the operation (GET/POST/PUT etc) used some of these may not be populated / operational.
EOT;
            $docBlock = new Generator\DocBlockGenerator($short, $long);
            $cg->setDocBlock($docBlock);

            $cg->addMethods(array($this->getStaticCreateMethod($this->getTargetType($fullClassName))));
        }

        foreach ($expose as $key => $value) {
            if (is_array($value)) {
                if ($ormClassMetaData->hasAssociation($key)) {
                    $this->handleAssocProperty($key, $cg, $ormClassMetaData);

                    $assocMapping = $ormClassMetaData->getAssociationMapping($key);
                    $this->recurseParams($value, $assocMapping['targetEntity']);
                }
            } else {
                if ($ormClassMetaData->hasAssociation($value)) {
                    // This is an association field with no explicit include fields,
                    // include add data field (no relations)
                    $this->handleAssocProperty($value, $cg, $ormClassMetaData);

                    $assocMapping = $ormClassMetaData->getAssociationMapping($value);
                    /** @var \Doctrine\ORM\Mapping\ClassMetadata $teCmd */
                    $teCmd = $this->emr->getManagerForClass($assocMapping['targetEntity'])->getClassMetadata($assocMapping['targetEntity']);
                    $this->recurseParams($teCmd->getColumnNames(), $assocMapping['targetEntity']);
                } else {
                    $this->handleNonAssocProperty($value, $cg);
                }
            }
        }

        // If the class is already set, overwrite it with it additional expose fields
        $this->classes[$fullClassName] = $cg;
    }

    /**
     * Build a ::create() method for each data class
     * @param  string                               $class - The name of the class returned (self)
     * @return \Zend\Code\Generator\MethodGenerator $method
     */
    private function getStaticCreateMethod($class)
    {
        $method = new Generator\MethodGenerator();
        $method->setDocBlock('@return ' . $class . ' $instance');
        $method->setBody('return new self();');
        $method->setName('create');
        $method->setStatic(true);

        return $method;
    }

    /**
     * Create a property instance
     * @param  string                      $name - property name
     * @return Generator\PropertyGenerator $property
     */
    private function createProperty($name)
    {
        $property = new Generator\PropertyGenerator();
        $property->setName($name);
        $property->setVisibility(Generator\AbstractMemberGenerator::FLAG_PUBLIC);

        return $property;
    }

    /**
     * get setter methods for a parameter based on type
     * @param  Generator\ClassGenerator $cg
     * @param  string                   $name        - the parameter name
     * @param  int                      $type        - The type of parameter to be handled
     * @param  string                   $targetClass - the target class name to be set (only used in relational setters)
     * @return array                    $methods
     */
    private function getSetterMethods(&$cg, $name, $type, $targetClass = null)
    {
        $methods = array();
        switch ($type) {
            case self::PARAM_TYPE_ITEM:
                $method = new Generator\MethodGenerator();
                $method->setDocBlock('@param string $' . $name);

                $method->setParameter(new ParameterGenerator($name));
                $method->setBody('$this->' . $name . ' = $' . $name . ';');

                $method->setName('set' . $this->camelCaseMethodName($name));
                $methods[] = $method;
                break;
            case self::PARAM_TYPE_RELATION_SINGLE:
                $method = new Generator\MethodGenerator();
                $method->setDocBlock('@param ' . $targetClass . ' $' . $name);

                $method->setParameter(new ParameterGenerator($name, $this->getTargetType($targetClass)));
                $method->setBody('$this->' . $name . ' = $' . $name . ';');
                $method->setName('set' . $this->camelCaseMethodName($name));
                $methods[] = $method;
                break;
            case self::PARAM_TYPE_RELATION_COLLECTION:
                $singledName = Inflector::singularize($name);
                $method = new Generator\MethodGenerator();
                $method->setDocBlock('@param ' . $targetClass . ' $' . $singledName);


                $method->setParameter(new ParameterGenerator($singledName, $this->getTargetType($targetClass)));

                $method->setBody('$this->' . $name . '[] = $' . $singledName . ';');
                $singleMethodName = 'add' . $this->camelCaseMethodName($singledName);
                $method->setName($singleMethodName);
                $methods[] = $method;

                $pluralName = Inflector::pluralize($name);
                if ($singledName === $pluralName) {
                    // Unable to generate a pluralized collection method
                    break;
                }

                $pluralMethod = new Generator\MethodGenerator();
                $pluralMethod->setDocBlock('@param array $' . $name);

                $pluralMethod->setName('add' . $this->camelCaseMethodName($pluralName));
                $pluralMethod->setParameter(new ParameterGenerator($pluralName, 'array'));
                $body = "foreach (\$$pluralName as \$$singledName) \n{\n";
                $body .= "    \$this->$singleMethodName(\$$singledName);\n}";
                $pluralMethod->setBody($body);

                $methods[] = $pluralMethod;
                break;
        }

        // All setter methods will return $this
        for ($x = 0; $x < sizeof($methods); $x++) {
            /** @var Generator\MethodGenerator $methods [$x] * */
            $docBlock = $methods[$x]->getDocBlock();
            $docBlock->setShortDescription($docBlock->getShortDescription() . "\n@return " . $cg->getName() . ' $this');
            $methods[$x]->setDocBlock($docBlock);
            $methods[$x]->setBody($methods[$x]->getBody() . "\nreturn \$this;");
        }

        return $methods;
    }

    /**
     * Handle a non associative property
     * @param string                              $name - name of the field
     * @param \Zend\Code\Generator\ClassGenerator $cg   - The class generator object to attach to
     */
    private function handleNonAssocProperty($name, Generator\ClassGenerator &$cg)
    {
        $property = $this->createProperty($name);
        if (!$cg->hasProperty($name)) {
            $cg->addProperties(array($property));
            $cg->addMethods($this->getSetterMethods($cg, $name, self::PARAM_TYPE_ITEM));
        }
    }

    /**
     * Handle an associative property field
     * @param string                              $name             - name of the field
     * @param \Zend\Code\Generator\ClassGenerator $cg               - The class generator object to attach to
     * @param ORMClassMetadata $ormClassMetaData - The ORM class meta data
     */
    private function handleAssocProperty($name, Generator\ClassGenerator &$cg, ORMClassMetadata $ormClassMetaData)
    {
        /** @var \Doctrine\ORM\Mapping\ClassMetaData $ormClassMetaData */
        $assocMapping = $ormClassMetaData->getAssociationMapping($name);
        $property = $this->createProperty($name);

        if ($assocMapping['type'] & $ormClassMetaData::TO_MANY) {
            // This is a collection (should be an Array)
            $property->setDocBlock('@var array $' . $name);
            $property->setDefaultValue(array());
            $paramType = self::PARAM_TYPE_RELATION_COLLECTION;
        } else {
            // This is a single relation
            $property->setDocBlock('@var ' . $assocMapping['targetEntity'] . ' $' . $name);
            $paramType = self::PARAM_TYPE_RELATION_SINGLE;
        }

        if (!$cg->hasProperty($name)) {
            $cg->addProperties(array($property));
            $cg->addMethods($this->getSetterMethods($cg, $name, $paramType, $assocMapping['targetEntity']));
        }
    }


    /**
     * camel case a parameter into a suitable method name
     * @param  string $name
     * @return string $name
     */
    private function camelCaseMethodName($name)
    {
        return implode(
            '',
            array_map(
                function ($item) {
                    return ucfirst($item);
                },
                explode('_', $name)
            )
        );
    }

    /**
     * Get the target type class (excludes any namespace)
     * @param  string $targetClass
     * @return string
     */
    private function getTargetType($targetClass)
    {
        $parts = explode('\\', $targetClass);

        return (sizeof($parts) > 1) ? implode('\\', array_slice($parts, 1)) : $targetClass;
    }
}

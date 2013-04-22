<?php


namespace Drest\Mapping\Driver;


use Drest\DrestException;

use	Doctrine\Common\Annotations,
    Doctrine\Common\Persistence\Mapping\Driver as PersistenceDriver,
    Drest\Mapping\Driver\DriverInterface,
	Drest\Mapping,
	Drest\Mapping\Annotation;

/**
 * The AnnotationDriver reads the mapping metadata from docblock annotations.
 * Doesn't require paths / file extensions as entities are pull from the doctrine entity manager
 */
class AnnotationDriver implements DriverInterface
{

	/**
	 * Annotations reader
	 * @var Doctrine\Common\Annotations\AnnotationReader $reader
	 */
    private $reader;

    /**
     * The paths to look for mapping files - immutable as classNames as cached, must be passed on construct.
     * @var array
     */
    protected $paths;

    /**
     * Loaded classnames
     * @var array
     */
    protected $classNames = array();

    /**
     * Extensions of the files to read
     * @var array
     */
    protected $extensions = array();


	public function __construct(Annotations\AnnotationReader $reader, $paths = array())
    {
        $this->reader = $reader;
        $this->paths = $paths;

        $this->addExtension('php');
    }

    /**
     * Get paths to annotation classes
     * @return array
     */
    public function getPaths()
    {
        return $this->paths;
    }

    /**
     * Add an extension to look for classes
     * @param mixed $extension - can be a string or an array of extensions
     */
    public function addExtension($extension)
    {
        $extension = (array) $extension;
        foreach ($extension as $ext)
        {
            if (!in_array($ext, $this->extensions))
            {
                $this->extensions[] = strtolower(preg_replace("/[^a-zA-Z0-9.\s]/", "", $ext));
            }
        }
    }

    /**
     * Remove all registered extensions, if an extension name is passed, only remove that entry
     * @param string $extension
     */
    public function removeExtensions($extension = null)
    {
        if (is_null($extension))
        {
            $this->extensions = array();
        } else
        {
            $offset = array_search($extension, $this->extensions);
            if ($offset !== false)
            {
                unset($this->extensions[$offset]);
            }
        }
    }


    /**
     * Get all the metadata class names known to this driver.
     * @return array $classes
     */
    public function getAllClassNames()
    {
        if (empty($this->classNames))
        {
            if (empty($this->paths))
            {
                throw DrestException::pathToConfigFilesRequired();
            }
            $classes = array();
            $included = array();
            foreach ($this->paths as $path)
            {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file)
                {
                    if (!in_array($file->getExtension(), $this->extensions))
                    {
                        continue;
                    }

                    $path = $file->getRealPath();
                    require_once $path;
                    // Register the files we've included here
                    $included[] = $path;
                }
            }

            foreach (get_declared_classes() as $className)
            {
                $reflClass = new \ReflectionClass($className);
                $sourceFile = $reflClass->getFileName();
                if (in_array($sourceFile, $included) && $this->isDrestResource($className))
                {
                    $classes[] = $className;
                }
            }

            $this->classNames = $classes;
        }

        return $this->classNames;
    }

    /**
     * Does the class contain a drest resource object
     * @param string $className
     */
    public function isDrestResource($className)
    {
        $classAnnotations = $this->reader->getClassAnnotations(new \ReflectionClass($className));

        foreach ($classAnnotations as $classAnnotation)
        {
            if ($classAnnotation instanceof Annotation\Resource)
            {
                return true;
            }
        }
        return false;
    }


	/**
	 * Load metadata for a class name
	 * @param object|string $className - Pass in either the class name, or an instance of that class
	 * @return Drest\Mapping\ClassMetaData $metaData - return null if metadata couldn't be populated from annotations
	 */
    public function loadMetadataForClass($class)
    {
        $resourceFound = false;

        if (is_string($class))
        {
            $class = new \ReflectionClass($class);
        }

        $metadata = new Mapping\ClassMetadata($class);
        foreach ($this->reader->getClassAnnotations($class) as $annotatedObject)
        {
        	if ($annotatedObject instanceof Annotation\Resource)
        	{
        	    $resourceFound = true;
        	    if ($annotatedObject->services === null)
        	    {
        	        throw DrestException::annotatedResourceRequiresAtLeastOneServiceDefinition($class->name);
        	    }

        	    $metadata->addWriters($annotatedObject->writers);

        	    foreach ($annotatedObject->services as $service)
        	    {
        	        $serviceMetaData = new Mapping\ServiceMetaData();

        	        // Set name
        	        $service->name = preg_replace("/[^a-zA-Z0-9_\s]/", "", $service->name);
        	        if ($service->name == '')
        	        {
                        throw DrestException::serviceNameIsEmpty();
        	        }
        	        if ($metadata->getServicesMetaData($service->name) !== false)
        	        {
        	            throw DrestException::serviceAlreadyDefinedWithName($class->name, $service->name);
        	        }
                    $serviceMetaData->setName($service->name);

                    // Set verbs (will throw if invalid)
        	        if (isset($service->verbs))
        	        {
        	            $serviceMetaData->setVerbs($service->verbs);
        	        }

        	        // Set content type (will throw if invalid)
        	        $serviceMetaData->setContentType($service->content);

        	        // Add the route
        	        /** @todo: run validation checks on route syntax? */
        	        $serviceMetaData->setRoutePattern($service->route_pattern);

        	        if (is_array($service->route_conditions))
        	        {
                        $serviceMetaData->setRouteConditions($service->route_conditions);
        	        }

        	        // Add repository method
        	        $serviceMetaData->setRepositoryMethod($service->repository_method);

                    $metadata->addServiceMetaData($serviceMetaData);
        	    }

        	}
        	if ($annotatedObject instanceof \Doctrine\ORM\Mapping\Entity)
        	{
        	    if (isset($annotatedObject->repositoryClass))
        	    {
        	        // Inject the annotated repository class into out metadata
        	        $metadata->setRepositoryClass($annotatedObject->repositoryClass);
        	    }
        	}
        }

        return ($resourceFound) ? $metadata : null;
    }


    /**
     * Factory method for the Annotation Driver
     *
     * @param AnnotationReader $reader
     * @param array|string $paths
     * @return AnnotationDriver
     */
    public static function create(Annotations\AnnotationReader $reader = null, $paths = array())
    {
        if ($reader == null) {
            $reader = new Annotations\AnnotationReader();
        }

        return new self($reader, $paths);
    }

	public static function registerAnnotations()
	{
		Annotations\AnnotationRegistry::registerFile( __DIR__ . '/DrestAnnotations.php');
	}
}

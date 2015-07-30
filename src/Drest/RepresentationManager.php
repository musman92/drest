<?php
/**
 * This file is part of the Drest package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Lee Davis
 * @copyright Copyright (c) Lee Davis <@leedavis81>
 * @link https://github.com/leedavis81/drest/blob/master/LICENSE
 * @license http://opensource.org/licenses/MIT The MIT X License (MIT)
 */
namespace Drest;

use DrestCommon\Representation\RepresentationException;
use DrestCommon\Representation\UnableToMatchRepresentationException;
use DrestCommon\Representation\AbstractRepresentation;
use Drest\Mapping\RouteMetaData;
use DrestCommon\Request\Request;

class RepresentationManager
{

    /**
     * Drest configuration object - referenced to same instance used in Manager
     * @var Configuration $config
     */
    protected $config;

    /**
     * A request instance for inspection
     * Reset on each getDeterminedRepresentation()
     * @var Request $request
     */
    protected $request;

    /**
     * @param Configuration $config
     */
    public function __construct(Configuration &$config)
    {
        $this->config = &$config;
    }

    /**
     * Detect an instance of a representation class using a matched route, or default representation classes
     * @param  Request                      $request
     * @param  RouteMetaData                $route
     * @throws UnableToMatchRepresentationException
     * @throws RepresentationException              - if unable to instantiate a representation object from config settings
     * @return AbstractRepresentation               $representation
     */
    public function getDeterminedRepresentation(Request $request, RouteMetaData &$route = null)
    {
        $this->request = $request;

        if (($representations = $this->getRepresentationClasses($route)) === []) {
            $name = (is_null($route)) ? '"unknown name"' : $route->getName();
            $className = (is_null($route)) ? '"unknown class"' : $route->getClassMetaData()->getClassName();
            throw RepresentationException::noRepresentationsSetForRoute(
                $name,
                $className
            );
        }

        if (($representation = $this->searchAndValidateRepresentations($representations)) !== null) {
            return $representation;
        }

        // We have no representation instances from either annotations or config object
        throw UnableToMatchRepresentationException::noMatch();
    }



    /**
     * Get representation options. Determined from route or config
     * @param RouteMetaData|null $route
     * @return array
     */
    protected function getRepresentationClasses(Mapping\RouteMetaData &$route = null)
    {
        return (is_null($route) || [] === $route->getClassMetaData()->getRepresentations())
            ? $this->config->getDefaultRepresentations()
            : $route->getClassMetaData()->getRepresentations();
    }


    /**
     * Iterate through an array of representations and return a match
     * @param array $representations
     * @return AbstractRepresentation|null
     * @throws RepresentationException
     * @throws UnableToMatchRepresentationException
     */
    protected function searchAndValidateRepresentations(array $representations)
    {
        $representationObjects = [];
        foreach ($representations as $representation) {
            if (($representationObj = $this->matchRepresentation($representation, $representationObjects)) instanceof AbstractRepresentation)
            {
                return $representationObj;
            }
        }

        // For get requests with "415 for no media match" set on, throw an exception
        if ($this->request->getHttpMethod() == Request::METHOD_GET && $this->config->get415ForNoMediaMatchSetting()) {
            throw UnableToMatchRepresentationException::noMatch();
        }

        // Return the first instantiated representation instance
        if (isset($representationObjects[0])) {
            return $representationObjects[0];
        }

        return null;
    }


    /**
     * Attempt to match a representation
     *
     * @param AbstractRepresentation|string $representation
     * @param array $representationObjects
     * @return AbstractRepresentation|null
     * @throws RepresentationException
     */
    protected function matchRepresentation($representation, array &$representationObjects)
    {
        if (!is_object($representation)) {
            $className = $this->getRepresentationClassName($representation);
            $representationObjects[] = $representation = new $className();
        }
        if (!$representation instanceof AbstractRepresentation) {
            throw RepresentationException::representationMustBeInstanceOfDrestRepresentation();
        }

        if (($representation = $this->determineRepresentationByHttpMethod($representation, $this->config->getDetectContentOptions())) !== null)
        {
            return $representation;
        }
        return null;
    }

    /**
     * Determine the representation by inspecting the HTTP method
     * @param AbstractRepresentation $representation
     * @param array $detectContentOptions - Eg array(self::DETECT_CONTENT_HEADER => 'Accept')
     * @return AbstractRepresentation|null
     */
    protected function determineRepresentationByHttpMethod(AbstractRepresentation $representation, array $detectContentOptions = [])
    {
        switch ($this->request->getHttpMethod()) {
            // Match on content option
            case Request::METHOD_GET:
                // This representation matches the required media type requested by the client
                if ($representation->isExpectedContent($detectContentOptions, $this->request)) {
                    return $representation;
                }
                break;
            // Match on content-type
            case Request::METHOD_POST:
            case Request::METHOD_PUT:
            case Request::METHOD_PATCH:
                if ($representation->getContentType() === $this->request->getHeaders('Content-Type')) {
                    return $representation;
                }
                break;
        }
        return null;
    }


    /**
     * Get's the representation class name.
     * Removes any root NS chars
     * Falls back to a DrestCommon Representation lookup
     *
     * @param string $representation
     * @return string
     * @throws RepresentationException
     */
    protected function getRepresentationClassName($representation)
    {
        $className = (strstr($representation, '\\') !== false)
            ? '\\' . ltrim($representation, '\\')
            : $representation;
        $className = (!class_exists($className))
            ? '\\DrestCommon\\Representation\\' . ltrim($className, '\\')
            : $className;
        if (!class_exists($className)) {
            throw RepresentationException::unknownRepresentationClass($representation);
        }
        return $className;
    }
}
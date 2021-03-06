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
namespace Drest\Service\Action;

use Doctrine\ORM;
use Drest\Mapping\RouteMetaData;
use Drest\Service;
use DrestCommon\Error\Response\ResponseInterface;
use DrestCommon\Representation\AbstractRepresentation;
use DrestCommon\Request\Request;
use DrestCommon\Response\Response;
use DrestCommon\ResultSet;

/**
 * abstract action class.
 * This should be extended for creating and registering custom service actions
 * @author Lee
 *
 */
abstract class AbstractAction implements ActionInterface
{
    /**
     * The service class this action is registered to
     * @var Service $service
     */
    protected $service;

    /**
     * additional key fields that are included in partial queries to make the DQL valid
     * These columns should be purged from the result set
     * @var array $addedKeyFields
     */
    protected $addedKeyFields;

    /**
     * Set the service object
     * @param Service $service
     */
    public function setService(Service $service)
    {
        $this->service = $service;
    }

    /**
     * Get the service class this action is registered against
     * @return Service
     */
    protected function getService()
    {
        return $this->service;
    }

    /**
     * get the matched route object
     * @return RouteMetaData $route
     */
    protected function getMatchedRoute()
    {
        return $this->service->getMatchedRoute();
    }

    /**
     * Get the entity manager from the service object (uses the default manager)
     * @return \Doctrine\ORM\EntityManager $em
     */
    protected function getEntityManager()
    {
        return $this->getEntityManagerRegistry()->getManager();
    }

    /**
     * Get the entity manager registry
     * @return \Drest\EntityManagerRegistry
     */
    protected function getEntityManagerRegistry()
    {
        return $this->service->getEntityManagerRegistry();
    }

    /**
     * Get the response object
     * @return Response $response
     */
    protected function getResponse()
    {
        return $this->service->getResponse();
    }

    /**
     * Get the request object
     * @return Request $request
     */
    protected function getRequest()
    {
        return $this->service->getRequest();
    }

    /**
     * Get the predetermined representation
     * @return AbstractRepresentation
     */
    public function getRepresentation()
    {
        return $this->service->getRepresentation();
    }

    /**
     * Handle an error - set the resulting error document to the response object
     * @param  \Exception        $e
     * @param  integer           $defaultResponseCode the default response code to use if no match on exception type occurs
     * @param  ResponseInterface $errorDocument
     * @return ResultSet         the error result set
     */
    public function handleError(\Exception $e, $defaultResponseCode = 500, ResponseInterface $errorDocument = null)
    {
        return $this->service->handleError($e, $defaultResponseCode, $errorDocument);
    }

    /**
     * Register the expose details from given metadata, typically used when setting up GET single/collection endpoints
     * @param ORM\QueryBuilder $qb
     * @param ORM\Mapping\ClassMetadata $classMetaData
     */
    protected function registerExposeFromMetaData(ORM\QueryBuilder $qb, ORM\Mapping\ClassMetadata $classMetaData)
    {
        $this->registerExpose(
            $this->getMatchedRoute()->getExpose(),
            $qb,
            $classMetaData
        );
    }

    /**
     * A recursive function to process the specified expose fields for a fetch request (GET)
     * @param  array                     $fields         - expose fields to process
     * @param  ORM\QueryBuilder $qb
     * @param  ORM\Mapping\ClassMetadata $classMetaData
     * @param $rootAlias - table alias to be used on SQL query
     * @param  array                     $addedKeyFields
     * @return ORM\QueryBuilder
     */
    protected function registerExpose(
        $fields,
        ORM\QueryBuilder $qb,
        ORM\Mapping\ClassMetadata $classMetaData,
        $rootAlias = null,
        &$addedKeyFields = []
    ) {
        if (empty($fields)) {
            return $qb;
        }

        $rootAlias = (is_null($rootAlias)) ? self::getAlias($classMetaData->getName()) : $rootAlias;

        $addedKeyFields = (array) $addedKeyFields;
        $ormAssociationMappings = $classMetaData->getAssociationMappings();

        // Process single fields into a partial set - Filter fields not available on class meta data
        $selectFields = $this->getFilteredAssociations($fields, $classMetaData, 'fields');

        // merge required identifier fields with select fields
        $keyFieldDiff = array_diff($classMetaData->getIdentifierFieldNames(), $selectFields);
        if (!empty($keyFieldDiff)) {
            $addedKeyFields = $keyFieldDiff;
            $selectFields = array_merge($selectFields, $keyFieldDiff);
        }

        if (!empty($selectFields)) {
            $qb->addSelect('partial ' . $rootAlias . '.{' . implode(', ', $selectFields) . '}');
        }

        // Process relational field with no deeper expose restrictions
        foreach ($this->getFilteredAssociations($fields, $classMetaData, 'association') as $relationalField) {
            $alias = self::getAlias($ormAssociationMappings[$relationalField]['targetEntity'], $relationalField);
            $qb->leftJoin($rootAlias . '.' . $relationalField, $alias);
            $qb->addSelect($alias);
        }

        foreach ($fields as $key => $value) {
            if (is_array($value) && isset($ormAssociationMappings[$key])) {
                $alias = self::getAlias($ormAssociationMappings[$key]['targetEntity'], $key);
                $qb->leftJoin($rootAlias . '.' . $key, $alias);
                $qb = $this->registerExpose(
                    $value,
                    $qb,
                    $this->getEntityManager()->getClassMetadata($ormAssociationMappings[$key]['targetEntity']),
                    $alias,
                    $addedKeyFields[$key]
                );
            }
        }

        $this->addedKeyFields = $addedKeyFields;

        return $qb;
    }

    /**
     * Get filtered associations
     * @param array $fields
     * @param ORM\Mapping\ClassMetadata $classMetaData
     * @param string $type 'fields' or 'association
     * @return array
     */
    protected function getFilteredAssociations(array $fields, ORM\Mapping\ClassMetadata $classMetaData, $type = 'fields')
    {
        // Process relational fields / association with no deeper expose restrictions
        if ($type !== 'fields' && $type !== 'associations')
        {
            return [];
        }

        $names = ($type === 'fields') ? $classMetaData->getFieldNames() : $classMetaData->getAssociationNames();
        return array_filter(
            $fields,
            function ($offset) use ($names) {
                if (!is_array($offset) && in_array($offset, $names)) {
                    return true;
                }
                return false;
            }
        );
    }

    /**
     * Method used to write to the $data array.
     * -    wraps results in a single entry array keyed by entity name.
     *        Eg array(user1, user2) becomes array('users' => array(user1, user2)) - this is useful for a more descriptive output of collection resources
     * -    Removes any addition expose fields required for a partial DQL query
     * @param  array     $data    - the data fetched from the database
     * @param  string    $keyName - the key name to use to wrap the data in. If null will attempt to pluralise the entity name on collection request, or singularize on single element request
     * @return ResultSet $data
     */
    public function createResultSet(array $data, $keyName = null)
    {
        $matchedRoute = $this->getMatchedRoute();
        $classMetaData = $matchedRoute->getClassMetaData();

        // Recursively remove any additionally added pk fields ($data must be a single record hierarchy. Iterate if we're getting a collection)
        if ($matchedRoute->isCollection()) {
            $dataSize = sizeof($data);
            for ($x = 0; $x < $dataSize; $x++) {
                $this->removeAddedKeyFields($this->addedKeyFields, $data[$x]);
            }
        } else {
            $this->removeAddedKeyFields($this->addedKeyFields, $data);
        }

        if (is_null($keyName)) {
            reset($data);
            if (sizeof($data) === 1 && is_string(key($data))) {
                // Use the single keyed array as the result set key
                $keyName = key($data);
                $data = $data[key($data)];
            } else {
                $keyName = ($matchedRoute->isCollection()) ? $classMetaData->getCollectionName(
                ) : $classMetaData->getElementName();
            }
        }

        return ResultSet::create($data, $keyName);
    }


    /**
     * Functional recursive method to remove any fields added to make the partial DQL work and remove the data
     * @param  array $addedKeyFields
     * @param  array $data           - pass by reference
     * @return array
     */
    protected function removeAddedKeyFields($addedKeyFields, &$data)
    {
        $addedKeyFields = (array) $addedKeyFields;
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($addedKeyFields[$key])) {
                if (is_int($key)) {
                    $valueSize = sizeof($value);
                    for ($x = 0; $x <= $valueSize; $x++) {
                        if (isset($data[$x]) && is_array($data[$x])) {
                            $this->removeAddedKeyFields($addedKeyFields[$key], $data[$x]);
                        }
                    }
                } else {
                    $this->removeAddedKeyFields($addedKeyFields[$key], $data[$key]);
                }

            } else {
                if (is_array($addedKeyFields) && in_array($key, $addedKeyFields)) {
                    unset($data[$key]);
                }
            }
        }

        return $data;
    }


    /**
     * Perform a default update action. Logic between PUT and PATCH (not POST) are identical
     * If things change then we can start to break this function down
     * @return ResultSet
     */
    protected function performDefaultUpdateAction()
    {
        $matchedRoute = $this->getMatchedRoute();
        $classMetaData = $matchedRoute->getClassMetaData();
        $elementName = $classMetaData->getEntityAlias();

        $em = $this->getEntityManager();

        $qb = $em->createQueryBuilder()->select($elementName)->from($classMetaData->getClassName(), $elementName);
        foreach ($matchedRoute->getRouteParams() as $key => $value) {
            $qb->andWhere($elementName . '.' . $key . ' = :' . $key);
            $qb->setParameter($key, $value);
        }

        try {
            $object = $qb->getQuery()->getSingleResult(ORM\Query::HYDRATE_OBJECT);
        } catch (\Exception $e) {
            return $this->handleError($e, Response::STATUS_CODE_404);
        }

        $this->runHandle($object);

        // Attempt to save the modified resource
        try {
            $em->persist($object);
            $em->flush($object);

            $location = $matchedRoute->getOriginLocation(
                $object,
                $this->getRequest()->getUrl(),
                $this->getEntityManager()
            );
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_200);
            $resultSet = ResultSet::create(['location' => ($location) ? $location : 'unknown'], 'response');
        } catch (\Exception $e) {
            return $this->handleError($e, Response::STATUS_CODE_500);
        }

        return $resultSet;
    }


    /**
     * Run the handle call on an entity object
     * @param object $object
     */
    protected function runHandle($object)
    {
        $matchedRoute = $this->getMatchedRoute();
        // Run any attached handle function
        if ($matchedRoute->hasHandleCall()) {
            $handleMethod = $matchedRoute->getHandleCall();
            $object->$handleMethod($this->getRepresentation()->toArray(false), $this->getRequest());
        }
    }

    /**
     * Get a unique alias name from an entity class name and relation field
     * @param  string $className - The class of the related entity
     * @param  string $fieldName - The field the relation is on. Typical to root when using top level.
     * @return string
     */
    public static function getAlias($className, $fieldName = 'rt')
    {
        $classNameParts = explode('\\', $className);
        if (sizeof($classNameParts) > 1) {
            $hash = preg_replace(
                '/[0-9_\/]+/',
                '',
                base64_encode(sha1(implode('', array_slice($classNameParts, 0, -1)) . $fieldName))
            );
            $className = array_pop($classNameParts);
        } else {
            $hash = preg_replace('/[0-9_\/]+/', '', base64_encode(sha1($fieldName)));
        }

        return strtolower(preg_replace("/[^a-zA-Z_\s]/", "", substr($hash, 0, 5) . '_' . $className));
    }
}

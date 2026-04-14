<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Traits;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Extends an entity with ArgusLoad capabilities. It makes out of an entity an ArgusRepo Entity.
 * It was a specific design decision to make Argus Repository extending Domain entities in order to reduce overhead
 * since Argus Repository entities are thought to be extension of domain entities with basically the same properties but extended through ArgusLoad capabilities
 */
trait ArgusTrait
{
    use ArgusLoadTrait;

    /** @var bool Used for easy identification of current class beeing an Argus Repo Class */

    public static bool $isArgusEntity = true;

    /** @var string protected caching for cacheKey (we want to avoid to call multiple times uniqueKey during loading operations */
    protected string $cacheKey;

    /**
     * copies all public properties from entity to this Argus Object
     * operates recursively and creates if possible Argus Repo Objects from Entities
     * @param DefaultObject $entity
     * @return static
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function fromEntity(DefaultObject &$entity): static
    {
        $propertyNamesToSkip = [
            'children' => true,
            'parent' => true,
            'elementsByUniqueKey' => true,
            'objectType' => true
        ];
        if ($parent = $entity->getParent()) {
            $this->setParent($parent);
        }
        if (!($this instanceof $entity)) {
            throw new InternalErrorException(
                'Argus Entity can be imported only from the same Class, e.g. Entities\Domain > Argus\Domain'
            );
        }
        $entityReflectionClass = $entity::getReflectionClass();
        foreach ($entity->getProperties() as $property) {
            $propertyName = $property->getName();
            if (isset($propertyNamesToSkip[$propertyName])) {
                continue;
            }
            if (!isset($entity->$propertyName)) {
                continue;
            }
            $typeName = null;
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();
            }
            // treating ObjectSets
            if ($propertyName == 'elements' && $this instanceof ObjectSet) {
                /** @var Entity $element */
                foreach ($entity->elements as $element) {
                    $argusClassName = $element::getRepoClass(LazyLoadRepo::ARGUS);
                    if ($argusClassName) {
                        /** @var ArgusTrait $argusInstance */
                        $argusInstance = new $argusClassName();
                        $argusInstance->fromEntity($element);
                        $argusInstance->setParent($this);
                        $this->add($argusInstance);
                    } else {
                        $element->setParent($this);
                        $this->add($element);
                    }
                }
                continue;
            }

            $typeIsBuiltin = false;
            if ($type instanceof ReflectionUnionType) {
                foreach ($type->getTypes() as $unionType) {
                    if (!$unionType->isBuiltin()) {
                        $typeIsBuiltin = false;
                    }
                }
            } elseif ($type !== null) {
                $typeIsBuiltin = $type->isBuiltin();
            }

            if (!($type && !$typeIsBuiltin && $typeName)) {
                $this->$propertyName = $entity->$propertyName;
            } elseif (is_a($typeName, DefaultObject::class, true)) {
                $propertyIsParent = $entityReflectionClass->isLazyLoadedPropertyToBeAddedAsParent($propertyName);
                if ($propertyIsParent){
                    $this->$propertyName = $entity->$propertyName;
                    continue;
                }
                $argusClassName = $entity->getRepoClassForProperty(LazyLoadRepo::ARGUS, $propertyName);
                if (!$argusClassName && is_a($type->getName(),DefaultObject::class,true)) {
                    /** @var DefaultObject $typeClassName */
                    $typeClassName = $type->getName();
                    $argusClassName = $typeClassName::getRepoClass(LazyLoadRepo::ARGUS);
                }
                //$propertyClass = $argusClassName = str_replace('\\Entities\\', '\\Repo\\Argus\\', $property->getType());
                if ($argusClassName && class_exists($argusClassName)) {
                    $argusInstance = new $argusClassName();
                    $propertyInstnace = $entity->$propertyName;
                    $propertyParent = $propertyInstnace->getParent();
                    if ($propertyParent) {
                        $argusInstance->setParent($propertyParent);
                    }
                    $argusInstance->fromEntity($entity->$propertyName);
                    $this->$propertyName = $argusInstance;
                    // if property is child of entity, we add $argusInstance also als our child
                    $parent = $entity->$propertyName->getParent();
                } else {
                    $this->$propertyName = $entity->$propertyName;
                }
                // we reflect parent child relationships from entity
                if ($entity->$propertyName->getParent() === $entity) {
                    $this->addChildren($this->$propertyName);
                }
            } else {
                $this->$propertyName = $entity->$propertyName;
            }
        }
        // if a parent for this location has not been set, we use the one from the entity passed.
        // this usually happens on the root level of the fromEntity call
        if ($this->parent === null && $entity->parent) {
            $this->parent = $entity->parent;
        }
        return $this;
    }


    /**
     * copies all public properties from current Argus Repo Instance to corresponding OrmEntity instance
     * operates recursively and creates if possible Entities from Argus Repo Objects
     * @param Entity $entiy
     * @return void
     * @throws ReflectionException
     */
    public function toEntity(
        array $callPath = [],
        DefaultObject|null &$entityInstance = null
    ): DefaultObject|null {
        if (isset($callPath[spl_object_id($this)])) {
            return null;
        }
        $propertyNamesToSkip = ['children' => true, 'parent' => true, 'elementsByUniqueKey' => true];

        $callPath[spl_object_id($this)] = true;

        /** @var ReflectionClass $parentRelectionClass */
        $parentRelectionClass = static::getReflectionClass()->getParentClass();
        $parentClassName = $parentRelectionClass->getName();

        $entity = $entityInstance ?? new $parentClassName();
        if ($parent = $this->getParent()) {
            $entity->setParent($parent);
        }
        foreach ($parentRelectionClass->getProperties() as $property) {
            $propertyName = $property->getName();
            if (isset($propertyNamesToSkip[$propertyName])) {
                continue;
            }
            if (!isset($this->$propertyName)) {
                continue;
            }
            if ($propertyName == 'objectType') {
                continue;
            }

            // treating ObjectSets
            if ($propertyName == 'elements' && $this instanceof ObjectSet) {
                /** @var Entity $element */
                foreach ($this->elements as $element) {
                    if (method_exists($element, 'toEntity')) {
                        $alreadyPresentElement = $entity->getByUniqueKey($element->uniqueKey());
                        if ($alreadyPresentElement) {
                            $element->toEntity($callPath, $alreadyPresentElement);
                        } else {
                            $entityInstance = $element->toEntity($callPath);
                            $entity->add($entityInstance);
                        }
                    } else {
                        $entity->add($element);
                    }
                }
                continue;
            }
            $typeIsBuiltin = false;
            $type = $property->getType();
            if ($type instanceof ReflectionUnionType) {
                foreach ($type->getTypes() as $unionType) {
                    if (!$unionType->isBuiltin()) {
                        $typeIsBuiltin = false;
                    }
                }
            } elseif ($type !== null) {
                $typeIsBuiltin = $type->isBuiltin();
            }
            if ($typeIsBuiltin || $type === null) {
                $entity->$propertyName = $this->$propertyName;
            } elseif ($this->$propertyName instanceof Entity || $this->$propertyName instanceof ValueObject) {
                if (method_exists($this->$propertyName, 'toEntity')) {
                    $entityInstance = $this->$propertyName->toEntity($callPath);
                    if ($entityInstance) {
                        $entity->$propertyName = $entityInstance;
                    } else {
                        $entity->$propertyName = $this->$propertyName;
                    }
                } else {
                    $entity->$propertyName = $this->$propertyName;
                }
                // we reflect parent child relationships on entity
                if (($this->$propertyName->parent ?? null) && spl_object_id(
                        $this->$propertyName->parent
                    ) == spl_object_id($this)) {
                    $entity->addChildren($entity->$propertyName);
                }
            } else {
                $entity->$propertyName = $this->$propertyName;
            }
        }

        return $entity;
    }

    /**
     * Returns the response data from Argus response or null if the response is not valid
     * @param mixed $callResponseData
     *
     * @return mixed
     */
    protected function getResponseDataFromArgusResponse(mixed &$callResponseData): mixed
    {
        $responseObject = null;
        if (isset($callResponseData->status, $callResponseData->data)
            && ($callResponseData->status === 200 || $callResponseData->status === 'OK')
        ) {
            $responseObject = $callResponseData->data;
        }
        return $responseObject;
    }

    /**
     * returns a cache key used for storing the object in cache, this function considers dynamically also children for creating the cache key
     * @return string
     */
    public function cacheKey(): string
    {
        if (isset($this->cacheKey)) {
            return $this->cacheKey;
        }

        $key = $this->uniqueKey(); //. '_' . ($this->shall_be_loaded || $this->autoload || $this->loaded);
        //if (!($this->children && count($this->children))) return $key;
        //foreach ($this->children as $child) $key .= '_' . $child->cacheKey();
        $this->cacheKey = $key;
        return $key;
    }

    public static function uniqueKeyStatic(string|int|null $id = null): string
    {
        $parentRelectionClass = static::getReflectionClass()->getParentClass();
        $parentClassName = $parentRelectionClass->getName();
        return $parentClassName . ($id ? '_' . $id : '');
    }
}

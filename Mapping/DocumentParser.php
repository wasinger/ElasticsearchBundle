<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\Mapping;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use ONGR\ElasticsearchBundle\Annotation\Index;
use ONGR\ElasticsearchBundle\Annotation\Embedded;
use ONGR\ElasticsearchBundle\Annotation\HashMap;
use ONGR\ElasticsearchBundle\Annotation\MetaField;
use ONGR\ElasticsearchBundle\Annotation\NestedType;
use ONGR\ElasticsearchBundle\Annotation\Property;
use Symfony\Component\Cache\DoctrineProvider;

/**
 * Document parser used for reading document annotations.
 */
class DocumentParser
{
    const PROPERTY_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\Property';
    const EMBEDDED_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\Embedded';
    const INDEX_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\Index';
    const OBJECT_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\ObjectType';
    const NESTED_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\NestedType';

    // Meta fields
    const ID_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\Id';
    const ROUTING_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\Routing';
    const VERSION_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\Version';
    const HASH_MAP_ANNOTATION = 'ONGR\ElasticsearchBundle\Annotation\HashMap';

    private $reader;

    private $objects = [];

    private $aliases = [];

    private $properties = [];

    private $indexes = [];

    public function __construct(Reader $reader, DoctrineProvider $cache = null)
    {
        $this->reader = $reader;
        $this->registerAnnotations();
    }

    private function getIndexAnnotationData(\ReflectionClass $document)
    {
        return $this->reader->getClassAnnotation($document, self::INDEX_ANNOTATION);
    }

    /**
     * Returns property annotation data from reader.
     *
     * @param \ReflectionProperty $property
     *
     * @return Property|object|null
     */
    private function getPropertyAnnotationData(\ReflectionProperty $property)
    {
        $result = $this->reader->getPropertyAnnotation($property, self::PROPERTY_ANNOTATION);

        if ($result !== null && $result->name === null) {
            $result->name = Caser::snake($property->getName());
        }

        return $result;
    }

    /**
     * Returns Embedded annotation data from reader.
     *
     * @param \ReflectionProperty $property
     *
     * @return Embedded|object|null
     */
    private function getEmbeddedAnnotationData(\ReflectionProperty $property)
    {
        $result = $this->reader->getPropertyAnnotation($property, self::EMBEDDED_ANNOTATION);

        if ($result !== null && $result->name === null) {
            $result->name = Caser::snake($property->getName());
        }

        return $result;
    }

    /**
     * Returns HashMap annotation data from reader.
     *
     * @param \ReflectionProperty $property
     *
     * @return HashMap|object|null
     */
    private function getHashMapAnnotationData(\ReflectionProperty $property)
    {
        $result = $this->reader->getPropertyAnnotation($property, self::HASH_MAP_ANNOTATION);

        if ($result !== null && $result->name === null) {
            $result->name = Caser::snake($property->getName());
        }

        return $result;
    }

    private function getMetaFieldAnnotationData(\ReflectionProperty $property): array
    {
        /** @var MetaField $annotation */
        $annotation = $this->reader->getPropertyAnnotation($property, self::ID_ANNOTATION);
        $annotation = $annotation ?: $this->reader->getPropertyAnnotation($property, self::ROUTING_ANNOTATION);
        $annotation = $annotation ?: $this->reader->getPropertyAnnotation($property, self::VERSION_ANNOTATION);

        if ($annotation === null) {
            return null;
        }

        $data = [
            'name' => $annotation->getName(),
            'settings' => $annotation->getSettings(),
        ];

        return $data;
    }

    /**
     * Finds aliases for every property used in document including parent classes.
     *
     * @param \ReflectionClass $reflectionClass
     * @param array            $metaFields
     *
     * @return array
     */
    private function getAliases(\ReflectionClass $reflectionClass, array &$metaFields = null)
    {
        $reflectionName = $reflectionClass->getName();

        // We skip cache in case $metaFields is given. This should not affect performance
        // because for each document this method is called only once. For objects it might
        // be called few times.
        if ($metaFields === null && array_key_exists($reflectionName, $this->aliases)) {
            return $this->aliases[$reflectionName];
        }

        $alias = [];

        /** @var \ReflectionProperty[] $properties */
        $properties = $this->getDocumentPropertiesReflection($reflectionClass);

        foreach ($properties as $name => $property) {
            $directory = $this->guessDirName($property->getDeclaringClass());

            $type = $this->getPropertyAnnotationData($property);
            $type = $type !== null ? $type : $this->getEmbeddedAnnotationData($property);
            $type = $type !== null ? $type : $this->getHashMapAnnotationData($property);

            if ($type === null && $metaFields !== null
                && ($metaData = $this->getMetaFieldAnnotationData($property, $directory)) !== null) {
                $metaFields[$metaData['name']] = $metaData['settings'];
                $type = new \stdClass();
                $type->name = $metaData['name'];
            }
            if ($type !== null) {
                $alias[$type->name] = [
                    'propertyName' => $name,
                ];

                if ($type instanceof Property) {
                    $alias[$type->name]['type'] = $type->type;
                }

                if ($type instanceof HashMap) {
                    $alias[$type->name]['type'] = HashMap::NAME;
                }

                $alias[$type->name][HashMap::NAME] = $type instanceof HashMap;

                switch (true) {
                    case $property->isPublic():
                        $propertyType = 'public';
                        break;
                    case $property->isProtected():
                    case $property->isPrivate():
                        $propertyType = 'private';
                        $alias[$type->name]['methods'] = $this->getMutatorMethods(
                            $reflectionClass,
                            $name,
                            $type instanceof Property ? $type->type : null
                        );
                        break;
                    default:
                        $message = sprintf(
                            'There is a wrong property type %s used in the class %s',
                            $name,
                            $reflectionName
                        );
                        throw new \LogicException($message);
                }
                $alias[$type->name]['propertyType'] = $propertyType;

                if ($type instanceof Embedded) {
                    $alias[$type->name] = array_merge(
                        $alias[$type->name],
                        [
                            'type' => $this->getObjectMapping($type->class)['type'],
                            'multiple' => $type->multiple,
                            'aliases' => $this->getAliases($child, $metaFields),
                            'namespace' => $child->getName(),
                        ]
                    );
                }
            }
        }

        $this->aliases[$reflectionName] = $alias;

        return $this->aliases[$reflectionName];
    }

    /**
     * Checks if class have setter and getter, and returns them in array.
     *
     * @param \ReflectionClass $reflectionClass
     * @param string           $property
     *
     * @return array
     */
    private function getMutatorMethods(\ReflectionClass $reflectionClass, $property, $propertyType)
    {
        $camelCaseName = ucfirst(Caser::camel($property));
        $setterName = 'set'.$camelCaseName;
        if (!$reflectionClass->hasMethod($setterName)) {
            $message = sprintf(
                'Missing %s() method in %s class. Add it, or change the property to public type.',
                $setterName,
                $reflectionClass->getName()
            );
            throw new \LogicException($message);
        }

        if ($reflectionClass->hasMethod('get'.$camelCaseName)) {
            return [
                'getter' => 'get' . $camelCaseName,
                'setter' => $setterName
            ];
        }

        if ($propertyType === 'boolean') {
            if ($reflectionClass->hasMethod('is' . $camelCaseName)) {
                return [
                    'getter' => 'is' . $camelCaseName,
                    'setter' => $setterName
                ];
            }

            $message = sprintf(
                'Missing %s() or %s() method in %s class. Add it, or change property to public.',
                'get'.$camelCaseName,
                'is'.$camelCaseName,
                $reflectionClass->getName()
            );
            throw new \LogicException($message);
        }

        $message = sprintf(
            'Missing %s() method in %s class. Add it, or change property to public.',
            'get'.$camelCaseName,
            $reflectionClass->getName()
        );
        throw new \LogicException($message);
    }

    /**
     * Registers annotations to registry so that it could be used by reader.
     */
    private function registerAnnotations()
    {
        $annotations = [
            'Index',
            'Property',
            'Embedded',
            'ObjectType',
            'NestedType',
            'Id',
            'Routing',
            'Version',
            'HashMap',
        ];

        foreach ($annotations as $annotation) {
            AnnotationRegistry::registerFile(__DIR__ . "/../Annotation/{$annotation}.php");
        }
    }

    /**
     * Returns all defined properties including private from parents.
     *
     * @param \ReflectionClass $reflectionClass
     *
     * @return array
     */
    private function getDocumentPropertiesReflection(\ReflectionClass $reflectionClass)
    {
        if (in_array($reflectionClass->getName(), $this->properties)) {
            return $this->properties[$reflectionClass->getName()];
        }

        $properties = [];

        foreach ($reflectionClass->getProperties() as $property) {
            if (!in_array($property->getName(), $properties)) {
                $properties[$property->getName()] = $property;
            }
        }

        $parentReflection = $reflectionClass->getParentClass();
        if ($parentReflection !== false) {
            $properties = array_merge(
                $properties,
                array_diff_key($this->getDocumentPropertiesReflection($parentReflection), $properties)
            );
        }

        $this->properties[$reflectionClass->getName()] = $properties;

        return $properties;
    }

    /**
     * Parses analyzers list from document mapping.
     *
     * @param \ReflectionClass $reflectionClass
     * @return array
     */
    private function getAnalyzers(\ReflectionClass $reflectionClass)
    {
        $analyzers = [];

        foreach ($this->getDocumentPropertiesReflection($reflectionClass) as $name => $property) {
            $directory = $this->guessDirName($property->getDeclaringClass());

            $type = $this->getPropertyAnnotationData($property);
            $type = $type !== null ? $type : $this->getEmbeddedAnnotationData($property);

            if ($type instanceof Embedded) {
                $analyzers = array_merge(
                    $analyzers,
                    $this->getAnalyzers(new \ReflectionClass($this->finder->getNamespace($type->class, $directory)))
                );
            }

            if ($type instanceof Property) {
                if (isset($type->options['analyzer'])) {
                    $analyzers[] = $type->options['analyzer'];
                }
                if (isset($type->options['search_analyzer'])) {
                    $analyzers[] = $type->options['search_analyzer'];
                }

                if (isset($type->options['fields'])) {
                    foreach ($type->options['fields'] as $field) {
                        if (isset($field['analyzer'])) {
                            $analyzers[] = $field['analyzer'];
                        }
                        if (isset($field['search_analyzer'])) {
                            $analyzers[] = $field['search_analyzer'];
                        }
                    }
                }
            }
        }
        return array_unique($analyzers);
    }

    /**
     * Returns properties of reflection class.
     *
     * @param \ReflectionClass $reflectionClass Class to read properties from.
     * @param array            $properties      Properties to skip.
     * @param bool             $flag            If false exludes properties, true only includes properties.
     *
     * @return array
     */
    private function getProperties(\ReflectionClass $reflectionClass, $properties = [], $flag = false)
    {
        $mapping = [];

        /** @var \ReflectionProperty $property */
        foreach ($this->getDocumentPropertiesReflection($reflectionClass) as $name => $property) {
            $directory = $this->guessDirName($property->getDeclaringClass());

            $type = $this->getPropertyAnnotationData($property);
            $type = $type !== null ? $type : $this->getEmbeddedAnnotationData($property);
            $type = $type !== null ? $type : $this->getHashMapAnnotationData($property);

            if ((in_array($name, $properties) && !$flag)
                || (!in_array($name, $properties) && $flag)
                || empty($type)
            ) {
                continue;
            }

            $map = $type->dump();

            // Inner object
            if ($type instanceof Embedded) {
                $map = array_replace_recursive($map, $this->getObjectMapping($type->class));
            }

            // HashMap object
            if ($type instanceof HashMap) {
                $map = array_replace_recursive($map, [
                    'type' => NestedType::NAME,
                    'dynamic' => true,
                ]);
            }

            // If there is set some Raw options, it will override current ones.
            if (isset($map['options'])) {
                $options = $map['options'];
                unset($map['options']);
                $map = array_merge($map, $options);
            }

            $mapping[$type->name] = $map;
        }

        return $mapping;
    }

    private function getObjectMapping(string $namespace): array
    {
        if (array_key_exists($namespace, $this->objects)) {
            return $this->objects[$namespace];
        }

        $reflectionClass = new \ReflectionClass($namespace);

        switch (true) {
            case $this->reader->getClassAnnotation($reflectionClass, self::OBJECT_ANNOTATION):
                $type = 'object';
                break;
            case $this->reader->getClassAnnotation($reflectionClass, self::NESTED_ANNOTATION):
                $type = 'nested';
                break;
            default:
                throw new \LogicException(
                    sprintf(
                        '%s should have @ObjectType or @NestedType annotation to be used as embeddable object.',
                        $namespace
                    )
                );
        }

        $this->objects[$namespace] = [
            'type' => $type,
            'properties' => $this->getProperties($reflectionClass),
        ];

        return $this->objects[$namespace];
    }
}

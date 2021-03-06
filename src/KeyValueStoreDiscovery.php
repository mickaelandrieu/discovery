<?php

/*
 * This file is part of the puli/discovery package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Discovery;

use InvalidArgumentException;
use Puli\Discovery\Api\Binding\BindingType;
use Puli\Discovery\Api\Binding\ResourceBinding;
use Puli\Discovery\Api\DuplicateTypeException;
use Puli\Discovery\Api\NoSuchTypeException;
use Puli\Discovery\Binding\LazyBinding;
use Puli\Repository\Api\ResourceRepository;
use RuntimeException;
use Webmozart\Assert\Assert;
use Webmozart\KeyValueStore\Api\KeyValueStore;

/**
 * A resource discovery that stores the bindings in a key-value store.
 *
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class KeyValueStoreDiscovery extends AbstractEditableDiscovery
{
    /**
     * @var KeyValueStore
     */
    private $store;

    /**
     * @var ResourceBinding[]
     */
    private $bindings = array();

    /**
     * @var BindingType[]
     */
    private $types = array();

    /**
     * Creates a new resource discovery.
     *
     * @param ResourceRepository $repo  The repository to fetch resources from.
     * @param KeyValueStore      $store The key-value store used to store the
     *                                  bindings and the binding types.
     */
    public function __construct(ResourceRepository $repo, KeyValueStore $store)
    {
        parent::__construct($repo);

        $this->store = $store;
        $this->queryIndex = $store->get('//queryIndex', array());
        $this->typeIndex = $store->get('//typeIndex', array());
    }

    /**
     * {@inheritdoc}
     */
    public function defineType($type)
    {
        if (is_string($type)) {
            $type = new BindingType($type);
        }

        if (!$type instanceof BindingType) {
            throw new InvalidArgumentException(sprintf(
                'Expected argument of type string or BindingType. Got: %s',
                is_object($type) ? get_class($type) : gettype($type)
            ));
        }

        if (isset($this->typeIndex[$type->getName()])) {
            throw DuplicateTypeException::forTypeName($type->getName());
        }

        $this->types[$type->getName()] = $type;
        $this->typeIndex[$type->getName()] = array();

        $this->store->set('//typeIndex', $this->typeIndex);
        $this->store->set($type->getName(), $type);
    }

    /**
     * {@inheritdoc}
     */
    public function undefineType($typeName)
    {
        Assert::stringNotEmpty($typeName, 'The type name must be a non-empty string. Got: %s');

        $this->removeBindingsByType($typeName);

        unset($this->types[$typeName]);
        unset($this->typeIndex[$typeName]);

        $this->store->set('//typeIndex', $this->typeIndex);
        $this->store->remove($typeName);
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinedType($typeName)
    {
        if (!isset($this->types[$typeName]) || !$this->types[$typeName] instanceof BindingType) {
            $this->loadType($typeName);
        }

        return $this->types[$typeName];
    }

    /**
     * {@inheritdoc}
     */
    public function isTypeDefined($typeName)
    {
        return array_key_exists($typeName, $this->typeIndex);
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinedTypes()
    {
        foreach ($this->typeIndex as $typeName => $index) {
            if (!isset($this->types[$typeName])) {
                $this->loadType($typeName);
            }
        }

        return $this->types;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        parent::clear();

        $this->types = array();
        $this->bindings = array();

        $this->store->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function getBindings()
    {
        $nextId = $this->store->get('//nextId');

        for ($id = 1; $id < $nextId; ++$id) {
            if ($this->store->exists($id)) {
                $this->loadBinding($id);
            }
        }

        return array_values($this->bindings);
    }

    /**
     * {@inheritdoc}
     */
    protected function getBinding($id)
    {
        if (!isset($this->bindings[$id])) {
            $this->loadBinding($id);
        }

        return $this->bindings[$id];
    }

    /**
     * {@inheritdoc}
     */
    protected function insertBinding(ResourceBinding $binding)
    {
        $id = $this->store->get('//nextId', 1);

        $this->bindings[$id] = $binding;

        $this->updateIndicesForId($id, $binding);

        $this->store->set($id, array(
            $binding->getQuery(),
            $binding->getType()->getName(),
            $binding->getParameterValues(),
            $binding->getLanguage(),
        ));

        $this->store->set('//nextId', $id + 1);
        $this->store->set('//queryIndex', $this->queryIndex);
        $this->store->set('//typeIndex', $this->typeIndex);
    }

    /**
     * {@inheritdoc}
     */
    protected function removeBinding($id)
    {
        unset($this->bindings[$id]);

        $this->store->remove($id);
    }

    /**
     * {@inheritdoc}
     */
    protected function removeBindingsByQuery($query, array $parameterValues = null)
    {
        parent::removeBindingsByQuery($query, $parameterValues);

        $this->store->set('//typeIndex', $this->typeIndex);
        $this->store->set('//queryIndex', $this->queryIndex);
    }

    /**
     * {@inheritdoc}
     */
    protected function removeBindingsByType($typeName, array $parameterValues = null)
    {
        parent::removeBindingsByType($typeName, $parameterValues);

        $this->store->set('//typeIndex', $this->typeIndex);
        $this->store->set('//queryIndex', $this->queryIndex);
    }

    /**
     * {@inheritdoc}
     */
    protected function removeBindingsByQueryAndType($query, $typeName, array $parameterValues = null)
    {
        parent::removeBindingsByQueryAndType($query, $typeName, $parameterValues);

        $this->store->set('//typeIndex', $this->typeIndex);
        $this->store->set('//queryIndex', $this->queryIndex);
    }

    private function loadBinding($id)
    {
        if (!($data = $this->store->get($id))) {
            throw new RuntimeException(sprintf(
                'Could not fetch data for binding with ID %s.',
                $id
            ));
        }

        $this->bindings[$id] = new LazyBinding(
            $data[0], // query
            $this->repo,
            $this->getDefinedType($data[1]), // type name
            $data[2], // parameters
            $data[3] // language
        );
    }

    private function loadType($typeName)
    {
        if (!($type = $this->store->get($typeName))) {
            throw NoSuchTypeException::forTypeName($typeName);
        }

        $this->types[$typeName] = $type;
    }
}

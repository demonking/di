<?php
namespace Yiisoft\Di;

use Psr\Container\ContainerInterface;
use SplObjectStorage;
use Yiisoft\Di\Contracts\DeferredServiceProviderInterface;
use Yiisoft\Di\Contracts\ServiceProviderInterface;
use Yiisoft\Factory\Definitions\Reference;
use Yiisoft\Factory\Exceptions\CircularReferenceException;
use Yiisoft\Factory\Exceptions\InvalidConfigException;
use Yiisoft\Factory\Exceptions\NotFoundException;
use Yiisoft\Factory\Exceptions\NotInstantiableException;
use Yiisoft\Factory\Definitions\DefinitionInterface;
use Yiisoft\Factory\Definitions\Normalizer;
use Yiisoft\Factory\Definitions\ArrayDefinition;

/**
 * Container implements a [dependency injection](http://en.wikipedia.org/wiki/Dependency_injection) container.
 */
class Container implements ContainerInterface
{
    /**
     * @var DefinitionInterface[] object definitions indexed by their types
     */
    private $definitions = [];
    /**
     * @var array used to collect ids instantiated during build
     * to detect circular references
     */
    private $building = [];
    /**
     * @var contracts\DeferredServiceProviderInterface[]|\SplObjectStorage list of providers
     * deferred to register till their services would be requested
     */
    private $deferredProviders;

    /**
     * @var object[]
     */
    private $instances;

    /**
     * Container constructor.
     *
     * @param array $definitions
     * @param ServiceProviderInterface[] $providers
     *
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function __construct(
        array $definitions = [],
        array $providers = []
    ) {
        $this->setMultiple($definitions);
        $this->deferredProviders = new SplObjectStorage();
        foreach ($providers as $provider) {
            $this->addProvider($provider);
        }
    }

    /**
     * Returns an instance by either interface name or alias.
     *
     * Same instance of the class will be returned each time this method is called.
     *
     * @param string|Reference $id the interface or an alias name that was previously registered via [[set()]].
     * @param array $parameters parameters to set for the object obtained
     * @return object an instance of the requested interface.
     * @throws CircularReferenceException
     * @throws InvalidConfigException
     * @throws NotFoundException
     * @throws NotInstantiableException
     */
    public function get($id, array $parameters = [])
    {
        $id = $this->getId($id);
        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $this->build($id, $parameters);
        }

        return $this->instances[$id];
    }

    public function getId($id): string
    {
        return is_string($id) ? $id : $id->getId();
    }

    /**
     * Creates new instance by either interface name or alias.
     *
     * @param string $id the interface or an alias name that was previously registered via [[set()]].
     * @param array $params
     * @return object new built instance of the specified class.
     * @throws CircularReferenceException
     * @internal
     */
    protected function build(string $id, array $params = [])
    {
        if (isset($this->building[$id])) {
            throw new CircularReferenceException(sprintf(
                'Circular reference to "%s" detected while building: %s',
                $id,
                implode(',', array_keys($this->building))
            ));
        }

        $this->building[$id] = 1;
        $this->registerProviderIfDeferredFor($id);
        $object = $this->buildInternal($id, $params);
        unset($this->building[$id]);

        return $object;
    }

    private function buildInternal(string $id, array $params = [])
    {
        if (!isset($this->definitions[$id])) {
            return $this->buildPrimitive($id, $params);
        }

        return $this->definitions[$id]->resolve($this, $params);
    }

    private function buildPrimitive(string $class, array $params = [])
    {
        if (class_exists($class)) {
            $definition = new ArrayDefinition($class);

            return $definition->resolve($this, $params);
        }

        throw new NotFoundException("No definition for $class");
    }

    /**
     * Register providers from {@link deferredProviders} if they provide
     * definition for given identifier.
     *
     * @param string $id class or identifier of a service.
     */
    private function registerProviderIfDeferredFor(string $id): void
    {
        $providers = $this->deferredProviders;

        foreach ($providers as $provider) {
            if ($provider->hasDefinitionFor($id)) {
                $provider->register($this);

                // provider should be removed after registration to not be registered again
                $providers->detach($provider);
            }
        }
    }

    /**
     * Sets a definition to the container. Definition may be defined multiple ways.
     * @param string $id
     * @param mixed $definition
     * @throws InvalidConfigException
     * @see `Normalizer::normalize()`
     */
    public function set(string $id, $definition): void
    {
        $this->instances[$id] = null;
        $this->definitions[$id] = Normalizer::normalize($definition, $id);
    }

    /**
     * Sets multiple definitions at once.
     * @param array $config definitions indexed by their ids
     * @throws InvalidConfigException
     */
    public function setMultiple(array $config): void
    {
        foreach ($config as $id => $definition) {
            $this->set($id, $definition);
        }
    }

    /**
     * Returns a value indicating whether the container has the definition of the specified name.
     * @param string $id class name, interface name or alias name
     * @return bool whether the container is able to provide instance of class specified.
     * @see set()
     */
    public function has($id): bool
    {
        return isset($this->definitions[$id]);
    }

    /**
     * Adds service provider to the container. Unless service provider is deferred
     * it would be immediately registered.
     *
     * @param string|array $providerDefinition
     *
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     * @see ServiceProviderInterface
     * @see DeferredServiceProviderInterface
     */
    public function addProvider($providerDefinition): void
    {
        $provider = $this->buildProvider($providerDefinition);

        if ($provider instanceof DeferredServiceProviderInterface) {
            $this->deferredProviders->attach($provider);
        } else {
            $provider->register($this);
        }
    }

    /**
     * Builds service provider by definition.
     *
     * @param string|array $providerDefinition class name or definition of provider.
     * @return ServiceProviderInterface instance of service provider;
     *
     * @throws InvalidConfigException
     */
    private function buildProvider($providerDefinition): ServiceProviderInterface
    {
        $provider = Normalizer::normalize($providerDefinition)->resolve($this);
        if (!($provider instanceof ServiceProviderInterface)) {
            throw new InvalidConfigException(
                'Service provider should be an instance of ' . ServiceProviderInterface::class
            );
        }

        return $provider;
    }

    /**
     * Returns a value indicating whether the container has already instantiated
     * instance of the specified name.
     * @param string|Reference $id class name, interface name or alias name
     * @return bool whether the container has instance of class specified.
     */
    public function hasInstance($id): bool
    {
        $id = $this->getId($id);

        return isset($this->instances[$id]);
    }

    /**
     * Returns all instances set in container
     * @return array list of instance
     */
    public function getInstances() : array
    {
        return $this->instances;
    }
}

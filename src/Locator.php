<?php

declare(strict_types=1);

namespace Spot;

/**
 * Service locator — the entry point for obtaining Mapper instances.
 *
 * Create a single Locator per application and pass it into any code that
 * needs to work with entities. It caches one Mapper instance per entity class.
 *
 * Usage:
 *
 *   $config = new \Spot\Config();
 *   $config->addConnection('default', 'sqlite::memory:');
 *   $spot = new \Spot\Locator($config);
 *   $mapper = $spot->mapper(MyEntity::class);
 *
 * @package Spot
 */
class Locator
{
    /**
     * Cached Mapper instances, keyed by entity class name.
     *
     * @var array<string, Mapper>
     */
    protected array $mapper = [];

    /**
     * @param Config $config The Spot configuration object.
     */
    public function __construct(protected Config $config)
    {
    }

    /**
     * Get or set the Config object.
     *
     * When $cfg is provided the internal config is replaced and the new
     * instance is returned.
     *
     * @throws Exception When no config has been set.
     */
    public function config(?Config $cfg = null): Config
    {
        if ($cfg !== null) {
            $this->config = $cfg;
        }

        return $this->config;
    }

    /**
     * Get (or create) the Mapper for a given entity class.
     *
     * If the entity defines a custom mapper via its static mapper() method,
     * that class is instantiated instead of the default Spot\Mapper.
     */
    public function mapper(string $entityName): Mapper
    {
        if (!isset($this->mapper[$entityName])) {
            if (!class_exists($entityName)) {
                throw new \InvalidArgumentException("Entity class '{$entityName}' does not exist.");
            }

            $mapperClass = $entityName::mapper() ?: Mapper::class;
            /** @var Mapper $instance */
            $instance = new $mapperClass($this, $entityName);
            $this->mapper[$entityName] = $instance;
        }

        return $this->mapper[$entityName];
    }
}

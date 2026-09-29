<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Storage;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Manager;
use Syriable\UserPresence\Contracts\PresenceStore;
use Syriable\UserPresence\Exceptions\UnsupportedPresenceStore;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\Support\PresenceRepository;

/**
 * Resolves the configured presence store. Custom stores are registered with
 * UserPresence::extendStore($name, fn (Container $app) => new MyStore).
 */
final class PresenceStoreManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->container->make(PresenceConfig::class)->presenceStore();
    }

    public function store(?string $name = null): PresenceStore
    {
        $name ??= $this->getDefaultDriver();

        $store = $this->driver($name);

        if (! $store instanceof PresenceStore) {
            throw UnsupportedPresenceStore::invalid($name, PresenceStore::class);
        }

        return $store;
    }

    /**
     * Drop resolved stores so the next call honours changed configuration
     * or a replaced custom store.
     */
    public function flush(): void
    {
        $this->forgetDrivers();
    }

    protected function createDatabaseDriver(): PresenceStore
    {
        return new DatabasePresenceStore($this->container->make(PresenceRepository::class));
    }

    protected function createCacheDriver(): PresenceStore
    {
        $config = $this->container->make(PresenceConfig::class);

        return new CachePresenceStore(
            $this->container->make(CacheFactory::class)->store($config->cacheStore()),
            $config->cacheTtl(),
        );
    }
}

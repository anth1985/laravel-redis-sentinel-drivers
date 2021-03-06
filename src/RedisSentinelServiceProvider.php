<?php

namespace Monospice\LaravelRedisSentinel;

use Illuminate\Broadcasting\Broadcasters\RedisBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Cache\RedisStore;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Monospice\LaravelRedisSentinel\Configuration\Loader as ConfigurationLoader;
use Monospice\LaravelRedisSentinel\RedisSentinelManager;
use Monospice\LaravelRedisSentinel\Manager;

/**
 * Registers the "redis-sentinel" driver as an available driver for Laravel's
 * cache, session, and queue services and loads the appropriate configuration.
 *
 * @category Package
 * @package  Monospice\LaravelRedisSentinel
 * @author   Cy Rossignol <cy@rossignols.me>
 * @license  See LICENSE file
 * @link     https://github.com/monospice/laravel-redis-sentinel-drivers
 */
class RedisSentinelServiceProvider extends ServiceProvider
{
    /**
     * Loads the package's configuration and provides configuration values.
     *
     * @var ConfigurationLoader
     */
    protected $config;

    /**
     * Boot the service by registering extensions with Laravel's cache, queue,
     * and session managers for the "redis-sentinel" driver.
     *
     * @return void
     */
    public function boot()
    {
        $this->addRedisSentinelBroadcaster();
        $this->addRedisSentinelCacheStore();
        $this->addRedisSentinelQueueConnector();

        // Since version 5.2, Lumen does not include support for sessions by
        // default, so we'll only register the session handler if enabled:
        if ($this->config->supportsSessions) {
            $this->addRedisSentinelSessionHandler();
        }

        // If we want Laravel's Redis API to use Sentinel, we'll remove the
        // "redis" service from the deferred services in the container:
        if ($this->config->shouldOverrideLaravelRedisApi()) {
            $this->removeDeferredRedisServices();
        }
    }

    /**
     * Bind the "redis-sentinel" database driver to the application service
     * container.
     *
     * @return void
     */
    public function register()
    {
        $this->config = ConfigurationLoader::load($this->app);

        $this->app->singleton('redis-sentinel', function ($app) {
            $class = $this->getVersionedRedisSentinelManagerClass();
            $config = $app->make('config')->get('database.redis-sentinel', [ ]);
            $driver = Arr::pull($config, 'client', 'predis');

            return new RedisSentinelManager(new $class($driver, $config));
        });

        // If we want Laravel's Redis API to use Sentinel, we'll return an
        // instance of the RedisSentinelManager when requesting the "redis"
        // service:
        if ($this->config->shouldOverrideLaravelRedisApi()) {
            $this->registerOverrides();
        }
    }

    /**
     * Replace the standard Laravel Redis service with the Redis Sentinel
     * database driver so all Redis operations use Sentinel connections.
     *
     * @return void
     */
    protected function registerOverrides()
    {
        $this->app->singleton('redis', function ($app) {
            return $app->make('redis-sentinel');
        });

        $this->app->bind('redis.connection', function ($app) {
            return $app->make('redis-sentinel')->connection();
        });
    }

    /**
     * Remove the standard Laravel Redis service from the bound deferred
     * services so they don't overwrite Redis Sentinel registrations.
     *
     * @return void
     */
    protected function removeDeferredRedisServices()
    {
        if ($this->config->isLumen) {
            return;
        }

        $deferredServices = $this->app->getDeferredServices();

        unset($deferredServices['redis']);
        unset($deferredServices['redis.connection']);

        $this->app->setDeferredServices($deferredServices);
    }

    /**
     * Add "redis-sentinel" as an available brodcaster option to the Laravel
     * event broadcasting manager.
     *
     * @return void
     */
    protected function addRedisSentinelBroadcaster()
    {
        $this->app->make(BroadcastFactory::class)
            ->extend('redis-sentinel', function ($app, $conf) {
                $redis = $app->make('redis-sentinel')->getVersionedManager();
                $connection = Arr::get($conf, 'connection', 'default');

                return new RedisBroadcaster($redis, $connection);
            });
    }

    /**
     * Add "redis-sentinel" as an available cache store option to the Laravel
     * cache manager.
     *
     * @return void
     */
    protected function addRedisSentinelCacheStore()
    {
        $this->app->make('cache')
            ->extend('redis-sentinel', function ($app, $conf) {
                $redis = $app->make('redis-sentinel')->getVersionedManager();
                $prefix = $app->make('config')->get('cache.prefix');
                $connection = Arr::get($conf, 'connection', 'default');
                $store = new RedisStore($redis, $prefix, $connection);

                return $this->repository($store);
            });
    }

    /**
     * Add "redis-sentinel" as an available driver option to the Laravel
     * session manager.
     *
     * @return void
     */
    protected function addRedisSentinelSessionHandler()
    {
        $this->app->make('session')->extend('redis-sentinel', function ($app) {
            $config = $app->make('config');
            $cacheDriver = clone $app->make('cache')->driver('redis-sentinel');
            $minutes = $config->get('session.lifetime');
            $connection = $config->get('session.connection');

            $cacheDriver->getStore()->setConnection($connection);

            return new CacheBasedSessionHandler($cacheDriver, $minutes);
        });
    }

    /**
     * Add "redis-sentinel" as an available queue connection driver option to
     * the Laravel queue manager.
     *
     * @return void
     */
    protected function addRedisSentinelQueueConnector()
    {
        $this->app->make('queue')->extend('redis-sentinel', function () {
            $redis = $this->app->make('redis-sentinel')->getVersionedManager();

            return new RedisConnector($redis);
        });
    }

    /**
     * Get the fully-qualified class name of the RedisSentinelManager class
     * for the current version of Laravel or Lumen.
     *
     * @return string The class name of the appropriate RedisSentinelManager
     * with its namespace
     */
    protected function getVersionedRedisSentinelManagerClass()
    {
        if ($this->config->isLumen) {
            $appVersion = substr($this->app->version(), 7, 3); // ex. "5.4"
            $frameworkVersion = '5.4';
        } else {
            $appVersion = \Illuminate\Foundation\Application::VERSION;
            $frameworkVersion = '5.4.20';
        }

        if (version_compare($appVersion, $frameworkVersion, 'lt')) {
            return Manager\Laravel540RedisSentinelManager::class;
        }

        return Manager\Laravel5420RedisSentinelManager::class;
    }
}

<?php
declare(strict_types=1);

namespace Muffin\Throttle;

use Cake\Cache\Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

trait ThrottleTrait
{
    /*
     * Default config for Throttle Middleware
     *
     * @var array
     */
    protected $_throttleConfig = [
        'response' => [
            'body' => 'Rate limit exceeded',
            'type' => 'text/html',
            'headers' => [],
        ],
        'interval' => '+1 minute',
        'limit' => 10,
        'headers' => [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset',
        ],
    ];

    /**
     * Cache configuration name
     *
     * @var string
     */
    public static $cacheConfig = 'throttle';

    /**
     * Cache expiration key suffix
     *
     * @var string
     */
    public static $cacheExpirationSuffix = 'expires';

    /**
     * Unique client identifier
     *
     * @var string
     */
    protected $_identifier;

    /**
     * Number of connections after increment
     *
     * @var int
     */
    protected $_count;

    /**
     * Set the default configuration
     *
     * @return array An array of default configuration
     */
    protected function _setConfiguration(): array
    {
        $this->_throttleConfig['identifier'] = function ($request) {
            return $request->clientIp();
        };

        return $this->_throttleConfig;
    }

    /**
     * Sets the identifier class property. Uses Throttle default IP address
     * based identifier unless a callable alternative is passed.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request RequestInterface instance
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function _setIdentifier(ServerRequestInterface $request): void
    {
        $key = $this->getConfig('identifier');
        if (!is_callable($this->getConfig('identifier'))) {
            throw new \InvalidArgumentException('Throttle identifier option must be a callable');
        }
        $this->_identifier = $key($request);
    }

    /**
     * Initializes cache configuration.
     *
     * @return void
     */
    protected function _initCache(): void
    {
        if (Cache::getConfig(static::$cacheConfig) === null) {
            Cache::setConfig(static::$cacheConfig, [
                'className' => $this->_getDefaultCacheConfigClassName(),
                'prefix' => static::$cacheConfig . '_' . $this->_identifier,
                'duration' => $this->getConfig('interval'),
            ]);
        }
    }

    /**
     * Gets the className of the default CacheEngine so the Throttle cache
     * config can use the same. String cast is required to catch a DebugEngine
     * array/object for users with DebugKit enabled.
     *
     * @return string ClassName property of default Cache engine
     */
    protected function _getDefaultCacheConfigClassName(): string
    {
        $config = Cache::getConfig('default');
        $engine = (string)$config['className'];

        // short cache engine names can be returned immediately
        if (strpos($engine, '\\') === false) {
            return $engine;
        }
        // fully namespace cache engine names need extracting class name
        preg_match('/.+\\\\(.+)Engine/', $engine, $matches);

        return $matches[1];
    }

    /**
     * Atomically updates cache using default CakePHP increment offset 1.
     *
     * Please note that the cache key needs to be initialized to prevent
     * increment() failing on 0. A separate cache key is created to store
     * the interval expiration time in epoch.
     *
     * @return int
     */
    protected function _touch(): int
    {
        if (Cache::read($this->_identifier, static::$cacheConfig) === null) {
            Cache::write($this->_identifier, 0, static::$cacheConfig);
            Cache::write(
                $this->_getCacheExpirationKey(),
                strtotime($this->getConfig('interval'), time()),
                static::$cacheConfig
            );
        }

        return Cache::increment($this->_identifier, 1, static::$cacheConfig) ?? 0;
    }

    /**
     * Returns cache key holding the epoch cache expiration timestamp.
     *
     * @return string Cache key holding cache expiration in epoch.
     */
    protected function _getCacheExpirationKey(): string
    {
        return $this->_identifier . '_' . static::$cacheExpirationSuffix;
    }

    /**
     * Extends response with X-headers containing rate limiting information.
     *
     * @param \Psr\Http\Message\ResponseInterface $response ResponseInterface instance
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function _setHeaders(ResponseInterface $response): ResponseInterface
    {
        $headers = $this->getConfig('headers');

        if (!is_array($headers)) {
            return $response;
        }

        return $response
            ->withHeader($headers['limit'], (string)$this->getConfig('limit'))
            ->withHeader($headers['remaining'], (string)$this->_getRemainingConnections())
            ->withHeader(
                $headers['reset'],
                (string)Cache::read($this->_getCacheExpirationKey(), static::$cacheConfig)
            );
    }

    /**
     * Calculates the number of hits remaining before client reaches rate limit.
     *
     * @return int Number of remaining client hits, zero if limit is reached
     */
    protected function _getRemainingConnections(): int
    {
        $remaining = $this->getConfig('limit') - $this->_count;
        if ($remaining <= 0) {
            return 0;
        }

        return $remaining;
    }
}

# CacheBundle

Add **cache abstraction** and **method annotations** for controlling cache.
The current implementation of the Cache component is a wrapper (proxy) for Doctrine\Common\Cache.

## Features

* `@Cacheable`, `@CacheUpdate`, `@CacheEvict` annotation support
* TTL strategy, allow you to customize cache retention
* Namespaced cache manager
* Multiple cache managers:
   * Doctrine/ArrayCache
   * Doctrine/ApcCache
   * Doctrine/MemcachedCache


## Index

* [State](#state)
* [Installation](#installation)
* [Configuration](#configuration)
* [Usage](#usage)
    * [Basic usage](#basic-usage)
    * [Annotation usage (recommended)](#annotation-usage)
        * [@Cacheable annotation](#cacheable-annotation)
        * [@CacheEvict annotation](#cacheevict-annotation)
        * [@CacheUpdate annotation](#cacheupdate-annotation)
        * [Expression Language](#expression-language)
    * [Custom Cache Manager](#custom-cache-manager)
    * [Key generation](#key-generation)
    * [Custom Key generation](#custom-key-generation)
    * [TTL Strategy](#ttl-strategy)
* [Testing](#testing)
* [License](#license)


## State

[![Build Status](https://travis-ci.org/TheBigBrainsCompany/TbbcCacheBundle.png?branch=master)](https://travis-ci.org/TheBigBrainsCompany/TbbcCacheBundle)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/TheBigBrainsCompany/TbbcCacheBundle/badges/quality-score.png?s=7f9dd7e70a3dc68f4b9e9823609e71d6e18a1a42)](https://scrutinizer-ci.com/g/TheBigBrainsCompany/TbbcCacheBundle/)
[![Downloads](https://poser.pugx.org/tbbc/cache-bundle/downloads.png)](https://packagist.org/packages/tbbc/cache-bundle)
[![Stable release](https://poser.pugx.org/tbbc/cache-bundle/v/stable.png)](https://packagist.org/packages/tbbc/cache-bundle)

## Installation

First, install the bundle package with composer:

```bash
$ php composer.phar require tbbc/cache-bundle
```

Next, activate the bundle into `app/AppKernel.php`:

```PHP
<?php

// ...
    public function registerBundles()
    {
        $bundles = array(
            //...
            new Tbbc\CacheBundle\TbbcCacheBundle(),
        );

        // ...
    }
```

## Configuration

```YAML
services:
    my_manager.product:
        class: My\Manager\ProductManager
        tags:
            - { name: tbbc_cache.cache_eligible }

tbbc_cache:
    annotations: { enabled: true }
    manager: simple_cache
    key_generator: simple_hash
    metadata:
        use_cache: true # Whether or not use metadata cache
        cache_dir: %kernel.cache_dir%/tbbc_cache
    cache:
        products:
            type: memcached
            servers:
                memcached-01: { host: localhost, port: 11211 }
```

*Note*: The `tbbc_cache.cache_eligible` tag is mandatory in your service definition if you want to be able to use
 annotation for this service.

## Usage

### Basic Usage

`CacheManager` instance must be injected into services that need cache management.

The `CacheManager` gives access to each configured cache (see [Configuration](#configuration) section).
Each cache implements [CacheInterface](https://github.com/TheBigBrainsCompany/TbbcCacheBundle/tree/master/Cache/CacheInterface.php).

Usage:

```PHP
<?php

namespace My\Manager;

use Tbbc\CacheBundle\Annotation\Cacheable;

class ProductManager
{
    private $cacheManager;
    private $keyGenerator;

    public function __construct(CacheManagerInterface $cacheManager, KeyGeneratorInterface $keyGenerator)
    {
        $this->cacheManager = $cacheManager;
        $this->keyGenerator = $keyGenerator;
    }


    public function getProduct($sku, $type = 'book')
    {
        $cacheKey   = $this->keyGenerator->generateKey($sku);
        $cache      = $this->cacheManager->getCache('products');

        if ($product = $cache->get($cacheKey)) {
            return $product;
        }

        $product = $this->productRepository->findProductBySkuAndType($sku, $type);

        $cache->set($cacheKey, $product);

        return $product;
    }

    public function saveProduct(Product $product)
    {
        $this->productRepository->save($product);

        $cacheKey   = $this->keyGenerator->generateKey($product->getSku());
        $cache      = $this->cacheManager->getCache('products');

        $cache->delete($cacheKey);
    }
}
```

### Annotation based caching

**Recommended**

If some prefer to avoid repeating code each time they want to add some caching logic, the bundle can automate the process
by using [AOP](http://en.wikipedia.org/wiki/Aspect-oriented_programming) approach and annotations.

The bundle provides the following annotations:
* [@Cacheable](#cacheable-annotation)
* [@CacheEvict](#cacheevict-annotation)
* [@CacheUpdate](#cacheupdate-annotation)

#### @Cacheable annotation

@Cacheable annotation is used to automatically store the result of a method into the cache.

When a method demarcated with the @Cacheable annotation is called, the bundle checks if an entry exists in the cache
before executing the method. If it finds one, the cache result is returned without having to actually execute the method.

If no cache entry is found, the method is executed and the bundle automatically stores its result into the cache.

```PHP
<?php

namespace My\Manager;

use My\Model\Product;

use Tbbc\CacheBundle\Annotation\Cacheable;

class ProductManager
{
    /**
     * @Cacheable(caches="products", key="sku")
     */
    public function getProduct($sku, $type = 'book')
    {
        $product = new Product($sku, $type);

        return $product;
    }
}
```

#### @CacheEvict annotation

@CacheEvict annotation allows methods to trigger cache population or cache eviction.

When a method is demarcated with @CacheEvict annotation, the bundle will execute the method and then will automatically
try to delete the cache entry with the provided key.

```PHP
<?php

namespace My\Manager;

use My\Model\Product;

use Tbbc\CacheBundle\Annotation\CacheEvict;

class ProductManager
{
    /**
     * @CacheEvict(caches="products", key="product.getSku()")
     */
    public function saveProduct(Product $product)
    {
        // saving product ...
    }
}
```

It is also possible to flush completely the caches by setting `allEntries` parameter to `true`

:warning: **Important note**: _when using the `allEntries` option you have to be really careful, if you
 use the same cache manager for different namespace, the whole cache manager will be flushed. This is currently
 a limitation of the underlying Doctrine Cache library_.

```PHP
<?php

namespace My\Manager;

use My\Model\Product;

use Tbbc\CacheBundle\Annotation\CacheEvict;

class ProductManager
{
    /**
     * @CacheEvict(caches="products", allEntries=true)
     */
    public function saveProduct(Product $product)
    {
        // saving product ...
    }
}
```

**Note**: If you also provide a `key`, it will be ignored and the cache will be flushed.

#### @CacheUpdate annotation

@CacheUpdate annotation is useful for cases where the cache needs to be updated without interfering with the method
execution.

When a method is demarcated with @CacheUpdate annotation, the bundle will always execute the method and then will
automatically try to update the cache entry with the method result.

```php
<?php

namespace My\Manager;

use My\Model\Product;

use Tbbc\CacheBundle\Annotation\CacheUpdate;

class ProductManager
{
    /**
     * @CacheUpdate(caches="products", key="product.getSku()")
     */
    public function saveProduct(Product $product)
    {
        // saving product....

        return $product;
    }
}
```

#### Expression Language

For key generation, [Symfony Expression Language](http://symfony.com/doc/current/components/expression_language/index.html) can be used.

```php
/**
 * @CacheUpdate(caches="products", key="product.getSku()")
 */
 public function saveProduct(Product $product)
 {
    // do something
 }
 ```

 The Expression Language allow you to retrieve any arguments passed to your method and use it to generate the cache key.

### Custom Cache Manager

Out of the box, the bundle provides a
[SimpleCacheManager](https://github.com/TheBigBrainsCompany/TbbcCacheBundle/tree/master/Cache/SimpleCacheManager.php), but
custom cache managers can be used instead of the default one and must implement the
[CacheManagerInterface](https://github.com/TheBigBrainsCompany/TbbcCacheBundle/tree/master/Cache/CacheManagerInterface.php).


### Key generation

Key generation is up to the developer, but for convenience, the bundle comes with some key generation logic.

**Note**: When using [Annotation based caching](#annotation-usage), usage of Key generators is mandatory.

Out of the box, the bundle provides a
[SimpleHashKeyGenerator](https://github.com/TheBigBrainsCompany/TbbcCacheBundle/tree/master/Cache/KeyGenerator/SimpleHashKeyGenerator.php)
which basically adds each param encoded using md5 algorithm, and returned a md5 hash of the result.

For testing purpose you may also use
[LiteralKeyGenerator](https://github.com/TheBigBrainsCompany/TbbcCacheBundle/tree/master/Cache/KeyGenerator/LiteralKeyGenerator.php)
which build a slug-like key.

**Note**: Both generators does **not** support non-scalar keys such as objects.

You can override the Key Generator by setting the `key_generator` key in your `config.yml`

Allowed values are: `simple_hash`, `literal` or the id of the service of your custom Key generator

### Custom Key generation

Custom key generators can be used instead of the default one and must implement the
[KeyGeneratorInterface](https://github.com/TheBigBrainsCompany/TbbcCacheBundle/tree/master/Cache/KeyGenerator/KeyGeneratorInterface.php).


### TTL Strategy

Since this bundle provides a cache abstraction and not all cache providers support or handle TTL the same way,
TTL strategy must be defined in each cache configuration options (when option is supported).

Example:
```YAML
tbbc_cache:
    annotations: { enabled: true }
    manager: simple_cache
    cache:
        products:
            type: memcached
            ttl: 86400 # 1 day
            servers:
                memcached-01: { host: localhost, port: 11211 }
        user_feeds:
            type: memcached
            ttl: 0 # infinite (same as omitting the option)
        followers_list:
            type: apc
            ttl: 1296000 # 15 days
```

## Testing

Install development dependencies

```bash
$ composer install --dev
```

Run the test suite

```bash
$ vendor/bin/phpunit
```

## License

This bundle is under the MIT license. See the complete license in the bundle:

    Resources/meta/LICENSE

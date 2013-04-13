<?php

/**
 * This file is part of KitanoCacheBundle
 *
 * (c) Kitano <contact@kitanolabs.org>
 *
 */

namespace Kitano\CacheBundle\DependencyInjection;

use Kitano\CacheBundle\DependencyInjection\CacheFactory\CacheFactoryInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * Class KitanoCacheExtension
 *
 * @author Benjamin Dulau <benjamin.dulau@gmail.com>
 */
class KitanoCacheExtension extends Extension
{
    /**
     * @var array|CacheFactoryInterface[]
     */
    protected $cacheFactories;

    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $processor = new Processor();

        $cacheFactories = $this->createCacheFactories();

        // Main configuration
        $configuration = new Configuration($cacheFactories);
        $config = $processor->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('cache.xml');
        $loader->load('doctrine_caches.xml');
        $loader->load('key_generators.xml');

        $managerId = $this->createCacheManager($config, $container);

        if (true === (bool) $config['annotations']['enabled']) {
            $bundles = $container->getParameter('kernel.bundles');
            if (!isset($bundles['JMSAopBundle'])) {
                throw new RuntimeException('The KitanoCacheBundle requires the JMSAopBundle for using annotations, please make sure to enable it in your AppKernel.');
            }

            $loader->load('aop.xml');

            $cacheDir = $container->getParameterBag()->resolveValue($config['annotations']['cache_dir']);
            if (!is_dir($cacheDir)) {
                if (false === @mkdir($cacheDir, 0777, true)) {
                    throw new RuntimeException(sprintf('Could not create cache directory "%s".', $cacheDir));
                }
            }
            $container->setParameter('kitano_cache.metadata.cache_dir', $cacheDir);

            $interceptor = $container->getDefinition('kitano_cache.aop.interceptor.cacheable');
            $interceptor->replaceArgument(1, new Reference($managerId));
            // TODO Remove hardcoded key generator id reference
            $interceptor->replaceArgument(2, new Reference('kitano_cache.key_generator.simple_hash'));
        }
    }

    private function createCacheManager(array $config, ContainerBuilder $container)
    {
        $managerId = sprintf('kitano_cache.%s_manager', strtolower($config['manager']));
        $managerReference = $container->getDefinition($managerId);

        foreach($config['cache'] as $name => $cache) {
            $cache['name'] = $name;
            $cacheId = $this->createCache($cache, $container);

            $managerReference->addMethodCall('addCache', array(new Reference($cacheId)));
        }

        return $managerId;
    }

    /**
     * Creates a single cache service
     *
     * @param array                         $config
     * @param ContainerBuilder              $container
     *
     * @return string
     */
    private function createCache(array $config, ContainerBuilder $container)
    {
        $type = $config['type'];
        if (array_key_exists($type, $this->cacheFactories)) {
            $id = sprintf('kitano_cache.%s_cache', $config['name']);
            $this->cacheFactories[$type]->create($container, $id, $config);

            return $id;
        }

        // TODO: throw exception ?
    }

    /**
     * Creates the cache factories
     *
     * @return array|CacheFactoryInterface[]
     */
    private function createCacheFactories()
    {
        if (null !== $this->cacheFactories) {
            return $this->cacheFactories;
        }

        // load bundled cache factories
        $tempContainer = new ContainerBuilder();
        $loader = new Loader\XmlFileLoader($tempContainer, new FileLocator(__DIR__.'/../Resources/config'));

        $loader->load('cache_factories.xml');

        $cacheFactories = array();
        foreach ($tempContainer->findTaggedServiceIds('kitano_cache.cache_factory') as $id => $factories) {
            foreach($factories as $factory) {
                if (!isset($factory['cache_type'])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Service "%s" must define a "cache_type" attribute for "kitano_cache.cache_factory" tag',
                        $id
                    ));
                }

                $factoryService = $tempContainer->get($id);
                $cacheFactories[str_replace('-', '_', strtolower($factory['cache_type']))] = $factoryService;
            }
        }

        return $this->cacheFactories = $cacheFactories;
    }
}
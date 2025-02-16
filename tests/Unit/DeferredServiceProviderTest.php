<?php
namespace Yiisoft\Di\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yiisoft\Di\Container;
use Yiisoft\Di\Tests\Support\Car;
use Yiisoft\Di\Tests\Support\CarDeferredProvider;
use Yiisoft\Di\Tests\Support\EngineInterface;
use Yiisoft\Di\Tests\Support\EngineMarkOne;

/**
 * Test for {@link \Yiisoft\Di\Support\DeferredServiceProvider}
 */
class DeferredServiceProviderTest extends TestCase
{
    public function testServiceProviderDeferring(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(Car::class), 'Container should not have Car before service provider added.');
        $this->assertFalse(
            $container->has(EngineInterface::class),
            'Container should not have EngineInterface before service provider added.'
        );

        $container->addProvider(CarDeferredProvider::class);

        $this->assertFalse(
            $container->has(Car::class),
            'Container should not have Car after adding deferred provider.'
        );
        $this->assertFalse(
            $container->has(EngineInterface::class),
            'Container should not have EngineInterface after adding deferred provider.'
        );

        $car = $container->get(Car::class);
        $engine = $container->get(EngineInterface::class);

        // ensure container return instances of classes register from provider
        $this->assertInstanceOf(Car::class, $car, 'Service provider should have set correct class for a Car.');
        $this->assertInstanceOf(
            EngineMarkOne::class,
            $engine,
            'Service provider should have set EngineInterface as an EngineMarkOne.'
        );

        // ensure get invoked DeferredServiceProviderInterface::register
        $this->assertTrue(
            $container->has(Car::class),
            'CarDeferredProvider should have registered Car once Car was requested from container.'
        );
        $this->assertTrue(
            $container->has(EngineInterface::class),
            'CarDeferredProvider should have registered EngineInterface once Car was requested from container.'
        );
    }
}

<?php

declare(strict_types=1);

namespace LaminasTest\ServiceManager\Factory;

use Laminas\ServiceManager\Factory\InvokableFactory;
use LaminasTest\ServiceManager\TestAsset\InvokableObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(InvokableFactory::class)]
final class InvokableFactoryTest extends TestCase
{
    public function testCanCreateObject(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $factory   = new InvokableFactory();

        $object = $factory($container, InvokableObject::class, ['foo' => 'bar']);

        self::assertInstanceOf(InvokableObject::class, $object);
        self::assertEquals(['foo' => 'bar'], $object->options);
    }
}

<?php

declare(strict_types=1);

namespace LaminasTest\ServiceManager;

use DateTime;
use Laminas\ServiceManager\AbstractPluginManager;
use Laminas\ServiceManager\Exception\ContainerModificationsNotAllowedException;
use Laminas\ServiceManager\Exception\CyclicAliasException;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Laminas\ServiceManager\Initializer\InitializerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\ServiceManager\ServiceManager;
use LaminasBench\ServiceManager\BenchAsset\AbstractFactoryFoo;
use LaminasTest\ServiceManager\TestAsset\CallTimesAbstractFactory;
use LaminasTest\ServiceManager\TestAsset\FailingAbstractFactory;
use LaminasTest\ServiceManager\TestAsset\FailingExceptionWithStringAsCodeFactory;
use LaminasTest\ServiceManager\TestAsset\FailingFactory;
use LaminasTest\ServiceManager\TestAsset\InvokableObject;
use LaminasTest\ServiceManager\TestAsset\PassthroughDelegatorFactory;
use LaminasTest\ServiceManager\TestAsset\SampleFactory;
use LaminasTest\ServiceManager\TestAsset\SimpleAbstractFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use stdClass;

use function array_fill_keys;
use function array_keys;
use function array_merge;
use function assert;
use function in_array;

/**
 * @see TestCase
 *
 * @psalm-import-type ServiceManagerConfiguration from ServiceManager
 * @psalm-require-extends TestCase
 */
trait CommonServiceLocatorBehaviorsTrait
{
    /**
     * The creation context container; used in some mocks for comparisons; set during createContainer.
     */
    protected static ContainerInterface|null $creationContext;

    /**
     * @psalm-param ServiceManagerConfiguration $config
     */
    abstract public static function createContainer(array $config = []): ServiceLocatorInterface;

    public function testIsSharedByDefault(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                stdClass::class => InvokableFactory::class,
            ],
        ]);

        $object1 = $serviceManager->get(stdClass::class);
        $object2 = $serviceManager->get(stdClass::class);

        self::assertSame($object1, $object2);
    }

    public function testCanDisableSharedByDefault(): void
    {
        $serviceManager = self::createContainer([
            'factories'         => [
                stdClass::class => InvokableFactory::class,
            ],
            'shared_by_default' => false,
        ]);

        $object1 = $serviceManager->get(stdClass::class);
        $object2 = $serviceManager->get(stdClass::class);

        self::assertNotSame($object1, $object2);
    }

    public function testCanDisableSharedForSingleService(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                stdClass::class => InvokableFactory::class,
            ],
            'shared'    => [
                stdClass::class => false,
            ],
        ]);

        $object1 = $serviceManager->get(stdClass::class);
        $object2 = $serviceManager->get(stdClass::class);

        self::assertNotSame($object1, $object2);
    }

    public function testCanEnableSharedForSingleService(): void
    {
        $serviceManager = self::createContainer([
            'factories'         => [
                stdClass::class => InvokableFactory::class,
            ],
            'shared_by_default' => false,
            'shared'            => [
                stdClass::class => true,
            ],
        ]);

        $object1 = $serviceManager->get(stdClass::class);
        $object2 = $serviceManager->get(stdClass::class);

        self::assertSame($object1, $object2);
    }

    public function testCanBuildObjectWithInvokableFactory(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                InvokableObject::class => InvokableFactory::class,
            ],
        ]);

        $object = $serviceManager->build(InvokableObject::class, ['foo' => 'bar']);

        self::assertInstanceOf(InvokableObject::class, $object);
        self::assertEquals(['foo' => 'bar'], $object->options);
    }

    public function testCanCreateObjectWithClosureFactory(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                stdClass::class => static function (ContainerInterface $container, $className): stdClass {
                    self::assertEquals(stdClass::class, $className);

                    return new stdClass();
                },
            ],
        ]);

        $object = $serviceManager->get(stdClass::class);

        self::assertInstanceOf(stdClass::class, $object);
    }

    public function testCanCreateServiceWithAbstractFactory(): void
    {
        $serviceManager = self::createContainer([
            'abstract_factories' => [
                new SimpleAbstractFactory(),
            ],
        ]);

        self::assertInstanceOf(DateTime::class, $serviceManager->get(DateTime::class));
    }

    public function testAllowsMultipleInstancesOfTheSameAbstractFactory(): void
    {
        CallTimesAbstractFactory::setCallTimes(0);

        $obj1 = new CallTimesAbstractFactory();
        $obj2 = new CallTimesAbstractFactory();

        $serviceManager = self::createContainer([
            'abstract_factories' => [
                $obj1,
                $obj2,
            ],
        ]);
        $serviceManager->addAbstractFactory($obj1);
        $serviceManager->addAbstractFactory($obj2);
        $serviceManager->has(stdClass::class);

        self::assertEquals(2, CallTimesAbstractFactory::getCallTimes());
    }

    public function testWillReUseAnExistingNamedAbstractFactoryInstance(): void
    {
        CallTimesAbstractFactory::setCallTimes(0);

        $serviceManager = self::createContainer([
            'abstract_factories' => [
                CallTimesAbstractFactory::class,
                CallTimesAbstractFactory::class,
            ],
        ]);
        $serviceManager->addAbstractFactory(CallTimesAbstractFactory::class);
        $serviceManager->has(stdClass::class);

        self::assertEquals(1, CallTimesAbstractFactory::getCallTimes());
    }

    public function testCanCreateServiceWithAlias(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                InvokableObject::class => InvokableFactory::class,
            ],
            'aliases'   => [
                'foo' => InvokableObject::class,
                'bar' => 'foo',
            ],
        ]);

        $object = $serviceManager->get('bar');

        self::assertInstanceOf(InvokableObject::class, $object);
        self::assertTrue($serviceManager->has('bar'));
        self::assertFalse($serviceManager->has('baz'));
    }

    public function testCheckingServiceExistenceWithChecksAgainstAbstractFactories(): void
    {
        $serviceManager = self::createContainer([
            'factories'          => [
                stdClass::class => InvokableFactory::class,
            ],
            'abstract_factories' => [
                new SimpleAbstractFactory(), // This one always return true
            ],
        ]);

        self::assertTrue($serviceManager->has(stdClass::class));
        self::assertTrue($serviceManager->has(DateTime::class));
    }

    public function testBuildNeverSharesInstances(): void
    {
        $container = self::createContainer([
            'factories' => [
                stdClass::class => InvokableFactory::class,
            ],
            'shared'    => [
                stdClass::class => true,
            ],
        ]);

        $object1 = $container->build(stdClass::class);
        self::assertInstanceOf(stdClass::class, $object1);
        $object2 = $container->build(stdClass::class, ['foo' => 'bar']);
        self::assertInstanceOf(stdClass::class, $object2);
        $object3 = $container->get(stdClass::class);
        self::assertInstanceOf(stdClass::class, $object3);

        self::assertNotSame($object1, $object2);
        self::assertNotSame($object1, $object3);
    }

    public function testInitializersAreRunAfterCreation(): void
    {
        $initializer = $this->createMock(InitializerInterface::class);

        $serviceManager = self::createContainer([
            'factories'    => [
                stdClass::class => InvokableFactory::class,
            ],
            'initializers' => [
                $initializer,
            ],
        ]);

        $initializer
            ->expects(self::once())
            ->method('__invoke')
            ->with(self::$creationContext, self::isInstanceOf(stdClass::class));

        // We call it twice to make sure that the initializer is only called once

        $serviceManager->get(stdClass::class);
        $serviceManager->get(stdClass::class);
    }

    public function testThrowExceptionIfServiceCannotBeCreated(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                stdClass::class => FailingFactory::class,
            ],
        ]);

        $this->expectException(ServiceNotCreatedException::class);

        $serviceManager->get(stdClass::class);
    }

    public function testThrowExceptionWithStringAsCodeIfServiceCannotBeCreated(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                stdClass::class => FailingExceptionWithStringAsCodeFactory::class,
            ],
        ]);

        $this->expectException(ServiceNotCreatedException::class);

        $serviceManager->get(stdClass::class);
    }

    public function testConfigureCanAddNewServices(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                DateTime::class => InvokableFactory::class,
            ],
        ]);

        self::assertTrue($serviceManager->has(DateTime::class));
        self::assertFalse($serviceManager->has(stdClass::class));

        $newServiceManager = $serviceManager->configure([
            'factories' => [
                stdClass::class => InvokableFactory::class,
            ],
        ]);

        self::assertSame($serviceManager, $newServiceManager);

        self::assertTrue($newServiceManager->has(DateTime::class));
        self::assertTrue($newServiceManager->has(stdClass::class));
    }

    public function testConfigureCanOverridePreviousSettings(): void
    {
        $firstFactory  = $this->createMock(FactoryInterface::class);
        $secondFactory = $this->createMock(FactoryInterface::class);

        $serviceManager = self::createContainer([
            'factories' => [
                DateTime::class => $firstFactory,
            ],
        ]);

        $newServiceManager = $serviceManager->configure([
            'factories' => [
                DateTime::class => $secondFactory,
            ],
        ]);

        self::assertSame($serviceManager, $newServiceManager);

        $firstFactory
            ->expects(self::never())
            ->method(self::anything());

        $date = new DateTime();
        $secondFactory
            ->expects(self::once())
            ->method('__invoke')
            ->willReturn($date);

        $dateFromServiceManager = $newServiceManager->get(DateTime::class);

        self::assertSame($date, $dateFromServiceManager);
    }

    public function testConfigureInvokablesTakePrecedenceOverFactories(): void
    {
        $firstFactory = $this->createMock(FactoryInterface::class);

        $serviceManager = self::createContainer([
            'aliases'    => [
                'custom_alias' => DateTime::class,
            ],
            'factories'  => [
                DateTime::class => $firstFactory,
            ],
            'invokables' => [
                'custom_alias' => stdClass::class,
            ],
        ]);

        $firstFactory->expects(self::never())->method('__invoke');

        $object = $serviceManager->get('custom_alias');

        self::assertInstanceOf(stdClass::class, $object);
    }

    #[Group('has')]
    public function testHasReturnsFalseIfServiceNotConfigured(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                stdClass::class => InvokableFactory::class,
            ],
        ]);

        self::assertFalse($serviceManager->has('Some\Made\Up\Entry'));
    }

    #[Group('has')]
    public function testHasReturnsTrueIfServiceIsConfigured(): void
    {
        $serviceManager = self::createContainer([
            'services' => [
                stdClass::class => new stdClass(),
            ],
        ]);

        self::assertTrue($serviceManager->has(stdClass::class));
    }

    #[Group('has')]
    public function testHasReturnsTrueIfFactoryIsConfigured(): void
    {
        $serviceManager = self::createContainer([
            'factories' => [
                stdClass::class => InvokableFactory::class,
            ],
        ]);

        self::assertTrue($serviceManager->has(stdClass::class));
    }

    /** @return array<string, array{0: AbstractFactoryInterface, 1: bool}> */
    public static function abstractFactories(): array
    {
        return [
            'simple'  => [new SimpleAbstractFactory(), true],
            'failing' => [new FailingAbstractFactory(), false],
        ];
    }

    #[DataProvider('abstractFactories')]
    #[Group('has')]
    public function testHasChecksAgainstAbstractFactories(
        AbstractFactoryInterface $abstractFactory,
        bool $expected,
    ): void {
        $serviceManager = self::createContainer([
            'abstract_factories' => [
                $abstractFactory,
            ],
        ]);

        self::assertSame($expected, $serviceManager->has(DateTime::class));
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::configure
     */
    public function testCanConfigureAllServiceTypes(): void
    {
        $serviceManager = self::createContainer([
            'services'           => [
                'config' => ['foo' => 'bar'],
            ],
            'factories'          => [
                stdClass::class => InvokableFactory::class,
            ],
            'delegators'         => [
                stdClass::class => [
                    static function (ContainerInterface $container, string $name, callable $callback): object {
                        $instance = $callback();
                        self::assertInstanceOf(stdClass::class, $instance);
                        $instance->foo = 'bar';

                        return $instance;
                    },
                ],
            ],
            'shared'             => [
                'config'        => true,
                stdClass::class => true,
            ],
            'aliases'            => [
                'Aliased' => stdClass::class,
            ],
            'shared_by_default'  => false,
            'abstract_factories' => [
                new SimpleAbstractFactory(),
            ],
            'initializers'       => [
                static function (ContainerInterface $container, $instance): void {
                    if (! $instance instanceof stdClass) {
                        return;
                    }

                    $instance->bar = 'baz';
                },
            ],
        ]);

        $dateTime = $serviceManager->get(DateTime::class);
        self::assertInstanceOf(DateTime::class, $dateTime, 'DateTime service did not resolve as expected');
        $notShared = $serviceManager->get(DateTime::class);
        self::assertInstanceOf(DateTime::class, $notShared, 'DateTime service did not re-resolve as expected');
        self::assertNotSame(
            $dateTime,
            $notShared,
            'Expected unshared instances for DateTime service but received shared instances',
        );

        $config = $serviceManager->get('config');
        self::assertIsArray($config, 'Config service did not resolve as expected');
        self::assertSame(
            $config,
            $serviceManager->get('config'),
            'Config service resolved as unshared instead of shared',
        );

        $stdClass = $serviceManager->get(stdClass::class);
        self::assertInstanceOf(stdClass::class, $stdClass, 'stdClass service did not resolve as expected');
        self::assertSame(
            $stdClass,
            $serviceManager->get(stdClass::class),
            'stdClass service should be shared, but resolved as unshared',
        );
        self::assertTrue(
            isset($stdClass->foo),
            'Expected delegator to inject "foo" property in stdClass service, but it was not',
        );
        self::assertEquals('bar', $stdClass->foo, 'stdClass "foo" property was not injected correctly');
        self::assertTrue(
            isset($stdClass->bar),
            'Expected initializer to inject "bar" property in stdClass service, but it was not',
        );
        self::assertEquals('baz', $stdClass->bar, 'stdClass "bar" property was not injected correctly');
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::configure
     */
    public function testCanSpecifyAbstractFactoryUsingStringViaConfiguration(): void
    {
        $serviceManager = self::createContainer([
            'abstract_factories' => [
                SimpleAbstractFactory::class,
            ],
        ]);

        $dateTime = $serviceManager->get(DateTime::class);

        self::assertInstanceOf(DateTime::class, $dateTime);
    }

    public function testCanSpecifyInitializerUsingStringViaConfiguration(): void
    {
        $serviceManager = self::createContainer([
            'factories'    => [
                stdClass::class => InvokableFactory::class,
            ],
            'initializers' => [
                TestAsset\SimpleInitializer::class,
            ],
        ]);

        $instance = $serviceManager->get(stdClass::class);

        self::assertInstanceOf(stdClass::class, $instance);
        self::assertTrue(isset($instance->foo), '"foo" property was not injected by initializer');
        self::assertEquals('bar', $instance->foo, '"foo" property was not properly injected');
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::getFactory
     */
    public function testGetRaisesExceptionWhenNoFactoryIsResolved(): void
    {
        $serviceManager = self::createContainer();
        $this->expectException(ContainerExceptionInterface::class);
        $this->expectExceptionMessage('Unable to resolve');
        $serviceManager->get('Some\Unknown\Service');
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::setAlias
     */
    #[Group('mutation')]
    public function testCanInjectAliases(): void
    {
        $container = self::createContainer([
            'factories' => [
                'foo' => static fn (): stdClass => new stdClass(),
            ],
        ]);

        $container->setAlias('bar', 'foo');

        $foo = $container->get('foo');
        $bar = $container->get('bar');

        self::assertInstanceOf(stdClass::class, $foo);
        self::assertInstanceOf(stdClass::class, $bar);
        self::assertSame($foo, $bar);
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::setInvokableClass
     */
    #[Group('mutation')]
    public function testCanInjectInvokables(): void
    {
        $container = self::createContainer();
        $container->setInvokableClass('foo', stdClass::class);

        self::assertTrue($container->has('foo'));
        self::assertTrue($container->has(stdClass::class));

        $foo = $container->get('foo');

        self::assertInstanceOf(stdClass::class, $foo);
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::setFactory
     */
    #[Group('mutation')]
    public function testCanInjectFactories(): void
    {
        $instance  = new stdClass();
        $container = self::createContainer();

        $container->setFactory('foo', static fn (): stdClass => $instance);

        self::assertTrue($container->has('foo'));

        $foo = $container->get('foo');

        self::assertSame($instance, $foo);
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::addAbstractFactory
     */
    #[Group('mutation')]
    public function testCanInjectAbstractFactories(): void
    {
        $container = self::createContainer();
        $container->addAbstractFactory(SimpleAbstractFactory::class);

        self::assertTrue($container->has(stdClass::class));

        $instance = $container->get(stdClass::class);

        self::assertInstanceOf(stdClass::class, $instance);
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::addDelegator
     */
    #[Group('mutation')]
    public function testCanInjectDelegators(): void
    {
        $container = self::createContainer([
            'factories' => [
                'foo' => static fn (): stdClass => new stdClass(),
            ],
        ]);

        $container->addDelegator('foo', static function ($container, $name, $callback) {
            $instance       = $callback();
            $instance->name = $name;

            return $instance;
        });

        $foo = $container->get('foo');

        self::assertInstanceOf(stdClass::class, $foo);
        self::assertSame('foo', $foo->name);
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::addInitializer
     */
    #[Group('mutation')]
    public function testCanInjectInitializers(): void
    {
        $container = self::createContainer([
            'factories' => [
                'foo' => static fn (): stdClass => new stdClass(),
            ],
        ]);
        $container->addInitializer(static function (ContainerInterface $container, $instance) {
            if (! $instance instanceof stdClass) {
                return $instance;
            }

            $instance->name = stdClass::class;

            return $instance;
        });

        $foo = $container->get('foo');

        self::assertInstanceOf(stdClass::class, $foo);
        self::assertSame(stdClass::class, $foo->name);
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::setService
     */
    #[Group('mutation')]
    public function testCanInjectServices(): void
    {
        $container = self::createContainer();
        $container->setService('foo', $this);

        self::assertSame($this, $container->get('foo'));
    }

    /**
     * @covers \Laminas\ServiceManager\ServiceManager::setShared
     */
    #[Group('mutation')]
    public function testCanInjectSharingRules(): void
    {
        $container = self::createContainer([
            'factories' => [
                'foo' => static fn (): stdClass => new stdClass(),
            ],
        ]);
        $container->setShared('foo', false);
        $first  = $container->get('foo');
        $second = $container->get('foo');

        self::assertNotSame($first, $second);
    }

    /** @return array<string, array{0: string, 1: mixed[]}> */
    public static function methodsAffectedByOverrideSettings(): array
    {
        //  name                        => [ 'method to invoke',  [arguments for invocation]]
        $that = new stdClass();

        return [
            'setAlias'                  => ['setAlias', ['foo', 'bar']],
            'setInvokableClass'         => ['setInvokableClass', ['foo', stdClass::class]],
            'setFactory'                => [
                'setFactory',
                [
                    'foo',
                    static function (): void {
                    },
                ],
            ],
            'setService'                => ['setService', ['foo', $that]],
            'setShared'                 => ['setShared', ['foo', false]],
            'mapLazyService'            => ['mapLazyService', ['foo', stdClass::class]],
            'addDelegator'              => [
                'addDelegator',
                [
                    'foo',
                    static function (): void {
                    },
                ],
            ],
            'configure-alias'           => ['configure', [['aliases' => ['foo' => 'bar']]]],
            'configure-invokable'       => ['configure', [['invokables' => ['foo' => 'foo']]]],
            'configure-invokable-alias' => ['configure', [['invokables' => ['foo' => 'bar']]]],
            'configure-factory'         => [
                'configure',
                [
                    [
                        'factories' => [
                            'foo' => static function (): void {
                            },
                        ],
                    ],
                ],
            ],
            'configure-service'         => ['configure', [['services' => ['foo' => $that]]]],
            'configure-shared'          => ['configure', [['shared' => ['foo' => false]]]],
            'configure-lazy-service'    => [
                'configure',
                [['lazy_services' => ['class_map' => ['foo' => stdClass::class]]]],
            ],
        ];
    }

    #[DataProvider('methodsAffectedByOverrideSettings')]
    #[Group('mutation')]
    public function testConfiguringInstanceRaisesExceptionIfAllowOverrideIsFalse(string $method, array $args): void
    {
        $container = self::createContainer(['services' => ['foo' => $this]]);
        $container->setAllowOverride(false);
        $this->expectException(ContainerModificationsNotAllowedException::class);
        $container->$method(...$args);
    }

    #[Group('mutation')]
    public function testAllowOverrideFlagIsFalseByDefault(): ContainerInterface
    {
        $container = self::createContainer();

        self::assertFalse($container->getAllowOverride());

        return $container;
    }

    #[Depends('testAllowOverrideFlagIsFalseByDefault')]
    #[Group('mutation')]
    public function testAllowOverrideFlagIsMutable(ServiceManager|AbstractPluginManager $container): void
    {
        $container->setAllowOverride(true);

        self::assertTrue($container->getAllowOverride());
    }

    #[Group('zendframework/zend-servicemanager#83')]
    public function testCrashesOnCyclicAliases(): void
    {
        $this->expectException(CyclicAliasException::class);

        self::createContainer([
            'aliases' => [
                'a' => 'b',
                'b' => 'a',
            ],
        ]);
    }

    public function testMinimalCyclicAliasDefinitionShouldThrow(): void
    {
        $sm = self::createContainer([]);

        $this->expectException(CyclicAliasException::class);
        $sm->setAlias('alias', 'alias');
    }

    public function testCoverageDepthFirstTaggingOnRecursiveAliasDefinitions(): void
    {
        $sm = self::createContainer([
            'factories' => [
                stdClass::class => InvokableFactory::class,
            ],
            'aliases'   => [
                'alias1' => 'alias2',
                'alias2' => 'alias3',
                'alias3' => stdClass::class,
            ],
        ]);
        self::assertSame($sm->get('alias1'), $sm->get('alias2'));
        self::assertSame($sm->get(stdClass::class), $sm->get('alias1'));
    }

    /**
     * The ServiceManager can change internal state on calls to get,
     * build or has, latter not currently. Possible state changes
     * are caching a factory, registering a service produced by
     * a factory, ...
     *
     * This tests performs three consecutive calls to build/get for
     * each registered service to push the service manager through
     * all internal states, thereby verifying that build/get/has
     * remain stable through the internal states.
     *
     * @param list<non-empty-string> $test
     */
    #[DataProvider('provideConsistencyOverInternalStatesTests')]
    public function testConsistencyOverInternalStates(
        ContainerInterface $smTemplate,
        string $name,
        array $test,
        bool $shared
    ): void {
        $sm     = clone $smTemplate;
        $object = [
            'get'   => [],
            'build' => [],
        ];

        // call get()/build() and store the retrieved
        // objects in $object['get'] or $object['build']
        // respectively
        foreach ($test as $method) {
            assert(in_array($method, ['get', 'build'], true));
            $obj = $sm->$method($name);

            self::assertNotNull($obj);
            self::assertTrue($sm->has($name));

            $target = $method;
            if (! $shared) {
                $target = 'build';
            }

            $object[$target][] = $obj;
        }

        // compares the first to the first also, but ok
        foreach ($object['get'] as $sharedObj) {
            self::assertSame($object['get'][0], $sharedObj);
        }
        // objects from object['build'] have to be different
        // from all other objects
        foreach ($object['build'] as $idx1 => $nonSharedObj1) {
            self::assertNotContains($nonSharedObj1, $object['get']);

            foreach ($object['build'] as $idx2 => $nonSharedObj2) {
                if ($idx1 !== $idx2) {
                    self::assertNotSame($nonSharedObj1, $nonSharedObj2);
                }
            }
        }
    }

    /**
     * Data provider
     *
     * @see testConsistencyOverInternalStates above
     *
     * @return array<
     *     array{
     *      0: ContainerInterface,
     *      1: string,
     *      2: list<non-empty-string>,
     *      3: bool
     *     }
     * >
     */
    public static function provideConsistencyOverInternalStatesTests(): array
    {
        $config1                      = [
            'factories'          => [
                // to allow build('service')
                'service'   => static fn ($container, $requestedName, ?array $options = null): stdClass =>
                    new stdClass(),
                'factory'   => SampleFactory::class,
                'delegator' => SampleFactory::class,
            ],
            'delegators'         => [
                'delegator' => [
                    PassthroughDelegatorFactory::class,
                ],
            ],
            'invokables'         => [
                'invokable' => InvokableObject::class,
            ],
            'services'           => [
                'service' => new stdClass(),
            ],
            'aliases'            => [
                'serviceAlias'         => 'service',
                'invokableAlias'       => 'invokable',
                'factoryAlias'         => 'factory',
                'abstractFactoryAlias' => 'foo',
                'delegatorAlias'       => 'delegator',
            ],
            'abstract_factories' => [
                AbstractFactoryFoo::class,
            ],
        ];
        $config2                      = $config1;
        $config2['shared_by_default'] = false;

        $configs = [$config1, $config2];

        // produce all 3-tuples of 'build' and 'get', i.e.
        //
        // [['get', 'get', 'get'], ['get', 'get', 'build'], ...
        // ['build', 'build', 'build']]
        $callSequences = [];
        $methods       = ['get', 'build'];
        foreach ($methods as $method1) {
            foreach ($methods as $method2) {
                foreach ($methods as $method3) {
                    $callSequences[] = [$method1, $method2, $method3];
                }
            }
        }

        $tests = [];

        foreach ($configs as $config) {
            $smTemplate      = self::createContainer($config);
            $sharedByDefault = $config['shared_by_default'] ?? true;

            // setup sharing, services are always shared
            /** @var array<string,bool> $names */
            $names = array_fill_keys(array_keys($config['services']), true);

            // initialize the other keys with shared_by_default
            // and merge them
            foreach (
                array_keys(array_merge(
                    $config['factories'] ?? [],
                    $config['invokables'] ?? [],
                    $config['aliases'] ?? [],
                    $config['delegators'] ?? [],
                )) as $name
            ) {
                // do not change shared setting for service placed in `services` as services are always shared
                if (isset($config['services'][$name])) {
                    continue;
                }

                $names[$name] = $sharedByDefault;
            }

            // add the key resolved by the abstract factory
            $names['foo'] = $sharedByDefault;

            foreach ($names as $name => $shared) {
                foreach ($callSequences as $callSequence) {
                    $tests[] = [$smTemplate, $name, $callSequence, $shared];
                }
            }
        }

        return $tests;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        Container::setInstance($this->container);
    }

    public function test_can_bind_and_resolve_singleton(): void
    {
        $this->container->singleton('custom_service', fn() => new \stdClass());

        $first = $this->container->get('custom_service');
        $second = $this->container->get('custom_service');

        $this->assertInstanceOf(\stdClass::class, $first);
        $this->assertSame($first, $second);
    }

    public function test_can_bind_and_resolve_factory(): void
    {
        $this->container->bind('counter', function () {
            static $i = 0;
            $obj = new \stdClass();
            $obj->count = ++$i;
            return $obj;
        });

        $first = $this->container->make('counter');
        $second = $this->container->make('counter');

        $this->assertEquals(1, $first->count);
        $this->assertEquals(2, $second->count);
        $this->assertNotSame($first, $second);
    }

    public function test_auto_wires_class_dependencies(): void
    {
        $resolved = $this->container->get(SampleDependentService::class);
        $this->assertInstanceOf(SampleDependentService::class, $resolved);
        $this->assertInstanceOf(SampleDependency::class, $resolved->dependency);
    }

    public function test_throws_exception_on_unresolvable_abstract(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->container->get('non_existent_key');
    }
}

class SampleDependency
{
    public string $name = 'dependency';
}

class SampleDependentService
{
    public function __construct(public SampleDependency $dependency)
    {
    }
}

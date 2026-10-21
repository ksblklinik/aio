<?php

declare(strict_types=1);

namespace Core;

use Closure;
use Core\Exceptions\ContainerException;
use Core\Exceptions\NotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class Container implements ContainerInterface
{
    private static ?Container $instance = null;

    /**
     * @var array<string, mixed>
     */
    private array $bindings = [];

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    public static function getInstance(): Container
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    /**
     * Daftarkan binding baru (bisa resolver callback atau nama kelas konkrit)
     */
    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared,
        ];
    }

    /**
     * Daftarkan singleton binding
     */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Resolve instansiasi dari container (PSR-11)
     */
    public function get(string $id): mixed
    {
        // 1. Jika sudah ada cached singleton instance
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. Jika ada binding terdaftar
        if (isset($this->bindings[$id])) {
            $binding = $this->bindings[$id];
            $concrete = $binding['concrete'];
            $object = $this->build($concrete);

            if ($binding['shared']) {
                $this->instances[$id] = $object;
            }

            return $object;
        }

        // 3. Jika class ada di namespace (auto-wire)
        if (class_exists($id)) {
            return $this->build($id);
        }

        throw new NotFoundException("Identifier [{$id}] tidak ditemukan di dalam Container.");
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * Buat instansi baru (selalu fresh)
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract]['concrete'];
            return $this->build($concrete, $parameters);
        }

        if (class_exists($abstract)) {
            return $this->build($abstract, $parameters);
        }

        throw new NotFoundException("Abstract [{$abstract}] tidak dapat di-resolve.");
    }

    /**
     * Bangun objek secara rekursif menggunakan Reflection (Auto-wiring)
     */
    private function build(mixed $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        if (is_object($concrete)) {
            return $concrete;
        }

        if (!is_string($concrete) || !class_exists($concrete)) {
            throw new ContainerException("Target [{$concrete}] tidak dapat diinstansiasi.");
        }

        $reflector = new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Target [{$concrete}] bukan tipe yang dapat diinstansiasi (abstract atau interface).");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $param) {
            $dependencies[] = $this->resolveParameter($param, $parameters);
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    private function resolveParameter(ReflectionParameter $param, array $overrideParameters = []): mixed
    {
        $name = $param->getName();
        if (array_key_exists($name, $overrideParameters)) {
            return $overrideParameters[$name];
        }

        $type = $param->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new ContainerException("Tidak dapat menyelesaikan parameter [{$name}] pada konstruktor.");
    }
}

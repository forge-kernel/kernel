<?php

declare(strict_types=1);

namespace Forge\Core\DI;

use Closure;
use Forge\Core\Cache\Attributes\Cache;
use Forge\Core\Cache\Attributes\NoCache;
use Forge\Core\Cache\CacheInterceptor;
use Forge\Core\Cache\CacheManager;
use Forge\Core\Cache\ProxyGenerator;
use Forge\Core\DI\Attributes\Injectable;
use Forge\Core\DI\Attributes\Service;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use ReflectionClass;
use RuntimeException;

final class Container
{
  private const QUICK_EXCLUDE_PATTERNS = [
    'Controller',
    'Middleware',
    'Request',
    'Response',
    'Session',
    'Config',
    'Container',
    'CacheInterceptor',
    'ProxyGenerator',
    'Reflection',
  ];

  private static ?Container $instance = null;
  private array $services = [];
  private array $instances = [];
  private array $parameters = [];
  private array $tags = [];
  private bool $isBootstrapping = true;
  private bool $recording = false;
  private array $recordedBindings = [];
  private ?CacheManager $cacheManager = null;
  private static array $reflectionCache = [];
  private static array $cacheAnalysisCache = [];
  private array $interfaceMapCompiled = [];
  private array $interfaceIndex = [];
  private bool $interfaceIndexDirty = true;
  private ?string $moduleNamespacePrefix = null;

  private function __construct()
  {
  }

  public static function getInstance(): Container
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public function setInstance(string $id, object $instance): void
  {
    $this->instances[$id] = $instance;
    $this->interfaceMapCompiled = [];
  }

  /**
   * @throws \ReflectionException
   */
  public function register(string $class): void
  {
    $reflection = new ReflectionClass($class);
    $attributes = $reflection->getAttributes(Injectable::class) ?: $reflection->getAttributes(Service::class);

    if (!empty($attributes)) {
      $serviceAttr = $attributes[0]->newInstance();
      $id = $serviceAttr->id ?? $class;
      $singleton = $serviceAttr->singleton;
    } else {
      $id = $class;
      $singleton = true;
    }

    if ($this->recording) {
      $this->recordedBindings[] = [
        'id' => $id,
        'concrete' => $class,
        'singleton' => $singleton,
      ];
    }

    $this->services[$id] = [
      "class" => $class,
      "singleton" => $singleton,
    ];
    $this->interfaceMapCompiled = [];
    $this->interfaceIndexDirty = true;
  }

  public function getServiceIds(): array
  {
    return array_keys($this->services);
  }

  public function startRecording(): void
  {
    $this->recording = true;
    $this->recordedBindings = [];
  }

  public function stopRecording(): void
  {
    $this->recording = false;
  }

  public function getRecordedBindings(): array
  {
    return $this->recordedBindings;
  }

  public function bind(
    string $id,
    Closure|string $concrete,
    bool $singleton = false,
  ): void {
    if ($this->recording && is_string($concrete)) {
      $this->recordedBindings[] = [
        'id' => $id,
        'concrete' => $concrete,
        'singleton' => $singleton,
      ];
    }
    $this->services[$id] = [
      "class" => $concrete,
      "singleton" => $singleton,
    ];
    $this->interfaceMapCompiled = [];
    $this->interfaceIndexDirty = true;
  }

  public function singleton(string $abstract, Closure|string $concrete): void
  {
    if ($this->recording && is_string($concrete)) {
      $this->recordedBindings[] = [
        'id' => $abstract,
        'concrete' => $concrete,
        'singleton' => true,
      ];
    }
    $this->services[$abstract] = [
      "class" => $concrete,
      "singleton" => true,
    ];
    $this->interfaceMapCompiled = [];
    $this->interfaceIndexDirty = true;
  }

  public function tag(string $tag, array $abstracts): void
  {
    foreach ($abstracts as $abstract) {
      $this->tags[$tag][] = $abstract;
    }
  }

  /**
   * @throws MissingServiceException
   */
  public function tagged(string $tag): array
  {
    return array_map(
      fn($abstract) => $this->make($abstract),
      $this->tags[$tag] ?? [],
    );
  }

  /**
   * @param string $abstract
   * @throws MissingServiceException
   */
  public function make(string $abstract): object
  {
    if ($abstract === self::class) {
      return $this;
    }

    if (isset($this->instances[$abstract])) {
      return $this->instances[$abstract];
    }

    if (str_starts_with($abstract, $this->getModuleNamespacePrefix())) {
      $this->loadModuleByClass($abstract);
    }

    if (isset($this->services[$abstract])) {
      $config = $this->services[$abstract];
      $object =
        $config["class"] instanceof Closure
        ? $config["class"]($this)
        : $this->build($config["class"]);

      $object = $this->wrapCacheIfNeeded($object);

      if ($config["singleton"]) {
        $this->instances[$abstract] = $object;
      }

      return $object;
    }

    if (class_exists($abstract)) {
      $object = $this->resolve($abstract);
      return $this->wrapCacheIfNeeded($object);
    }

    throw new MissingServiceException($abstract);
  }

  private function getModuleNamespacePrefix(): string
  {
    if ($this->moduleNamespacePrefix === null) {
      $this->moduleNamespacePrefix = \Forge\Core\Structure\StructureResolver::resolveModulesNamespace() . '\\';
    }
    return $this->moduleNamespacePrefix;
  }

  private function loadModuleByClass(string $class): void
  {
    $ns = preg_quote($this->getModuleNamespacePrefix(), '/');
    preg_match("/^({$ns}[^\\\\]+)/", $class, $matches);
    if (!empty($matches[1])) {
      $namespacePrefix = $matches[1];
      try {
        $loader = $this->get(Loader::class);
        $loader->loadModuleByNamespace($namespacePrefix);
      } catch (MissingServiceException $e) {
        error_log("Loader service not available: " . $e->getMessage());
      } catch (\Throwable $e) {
        error_log(
          "Error loading module for namespace '$namespacePrefix': " . $e->getMessage(),
        );
      }
    }
  }

  /**
   * Get service by id from the container
   * @param string $id
   * @throws MissingServiceException
   * @throws ResolveParameterException|\ReflectionException
   */
  public function get(string $id)
  {
    if ($id === self::class) {
      return $this;
    }

    if (isset($this->instances[$id])) {
      return $this->instances[$id];
    }

    $this->loadModuleByClass($id);

    if (isset($this->services[$id])) {
      $serviceConfig = $this->services[$id];

      if ($serviceConfig["class"] instanceof \Closure) {
        $instance = $serviceConfig["class"]($this);
      } else {
        $instance = $this->build($serviceConfig["class"]);
      }

      if ($serviceConfig["singleton"] ?? false) {
        $this->instances[$id] = $instance;
      }

      return $this->wrapCacheIfNeeded($instance);
    }

    if (class_exists($id)) {
      $instance = $this->resolve($id);
      return $this->wrapCacheIfNeeded($instance);
    }

    throw new MissingServiceException($id);
  }

  /** Build a class with dependencies
   * @throws ResolveParameterException
   * @throws \ReflectionException|MissingServiceException
   */
  private function build(string $class): object
  {
    if ($class === self::class) {
      return $this;
    }

    $reflector = new ReflectionClass($class);

    if (!($constructor = $reflector->getConstructor())) {
      return new $class();
    }

    $dependencies = [];
    foreach ($constructor->getParameters() as $parameter) {
      $type = $parameter->getType();

      if ($type->isBuiltin()) {
        if ($parameter->isDefaultValueAvailable()) {
          $dependencies[] = $parameter->getDefaultValue();
          continue;
        }
        if ($parameter->allowsNull()) {
          $dependencies[] = null;
          continue;
        }
        throw new ResolveParameterException(
          "Cannot resolve parameter {$parameter->name} in {$class}",
        );
      }

      if ($type instanceof \ReflectionNamedType) {
        try {
          $dependencies[] = $this->make($type->getName());
        } catch (MissingServiceException $e) {
          if ($parameter->allowsNull()) {
            $dependencies[] = null;
          } else {
            throw $e;
          }
        }
      } else {
        throw new ResolveParameterException(
          "Cannot resolve parameter {$parameter->name} in {$class}. Unsupported type.",
        );
      }
    }

    return $reflector->newInstanceArgs($dependencies);
  }

  /** Resolve a class and its dependencies using auto wiring
   * @throws ResolveParameterException
   * @throws \ReflectionException|MissingServiceException
   */
  private function resolve(string $class): object
  {
    if ($class === self::class) {
      return $this;
    }

    $reflector = new ReflectionClass($class);
    $constructor = $reflector->getConstructor();

    if (!$constructor) {
      return new $class();
    }

    $dependencies = [];
    foreach ($constructor->getParameters() as $parameter) {
      $type = $parameter->getType();

      if (!$type) {
        throw new ResolveParameterException(
          "Cannot resolve parameter {$parameter->name} in {$class} because its type is not hinted.",
        );
      }

      if ($type->isBuiltin()) {
        if ($parameter->isDefaultValueAvailable()) {
          $dependencies[] = $parameter->getDefaultValue();
          continue;
        }
        if ($parameter->allowsNull()) {
          $dependencies[] = null;
          continue;
        }
        throw new ResolveParameterException(
          "Cannot resolve parameter {$parameter->name} in {$class} because it is a built-in type and cannot be auto-resolved.",
        );
      }

      if ($type instanceof \ReflectionNamedType) {
        $dependencyClass = $type->getName();
        try {
          $dependencies[] = $this->get($dependencyClass);
        } catch (MissingServiceException $e) {
          if ($parameter->allowsNull()) {
            $dependencies[] = null;
          } else {
            throw new ResolveParameterException(
              "Failed to resolve dependency {$dependencyClass} for parameter {$parameter->getName()} in {$class}: " .
              $e->getMessage(),
              0,
              $e,
            );
          }
        } catch (RuntimeException $e) {
          throw new ResolveParameterException(
            "Failed to resolve dependency {$dependencyClass} for parameter {$parameter->getName()} in {$class}: " .
            $e->getMessage(),
            0,
            $e,
          );
        }
      } else {
        throw new ResolveParameterException(
          "Cannot resolve parameter {$parameter->name} in {$class}. Unsupported type: " .
          $type,
        );
      }
    }
    return $reflector->newInstanceArgs($dependencies);
  }

  private function wrapCacheIfNeeded(object $instance): object
  {
    if ($this->isBootstrapping) {
      return $instance;
    }

    if ($instance instanceof CacheManager) {
      return $instance;
    }

    $className = get_class($instance);

    // Fast path: use cached analysis if available
    if (isset(self::$cacheAnalysisCache[$className])) {
      $analysis = self::$cacheAnalysisCache[$className];
      if ($analysis['hasNoCache'] || $analysis['hasUnserializable'] || !$analysis['hasCacheableMethods']) {
        return $instance;
      }
    } else {
      // Lazy analysis: only analyze if we can't rule it out quickly
      if ($this->quickExclude($className)) {
        self::$cacheAnalysisCache[$className] = [
          'hasNoCache' => false,
          'hasUnserializable' => false,
          'hasCacheableMethods' => false,
        ];
        return $instance;
      }

      // Single pass analysis for remaining classes
      $analysis = self::$cacheAnalysisCache[$className] = $this->analyzeCacheability($instance);
      if ($analysis['hasNoCache'] || $analysis['hasUnserializable'] || !$analysis['hasCacheableMethods']) {
        return $instance;
      }
    }

    // Lazy load CacheManager only when needed
    if ($this->cacheManager === null) {
      $this->cacheManager = $this->get(CacheManager::class);
    }

    return ProxyGenerator::wrap(
      $instance,
      new CacheInterceptor($this->cacheManager),
    );
  }

  private function quickExclude(string $className): bool
  {
    foreach (self::QUICK_EXCLUDE_PATTERNS as $pattern) {
      if (str_contains($className, $pattern)) {
        return true;
      }
    }
    return false;
  }

  public function setParameter(string $key, mixed $value): void
  {
    $this->parameters[$key] = $value;
  }

  public function finishBootstrap(): void
  {
    $this->isBootstrapping = false;
  }

  public function getParameter(string $key): mixed
  {
    return $this->parameters[$key] ?? null;
  }

  public function has(string $id): bool
  {
    return isset($this->services[$id]) || isset($this->instances[$id]);
  }

  /**
   * Get all implementations of a given interface.
   * This method finds all services that implement the interface, including:
   * - Services directly bound to the interface (via #[Provides] or bind())
   * - Services that are classes implementing the interface
   * - Instances already created that implement the interface
   *
   * @param string $interface The interface class name
   * @return array Array of all implementations of the interface
   * @throws MissingServiceException
   * @throws ResolveParameterException
   * @throws \ReflectionException
   */
  private function buildInterfaceIndex(): void
  {
    $this->interfaceIndex = [];
    $interfacesToCheck = [];

    foreach ($this->services as $serviceId => $serviceConfig) {
      $className = $serviceConfig['class'] instanceof Closure
        ? null
        : (is_string($serviceConfig['class']) ? $serviceConfig['class'] : null);

      if (!$className && class_exists($serviceId)) {
        $className = $serviceId;
      }

      if ($className && class_exists($className)) {
        $interfacesToCheck[$serviceId] = $className;
      }
    }

    foreach ($interfacesToCheck as $serviceId => $className) {
      if (isset(self::$reflectionCache[$className])) {
        $reflection = self::$reflectionCache[$className];
      } else {
        $reflection = new ReflectionClass($className);
        self::$reflectionCache[$className] = $reflection;
      }

      foreach ($reflection->getInterfaces() as $reflectionInterface) {
        $interfaceName = $reflectionInterface->getName();
        if (!isset($this->interfaceIndex[$interfaceName])) {
          $this->interfaceIndex[$interfaceName] = [];
        }
        if (!in_array($serviceId, $this->interfaceIndex[$interfaceName], true)) {
          $this->interfaceIndex[$interfaceName][] = $serviceId;
        }
      }
    }

    $this->interfaceIndexDirty = false;
  }

  public function getAll(string $interface): array
  {
    if (!interface_exists($interface) && !class_exists($interface)) {
      return [];
    }

    if ($this->interfaceIndexDirty) {
      $this->buildInterfaceIndex();
    }

    if (isset($this->interfaceMapCompiled[$interface])) {
      $implementations = [];
      $checkedClasses = [];
      foreach ($this->interfaceMapCompiled[$interface] as $serviceId) {
        try {
          $instance = $this->get($serviceId);
          if ($instance instanceof $interface) {
            $instanceClass = get_class($instance);
            if (!isset($checkedClasses[$instanceClass])) {
              $implementations[] = $instance;
              $checkedClasses[$instanceClass] = true;
            }
          }
        } catch (\Throwable $e) {
        }
      }

      foreach ($this->instances as $instanceId => $instance) {
        if ($instance instanceof $interface) {
          $instanceClass = get_class($instance);
          if (!isset($checkedClasses[$instanceClass])) {
            $implementations[] = $instance;
            $checkedClasses[$instanceClass] = true;
          }
        }
      }
      return $implementations;
    }

    $implementations = [];
    $checkedClasses = [];
    $matchedServices = [];

    if (isset($this->services[$interface])) {
      try {
        $instance = $this->get($interface);
        if ($instance instanceof $interface) {
          $implementations[] = $instance;
          $checkedClasses[get_class($instance)] = true;
          $matchedServices[] = $interface;
        }
      } catch (\Throwable $e) {
      }
    }

    foreach ($this->services as $serviceId => $serviceConfig) {
      if ($serviceId === $interface) {
        continue;
      }

      $className = $serviceConfig['class'] instanceof Closure
        ? null
        : (is_string($serviceConfig['class']) ? $serviceConfig['class'] : null);

      if (!$className && class_exists($serviceId)) {
        $className = $serviceId;
      }

      if ($className && class_exists($className) && !isset($checkedClasses[$className])) {
        try {
          $reflectionClass = new ReflectionClass($className);
          if ($reflectionClass->implementsInterface($interface)) {
            $matchedServices[] = $serviceId;
            try {
              $instance = $this->get($serviceId);
              if ($instance instanceof $interface) {
                $instanceClass = get_class($instance);
                if (!isset($checkedClasses[$instanceClass])) {
                  $implementations[] = $instance;
                  $checkedClasses[$instanceClass] = true;
                }
              }
            } catch (\Throwable $e) {
            }
          }
        } catch (\ReflectionException $e) {
          continue;
        }
      }
    }

    foreach ($this->instances as $instanceId => $instance) {
      if ($instance instanceof $interface) {
        $instanceClass = get_class($instance);
        if (!isset($checkedClasses[$instanceClass])) {
          $implementations[] = $instance;
          $checkedClasses[$instanceClass] = true;
        }
      }
    }

    $this->interfaceMapCompiled[$interface] = array_unique($matchedServices);

    return $implementations;
  }

  /**
   * @throws \Exception
   */
  public function __wakeup()
  {
    throw new \Exception("Cannot unserialize a singleton.");
  }

  public function getServices(): array
  {
    return $this->services;
  }

  /**
   * Comprehensive cacheability analysis - combines all checks in one reflection pass
   */
  private function analyzeCacheability(object $instance): array
  {
    $className = get_class($instance);

    if (!isset(self::$reflectionCache[$className])) {
      self::$reflectionCache[$className] = new \ReflectionClass($instance);
    }
    $reflection = self::$reflectionCache[$className];

    // Check NoCache attribute
    $hasNoCache = !empty($reflection->getAttributes(NoCache::class));

    // Check for unserializable dependencies (string patterns first - fastest)
    $excludedPatterns = ['EventDispatcher', 'QueryBuilder', 'PDO', 'Database'];
    $hasUnserializable = false;
    foreach ($excludedPatterns as $pattern) {
      if (str_contains($className, $pattern)) {
        $hasUnserializable = true;
        break;
      }
    }

    // Only check properties if not already excluded
    if (!$hasUnserializable) {
      foreach ($reflection->getProperties() as $property) {
        if ($property->isInitialized($instance)) {
          $value = $property->getValue($instance);
          if (is_resource($value) || $value instanceof \PDO) {
            $hasUnserializable = true;
            break;
          }
        }
      }
    }

    // Check for cacheable methods
    $hasCacheableMethods = false;
    foreach ($reflection->getMethods() as $method) {
      if ($method->getAttributes(Cache::class)) {
        $hasCacheableMethods = true;
        break;
      }
    }

    return [
      'hasNoCache' => $hasNoCache,
      'hasUnserializable' => $hasUnserializable,
      'hasCacheableMethods' => $hasCacheableMethods,
    ];
  }

  private function __clone()
  {
  }
}

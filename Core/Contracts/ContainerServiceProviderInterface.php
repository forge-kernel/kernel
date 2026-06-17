<?php

declare(strict_types=1);

namespace Forge\Core\Contracts;

use Forge\Core\DI\Container;

/**
 * Interface for container service providers that can register services
 * early in the bootstrap process. Modules implementing this interface
 * will be called to register services before modules are fully loaded.
 */
interface ContainerServiceProviderInterface
{
  /**
   * Register services with the container.
   * Called early in the bootstrap process.
   *
   * @param Container $container The dependency injection container
   * @return void
   */
  public function registerServices(Container $container): void;
}

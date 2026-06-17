<?php

declare(strict_types=1);

namespace Forge\Core\Contracts;

use Forge\Core\DI\Container;

/**
 * Interface for bootstrap hooks that can hook into the bootstrap process.
 * Modules implementing this interface will be called during bootstrap
 * to perform early initialization.
 */
interface BootstrapHookInterface
{
  /**
   * Called during bootstrap to allow modules to perform early initialization.
   *
   * @param Container $container The dependency injection container
   * @return void
   */
  public function onBootstrap(Container $container): void;
}

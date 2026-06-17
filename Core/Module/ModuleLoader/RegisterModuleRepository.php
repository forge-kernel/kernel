<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\Module\Attributes\Repository;
use Forge\Traits\NamespaceHelper;
use ReflectionClass;

final class RegisterModuleRepository
{
    use NamespaceHelper;

    public function __construct(private readonly ReflectionClass $reflectionClass)
    {
    }

    public function init(): void
    {
        $this->initModuleRepository();
    }

    private function initModuleRepository(): void
    {
        $repositoryAttribute = $this->reflectionClass->getAttributes(Repository::class)[0] ?? null;
        if ($repositoryAttribute) {
            $repositoryInstance = $repositoryAttribute->newInstance();
            // TODO: for later use (for module management)
            //$this->moduleRepositories[$moduleAttributeInstance->name] = ['type' => $repositoryInstance->type, 'url' => $repositoryInstance->url];
        }
    }
}

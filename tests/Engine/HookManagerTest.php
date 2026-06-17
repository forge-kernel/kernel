<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\BeforeEach;
use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use Forge\Core\Bootstrap\ModuleSetup;
use Forge\Core\Module\HookManager;
use Forge\Core\Module\LifecycleHookName;

#[Group('core')]
final class HookManagerTest extends TestCase
{
    #[BeforeEach]
    public function setUp(): void
    {
        HookManager::debugResetHooks();
    }

    #[Test('addHook prevents duplicate callbacks')]
    public function add_hook_prevents_duplicates(): void
    {
        $callback = function () {
            return 'test';
        };

        HookManager::addHook(LifecycleHookName::APP_BOOTED, $callback);
        HookManager::addHook(LifecycleHookName::APP_BOOTED, $callback);

        $hooks = HookManager::debugGetHooks();
        $count = 0;
        foreach ($hooks[LifecycleHookName::APP_BOOTED->value] as $hook) {
            if ($hook === $callback) {
                $count++;
            }
        }

        $this->assertEquals(1, $count, 'Should only have one instance of the callback');
    }

    #[Test('addHook prevents duplicate array callbacks')]
    public function add_hook_prevents_duplicate_arrays(): void
    {
        $obj = new class {
            public function handle()
            {
            }
        };
        $callback = [$obj, 'handle'];

        HookManager::addHook(LifecycleHookName::APP_BOOTED, $callback);
        HookManager::addHook(LifecycleHookName::APP_BOOTED, $callback);

        $hooks = HookManager::debugGetHooks();
        $count = 0;
        foreach ($hooks[LifecycleHookName::APP_BOOTED->value] as $hook) {
            if (is_array($hook) && $hook[0] === $obj && $hook[1] === 'handle') {
                $count++;
            }
        }

        $this->assertEquals(1, $count, 'Should only have one instance of the array callback');
    }

    #[Test('compileHooks avoids duplicates in the compiled file')]
    public function compile_hooks_avoids_duplicates(): void
    {
        $obj = new class {
            public function handle()
            {}
        };
        $callback = [$obj, 'handle'];

        $reflection = new \ReflectionClass(HookManager::class);
        $hooksProp = $reflection->getProperty('hooks');
        $hooksProp->setAccessible(true);
        $hooksProp->setValue(null, [
            LifecycleHookName::APP_BOOTED->value => [
                $callback,
                $callback
            ]
        ]);

        ModuleSetup::compileHooks();

        $compiledFile = BASE_PATH . '/storage/framework/cache/compiled_hooks.php';
        $this->assertTrue(file_exists($compiledFile));

        $compiled = include $compiledFile;
        $this->assertCount(1, $compiled[LifecycleHookName::APP_BOOTED->value]);
    }
}

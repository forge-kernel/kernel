<?php

declare(strict_types=1);

namespace Forge\tests;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use Forge\Core\DI\Container;
use ReflectionClass;
use App\Modules\ForgeRouter\Http\Request;
use App\Modules\ForgeRouter\Routing\Route;
use App\Modules\ForgeRouter\Routing\Router;
use App\Modules\ForgeRouter\Http\Attributes\ApiRoute;

#[Group('routing')]
final class RouterTest extends TestCase
{
    private function getRouter(): Router
    {
        $container = Container::getInstance();
        if (!$container) {
            $container = new Container();
            // Use reflection to set the instance if setInstance is not a static method
            $containerReflection = new ReflectionClass(Container::class);
            if ($containerReflection->hasProperty('instance')) {
                $prop = $containerReflection->getProperty('instance');
                $prop->setAccessible(true);
                $prop->setValue(null, $container);
            }
        }
        $container->bind(MockController::class, MockController::class);

        $reflection = new ReflectionClass(Router::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $router = Router::init($container, []);
        $router->registerControllers(MockController::class);

        return $router;
    }

    #[Test('Router resolves static route correctly')]
    public function resolves_static_route(): void
    {
        $router = $this->getRouter();
        $routes = $router->getRoutes();
        $this->assertTrue(isset($routes['GET#^/test-static/?$#']));
        $this->assertEquals(MockController::class, $routes['GET#^/test-static/?$#']['controller']);
    }

    #[Test('Router resolves dynamic route correctly')]
    public function resolves_dynamic_route(): void
    {
        $router = $this->getRouter();
        $routes = $router->getRoutes();

        $routeKey = 'GET#^/test-dynamic/([a-zA-Z0-9_-]+)/?$#';
        $this->assertTrue(isset($routes[$routeKey]));
        $this->assertEquals(['id'], $routes[$routeKey]['params']);
    }

    #[Test('Router resolves API route correctly')]
    public function resolves_api_route(): void
    {
        $router = $this->getRouter();
        $routes = $router->getRoutes();
        $this->assertTrue(isset($routes['POST#^/api/v1/api/submit/?$#']));
    }
}

class MockController
{
    #[Route('/test-static', 'GET')]
    public function staticRoute(): string
    {
        return 'static';
    }

    #[Route('/test-dynamic/{id}', 'GET')]
    public function dynamicRoute(int $id): string
    {
        return 'dynamic:' . $id;
    }

    #[ApiRoute('/api/submit', 'POST')]
    public function submitRoute(): string
    {
        return 'submit';
    }
}

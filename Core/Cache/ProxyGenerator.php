<?php
declare(strict_types=1);

namespace Forge\Core\Cache;

use Forge\Core\Cache\Attributes\Cache;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class ProxyGenerator
{
    /** @var array<string, class-string> */
    private static array $proxyClassNames = [];

    public static function wrap(
        object $instance,
        CacheInterceptor $interceptor,
    ): object {
        if ($instance instanceof ProxyMarkerInterface) {
            throw new \LogicException("Cannot wrap a proxy twice");
        }

        $className = $instance::class;

        if (
            $instance instanceof ProxyMarkerInterface ||
            str_starts_with($className, "Forge\\Cache\\Proxy\\")
        ) {
            return $instance;
        }

        if (isset(self::$proxyClassNames[$className])) {
            return self::instantiateProxy(
                self::$proxyClassNames[$className],
                $instance,
                $interceptor,
            );
        }

        $refClass = new ReflectionClass($instance);

        if ($refClass->isFinal()) {
            return $instance;
        }

        $cacheableMethods = [];
        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if (
                !$m->isStatic() &&
                !$m->isConstructor() &&
                !$m->isDestructor() &&
                $m->getAttributes(Cache::class)
            ) {
                $cacheableMethods[] = $m;
            }
        }

        if (empty($cacheableMethods)) {
            return $instance;
        }

        $proxyShort =
            str_replace("\\", "_", $className) .
            "_Proxy_" .
            substr(hash("xxh128", $className), 0, 8);
        $proxyFqn = "Forge\\Cache\\Proxy\\{$proxyShort}";
        $file =
            sys_get_temp_dir() .
            "/forge_cache_proxy_" .
            substr(md5($proxyFqn . __FILE__ . filemtime(__FILE__)), 0, 16) .
            ".php";

        if (!is_file($file)) {
            $source = self::generateSource(
                $refClass,
                $proxyFqn,
                $cacheableMethods,
            );
            $tmp = "{$file}." . getmypid() . ".tmp";
            file_put_contents($tmp, $source, LOCK_EX);
            rename($tmp, $file);
        }

        require_once $file;
        self::$proxyClassNames[$className] = $proxyFqn;

        return self::instantiateProxy($proxyFqn, $instance, $interceptor);
    }

    private static function generateSource(
        ReflectionClass $refClass,
        string $proxyFqn,
        array $cacheableMethods,
    ): string {
        $extends = $refClass->getName();
        $ns = "Forge\\Cache\\Proxy";
        $classShort = substr(strrchr($proxyFqn, "\\"), 1);

        $methods = "";
        foreach ($cacheableMethods as $m) {
            $methods .= self::generateMethod($m);
        }

        return <<<PHP
        <?php
        declare(strict_types=1);

        namespace $ns;

        use Forge\\Core\\Cache\\ProxyMarkerInterface;

        class $classShort extends \\$extends implements ProxyMarkerInterface
        {
            private \\Forge\\Core\\Cache\\CacheInterceptor \$__interceptor;
            private \\$extends \$__real;

            public function __construct(
                \\Forge\\Core\\Cache\\CacheInterceptor \$interceptor,
                \\$extends \$real
            ) {
                \$this->__interceptor = \$interceptor;
                \$this->__real = \$real;
            }

        {$methods}
        }
        PHP;
    }

    private static function generateMethod(ReflectionMethod $m): string
    {
        $name = $m->getName();
        $params = [];
        $args = [];

        foreach ($m->getParameters() as $p) {
            $typeDecl = self::paramType($p);
            $variadic = $p->isVariadic() ? "..." : "";
            $ref = $p->isPassedByReference() ? "&" : "";
            $default = "";
            if ($p->isDefaultValueAvailable()) {
                $defaultVal = var_export($p->getDefaultValue(), true);
                $default = " = {$defaultVal}";
            }
            $params[] = trim(
                "{$typeDecl} {$ref}{$variadic}\${$p->getName()}{$default}",
            );
            $args[] = $variadic . '$' . $p->getName();
        }

        $params = implode(", ", $params);
        $args = implode(", ", $args);
        $returnDecl = self::returnType($m->getReturnType());

        return "
    public function {$name}({$params}){$returnDecl}
    {
        return \$this->__interceptor->call(\$this->__real, '{$name}', [{$args}]);
    }";
    }

    private static function paramType(\ReflectionParameter $p): string
    {
        $t = $p->getType();
        if (!$t) {
            return "";
        }

        $nullable = $t->allowsNull() ? "?" : "";
        if ($t instanceof ReflectionNamedType) {
            $typeName = $t->getName();
            if ($typeName === "mixed") {
                return "";
            }
            return "{$nullable}" .
                ($t->isBuiltin() ? $typeName : "\\" . $typeName);
        }

        return "";
    }

    private static function returnType(?\ReflectionType $t): string
    {
        if (!$t) {
            return "";
        }
        $nullable = $t->allowsNull() ? "?" : "";
        if ($t instanceof ReflectionNamedType) {
            $typeName = $t->getName();
            if ($typeName === "mixed") {
                return "";
            }
            return ": {$nullable}" .
                ($t->isBuiltin() ? $typeName : "\\" . $typeName);
        }
        return "";
    }

    private static function instantiateProxy(
        string $proxyFqn,
        object $real,
        CacheInterceptor $interceptor,
    ): object {
        return new $proxyFqn($interceptor, $real);
    }
}

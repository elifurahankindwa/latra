<?php
// Composer is optional for existing deployments; runtime dependencies remain empty.
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) require_once $composerAutoload;

spl_autoload_register(static function (string $class): void {
    $applicationPrefix = 'Latra\\';
    if (str_starts_with($class, $applicationPrefix)) {
        $relativePath = str_replace('\\', '/', substr($class, strlen($applicationPrefix)));
        $file = dirname(__DIR__) . '/src/' . $relativePath . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }

    if (str_contains($class, '\\')) {
        return;
    }

    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

<?php declare(strict_types=1);

/**
 * Enough autoloading to run the tests, wherever they are being run from.
 *
 * There are two places somebody sensibly runs these: in this folder on its own,
 * after a composer install, and inside a Shopware installation where the
 * plugin sits in custom/plugins and the shop's own vendor folder already has
 * PHPUnit in it. Both are tried, and the classes under test are autoloaded
 * from src either way, because neither arrangement can be relied on to know
 * about this plugin's namespace.
 */

$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;
        break;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Upeo\\Njiwa\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, \strlen($prefix));

    // Tests live in tests/, everything else in src/.
    $file = str_starts_with($relative, 'Tests\\')
        ? __DIR__ . '/' . str_replace('\\', '/', substr($relative, \strlen('Tests\\'))) . '.php'
        : __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

<?php
declare(strict_types=1);

// Run only on the staged Linux deployment tree, never on the active app.
if (count($argv) !== 2 || trim($argv[1]) === '') {
    throw new RuntimeException('An explicit staging directory is required.');
}
$root = realpath($argv[1]);
if ($root === realpath(dirname(__DIR__)) || $root === '/home/site/wwwroot') {
    throw new RuntimeException('Refusing to modify a source checkout or active application.');
}
if ($root === false || !is_file($root.'/artisan') || !is_dir($root.'/vendor')) {
    throw new RuntimeException('A staged Laravel application is required.');
}
if (is_dir($root.'/vendor/laravel/pint')) {
    throw new RuntimeException('Development-only Pint must not be deployed.');
}
$installed = json_decode(file_get_contents($root.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
if (($installed['dev'] ?? null) !== false) {
    throw new RuntimeException('Deployment requires a production-only Composer installation.');
}

// Symfony uses this helper only on Windows. It has no role on Azure Linux.
// Check exact reviewed bytes before excluding it; an unexpected change fails.
$windowsHelper = $root.'/vendor/symfony/console/Resources/bin/hiddeninput.exe';
if (!is_file($windowsHelper) || is_link($windowsHelper)
    || hash_file('sha256', $windowsHelper) !== '8fdff52a7430dba14fb97239c7fe414710991f16da269374e0936a1385f3a318') {
    throw new RuntimeException('Unexpected Symfony Windows helper; review required.');
}
if (!unlink($windowsHelper)) {
    throw new RuntimeException('Could not exclude Windows-only helper.');
}

// No native or PHAR binaries are needed by the approved production graph.
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/vendor', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isLink()) {
        throw new RuntimeException('Unexpected symlink in deployment vendor tree.');
    }
    if (!$file->isFile()) {
        continue;
    }
    if (preg_match('/\.(?:exe|dll|so|dylib|phar)$/i', $file->getFilename())) {
        throw new RuntimeException('Unexpected executable in deployment vendor tree.');
    }
    $handle = fopen($file->getPathname(), 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unreadable deployment file.');
    }
    $prefix = fread($handle, 4);
    fclose($handle);
    if ($prefix === false || str_starts_with($prefix, 'MZ') || str_starts_with($prefix, "\x7fELF")
        || in_array($prefix, ["\xcf\xfa\xed\xfe", "\xce\xfa\xed\xfe", "\xfe\xed\xfa\xcf", "\xfe\xed\xfa\xce"], true)) {
        throw new RuntimeException('Unexpected binary content in deployment vendor tree.');
    }
}
echo "Linux deployment artifact: production-only dependencies, no Pint or native helper binaries.\n";

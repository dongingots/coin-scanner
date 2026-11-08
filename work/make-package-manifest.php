<?php
$installed = json_decode(file_get_contents('vendor/composer/installed.json'), true);
$packages = $installed['packages'] ?? $installed;
$manifest = [];
$ignored = json_decode(file_get_contents('composer.json'), true)['extra']['laravel']['dont-discover'] ?? [];
foreach ($packages as $package) {
    $name = $package['name'];
    $config = $package['extra']['laravel'] ?? [];
    if ($config && ! in_array('*', $ignored, true) && ! in_array($name, $ignored, true)) {
        $manifest[str_replace('vendor/', '', $name)] = $config;
    }
}
file_put_contents('bootstrap/cache/packages.php', '<?php return '.var_export($manifest, true).';');

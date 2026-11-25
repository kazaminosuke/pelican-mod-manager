<?php

require_once dirname(__DIR__).'/bootstrap.php';

$panelAutoload = dirname(__DIR__, 4).'/vendor/autoload.php';

if (!is_file($panelAutoload)) {
    throw new RuntimeException('Pelican Panel vendor/autoload.php was not found. Run E2E tests from a plugin copied into a Panel checkout.');
}

$loader = require $panelAutoload;
$loader->addPsr4('Kazaminosuke\\ModManager\\Tests\\E2E\\', __DIR__.'/');

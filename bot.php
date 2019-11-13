<?php

/*
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Shelyn;

use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Huntress\Huntress;
use Huntress\SentryTransport;
use React\EventLoop\Factory;
use Sentry\ClientBuilder;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Throwable;

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/config.php";

// set up our event loop
$loop = Factory::create();
URLHelpers::setLoop($loop);

// grab out git ID and thow it in a const
exec("git diff --quiet HEAD", $null, $rv);
define('VERSION', trim(`git rev-parse HEAD`) . ($rv == 1 ? "-modified" : ""));

// initialize Sentry
$builder = ClientBuilder::create(array_merge($config['sentry'], [
    'release' => 'shelyn@' . VERSION,
]));

$transport = new SentryTransport($builder->getOptions(), $loop);

$client = $builder->setTransport($transport)->getClient();
Hub::setCurrent((new Hub($client)));

set_exception_handler(function (Throwable $e) {
    $scope = new Scope();
    $scope->setExtra('fatal', true);
    Hub::getCurrent()->getClient()->captureException($e, $scope);
});

if (PHP_SAPI != "cli") {
    die("Only run from the command-line.");
}

if (!is_writable("temp")) {
    if (!mkdir("temp", 0770)) {
        die("Huntress must be able to write to the 'temp' directory. Please make this dir, give permissions, and try again");
    }
}

foreach (glob(__DIR__ . "/src/Shelyn/Plugin/*.php") as $file) {
    require_once($file);
}
$vanilla_plugins = [
    'Evaluate',
    'Management',
    'Localization',
];
foreach ($vanilla_plugins as $file) {
    require_once("vendor/sylae/huntress/src/Huntress/Plugin/" . $file . ".php");
}

$huntress_inhibit_auto_restart = false;
register_shutdown_function(function () {
    global $huntress_inhibit_auto_restart;
    if ($huntress_inhibit_auto_restart) {
        die(0);
    } else {
        die(1);
    }
});

$bot = new Huntress($config, $loop);
$bot->log->info('Connecting to discord...');
$bot->start();

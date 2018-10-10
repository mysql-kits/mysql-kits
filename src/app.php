<?php
require __DIR__ . '/../vendor/autoload.php';
$Application = new \Symfony\Component\Console\Application();
$Application->add(new \App\Command\Mysql\Config());
$Application->add(new \App\Command\Mysql\Replace());
$Application->add(new \App\Command\Redis\Config());
$Application->add(new \App\Command\Mysql\Dump());
$Application->add(new \App\Command\Mysql\Variables());
$Application->run();
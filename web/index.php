<?php

require_once dirname(__DIR__) . "/vendor/autoload.php";

$appType = "api";
$app     = require "../bootstrap/bootstrap.php";
$app->run();

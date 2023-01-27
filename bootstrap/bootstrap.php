<?php

require dirname(__DIR__) . "/config/constants.php";

date_default_timezone_set("Europe/London");

if (class_exists('Dotenv\Dotenv') && file_exists(BASE_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->load();
    if (!empty(getenv("BASE_FOLDER"))) {
        $_ENV["BASE_FOLDER"] = getenv("BASE_FOLDER");
    }
}

require BASE_PATH . "/src/Nova.php";
return new nova\Nova($appType, $argv ?? []);

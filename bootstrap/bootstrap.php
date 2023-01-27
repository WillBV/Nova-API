<?php

require dirname(__DIR__) . "/config/constants.php";

date_default_timezone_set("Europe/London");

require NOVA_VENDOR_PATH . "/src/Nova.php";
return new nova\Nova($appType, $argv ?? []);

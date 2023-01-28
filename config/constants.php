<?php

define("NOVA_LIBRARY_PATH", dirname(__DIR__));
if (!defined("NOVA_BASE_PATH")) {
    define("NOVA_BASE_PATH", strstr($dir, "/vendor", true));
}
if (!defined("NOVA_VENDOR_PATH")) {
    define("NOVA_VENDOR_PATH", NOVA_BASE_PATH . "/vendor");
}
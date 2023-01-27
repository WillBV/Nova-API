<?php

namespace nova\utilities\commands;

/**
 * System
 *
 * System commands for setup & maintenance.
 */
class SystemCommand
{
    public $requiresDb = TRUE;

    /**
     * Installs API.
     *
     * @param string $logResponse The response to be logged.
     *
     * @return void
     */
    public static function installCommand(string &$logResponse): void {
        $scriptStart = microtime(TRUE);
        $command     = "composer install";
        $begin       = shell_exec($command);
        $logResponse = $begin;
        echo $begin;
        $end          = "\nTime: " . round(microtime(TRUE) - $scriptStart, 2) . " secs\n";
        $logResponse .= $end;
        echo $end;
    }
}

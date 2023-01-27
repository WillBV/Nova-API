<?php

namespace nova\utilities\commands;

/**
 * Linter
 *
 * Linter commands to validate & fix the code syntax.
 */
class LintCommand
{
    public $requiresDb = FALSE;

    /**
     * Generates a report for the PHP linter errors.
     *
     * @param string $logResponse The response to be logged.
     *
     * @return void
     */
    public function reportCommand(string &$logResponse): void {
        /*
            PHP Code Sniffer Command
            Ruleset file location
            Path to code directory
            Files/Folders to ignore
        */
        $command     = BASE_PATH . "/vendor/bin/phpcs" .
            " --standard=" . BASE_PATH . "/novaLint.xml " .
            BASE_PATH .
            " --ignore=" . BASE_PATH . "/vendor/* --cache";
        $logResponse = shell_exec($command);
        echo $logResponse;
    }
    
    /**
     * Runs the PHP linter fixes.
     *
     * @param string $logResponse The response to be logged.
     *
     * This will only fix certain errors as marked in the linter report.
     *
     * @return void
     */
    public function fixCommand(string &$logResponse): void {
        /*
            PHP Code Beautifier Command
            Ruleset file location
            Path to code directory
            Files/Folders to ignore
        */
        $command     = BASE_PATH . "/vendor/bin/phpcbf" .
            " --standard=" . BASE_PATH . "/novaLint.xml " .
            BASE_PATH .
            " --ignore=" . BASE_PATH . "/vendor/*";
        $logResponse = shell_exec($command);
        echo $logResponse;
    }
}

<?php

namespace nova;

use nova\utilities\Utilities;
use nova\utilities\cache\Cache;
use nova\utilities\database\DBConnection;
use nova\utilities\security\Security;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use nova\services\SystemService;
use Psr\Log\LogLevel;

class Nova
{
    public static $cache;
    public static $db;
    public static $logger;
    public static $router;
    public static $security;
    private static $argv;
    private static $appType;
    private static $debugMode;
    private static $devMode;
    private static $idempotentRequest = FALSE;

    /**
     * Construct the system application class.
     *
     * @param string $appType The source type of the current application (api|console).
     * @param array  $argv    The array of script arguments used in the console application.
     */
    // @codingStandardsIgnoreStart - Ignored due to complex API construction.
    public function __construct(string $appType, array $argv = []) {
        try {
            self::$argv      = $argv;
            self::$appType   = $appType;
            self::$debugMode = strtoupper($_ENV["DEBUG"] ?? "") === "TRUE";
            self::$devMode   = strtoupper($_ENV["ENV"] ?? "") === "DEVELOPMENT";
            if (self::$logger == NULL) {
                $loggerName   = $appType === "api" ? "API" : "Console";
                self::$logger = new Logger($loggerName);
            }
            if (is_null(self::$db)) {
                $dbDetailsSet = isset(
                    $_ENV["DB_HOST"],
                    $_ENV["DB_DATABASE"],
                    $_ENV["DB_USERNAME"],
                    $_ENV["DB_PASSWORD"]
                );
                if (!$dbDetailsSet && $appType === "api") {
                    $exception         = new \Exception("Internal Server Error", 500);
                    $exception->detail = "Could not connect to the database";
                    throw $exception;
                } elseif ($dbDetailsSet) {
                    try {
                        self::$db = DBConnection::connect(
                            $_ENV["DB_HOST"],
                            $_ENV["DB_DATABASE"],
                            $_ENV["DB_USERNAME"],
                            $_ENV["DB_PASSWORD"]
                        );
                        self::$db->beginTransaction();
                    } catch (\Exception $exception) {
                        if ($appType === "api") {
                            throw $exception;
                        }
                    }
                }
            }
            if (self::$router == NULL) {
                self::$router = new Router($appType);
            }
            if (self::$security == NULL) {
                self::$security = new Security($appType);
            }
            if (self::$cache == NULL) {
                self::$cache = new Cache($_ENV["CACHE_DRIVER"] ?? "apcu");
            }
        } catch (\Exception $exception) {
            $parsed = Utilities::parseException($exception);
            self::log("---REQUEST--- " . json_encode([]) . " ---ERRORS--- " . json_encode($parsed), "error");
            $response = [
                "data"   => $parsed,
                "header" => $parsed["code"] . " " . $parsed["message"]
            ];
            $this->sendResponse($response);
            exit;
        }
    }
    // @codingStandardsIgnoreEnd
    
    /**
     * Runs the application.
     *
     * @return integer The exit status (0 means normal, non-zero values mean abnormal)
     */
    public function run(): int {
        $responseCode = 1;
        if (self::$appType === "api") {
            $responseCode = $this->runApi();
        } elseif (self::$appType === "console") {
            $responseCode = $this->runConsole();
        }
        return $responseCode;
    }

    /**
     * Runs the API version of the application.
     *
     * @return integer
     */
    private function runApi(): int {
        try {
            $logLevel       = "info";
            $novaService    = new SystemService();
            $request        = Utilities::parseRequest();
            $log            = "---REQUEST--- " . json_encode($request);
            $idempotencyKey = $request["headers"]["idempotency-key"] ?? "";
            $match          = self::$router->matchRoute();
            if ($request["requestMethod"] == "OPTIONS") {
                if ($match) {
                    $responseHeader = "200 Success";
                } elseif (self::$router->allowedMethods) {
                    $responseHeader = "405 Method Not Allowed";
                } else {
                    $responseHeader = "404 Resource not found";
                }
                $response = [
                    "data"   => "",
                    "header" => $responseHeader
                ];
            } elseif ($match && is_callable($match["target"])) {
                if (!self::$security->isAuthed()) {
                    $exception         = new \Exception("Unauthorised", 401);
                    $exception->detail = self::$security->securityMessage;
                    throw $exception;
                }
                $idempotencyModel = $novaService->getIdempotentResponse($idempotencyKey);
                if ($idempotencyModel->format() && $request["requestUri"] == $idempotencyModel->route) {
                    $response = [
                        "data"   => $idempotencyModel->body,
                        "header" => $idempotencyModel->statusCode
                    ];
                } else {
                    $response = call_user_func_array($match["target"], $match["params"]);
                }
            } else {
                $message = "Resource not found";
                $code    = 404;
                if (self::$router->allowedMethods) {
                    $message = "Method Not Allowed";
                    $code    = 405;
                }
                $exception         = new \Exception($message, $code);
                $exception->detail = ($message);
                throw $exception;
            }
            $this->endTransaction(TRUE);
            $log .= " ---RESPONSE--- " . json_encode($response);
        } catch (\Exception $exception) {
            $logLevel = "error";
            $this->endTransaction();
            $parsed   = Utilities::parseException($exception);
            $log     .= " ---ERRORS--- " . json_encode($parsed);
            $response = [
                "data"   => $parsed,
                "header" => $parsed["code"] . " " . $parsed["message"]
            ];
        }
        if (self::$idempotentRequest) {
            $novaService->setIdempotentResponse(
                $idempotencyKey,
                $request["requestUri"],
                $response["idempotency"]["status"] ?? $response["header"],
                $response["data"]
            );
        }
        $this->sendResponse($response);
        self::log($log, $logLevel);
        return 0;
    }

    /**
     * Runs the console version of the application.
     *
     * @return integer
     */
    private function runConsole(): int {
        try {
            $log               = "---COMMAND--- '" . implode(" ", self::$argv) . "'";
            $returnCode        = 0;
            $commandRequiresDb = [];
            $helperText        = "\033[1mAvailable Commands:\033[0m\n";
            $sections          = glob("src/utilities/commands/*Command.php");
            foreach ($sections as $section) {
                $className      = substr($section, strrpos($section, "/") + 1, -4);
                $class          = "nova\utilities\commands\\{$className}";
                $rc             = new \ReflectionClass(new $class());
                $classComments  = Utilities::parseDocComment($rc->getDocComment());
                $commandSection = strtolower(substr($rc->getShortName(), 0, -7));
                $sectionName    = trim($classComments["shortDescription"]);
                $properties     = $rc->getDefaultProperties();
                $requiresDb     = $properties["requiresDb"] ?? TRUE;
                $helperText    .= "- \e[33m{$sectionName}\e[39m\n\033[1m" .
                    $classComments["longDescription"] . "\033[0m";
                $commands       = $rc->getMethods();
                foreach ($commands as $command) {
                    if (substr($command->getName(), -7) !== "Command") {
                        continue;
                    }
                    $functionComments                = Utilities::parseDocComment($command->getDocComment(), 2);
                    $commandName                     = $commandSection . "/" . substr($command->getName(), 0, -7);
                    $commandName                     = strtolower(preg_replace('/(?<!^)[A-Z]/', "-$0", $commandName));
                    $commandFunctions[$commandName]  = $command;
                    $commandRequiresDb[$commandName] = $requiresDb;
                    $helperText                     .= "\t\e[32m{$commandName}\e[39m\n\033[1m" .
                        $functionComments["shortDescription"] . "\033[0m\n" .
                        $functionComments["longDescription"];
                }
            }
            $logResponse = "";
            if (!isset(self::$argv[1]) || self::$argv[1] === "help") {
                $logResponse = $helperText;
                echo $helperText;
            } elseif (isset($commandFunctions[self::$argv[1]])) {
                if ($commandRequiresDb[self::$argv[1]] === TRUE && self::$db === NULL) {
                    $exception         = new \Exception("Unable to connect to the database.", 500);
                    $exception->detail = "There was an issue connecting to the database";
                    throw $exception;
                }
                $class = $commandFunctions[self::$argv[1]]->getDeclaringClass()->getName();
                $commandFunctions[self::$argv[1]]->invokeArgs(new $class(), [&$logResponse]);
                $this->endTransaction(TRUE);
                $logResponse = preg_replace("^\\e\[\d+m^", "", $logResponse);
            } else {
                $returnCode  = 1;
                $logResponse = "Command not found.";
                echo "{$logResponse}\n";
            }
            $log .= " ---RESPONSE--- {$logResponse}";
            self::log($log, "info");
        } catch (\Exception $exception) {
            $this->endTransaction();
            $parsed = Utilities::parseException($exception);
            echo $parsed["message"] . "\n";
            $returnCode = is_int($parsed["code"]) ? $parsed["code"] : 1;
            $log       .= "' ---ERRORS--- " . json_encode($parsed);
            self::log($log, "error");
        }
        return $returnCode;
    }

    /**
     * Sets the request as an idempotent request.
     *
     * @return void
     */
    public static function isIdempotentRequest(): void {
        self::$idempotentRequest = TRUE;
    }

    /**
     * Returns whether the system is in development mode.
     *
     * @return boolean
     */
    public static function isDev(): bool {
        return self::$devMode;
    }

    /**
     * Returns whether the system is in debug mode.
     *
     * @return boolean
     */
    public static function isDebug(): bool {
        return self::$debugMode;
    }

    /**
     * Log a message to the API logs.
     *
     * @param string $message  The message to log.
     * @param string $logLevel The level at which to log the message following the RFC 5424 levels.
     *
     * @return void
     */
    public static function log(string $message, string $logLevel = "info"): void {
        self::obfuscateData($message);
        $logFolder = "";
        $logBlock  = FALSE;
        if (in_array($logLevel, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR])) {
            $logFolder = "error";
        } elseif (in_array($logLevel, [LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO])) {
            $logFolder = "info";
        } elseif ($logLevel === LogLevel::DEBUG) {
            $logFolder = "debug";
        }
        try {
            $request = Utilities::parseRequest();
            if (isset($request["headers"]["user-agent"])) {
                $blockedAgents = [
                    "^LB-HealthChecker*^",
                    "^UptimeRobot^"
                ];
                foreach ($blockedAgents as $search) {
                    if (preg_match($search, $request["headers"]["user-agent"])) {
                        $logBlock = TRUE;
                        break;
                    }
                }
            }
        } catch (\Exception $exception) {
        }
        if (!$logBlock && ($logFolder === "error" || (in_array($logFolder, ["info", "debug"]) && self::isDebug()))) {
            $fileHandler = new StreamHandler(
                BASE_PATH . "/logs/{$logFolder}/{$logLevel}.log",
                Logger::DEBUG,
                TRUE,
                0666
            );
            self::$logger->setHandlers([$fileHandler]);
            self::$logger->log($logLevel, $message);
        }
    }

    /**
     * Obfuscate any sensitive data before logging.
     *
     * @param string $message The message to obfuscate.
     *
     * @return void
     */
    private static function obfuscateData(string &$message): void {
        $message = preg_replace('/("authentication":"Basic\s)(.*?)(")/', "$1**********$3", $message);
    }

    /**
     * Get the type of application the system is currently running in.
     *
     * @return string
     */
    public static function appType(): string {
        return self::$appType;
    }

    /**
     * Sends the response to the client.
     *
     * @param array $response The response to send.
     *
     * @return void
     */
    private function sendResponse(array $response): void {
        $output = "";
        if (strlen($_ENV["LOCAL_HOST"] ?? "")) {
            header("Access-Control-Allow-Headers: *");
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Methods: *");
        }
        header("Content-Type: application/json");
        if ($response["data"] !== NULL) {
            $output = json_encode($response["data"]);
        }
        if ($this->appType() === "api") {
            header($_SERVER["SERVER_PROTOCOL"] . " {$response['header']}");
        } elseif ($output) {
                $output .= "\n";
        }
        if ($output) {
            echo $output;
        }
    }

    /**
     * Handles the database transaction at the end of the request.
     *
     * @param boolean $commit Flag to commit or rollback transaction.
     *
     * @return void
     */
    private function endTransaction(bool $commit = FALSE): void {
        if (self::$db != NULL) {
            if ($commit) {
                self::$db->commit();
            } else {
                self::$db->rollback();
            }
        }
    }
}

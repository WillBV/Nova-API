<?php

namespace nova\utilities;

use nova\Nova;

final class Utilities
{
    public const DATE_TIME_REGEX = (
        "/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1]) ([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])$/"
    );

    /**
     * Utility function to parse the incoming request data into a standard format.
     *
     * @param array $requiredData An optional parameter to validate any expected data.
     *
     * @return array
     */
    public static function parseRequest(array $requiredData = []): array {
        $headers     = self::arrayKeyCase(getallheaders());
        $method      = $_SERVER["REQUEST_METHOD"] ?? "";
        $requestUri  = strtok($_SERVER["REQUEST_URI"] ?? "", "?");
        $queryString = $_GET;
        $requestBody = [];
        if (!empty($_POST)) {
            $requestBody = $_POST;
        } else {
            $postRaw = (file_get_contents("php://input"));
            if ($postRaw) {
                $post  = json_decode($postRaw, TRUE);
                $error = json_last_error();
                if ($error == JSON_ERROR_NONE) {
                    $requestBody = $post;
                } else {
                    $detail            = "There was an unexpected error processing your request";
                    $errorMessages     = [
                        JSON_ERROR_DEPTH          => " - Maximum stack depth exceeded",
                        JSON_ERROR_STATE_MISMATCH => " - Underflow or the modes mismatch",
                        JSON_ERROR_CTRL_CHAR      => " - Unexpected control character found",
                        JSON_ERROR_SYNTAX         => " - Syntax error, malformed JSON",
                        JSON_ERROR_UTF8           => " - Malformed UTF-8 characters, possibly incorrectly encoded"
                    ];
                    $detail           .= $errorMessages[$error] ?: "";
                    $exception         = new \Exception("Bad request", 400);
                    $exception->detail = $detail;
                    throw $exception;
                }
            }
        }
        array_walk_recursive($requestBody, function (&$val) {
            if (is_string($val)) {
                $val = trim($val);
            }
        });
        $responseData = [
            "requestMethod" => $method,
            "requestUri"    => $requestUri,
            "uriParams"     => !is_null(Nova::$router) ? Nova::$router->matchRoute()["params"] ?? "" : [],
            "routeName"     => !is_null(Nova::$router) ? Nova::$router->matchRoute()["name"] ?? "" : "",
            "headers"       => $headers,
            "body"          => $requestBody,
            "queryString"   => $queryString
        ];
        $errors       = [];
        foreach ($requiredData as $dataType => $format) {
            if ($dataType == "headers") {
                $format = self::arrayKeyCase($format);
            }
            $errors = array_merge($errors, self::dataValidator($responseData[$dataType], $format));
        }
        if ($errors) {
            $exception         = new \Exception("Bad request", 400);
            $exception->detail = ($errors);
            throw $exception;
        }
        return $responseData;
    }

    /**
     * Utility function to parse API response exceptions.
     *
     * @param object $exception The exception object to parse.
     *
     * @return array
     */
    // @codingStandardsIgnoreStart - due to switch statements breaking the cyclomatic complexity
    public static function parseException(object $exception): array {
        $eClass = get_class($exception);
        $eType  = strtok($eClass, "\\");
        if ($eType === "GuzzleHttp" && strpos($eClass, "ConnectException") !== FALSE) {
            $eType = "Exception";
        }
        switch ($eType) {
            case "Aws":
            case "PDOException":
            case "RuntimeException":
            case "InvalidArgumentException":
            case "UnexpectedValueException":
            case "Exception":
                $statusCode = $exception->getCode();
                $message    = $exception->getMessage();
                $detail     = "";
                if (isset($exception->detail)) {
                    $detail = $exception->detail;
                }
                break;
            case "GuzzleHttp":
                $response   = $exception->getResponse();
                $statusCode = $response->getStatusCode();
                $message    = $response->getReasonPhrase();
                $messageObj = json_decode((string)$response->getBody());
                $detail     = "";
                // @codingStandardsIgnoreStart - Ignored due to external object not using camelCase
                if (isset($messageObj->Errors[0]->Detail)) {
                    $detail = $messageObj->Errors[0]->Detail;
                } elseif (isset($messageObj->errors[0]->detail)) {
                    $detail = $messageObj->errors[0]->detail;
                }
                // @codingStandardsIgnoreEnd
                break;
            default:
                $loc        = __METHOD__;
                $statusCode = "000";
                $message    = "Exception for type '{$eType}' could not be parsed.";
                $detail     = "Undefined exception type passed to '{$loc}'";
                break;
        }
        $exceptionResponse = [
            "code"    => $statusCode,
            "message" => $message,
            "detail"  => $detail
        ];
        
        return $exceptionResponse;
    }
    // @codingStandardsIgnoreEnd

    /**
     * Utility function to parse class & function document comments.
     *
     * @param string  $comment  The raw comment to parse.
     * @param integer $tabDepth The depth of tab characters for response formatting.
     *
     * @return array
     */
    public static function parseDocComment(string $comment, int $tabDepth = 1): array {
        // Parse the short & long descriptions.
        // Parse the @params & @return etc.
        $parsedComment = [
            "shortDescription" => "",
            "longDescription"  => "\n"
        ];
        $regex         = "^\/[*]{2}\s+\*\s(.+)[\s\S]+?\*(?:\/|\s+\* ([\s\S]*?)(?:\s+\*\n\s+\* |(?=@|\n\s*\*\/|\*\n)))^";
        preg_match($regex, $comment, $matches);
        if ($matches) {
            $tabs                              = str_repeat("\t", $tabDepth);
            $parsedComment["shortDescription"] = $tabs . $matches[1];
            if (isset($matches[2]) && $matches[2]) {
                $longDesc                         = preg_replace("^[\h\*]{2,}^", "", $matches[2]);
                $parsedComment["longDescription"] = $tabs . preg_replace("^\n^", "\n{$tabs}", $longDesc) . "\n\n";
            }
        }
        return $parsedComment;
    }

    /**
     * Generate a new UUID.
     *
     * @return string
     */
    public static function getUuid(): string {
        mt_srand((double)microtime() * 10000);
        $charid = strtolower(md5(uniqid(rand(), TRUE)));
        $hyphen = chr(45);
        $uuid   = substr($charid, 0, 8) . $hyphen
            . substr($charid, 8, 4) . $hyphen
            . substr($charid, 12, 4) . $hyphen
            . substr($charid, 16, 4) . $hyphen
            . substr($charid, 20, 12);
        return $uuid;
    }

    /**
     * Validates given data based on provided format.
     *
     * @param array $properties  The data to validate.
     * @param array $validFormat The validation rules.
     * @param array $validAll    If set, requires all properties to have a validation rule.
     *
     * @return array
     */
    // @codingStandardsIgnoreStart - Ignored due to switch statements breaking the cyclomatic complexity
    public static function dataValidator(array $properties, array $validation, bool $validateAll = false): array {
        $errors = [];
        $fields = array_keys($properties);
        if ($validateAll) {
            foreach ($fields as $name) {
                if (!isset($validation[$name])) {
                    $errors[] = "No validation format provided for property '{$name}'.";
                }
            }
        }
        foreach ($validation as $fieldName => $format) {
            $required = isset($format["required"]) ? $format["required"] : false;
            if (!isset($properties[$fieldName])) {
                if ($required) {
                    $errors[] = "Required property '{$fieldName}' not provided.";
                }
                continue;
            }
            foreach ($format as $validator => $condition) {
                switch ($validator) {
                    case "type":
                        $typeError = false;
                        if (!is_array($condition)) {
                            $condition = [$condition];
                        }
                        foreach ($condition as $cond) {
                            switch ($cond) {
                                case "json":
                                    json_decode($properties[$fieldName]);
                                    if (json_last_error() !== JSON_ERROR_NONE) {
                                        $typeError = true;
                                        break 2;
                                    }
                                    break;
                                case "boolean":
                                    if (filter_var($properties[$fieldName], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                                        $typeError = true;
                                        break 2;
                                    }
                                    break;
                                case "numeric":
                                    if (!is_numeric($properties[$fieldName])) {
                                        $typeError = true;
                                        break 2;
                                    }
                                    break;
                                case "string":
                                    if (!is_string($properties[$fieldName])) {
                                        $typeError = true;
                                        break 2;
                                    }
                                    break;
                                case "array":
                                    if(!is_array($properties[$fieldName])){
                                        $typeError = true;
                                        break 2;
                                    }
                                    if (isset($format["fields"])) {
                                        $errors = array_merge(self::dataValidator($properties[$fieldName], $format["fields"]));
                                    }
                                    break;
                                case "arrays":
                                    if(!is_array($properties[$fieldName])){
                                        $typeError = true;
                                        break 2;
                                    }
                                    if (isset($format["fields"])) {
                                        foreach ($properties[$fieldName] as $key => $arrProperties) {
                                            $errors = array_merge(self::dataValidator($arrProperties, $format["fields"]));
                                        }
                                    }
                                    break;
                            }
                        }
                        if ($typeError) {
                            $errors[] = "Property '{$fieldName}' has invalid type.";
                        }
                        break;
                    case "length":
                        if ($condition != strlen($properties[$fieldName])) {
                            $errors[] = "Property '{$fieldName}' has invalid length.";
                        }
                        break;
                    case "maxLength":
                        if ($condition < strlen($properties[$fieldName])) {
                            $errors[] = "Property '{$fieldName}' is too long.";
                        }
                        break;
                    case "minLength":
                        if ($condition > strlen($properties[$fieldName])) {
                            $errors[] = "Property '{$fieldName}' is too short.";
                        }
                        break;
                    case "eval":
                        if (
                            is_scalar($properties[$fieldName]) &&
                            !eval("return {$properties[$fieldName]} {$condition};")
                        ) {
                            $errors[] = "Property '{$fieldName}' does not meet the condition '{$condition}'";
                        }
                        break;
                    case "required":
                        if (
                            empty($properties[$fieldName])
                            && !(
                                $properties[$fieldName] === false
                                || $properties[$fieldName] === 0
                                || $properties[$fieldName] === "0"
                            )
                        ) {
                            $errors[] = "Required property '{$fieldName}' not provided.";
                        }
                        break;
                    case "regex":
                        if (!preg_match($condition, $properties[$fieldName])) {
                            $errors[] = "Property '{$fieldName}' does not match provided format.";
                        }
                        break;
                }
            }
        }
        return $errors;
    }
    // @codingStandardsIgnoreEnd

    /**
     * Format a given URL to include the scheme if missing & a single trailing "/".
     *
     * @param string $url The URL to format.
     *
     * @return string
     */
    public static function formatUrl(string $url): string {
        $url = rtrim(stripslashes(trim($url)), "/") . "/";
        if (!isset(parse_url($url)["scheme"])) {
            $url = "https://" . $url;
        }
        return $url;
    }

    /**
     * Converts a string from camel case to snake case.
     * e.g. exampleString -> example_string.
     *
     * @param string $string The camel case string to convert to snake case.
     *
     * @return string
     */
    public static function camelToSnake(string $string): string {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', "_$0", $string));
    }

    /**
     * Converts a string from snake case to camel case.
     * e.g. example_string -> exampleString.
     *
     * @param string $string The snake case string to convert to camel case.
     *
     * @return string
     */
    public static function snakeToCamel(string $string): string {
        return lcfirst(str_replace(" ", "", ucwords(str_replace("_", " ", $string))));
    }

    /**
     * Change the casing for all array keys recursively.
     *
     * @param array   $array The array to change key casing for.
     * @param integer $case  The case to change to. Accepts: CASE_LOWER(default) & CASE_UPPER.
     *
     * @return array
     */
    public static function arrayKeyCase(array $array, int $case = CASE_LOWER): array {
        return array_map(function ($item) use ($case) {
            if (is_array($item)) {
                $item = self::arrayKeyCase($item, $case);
            }
            return $item;
        }, array_change_key_case($array, $case));
    }

    /**
     * A function to format floating point numbers and remove the floating point precision error.
     *
     * @param float  $float      The float to format.
     * @param string $cSeparator The thousands separator.
     *
     * @return string
     */
    public static function fvf(float $float, string $cSeparator = ""): string {
        $fvf = number_format($float, 2, ".", "");
        if ($fvf === "-0.00") {
            return "0.00";
        }
        return number_format($float, 2, ".", $cSeparator);
    }

    /**
     * Converts an XML string to array format.
     *
     * @param string $xmlstr The XML string to convert.
     *
     * @return array
     */
    public static function xmlToArr(string $xmlstr): array {
        if ($xmlstr === "OK") {
            return FALSE;
        }
        $xml = simplexml_load_string($xmlstr);
        if ($xml === FALSE) {
            Nova::log("Invalid XML: {$xmlstr}", "error");
            return [];
        }
        $json = json_encode($xml);
        $arr  = json_decode($json, TRUE);
        return $arr;
    }

    /**
     * Function to transliterate text where given characters may not be available to use.
     *
     * @param string $string The string to convert.
     *
     * @return string
     */
    public static function transliterateString(string $string): string {
        // @codingStandardsIgnoreStart - Ignored due to large multiline array
        $transliteration = [
            "??" => "I", "??" => "O", "??" => "O", "??" => "U", "??" => "a", "??" => "a", "??" => "i", "??" => "o", "??" => "o",
            "??" => "u", "??" => "s", "??" => "s", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A",
            "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "C", "??" => "C", "??" => "C", "??" => "C", "??" => "C",
            "??" => "D", "??" => "D", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E",
            "??" => "E", "??" => "E", "??" => "G", "??" => "G", "??" => "G", "??" => "G", "??" => "H", "??" => "H", "??" => "I",
            "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "J",
            "??" => "K", "??" => "K", "??" => "K", "??" => "K", "??" => "K", "??" => "L", "??" => "N", "??" => "N", "??" => "N",
            "??" => "N", "??" => "N", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O",
            "??" => "O", "??" => "R", "??" => "R", "??" => "R", "??" => "S", "??" => "S", "??" => "S", "??" => "S", "??" => "S",
            "??" => "T", "??" => "T", "??" => "T", "??" => "T", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U",
            "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "W", "??" => "Y", "??" => "Y", "??" => "Y", "??" => "Z",
            "??" => "Z", "??" => "Z", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a",
            "??" => "a", "??" => "c", "??" => "c", "??" => "c", "??" => "c", "??" => "c", "??" => "d", "??" => "d", "??" => "e",
            "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "f",
            "??" => "g", "??" => "g", "??" => "g", "??" => "g", "??" => "h", "??" => "h", "??" => "i", "??" => "i", "??" => "i",
            "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "j", "??" => "k", "??" => "k",
            "??" => "l", "??" => "l", "??" => "l", "??" => "l", "??" => "l", "??" => "n", "??" => "n", "??" => "n", "??" => "n",
            "??" => "n", "??" => "n", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o",
            "??" => "o", "??" => "r", "??" => "r", "??" => "r", "??" => "s", "??" => "s", "??" => "t", "??" => "u", "??" => "u",
            "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "w", "??" => "y",
            "??" => "y", "??" => "y", "??" => "z", "??" => "z", "??" => "z", "??" => "A", "??" => "A", "???" => "A", "???" => "A",
            "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A",
            "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A", "???" => "A",
            "??" => "B", "??" => "G", "??" => "D", "??" => "E", "??" => "E", "???" => "E", "???" => "E", "???" => "E", "???" => "E",
            "???" => "E", "???" => "E", "???" => "E", "??" => "Z", "??" => "I", "??" => "I", "???" => "I", "???" => "I", "???" => "I",
            "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I",
            "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "??" => "T", "??" => "I", "??" => "I",
            "??" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I", "???" => "I",
            "???" => "I", "???" => "I", "???" => "I", "??" => "K", "??" => "L", "??" => "M", "??" => "N", "??" => "K", "??" => "O",
            "??" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "??" => "P",
            "??" => "R", "???" => "R", "??" => "S", "??" => "T", "??" => "Y", "??" => "Y", "??" => "Y", "???" => "Y", "???" => "Y",
            "???" => "Y", "???" => "Y", "???" => "Y", "???" => "Y", "???" => "Y", "??" => "F", "??" => "X", "??" => "P", "??" => "O",
            "??" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O",
            "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O", "???" => "O",
            "???" => "O", "??" => "a", "??" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a",
            "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a",
            "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a", "???" => "a",
            "??" => "b", "??" => "g", "??" => "d", "??" => "e", "??" => "e", "???" => "e", "???" => "e", "???" => "e", "???" => "e",
            "???" => "e", "???" => "e", "???" => "e", "??" => "z", "??" => "i", "??" => "i", "???" => "i", "???" => "i", "???" => "i",
            "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i",
            "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i",
            "???" => "i", "??" => "t", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "???" => "i", "???" => "i", "???" => "i",
            "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i", "???" => "i",
            "???" => "i", "???" => "i", "??" => "k", "??" => "l", "??" => "m", "??" => "n", "??" => "k", "??" => "o", "??" => "o",
            "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "??" => "p", "??" => "r",
            "???" => "r", "???" => "r", "??" => "s", "??" => "s", "??" => "t", "??" => "y", "??" => "y", "??" => "y", "??" => "y",
            "???" => "y", "???" => "y", "???" => "y", "???" => "y", "???" => "y", "???" => "y", "???" => "y", "???" => "y", "???" => "y",
            "???" => "y", "???" => "y", "???" => "y", "???" => "y", "???" => "y", "??" => "f", "??" => "x", "??" => "p", "??" => "o",
            "??" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o",
            "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o",
            "???" => "o", "???" => "o", "???" => "o", "???" => "o", "???" => "o", "??" => "A", "??" => "B", "??" => "V", "??" => "G",
            "??" => "D", "??" => "E", "??" => "E", "??" => "Z", "??" => "Z", "??" => "I", "??" => "I", "??" => "K", "??" => "L",
            "??" => "M", "??" => "N", "??" => "O", "??" => "P", "??" => "R", "??" => "S", "??" => "T", "??" => "U", "??" => "F",
            "??" => "K", "??" => "T", "??" => "C", "??" => "S", "??" => "S", "??" => "Y", "??" => "E", "??" => "Y", "??" => "Y",
            "??" => "A", "??" => "B", "??" => "V", "??" => "G", "??" => "D", "??" => "E", "??" => "E", "??" => "Z", "??" => "Z",
            "??" => "I", "??" => "I", "??" => "K", "??" => "L", "??" => "M", "??" => "N", "??" => "O", "??" => "P", "??" => "R",
            "??" => "S", "??" => "T", "??" => "U", "??" => "F", "??" => "K", "??" => "T", "??" => "C", "??" => "S", "??" => "S",
            "??" => "Y", "??" => "E", "??" => "Y", "??" => "Y", "??" => "d", "??" => "D", "??" => "t", "??" => "T", "???" => "a",
            "???" => "b", "???" => "g", "???" => "d", "???" => "e", "???" => "v", "???" => "z", "???" => "t", "???" => "i", "???" => "k",
            "???" => "l", "???" => "m", "???" => "n", "???" => "o", "???" => "p", "???" => "z", "???" => "r", "???" => "s", "???" => "t",
            "???" => "u", "???" => "p", "???" => "k", "???" => "g", "???" => "q", "???" => "s", "???" => "c", "???" => "t", "???" => "d",
            "???" => "t", "???" => "c", "???" => "k", "???" => "j", "???" => "h"
        ];
        // @codingStandardsIgnoreEnd
        $string = str_replace(
            array_keys($transliteration),
            array_values($transliteration),
            $string
        );
        return $string;
    }
}

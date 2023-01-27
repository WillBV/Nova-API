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
            "Ĳ" => "I", "Ö" => "O", "Œ" => "O", "Ü" => "U", "ä" => "a", "æ" => "a", "ĳ" => "i", "ö" => "o", "œ" => "o",
            "ü" => "u", "ß" => "s", "ſ" => "s", "À" => "A", "Á" => "A", "Â" => "A", "Ã" => "A", "Ä" => "A", "Å" => "A",
            "Æ" => "A", "Ā" => "A", "Ą" => "A", "Ă" => "A", "Ç" => "C", "Ć" => "C", "Č" => "C", "Ĉ" => "C", "Ċ" => "C",
            "Ď" => "D", "Đ" => "D", "È" => "E", "É" => "E", "Ê" => "E", "Ë" => "E", "Ē" => "E", "Ę" => "E", "Ě" => "E",
            "Ĕ" => "E", "Ė" => "E", "Ĝ" => "G", "Ğ" => "G", "Ġ" => "G", "Ģ" => "G", "Ĥ" => "H", "Ħ" => "H", "Ì" => "I",
            "Í" => "I", "Î" => "I", "Ï" => "I", "Ī" => "I", "Ĩ" => "I", "Ĭ" => "I", "Į" => "I", "İ" => "I", "Ĵ" => "J",
            "Ķ" => "K", "Ľ" => "K", "Ĺ" => "K", "Ļ" => "K", "Ŀ" => "K", "Ł" => "L", "Ñ" => "N", "Ń" => "N", "Ň" => "N",
            "Ņ" => "N", "Ŋ" => "N", "Ò" => "O", "Ó" => "O", "Ô" => "O", "Õ" => "O", "Ø" => "O", "Ō" => "O", "Ő" => "O",
            "Ŏ" => "O", "Ŕ" => "R", "Ř" => "R", "Ŗ" => "R", "Ś" => "S", "Ş" => "S", "Ŝ" => "S", "Ș" => "S", "Š" => "S",
            "Ť" => "T", "Ţ" => "T", "Ŧ" => "T", "Ț" => "T", "Ù" => "U", "Ú" => "U", "Û" => "U", "Ū" => "U", "Ů" => "U",
            "Ű" => "U", "Ŭ" => "U", "Ũ" => "U", "Ų" => "U", "Ŵ" => "W", "Ŷ" => "Y", "Ÿ" => "Y", "Ý" => "Y", "Ź" => "Z",
            "Ż" => "Z", "Ž" => "Z", "à" => "a", "á" => "a", "â" => "a", "ã" => "a", "ā" => "a", "ą" => "a", "ă" => "a",
            "å" => "a", "ç" => "c", "ć" => "c", "č" => "c", "ĉ" => "c", "ċ" => "c", "ď" => "d", "đ" => "d", "è" => "e",
            "é" => "e", "ê" => "e", "ë" => "e", "ē" => "e", "ę" => "e", "ě" => "e", "ĕ" => "e", "ė" => "e", "ƒ" => "f",
            "ĝ" => "g", "ğ" => "g", "ġ" => "g", "ģ" => "g", "ĥ" => "h", "ħ" => "h", "ì" => "i", "í" => "i", "î" => "i",
            "ï" => "i", "ī" => "i", "ĩ" => "i", "ĭ" => "i", "į" => "i", "ı" => "i", "ĵ" => "j", "ķ" => "k", "ĸ" => "k",
            "ł" => "l", "ľ" => "l", "ĺ" => "l", "ļ" => "l", "ŀ" => "l", "ñ" => "n", "ń" => "n", "ň" => "n", "ņ" => "n",
            "ŉ" => "n", "ŋ" => "n", "ò" => "o", "ó" => "o", "ô" => "o", "õ" => "o", "ø" => "o", "ō" => "o", "ő" => "o",
            "ŏ" => "o", "ŕ" => "r", "ř" => "r", "ŗ" => "r", "ś" => "s", "š" => "s", "ť" => "t", "ù" => "u", "ú" => "u",
            "û" => "u", "ū" => "u", "ů" => "u", "ű" => "u", "ŭ" => "u", "ũ" => "u", "ų" => "u", "ŵ" => "w", "ÿ" => "y",
            "ý" => "y", "ŷ" => "y", "ż" => "z", "ź" => "z", "ž" => "z", "Α" => "A", "Ά" => "A", "Ἀ" => "A", "Ἁ" => "A",
            "Ἂ" => "A", "Ἃ" => "A", "Ἄ" => "A", "Ἅ" => "A", "Ἆ" => "A", "Ἇ" => "A", "ᾈ" => "A", "ᾉ" => "A", "ᾊ" => "A",
            "ᾋ" => "A", "ᾌ" => "A", "ᾍ" => "A", "ᾎ" => "A", "ᾏ" => "A", "Ᾰ" => "A", "Ᾱ" => "A", "Ὰ" => "A", "ᾼ" => "A",
            "Β" => "B", "Γ" => "G", "Δ" => "D", "Ε" => "E", "Έ" => "E", "Ἐ" => "E", "Ἑ" => "E", "Ἒ" => "E", "Ἓ" => "E",
            "Ἔ" => "E", "Ἕ" => "E", "Ὲ" => "E", "Ζ" => "Z", "Η" => "I", "Ή" => "I", "Ἠ" => "I", "Ἡ" => "I", "Ἢ" => "I",
            "Ἣ" => "I", "Ἤ" => "I", "Ἥ" => "I", "Ἦ" => "I", "Ἧ" => "I", "ᾘ" => "I", "ᾙ" => "I", "ᾚ" => "I", "ᾛ" => "I",
            "ᾜ" => "I", "ᾝ" => "I", "ᾞ" => "I", "ᾟ" => "I", "Ὴ" => "I", "ῌ" => "I", "Θ" => "T", "Ι" => "I", "Ί" => "I",
            "Ϊ" => "I", "Ἰ" => "I", "Ἱ" => "I", "Ἲ" => "I", "Ἳ" => "I", "Ἴ" => "I", "Ἵ" => "I", "Ἶ" => "I", "Ἷ" => "I",
            "Ῐ" => "I", "Ῑ" => "I", "Ὶ" => "I", "Κ" => "K", "Λ" => "L", "Μ" => "M", "Ν" => "N", "Ξ" => "K", "Ο" => "O",
            "Ό" => "O", "Ὀ" => "O", "Ὁ" => "O", "Ὂ" => "O", "Ὃ" => "O", "Ὄ" => "O", "Ὅ" => "O", "Ὸ" => "O", "Π" => "P",
            "Ρ" => "R", "Ῥ" => "R", "Σ" => "S", "Τ" => "T", "Υ" => "Y", "Ύ" => "Y", "Ϋ" => "Y", "Ὑ" => "Y", "Ὓ" => "Y",
            "Ὕ" => "Y", "Ὗ" => "Y", "Ῠ" => "Y", "Ῡ" => "Y", "Ὺ" => "Y", "Φ" => "F", "Χ" => "X", "Ψ" => "P", "Ω" => "O",
            "Ώ" => "O", "Ὠ" => "O", "Ὡ" => "O", "Ὢ" => "O", "Ὣ" => "O", "Ὤ" => "O", "Ὥ" => "O", "Ὦ" => "O", "Ὧ" => "O",
            "ᾨ" => "O", "ᾩ" => "O", "ᾪ" => "O", "ᾫ" => "O", "ᾬ" => "O", "ᾭ" => "O", "ᾮ" => "O", "ᾯ" => "O", "Ὼ" => "O",
            "ῼ" => "O", "α" => "a", "ά" => "a", "ἀ" => "a", "ἁ" => "a", "ἂ" => "a", "ἃ" => "a", "ἄ" => "a", "ἅ" => "a",
            "ἆ" => "a", "ἇ" => "a", "ᾀ" => "a", "ᾁ" => "a", "ᾂ" => "a", "ᾃ" => "a", "ᾄ" => "a", "ᾅ" => "a", "ᾆ" => "a",
            "ᾇ" => "a", "ὰ" => "a", "ᾰ" => "a", "ᾱ" => "a", "ᾲ" => "a", "ᾳ" => "a", "ᾴ" => "a", "ᾶ" => "a", "ᾷ" => "a",
            "β" => "b", "γ" => "g", "δ" => "d", "ε" => "e", "έ" => "e", "ἐ" => "e", "ἑ" => "e", "ἒ" => "e", "ἓ" => "e",
            "ἔ" => "e", "ἕ" => "e", "ὲ" => "e", "ζ" => "z", "η" => "i", "ή" => "i", "ἠ" => "i", "ἡ" => "i", "ἢ" => "i",
            "ἣ" => "i", "ἤ" => "i", "ἥ" => "i", "ἦ" => "i", "ἧ" => "i", "ᾐ" => "i", "ᾑ" => "i", "ᾒ" => "i", "ᾓ" => "i",
            "ᾔ" => "i", "ᾕ" => "i", "ᾖ" => "i", "ᾗ" => "i", "ὴ" => "i", "ῂ" => "i", "ῃ" => "i", "ῄ" => "i", "ῆ" => "i",
            "ῇ" => "i", "θ" => "t", "ι" => "i", "ί" => "i", "ϊ" => "i", "ΐ" => "i", "ἰ" => "i", "ἱ" => "i", "ἲ" => "i",
            "ἳ" => "i", "ἴ" => "i", "ἵ" => "i", "ἶ" => "i", "ἷ" => "i", "ὶ" => "i", "ῐ" => "i", "ῑ" => "i", "ῒ" => "i",
            "ῖ" => "i", "ῗ" => "i", "κ" => "k", "λ" => "l", "μ" => "m", "ν" => "n", "ξ" => "k", "ο" => "o", "ό" => "o",
            "ὀ" => "o", "ὁ" => "o", "ὂ" => "o", "ὃ" => "o", "ὄ" => "o", "ὅ" => "o", "ὸ" => "o", "π" => "p", "ρ" => "r",
            "ῤ" => "r", "ῥ" => "r", "σ" => "s", "ς" => "s", "τ" => "t", "υ" => "y", "ύ" => "y", "ϋ" => "y", "ΰ" => "y",
            "ὐ" => "y", "ὑ" => "y", "ὒ" => "y", "ὓ" => "y", "ὔ" => "y", "ὕ" => "y", "ὖ" => "y", "ὗ" => "y", "ὺ" => "y",
            "ῠ" => "y", "ῡ" => "y", "ῢ" => "y", "ῦ" => "y", "ῧ" => "y", "φ" => "f", "χ" => "x", "ψ" => "p", "ω" => "o",
            "ώ" => "o", "ὠ" => "o", "ὡ" => "o", "ὢ" => "o", "ὣ" => "o", "ὤ" => "o", "ὥ" => "o", "ὦ" => "o", "ὧ" => "o",
            "ᾠ" => "o", "ᾡ" => "o", "ᾢ" => "o", "ᾣ" => "o", "ᾤ" => "o", "ᾥ" => "o", "ᾦ" => "o", "ᾧ" => "o", "ὼ" => "o",
            "ῲ" => "o", "ῳ" => "o", "ῴ" => "o", "ῶ" => "o", "ῷ" => "o", "А" => "A", "Б" => "B", "В" => "V", "Г" => "G",
            "Д" => "D", "Е" => "E", "Ё" => "E", "Ж" => "Z", "З" => "Z", "И" => "I", "Й" => "I", "К" => "K", "Л" => "L",
            "М" => "M", "Н" => "N", "О" => "O", "П" => "P", "Р" => "R", "С" => "S", "Т" => "T", "У" => "U", "Ф" => "F",
            "Х" => "K", "Ц" => "T", "Ч" => "C", "Ш" => "S", "Щ" => "S", "Ы" => "Y", "Э" => "E", "Ю" => "Y", "Я" => "Y",
            "а" => "A", "б" => "B", "в" => "V", "г" => "G", "д" => "D", "е" => "E", "ё" => "E", "ж" => "Z", "з" => "Z",
            "и" => "I", "й" => "I", "к" => "K", "л" => "L", "м" => "M", "н" => "N", "о" => "O", "п" => "P", "р" => "R",
            "с" => "S", "т" => "T", "у" => "U", "ф" => "F", "х" => "K", "ц" => "T", "ч" => "C", "ш" => "S", "щ" => "S",
            "ы" => "Y", "э" => "E", "ю" => "Y", "я" => "Y", "ð" => "d", "Ð" => "D", "þ" => "t", "Þ" => "T", "ა" => "a",
            "ბ" => "b", "გ" => "g", "დ" => "d", "ე" => "e", "ვ" => "v", "ზ" => "z", "თ" => "t", "ი" => "i", "კ" => "k",
            "ლ" => "l", "მ" => "m", "ნ" => "n", "ო" => "o", "პ" => "p", "ჟ" => "z", "რ" => "r", "ს" => "s", "ტ" => "t",
            "უ" => "u", "ფ" => "p", "ქ" => "k", "ღ" => "g", "ყ" => "q", "შ" => "s", "ჩ" => "c", "ც" => "t", "ძ" => "d",
            "წ" => "t", "ჭ" => "c", "ხ" => "k", "ჯ" => "j", "ჰ" => "h"
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

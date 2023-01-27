<?php

namespace nova\utilities\security;

use nova\utilities\Utilities;

class Security
{
    public $securityMessage  = "Unauthorised";
    protected $authenticated = FALSE;

    /**
     * Authenticate the Nova API.
     *
     * @param string $appType Whether Nova is being run via API or Console command.
     */
    public function __construct(string $appType) {
        $request = Utilities::parseRequest();
        if ($appType === "console") {
            $method = "console";
        } else {
            $exploded = explode("/", trim($request["requestUri"] . "/", "/"));
            $appType  = $exploded[0];
            $subType  = $exploded[1] ?? "";
            if ($appType === "webhook") {
                $method = "{$appType}-{$subType}";
            } else {
                $method = "api";
            }
        }
        switch ($method) {
            case "api":
                $this->authenticated = TRUE;
                break;
            case "console":
                $this->authenticated = TRUE;
                break;
            default:
                $this->authenticated = FALSE;
                break;
        }
        return $this;
    }

    /**
     * Return whether the request has been authenticated.
     *
     * @return boolean
     */
    public function isAuthed(): bool {
        return $this->authenticated;
    }
}

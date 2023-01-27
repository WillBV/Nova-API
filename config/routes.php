<?php

// Default Route.
$this->map(
    "GET",
    $_ENV["BASE_FOLDER"] . "/",
    function () {
        $response = [
            "data"   => NULL,
            "header" => "200 Success"
        ];
        return $response;
    },
    "default"
);

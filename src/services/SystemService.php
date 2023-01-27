<?php

namespace nova\services;

use nova\Nova;
use nova\models\EventModel;
use nova\models\IdempotencyRequestModel;
use nova\models\InfoModel;
use nova\models\NotificationModel;
use nova\models\SettingsModel;
use nova\utilities\Utilities;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use GuzzleHttp\Client;

class SystemService
{
    /**
     * Deletes expired auth tokens in the auth_tokens.
     *
     * @return void
     */
    public function deleteExpiredAuthTokens(): void {
        $dt     = new \DateTime();
        $now    = $dt->format("Y-m-d H:i:s");
        $exists = Nova::$db
            ->select()
            ->from("auth_tokens")
            ->where(["<", "expiry"], ["expiry" => $now])
            ->exists();
        if ($exists) {
            Nova::$db
                ->delete()
                ->from("auth_tokens")
                ->where(["<", "expiry"], ["expiry" => $now])
                ->execute();
        }
    }

    /**
     * Fetch and clean relevant auth tokens in the DB.
     *
     * @param string $tokenId The token ID to authenticate.
     *
     * @return array
     */
    public function getAuthToken(string $tokenId): array {
        $this->deleteExpiredAuthTokens();
        $dt        = new \DateTime();
        $now       = $dt->format("Y-m-d H:i:s");
        $authToken = Nova::$db
            ->select()
            ->from("auth_tokens")
            ->where(["=", "token_id"], ["token_id" => $tokenId])
            ->andWhere([">", "expiry"], ["expiry" => $now])
            ->one();
        return $authToken;
    }

    /**
     * Clear out expired saved idempotent responses.
     *
     * @return void
     */
    public function clearIdempotentResponses(): void {
        $dt     = new \DateTime();
        $now    = $dt->format("Y-m-d H:i:s");
        $exists = Nova::$db
            ->select()
            ->from("idempotent_requests")
            ->where(["<", "expiry"], ["expiry" => $now])
            ->exists();
        if ($exists) {
            Nova::$db
                ->delete()
                ->from("idempotent_requests")
                ->where(["<", "expiry"], ["expiry" => $now])
                ->execute();
        }
    }

    /**
     * Fetch a saved response based on the provided idempotency key.
     *
     * @param string $idempotencyKey The key to fetch a saved response.
     *
     * @return IdempotencyRequestModel
     */
    public function getIdempotentResponse(string $idempotencyKey): IdempotencyRequestModel {
        $this->clearIdempotentResponses();
        $idempotencyModel = (new IdempotencyRequestModel())->find($idempotencyKey);
        return $idempotencyModel;
    }

    /**
     * Save a new response set to the provided idempotency key.
     *
     * @param string  $idempotencyKey The unique idempotency key.
     * @param string  $route          The route of the request.
     * @param string  $status         The status code of the response.
     * @param array   $body           The body of the response.
     * @param array   $headers        The extra headers of the response.
     * @param integer $expiry         How long until the key should expire in seconds.
     *
     * @return array
     */
    public function setIdempotentResponse(
        string $idempotencyKey,
        string $route,
        string $status,
        array $body = [],
        array $headers = [],
        int $expiry = 900
    ): array {
        $dt = new \DateTime();
        $dt->add(new \DateInterval("PT" . $expiry . "S"));
        $idempotencyModel                 = new IdempotencyRequestModel();
        $idempotencyModel->idempotencyKey = $idempotencyKey;
        $idempotencyModel->route          = $route;
        $idempotencyModel->body           = json_encode($body);
        $idempotencyModel->headers        = json_encode($headers);
        $idempotencyModel->statusCode     = $status;
        $idempotencyModel->expiry         = $dt->format("Y-m-d H:i:s");
        $success                          = $idempotencyModel->create();
        return $success;
    }
}

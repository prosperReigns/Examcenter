<?php

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/RequestSigner.php";

class LicenseAPI
{


    /*
    |--------------------------------------------------------------------------
    | Get configuration
    |--------------------------------------------------------------------------
    */

    private static function config(): array
    {
        return config("license");
    }



    /*
    |--------------------------------------------------------------------------
    | License server base URL
    |--------------------------------------------------------------------------
    */

    private static function server(): string
    {
        $config = self::config();

        return rtrim(
            $config["server"],
            "/"
        );
    }



    /*
    |--------------------------------------------------------------------------
    | Build endpoint
    |--------------------------------------------------------------------------
    */

    private static function endpoint(
        string $name
    ): string {

        $config = self::config();

        return
            $config["endpoints"][$name];

    }



    /*
    |--------------------------------------------------------------------------
    | HTTP Request Handler
    |--------------------------------------------------------------------------
    */

    private static function request(
        string $method,
        string $endpoint,
        array $payload = []
    ): array {


        $app = config("app");


        $url =
            self::server()
            .
            $endpoint;



        if (!function_exists("curl_init")) {

            throw new Exception(
                "cURL extension is not installed."
            );

        }

        $timestamp =
            time();


        $nonce =
            RequestSigner::nonce();


        $requestBody =
            json_encode(
                $payload
            );


        $signature =
            RequestSigner::sign(

                $requestBody,

                $timestamp,

                $nonce

            );

        $ch = curl_init($url);



        $headers = [

            "Content-Type: application/json",

            "Accept: application/json",

            "X-App-Name: "
                . $app["name"],

            "X-App-Version: "
                . $app["version"],

            "X-Request-Time: " . $timestamp,

            "X-Request-Nonce: " . $nonce,

            "X-Request-Signature: " . $signature

        ];



        $options = [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_HTTPHEADER =>
                $headers,

            CURLOPT_USERAGENT =>
                $app["name"]
                .
                "/"
                .
                $app["version"],


            CURLOPT_SSL_VERIFYPEER => true,

            CURLOPT_SSL_VERIFYHOST => 2,


            CURLOPT_TIMEOUT =>
                self::config()["timeout"],


            CURLOPT_CONNECTTIMEOUT =>
                self::config()["connect_timeout"],

        ];



        if (
            strtoupper($method)
            === "POST"
        ) {


            $options[CURLOPT_POST] = true;

            $requestBody = json_encode($payload);

            $options[CURLOPT_POSTFIELDS] =
                $requestBody;

        }

        if (
            strtoupper($method) === "GET"
            && !empty($payload)
        ) {

            $url .= "?" . http_build_query($payload);

        }



        curl_setopt_array(
            $ch,
            $options
        );



        $response = curl_exec($ch);

        if ($response === false) {

            throw new Exception(
                curl_error($ch)
            );

        }



        $status =
            curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

        error_log(
            "LICENSE API REQUEST: "
            . strtoupper($method)
            . " "
            . $url  
        );

        error_log(
            "LICENSE API RESPONSE HTTP: "
            . $status
        );

        error_log(
            "LICENSE API RESPONSE BODY: "
            . $response
        );


        curl_close($ch);



        if ($status >= 400) {

            error_log(
                "LICENSE SERVER HTTP ERROR: "
                . $status
                . " URL="
                . $url
                . " RESPONSE="
                . $response
            );

            throw new Exception(
                "License server request failed. HTTP "
                . $status
                . ": "
                . $response
            );

        }



        $json =
            json_decode(
                $response,
                true
            );



        if (
            !is_array($json)
        ) {

            throw new Exception(
                "Invalid license server response."
            );

        }


        return $json;

    }



    /*
    |--------------------------------------------------------------------------
    | Check Purchase Status
    |--------------------------------------------------------------------------
    */

    public static function purchaseStatus(
        string $pollToken
    ): array {


        return self::request(

            "GET",

            self::endpoint(
                "purchase_status"
            )
            .
            "/"
            .
            urlencode(
                $pollToken
            )

        );

    }


    public static function fetchLicense(
        string $token
    ): array
    {


        return self::request(

            "GET",

            "/api/public/license/"
            .
            urlencode(
                $token
            )

        );


    }

    /*
    |--------------------------------------------------------------------------
    | Heartbeat
    |--------------------------------------------------------------------------
    */

    public static function heartbeat(
        array $payload
    ): array {


        return self::request(

            "POST",

            self::endpoint(
                "heartbeat"
            ),

            $payload

        );

    }

    public static function plans()
    {

        return self::request(
            "GET",
            self::endpoint("plans")

        );

    }

    public static function startPurchase(array $payload): array
    {
        $response = self::request(
            "POST",
            self::endpoint("purchase_start"),
            $payload
        );

        if (isset($response["checkout_token"])) {

            $response["checkout_url"] =
                self::checkoutUrl(
                    $response["checkout_token"]
                );

        }

        return $response;
    }

    public static function deliverLicense(
        string $token
    ): array {


        return self::request(
            "GET",
            self::endpoint("license_delivery")
            .
            urlencode($token)
        );

    }

    public static function checkoutUrl(
        string $checkoutToken
    ): string {

        return
            self::server()
            .
            self::endpoint("checkout")
            .
            "/"
            .
            urlencode($checkoutToken);

    }
}
?>
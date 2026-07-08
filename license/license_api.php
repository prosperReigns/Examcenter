<?php
require_once __DIR__ . "/helpers.php";
class LicenseAPI
{
    /*
    |--------------------------------------------------------------------------
    | Change this once before deployment
    |--------------------------------------------------------------------------
    */

   private static function server()
    {
        $config = config("license");

        return rtrim(
            $config["server"],
            "/"
        );
    }
    /**
     * Send POST request
     */
    private static function request(
        string $endpoint,
        array $payload,
    ): array {
        $app = config("app");
        $url = self::server() . $endpoint;
        if (!function_exists("curl_init")) {
            throw new Exception(
                "cURL extension is not installed."
            );

        }
        $ch = curl_init($url);

        curl_setopt_array($ch, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_HTTPHEADER => [

                "Content-Type: application/json",

                "X-App-Name: " . $app["name"],

                "X-App-Version: " . $app["version"]

            ], 

            CURLOPT_USERAGENT =>
            $app["name"] .
            "/" .
            $app["version"],

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_POSTFIELDS =>
                json_encode($payload),

            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,

        ]);

        $response = curl_exec($ch);

        if ($response === false) {

            throw new Exception(
                curl_error($ch)
            );

        }

        $status = curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        if ($status >= 400) {

            throw new Exception(
                "License server unavailable."
            );

        }

        $json = json_decode(
            $response,
            true
        );

        if (!$json) {

            throw new Exception(
                "Invalid server response."
            );

        }

        return $json;
    }

    /**
     * Verify license online
     */
    public static function verify(
        string $licenseKey,
        string $fingerprint
    ): array {

        return self::request(

            "/api/license/verify",

            [

                "license_key"=>$licenseKey,

                "fingerprint"=>$fingerprint

            ]

        );

    }

}
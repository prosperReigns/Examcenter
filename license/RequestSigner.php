<?php

class RequestSigner
{


    /*
    |--------------------------------------------------------------------------
    | Application secret
    |--------------------------------------------------------------------------
    */

    private static function secret(): string
    {

        $config =
            config("app");


        return $config["api_secret"];

    }





    /*
    |--------------------------------------------------------------------------
    | Generate request signature
    |--------------------------------------------------------------------------
    */

    public static function sign(
        string $payload,
        int $timestamp,
        string $nonce
    ): string
    {

        $data = implode("|", [

            $payload,

            $timestamp,

            $nonce

        ]);

        return hash_hmac(

            "sha256",

            $data,

            self::secret()

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Generate nonce
    |--------------------------------------------------------------------------
    */

    public static function nonce(): string
    {

        return bin2hex(

            random_bytes(16)

        );

    }


}
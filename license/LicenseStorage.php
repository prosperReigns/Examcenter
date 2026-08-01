<?php

require_once __DIR__ . "/installation.php";


class LicenseStorage
{


    private static function file(): string
    {

        return __DIR__
            .
            "/storage/license.enc";

    }



    /*
    |--------------------------------------------------------------------------
    | Generate local encryption key
    |--------------------------------------------------------------------------
    */

    private static function key(): string
    {

        return hash(

            "sha256",

            InstallationIdentity::id(),

            true

        );

    }




    /*
    |--------------------------------------------------------------------------
    | Store encrypted license
    |--------------------------------------------------------------------------
    */

    public static function store(
        string $license
    ): bool
    {


        $iv =
            random_bytes(
                16
            );



        $encrypted =
            openssl_encrypt(

                $license,

                "AES-256-CBC",

                self::key(),

                OPENSSL_RAW_DATA,

                $iv

            );



        if (
            !$encrypted
        ) {

            return false;

        }



        $payload = [

            "version" => 1,


            "iv" =>
                base64_encode(
                    $iv
                ),


            "data" =>
                base64_encode(
                    $encrypted
                )

        ];



        $directory =
            dirname(
                self::file()
            );



        if (
            !is_dir($directory)
        ) {

            mkdir(
                $directory,
                0755,
                true
            );

        }



        return file_put_contents(

            self::file(),

            json_encode(
                $payload,
                JSON_PRETTY_PRINT
            ),

            LOCK_EX

        ) !== false;


    }





    /*
    |--------------------------------------------------------------------------
    | Retrieve decrypted license
    |--------------------------------------------------------------------------
    */

    public static function get(): ?string
    {


        if (
            !file_exists(
                self::file()
            )
        ) {

            return null;

        }



        $content =
            json_decode(

                file_get_contents(
                    self::file()
                ),

                true

            );



        if (
            empty($content["iv"])
            ||
            empty($content["data"])
        ) {

            return null;

        }



        return openssl_decrypt(

            base64_decode(
                $content["data"]
            ),

            "AES-256-CBC",

            self::key(),

            OPENSSL_RAW_DATA,

            base64_decode(
                $content["iv"]
            )

        );


    }





    /*
    |--------------------------------------------------------------------------
    | Delete stored license
    |--------------------------------------------------------------------------
    */

    public static function delete(): void
    {

        if (
            file_exists(
                self::file()
            )
        ) {

            unlink(
                self::file()
            );

        }

    }


}
<?php

/**
 * Installation Identity Manager
 *
 * Creates and manages a unique
 * ExamCenter installation identifier.
 */


class InstallationIdentity
{


    private static function file(): string
    {

        return __DIR__
            .
            "/installation.id";

    }



    /*
    |--------------------------------------------------------------------------
    | Generate Installation ID
    |--------------------------------------------------------------------------
    */

    private static function generate(): string
    {

        $data = [

            "random" =>
                bin2hex(
                    random_bytes(32)
                ),


            "created_at" =>
                date(
                    "Y-m-d H:i:s"
                ),


            "host" =>
                php_uname()

        ];



        return strtoupper(

            hash(

                "sha256",

                json_encode($data)

            )

        );

    }




    /*
    |--------------------------------------------------------------------------
    | Get Installation ID
    |--------------------------------------------------------------------------
    */

    public static function id(): string
    {

        $file =
            self::file();



        if (
            file_exists($file)
        ) {


            return trim(

                file_get_contents(
                    $file
                )

            );

        }



        $id =
            self::generate();



        file_put_contents(

            $file,

            $id,

            LOCK_EX

        );



        return $id;

    }





    /*
    |--------------------------------------------------------------------------
    | Verify installation file
    |--------------------------------------------------------------------------
    */

    public static function exists(): bool
    {

        return file_exists(
            self::file()
        );

    }


}
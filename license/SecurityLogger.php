<?php

require_once __DIR__ . "/installation.php";
require_once __DIR__ . "/fingerprint.php";
require_once __DIR__ . "/EventSync.php";

class SecurityLogger
{
    private static function file(): string
    {

        return __DIR__
            .
            "/security.log";

    }

    public static function write(
        string $event,
        string $message,
        array $context = []
    ): void
    {


        $entry = [

            "time" =>
                date(
                    "Y-m-d H:i:s"
                ),


            "event" =>
                $event,


            "message" =>
                $message,


            "installation_id" =>
                InstallationIdentity::id(),


            "fingerprint" =>
                MachineFingerprint::generate(),


            "context" =>
                $context

        ];



        file_put_contents(

            self::file(),

            json_encode(
                $entry
            )
            .
            PHP_EOL,

            FILE_APPEND |
            LOCK_EX

        );

        try {
            EventSync::send(

                $event,

                $message,

                $context

            );


        }
        catch(Exception $e)
        {

            // Do not block CBT operation

        }
    }

    public static function recent(
        int $limit = 50
    ): array
    {

        if (
            !file_exists(
                self::file()
            )
        ) {

            return [];

        }



        $lines =
            file(
                self::file()
            );



        $lines =
            array_slice(
                $lines,
                -$limit
            );



        return array_map(

            function($line){

                return json_decode(
                    $line,
                    true
                );

            },

            $lines

        );

    }


}
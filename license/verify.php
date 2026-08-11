<?php

require_once __DIR__ . '/../db.php';
require_once 'fingerprint.php';
require_once "installation.php";
require_once "LicenseStorage.php";
require_once "CloneDetector.php";
require_once "SecurityLogger.php";

class LicenseVerifier
{
    private $db;
    private $publicKey;

    public function __construct()
    {
        $this->db =
            Database::getInstance()
                ->getConnection();

        $keyFile =
            __DIR__ . '/../keys/public.pem';

        if (!file_exists($keyFile)) {
            throw new Exception(
                "Public key not found."
            );
        }

        $this->publicKey =
            openssl_pkey_get_public(
                file_get_contents($keyFile)
            );

        if (!$this->publicKey) {
            throw new Exception(
                "Invalid public key."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Recursively sort associative arrays
    |-----------------------------------------------

    /*
    |--------------------------------------------------------------------------
    | Recursively sort associative arrays
    |--------------------------------------------------------------------------
    |
    | Matches Python:
    |
    | json.dumps(
    |     data,
    |     sort_keys=True,
    |     separators=(",", ":")
    | )
    |
    */

    private function sortKeysRecursive(
        &$data
    ): void {

        if (!is_array($data)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Python sort_keys=True sorts dictionary/object keys.
        |
        | We must NOT sort JSON arrays.
        |--------------------------------------------------------------------------
        */

        if (
            array_keys($data) !==
            range(0, count($data) - 1)
        ) {

            uksort(
                $data,
                function ($a, $b) {

                    return strcmp(
                        (string) $a,
                        (string) $b
                    );
                }
            );
        }

        foreach ($data as &$value) {

            if (is_array($value)) {

                $this->sortKeysRecursive(
                    $value
                );
            }
        }

        unset($value);
    }


    /*
    |--------------------------------------------------------------------------
    | Build canonical JSON
    |--------------------------------------------------------------------------
    */

    private function canonicalJson(
    array $payload
    ): string {

    /*
    |--------------------------------------------------------------------------
    | Preserve JSON object semantics
    |--------------------------------------------------------------------------
    |
    | PHP's json_decode(..., true) converts both:
    |
    |     {}
    |
    | and
    |
    |     []
    |
    | into PHP arrays.
    |
    | The License Server/Python uses "features" as a dictionary/object,
    | therefore an empty features value must be encoded as {} and NOT [].
    |
    */

    if (
        array_key_exists("features", $payload) &&
        is_array($payload["features"]) &&
        empty($payload["features"])
    ) {

        $payload["features"] =
            new stdClass();
    }


    /*
    |--------------------------------------------------------------------------
    | Recursively sort associative arrays
    |--------------------------------------------------------------------------
    |
    | Matches Python:
    |
    | json.dumps(
    |     data,
    |     sort_keys=True,
    |     separators=(",", ":")
    | )
    |
    */

    $this->sortKeysRecursive(
        $payload
    );


    /*
    |--------------------------------------------------------------------------
    | Build compact JSON
    |--------------------------------------------------------------------------
    */

    $json =
        json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );

    if ($json === false) {

        throw new Exception(
            "Unable to encode license payload: "
            . json_last_error_msg()
        );
    }

    return $json;

    }

    /*
    |--------------------------------------------------------------------------
    | Decode license document
    |--------------------------------------------------------------------------
    */

    private function decodeLicense(
        string $licenseText
    ): array {

        $licenseText =
            trim($licenseText);

        if ($licenseText === '') {

            throw new Exception(
                "Empty license."
            );
        }

        /*
        |----------------------------------------------------------------------
        | The License Server returns raw JSON.
        | DO NOT base64_decode it.
        |----------------------------------------------------------------------
        */

        $decoded =
            json_decode(
                $licenseText,
                true
            );

        if (
            !is_array($decoded)
        ) {

            throw new Exception(
                "Invalid license JSON."
            );
        }

        /*
        |----------------------------------------------------------------------
        | Required fields from License Server
        |----------------------------------------------------------------------
        */

        if (
            !isset($decoded["signature"])
        ) {

            throw new Exception(
                "License signature missing."
            );
        }

        if (
            !isset($decoded["checksum"])
        ) {

            throw new Exception(
                "License checksum missing."
            );
        }

        return $decoded;
    }


    /*
    |--------------------------------------------------------------------------
    | Extract actual payload
    |--------------------------------------------------------------------------
    */

    private function extractPayload(
        array $license
    ): array {

        $payload =
            $license;

        /*
        |----------------------------------------------------------------------
        | These fields are NOT part of the signed LicensePayload.
        |----------------------------------------------------------------------
        */

        unset(
            $payload["signature"],
            $payload["checksum"],
            $payload["checksum_algorithm"],
            $payload["signature_algorithm"]
        );

        return $payload;
    }


    /*
    |--------------------------------------------------------------------------
    | Verify checksum
    |--------------------------------------------------------------------------
    */

    private function verifyChecksum(
        array $payload,
        string $checksum
    ): void {

        $canonical =
            $this->canonicalJson(
                $payload
            );

        $calculated =
            hash(
                "sha256",
                $canonical
            );

        error_log(
            "========== LICENSE CHECKSUM DEBUG =========="
        );

        error_log(
            "PHP CANONICAL JSON:"
        );

        error_log(
            $canonical
        );

        error_log(
            "PHP CALCULATED CHECKSUM:"
        );

        error_log(
            $calculated
        );

        error_log(
            "SERVER CHECKSUM:"
        );

        error_log(
            $checksum
        );

        error_log(
            "============================================"
        );
        if (
            !hash_equals(
                $checksum,
                $calculated
            )
        ) {

            error_log(
                "LICENSE CHECKSUM FAILURE"
            );

            error_log(
                "EXPECTED: "
                . $calculated
            );

            error_log(
                "RECEIVED: "
                . $checksum
            );

            throw new Exception(
                "License checksum mismatch."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Verify RSA signature
    |--------------------------------------------------------------------------
    */

    private function verifySignature(
        array $payload,
        string $signature
    ): void {

        $canonical =
            $this->canonicalJson(
                $payload
            );

        $signatureBytes =
            base64_decode(
                $signature,
                true
            );

        if (
            $signatureBytes === false
        ) {

            throw new Exception(
                "Invalid license signature encoding."
            );
        }

        $result =
            openssl_verify(
                $canonical,
                $signatureBytes,
                $this->publicKey,
                OPENSSL_ALGO_SHA256
            );

        if ($result !== 1) {

            error_log(
                "LICENSE SIGNATURE FAILURE"
            );

            error_log(
                "OPENSSL ERROR: "
                . openssl_error_string()
            );

            throw new Exception(
                "Invalid digital signature."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Verify license version
    |--------------------------------------------------------------------------
    */

    private function verifyLicenseVersion(
        array $license
    ): void {

        if (
            !isset($license["version"])
        ) {

            throw new Exception(
                "Missing license version."
            );
        }

        if (
            (int) $license["version"] !== 1
        ) {

            throw new Exception(
                "Unsupported license version."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Verify product
    |--------------------------------------------------------------------------
    */

    private function verifyProduct(
        array $license
    ): void {

        if (
            !isset($license["product_code"])
        ) {

            throw new Exception(
                "Missing product code."
            );
        }

        /*
        |----------------------------------------------------------------------
        | This is the value actually present in your current database license:
        |
        |     "product_code": "cbt_exam"
        |
        |----------------------------------------------------------------------
        */

        $expected =
            "cbt_exam";

        if (
            $license["product_code"] !==
            $expected
        ) {

            throw new Exception(
                "Invalid product."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Verify machine fingerprint
    |--------------------------------------------------------------------------
    */

    private function verifyMachine(
        array $license
    ): string {

        if (
            !isset($license["machine"])
        ) {

            throw new Exception(
                "Machine fingerprint missing."
            );
        }

        $machine =
            MachineFingerprint::generate();

        if (
            !hash_equals(
                (string) $license["machine"],
                $machine
            )
        ) {

            error_log(
                "LICENSE MACHINE MISMATCH"
            );

            error_log(
                "LICENSE MACHINE: "
                . $license["machine"]
            );

            error_log(
                "LOCAL MACHINE: "
                . $machine
            );

            throw new Exception(
                "Machine fingerprint mismatch."
            );
        }

        return $machine;
    }


    /*
    |--------------------------------------------------------------------------
    | Verify expiry
    |--------------------------------------------------------------------------
    */

    private function verifyExpiry(
        array $license
    ): void {

        if (
            !isset($license["expiry"])
        ) {

            throw new Exception(
                "Expiry missing."
            );
        }

        $expiry =
            strtotime(
                $license["expiry"]
            );

        if ($expiry === false) {

            throw new Exception(
                "Invalid license expiry."
            );
        }

        if (
            $expiry < time()
        ) {

            throw new Exception(
                "License expired."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Verify issued_at
    |--------------------------------------------------------------------------
    */

    private function verifyIssuedAt(
        array $license
    ): void {

        if (
            empty(
                $license["issued_at"]
            )
        ) {

            return;
        }

        $issuedAt =
            strtotime(
                $license["issued_at"]
            );

        if ($issuedAt === false) {

            throw new Exception(
                "Invalid license issue date."
            );
        }

        if (
            $issuedAt > time()
        ) {

            throw new Exception(
                "License issue date invalid."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Parse and verify complete license
    |--------------------------------------------------------------------------
    */

    private function prepareLicense(
        string $licenseText
    ): array {

        $decoded =
            $this->decodeLicense(
                $licenseText
            );

        $payload =
            $this->extractPayload(
                $decoded
            );

        /*
        |----------------------------------------------------------------------
        | Verify checksum first
        |----------------------------------------------------------------------
        */

        $this->verifyChecksum(
            $payload,
            $decoded["checksum"]
        );

        /*
        |----------------------------------------------------------------------
        | Verify RSA signature
        |----------------------------------------------------------------------
        */

        $this->verifySignature(
            $payload,
            $decoded["signature"]
        );

        /*
        |----------------------------------------------------------------------
        | Validate license data
        |----------------------------------------------------------------------
        */

        $this->verifyLicenseVersion(
            $payload
        );

        $this->verifyProduct(
            $payload
        );

        $this->verifyExpiry(
            $payload
        );

        $this->verifyIssuedAt(
            $payload
        );

        $machine =
            $this->verifyMachine(
                $payload
            );

        return [

            "license" =>
                $payload,

            "machine" =>
                $machine,

            "signature" =>
                $decoded["signature"],

            "payload" =>
                $payload

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Remote activation
    |--------------------------------------------------------------------------
    */

    public function activateRemote(
        string $licenseText
    ) {

        return $this->activate(
            $licenseText
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Activate License
    |--------------------------------------------------------------------------
    */

    public function activate(
        $licenseText
    ) {

        $licenseText =
            trim($licenseText);

        $data =
            $this->prepareLicense(
                $licenseText
            );

        $license =
            $data["license"];

        $machine =
            $data["machine"];

        $signature =
            $data["signature"];

        $installationId =
            InstallationIdentity::id();

        $installationSignature =
            CloneDetector::generate();

        /*
        |----------------------------------------------------------------------
        | Store original signed license
        |----------------------------------------------------------------------
        */

        if (
            !LicenseStorage::store(
                $licenseText
            )
        ) {

            throw new Exception(
                "Unable to securely store license."
            );
        }

        try {

            $this->db->begin_transaction();

            /*
            |----------------------------------------------------------------------
            | Remove old installation
            |----------------------------------------------------------------------
            */

            $this->db->query(
                "DELETE FROM licenses"
            );

            /*
            |----------------------------------------------------------------------
            | Insert new license
            |----------------------------------------------------------------------
            */

            $stmt =
                $this->db->prepare(
                "
                INSERT INTO licenses(
                    school_name,
                    license_key,
                    machine_fingerprint,
                    installation_id,
                    installation_signature,
                    license_signature,
                    license_type,
                    activation_date,
                    expiry_date,
                    status,
                    last_verified,
                    last_system_time
                )

                VALUES(
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'active',
                    NOW(),
                    NOW()
                )
                "
            );

            if (!$stmt) {

                throw new Exception(
                    "Unable to prepare license activation."
                );
            }

            $now =
                date(
                    'Y-m-d H:i:s'
                );

            $stmt->bind_param(

                "sssssssss",

                $license["school"],

                $licenseText,

                $machine,

                $installationId,

                $installationSignature,

                $signature,

                $license["license_type"],

                $now,

                $license["expiry"]

            );

            if (
                !$stmt->execute()
            ) {

                throw new Exception(
                    "Unable to save activated license."
                );
            }

            $stmt->close();

            $this->db->commit();

            return true;

        } catch(Exception $e) {

            SecurityLogger::write(
                "ACTIVATION_FAILED",
                $e->getMessage()
            );

            $this->db->rollback();

            throw $e;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Renew License
    |--------------------------------------------------------------------------
    */

    public function renew(
        $licenseText
    ) {

        $licenseText =
            trim($licenseText);

        $data =
            $this->prepareLicense(
                $licenseText
            );

        $license =
            $data["license"];

        $machine =
            $data["machine"];

        $installationId =
            InstallationIdentity::id();

        $installationSignature =
            CloneDetector::generate();

        $signature =
            $data["signature"];

        try {

            $this->db->begin_transaction();

            $stmt =
                $this->db->prepare(
                "
                UPDATE licenses

                SET

                    school_name=?,

                    license_key=?,

                    machine_fingerprint=?,

                    installation_id=?,

                    installation_signature=?,

                    license_signature=?,

                    expiry_date=?,

                    status='active',

                    last_verified=NOW(),

                    last_system_time=NOW()

                LIMIT 1
                "
            );

            if (!$stmt) {

                throw new Exception(
                    "Unable to prepare license renewal."
                );
            }

            $stmt->bind_param(

                "sssssss",

                $license["school"],

                $licenseText,

                $machine,

                $installationId,

                $installationSignature,

                $signature,

                $license["expiry"]

            );

            if (
                !$stmt->execute()
            ) {

                throw new Exception(
                    "Unable to update license."
                );
            }

            $stmt->close();

            $this->db->commit();

            return true;

        } catch(Exception $e) {

            $this->db->rollback();

            throw $e;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    */

    public function preview(
        string $licenseText
    ): array {

        $data =
            $this->prepareLicense(
                $licenseText
            );

        return [

            "license" =>
                $data["license"],

            "machine" =>
                $data["machine"]

        ];
    }
}
?>
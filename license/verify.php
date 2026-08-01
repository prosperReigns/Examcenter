<?php

require_once __DIR__ . '/../db.php';
require_once 'fingerprint.php';
require_once "installation.php";
require_once "LicenseStorage.php";
require_once "installation.php";
require_once "CloneDetector.php";
require_once "SecurityLogger.php";

class LicenseVerifier
{
    private $db;

    private $publicKey;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();

        $keyFile = __DIR__ . '/../keys/public.pem';

        if (!file_exists($keyFile)) {
            throw new Exception("Public key not found.");
        }

        $this->publicKey = openssl_pkey_get_public(
            file_get_contents($keyFile)
        );

        if (!$this->publicKey) {
            throw new Exception("Invalid public key.");
        }
    }

    private function decodeLicense(
        string $licenseText
    ): array
    {
        $decoded = json_decode(
            base64_decode($licenseText),
            true
        );
        if (!$decoded) {
            throw new Exception(
                "Invalid license."
            );
        }

        if (
            !isset($decoded["payload"]) ||
            !isset($decoded["signature"])
        ) {
            throw new Exception(
                "Corrupted license."
            );
        }
        return $decoded;
    }

    private function verifySignature(
        string $payload,
        string $signature
    ): void
    {
        $ok = openssl_verify(
            $payload,
            base64_decode($signature),
            $this->publicKey,
            OPENSSL_ALGO_SHA256
        );
        if ($ok !== 1) {
            throw new Exception(
                "Invalid digital signature."
            );
        }
    }

    private function verifyLicenseVersion(
        array $license
    ): void
    {
        if (
            !isset($license["version"])
        ) {
            throw new Exception(
                "Missing license version."
            );
        }
        if (
            $license["version"] != 1
        ) {
            throw new Exception(
                "Unsupported license version."
            );
        }
    }

    private function verifyProduct(
        array $license
    ): void
    {
        $expected = "examcenter";
        if (
            !isset($license["product"])
        ) {
            throw new Exception(
                "Missing product."
            );
        }
        if (
            $license["product"] !== $expected
        ) {
            throw new Exception(
                "Invalid product."
            );
        }
    }

    private function verifyMachine(
        array $license
    ): string
    {
        $machine = MachineFingerprint::generate();
        if (
            !hash_equals(
                $license["machine"],
                $machine
            )
        ) {
            throw new Exception(
                "Machine fingerprint mismatch."
            );
        }
        return $machine;
    }

    private function verifyExpiry(
        array $license
    ): void
    {
        if (
            !isset($license["expiry"])
        ) {
            throw new Exception(
                "Expiry missing."
            );
        }

        if (
            strtotime(
                $license["expiry"]
            ) < time()
        ) {
            throw new Exception(
                "License expired."
            );
        }
    }

    private function verifyIssuedAt(
        array $license
    ): void
    {
        if (
            empty(
                $license["issued_at"]
            )
        ) {
            return;
        }
        if (
            strtotime(
                $license["issued_at"]
            ) > time()
        ) {
            throw new Exception(
                "License issue date invalid."
            );
        }
    }

    private function parseAndVerifyLicense(string $licenseText): array{
        $decoded =
            $this->decodeLicense(
                $licenseText
            );

        $this->verifySignature(
            $decoded["payload"],
            $decoded["signature"]
        );

        $license = json_decode(
            $decoded["payload"],
            true
        );

        if (!$license) {
            throw new Exception(
                "Invalid payload."
            );
        }

        $this->verifyLicenseVersion(
            $license
        );

        $this->verifyProduct(
            $license
        );

        $this->verifyExpiry(
            $license
        );

        $this->verifyIssuedAt(
            $license
        );

        $machine =
            $this->verifyMachine(
                $license
            );

        return [

            "license"=>$license,
            "machine"=>$machine

        ];

    }

    private function prepareLicense(
        string $licenseText
    ): array
    {

        $data =
            $this->parseAndVerifyLicense(
                $licenseText
            );

        $license =
            $data["license"];

        $machine =
            $data["machine"];

        /*
        |--------------------------------------------------------------------------
        | Keep original server signature
        |--------------------------------------------------------------------------
        */

        $decoded =
            $this->decodeLicense(
                $licenseText
            );


        return [

            "license" => $license,

            "machine" => $machine,

            "signature" =>
                $decoded["signature"],

            "payload" =>
                $decoded["payload"]

        ];

    }

    /**
     * Verify License
     */
    public function activate(
        $licenseText
    )
    {

        $data =
            $this->prepareLicense(
                trim($licenseText)
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

        $payload =
            $data["payload"];

        if (
            !LicenseStorage::store(
                trim($licenseText)
            )
        ) {

            throw new Exception(
                "Unable to securely store license."
            );

        }

        try {

            $this->db->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | Remove old installation
            |--------------------------------------------------------------------------
            */

            $this->db->query(
                "DELETE FROM licenses"
            );

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
                    activation_date,
                    expiry_date,
                    status,
                    last_verified,
                    last_system_time
                )

                VALUES(?,?,?,?,?,?,?,?,'active',NOW(),NOW())
                "
            );

            $now =
                date(
                    'Y-m-d H:i:s'
                );

            $stmt->bind_param(

                "ssssssss",

                $license["school"],

                $licenseText,

                $machine,
                $installationId,
                $installationSignature,
                $signature,

                $now,

                $license["expiry"]

            );

            $stmt->execute();

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

    public function renew(
        $licenseText
    )
    {

        $data =
            $this->prepareLicense(
                trim($licenseText)
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

            $stmt->execute();

            $this->db->commit();

            return true;

        } catch(Exception $e) {

            $this->db->rollback();

            throw $e;

        }

    }

    public function preview(
        string $licenseText
    ): array
    {


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
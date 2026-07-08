<?php

require_once __DIR__ . '/../db.php';
require_once 'fingerprint.php';

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
    private function parseAndVerifyLicense(string $licenseText): array
    {
        $decoded = json_decode(base64_decode($licenseText), true);

        if (!$decoded) {
            throw new Exception("Invalid license.");
        }

        if (
            !isset($decoded["payload"]) ||
            !isset($decoded["signature"])
        ) {
            throw new Exception("Corrupted license.");
        }

        $payload = $decoded["payload"];

        $signature = base64_decode($decoded["signature"]);

        $ok = openssl_verify(
            $payload,
            $signature,
            $this->publicKey,
            OPENSSL_ALGO_SHA256
        );

        if ($ok !== 1) {
            throw new Exception("Signature verification failed.");
        }

        $license = json_decode($payload, true);

        if (!$license) {
            throw new Exception("Invalid license payload.");
        }

        $machine = MachineFingerprint::generate();

        if ($machine !== $license["machine"]) {
            throw new Exception(
                "This license belongs to another computer."
            );
        }

        return [
            "license" => $license,
            "machine" => $machine
        ];
    }
    /**
     * Verify License
     */
    public function activate($licenseText)
    {
        $data = $this->parseAndVerifyLicense($licenseText);

        $license = $data["license"];

        $machine = $data["machine"];
        $this->db->query("DELETE FROM licenses");
        $stmt = $this->db->prepare("

        INSERT INTO licenses(

        school_name,

        license_key,

        machine_fingerprint,

        license_signature,

        activation_date,

        expiry_date,

        status,

        last_verified,

        last_system_time

        )

        VALUES(

        ?,?,?,?,?,

        ?,

        'active',

        NOW(),

        NOW()

        )

        ");

        $now = date('Y-m-d H:i:s');
        $signature = generateLicenseSignature([

        "school_name" => $license["school"],

        "machine_fingerprint" => $machine,

        "expiry_date" => $license["expiry"],

        "status" => "active"

    ]);
        $stmt->bind_param(

        "ssssss",

        $license["school"],

        $licenseText,

        $machine,

        $signature,

        $now,

        $license["expiry"]

        );

        $stmt->execute();

        return true;
    }
    public function renew($licenseText)
    {
        $data = $this->parseAndVerifyLicense($licenseText);

        $license = $data["license"];

        $machine = $data["machine"];
        $signature = generateLicenseSignature([

            "school_name"=>$license["school"],

            "machine_fingerprint"=>$machine,

            "expiry_date"=>$license["expiry"],

            "status"=>"active"

        ]);

        /*
        |--------------------------------------------------------------------------
        | Update Existing License
        |--------------------------------------------------------------------------
        */

        $stmt = $this->db->prepare("
            UPDATE licenses
            SET
            school_name=?,
            license_key=?,
            machine_fingerprint=?,
            license_signature=?,
            expiry_date=?,
            status='active',
            last_verified=NOW(),
            last_system_time=NOW()
            LIMIT 1
        ");

        $stmt->bind_param(
        "sssss",
        $license["school"],
        $licenseText,
        $machine,
        $signature,
        $license["expiry"]
        );

        $stmt->execute();

        return true;
    }
}
?>
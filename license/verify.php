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

    /**
     * Verify License
     */
    public function activate($licenseText)
    {
        $decoded = json_decode(base64_decode($licenseText), true);

        if (!$decoded) {
            throw new Exception("Invalid license.");
        }

        if (
            !isset($decoded['payload']) ||
            !isset($decoded['signature'])
        ) {
            throw new Exception("Corrupted license.");
        }

        $payload = $decoded['payload'];

        $signature = base64_decode($decoded['signature']);

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
            throw new Exception("License payload invalid.");
        }

        $machine = MachineFingerprint::generate();

        if ($license['machine'] !== $machine) {
            throw new Exception("License belongs to another computer.");
        }

        $stmt = $this->db->prepare("DELETE FROM licenses");
        $stmt->execute();

        $stmt = $this->db->prepare("

            INSERT INTO licenses(

                school_name,

                license_key,

                machine_fingerprint,

                license_type,

                activation_date,

                expiry_date,

                status,

                last_verified,

                last_system_time

            )

            VALUES(

                ?,?,?,?,?,?,
                'active',
                NOW(),
                NOW()

            )

        ");

        $now = date('Y-m-d H:i:s');

        $stmt->bind_param(

            "ssssss",

            $license['school'],

            $licenseText,

            $machine,

            $license['type'],

            $now,

            $license['expiry']

        );

        $stmt->execute();

        return true;
    }
}
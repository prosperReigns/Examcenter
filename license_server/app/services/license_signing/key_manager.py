from pathlib import Path

from cryptography.hazmat.primitives.asymmetric import rsa

from cryptography.hazmat.primitives import serialization


PRIVATE_KEY = Path("keys/private.pem")

PUBLIC_KEY = Path("keys/public.pem")

def generate_keys():

    key = rsa.generate_private_key(

        public_exponent=65537,

        key_size=4096,

    )

    private_bytes = key.private_bytes(

        encoding=serialization.Encoding.PEM,

        format=serialization.PrivateFormat.PKCS8,

        encryption_algorithm=serialization.NoEncryption(),

    )

    public_bytes = key.public_key().public_bytes(

        encoding=serialization.Encoding.PEM,

        format=serialization.PublicFormat.SubjectPublicKeyInfo,

    )

    PRIVATE_KEY.write_bytes(
        private_bytes
    )

    PUBLIC_KEY.write_bytes(
        public_bytes
    )

def load_private_key():

    return serialization.load_pem_private_key(

        PRIVATE_KEY.read_bytes(),

        password=None,

    )

def load_public_key():

    return serialization.load_pem_public_key(

        PUBLIC_KEY.read_bytes(),

    )
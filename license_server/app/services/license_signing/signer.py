import base64

from cryptography.hazmat.primitives import hashes

from cryptography.hazmat.primitives.asymmetric import padding

from .serializer import serialize_license

from .key_manager import load_private_key

def sign_license(data):

    key = load_private_key()

    payload = serialize_license(data)

    signature = key.sign(

        payload,

        padding.PSS(

            mgf=padding.MGF1(

                hashes.SHA256()

            ),

            salt_length=padding.PSS.MAX_LENGTH,

        ),

        hashes.SHA256(),

    )

    return base64.b64encode(
        signature
    ).decode()
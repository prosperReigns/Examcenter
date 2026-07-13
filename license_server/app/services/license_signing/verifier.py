import base64

from cryptography.exceptions import InvalidSignature

from cryptography.hazmat.primitives import hashes

from cryptography.hazmat.primitives.asymmetric import padding

from .serializer import serialize_license

from .key_manager import load_public_key

def verify_signature(

    data,

    signature,

):

    public_key = load_public_key()

    try:

        public_key.verify(

            base64.b64decode(signature),

            serialize_license(data),

            padding.PSS(

                mgf=padding.MGF1(

                    hashes.SHA256()

                ),

                salt_length=padding.PSS.MAX_LENGTH,

            ),

            hashes.SHA256(),

        )

        return True

    except InvalidSignature:

        return False
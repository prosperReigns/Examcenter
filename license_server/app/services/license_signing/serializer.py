import json


def serialize_license(data):

    return json.dumps(

        data,

        sort_keys=True,

        separators=(",", ":"),

    ).encode()
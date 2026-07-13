def create(
    db,
    activation_token,
):
    db.add(activation_token)
    db.commit()
    db.refresh(activation_token)
    return activation_token

def get_by_token(
    db,
    token: str,
):
    return (
        db.query(ActivationToken)
        .filter(
            ActivationToken.token == token,
        )
        .first()
    )

from datetime import datetime


def mark_used(
    db,
    activation_token,
):
    activation_token.used_at = datetime.utcnow()

    db.commit()

    return activation_token

def revoke(
    db,
    activation_token,
):
    activation_token.revoked_at = datetime.utcnow()

    db.commit()

    return activation_token

from datetime import datetime


def delete_expired(
    db,
):
    return (
        db.query(ActivationToken)
        .filter(
            ActivationToken.expires_at < datetime.utcnow(),
        )
        .delete()
    )
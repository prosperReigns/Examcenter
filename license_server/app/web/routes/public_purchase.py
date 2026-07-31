from flask import Blueprint
from flask import render_template

bp = Blueprint(
    "public_purchase",
    __name__,
)


@bp.get("/purchase/<purchase_number>")
def purchase_status_page(
    purchase_number,
):

    return render_template(

        "activation/status.html",

        purchase_number=purchase_number,

    )
from fastapi import Request

from app.core.config import get_settings

from app.utils.flash import pop_flashes

settings = get_settings()


def template_context(request: Request):

    return {

        "request": request,

        "settings": settings,

        "messages": pop_flashes(request),

    }
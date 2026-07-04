from fastapi import Request


FLASH_SESSION_KEY = "flash_messages"


def flash(request: Request, message: str, category: str = "info") -> None:
    messages = request.session.setdefault(FLASH_SESSION_KEY, [])
    messages.append({"message": message, "category": category})
    request.session[FLASH_SESSION_KEY] = messages


def pop_flashes(request: Request) -> list[dict[str, str]]:
    messages = request.session.pop(FLASH_SESSION_KEY, [])
    return [message for message in messages if isinstance(message, dict)]
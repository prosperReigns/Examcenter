document.addEventListener("DOMContentLoaded", () => {
    const redirectCard = document.querySelector("[data-public-redirect-url]");

    if (!redirectCard) {
        return;
    }

    const redirectUrl = redirectCard.getAttribute("data-public-redirect-url");
    const redirectDelay = Number(
        redirectCard.getAttribute("data-public-redirect-delay") || 900
    );

    if (!redirectUrl) {
        return;
    }

    window.setTimeout(() => {
        window.location.assign(redirectUrl);
    }, redirectDelay);
});
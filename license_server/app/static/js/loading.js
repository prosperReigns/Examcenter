class LoadingManager {

    constructor() {

        this.overlay = null;

        this.createOverlay();

    }

    createOverlay() {

        this.overlay = document.createElement("div");

        this.overlay.id = "loading-overlay";

        this.overlay.innerHTML = `
            <div class="loading-spinner"></div>
            <div class="loading-text">Loading...</div>
        `;

        document.body.appendChild(this.overlay);

    }

    show(message="Loading...") {

        this.overlay.querySelector(".loading-text").textContent = message;

        this.overlay.classList.add("active");

    }

    hide() {

        this.overlay.classList.remove("active");

    }

}

window.Loader = new LoadingManager();
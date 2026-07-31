/*
==========================================================
MODAL SYSTEM
License Management Server
==========================================================
*/

"use strict";

/*
==========================================================
Open Modal
==========================================================
*/

function openModal(id) {

    const modal = document.getElementById(id);

    if (!modal) return;

    modal.classList.add("show");

    document.body.style.overflow = "hidden";

}

/*
==========================================================
Close Modal
==========================================================
*/

function closeModal(id) {

    const modal = document.getElementById(id);

    if (!modal) return;

    modal.classList.remove("show");

    document.body.style.overflow = "";

}

/*
==========================================================
Close Every Modal
==========================================================
*/

function closeAllModals() {

    document.querySelectorAll(".modal").forEach(modal => {

        modal.classList.remove("show");

    });

    document.body.style.overflow = "";

}

/*
==========================================================
Click Outside
==========================================================
*/

document.addEventListener("click", function (event) {

    if (event.target.classList.contains("modal")) {

        event.target.classList.remove("show");

        document.body.style.overflow = "";

    }

});

/*
==========================================================
Escape Key
==========================================================
*/

document.addEventListener("keydown", function (event) {

    if (event.key === "Escape") {

        closeAllModals();

    }

});

/*
==========================================================
Open Button

<button data-modal="deleteModal">

==========================================================
*/

document.addEventListener("click", function (event) {

    const trigger = event.target.closest("[data-modal]");

    if (!trigger) return;

    const modalId = trigger.dataset.modal;

    openModal(modalId);

});

/*
==========================================================
Close Button

<button data-close>

==========================================================
*/

document.addEventListener("click", function (event) {

    if (!event.target.closest("[data-close]")) return;

    const modal = event.target.closest(".modal");

    if (!modal) return;

    modal.classList.remove("show");

    document.body.style.overflow = "";

});

/*
==========================================================
Loading Modal
==========================================================
*/

function showLoadingModal(message = "Processing...") {

    let modal = document.getElementById("loadingModal");

    if (!modal) {

        modal = document.createElement("div");

        modal.id = "loadingModal";

        modal.className = "modal show";

        modal.innerHTML = `
            <div class="modal-content modal-sm">
                <div class="modal-body modal-loading">

                    <div class="modal-spinner"></div>

                    <div class="modal-loading-text">

                        ${message}

                    </div>

                </div>
            </div>
        `;

        document.body.appendChild(modal);

    } else {

        modal.querySelector(".modal-loading-text").textContent = message;

        modal.classList.add("show");

    }

    document.body.style.overflow = "hidden";

}

/*
==========================================================
Hide Loading
==========================================================
*/

function hideLoadingModal() {

    const modal = document.getElementById("loadingModal");

    if (!modal) return;

    modal.classList.remove("show");

    document.body.style.overflow = "";

}

/*
==========================================================
Confirmation Dialog
==========================================================
*/

function confirmAction({

    title = "Confirm",

    message = "Continue?",

    confirmText = "Yes",

    cancelText = "Cancel",

    confirmClass = "btn-danger",

    onConfirm = null

}) {

    const wrapper = document.createElement("div");

    wrapper.className = "modal show";

    wrapper.innerHTML = `

<div class="modal-content modal-sm">

<div class="modal-header">

<div class="modal-title">

${title}

</div>

<button class="modal-close">&times;</button>

</div>

<div class="modal-body">

<p>

${message}

</p>

</div>

<div class="modal-footer">

<button class="btn btn-secondary cancel-btn">

${cancelText}

</button>

<button class="btn ${confirmClass} confirm-btn">

${confirmText}

</button>

</div>

</div>

`;

    document.body.appendChild(wrapper);

    document.body.style.overflow = "hidden";

    wrapper.querySelector(".modal-close").onclick = removeDialog;

    wrapper.querySelector(".cancel-btn").onclick = removeDialog;

    wrapper.onclick = function (e) {

        if (e.target === wrapper) {

            removeDialog();

        }

    };

    wrapper.querySelector(".confirm-btn").onclick = function () {

        if (typeof onConfirm === "function") {

            onConfirm();

        }

        removeDialog();

    };

    function removeDialog() {

        wrapper.remove();

        document.body.style.overflow = "";

    }

}

/*
==========================================================
Delete Confirmation
==========================================================
*/

function confirmDelete(callback) {

    confirmAction({

        title: "Delete Record",

        message: "This action cannot be undone.",

        confirmText: "Delete",

        confirmClass: "btn-danger",

        onConfirm: callback

    });

}

/*
==========================================================
Exports
==========================================================
*/

window.openModal = openModal;

window.closeModal = closeModal;

window.closeAllModals = closeAllModals;

window.showLoadingModal = showLoadingModal;

window.hideLoadingModal = hideLoadingModal;

window.confirmAction = confirmAction;

window.confirmDelete = confirmDelete;
/*
==========================================================
FORM UTILITIES
License Management Server
==========================================================
*/

"use strict";

/*
==========================================================
Trim Inputs
==========================================================
*/

function trimForm(form) {

    form.querySelectorAll("input[type=text], input[type=email], textarea")
        .forEach(input => {

            input.value = input.value.trim();

        });

}

/*
==========================================================
Required Validation
==========================================================
*/

function validateForm(form) {

    let valid = true;

    clearErrors(form);

    form.querySelectorAll("[required]").forEach(field => {

        const value = field.value.trim();

        if (value === "") {

            showError(field, "This field is required.");

            valid = false;

        }

    });

    return valid;

}

/*
==========================================================
Email Validation
==========================================================
*/

function validateEmail(field) {

    if (!field) return true;

    const value = field.value.trim();

    if (value === "") return true;

    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (!regex.test(value)) {

        showError(field, "Enter a valid email address.");

        return false;

    }

    return true;

}

/*
==========================================================
Show Error
==========================================================
*/

function showError(field, message) {

    field.classList.add("is-invalid");

    field.classList.remove("is-valid");

    let feedback = field.parentElement.querySelector(".invalid-feedback");

    if (!feedback) {

        feedback = document.createElement("div");

        feedback.className = "invalid-feedback";

        field.parentElement.appendChild(feedback);

    }

    feedback.textContent = message;

}

/*
==========================================================
Show Success
==========================================================
*/

function showSuccess(field) {

    field.classList.remove("is-invalid");

    field.classList.add("is-valid");

}

/*
==========================================================
Clear Errors
==========================================================
*/

function clearErrors(form) {

    form.querySelectorAll(".invalid-feedback")
        .forEach(item => item.remove());

    form.querySelectorAll(".is-invalid")
        .forEach(item => item.classList.remove("is-invalid"));

}

/*
==========================================================
Disable Button
==========================================================
*/

function disableButton(button) {

    button.disabled = true;

    button.dataset.originalText = button.innerHTML;

    button.classList.add("btn-loading");

    button.innerHTML = "Processing...";

}

/*
==========================================================
Enable Button
==========================================================
*/

function enableButton(button) {

    button.disabled = false;

    button.classList.remove("btn-loading");

    if (button.dataset.originalText) {

        button.innerHTML = button.dataset.originalText;

    }

}

/*
==========================================================
Password Visibility
==========================================================
*/

function togglePassword(id) {

    const input = document.getElementById(id);

    if (!input) return;

    input.type = input.type === "password"
        ? "text"
        : "password";

}

/*
==========================================================
AJAX Submit
==========================================================
*/

async function submitAjax(form, options = {}) {

    trimForm(form);

    if (!validateForm(form)) {

        return false;

    }

    const submitButton = form.querySelector("[type=submit]");

    if (submitButton) {

        disableButton(submitButton);

    }

    showLoadingModal();

    try {

        const response = await fetch(

            options.url || form.action,

            {

                method: options.method || form.method || "POST",

                body: new FormData(form)

            }

        );

        hideLoadingModal();

        if (submitButton) {

            enableButton(submitButton);

        }

        if (!response.ok) {

            throw new Error("Request failed.");

        }

        if (typeof options.success === "function") {

            options.success(await response.json());

        }

    }

    catch (error) {

        hideLoadingModal();

        if (submitButton) {

            enableButton(submitButton);

        }

        if (typeof options.error === "function") {

            options.error(error);

        }

        else {

            alert(error.message);

        }

    }

}

/*
==========================================================
Auto Attach Validation
==========================================================
*/

document.addEventListener("submit", function(event){

    const form = event.target;

    if (!form.matches(".validate")) return;

    trimForm(form);

    if (!validateForm(form)){

        event.preventDefault();

    }

});

/*
==========================================================
Live Validation
==========================================================
*/

document.addEventListener("input", function(event){

    const field = event.target;

    if(field.hasAttribute("required")){

        if(field.value.trim() !== ""){

            field.classList.remove("is-invalid");

            field.classList.add("is-valid");

        }

    }

});

/*
==========================================================
Email Validation
==========================================================
*/

document.addEventListener("blur", function(event){

    const field = event.target;

    if(field.type === "email"){

        validateEmail(field);

    }

}, true);

/*
==========================================================
Exports
==========================================================
*/

window.validateForm = validateForm;

window.submitAjax = submitAjax;

window.showError = showError;

window.showSuccess = showSuccess;

window.clearErrors = clearErrors;

window.disableButton = disableButton;

window.enableButton = enableButton;

window.togglePassword = togglePassword;

document.querySelectorAll("form").forEach(form=>{

    form.addEventListener("submit",()=>{

        Loader.show("Submitting...");

    });

});

document.querySelectorAll("[data-loading]").forEach(button=>{

    button.addEventListener("click",()=>{

        button.disabled=true;

        button.dataset.original=button.innerHTML;

        button.innerHTML="Processing...";

    });

});

async function fetchWithLoader(url,options={}){

    Loader.show();

    try{

        return await fetch(url,options);

    }

    finally{

        Loader.hide();

    }

}
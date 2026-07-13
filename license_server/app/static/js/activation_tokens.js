document.addEventListener(

    "DOMContentLoaded",

    ()=>{

        initializeViewButtons();

        initializeCopyButtons();

        initializeRevokeButtons();

        initializeRegenerateButtons();

        initializeCountdowns();

    }

);

function initializeViewButtons(){

document
.querySelectorAll(
".view-token"
)
.forEach(

button=>{

button.onclick=
async()=>{

let id=
button.dataset.id;

let token=
await api.get(

`/activation-tokens/${id}`

);

populateModal(
token
);

new bootstrap.Modal(

viewTokenModal

).show();

};

}

);

}

function initializeCopyButtons(){

document
.querySelectorAll(
".copy-token"
)
.forEach(

button=>{

button.onclick=
()=>{

copyText(

button.dataset.token

);

};

}

);

}

function initializeRegenerateButtons(){

document
.querySelectorAll(
".regenerate-token"
)
.forEach(

button=>{

button.onclick=
()=>{

regenerateTokenId.value=

button.dataset.id;

new bootstrap.Modal(

regenerateModal

).show();

};

}

);

}

function initializeRevokeButtons(){

document
.querySelectorAll(
".revoke-token"
)
.forEach(

button=>{

button.onclick=
()=>{

revokeTokenId.value=

button.dataset.id;

new bootstrap.Modal(

revokeModal

).show();

};

}

);

}

function initializeCountdowns(){

setInterval(

()=>{

document
.querySelectorAll(
".remaining"
)
.forEach(

element=>{

element.innerHTML=

remainingTime(

element.dataset.expiry

);

}

);

},

60000

);

}
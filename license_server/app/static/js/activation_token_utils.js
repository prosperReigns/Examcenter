class ToastManager {

    constructor() {

        this.toast =
            new bootstrap.Toast(
                document.getElementById(
                    "tokenToast"
                )
            );

        this.message =
            document.getElementById(
                "toastMessage"
            );

    }

    show(
        message,
        type="success"
    ){

        this.message.innerHTML=message;

        this.toast.show();

    }

}

const toast =
    new ToastManager();

class LoadingOverlay{

    constructor(){

        this.overlay=
            document.createElement(
                "div"
            );

        this.overlay.className=
            "loading-overlay";

        this.overlay.innerHTML=`

        <div class="spinner-border text-primary"></div>

        `;

        document.body.appendChild(
            this.overlay
        );

        this.hide();

    }

    show(){

        this.overlay.style.display="flex";

    }

    hide(){

        this.overlay.style.display="none";

    }

}

const loader=
new LoadingOverlay();

async function copyText(text){

    try{

        await navigator.clipboard.writeText(
            text
        );

        toast.show(
            "Copied to clipboard."
        );

    }

    catch{

        toast.show(
            "Unable to copy.",
            "danger"
        );

    }

}

function formatDate(date){

    return new Date(date)
        .toLocaleString();

}

function buildStatusBadge(
    status
){

    switch(status){

        case "active":

            return `<span class="badge bg-success">
            Active
            </span>`;

        case "used":

            return `<span class="badge bg-primary">
            Used
            </span>`;

        case "expired":

            return `<span class="badge bg-danger">
            Expired
            </span>`;

        case "revoked":

            return `<span class="badge bg-secondary">
            Revoked
            </span>`;

        default:

            return `<span class="badge bg-dark">
            Unknown
            </span>`;

    }

}

function remainingTime(
    expiry
){

    let diff=
        new Date(expiry)
        -new Date();

    if(diff<=0){

        return "Expired";

    }

    let hours=
        Math.floor(
            diff/3600000
        );

    let minutes=
        Math.floor(
            (diff%3600000)
            /60000
        );

    return `${hours}h ${minutes}m`;

}
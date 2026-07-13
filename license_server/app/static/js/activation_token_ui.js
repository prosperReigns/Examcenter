function populateModal(
    token
){

    modalSchool.value=
    token.school;

    modalLicense.value=
    token.license;

    modalToken.value=
    token.token;

    modalFingerprint.value=
    token.machine_fingerprint;

    modalStatus.value=
    token.status;

    modalCreated.value=
    formatDate(
        token.created_at
    );

    modalExpires.value=
    formatDate(
        token.expires_at
    );

    modalUsed.value=
    token.used_at
        ?
        formatDate(
            token.used_at
        )
        :
        "-";

}

function updateRow(

    id,

    token

){

    let row=
        document.querySelector(

            `[data-row='${id}']`

        );

    if(!row)
        return;

    row.querySelector(".status")
        .innerHTML=
        buildStatusBadge(
            token.status
        );

}
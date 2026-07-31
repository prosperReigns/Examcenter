function togglePassword() {

    const field = document.getElementById("password");

    if (!field) return;

    field.type =
        field.type === "password"
            ? "text"
            : "password";
}
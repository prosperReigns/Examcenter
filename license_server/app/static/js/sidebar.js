document.addEventListener("DOMContentLoaded", () => {

    const current = window.location.pathname;

    document.querySelectorAll(".sidebar-menu a").forEach(link => {

        if(current === link.getAttribute("href")){

            link.classList.add("active");

        }

    });

});

const button = document.getElementById("menu-toggle");

if(button){

    button.addEventListener("click", () => {

        document.querySelector(".sidebar")
            .classList.toggle("show");

    });

}
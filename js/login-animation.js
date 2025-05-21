




document.addEventListener('DOMContentLoaded', function() {
    // 1. Immediately show all content
    gsap.set("body", { opacity: 1 });
    
    // 2. Get all elements
    const loginContainer = document.querySelector('.login-container');
    const icon = document.querySelector('.fa-user-shield');
    const heading = document.querySelector('h2');
    const subheading = document.querySelector('p');
    const formElements = document.querySelectorAll('.login-container form > *');
    
    // 3. Set initial state (hidden)
    gsap.set([loginContainer, icon, heading, subheading, ...formElements], {
        opacity: 0,
        y: 20
    });
    
    // 4. Create master timeline
    const tl = gsap.timeline();
    
    // 5. Animate everything together
    tl.to(loginContainer, {
        opacity: 1,
        y: 0,
        duration: 0.8,
        ease: "back.out(1.7)"
    })
    .to([icon, heading, subheading], {
        opacity: 1,
        y: 0,
        duration: 0.6,
        stagger: 0.1,
        ease: "power2.out"
    }, "-=0.5") // Overlap with previous animation
    .to(formElements, {
        opacity: 1,
        y: 0,
        duration: 0.4,
        stagger: 0.05,
        ease: "power2.out"
    }, "-=0.3");
    
    // 6. Form validation
    const form = document.getElementById('loginForm');
    const loginBtn = document.getElementById('loginBtn');
    
    if (form && loginBtn) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            } else {
                loginBtn.disabled = true;
                const btnText = loginBtn.querySelector('.btn-text');
                if (btnText) btnText.textContent = 'Authenticating...';
                
                const icon = loginBtn.querySelector('i');
                if (icon) icon.classList.add('d-none');
                
                const spinner = document.createElement('span');
                spinner.className = 'spinner-border spinner-border-sm';
                spinner.setAttribute('aria-hidden', 'true');
                loginBtn.insertBefore(spinner, icon);
            }
            form.classList.add('was-validated');
        });
    }
});
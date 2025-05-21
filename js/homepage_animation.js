document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - initializing page system');
    
    // Initialize pages
    const pages = {
        home: document.querySelector('.home-page'),
        faq: document.querySelector('#faq_page'),
        about: document.querySelector('#about_page')
    };
    
    // Show home page by default
    showPage('home');
    
    // Set up navigation
    document.querySelectorAll('[data-page]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const pageId = this.getAttribute('data-page');
            showPage(pageId);
            window.location.hash = pageId;
        });
    });
    
    // Check URL hash
    if (window.location.hash) {
        const pageId = window.location.hash.substring(1);
        showPage(pageId);
    }
});

function showPage(pageId) {
    console.log('Attempting to show page:', pageId);
    
    // Hide all pages
    document.querySelectorAll('.page').forEach(page => {
        page.style.display = 'none';
        page.classList.remove('active');
    });
    
    // Show requested page
    let targetPage;
    
    switch(pageId) {
        case 'home':
            targetPage = document.querySelector('.home-page');
            break;
        case 'faq_page':
            targetPage = document.querySelector('#faq_page');
            break;
        case 'about_page':
            targetPage = document.querySelector('#about_page');
            break;
        default:
            targetPage = document.querySelector('.home-page');
    }
    
    if (targetPage) {
        console.log('Found page element:', targetPage);
        targetPage.style.display = 'block';
        targetPage.classList.add('active');
        runPageAnimations(pageId);
        updateActiveNav(pageId);
    } else {
        console.error('Page not found:', pageId);
        // Fallback to home
        document.querySelector('.home-page').style.display = 'block';
    }
}

function updateActiveNav(pageId) {
    document.querySelectorAll('.nav-link').forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('data-page') === pageId) {
            link.classList.add('active');
        }
    });
}

function runPageAnimations(pageId) {
    console.log('Running animations for:', pageId);
    
}
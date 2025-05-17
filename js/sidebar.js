document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    const sidebarToggle = document.getElementById('sidebarToggle');

    // Initial state
    let isSidebarVisible = true;

    // Toggle sidebar function
    function toggleSidebar() {
        if (isSidebarVisible) {
            sidebar.style.left = '-100%';
            mainContent.style.marginLeft = '0';
        } else {
            sidebar.style.left = '0';
            mainContent.style.marginLeft = '250px';
        }
        isSidebarVisible = !isSidebarVisible;
    }

    // Add click event listener to toggle button
    sidebarToggle.addEventListener('click', toggleSidebar);

    // Add responsive behavior
    function handleResize() {
        if (window.innerWidth < 768) {
            sidebar.style.left = '-100%';
            mainContent.style.marginLeft = '0';
            isSidebarVisible = false;
        } else {
            sidebar.style.left = '0';
            mainContent.style.marginLeft = '250px';
            isSidebarVisible = true;
        }
    }

    // Listen for window resize
    window.addEventListener('resize', handleResize);
    
    // Initial check
    handleResize();
});
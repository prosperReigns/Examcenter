<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D-Portal</title>
    <!-- Bootstrap 5 CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="css/all.css">
    
    <link rel="stylesheet" href="css/homepage_styles.css">
</head>

<body>
    <!-- Floating background shapes -->
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-transparent py-3">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-graduation-cap me-2"></i>D-Portal
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#" data-page="home"><i class="fas fa-home me-1"></i> Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#faq_page" data-page="faq_page"><i class="fa fa-question-circle me-1"></i> FAQ</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about_page" data-page="about_page"><i class="fas fa-info-circle me-1"></i> About</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content Container -->
    <div id="app-content">
        <!-- Home Page (default) -->
        <section class="page home-page active">
            <div class="hero">
                <div class="container">
                    <div class="text-center">
                        <h1 class="hero-title">D-Portal CBT System</h1>
                        <p class="hero-subtitle">A modern, intuitive platform for conducting computer-based tests in offline environments</p>
                    </div>

                    <!-- Portal Cards -->
                    <div class="row justify-content-center g-4">
                        <div class="col-lg-5 col-md-6">
                            <div class="card portal-card h-100 admin">
                                <div class="card-body text-center p-5">
                                    <div class="card-icon">
                                        <i class="fas fa-user-shield"></i>
                                    </div>
                                    <h3 class="card-title mb-3"> Staff Portal</h3>
                                    <p class="card-text mb-4">Manage questions, exams, and analyze student performance with powerful tools.</p>
                                    <a href="login.php" class="btn btn-primary">
                                        <i class="fas fa-sign-in-alt me-2"></i>Staff Login
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Student Portal -->
                        <div class="col-lg-5 col-md-6">
                            <div class="card portal-card h-100 student">
                                <div class="card-body text-center p-5">
                                    <div class="card-icon">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <h3 class="card-title mb-3">Student Portal</h3>
                                    <p class="card-text mb-4">Take exams in a distraction-free environment with intuitive controls.</p>
                                    <a href="student/register.php" class="btn btn-student">
                                        <i class="fas fa-sign-in-alt me-2"></i>Student Login
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ Page -->
        <section class="page faq-page" id="faq_page">
            <div class="container py-5">
                <div class="text-center mb-5">
                    <h1 class="hero-title">Frequently Asked Questions</h1>
                    <p class="hero-subtitle">Find answers to common questions about our CBT system</p>
                </div>

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="accordion" id="faqAccordion">
                            <!-- FAQ Item 1 -->
                            <div class="accordion-item mb-3 border-0 rounded-3 overflow-hidden shadow-sm">
                                <h2 class="accordion-header" id="headingOne">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                        How does the offline functionality work?
                                    </button>
                                </h2>
                                <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        The D-Portal system uses local storage and service workers to cache all necessary resources. Once initially loaded, the system can function without an internet connection, syncing data when connectivity is restored.
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 2 -->
                            <div class="accordion-item mb-3 border-0 rounded-3 overflow-hidden shadow-sm">
                                <h2 class="accordion-header" id="headingTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                        What browsers are supported?
                                    </button>
                                </h2>
                                <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        D-Portal works best on modern browsers including Chrome, Firefox, Edge, and Safari. For optimal performance, we recommend using the latest version of Safe Examination Browser.
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 3 -->
                            <div class="accordion-item mb-3 border-0 rounded-3 overflow-hidden shadow-sm">
                                <h2 class="accordion-header" id="headingThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                        How secure is the exam environment?
                                    </button>
                                </h2>
                                <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Our system employs multiple security measures including question randomization, Safe Examination Browser's  browser lockdown capabilities, and activity monitoring to ensure exam integrity.
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 4 -->
                            <div class="accordion-item mb-3 border-0 rounded-3 overflow-hidden shadow-sm">
                                <h2 class="accordion-header" id="headingFour">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                        Can I customize the exam interface?
                                    </button>
                                </h2>
                                <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Yes, administrators can customize colors, logos, and certain interface elements to match institutional branding through the admin portal.
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 5 -->
                            <div class="accordion-item mb-3 border-0 rounded-3 overflow-hidden shadow-sm">
                                <h2 class="accordion-header" id="headingFive">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFive">
                                        What types of questions are supported?
                                    </button>
                                </h2>
                                <div id="collapseFive" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        D-Portal supports multiple question types including multiple choice, true/false, fill-in-the-blank, matching, and short answer questions.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- About Page -->
        <section class="page about-page" id="about_page">
            <div class="container py-5">
                <div class="text-center mb-5">
                    <h1 class="hero-title">About D-Portal</h1>
                    <p class="hero-subtitle">Innovative CBT solutions for modern educational needs</p>
                </div>

                <div class="row align-items-center">
                    <div class="col-lg-6 mb-4 mb-lg-0">
                        <div class="about-image p-4">
                            <div class="image-wrapper rounded-4 overflow-hidden shadow">
                                <img src="https://images.unsplash.com/photo-1588072432836-e10032774350?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80" alt="Education Technology" class="img-fluid">
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="about-content p-4">
                            <h3 class="mb-4">Our Mission</h3>
                            <p class="mb-4">D-Portal was created to bridge the gap between technology and education in areas with limited or unreliable internet connectivity. Our mission is to provide a robust, user-friendly computer-based testing platform that works seamlessly both online and offline.</p>
                            
                            <h3 class="mb-4">Key Features</h3>
                            <ul class="feature-list mb-4">
                                <li><i class="fas fa-check-circle text-primary me-2"></i> Offline-first design for reliability</li>
                                <li><i class="fas fa-check-circle text-primary me-2"></i> Intuitive interface for all user levels</li>
                                <li><i class="fas fa-check-circle text-primary me-2"></i> Comprehensive analytics and reporting</li>
                                <li><i class="fas fa-check-circle text-primary me-2"></i> Scalable for institutions of all sizes</li>
                                <li><i class="fas fa-check-circle text-primary me-2"></i> Regular updates and feature additions</li>
                            </ul>
                            
                            <h3 class="mb-4">The Team</h3>
                            <p>D-Portal is developed and maintained by ImadeTech, a software company specializing in educational technology solutions. Our team consists of experienced developers, educators, and UX designers committed to improving learning experiences through technology.</p>
                        </div>
                    </div>
                </div>

                <div class="row mt-5">
                    <div class="col-12">
                        <div class="card features-card border-0 shadow-sm rounded-4 overflow-hidden">
                            <div class="card-body p-5">
                                <div class="row text-center">
                                    <div class="col-md-4 mb-4 mb-md-0">
                                        <div class="feature-icon mb-3">
                                            <i class="fas fa-bolt fa-2x text-primary"></i>
                                        </div>
                                        <h4>Fast Performance</h4>
                                        <p class="mb-0">Optimized for quick loading and smooth operation even on older hardware.</p>
                                    </div>
                                    <div class="col-md-4 mb-4 mb-md-0">
                                        <div class="feature-icon mb-3">
                                            <i class="fas fa-shield-alt fa-2x text-primary"></i>
                                        </div>
                                        <h4>Secure</h4>
                                        <p class="mb-0">Multiple layers of security to protect exam integrity and student data.</p>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="feature-icon mb-3">
                                            <i class="fas fa-sync-alt fa-2x text-primary"></i>
                                        </div>
                                        <h4>Auto-Sync</h4>
                                        <p class="mb-0">Automatically syncs data when internet connection is available.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Footer -->
    <footer class="text-center">
        <div class="container">
            <p>&copy; 2025 D-Portal CBT Portal — A subsidiary of <b>I</b>made<b>T</b>ech.</p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="js/bootstrap.bundle.min.js"></script>
    <!-- GSAP -->
    <script src="js/gsap-public/minified/gsap.min.js"></script>

    <script src="js/animation.js"></script>
    <!-- GSAP Animation Script -->
    <script src="js/homepage_animation.js"></script>
    
    

</script>
</body>
</html>
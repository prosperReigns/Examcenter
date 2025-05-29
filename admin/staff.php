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
                                    <h3 class="card-title mb-3">Admin Portal</h3>
                                    <p class="card-text mb-4">Manage questions, exams, and analyze student performance with powerful tools.</p>
                                    <a href="login.php" class="btn btn-primary">
                                        <i class="fas fa-sign-in-alt me-2"></i>Admin Login
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
                                    <h3 class="card-title mb-3">Teacher Portal</h3>
                                    <p class="card-text mb-4">Take exams in a distraction-free environment with intuitive controls.</p>
                                    <a href="../teacher/login.php" class="btn btn-student">
                                        <i class="fas fa-sign-in-alt me-2"></i>Teacher Login
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
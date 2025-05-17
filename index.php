<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT Application</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .main-container {
            max-width: 800px;
            margin: 100px auto;
            padding: 20px;
            background-color: white;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .btn-container {
            margin-top: 30px;
        }
        .btn {
            margin: 10px;
            padding: 15px 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-container">
            <h1 class="mb-4">Welcome to CBT Application</h1>
            <p class="lead">Choose your role to continue</p>
            
            <div class="btn-container">
                <a href="admin/login.php" class="btn btn-primary btn-lg">Admin Login</a>
                <a href="student/register.php" class="btn btn-success btn-lg">Start Exam</a>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
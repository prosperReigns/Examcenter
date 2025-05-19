<?php
require_once '../db.php';

$conn = Database::getInstance()->getConnection();

// Check if admin table exists, if not create it
$create_table_sql = "CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if (!mysqli_query($conn, $create_table_sql)) {
    die("Error creating table: " . mysqli_error($conn));
}

// Default admin credentials
$default_username = "teacher";
$default_password = "teacher123"; // You should change this in production
$hashed_password = password_hash($default_password, PASSWORD_DEFAULT);

// Check if admin already exists
$check_sql = "SELECT id FROM admins WHERE username = '$default_username'";
$result = mysqli_query($conn, $check_sql);

if (mysqli_num_rows($result) == 0) {
    // Insert default admin
    $insert_sql = "INSERT INTO admins (username, password) VALUES ('$default_username', '$hashed_password')";
    if (mysqli_query($conn, $insert_sql)) {
        echo "Default admin account created successfully!\n";
        echo "Username: $default_username\n";
        echo "Password: $default_password\n";
    } else {
        echo "Error creating admin account: " . mysqli_error($conn);
    }
} else {
    echo "Admin account already exists!";
}
?>
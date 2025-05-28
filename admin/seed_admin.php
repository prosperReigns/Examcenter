<?php
require_once '../db.php';

$conn = Database::getInstance()->getConnection();

// Check if admin table exists, if not create it
$create_table_sql = "CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if (!mysqli_query($conn, $create_table_sql)) {
    die("Error creating table: " . mysqli_error($conn));
}

// Default admin credentials
$default_username = "teacher";
$default_password = "teacher123"; // Change this in production
$hashed_password = password_hash($default_password, PASSWORD_DEFAULT);

// Check if admin already exists
$check_sql = "SELECT id FROM admins WHERE username = ?";
$stmt = mysqli_prepare($conn, $check_sql);
mysqli_stmt_bind_param($stmt, "s", $default_username);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) == 0) {
    // Insert default admin
    $insert_sql = "INSERT INTO admins (username, password, role) VALUES (?, ?, 'admin')";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "ss", $default_username, $hashed_password);

    if (mysqli_stmt_execute($insert_stmt)) {
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

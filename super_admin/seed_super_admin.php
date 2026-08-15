<?php

require_once __DIR__ . '/../db.php';

$conn = Database::getInstance()->getConnection();

/*
|--------------------------------------------------------------------------
| Create super_admins table if it does not exist
|--------------------------------------------------------------------------
*/

$create_table_sql = "
CREATE TABLE IF NOT EXISTS super_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE,
    password VARCHAR(255),
    created_at DATETIME,
    role VARCHAR(50)
)
";

if (!mysqli_query($conn, $create_table_sql)) {
    die(
        "ERROR: Could not create super_admins table: " .
        mysqli_error($conn) .
        PHP_EOL
    );
}

/*
|--------------------------------------------------------------------------
| Default Super Admin
|--------------------------------------------------------------------------
*/

$default_username = "superadmin";
$default_password = "superadmin123";

/*
|--------------------------------------------------------------------------
| Check whether Super Admin already exists
|--------------------------------------------------------------------------
*/

$check_sql = "
    SELECT id
    FROM super_admins
    WHERE username = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $check_sql);

if (!$stmt) {
    die(
        "ERROR: Could not prepare check statement: " .
        mysqli_error($conn) .
        PHP_EOL
    );
}

mysqli_stmt_bind_param(
    $stmt,
    "s",
    $default_username
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) > 0) {

    echo "Super Admin already exists." . PHP_EOL;

    mysqli_stmt_close($stmt);
    exit(0);
}

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| Create Super Admin
|--------------------------------------------------------------------------
*/

$hashed_password = password_hash(
    $default_password,
    PASSWORD_DEFAULT
);

$insert_sql = "
    INSERT INTO super_admins
    (
        username,
        password,
        role,
        created_at
    )
    VALUES
    (?, ?, 'super_admin', NOW())
";

$insert_stmt = mysqli_prepare($conn, $insert_sql);

if (!$insert_stmt) {
    die(
        "ERROR: Could not prepare insert statement: " .
        mysqli_error($conn) .
        PHP_EOL
    );
}

mysqli_stmt_bind_param(
    $insert_stmt,
    "ss",
    $default_username,
    $hashed_password
);

if (!mysqli_stmt_execute($insert_stmt)) {
    die(
        "ERROR: Could not create Super Admin: " .
        mysqli_error($conn) .
        PHP_EOL
    );
}

mysqli_stmt_close($insert_stmt);

echo "Super Admin created successfully." . PHP_EOL;
echo "Username: " . $default_username . PHP_EOL;
echo "Password: " . $default_password . PHP_EOL;

exit(0);
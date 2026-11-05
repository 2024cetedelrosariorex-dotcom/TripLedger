<?php

require_once __DIR__ . '/../app/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only run in the terminal.\n");
}



function readAdminInput(string $prompt): string
{
    echo $prompt;
    $input = fgets(STDIN);

    if ($input === false) {
        exit("ERROR: Unable to read input.\n");
    }

    return trim($input);
}

function readAdminPassword(): string
{
    echo "Enter administrator password (minimum 12 characters): ";

    // Windows-friendly CLI input.
    // The entered password may be visible in the terminal.
    $input = fgets(STDIN);

    if ($input === false) {
        exit("\nERROR: Unable to read password.\n");
    }

    return rtrim($input, "\r\n");
}

echo "\n===================================\n";
echo " TripLedger Administrator Setup\n";
echo "===================================\n\n";

try {
    $pdo = db();

    // Enforce one Administrator account, including
    // inactive Administrator accounts.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM users
         WHERE role = ?"
    );

    $stmt->execute(['Administrator']);

    if ((int) $stmt->fetchColumn() > 0) {
        exit(
            "ERROR: An Administrator account already exists.\n"
        );
    }

    $username = readAdminInput("Enter admin username: ");
    $fullName = readAdminInput("Enter admin full name: ");

    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        exit(
            "ERROR: Username must contain 3-50 letters, numbers, or underscores.\n"
        );
    }

    if ($fullName === '' || mb_strlen($fullName) > 150) {
        exit(
            "ERROR: Full name must contain 1-150 characters.\n"
        );
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE username = ? LIMIT 1"
    );

    $stmt->execute([$username]);

    if ($stmt->fetch()) {
        exit("ERROR: Username already exists.\n");
    }

    $password = readAdminPassword();

    if (strlen($password) < 12 || strlen($password) > 72) {
        exit(
            "ERROR: Administrator password must be 12 to 72 bytes.\n"
        );
    }

    $confirmPassword = readAdminInput(
        "Confirm administrator password: "
    );

    if (!hash_equals($password, $confirmPassword)) {
        exit("ERROR: Passwords do not match.\n");
    }

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    unset($password, $confirmPassword);

    $stmt = $pdo->prepare(
        "INSERT INTO users
            (username, full_name, password_hash, role, is_active)
         VALUES (?, ?, ?, 'Administrator', 1)"
    );

    $stmt->execute([
        $username,
        $fullName,
        $passwordHash
    ]);

    echo "\nSUCCESS: Administrator account created!\n";
    echo "Username: " . $username . "\n";
    echo "Role: Administrator\n";
    echo "Status: Active\n";

} catch (PDOException $e) {
    error_log('Admin setup error: ' . $e->getMessage());

    exit(
        "ERROR: Unable to create Administrator account.\n"
    );
}

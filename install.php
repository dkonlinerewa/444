<?php
// ============================================
// SIMPLEST - Just run this file once
// ============================================

require_once 'config.php';

// SET YOUR DESIRED ADMIN CREDENTIALS HERE
$new_username = 'admin';
$new_password = 'Admin@123'; // CHANGE THIS!
$new_email = 'admin@hidk.in';

// DO NOT EDIT BELOW THIS LINE
$hash = password_hash($new_password, PASSWORD_DEFAULT);

$exists = $db->querySingle("SELECT id FROM admin_users WHERE username = '$new_username'");

if ($exists) {
    $db->exec("UPDATE admin_users SET password_hash = '$hash', email = '$new_email', is_active = 1 WHERE username = '$new_username'");
    echo "✅ Admin UPDATED! Login with: $new_username / $new_password";
} else {
    $staff_id = 'ADMIN-' . date('Y') . rand(100, 999);
    $db->exec("INSERT INTO admin_users (username, password_hash, email, full_name, role, staff_id, is_active) 
              VALUES ('$new_username', '$hash', '$new_email', 'Administrator', 'admin', '$staff_id', 1)");
    echo "✅ Admin CREATED! Login with: $new_username / $new_password";
}

echo "\n\n<a href='admin.php'>Go to Admin Login</a>";
?>
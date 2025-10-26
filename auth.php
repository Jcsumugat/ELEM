<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $system = $_POST['system'] ?? 'elementary';

        if (empty($email) || empty($password)) {
            jsonResponse(false, 'Email and password are required');
        }

        $isElementary = ($system === 'elementary');
        $conn = getDBConnection($isElementary);

        $stmt = $conn->prepare("SELECT id, fullname, password, email, role FROM users WHERE email = ? AND is_active = 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && verifyPassword($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['fullname'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['system'] = $system;
            $_SESSION['login_time'] = date('Y-m-d H:i:s');

            $updateStmt = $conn->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $user['id']);
            $updateStmt->execute();
            $updateStmt->close();

            try {
                logActivity($conn, $user['id'], 'Login', 'User logged in from IP: ' . $_SERVER['REMOTE_ADDR']);
            } catch (Exception $e) {
                // Activity logging failed, but continue
            }

            $redirectPage = 'dashboard.php';

            jsonResponse(true, 'Login successful', ['redirect' => $redirectPage]);
        } else {
            jsonResponse(false, 'Invalid email or password');
        }

        $stmt->close();
        $conn->close();

    } elseif ($action === 'logout') {
        $userId = $_SESSION['user_id'] ?? null;
        $system = $_SESSION['system'] ?? 'elementary';

        if ($userId) {
            $isElementary = ($system === 'elementary');
            $conn = getDBConnection($isElementary);
            logActivity($conn, $userId, 'Logout', 'User logged out');
            $conn->close();
        }

        session_unset();
        session_destroy();

        jsonResponse(true, 'Logged out successfully', ['redirect' => 'login.php']);
    } elseif ($action === 'register') {
        $email = sanitizeInput($_POST['email'] ?? '');
        $fullname = sanitizeInput($_POST['fullname'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $role = sanitizeInput($_POST['role'] ?? 'Teacher');
        $system = $_POST['system'] ?? 'elementary';

        if (empty($email) || empty($fullname) || empty($password)) {
            jsonResponse(false, 'All fields are required');
        }

        if ($password !== $confirmPassword) {
            jsonResponse(false, 'Passwords do not match');
        }

        if (!isValidEmail($email)) {
            jsonResponse(false, 'Invalid email address');
        }

        if (strlen($password) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters long');
        }

        $isElementary = ($system === 'elementary');
        $conn = getDBConnection($isElementary);

        // Check if email or fullname already exists
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE fullname = ? OR email = ?");
        $checkStmt->bind_param("ss", $fullname, $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            jsonResponse(false, 'Name or email already exists');
        }
        $checkStmt->close();

        $hashedPassword = hashPassword($password);

        // Insert new user - adjust columns based on your actual table structure
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param("ssss", $fullname, $email, $hashedPassword, $role);

        if ($stmt->execute()) {
            $userId = $stmt->insert_id;
            logActivity($conn, $userId, 'Registration', 'New user registered');
            jsonResponse(true, 'Registration successful. Please login.');
        } else {
            jsonResponse(false, 'Registration failed. Please try again.');
        }

        $stmt->close();
        $conn->close();
    } elseif ($action === 'change_password') {
        requireLogin();

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $userId = $_SESSION['user_id'];
        $system = $_SESSION['system'] ?? 'elementary';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            jsonResponse(false, 'All fields are required');
        }

        if ($newPassword !== $confirmPassword) {
            jsonResponse(false, 'New passwords do not match');
        }

        if (strlen($newPassword) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters long');
        }

        $isElementary = ($system === 'elementary');
        $conn = getDBConnection($isElementary);

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user || !verifyPassword($currentPassword, $user['password'])) {
            jsonResponse(false, 'Current password is incorrect');
        }

        $hashedPassword = hashPassword($newPassword);

        $updateStmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("si", $hashedPassword, $userId);

        if ($updateStmt->execute()) {
            logActivity($conn, $userId, 'Password Change', 'User changed password');
            jsonResponse(true, 'Password changed successfully');
        } else {
            jsonResponse(false, 'Failed to change password');
        }

        $updateStmt->close();
        $conn->close();
    } elseif ($action === 'forgot_password') {
        $email = sanitizeInput($_POST['email'] ?? '');
        $system = $_POST['system'] ?? 'elementary';

        if (empty($email)) {
            jsonResponse(false, 'Email is required');
        }

        if (!isValidEmail($email)) {
            jsonResponse(false, 'Invalid email address');
        }

        $isElementary = ($system === 'elementary');
        $conn = getDBConnection($isElementary);

        $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE email = ? AND is_active = 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            $newPassword = generatePassword(10);
            $hashedPassword = hashPassword($newPassword);

            $updateStmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("si", $hashedPassword, $user['id']);
            $updateStmt->execute();
            $updateStmt->close();

            logActivity($conn, $user['id'], 'Password Reset', 'Password reset requested');

            jsonResponse(true, 'A temporary password has been generated. Please contact your administrator.', [
                'temp_password' => $newPassword
            ]);
        } else {
            jsonResponse(false, 'Email address not found');
        }

        $conn->close();
    } elseif ($action === 'check_session') {
        if (isLoggedIn()) {
            $system = $_SESSION['system'] ?? 'elementary';
            jsonResponse(true, 'Session active', [
                'user_id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'fullname' => $_SESSION['fullname'],
                'role' => $_SESSION['role'],
                'system' => $system
            ]);
        } else {
            jsonResponse(false, 'Session expired');
        }
    } else {
        jsonResponse(false, 'Invalid action');
    }
} else {
    jsonResponse(false, 'Invalid request method');
}

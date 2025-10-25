<?php
// Database configuration for College System
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'attendance_system');

// Database configuration for Elementary System
define('ELEM_DB_HOST', 'localhost');
define('ELEM_DB_USER', 'root');
define('ELEM_DB_PASS', '');
define('ELEM_DB_NAME', 'elementary_attendance');

// Get database connection
function getDBConnection($isElementary = false) {
    if ($isElementary) {
        $conn = new mysqli(ELEM_DB_HOST, ELEM_DB_USER, ELEM_DB_PASS, ELEM_DB_NAME);
    } else {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    }
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

// Require login - redirect to login page if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && (
        $_SESSION['role'] === 'admin' || 
        $_SESSION['role'] === 'Admin'
    );
}

// Check if user is teacher
function isTeacher() {
    return isset($_SESSION['role']) && (
        $_SESSION['role'] === 'teacher' || 
        $_SESSION['role'] === 'Teacher'
    );
}

// Check if user is staff
function isStaff() {
    return isset($_SESSION['role']) && (
        $_SESSION['role'] === 'staff' || 
        $_SESSION['role'] === 'Staff'
    );
}

// Sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// JSON response helper
function jsonResponse($success, $message, $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

// Format date for display
function formatDate($date) {
    if (empty($date)) return 'N/A';
    return date('M d, Y', strtotime($date));
}

// Format time for display
function formatTime($time) {
    if (empty($time)) return 'N/A';
    return date('h:i A', strtotime($time));
}

// Get current academic year
function getCurrentAcademicYear() {
    $currentYear = date('Y');
    $currentMonth = date('n');
    
    // Academic year starts in August
    if ($currentMonth >= 8) {
        return $currentYear . '-' . ($currentYear + 1);
    } else {
        return ($currentYear - 1) . '-' . $currentYear;
    }
}

// Get current school year for elementary
function getCurrentSchoolYear() {
    $currentYear = date('Y');
    $currentMonth = date('n');
    
    // School year starts in June for elementary
    if ($currentMonth >= 6) {
        return $currentYear . '-' . ($currentYear + 1);
    } else {
        return ($currentYear - 1) . '-' . $currentYear;
    }
}

// Calculate attendance percentage
function calculateAttendancePercentage($present, $total) {
    if ($total == 0) return 0;
    return round(($present / $total) * 100, 2);
}

// Generate student ID for college
function generateStudentID($conn, $year) {
    $prefix = $year;
    $query = "SELECT student_id FROM students WHERE student_id LIKE '$prefix%' ORDER BY student_id DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastID = intval(substr($row['student_id'], -4));
        $newID = $prefix . '-' . str_pad($lastID + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $newID = $prefix . '-0001';
    }
    
    return $newID;
}

// Generate student ID for elementary
function generateElementaryStudentID($conn, $gradeLevel) {
    $year = date('Y');
    $prefix = $year . '-' . str_pad($gradeLevel, 2, '0', STR_PAD_LEFT);
    
    $query = "SELECT student_id FROM students WHERE student_id LIKE '$prefix%' ORDER BY student_id DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastID = intval(substr($row['student_id'], -3));
        $newID = $prefix . str_pad($lastID + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $newID = $prefix . '001';
    }
    
    return $newID;
}

// Log activity
function logActivity($conn, $userId, $action, $details = '') {
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iss", $userId, $action, $details);
        $stmt->execute();
        $stmt->close();
    }
}

// Check if attendance already exists
function attendanceExists($conn, $studentId, $date) {
    $stmt = $conn->prepare("SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ?");
    $stmt->bind_param("is", $studentId, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

// Get attendance status
function getAttendanceStatus($timeIn, $cutoffTime = '08:00:00') {
    if (empty($timeIn)) {
        return 'Absent';
    }
    
    if (strtotime($timeIn) <= strtotime($cutoffTime)) {
        return 'Present';
    } else {
        return 'Late';
    }
}

// Validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Generate random password
function generatePassword($length = 8) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

// Hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Get user full name
function getUserFullName($userId, $conn) {
    $stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['fullname'];
    }
    
    return 'Unknown User';
}

// Upload file helper
function uploadFile($file, $targetDir, $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf']) {
    $fileName = basename($file["name"]);
    $targetFilePath = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
    
    if (in_array($fileType, $allowedTypes)) {
        if (move_uploaded_file($file["tmp_name"], $targetFilePath)) {
            return $fileName;
        }
    }
    
    return false;
}

// Delete file helper
function deleteFile($filePath) {
    if (file_exists($filePath)) {
        return unlink($filePath);
    }
    return false;
}

// Pagination helper
function paginate($totalRecords, $recordsPerPage, $currentPage) {
    $totalPages = ceil($totalRecords / $recordsPerPage);
    $offset = ($currentPage - 1) * $recordsPerPage;
    
    return [
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'offset' => $offset,
        'records_per_page' => $recordsPerPage
    ];
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
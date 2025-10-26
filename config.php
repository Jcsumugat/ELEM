<?php
// Database configuration for Elementary Attendance System
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'elementary_attendance');

// Get database connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
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
    return isset($_SESSION['user_id']) && isset($_SESSION['email']);
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
        $_SESSION['role'] === 'Admin'
    );
}

// Check if user is teacher
function isTeacher() {
    return isset($_SESSION['role']) && (
        $_SESSION['role'] === 'Teacher'
    );
}

// Check if user is staff
function isStaff() {
    return isset($_SESSION['role']) && (
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

// Get current school year for elementary (starts in June)
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

// Generate student ID for elementary
// Format: YYYY-GL-###
// Example: 2025-01-001 (Year-Grade Level-Sequence)
function generateStudentID($conn, $gradeLevel) {
    $year = date('Y');
    $prefix = $year . '-' . str_pad($gradeLevel, 2, '0', STR_PAD_LEFT);
    
    $query = "SELECT student_id FROM students WHERE student_id LIKE '$prefix%' ORDER BY student_id DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastID = intval(substr($row['student_id'], -3));
        $newID = $prefix . '-' . str_pad($lastID + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $newID = $prefix . '-001';
    }
    
    return $newID;
}

// Log activity
function logActivity($conn, $userId, $action, $description = '') {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isss", $userId, $action, $description, $ipAddress);
        $stmt->execute();
        $stmt->close();
    }
}

// Check if attendance already exists for a student on a specific date
function attendanceExists($conn, $studentId, $date) {
    $stmt = $conn->prepare("SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ?");
    $stmt->bind_param("is", $studentId, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

// Check if subject attendance already exists
function subjectAttendanceExists($conn, $studentId, $subjectId, $date, $period = null) {
    if ($period) {
        $stmt = $conn->prepare("SELECT id FROM attendance_subjects WHERE student_id = ? AND subject_id = ? AND attendance_date = ? AND period = ?");
        $stmt->bind_param("iiss", $studentId, $subjectId, $date, $period);
    } else {
        $stmt = $conn->prepare("SELECT id FROM attendance_subjects WHERE student_id = ? AND subject_id = ? AND attendance_date = ?");
        $stmt->bind_param("iis", $studentId, $subjectId, $date);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

// Get attendance status based on time
function getAttendanceStatus($timeIn, $cutoffTime = '07:30:00') {
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
    
    $stmt->close();
    return 'Unknown User';
}

// Get grade level name
function getGradeLevelName($gradeLevelId, $conn) {
    $stmt = $conn->prepare("SELECT grade_name FROM grade_levels WHERE id = ?");
    $stmt->bind_param("i", $gradeLevelId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['grade_name'];
    }
    
    $stmt->close();
    return 'Unknown Grade';
}

// Get section name
function getSectionName($sectionId, $conn) {
    $stmt = $conn->prepare("SELECT name FROM sections WHERE id = ?");
    $stmt->bind_param("i", $sectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['name'];
    }
    
    $stmt->close();
    return 'Unknown Section';
}

// Upload file helper
function uploadFile($file, $targetDir, $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf']) {
    // Create directory if it doesn't exist
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $fileName = uniqid() . '_' . basename($file["name"]);
    $targetFilePath = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
    
    // Check file size (5MB max)
    if ($file["size"] > 5000000) {
        return false;
    }
    
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
    $currentPage = max(1, min($currentPage, $totalPages)); // Ensure valid page
    $offset = ($currentPage - 1) * $recordsPerPage;
    
    return [
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'offset' => max(0, $offset),
        'records_per_page' => $recordsPerPage,
        'total_records' => $totalRecords
    ];
}

// Get grade levels array
function getGradeLevels($conn) {
    $query = "SELECT id, grade_number, grade_name FROM grade_levels WHERE is_active = 1 ORDER BY grade_number";
    $result = $conn->query($query);
    
    $gradeLevels = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $gradeLevels[] = $row;
        }
    }
    
    return $gradeLevels;
}

// Get sections by grade level
function getSectionsByGradeLevel($conn, $gradeLevelId) {
    $stmt = $conn->prepare("SELECT id, name, room_number FROM sections WHERE grade_level_id = ? AND is_active = 1 ORDER BY name");
    $stmt->bind_param("i", $gradeLevelId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sections = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $sections[] = $row;
        }
    }
    
    $stmt->close();
    return $sections;
}

// Get active subjects
function getActiveSubjects($conn) {
    $query = "SELECT id, code, name FROM subjects WHERE is_active = 1 ORDER BY name";
    $result = $conn->query($query);
    
    $subjects = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
    }
    
    return $subjects;
}

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set default character encoding
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
?>
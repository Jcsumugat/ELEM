<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_records') {
        $date_from = sanitizeInput($_POST['date_from'] ?? '');
        $date_to = sanitizeInput($_POST['date_to'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $year_level = sanitizeInput($_POST['year_level'] ?? '');
        $section_id = (int)($_POST['section_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        $search = sanitizeInput($_POST['search'] ?? '');
        
        $query = "SELECT 
            a.id,
            a.attendance_date,
            a.time_in,
            a.status,
            a.remarks,
            s.student_id,
            CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as student_name,
            d.code as department_code,
            c.code as course_code,
            s.year_level,
            sec.name as section_name
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            LEFT JOIN departments d ON s.department_id = d.id
            LEFT JOIN courses c ON s.course_id = c.id
            LEFT JOIN sections sec ON s.section_id = sec.id
            WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($date_from && $date_to) {
            $query .= " AND a.attendance_date BETWEEN ? AND ?";
            $params[] = $date_from;
            $params[] = $date_to;
            $types .= "ss";
        }
        
        if ($department_id > 0) {
            $query .= " AND s.department_id = ?";
            $params[] = $department_id;
            $types .= "i";
        }
        
        if ($course_id > 0) {
            $query .= " AND s.course_id = ?";
            $params[] = $course_id;
            $types .= "i";
        }
        
        if ($year_level) {
            $query .= " AND s.year_level = ?";
            $params[] = $year_level;
            $types .= "s";
        }
        
        if ($section_id > 0) {
            $query .= " AND s.section_id = ?";
            $params[] = $section_id;
            $types .= "i";
        }
        
        if ($status) {
            $query .= " AND a.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        if ($search) {
            $query .= " AND (s.student_id LIKE ? OR s.firstname LIKE ? OR s.lastname LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "sss";
        }
        
        $query .= " ORDER BY a.attendance_date DESC, s.lastname, s.firstname";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        
        jsonResponse(true, 'Records loaded', $records);
    }
    
    if ($action === 'update_record') {
        $id = (int)$_POST['id'];
        $status = sanitizeInput($_POST['status']);
        $time_in = sanitizeInput($_POST['time_in']);
        $remarks = sanitizeInput($_POST['remarks']);
        
        $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param("sssi", $status, $time_in, $remarks, $id);
        
        if ($stmt->execute()) {
            jsonResponse(true, 'Record updated successfully');
        } else {
            jsonResponse(false, 'Failed to update record');
        }
    }
    
    if ($action === 'delete_record') {
        $id = (int)$_POST['id'];
        
        $stmt = $conn->prepare("DELETE FROM attendance WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            jsonResponse(true, 'Record deleted successfully');
        } else {
            jsonResponse(false, 'Failed to delete record');
        }
    }
    
    if ($action === 'get_record') {
        $id = (int)$_POST['id'];
        
        $stmt = $conn->prepare("SELECT * FROM attendance WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $record = $result->fetch_assoc();
        
        if ($record) {
            jsonResponse(true, 'Record found', $record);
        } else {
            jsonResponse(false, 'Record not found');
        }
    }
    
    if ($action === 'export_csv') {
        $date_from = sanitizeInput($_POST['date_from'] ?? '');
        $date_to = sanitizeInput($_POST['date_to'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $year_level = sanitizeInput($_POST['year_level'] ?? '');
        $section_id = (int)($_POST['section_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        
        $query = "SELECT 
            a.attendance_date as 'Date',
            s.student_id as 'Student ID',
            CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as 'Student Name',
            d.code as 'Department',
            c.code as 'Course',
            s.year_level as 'Year Level',
            sec.name as 'Section',
            a.status as 'Status',
            a.time_in as 'Time In',
            a.remarks as 'Remarks'
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            LEFT JOIN departments d ON s.department_id = d.id
            LEFT JOIN courses c ON s.course_id = c.id
            LEFT JOIN sections sec ON s.section_id = sec.id
            WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($date_from && $date_to) {
            $query .= " AND a.attendance_date BETWEEN ? AND ?";
            $params[] = $date_from;
            $params[] = $date_to;
            $types .= "ss";
        }
        
        if ($department_id > 0) {
            $query .= " AND s.department_id = ?";
            $params[] = $department_id;
            $types .= "i";
        }
        
        if ($course_id > 0) {
            $query .= " AND s.course_id = ?";
            $params[] = $course_id;
            $types .= "i";
        }
        
        if ($year_level) {
            $query .= " AND s.year_level = ?";
            $params[] = $year_level;
            $types .= "s";
        }
        
        if ($section_id > 0) {
            $query .= " AND s.section_id = ?";
            $params[] = $section_id;
            $types .= "i";
        }
        
        if ($status) {
            $query .= " AND a.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        $query .= " ORDER BY a.attendance_date DESC, s.lastname, s.firstname";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="attendance_records_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add headers
        $firstRow = $result->fetch_assoc();
        if ($firstRow) {
            fputcsv($output, array_keys($firstRow));
            fputcsv($output, $firstRow);
            
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit;
    }
}

// Get departments for dropdown
$departments = $conn->query("SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY name");

// Get sections for dropdown
$sections = $conn->query("SELECT id, name FROM sections WHERE is_active = 1 ORDER BY name");

// Get statistics
$today = date('Y-m-d');
$stats = [];

$stats['today_present'] = $conn->query("SELECT COUNT(DISTINCT student_id) as count FROM attendance WHERE attendance_date = '$today' AND status IN ('Present', 'Late')")->fetch_assoc()['count'];
$stats['today_absent'] = $conn->query("SELECT COUNT(DISTINCT student_id) as count FROM attendance WHERE attendance_date = '$today' AND status = 'Absent'")->fetch_assoc()['count'];
$stats['today_late'] = $conn->query("SELECT COUNT(DISTINCT student_id) as count FROM attendance WHERE attendance_date = '$today' AND status = 'Late'")->fetch_assoc()['count'];

$currentMonth = date('Y-m');
$stats['month_records'] = $conn->query("SELECT COUNT(*) as count FROM attendance WHERE DATE_FORMAT(attendance_date, '%Y-%m') = '$currentMonth'")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Records - Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.present {
            background: rgba(0, 208, 132, 0.1);
            color: var(--success);
        }

        .stat-icon.absent {
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
        }

        .stat-icon.late {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }

        .stat-icon.total {
            background: rgba(74, 144, 226, 0.1);
            color: var(--primary-blue);
        }

        .stat-info h4 {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--text-light);
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }

        .filter-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-title {
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            padding: 10px 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .filter-group select option {
            background: var(--card-bg);
            color: var(--text-light);
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(74, 144, 226, 0.3);
        }

        .btn-reset {
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-reset:hover {
            border-color: var(--accent-green);
        }

        .btn-export {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--accent-green), #00a86b);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            margin-left: auto;
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 208, 132, 0.3);
        }

        .records-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 25px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
        }

        .search-box {
            position: relative;
            width: 300px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 40px 10px 15px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .search-box i {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .records-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .records-table thead th {
            background: rgba(157, 78, 221, 0.1);
            color: var(--text-secondary);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .records-table tbody td {
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .records-table tbody tr:hover {
            background: rgba(157, 78, 221, 0.05);
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-block;
        }

        .status-badge.status-present {
            background: rgba(0, 208, 132, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .status-badge.status-late {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .status-badge.status-absent {
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
            border: 1px solid var(--error);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
        }

        .btn-edit {
            background: rgba(74, 144, 226, 0.1);
            color: var(--primary-blue);
            border: 1px solid var(--primary-blue);
        }

        .btn-edit:hover {
            background: var(--primary-blue);
            color: white;
        }

        .btn-delete {
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
            border: 1px solid var(--error);
        }

        .btn-delete:hover {
            background: var(--error);
            color: white;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-header h2 {
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-modal {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: var(--error);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .form-group select option {
            background: var(--card-bg);
            color: var(--text-light);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .btn-submit {
            flex: 1;
            padding: 12px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(74, 144, 226, 0.3);
        }

        .btn-cancel {
            flex: 1;
            padding: 12px;
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
            border: 2px solid var(--error);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: var(--error);
            color: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .pagination-info {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Attendance System</h2>
                <p><?php echo htmlspecialchars($_SESSION['role']); ?></p>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item" onclick="location.href='dashboard.php'">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="location.href='students.php'">
                    <i class="fas fa-users"></i>
                    <span>Students</span>
                </div>
                <div class="nav-item" onclick="location.href='attendance.php'">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Mark Attendance</span>
                </div>
                <div class="nav-item active">
                    <i class="fas fa-history"></i>
                    <span>Attendance Records</span>
                </div>
                <div class="nav-item" onclick="location.href='reports.php'">
                    <i class="fas fa-file-alt"></i>
                    <span>Reports</span>
                </div>
            </nav>

            <div class="sidebar-footer">
                <button class="logout-btn" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </aside>

        <main class="main-content">
            <div class="header">
                <h1 class="page-title">Attendance Records</h1>
                <div class="header-actions">
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['fullname'], 0, 2)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
                    </div>
                </div>
            </div>

            <div id="message-container"></div>

            <!-- Statistics -->
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-icon present">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Present Today</h4>
                        <p><?php echo $stats['today_present']; ?></p>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="stat-icon late">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Late Today</h4>
                        <p><?php echo $stats['today_late']; ?></p>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="stat-icon absent">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Absent Today</h4>
                        <p><?php echo $stats['today_absent']; ?></p>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="stat-icon total">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Month Records</h4>
                        <p><?php echo $stats['month_records']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3 class="filter-title">
                        <i class="fas fa-filter"></i>
                        Filter Records
                    </h3>
                </div>

                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" id="date_from" value="<?php echo date('Y-m-01'); ?>">
                    </div>

                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" id="date_to" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="filter-group">
                        <label>Department</label>
                        <select id="filter_department" onchange="loadFilterCourses()">
                            <option value="">All Departments</option>
                            <?php 
                            $departments->data_seek(0);
                            while ($dept = $departments->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['code']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Course</label>
                        <select id="filter_course">
                            <option value="">All Courses</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Year Level</label>
                        <select id="filter_year">
                            <option value="">All Years</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Section</label>
                        <select id="filter_section">
                            <option value="">All Sections</option>
                            <?php 
                            $sections->data_seek(0);
                            while ($section = $sections->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $section['id']; ?>">
                                    <?php echo htmlspecialchars($section['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Status</label>
                        <select id="filter_status">
                            <option value="">All Status</option>
                            <option value="Present">Present</option>
                            <option value="Late">Late</option>
                            <option value="Absent">Absent</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button class="btn-filter" onclick="applyFilters()">
                        <i class="fas fa-search"></i>
                        Apply Filters
                    </button>
                    <button class="btn-reset" onclick="resetFilters()">
                        <i class="fas fa-redo"></i>
                        Reset
                    </button>
                    <button class="btn-export" onclick="exportToCSV()">
                        <i class="fas fa-file-export"></i>
                        Export to CSV
                    </button>
                </div>
            </div>

            <!-- Records Section -->
            <div class="records-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-list"></i>
                        <span id="recordsCount">All Records</span>
                    </h3>
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="Search student...">
                        <i class="fas fa-search"></i>
                    </div>
                </div>

                <div id="recordsTableContainer">
                    <div class="loading">
                        <i class="fas fa-spinner"></i>
                        <p>Loading records...</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-edit"></i>
                    Edit Attendance Record
                </h2>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editForm">
                <input type="hidden" id="edit_id">
                
                <div class="form-group">
                    <label>Status *</label>
                    <select id="edit_status" required>
                        <option value="Present">Present</option>
                        <option value="Late">Late</option>
                        <option value="Absent">Absent</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Time In</label>
                    <input type="time" id="edit_time_in">
                </div>
                
                <div class="form-group">
                    <label>Remarks</label>
                    <input type="text" id="edit_remarks" placeholder="Optional remarks...">
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Update Record
                    </button>
                    <button type="button" class="btn-cancel" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentRecords = [];
        let filteredRecords = [];

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }

        function showMessage(type, message) {
            const container = document.getElementById('message-container');
            const messageBox = document.createElement('div');
            messageBox.className = `message-box ${type}`;
            messageBox.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            
            container.innerHTML = '';
            container.appendChild(messageBox);
            
            setTimeout(() => {
                messageBox.style.opacity = '0';
                setTimeout(() => messageBox.remove(), 300);
            }, 3000);
        }

        function loadFilterCourses() {
            const departmentId = document.getElementById('filter_department').value;
            const courseSelect = document.getElementById('filter_course');
            
            if (!departmentId) {
                courseSelect.innerHTML = '<option value="">All Courses</option>';
                return;
            }
            
            courseSelect.innerHTML = '<option value="">Loading...</option>';
            
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_courses');
            formData.append('department_id', departmentId);
            
            fetch('students.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    courseSelect.innerHTML = '<option value="">All Courses</option>';
                    data.data.forEach(course => {
                        const option = document.createElement('option');
                        option.value = course.id;
                        option.textContent = course.code;
                        courseSelect.appendChild(option);
                    });
                } else {
                    courseSelect.innerHTML = '<option value="">No courses found</option>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                courseSelect.innerHTML = '<option value="">Error loading courses</option>';
            });
        }

        function applyFilters() {
            const container = document.getElementById('recordsTableContainer');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><p>Loading records...</p></div>';
            
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_records');
            formData.append('date_from', document.getElementById('date_from').value);
            formData.append('date_to', document.getElementById('date_to').value);
            formData.append('department_id', document.getElementById('filter_department').value);
            formData.append('course_id', document.getElementById('filter_course').value);
            formData.append('year_level', document.getElementById('filter_year').value);
            formData.append('section_id', document.getElementById('filter_section').value);
            formData.append('status', document.getElementById('filter_status').value);
            formData.append('search', document.getElementById('searchInput').value);
            
            fetch('records.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentRecords = data.data;
                    filteredRecords = data.data;
                    renderRecordsTable();
                    document.getElementById('recordsCount').textContent = `${data.data.length} Record(s) Found`;
                    showMessage('success', `Loaded ${data.data.length} records`);
                } else {
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>Error</h3>
                            <p>${data.message}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error</h3>
                        <p>Failed to load records</p>
                    </div>
                `;
            });
        }

        function renderRecordsTable() {
            const container = document.getElementById('recordsTableContainer');
            
            if (filteredRecords.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No Records Found</h3>
                        <p>Try adjusting your filters or date range</p>
                    </div>
                `;
                return;
            }
            
            let html = `
                <table class="records-table">
                    <thead>
                        <tr>
                            <th style="width: 5%">#</th>
                            <th style="width: 10%">Date</th>
                            <th style="width: 10%">Student ID</th>
                            <th style="width: 20%">Student Name</th>
                            <th style="width: 8%">Dept</th>
                            <th style="width: 8%">Course</th>
                            <th style="width: 8%">Year</th>
                            <th style="width: 8%">Section</th>
                            <th style="width: 10%">Status</th>
                            <th style="width: 8%">Time In</th>
                            <th style="width: 15%">Remarks</th>
                            <th style="width: 10%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            filteredRecords.forEach((record, index) => {
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${formatDate(record.attendance_date)}</td>
                        <td>${record.student_id}</td>
                        <td>${record.student_name}</td>
                        <td>${record.department_code || 'N/A'}</td>
                        <td>${record.course_code || 'N/A'}</td>
                        <td>${record.year_level}</td>
                        <td>${record.section_name || 'N/A'}</td>
                        <td>
                            <span class="status-badge status-${record.status.toLowerCase()}">
                                ${record.status}
                            </span>
                        </td>
                        <td>${record.time_in || 'N/A'}</td>
                        <td>${record.remarks || '-'}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-edit" onclick="editRecord(${record.id})" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-icon btn-delete" onclick="deleteRecord(${record.id})" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
            `;

            container.innerHTML = html;
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return date.toLocaleDateString('en-US', options);
        }

        function editRecord(id) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_record');
            formData.append('id', id);
            
            fetch('records.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const record = data.data;
                    document.getElementById('edit_id').value = record.id;
                    document.getElementById('edit_status').value = record.status;
                    document.getElementById('edit_time_in').value = record.time_in || '';
                    document.getElementById('edit_remarks').value = record.remarks || '';
                    document.getElementById('editModal').classList.add('active');
                } else {
                    showMessage('error', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('error', 'Failed to load record');
            });
        }

        function deleteRecord(id) {
            if (!confirm('Are you sure you want to delete this record?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'delete_record');
            formData.append('id', id);
            
            fetch('records.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showMessage(data.success ? 'success' : 'error', data.message);
                if (data.success) {
                    applyFilters();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('error', 'Failed to delete record');
            });
        }

        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function resetFilters() {
            document.getElementById('date_from').value = '<?php echo date('Y-m-01'); ?>';
            document.getElementById('date_to').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('filter_department').value = '';
            document.getElementById('filter_course').innerHTML = '<option value="">All Courses</option>';
            document.getElementById('filter_year').value = '';
            document.getElementById('filter_section').value = '';
            document.getElementById('filter_status').value = '';
            document.getElementById('searchInput').value = '';
            applyFilters();
        }

        function exportToCSV() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'records.php';
            
            const fields = {
                'ajax': '1',
                'action': 'export_csv',
                'date_from': document.getElementById('date_from').value,
                'date_to': document.getElementById('date_to').value,
                'department_id': document.getElementById('filter_department').value,
                'course_id': document.getElementById('filter_course').value,
                'year_level': document.getElementById('filter_year').value,
                'section_id': document.getElementById('filter_section').value,
                'status': document.getElementById('filter_status').value
            };
            
            for (const key in fields) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            }
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            
            showMessage('success', 'Exporting records to CSV...');
        }

        // Handle edit form submission
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'update_record');
            formData.append('id', document.getElementById('edit_id').value);
            formData.append('status', document.getElementById('edit_status').value);
            formData.append('time_in', document.getElementById('edit_time_in').value);
            formData.append('remarks', document.getElementById('edit_remarks').value);
            
            fetch('records.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showMessage(data.success ? 'success' : 'error', data.message);
                if (data.success) {
                    closeModal();
                    applyFilters();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('error', 'Failed to update record');
            });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            
            if (searchTerm === '') {
                filteredRecords = currentRecords;
            } else {
                filteredRecords = currentRecords.filter(record => 
                    record.student_id.toLowerCase().includes(searchTerm) ||
                    record.student_name.toLowerCase().includes(searchTerm)
                );
            }
            
            renderRecordsTable();
            document.getElementById('recordsCount').textContent = `${filteredRecords.length} Record(s) Found`;
        });

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Auto-disable time input when status is Absent
        document.getElementById('edit_status').addEventListener('change', function() {
            const timeInput = document.getElementById('edit_time_in');
            if (this.value === 'Absent') {
                timeInput.value = '';
                timeInput.disabled = true;
            } else {
                timeInput.disabled = false;
            }
        });

        // Load records on page load
        window.addEventListener('DOMContentLoaded', function() {
            applyFilters();
        });
    </script>
</body>
</html>
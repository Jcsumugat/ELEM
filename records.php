<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    ob_clean();
    $action = $_POST['action'] ?? '';

    if ($action === 'get_sections') {
        $grade_id = (int)$_POST['grade_id'];
        $stmt = $conn->prepare("SELECT id, name FROM sections WHERE grade_level_id = ? AND is_active = 1 ORDER BY name");
        $stmt->bind_param("i", $grade_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $sections = [];
        while ($row = $result->fetch_assoc()) {
            $sections[] = $row;
        }
        jsonResponse(true, 'Sections found', $sections);
        exit;
    }

    if ($action === 'get_records') {
        $date_from = sanitizeInput($_POST['date_from'] ?? '');
        $date_to = sanitizeInput($_POST['date_to'] ?? '');
        $grade_level_id = (int)($_POST['grade_level_id'] ?? 0);
        $section_id = (int)($_POST['section_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        $search = sanitizeInput($_POST['search'] ?? '');

        $query = "SELECT 
            a.id,
            a.attendance_date,
            a.time_in,
            a.time_out,
            a.status,
            a.remarks,
            s.student_id,
            CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as student_name,
            gl.grade_name,
            sec.name as section_name
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
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

        if ($grade_level_id > 0) {
            $query .= " AND s.grade_level_id = ?";
            $params[] = $grade_level_id;
            $types .= "i";
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
        exit;
    }

    if ($action === 'update_record') {
        $id = (int)$_POST['id'];
        $status = sanitizeInput($_POST['status']);
        $time_in = sanitizeInput($_POST['time_in']);
        $time_out = sanitizeInput($_POST['time_out']);
        $remarks = sanitizeInput($_POST['remarks']);

        // Validate status
        if (!in_array($status, ['Present', 'Late', 'Absent', 'Excused'])) {
            jsonResponse(false, 'Invalid status');
            exit;
        }

        // If absent, clear time_in and time_out
        if ($status === 'Absent') {
            $time_in = null;
            $time_out = null;
        } else {
            if (!empty($time_in)) {
                $time_in = date('H:i:s', strtotime($time_in));
            } else {
                $time_in = null;
            }

            if (!empty($time_out)) {
                $time_out = date('H:i:s', strtotime($time_out));
            } else {
                $time_out = null;
            }
        }

        if ($time_in !== null && $time_out !== null) {
            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = ?, time_out = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("ssssi", $status, $time_in, $time_out, $remarks, $id);
        } elseif ($time_in !== null) {
            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = ?, time_out = NULL, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("sssi", $status, $time_in, $remarks, $id);
        } else {
            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = NULL, time_out = NULL, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("ssi", $status, $remarks, $id);
        }

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'Update Attendance', "Updated attendance record ID: $id");
            jsonResponse(true, 'Record updated successfully');
        } else {
            jsonResponse(false, 'Failed to update record');
        }
        exit;
    }

    if ($action === 'delete_record') {
        $id = (int)$_POST['id'];

        $stmt = $conn->prepare("DELETE FROM attendance WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'Delete Attendance', "Deleted attendance record ID: $id");
            jsonResponse(true, 'Record deleted successfully');
        } else {
            jsonResponse(false, 'Failed to delete record');
        }
        exit;
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
        exit;
    }

    if ($action === 'export_csv') {
        $date_from = sanitizeInput($_POST['date_from'] ?? '');
        $date_to = sanitizeInput($_POST['date_to'] ?? '');
        $grade_level_id = (int)($_POST['grade_level_id'] ?? 0);
        $section_id = (int)($_POST['section_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');

        $query = "SELECT 
            a.attendance_date as 'Date',
            s.student_id as 'Student ID',
            CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as 'Student Name',
            gl.grade_name as 'Grade Level',
            sec.name as 'Section',
            a.status as 'Status',
            a.time_in as 'Time In',
            a.time_out as 'Time Out',
            a.remarks as 'Remarks'
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
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

        if ($grade_level_id > 0) {
            $query .= " AND s.grade_level_id = ?";
            $params[] = $grade_level_id;
            $types .= "i";
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

// Get grade levels for dropdown
$grade_levels = $conn->query("SELECT id, grade_number, grade_name FROM grade_levels WHERE is_active = 1 ORDER BY grade_number");

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
    <title>Attendance Records - Elementary Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="elementary_dashboard.css">
    <style>
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .stat-box:nth-child(1) {
            border: 3px solid var(--accent-green);
        }

        .stat-box:nth-child(2) {
            border: 3px solid var(--accent-yellow);
        }

        .stat-box:nth-child(3) {
            border: 3px solid var(--accent-pink);
        }

        .stat-box:nth-child(4) {
            border: 3px solid var(--primary-blue);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: var(--shadow);
        }

        .stat-icon.present {
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            color: white;
        }

        .stat-icon.late {
            background: linear-gradient(135deg, var(--accent-yellow), var(--accent-orange));
            color: white;
        }

        .stat-icon.absent {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
        }

        .stat-icon.total {
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
        }

        .stat-info h4 {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--text-primary);
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }

        .filter-section {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            border: 3px dashed var(--accent-yellow);
            position: relative;
        }

        .filter-section::before {
            content: '';
            position: absolute;
            top: -15px;
            left: 30px;
            font-size: 2rem;
            background: white;
            padding: 0 10px;
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-title {
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
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
            font-weight: 600;
        }

        .filter-group select,
        .filter-group input {
            padding: 10px 12px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--accent-purple);
            background: white;
            box-shadow: 0 0 0 4px rgba(181, 101, 216, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-reset {
            padding: 10px 20px;
            background: white;
            color: var(--danger);
            border: 2px solid var(--danger);
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-reset:hover {
            background: var(--danger);
            color: white;
        }

        .btn-export {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            margin-left: auto;
            box-shadow: var(--shadow);
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .records-section {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            box-shadow: var(--shadow);
            border: 3px solid var(--accent-green);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .search-box {
            position: relative;
            width: 300px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 40px 10px 15px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--accent-purple);
            background: white;
            box-shadow: 0 0 0 4px rgba(181, 101, 216, 0.1);
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
            border-collapse: separate;
            border-spacing: 0 8px;
            margin-top: 15px;
        }

        .records-table thead tr {
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
        }

        .records-table thead th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .records-table thead th:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .records-table thead th:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .records-table tbody tr {
            background: var(--hover-bg);
            transition: all 0.3s ease;
        }

        .records-table tbody tr:hover {
            background: white;
            box-shadow: var(--shadow);
            transform: scale(1.01);
        }

        .records-table tbody td {
            padding: 14px 16px;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.85rem;
        }

        .records-table tbody td:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .records-table tbody td:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.75rem;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.status-present {
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            color: white;
            box-shadow: 0 2px 8px rgba(123, 201, 111, 0.3);
        }

        .status-badge.status-late {
            background: linear-gradient(135deg, var(--accent-yellow), var(--accent-orange));
            color: white;
            box-shadow: 0 2px 8px rgba(255, 199, 95, 0.3);
        }

        .status-badge.status-absent {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
            box-shadow: 0 2px 8px rgba(255, 111, 145, 0.3);
        }

        .status-badge.status-excused {
            background: linear-gradient(135deg, var(--primary-blue), var(--accent-purple));
            color: white;
            box-shadow: 0 2px 8px rgba(74, 144, 226, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .btn-icon {
            padding: 6px 10px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-edit {
            background: rgba(74, 144, 226, 0.1);
            color: var(--primary-blue);
            border: 2px solid var(--primary-blue);
        }

        .btn-edit:hover {
            background: var(--primary-blue);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 2px solid var(--danger);
        }

        .btn-delete:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
            color: var(--primary-blue);
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
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

        .empty-state h3 {
            font-size: 1.2rem;
            color: var(--text-primary);
            margin-bottom: 8px;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 0.9rem;
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
            border-radius: 20px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-lg);
            border: 3px solid var(--accent-orange);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-header h2 {
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover {
            color: var(--danger);
            background: rgba(239, 68, 68, 0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-purple);
            background: white;
            box-shadow: 0 0 0 4px rgba(181, 101, 216, 0.1);
        }

        .logout-btn {
            margin: 24px 12px 0;
            margin-top: 22rem;
            padding: 12px 20px;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(255, 111, 145, 0.1));
            border: 2px solid var(--danger);
            color: var(--danger);
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            width: calc(100% - 24px);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .btn-submit {
            flex: 1;
            padding: 12px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: var(--shadow);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-cancel {
            flex: 1;
            padding: 12px;
            background: white;
            color: var(--danger);
            border: 2px solid var(--danger);
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-cancel:hover {
            background: var(--danger);
            color: white;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-yellow));
            border-radius: 20px;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            box-shadow: var(--shadow);
        }

        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .search-box {
                width: 100%;
            }

            .stats-row {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .btn-export {
                margin-left: 0;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Elementary School</h2>
            <p>San Francisco Sur Elementary School Attendance Monitoring System.</p>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item" onclick="location.href='dashboard.php'">
                    <span>Home</span>
                </div>
                <div class="nav-item" onclick="location.href='sections.php'">
                    <span>Sections</span>
                </div>
                <div class="nav-item" onclick="location.href='students.php'">
                    <span>Students</span>
                </div>
                <div class="nav-item" onclick="location.href='attendance.php'">
                    <span>Attendance</span>
                </div>
                <div class="nav-item active">
                    <span>Records</span>
                </div>
                <div class="nav-item" onclick="location.href='reports.php'">
                    <span>Reports</span>
                </div>
            </nav>

            <button class="logout-btn" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </aside>

        <main class="main-content">
            <div class="header">
                <h1 class="page-title">Attendance Records</h1>
                <div class="header-actions">
                    <div class="date-display">
                        <i class="fas fa-calendar-day"></i>
                        <span><?php echo date('l, F d, Y'); ?></span>
                    </div>
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
                        <label><i class="fas fa-calendar"></i> Date From</label>
                        <input type="date" id="date_from" value="<?php echo date('Y-m-01'); ?>">
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Date To</label>
                        <input type="date" id="date_to" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-graduation-cap"></i> Grade Level</label>
                        <select id="filter_grade" onchange="loadFilterSections()">
                            <option value="">All Grades</option>
                            <?php
                            $grade_levels->data_seek(0);
                            while ($grade = $grade_levels->fetch_assoc()):
                            ?>
                                <option value="<?php echo $grade['id']; ?>">
                                    <?php echo htmlspecialchars($grade['grade_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-users"></i> Section</label>
                        <select id="filter_section">
                            <option value="">All Sections</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-info-circle"></i> Status</label>
                        <select id="filter_status">
                            <option value="">All Status</option>
                            <option value="Present">Present</option>
                            <option value="Late">Late</option>
                            <option value="Absent">Absent</option>
                            <option value="Excused">Excused</option>
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
                    <label><i class="fas fa-check-circle"></i> Status *</label>
                    <select id="edit_status" required>
                        <option value="Present">Present</option>
                        <option value="Late">Late</option>
                        <option value="Absent">Absent</option>
                        <option value="Excused">Excused</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Time In</label>
                    <input type="time" id="edit_time_in">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Time Out</label>
                    <input type="time" id="edit_time_out">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Remarks</label>
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

        function loadFilterSections() {
            const gradeId = document.getElementById('filter_grade').value;
            const sectionSelect = document.getElementById('filter_section');

            if (!gradeId) {
                sectionSelect.innerHTML = '<option value="">All Sections</option>';
                return;
            }

            sectionSelect.innerHTML = '<option value="">Loading...</option>';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_sections');
            formData.append('grade_id', gradeId);

            fetch('records.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        sectionSelect.innerHTML = '<option value="">All Sections</option>';
                        data.data.forEach(section => {
                            const option = document.createElement('option');
                            option.value = section.id;
                            option.textContent = section.name;
                            sectionSelect.appendChild(option);
                        });
                    } else {
                        sectionSelect.innerHTML = '<option value="">No sections found</option>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                    showMessage('error', 'Failed to load sections');
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
            formData.append('grade_level_id', document.getElementById('filter_grade').value);
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
                            <th style="width: 12%">Student ID</th>
                            <th style="width: 20%">Student Name</th>
                            <th style="width: 12%">Grade Level</th>
                            <th style="width: 10%">Section</th>
                            <th style="width: 10%">Status</th>
                            <th style="width: 8%">Time In</th>
                            <th style="width: 8%">Time Out</th>
                            <th style="width: 15%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            filteredRecords.forEach((record, index) => {
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${formatDate(record.attendance_date)}</td>
                        <td><strong>${record.student_id}</strong></td>
                        <td>${record.student_name}</td>
                        <td>
                            <span class="grade-badge">
                                <i class="fas fa-graduation-cap"></i>
                                ${record.grade_name || 'N/A'}
                            </span>
                        </td>
                        <td>${record.section_name || 'N/A'}</td>
                        <td>
                            <span class="status-badge status-${record.status.toLowerCase()}">
                                ${record.status}
                            </span>
                        </td>
                        <td>${record.time_in ? formatTime(record.time_in) : 'N/A'}</td>
                        <td>${record.time_out ? formatTime(record.time_out) : 'N/A'}</td>
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
            const options = {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            };
            return date.toLocaleDateString('en-US', options);
        }

        function formatTime(timeString) {
            if (!timeString) return 'N/A';
            const [hours, minutes] = timeString.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const displayHour = hour % 12 || 12;
            return `${displayHour}:${minutes} ${ampm}`;
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
                        document.getElementById('edit_time_out').value = record.time_out || '';
                        document.getElementById('edit_remarks').value = record.remarks || '';

                        // Handle disabled state for absent
                        handleStatusChange();

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
            if (!confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
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
            document.getElementById('filter_grade').value = '';
            document.getElementById('filter_section').innerHTML = '<option value="">All Sections</option>';
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
                'grade_level_id': document.getElementById('filter_grade').value,
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

        function handleStatusChange() {
            const status = document.getElementById('edit_status').value;
            const timeInInput = document.getElementById('edit_time_in');
            const timeOutInput = document.getElementById('edit_time_out');

            if (status === 'Absent') {
                timeInInput.value = '';
                timeInInput.disabled = true;
                timeOutInput.value = '';
                timeOutInput.disabled = true;
            } else {
                timeInInput.disabled = false;
                timeOutInput.disabled = false;
            }
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
            formData.append('time_out', document.getElementById('edit_time_out').value);
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

        // Status change handler
        document.getElementById('edit_status').addEventListener('change', handleStatusChange);

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

        // Load records on page load
        window.addEventListener('DOMContentLoaded', function() {
            applyFilters();
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>
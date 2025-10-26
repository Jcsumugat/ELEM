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

    if ($action === 'generate_report') {
        $report_type = sanitizeInput($_POST['report_type']);
        $date_from = sanitizeInput($_POST['date_from']);
        $date_to = sanitizeInput($_POST['date_to']);
        $grade_level_id = (int)($_POST['grade_level_id'] ?? 0);
        $section_id = (int)($_POST['section_id'] ?? 0);

        $reportData = [];

        if ($report_type === 'summary') {
            // Summary Report
            $query = "SELECT 
                COUNT(DISTINCT a.student_id) as total_students,
                COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as total_present,
                COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as total_late,
                COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as total_absent,
                ROUND(COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0), 2) as attendance_rate
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE a.attendance_date BETWEEN ? AND ?";

            $params = [$date_from, $date_to];
            $types = "ss";

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

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            // Handle null values
            $reportData = [
                'total_students' => $result['total_students'] ?? 0,
                'total_present' => $result['total_present'] ?? 0,
                'total_late' => $result['total_late'] ?? 0,
                'total_absent' => $result['total_absent'] ?? 0,
                'attendance_rate' => $result['attendance_rate'] ?? 0
            ];
        } elseif ($report_type === 'by_student') {
            // By Student Report
            $query = "SELECT 
                s.student_id,
                CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as student_name,
                gl.grade_name,
                sec.name as section,
                COUNT(a.id) as total_days,
                COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present_count,
                COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as late_count,
                COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent_count,
                ROUND(COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0), 2) as attendance_rate
                FROM students s
                LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date BETWEEN ? AND ?
                LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE s.is_active = 1";

            $params = [$date_from, $date_to];
            $types = "ss";

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

            $query .= " GROUP BY s.id ORDER BY gl.grade_number, sec.name, student_name";

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $reportData = [];
            while ($row = $result->fetch_assoc()) {
                $reportData[] = $row;
            }
        } elseif ($report_type === 'by_date') {
            // By Date Report
            $query = "SELECT 
                a.attendance_date,
                COUNT(DISTINCT a.student_id) as total_students,
                COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present_count,
                COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as late_count,
                COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent_count,
                ROUND(COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0), 2) as attendance_rate
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE a.attendance_date BETWEEN ? AND ?";

            $params = [$date_from, $date_to];
            $types = "ss";

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

            $query .= " GROUP BY a.attendance_date ORDER BY a.attendance_date DESC";

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $reportData = [];
            while ($row = $result->fetch_assoc()) {
                $reportData[] = $row;
            }
        } elseif ($report_type === 'by_class') {
            // By Class/Section Report
            $query = "SELECT 
                gl.grade_name,
                sec.name as section,
                sec.room_number,
                COUNT(DISTINCT s.id) as total_students,
                COUNT(a.id) as total_records,
                COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present_count,
                COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as late_count,
                COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent_count,
                ROUND(COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0), 2) as attendance_rate
                FROM students s
                LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date BETWEEN ? AND ?
                LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE s.is_active = 1";

            $params = [$date_from, $date_to];
            $types = "ss";

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

            $query .= " GROUP BY s.grade_level_id, s.section_id ORDER BY gl.grade_number, sec.name";

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $reportData = [];
            while ($row = $result->fetch_assoc()) {
                $reportData[] = $row;
            }
        }

        jsonResponse(true, 'Report generated', ['type' => $report_type, 'data' => $reportData]);
        exit;
    }
}

// Get grade levels for dropdown
$grade_levels = $conn->query("SELECT id, grade_name, grade_number FROM grade_levels WHERE is_active = 1 ORDER BY grade_number");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Elementary Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="elementary_dashboard.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        .report-section {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            border: 3px solid var(--accent-purple);
        }

        .section-title {
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.2rem;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .report-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
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

        .report-type-card {
            background: var(--hover-bg);
            border: 3px solid var(--border-color);
            border-radius: 15px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .report-type-card:hover {
            border-color: var(--accent-green);
            background: white;
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .report-type-card.active {
            border-color: var(--primary-blue);
            background: linear-gradient(135deg, rgba(74, 144, 226, 0.1), rgba(103, 178, 228, 0.1));
            box-shadow: var(--shadow);
        }

        .report-type-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .report-type-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
            box-shadow: var(--shadow);
        }

        .report-type-card:nth-child(1) .report-type-icon {
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
        }

        .report-type-card:nth-child(2) .report-type-icon {
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            color: white;
        }

        .report-type-card:nth-child(3) .report-type-icon {
            background: linear-gradient(135deg, var(--accent-purple), var(--accent-pink));
            color: white;
        }

        .report-type-card:nth-child(4) .report-type-icon {
            background: linear-gradient(135deg, var(--accent-yellow), var(--accent-orange));
            color: white;
        }

        .report-type-card h3 {
            color: var(--text-primary);
            font-size: 1.05rem;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .report-type-card p {
            color: var(--text-secondary);
            font-size: 0.85rem;
            line-height: 1.5;
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
            display: flex;
            align-items: center;
            gap: 6px;
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

        .btn-generate {
            padding: 12px 15px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            width: 100%;
            justify-content: center;
            box-shadow: var(--shadow);
            font-size: 1rem;
        }

        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-generate:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .results-section {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            display: none;
            box-shadow: var(--shadow);
            border: 3px solid var(--accent-green);
        }

        .results-section.active {
            display: block;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px dashed var(--border-color);
        }

        .btn-print,
        .btn-export {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn-print {
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
        }

        .btn-export {
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            color: white;
        }

        .btn-print:hover,
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: var(--hover-bg);
            border: 3px solid;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .summary-card h4 {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .summary-card .value {
            color: var(--text-primary);
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-card .label {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .summary-card.present {
            border-color: var(--accent-green);
        }

        .summary-card.present .value {
            color: var(--accent-green);
        }

        .summary-card.late {
            border-color: var(--accent-yellow);
        }

        .summary-card.late .value {
            color: var(--accent-orange);
        }

        .summary-card.absent {
            border-color: var(--accent-pink);
        }

        .summary-card.absent .value {
            color: var(--accent-pink);
        }

        .summary-card.rate {
            border-color: var(--primary-blue);
        }

        .summary-card.rate .value {
            color: var(--primary-blue);
        }

        .chart-container {
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .chart-container h3 {
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
        }

        .report-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            margin-top: 20px;
        }

        .report-table thead tr {
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
        }

        .report-table thead th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .report-table thead th:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .report-table thead th:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .report-table tbody tr {
            background: var(--hover-bg);
            transition: all 0.3s ease;
        }

        .report-table tbody tr:hover {
            background: white;
            box-shadow: var(--shadow);
            transform: scale(1.01);
        }

        .report-table tbody td {
            padding: 14px 16px;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.85rem;
        }

        .report-table tbody td:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .report-table tbody td:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .rate-bar {
            width: 100%;
            height: 25px;
            background: rgba(0, 0, 0, 0.05);
            border-radius: 20px;
            overflow: hidden;
            position: relative;
        }

        .rate-bar-fill {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
            transition: width 0.5s ease;
            border-radius: 20px;
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
            color: var(--primary-blue);
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

        @media print {

            .sidebar,
            .header,
            .btn-print,
            .btn-export,
            .report-section,
            .filter-section {
                display: none !important;
            }

            body {
                background: white !important;
            }

            .dashboard-container {
                display: block !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 20px !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .results-section {
                border: none !important;
                box-shadow: none !important;
                page-break-inside: avoid;
            }

            .results-header {
                border-bottom: 2px solid #000 !important;
            }

            .report-table {
                page-break-inside: auto;
            }

            .report-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            .report-table thead {
                display: table-header-group;
            }

            .chart-container {
                page-break-inside: avoid;
            }

            .summary-cards {
                page-break-inside: avoid;
            }
            canvas {
                max-height: 400px !important;
            }
        }

        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .report-types {
                grid-template-columns: 1fr;
            }

            .results-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
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
                <div class="nav-item" onclick="location.href='records.php'">
                    <span>Records</span>
                </div>
                <div class="nav-item active">
                    <span>Reports</span>
                </div>
            </nav>

            <button class="logout-btn" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </aside>

        <main class="main-content">
            <div class="header">
                <h1 class="page-title">Reports & Analytics</h1>
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

            <!-- Report Configuration -->
            <div class="report-section">
                <h3 class="section-title">
                    <i class="fas fa-cog"></i>
                    Report Configuration
                </h3>

                <div class="report-types">
                    <label class="report-type-card active">
                        <input type="radio" name="report_type" value="summary" checked>
                        <div class="report-type-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h3>Summary Report</h3>
                        <p>Overall attendance statistics and summary</p>
                    </label>

                    <label class="report-type-card">
                        <input type="radio" name="report_type" value="by_student">
                        <div class="report-type-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <h3>By Student</h3>
                        <p>Individual student attendance records</p>
                    </label>

                    <label class="report-type-card">
                        <input type="radio" name="report_type" value="by_date">
                        <div class="report-type-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <h3>By Date</h3>
                        <p>Daily attendance breakdown</p>
                    </label>

                    <label class="report-type-card">
                        <input type="radio" name="report_type" value="by_class">
                        <div class="report-type-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <h3>By Section</h3>
                        <p>Section-wise attendance comparison</p>
                    </label>
                </div>

                <div class="filter-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Date From *</label>
                        <input type="date" id="report_date_from" value="<?php echo date('Y-m-01'); ?>" required>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Date To *</label>
                        <input type="date" id="report_date_to" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-graduation-cap"></i> Grade Level</label>
                        <select id="report_grade_level" onchange="loadReportSections()">
                            <option value="">All Grade Levels</option>
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
                        <select id="report_section">
                            <option value="">All Sections</option>
                        </select>
                    </div>
                </div>

                <button class="btn-generate" onclick="generateReport()">
                    <i class="fas fa-chart-bar"></i>
                    Generate Report
                </button>
            </div>

            <!-- Results Section -->
            <div id="resultsSection" class="results-section">
                <div class="results-header">
                    <h3 class="section-title">
                        <i class="fas fa-chart-line"></i>
                        <span id="reportTitle">Report Results</span>
                    </h3>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn-print" onclick="printReport()">
                            <i class="fas fa-print"></i>
                            Print
                        </button>
                        <button class="btn-export" onclick="exportReport()">
                            <i class="fas fa-file-excel"></i>
                            Export CSV
                        </button>
                    </div>
                </div>

                <div id="reportContent"></div>
            </div>
        </main>
    </div>

    <script>
        let currentReportData = null;

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

        // Report type selection
        document.querySelectorAll('.report-type-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.report-type-card').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                this.querySelector('input[type="radio"]').checked = true;
            });
        });

        function loadReportSections() {
            const gradeId = document.getElementById('report_grade_level').value;
            const sectionSelect = document.getElementById('report_section');

            if (!gradeId) {
                sectionSelect.innerHTML = '<option value="">All Sections</option>';
                return;
            }

            sectionSelect.innerHTML = '<option value="">Loading...</option>';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_sections');
            formData.append('grade_id', gradeId);

            fetch('reports.php', {
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
                });
        }

        function generateReport() {
            const reportType = document.querySelector('input[name="report_type"]:checked').value;
            const dateFrom = document.getElementById('report_date_from').value;
            const dateTo = document.getElementById('report_date_to').value;

            if (!dateFrom || !dateTo) {
                showMessage('error', 'Please select date range');
                return;
            }

            const content = document.getElementById('reportContent');
            content.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><p>Generating report...</p></div>';
            document.getElementById('resultsSection').classList.add('active');

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'generate_report');
            formData.append('report_type', reportType);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            formData.append('grade_level_id', document.getElementById('report_grade_level').value);
            formData.append('section_id', document.getElementById('report_section').value);

            fetch('reports.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentReportData = data.data;
                        renderReport(data.data.type, data.data.data);
                        showMessage('success', 'Report generated successfully');
                    } else {
                        content.innerHTML = `
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
                    content.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error</h3>
                        <p>Failed to generate report</p>
                    </div>
                `;
                });
        }

        function renderReport(type, data) {
            const content = document.getElementById('reportContent');
            let html = '';

            if (type === 'summary') {
                document.getElementById('reportTitle').textContent = 'Summary Report';

                html = `
                    <div class="summary-cards">
                        <div class="summary-card">
                            <h4>Total Students</h4>
                            <div class="value">${data.total_students || 0}</div>
                            <div class="label">Unique students</div>
                        </div>
                        <div class="summary-card present">
                            <h4>Present</h4>
                            <div class="value">${data.total_present || 0}</div>
                            <div class="label">Total present</div>
                        </div>
                        <div class="summary-card late">
                            <h4>Late</h4>
                            <div class="value">${data.total_late || 0}</div>
                            <div class="label">Total late</div>
                        </div>
                        <div class="summary-card absent">
                            <h4>Absent</h4>
                            <div class="value">${data.total_absent || 0}</div>
                            <div class="label">Total absent</div>
                        </div>
                        <div class="summary-card rate">
                            <h4>Attendance Rate</h4>
                            <div class="value">${data.attendance_rate || 0}%</div>
                            <div class="label">Overall rate</div>
                        </div>
                    </div>

                    <div class="chart-container">
                        <h3><i class="fas fa-chart-pie"></i> Attendance Distribution</h3>
                        <canvas id="summaryChart" height="100"></canvas>
                    </div>
                `;

                content.innerHTML = html;

                // Create pie chart
                const ctx = document.getElementById('summaryChart').getContext('2d');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Present', 'Late', 'Absent'],
                        datasets: [{
                            data: [data.total_present, data.total_late, data.total_absent],
                            backgroundColor: ['#7BC96F', '#FFC75F', '#FF6F91'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: '#2D3748',
                                    font: {
                                        size: 14,
                                        family: 'Inter'
                                    }
                                }
                            }
                        }
                    }
                });

            } else if (type === 'by_student') {
                document.getElementById('reportTitle').textContent = 'Student Attendance Report';

                if (data.length === 0) {
                    html = `
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Data Found</h3>
                            <p>No student records found for the selected criteria</p>
                        </div>
                    `;
                } else {
                    html = `
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Grade Level</th>
                                    <th>Section</th>
                                    <th>Present</th>
                                    <th>Late</th>
                                    <th>Absent</th>
                                    <th>Total Days</th>
                                    <th>Attendance Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.forEach((student, index) => {
                        const rate = student.attendance_rate || 0;
                        let barColor = '#FF6F91'; // Red for low
                        if (rate >= 90) barColor = '#7BC96F'; // Green for high
                        else if (rate >= 75) barColor = '#FFC75F'; // Yellow for medium

                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td><strong>${student.student_id}</strong></td>
                                <td>${student.student_name}</td>
                                <td>
                                    <span class="grade-badge">
                                        <i class="fas fa-graduation-cap"></i>
                                        ${student.grade_name || 'N/A'}
                                    </span>
                                </td>
                                <td>${student.section || 'N/A'}</td>
                                <td style="color: #7BC96F; font-weight: 700;">${student.present_count}</td>
                                <td style="color: #FFC75F; font-weight: 700;">${student.late_count}</td>
                                <td style="color: #FF6F91; font-weight: 700;">${student.absent_count}</td>
                                <td><strong>${student.total_days}</strong></td>
                                <td>
                                    <div class="rate-bar">
                                        <div class="rate-bar-fill" style="width: ${rate}%; background: ${barColor};">
                                            ${rate}%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });

                    html += `
                            </tbody>
                        </table>
                    `;
                }

                content.innerHTML = html;

            } else if (type === 'by_date') {
                document.getElementById('reportTitle').textContent = 'Daily Attendance Report';

                if (data.length === 0) {
                    html = `
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Data Found</h3>
                            <p>No attendance records found for the selected date range</p>
                        </div>
                    `;
                } else {
                    // Prepare chart data
                    const dates = data.map(d => formatDate(d.attendance_date));
                    const presentData = data.map(d => d.present_count);
                    const lateData = data.map(d => d.late_count);
                    const absentData = data.map(d => d.absent_count);

                    html = `
                        <div class="chart-container">
                            <h3><i class="fas fa-chart-line"></i> Attendance Trend</h3>
                            <canvas id="dateChart" height="80"></canvas>
                        </div>

                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Total Students</th>
                                    <th>Present</th>
                                    <th>Late</th>
                                    <th>Absent</th>
                                    <th>Attendance Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.forEach((record, index) => {
                        const rate = record.attendance_rate || 0;
                        let barColor = '#FF6F91';
                        if (rate >= 90) barColor = '#7BC96F';
                        else if (rate >= 75) barColor = '#FFC75F';

                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td><strong>${formatDate(record.attendance_date)}</strong></td>
                                <td>${record.total_students}</td>
                                <td style="color: #7BC96F; font-weight: 700;">${record.present_count}</td>
                                <td style="color: #FFC75F; font-weight: 700;">${record.late_count}</td>
                                <td style="color: #FF6F91; font-weight: 700;">${record.absent_count}</td>
                                <td>
                                    <div class="rate-bar">
                                        <div class="rate-bar-fill" style="width: ${rate}%; background: ${barColor};">
                                            ${rate}%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });

                    html += `
                            </tbody>
                        </table>
                    `;

                    content.innerHTML = html;

                    // Create line chart
                    const ctx = document.getElementById('dateChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: dates,
                            datasets: [{
                                    label: 'Present',
                                    data: presentData,
                                    borderColor: '#7BC96F',
                                    backgroundColor: 'rgba(123, 201, 111, 0.1)',
                                    fill: true,
                                    tension: 0.4
                                },
                                {
                                    label: 'Late',
                                    data: lateData,
                                    borderColor: '#FFC75F',
                                    backgroundColor: 'rgba(255, 199, 95, 0.1)',
                                    fill: true,
                                    tension: 0.4
                                },
                                {
                                    label: 'Absent',
                                    data: absentData,
                                    borderColor: '#FF6F91',
                                    backgroundColor: 'rgba(255, 111, 145, 0.1)',
                                    fill: true,
                                    tension: 0.4
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    labels: {
                                        color: '#2D3748',
                                        font: {
                                            size: 13,
                                            family: 'Inter'
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        color: '#2D3748'
                                    },
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.1)'
                                    }
                                },
                                x: {
                                    ticks: {
                                        color: '#2D3748'
                                    },
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

            } else if (type === 'by_class') {
                document.getElementById('reportTitle').textContent = 'Section-wise Attendance Report';

                if (data.length === 0) {
                    html = `
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Data Found</h3>
                            <p>No section records found for the selected criteria</p>
                        </div>
                    `;
                } else {
                    // Prepare chart data
                    const sections = data.map(d => `${d.grade_name} - ${d.section}`);
                    const rates = data.map(d => d.attendance_rate || 0);

                    html = `
                        <div class="chart-container">
                            <h3><i class="fas fa-chart-bar"></i> Section Comparison</h3>
                            <canvas id="classChart" height="100"></canvas>
                        </div>

                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Grade Level</th>
                                    <th>Section</th>
                                    <th>Room</th>
                                    <th>Students</th>
                                    <th>Present</th>
                                    <th>Late</th>
                                    <th>Absent</th>
                                    <th>Attendance Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.forEach((cls, index) => {
                        const rate = cls.attendance_rate || 0;
                        let barColor = '#FF6F91';
                        if (rate >= 90) barColor = '#7BC96F';
                        else if (rate >= 75) barColor = '#FFC75F';

                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>
                                    <span class="grade-badge">
                                        <i class="fas fa-graduation-cap"></i>
                                        ${cls.grade_name || 'N/A'}
                                    </span>
                                </td>
                                <td><strong>${cls.section || 'N/A'}</strong></td>
                                <td>${cls.room_number || 'N/A'}</td>
                                <td>${cls.total_students}</td>
                                <td style="color: #7BC96F; font-weight: 700;">${cls.present_count}</td>
                                <td style="color: #FFC75F; font-weight: 700;">${cls.late_count}</td>
                                <td style="color: #FF6F91; font-weight: 700;">${cls.absent_count}</td>
                                <td>
                                    <div class="rate-bar">
                                        <div class="rate-bar-fill" style="width: ${rate}%; background: ${barColor};">
                                            ${rate}%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });

                    html += `
                            </tbody>
                        </table>
                    `;

                    content.innerHTML = html;

                    // Create bar chart
                    const ctx = document.getElementById('classChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: sections,
                            datasets: [{
                                label: 'Attendance Rate (%)',
                                data: rates,
                                backgroundColor: 'rgba(123, 201, 111, 0.6)',
                                borderColor: '#7BC96F',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    labels: {
                                        color: '#2D3748',
                                        font: {
                                            size: 13,
                                            family: 'Inter'
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100,
                                    ticks: {
                                        color: '#2D3748'
                                    },
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.1)'
                                    }
                                },
                                x: {
                                    ticks: {
                                        color: '#2D3748'
                                    },
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }
            }
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

        function printReport() {
            window.print();
        }

        function exportReport() {
            if (!currentReportData) {
                showMessage('error', 'No report data to export');
                return;
            }

            const type = currentReportData.type;
            const data = currentReportData.data;

            let csv = '';
            let filename = `attendance_report_${type}_${new Date().toISOString().split('T')[0]}.csv`;

            if (type === 'summary') {
                csv = 'Metric,Value\n';
                csv += `Total Students,${data.total_students || 0}\n`;
                csv += `Total Present,${data.total_present || 0}\n`;
                csv += `Total Late,${data.total_late || 0}\n`;
                csv += `Total Absent,${data.total_absent || 0}\n`;
                csv += `Attendance Rate,${data.attendance_rate || 0}%\n`;

            } else if (type === 'by_student') {
                csv = 'Student ID,Name,Grade Level,Section,Present,Late,Absent,Total Days,Attendance Rate\n';
                data.forEach(student => {
                    csv += `${student.student_id},"${student.student_name}",${student.grade_name || ''},${student.section || ''},${student.present_count},${student.late_count},${student.absent_count},${student.total_days},${student.attendance_rate}%\n`;
                });

            } else if (type === 'by_date') {
                csv = 'Date,Total Students,Present,Late,Absent,Attendance Rate\n';
                data.forEach(record => {
                    csv += `${record.attendance_date},${record.total_students},${record.present_count},${record.late_count},${record.absent_count},${record.attendance_rate}%\n`;
                });

            } else if (type === 'by_class') {
                csv = 'Grade Level,Section,Room,Total Students,Present,Late,Absent,Attendance Rate\n';
                data.forEach(cls => {
                    csv += `${cls.grade_name || ''},${cls.section || ''},${cls.room_number || ''},${cls.total_students},${cls.present_count},${cls.late_count},${cls.absent_count},${cls.attendance_rate}%\n`;
                });
            }

            // Download CSV
            const blob = new Blob([csv], {
                type: 'text/csv'
            });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);

            showMessage('success', 'Report exported successfully');
        }
    </script>
</body>

</html>
<?php
$conn->close();
?>
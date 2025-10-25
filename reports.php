<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate_report') {
        $report_type = sanitizeInput($_POST['report_type']);
        $date_from = sanitizeInput($_POST['date_from']);
        $date_to = sanitizeInput($_POST['date_to']);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $year_level = sanitizeInput($_POST['year_level'] ?? '');
        $section_id = (int)($_POST['section_id'] ?? 0);
        
        $reportData = [];
        
        if ($report_type === 'summary') {
            // Summary Report
            $query = "SELECT 
                COUNT(DISTINCT a.student_id) as total_students,
                COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as total_present,
                COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as total_late,
                COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as total_absent,
                ROUND(COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) * 100.0 / COUNT(*), 2) as attendance_rate
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE a.attendance_date BETWEEN ? AND ?";
            
            $params = [$date_from, $date_to];
            $types = "ss";
            
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
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $reportData = $stmt->get_result()->fetch_assoc();
            
        } elseif ($report_type === 'by_student') {
            // By Student Report
            $query = "SELECT 
                s.student_id,
                CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as student_name,
                d.code as department,
                c.code as course,
                s.year_level,
                sec.name as section,
                COUNT(a.id) as total_days,
                COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present_count,
                COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as late_count,
                COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent_count,
                ROUND(COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) * 100.0 / COUNT(*), 2) as attendance_rate
                FROM students s
                LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date BETWEEN ? AND ?
                LEFT JOIN departments d ON s.department_id = d.id
                LEFT JOIN courses c ON s.course_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE s.is_active = 1";
            
            $params = [$date_from, $date_to];
            $types = "ss";
            
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
            
            $query .= " GROUP BY s.id ORDER BY student_name";
            
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
                ROUND(COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) * 100.0 / COUNT(*), 2) as attendance_rate
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE a.attendance_date BETWEEN ? AND ?";
            
            $params = [$date_from, $date_to];
            $types = "ss";
            
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
            // By Class Report
            $query = "SELECT 
                d.code as department,
                c.code as course,
                s.year_level,
                sec.name as section,
                COUNT(DISTINCT s.id) as total_students,
                COUNT(a.id) as total_records,
                COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present_count,
                COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as late_count,
                COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent_count,
                ROUND(COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) * 100.0 / COUNT(*), 2) as attendance_rate
                FROM students s
                LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date BETWEEN ? AND ?
                LEFT JOIN departments d ON s.department_id = d.id
                LEFT JOIN courses c ON s.course_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE s.is_active = 1";
            
            $params = [$date_from, $date_to];
            $types = "ss";
            
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
            
            $query .= " GROUP BY s.department_id, s.course_id, s.year_level, s.section_id ORDER BY department, course, year_level, section";
            
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
    }
}

// Get departments for dropdown
$departments = $conn->query("SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY name");

// Get sections for dropdown
$sections = $conn->query("SELECT id, name FROM sections WHERE is_active = 1 ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        .report-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .section-title {
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            margin-bottom: 20px;
        }

        .report-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .report-type-card {
            background: rgba(255, 255, 255, 0.02);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .report-type-card:hover {
            border-color: var(--accent-green);
            background: rgba(0, 208, 132, 0.05);
            transform: translateY(-3px);
        }

        .report-type-card.active {
            border-color: var(--accent-green);
            background: rgba(0, 208, 132, 0.1);
        }

        .report-type-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .report-type-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }

        .report-type-card:nth-child(1) .report-type-icon {
            background: rgba(74, 144, 226, 0.1);
            color: var(--primary-blue);
        }

        .report-type-card:nth-child(2) .report-type-icon {
            background: rgba(0, 208, 132, 0.1);
            color: var(--accent-green);
        }

        .report-type-card:nth-child(3) .report-type-icon {
            background: rgba(157, 78, 221, 0.1);
            color: var(--accent-purple);
        }

        .report-type-card:nth-child(4) .report-type-icon {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }

        .report-type-card h3 {
            color: var(--text-light);
            font-size: 1rem;
            margin-bottom: 8px;
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
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            padding: 12px 15px;
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

        .btn-generate {
            padding: 12px 15px;
            background: linear-gradient(135deg, var(--accent-green), #00a86b);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            width: 100%;
            justify-content: center;
        }

        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 208, 132, 0.3);
        }

        .btn-generate:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .results-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 25px;
            display: none;
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
            border-bottom: 2px solid rgba(157, 78, 221, 0.2);
        }

        .btn-print, .btn-export {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-print {
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
            color: white;
        }

        .btn-export {
            background: linear-gradient(135deg, var(--accent-green), #00a86b);
            color: white;
        }

        .btn-print:hover, .btn-export:hover {
            transform: translateY(-2px);
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        .summary-card h4 {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 10px;
        }

        .summary-card .value {
            color: var(--text-light);
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-card .label {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .summary-card.present .value {
            color: var(--success);
        }

        .summary-card.late .value {
            color: var(--warning);
        }

        .summary-card.absent .value {
            color: var(--error);
        }

        .summary-card.rate .value {
            color: var(--primary-blue);
        }

        .chart-container {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .chart-container h3 {
            color: var(--text-light);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .report-table thead th {
            background: rgba(157, 78, 221, 0.1);
            color: var(--text-secondary);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .report-table tbody td {
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .report-table tbody tr:hover {
            background: rgba(157, 78, 221, 0.05);
        }

        .rate-bar {
            width: 100%;
            height: 25px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }

        .rate-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--error), var(--warning), var(--success));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
            transition: width 0.5s ease;
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

        @media print {
            .sidebar, .header, .btn-print, .btn-export, .report-section {
                display: none !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 20px !important;
            }
            
            .results-section {
                border: none !important;
                box-shadow: none !important;
            }
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
                <div class="nav-item" onclick="location.href='records.php'">
                    <i class="fas fa-history"></i>
                    <span>Attendance Records</span>
                </div>
                <div class="nav-item active">
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
                <h1 class="page-title">Reports & Analytics</h1>
                <div class="header-actions">
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
                        <h3>By Class</h3>
                        <p>Class-wise attendance comparison</p>
                    </label>
                </div>

                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Date From *</label>
                        <input type="date" id="report_date_from" value="<?php echo date('Y-m-01'); ?>" required>
                    </div>

                    <div class="filter-group">
                        <label>Date To *</label>
                        <input type="date" id="report_date_to" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="filter-group">
                        <label>Department</label>
                        <select id="report_department" onchange="loadReportCourses()">
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
                        <select id="report_course">
                            <option value="">All Courses</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Year Level</label>
                        <select id="report_year">
                            <option value="">All Years</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Section</label>
                        <select id="report_section">
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
                            Export
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

        function loadReportCourses() {
            const departmentId = document.getElementById('report_department').value;
            const courseSelect = document.getElementById('report_course');
            
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
            formData.append('department_id', document.getElementById('report_department').value);
            formData.append('course_id', document.getElementById('report_course').value);
            formData.append('year_level', document.getElementById('report_year').value);
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
                            backgroundColor: ['#00FF88', '#FFC107', '#FF4757'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: '#E0E0E0',
                                    font: { size: 14 }
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
                                    <th>Department</th>
                                    <th>Course</th>
                                    <th>Year</th>
                                    <th>Section</th>
                                    <th>Present</th>
                                    <th>Late</th>
                                    <th>Absent</th>
                                    <th>Total Days</th>
                                    <th>Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.forEach((student, index) => {
                        const rate = student.attendance_rate || 0;
                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${student.student_id}</td>
                                <td>${student.student_name}</td>
                                <td>${student.department || 'N/A'}</td>
                                <td>${student.course || 'N/A'}</td>
                                <td>${student.year_level}</td>
                                <td>${student.section || 'N/A'}</td>
                                <td style="color: var(--success); font-weight: 600;">${student.present_count}</td>
                                <td style="color: var(--warning); font-weight: 600;">${student.late_count}</td>
                                <td style="color: var(--error); font-weight: 600;">${student.absent_count}</td>
                                <td>${student.total_days}</td>
                                <td>
                                    <div class="rate-bar">
                                        <div class="rate-bar-fill" style="width: ${rate}%">
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
                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${formatDate(record.attendance_date)}</td>
                                <td>${record.total_students}</td>
                                <td style="color: var(--success); font-weight: 600;">${record.present_count}</td>
                                <td style="color: var(--warning); font-weight: 600;">${record.late_count}</td>
                                <td style="color: var(--error); font-weight: 600;">${record.absent_count}</td>
                                <td>
                                    <div class="rate-bar">
                                        <div class="rate-bar-fill" style="width: ${record.attendance_rate}%">
                                            ${record.attendance_rate}%
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
                            datasets: [
                                {
                                    label: 'Present',
                                    data: presentData,
                                    borderColor: '#00FF88',
                                    backgroundColor: 'rgba(0, 255, 136, 0.1)',
                                    fill: true,
                                    tension: 0.4
                                },
                                {
                                    label: 'Late',
                                    data: lateData,
                                    borderColor: '#FFC107',
                                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                    fill: true,
                                    tension: 0.4
                                },
                                {
                                    label: 'Absent',
                                    data: absentData,
                                    borderColor: '#FF4757',
                                    backgroundColor: 'rgba(255, 71, 87, 0.1)',
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
                                        color: '#E0E0E0',
                                        font: { size: 13 }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: { color: '#E0E0E0' },
                                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                                },
                                x: {
                                    ticks: { color: '#E0E0E0' },
                                    grid: { display: false }
                                }
                            }
                        }
                    });
                }

            } else if (type === 'by_class') {
                document.getElementById('reportTitle').textContent = 'Class-wise Attendance Report';
                
                if (data.length === 0) {
                    html = `
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Data Found</h3>
                            <p>No class records found for the selected criteria</p>
                        </div>
                    `;
                } else {
                    // Prepare chart data
                    const classes = data.map(d => `${d.course} ${d.year_level} ${d.section}`);
                    const rates = data.map(d => d.attendance_rate || 0);

                    html = `
                        <div class="chart-container">
                            <h3><i class="fas fa-chart-bar"></i> Class Comparison</h3>
                            <canvas id="classChart" height="100"></canvas>
                        </div>

                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Department</th>
                                    <th>Course</th>
                                    <th>Year Level</th>
                                    <th>Section</th>
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
                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${cls.department || 'N/A'}</td>
                                <td>${cls.course || 'N/A'}</td>
                                <td>${cls.year_level}</td>
                                <td>${cls.section || 'N/A'}</td>
                                <td>${cls.total_students}</td>
                                <td style="color: var(--success); font-weight: 600;">${cls.present_count}</td>
                                <td style="color: var(--warning); font-weight: 600;">${cls.late_count}</td>
                                <td style="color: var(--error); font-weight: 600;">${cls.absent_count}</td>
                                <td>
                                    <div class="rate-bar">
                                        <div class="rate-bar-fill" style="width: ${cls.attendance_rate || 0}%">
                                            ${cls.attendance_rate || 0}%
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
                            labels: classes,
                            datasets: [{
                                label: 'Attendance Rate (%)',
                                data: rates,
                                backgroundColor: 'rgba(0, 255, 136, 0.6)',
                                borderColor: '#00FF88',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    labels: {
                                        color: '#E0E0E0',
                                        font: { size: 13 }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100,
                                    ticks: { color: '#E0E0E0' },
                                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                                },
                                x: {
                                    ticks: { color: '#E0E0E0' },
                                    grid: { display: false }
                                }
                            }
                        }
                    });
                }
            }
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
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
                csv = 'Student ID,Name,Department,Course,Year,Section,Present,Late,Absent,Total Days,Attendance Rate\n';
                data.forEach(student => {
                    csv += `${student.student_id},"${student.student_name}",${student.department || ''},${student.course || ''},${student.year_level},${student.section || ''},${student.present_count},${student.late_count},${student.absent_count},${student.total_days},${student.attendance_rate}%\n`;
                });
                
            } else if (type === 'by_date') {
                csv = 'Date,Total Students,Present,Late,Absent,Attendance Rate\n';
                data.forEach(record => {
                    csv += `${record.attendance_date},${record.total_students},${record.present_count},${record.late_count},${record.absent_count},${record.attendance_rate}%\n`;
                });
                
            } else if (type === 'by_class') {
                csv = 'Department,Course,Year Level,Section,Total Students,Present,Late,Absent,Attendance Rate\n';
                data.forEach(cls => {
                    csv += `${cls.department || ''},${cls.course || ''},${cls.year_level},${cls.section || ''},${cls.total_students},${cls.present_count},${cls.late_count},${cls.absent_count},${cls.attendance_rate}%\n`;
                });
            }

            // Download CSV
            const blob = new Blob([csv], { type: 'text/csv' });
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
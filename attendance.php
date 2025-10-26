<?php
// Prevent any output before JSON response
ob_start();

require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // Clear any output buffers to ensure clean JSON response
    ob_clean();

    $action = $_POST['action'] ?? '';

    if ($action === 'get_sections') {
        $grade_id = (int)$_POST['grade_id'];
        $stmt = $conn->prepare("SELECT id, name, room_number FROM sections WHERE grade_level_id = ? AND is_active = 1 ORDER BY name");
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

    if ($action === 'get_students') {
        $grade_level_id = (int)$_POST['grade_level_id'];
        $section_id = (int)$_POST['section_id'];
        $attendance_date = sanitizeInput($_POST['attendance_date']);

        $query = "SELECT 
        s.id,
        s.student_id,
        CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as full_name,
        s.gender,
        gl.grade_name,
        sec.name as section_name,
        a.id as attendance_id,
        a.status,
        a.time_in,
        a.time_out,
        a.remarks,
        a.excuse_letter
        FROM students s
            JOIN grade_levels gl ON s.grade_level_id = gl.id
            JOIN sections sec ON s.section_id = sec.id
            LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date = ?
            WHERE s.is_active = 1 
            AND s.grade_level_id = ?
            AND s.section_id = ?
            ORDER BY s.lastname, s.firstname";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("sii", $attendance_date, $grade_level_id, $section_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        jsonResponse(true, 'Students loaded', $students);
        exit;
    }

    if ($action === 'save_attendance') {
        try {
            $attendance_date = sanitizeInput($_POST['attendance_date']);
            $attendanceData = json_decode($_POST['attendance_data'], true);

            if (empty($attendanceData)) {
                jsonResponse(false, 'No attendance data provided');
                exit;
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                jsonResponse(false, 'Invalid attendance data format');
                exit;
            }

            $conn->begin_transaction();

            $success_count = 0;
            $recorded_by = $_SESSION['user_id'];

            foreach ($attendanceData as $record) {
                $student_id = (int)$record['student_id'];
                $status = sanitizeInput($record['status']);
                $time_in = sanitizeInput($record['time_in']);
                $time_out = sanitizeInput($record['time_out'] ?? '');
                $remarks = sanitizeInput($record['remarks'] ?? '');
                $excuse_letter = $record['excuse_letter'] ?? null;

                // Validate status
                if (!in_array($status, ['Present', 'Late', 'Absent', 'Excused'])) {
                    continue;
                }

                // If absent, clear time_in and time_out
                if ($status === 'Absent') {
                    $time_in = null;
                    $time_out = null;
                    $excuse_letter = null;
                } else {
                    if (empty($time_in)) {
                        $time_in = date('H:i:s');
                    } else {
                        $time_in = date('H:i:s', strtotime($time_in));
                    }

                    if (!empty($time_out)) {
                        $time_out = date('H:i:s', strtotime($time_out));
                    } else {
                        $time_out = null;
                    }
                }

                // Check if attendance already exists
                $checkStmt = $conn->prepare("SELECT id, excuse_letter FROM attendance WHERE student_id = ? AND attendance_date = ?");
                $checkStmt->bind_param("is", $student_id, $attendance_date);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->fetch_assoc();

                if ($existing) {
                    // Delete old excuse letter if status changed from Excused or new letter uploaded
                    if ($existing['excuse_letter'] && ($status !== 'Excused' || $excuse_letter)) {
                        deleteExcuseLetter($existing['excuse_letter']);
                    }

                    // Update existing record
                    if ($time_in !== null) {
                        if ($time_out !== null) {
                            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = ?, time_out = ?, remarks = ?, excuse_letter = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("sssssii", $status, $time_in, $time_out, $remarks, $excuse_letter, $recorded_by, $existing['id']);
                        } else {
                            $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = ?, time_out = NULL, remarks = ?, excuse_letter = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("ssssii", $status, $time_in, $remarks, $excuse_letter, $recorded_by, $existing['id']);
                        }
                    } else {
                        $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = NULL, time_out = NULL, remarks = ?, excuse_letter = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->bind_param("sssii", $status, $remarks, $excuse_letter, $recorded_by, $existing['id']);
                    }
                } else {
                    // Insert new record
                    if ($time_in !== null) {
                        if ($time_out !== null) {
                            $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, time_in, time_out, remarks, excuse_letter, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("issssssi", $student_id, $attendance_date, $status, $time_in, $time_out, $remarks, $excuse_letter, $recorded_by);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, time_in, remarks, excuse_letter, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("isssssi", $student_id, $attendance_date, $status, $time_in, $remarks, $excuse_letter, $recorded_by);
                        }
                    } else {
                        $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, remarks, excuse_letter, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("issssi", $student_id, $attendance_date, $status, $remarks, $excuse_letter, $recorded_by);
                    }
                }

                if ($stmt->execute()) {
                    $success_count++;
                }
            }

            $conn->commit();

            // Log activity
            logActivity($conn, $_SESSION['user_id'], 'Save Attendance', "Saved attendance for $success_count students on $attendance_date");

            jsonResponse(true, "Attendance saved successfully ({$success_count} records)");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Attendance save error: " . $e->getMessage());
            jsonResponse(false, 'Failed to save attendance. Please try again.');
            exit;
        }
    }

    if ($action === 'upload_excuse_letter') {
        $student_id = (int)$_POST['student_id'];
        $attendance_date = sanitizeInput($_POST['attendance_date']);

        if (isset($_FILES['excuse_letter']) && $_FILES['excuse_letter']['error'] === UPLOAD_ERR_OK) {
            $upload_result = uploadExcuseLetter($_FILES['excuse_letter'], $student_id, $attendance_date);

            if ($upload_result['success']) {
                jsonResponse(true, 'File uploaded successfully', ['filename' => $upload_result['filename']]);
            } else {
                jsonResponse(false, $upload_result['message']);
            }
        } else {
            jsonResponse(false, 'No file uploaded or upload error');
        }
        exit;
    }

    // If action not recognized
    jsonResponse(false, 'Invalid action');
    exit;
}

// Get grade levels for dropdown
$grade_levels = $conn->query("SELECT id, grade_number, grade_name FROM grade_levels WHERE is_active = 1 ORDER BY grade_number");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance - Elementary Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="elementary_dashboard.css">
    <style>
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

        .filter-section h3 {
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
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
            padding: 12px 15px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 0.85rem;
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

        .btn-load {
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin-top: auto;
            box-shadow: var(--shadow);
        }

        .btn-load:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-load:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .attendance-section {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            display: none;
            box-shadow: var(--shadow);
            border: 3px solid var(--accent-green);
        }

        .attendance-section.active {
            display: block;
            animation: slideDown 0.3s ease;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--border-color);
        }

        .section-title {
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-save-all {
            padding: 12px 25px;
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn-save-all:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-save-all:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .attendance-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .attendance-table thead tr {
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
        }

        .attendance-table thead th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-table thead th:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .attendance-table thead th:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .attendance-table tbody tr {
            background: var(--hover-bg);
            transition: all 0.3s ease;
        }

        .attendance-table tbody tr:hover {
            background: white;
            box-shadow: var(--shadow);
            transform: scale(1.01);
        }

        .attendance-table tbody td {
            padding: 14px 16px;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.85rem;
        }

        .attendance-table tbody td:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .attendance-table tbody td:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .status-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-status {
            padding: 6px 12px;
            border: 2px solid;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.75rem;
            transition: all 0.3s ease;
            background: transparent;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-status.present {
            border-color: var(--success);
            color: var(--success);
        }

        .btn-status.present.active {
            background: var(--success);
            color: white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-status.late {
            border-color: var(--warning);
            color: var(--warning);
        }

        .btn-status.late.active {
            background: var(--warning);
            color: white;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }

        .btn-status.absent {
            border-color: var(--danger);
            color: var(--danger);
        }

        .btn-status.absent.active {
            background: var(--danger);
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .btn-status.excused {
            border-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-status.excused.active {
            background: var(--primary-blue);
            color: white;
            box-shadow: 0 2px 8px rgba(74, 144, 226, 0.3);
        }

        .time-input {
            padding: 8px 12px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            width: 100%;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .time-input:focus {
            outline: none;
            border-color: var(--accent-purple);
            background: white;
            box-shadow: 0 0 0 4px rgba(181, 101, 216, 0.1);
        }

        .time-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .remarks-input {
            padding: 8px 12px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            width: 100%;
            resize: none;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .remarks-input:focus {
            outline: none;
            border-color: var(--accent-purple);
            background: white;
            box-shadow: 0 0 0 4px rgba(181, 101, 216, 0.1);
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

        .excuse-letter-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .excuse-filename a,
        .excuse-filename.uploaded {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }

        .excuse-filename a:hover {
            text-decoration: underline;
        }

        .btn-upload-excuse {
            padding: 6px 12px;
            background: linear-gradient(135deg, var(--accent-purple), var(--primary-blue));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn-upload-excuse:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .excuse-filename {
            font-size: 0.75rem;
            color: var(--text-secondary);
            word-break: break-all;
        }

        .excuse-filename.uploaded {
            color: var(--accent-green);
            font-weight: 600;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .bulk-actions {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            padding: 15px;
            background: linear-gradient(135deg, rgba(255, 150, 113, 0.1), rgba(255, 111, 145, 0.1));
            border-radius: 12px;
            border: 2px solid var(--border-color);
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

        .btn-bulk {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow);
        }

        .btn-bulk.all-present {
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            color: white;
        }

        .btn-bulk.all-absent {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
        }

        .btn-bulk:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .student-id-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
            box-shadow: var(--shadow);
        }

        .gender-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            font-size: 0.7rem;
        }

        .gender-icon.male {
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            color: white;
        }

        .gender-icon.female {
            background: linear-gradient(135deg, var(--accent-pink), var(--accent-purple));
            color: white;
        }

        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .status-buttons {
                flex-direction: column;
            }

            .bulk-actions {
                flex-direction: column;
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
                <div class="sidebar-nav">
                    <div class="nav-item" onclick="location.href='sections.php'">
                        <span>Sections</span>
                    </div>
                </div>
                <div class="nav-item" onclick="location.href='students.php'">
                    <span>Students</span>
                </div>
                <div class="nav-item active">
                    <span>Attendance</span>
                </div>
                <div class="nav-item" onclick="location.href='records.php'">
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
                <h1 class="page-title">Mark Attendance</h1>
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

            <!-- Filter Section -->
            <div class="filter-section">
                <h3>
                    <i class="fas fa-filter"></i>
                    Select Class
                </h3>
                <div class="filter-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Attendance Date *</label>
                        <input type="date" id="attendance_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-graduation-cap"></i> Grade Level *</label>
                        <select id="grade_level_id" onchange="loadSections()">
                            <option value="">Select Grade Level</option>
                            <?php while ($grade = $grade_levels->fetch_assoc()): ?>
                                <option value="<?php echo $grade['id']; ?>">
                                    <?php echo htmlspecialchars($grade['grade_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-users"></i> Section *</label>
                        <select id="section_id" disabled>
                            <option value="">Select Grade First</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <button class="btn-load" onclick="loadStudents()">
                            <i class="fas fa-download"></i>
                            Load Students
                        </button>
                    </div>
                </div>
            </div>

            <!-- Attendance Section -->
            <div id="attendanceSection" class="attendance-section">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-clipboard-list"></i>
                        <span id="classInfo">Students List</span>
                    </div>
                    <button class="btn-save-all" onclick="saveAllAttendance()">
                        <i class="fas fa-save"></i>
                        Save Attendance
                    </button>
                </div>

                <div class="bulk-actions">
                    <button class="btn-bulk all-present" onclick="markAllStatus('Present')">
                        <i class="fas fa-check-circle"></i>
                        Mark All Present
                    </button>
                    <button class="btn-bulk all-absent" onclick="markAllStatus('Absent')">
                        <i class="fas fa-times-circle"></i>
                        Mark All Absent
                    </button>
                </div>

                <div id="attendanceTableContainer"></div>
            </div>
        </main>
    </div>

    <script>
        let studentsData = [];
        let isSaving = false;

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

        function loadSections() {
            const gradeId = document.getElementById('grade_level_id').value;
            const sectionSelect = document.getElementById('section_id');

            if (!gradeId) {
                sectionSelect.innerHTML = '<option value="">Select Grade First</option>';
                sectionSelect.disabled = true;
                return;
            }

            sectionSelect.disabled = false;
            sectionSelect.innerHTML = '<option value="">Loading...</option>';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_sections');
            formData.append('grade_id', gradeId);

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        sectionSelect.innerHTML = '<option value="">Select Section</option>';
                        data.data.forEach(section => {
                            const option = document.createElement('option');
                            option.value = section.id;
                            option.textContent = `${section.name}${section.room_number ? ' (Room ' + section.room_number + ')' : ''}`;
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

        function loadStudents() {
            const attendance_date = document.getElementById('attendance_date').value;
            const grade_level_id = document.getElementById('grade_level_id').value;
            const section_id = document.getElementById('section_id').value;

            if (!attendance_date || !grade_level_id || !section_id) {
                showMessage('error', 'Please fill in all required fields');
                return;
            }

            const container = document.getElementById('attendanceTableContainer');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><p>Loading students...</p></div>';

            document.getElementById('attendanceSection').classList.add('active');

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_students');
            formData.append('attendance_date', attendance_date);
            formData.append('grade_level_id', grade_level_id);
            formData.append('section_id', section_id);

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        studentsData = data.data;

                        if (studentsData.length === 0) {
                            container.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-users-slash"></i>
                                <h3>No Students Found</h3>
                                <p>No students found for the selected class</p>
                            </div>
                        `;
                            return;
                        }

                        renderAttendanceTable();

                        const gradeText = document.getElementById('grade_level_id').selectedOptions[0].text;
                        const sectionText = document.getElementById('section_id').selectedOptions[0].text;
                        document.getElementById('classInfo').textContent =
                            `${gradeText} - Section ${sectionText}`;

                        showMessage('success', `Loaded ${studentsData.length} students`);
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
                        <p>Failed to load students</p>
                    </div>
                `;
                });
        }

        function renderAttendanceTable() {
            const container = document.getElementById('attendanceTableContainer');
            const currentTime = new Date().toTimeString().slice(0, 5);

            let html = `
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th style="width: 5%">#</th>
                            <th style="width: 12%">Student ID</th>
                            <th style="width: 23%">Full Name</th>
                            <th style="width: 5%">Gender</th>
                            <th style="width: 25%">Status</th>
                            <th style="width: 10%">Time In</th>
                            <th style="width: 10%">Remarks</th>
                            <th style="width: 12%">Excuse Letter</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            studentsData.forEach((student, index) => {
                const status = student.status || 'Present';
                const time_in = student.time_in || currentTime;
                const time_out = student.time_out || '';
                const remarks = student.remarks || '';
                const excuse_letter = student.excuse_letter || '';
                const genderClass = student.gender.toLowerCase();
                const genderIcon = student.gender === 'Male' ? 'mars' : 'venus';

                html += `
                    <tr data-student-id="${student.id}">
                        <td>${index + 1}</td>
                        <td>
                            <span class="student-id-badge">
                                ${student.student_id}
                            </span>
                        </td>
                        <td><strong>${student.full_name}</strong></td>
                        <td>
                            <div class="gender-icon ${genderClass}" title="${student.gender}">
                                <i class="fas fa-${genderIcon}"></i>
                            </div>
                        </td>
                        <td>
                            <div class="status-buttons">
                                <button class="btn-status present ${status === 'Present' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Present')" title="Present">
                                    <i class="fas fa-check"></i> P
                                </button>
                                <button class="btn-status late ${status === 'Late' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Late')" title="Late">
                                    <i class="fas fa-clock"></i> L
                                </button>
                                <button class="btn-status absent ${status === 'Absent' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Absent')" title="Absent">
                                    <i class="fas fa-times"></i> A
                                </button>
                                <button class="btn-status excused ${status === 'Excused' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Excused')" title="Excused">
                                    <i class="fas fa-file-medical"></i> E
                                </button>
                            </div>
                        </td>
                        <td>
                            <input type="time" class="time-input time-in" 
                                   data-student-id="${student.id}"
                                   value="${time_in}"
                                   ${status === 'Absent' ? 'disabled' : ''}>
                        </td>
                        <td>
                            <input type="text" class="remarks-input" 
                                data-student-id="${student.id}"
                                value="${remarks}"
                                placeholder="Remarks...">
                        </td>
                        <td>
                            <div class="excuse-letter-container" data-student-id="${student.id}" style="display: ${status === 'Excused' ? 'block' : 'none'};">
                                <input type="file" 
                                    class="excuse-letter-input" 
                                    data-student-id="${student.id}"
                                    accept=".jpg,.jpeg,.png,.pdf"
                                    style="display: none;"
                                    onchange="handleExcuseLetterUpload(${student.id}, this)">
                                <button type="button" 
                                        class="btn-upload-excuse" 
                                        onclick="document.querySelector('.excuse-letter-input[data-student-id=\\'${student.id}\\']').click()">
                                    <i class="fas fa-upload"></i> Upload
                                </button>
                                ${excuse_letter ? `<a href="uploads/excuse_letters/${excuse_letter}" target="_blank" class="excuse-filename uploaded" data-student-id="${student.id}"><i class="fas fa-file"></i> View Letter</a>` : `<span class="excuse-filename" data-student-id="${student.id}"></span>`}
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

        function setStatus(studentId, status) {
            const row = document.querySelector(`tr[data-student-id="${studentId}"]`);
            const buttons = row.querySelectorAll('.btn-status');
            const timeInInput = row.querySelector('.time-in');
            const timeOutInput = row.querySelector('.time-out');
            const excuseContainer = row.querySelector('.excuse-letter-container');

            buttons.forEach(btn => btn.classList.remove('active'));
            row.querySelector(`.btn-status.${status.toLowerCase()}`).classList.add('active');

            if (status === 'Absent') {
                timeInInput.value = '';
                timeInInput.disabled = true;
                timeOutInput.value = '';
                timeOutInput.disabled = true;
                if (excuseContainer) excuseContainer.style.display = 'none';
            } else {
                timeInInput.disabled = false;
                timeOutInput.disabled = false;
                if (!timeInInput.value) {
                    timeInInput.value = new Date().toTimeString().slice(0, 5);
                }

                // Show excuse letter upload for Excused status
                if (excuseContainer) {
                    excuseContainer.style.display = status === 'Excused' ? 'block' : 'none';
                }
            }

            const studentIndex = studentsData.findIndex(s => s.id == studentId);
            if (studentIndex !== -1) {
                studentsData[studentIndex].status = status;
                // Clear excuse letter if not excused
                if (status !== 'Excused') {
                    studentsData[studentIndex].excuse_letter = null;
                }
            }
        }

        function markAllStatus(status) {
            studentsData.forEach(student => {
                setStatus(student.id, status);
            });
            showMessage('success', `All students marked as ${status}`);
        }

        function saveAllAttendance() {
            if (isSaving) {
                return;
            }

            const attendance_date = document.getElementById('attendance_date').value;
            const attendanceData = [];

            studentsData.forEach(student => {
                const row = document.querySelector(`tr[data-student-id="${student.id}"]`);
                if (!row) return;

                const statusBtn = row.querySelector('.btn-status.active');
                let status = 'Present';
                if (statusBtn) {
                    if (statusBtn.classList.contains('present')) status = 'Present';
                    else if (statusBtn.classList.contains('late')) status = 'Late';
                    else if (statusBtn.classList.contains('absent')) status = 'Absent';
                    else if (statusBtn.classList.contains('excused')) status = 'Excused';
                }

                const timeInInput = row.querySelector('.time-in');
                const timeOutInput = row.querySelector('.time-out');
                const time_in = timeInInput.disabled ? '' : timeInInput.value;
                const time_out = timeOutInput.disabled ? '' : timeOutInput.value;
                const remarks = row.querySelector('.remarks-input').value;

                const studentData = studentsData.find(s => s.id == student.id);
                const excuse_letter = studentData && studentData.excuse_letter ? studentData.excuse_letter : null;

                attendanceData.push({
                    student_id: student.id,
                    status: status,
                    time_in: time_in,
                    time_out: time_out,
                    remarks: remarks,
                    excuse_letter: excuse_letter
                });
            });

            if (attendanceData.length === 0) {
                showMessage('error', 'No attendance data to save');
                return;
            }

            isSaving = true;
            const saveBtn = document.querySelector('.btn-save-all');
            const originalContent = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'save_attendance');
            formData.append('attendance_date', attendance_date);
            formData.append('attendance_data', JSON.stringify(attendanceData));

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    showMessage(data.success ? 'success' : 'error', data.message);
                    if (data.success) {
                        setTimeout(() => loadStudents(), 1500);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'Failed to save attendance');
                })
                .finally(() => {
                    isSaving = false;
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalContent;
                });
        }

        function handleExcuseLetterUpload(studentId, input) {
            const file = input.files[0];
            if (!file) return;

            // Validate file size (5MB)
            if (file.size > 5000000) {
                showMessage('error', 'File size must be less than 5MB');
                input.value = '';
                return;
            }

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
            if (!allowedTypes.includes(file.type)) {
                showMessage('error', 'Only JPG, PNG, and PDF files are allowed');
                input.value = '';
                return;
            }

            const attendance_date = document.getElementById('attendance_date').value;
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'upload_excuse_letter');
            formData.append('student_id', studentId);
            formData.append('attendance_date', attendance_date);
            formData.append('excuse_letter', file);

            // Show uploading message
            const filenameSpan = document.querySelector(`.excuse-filename[data-student-id="${studentId}"]`);
            filenameSpan.textContent = 'Uploading...';
            filenameSpan.className = 'excuse-filename';

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        filenameSpan.textContent = file.name;
                        filenameSpan.className = 'excuse-filename uploaded';

                        // Store filename for saving
                        const studentIndex = studentsData.findIndex(s => s.id == studentId);
                        if (studentIndex !== -1) {
                            studentsData[studentIndex].excuse_letter = data.data.filename;
                        }

                        showMessage('success', 'Excuse letter uploaded successfully');
                    } else {
                        filenameSpan.textContent = '';
                        showMessage('error', data.message);
                        input.value = '';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    filenameSpan.textContent = '';
                    showMessage('error', 'Failed to upload excuse letter');
                    input.value = '';
                });
        }
    </script>
</body>

</html>
<?php
$conn->close();
?>
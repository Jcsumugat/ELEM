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
    
    if ($action === 'get_courses') {
        $department_id = (int)$_POST['department_id'];
        $stmt = $conn->prepare("SELECT id, code, name FROM courses WHERE department_id = ? AND is_active = 1 ORDER BY name");
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
        jsonResponse(true, 'Courses found', $courses);
        exit;
    }
    
    if ($action === 'get_students') {
        $department_id = (int)$_POST['department_id'];
        $course_id = (int)$_POST['course_id'];
        $year_level = sanitizeInput($_POST['year_level']);
        $section_id = (int)$_POST['section_id'];
        $attendance_date = sanitizeInput($_POST['attendance_date']);
        
        $query = "SELECT 
            s.id,
            s.student_id,
            CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as full_name,
            s.email,
            a.id as attendance_id,
            a.status,
            a.time_in,
            a.remarks
            FROM students s
            LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date = ?
            WHERE s.is_active = 1 
            AND s.department_id = ?
            AND s.course_id = ?
            AND s.year_level = ?
            AND s.section_id = ?
            ORDER BY s.lastname, s.firstname";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("siisi", $attendance_date, $department_id, $course_id, $year_level, $section_id);
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
            
            foreach ($attendanceData as $record) {
                $student_id = (int)$record['student_id'];
                $status = sanitizeInput($record['status']);
                $time_in = sanitizeInput($record['time_in']);
                $remarks = sanitizeInput($record['remarks'] ?? '');
                
                // Validate status
                if (!in_array($status, ['Present', 'Late', 'Absent'])) {
                    continue;
                }
                
                // If absent, clear time_in
                if ($status === 'Absent') {
                    $time_in = null;
                } elseif (empty($time_in)) {
                    $time_in = date('H:i:s');
                } else {
                    // Ensure time format is correct
                    $time_in = date('H:i:s', strtotime($time_in));
                }
                
                // Check if attendance already exists
                $checkStmt = $conn->prepare("SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ?");
                $checkStmt->bind_param("is", $student_id, $attendance_date);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->fetch_assoc();
                
                if ($existing) {
                    // Update existing record
                    if ($time_in !== null) {
                        $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->bind_param("sssi", $status, $time_in, $remarks, $existing['id']);
                    } else {
                        $stmt = $conn->prepare("UPDATE attendance SET status = ?, time_in = NULL, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->bind_param("ssi", $status, $remarks, $existing['id']);
                    }
                } else {
                    // Insert new record
                    if ($time_in !== null) {
                        $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, time_in, remarks) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("issss", $student_id, $attendance_date, $status, $time_in, $remarks);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, status, time_in, remarks) VALUES (?, ?, ?, NULL, ?)");
                        $stmt->bind_param("isss", $student_id, $attendance_date, $status, $remarks);
                    }
                }
                
                if ($stmt->execute()) {
                    $success_count++;
                }
            }
            
            $conn->commit();
            jsonResponse(true, "Attendance saved successfully ({$success_count} records)");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Attendance save error: " . $e->getMessage());
            jsonResponse(false, 'Failed to save attendance. Please try again.');
            exit;
        }
    }
    
    // If action not recognized
    jsonResponse(false, 'Invalid action');
    exit;
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
    <title>Mark Attendance - Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .filter-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
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
            font-weight: 500;
        }

        .filter-group select,
        .filter-group input {
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.95rem;
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

        .btn-load {
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin-top: auto;
        }

        .btn-load:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(74, 144, 226, 0.3);
        }

        .btn-load:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .attendance-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 25px;
            display: none;
        }

        .attendance-section.active {
            display: block;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(157, 78, 221, 0.2);
        }

        .section-title {
            color: var(--text-light);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-save-all {
            padding: 12px 25px;
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
        }

        .btn-save-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 208, 132, 0.3);
        }

        .btn-save-all:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table thead th {
            background: rgba(157, 78, 221, 0.1);
            color: var(--text-secondary);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-table tbody td {
            padding: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-light);
        }

        .attendance-table tbody tr:hover {
            background: rgba(157, 78, 221, 0.05);
        }

        .status-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-status {
            padding: 8px 15px;
            border: 2px solid;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            background: transparent;
        }

        .btn-status.present {
            border-color: var(--success);
            color: var(--success);
        }

        .btn-status.present.active {
            background: var(--success);
            color: white;
        }

        .btn-status.late {
            border-color: var(--warning);
            color: var(--warning);
        }

        .btn-status.late.active {
            background: var(--warning);
            color: white;
        }

        .btn-status.absent {
            border-color: var(--error);
            color: var(--error);
        }

        .btn-status.absent.active {
            background: var(--error);
            color: white;
        }

        .time-input {
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            width: 120px;
        }

        .time-input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .remarks-input {
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            width: 100%;
            resize: none;
        }

        .remarks-input:focus {
            outline: none;
            border-color: var(--accent-green);
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

        .bulk-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(157, 78, 221, 0.05);
            border-radius: 10px;
        }

        .btn-bulk {
            padding: 8px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-bulk.all-present {
            background: rgba(0, 208, 132, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .btn-bulk.all-absent {
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
            border: 1px solid var(--error);
        }

        .btn-bulk:hover {
            transform: translateY(-2px);
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
                <div class="nav-item active">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Mark Attendance</span>
                </div>
                <div class="nav-item" onclick="location.href='records.php'">
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
                <h1 class="page-title">Mark Attendance</h1>
                <div class="header-actions">
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['fullname'], 0, 2)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
                    </div>
                </div>
            </div>

            <div id="message-container"></div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 style="color: var(--text-light); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-filter"></i>
                    Select Class
                </h3>
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Attendance Date *</label>
                        <input type="date" id="attendance_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="filter-group">
                        <label>Department *</label>
                        <select id="department_id" onchange="loadCourses()">
                            <option value="">Select Department</option>
                            <?php while ($dept = $departments->fetch_assoc()): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Course *</label>
                        <select id="course_id" disabled>
                            <option value="">Select Department First</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Year Level *</label>
                        <select id="year_level">
                            <option value="">Select Year</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Section *</label>
                        <select id="section_id">
                            <option value="">Select Section</option>
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

        function loadCourses() {
            const departmentId = document.getElementById('department_id').value;
            const courseSelect = document.getElementById('course_id');
            
            if (!departmentId) {
                courseSelect.innerHTML = '<option value="">Select Department First</option>';
                courseSelect.disabled = true;
                return;
            }
            
            courseSelect.disabled = false;
            courseSelect.innerHTML = '<option value="">Loading...</option>';
            
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_courses');
            formData.append('department_id', departmentId);
            
            fetch('attendance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    courseSelect.innerHTML = '<option value="">Select Course</option>';
                    data.data.forEach(course => {
                        const option = document.createElement('option');
                        option.value = course.id;
                        option.textContent = `${course.code} - ${course.name}`;
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

        function loadStudents() {
            const attendance_date = document.getElementById('attendance_date').value;
            const department_id = document.getElementById('department_id').value;
            const course_id = document.getElementById('course_id').value;
            const year_level = document.getElementById('year_level').value;
            const section_id = document.getElementById('section_id').value;

            if (!attendance_date || !department_id || !course_id || !year_level || !section_id) {
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
            formData.append('department_id', department_id);
            formData.append('course_id', course_id);
            formData.append('year_level', year_level);
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
                    
                    const deptText = document.getElementById('department_id').selectedOptions[0].text.split(' - ')[0];
                    const courseText = document.getElementById('course_id').selectedOptions[0].text.split(' - ')[0];
                    document.getElementById('classInfo').textContent = 
                        `${deptText} - ${courseText} - ${year_level} - Section ${document.getElementById('section_id').selectedOptions[0].text}`;
                    
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
                            <th style="width: 25%">Full Name</th>
                            <th style="width: 20%">Status</th>
                            <th style="width: 13%">Time In</th>
                            <th style="width: 25%">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            studentsData.forEach((student, index) => {
                const status = student.status || 'Present';
                const time_in = student.time_in || currentTime;
                const remarks = student.remarks || '';

                html += `
                    <tr data-student-id="${student.id}">
                        <td>${index + 1}</td>
                        <td>${student.student_id}</td>
                        <td>${student.full_name}</td>
                        <td>
                            <div class="status-buttons">
                                <button class="btn-status present ${status === 'Present' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Present')">
                                    <i class="fas fa-check"></i> Present
                                </button>
                                <button class="btn-status late ${status === 'Late' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Late')">
                                    <i class="fas fa-clock"></i> Late
                                </button>
                                <button class="btn-status absent ${status === 'Absent' ? 'active' : ''}" 
                                        onclick="setStatus(${student.id}, 'Absent')">
                                    <i class="fas fa-times"></i> Absent
                                </button>
                            </div>
                        </td>
                        <td>
                            <input type="time" class="time-input" 
                                   data-student-id="${student.id}"
                                   value="${time_in}"
                                   ${status === 'Absent' ? 'disabled' : ''}>
                        </td>
                        <td>
                            <input type="text" class="remarks-input" 
                                   data-student-id="${student.id}"
                                   value="${remarks}"
                                   placeholder="Optional remarks...">
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
            const timeInput = row.querySelector('.time-input');

            buttons.forEach(btn => btn.classList.remove('active'));
            row.querySelector(`.btn-status.${status.toLowerCase()}`).classList.add('active');

            if (status === 'Absent') {
                timeInput.value = '';
                timeInput.disabled = true;
            } else {
                timeInput.disabled = false;
                if (!timeInput.value) {
                    timeInput.value = new Date().toTimeString().slice(0, 5);
                }
            }

            const studentIndex = studentsData.findIndex(s => s.id == studentId);
            if (studentIndex !== -1) {
                studentsData[studentIndex].status = status;
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
                const status = statusBtn ? statusBtn.textContent.trim().replace(/\s+/g, ' ').split(' ').pop() : 'Present';
                const timeInput = row.querySelector('.time-input');
                const time_in = timeInput.disabled ? '' : timeInput.value;
                const remarks = row.querySelector('.remarks-input').value;

                attendanceData.push({
                    student_id: student.id,
                    status: status,
                    time_in: time_in,
                    remarks: remarks
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
    </script>
</body>
</html>
<?php
// CRITICAL: No spaces or characters before this tag!
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Handle AJAX requests FIRST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // Clear ALL buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // Set headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');

    $action = $_POST['action'] ?? '';

    try {
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

            $response = [
                'success' => true,
                'message' => 'Sections found',
                'data' => $sections
            ];

            ob_clean();
            echo json_encode($response);
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

            $response = [
                'success' => true,
                'message' => 'Students loaded',
                'data' => $students
            ];

            ob_clean();
            echo json_encode($response);
            exit;
        }

        if ($action === 'upload_excuse_letter') {
            $student_id = (int)$_POST['student_id'];
            $attendance_date = sanitizeInput($_POST['attendance_date']);

            if (isset($_FILES['excuse_letter']) && $_FILES['excuse_letter']['error'] === UPLOAD_ERR_OK) {
                if (function_exists('uploadExcuseLetter')) {
                    $upload_result = uploadExcuseLetter($_FILES['excuse_letter'], $student_id, $attendance_date);

                    $response = $upload_result['success'] ? [
                        'success' => true,
                        'message' => 'File uploaded successfully',
                        'data' => ['filename' => $upload_result['filename']]
                    ] : [
                        'success' => false,
                        'message' => $upload_result['message']
                    ];
                } else {
                    $response = [
                        'success' => false,
                        'message' => 'Upload function not available'
                    ];
                }
            } else {
                $response = [
                    'success' => false,
                    'message' => 'No file uploaded or upload error'
                ];
            }

            ob_clean();
            echo json_encode($response);
            exit;
        }

        if ($action === 'save_attendance') {
            $attendance_date = sanitizeInput($_POST['attendance_date']);
            $grade_level_id = (int)$_POST['grade_level_id'];
            $section_id = (int)$_POST['section_id'];
            $attendanceData = json_decode($_POST['attendance_data'], true);

            if (empty($attendanceData)) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'No attendance data provided']);
                exit;
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Invalid attendance data format']);
                exit;
            }

            $conn->begin_transaction();

            $session_code = 'ATT-' . date('Ymd') . '-' . $grade_level_id . $section_id . '-' . time();

            $counts = [
                'total' => count($attendanceData),
                'present' => 0,
                'late' => 0,
                'absent' => 0,
                'excused' => 0
            ];

            foreach ($attendanceData as $record) {
                $status = strtolower($record['status']);
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
            }

            $checkSession = $conn->prepare("SELECT id FROM attendance_sessions WHERE attendance_date = ? AND grade_level_id = ? AND section_id = ?");
            $checkSession->bind_param("sii", $attendance_date, $grade_level_id, $section_id);
            $checkSession->execute();
            $existingSession = $checkSession->get_result()->fetch_assoc();

            $recorded_by = $_SESSION['user_id'];

            if ($existingSession) {
                $session_id = $existingSession['id'];
                $updateSession = $conn->prepare("UPDATE attendance_sessions SET 
                total_students = ?, 
                present_count = ?, 
                late_count = ?, 
                absent_count = ?, 
                excused_count = ?,
                recorded_by = ?,
                updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?");
                $updateSession->bind_param(
                    "iiiiiii",
                    $counts['total'],
                    $counts['present'],
                    $counts['late'],
                    $counts['absent'],
                    $counts['excused'],
                    $recorded_by,
                    $session_id
                );
                $updateSession->execute();
            } else {
                $insertSession = $conn->prepare("INSERT INTO attendance_sessions 
                (session_code, attendance_date, grade_level_id, section_id, total_students, present_count, late_count, absent_count, excused_count, recorded_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insertSession->bind_param(
                    "ssiiiiiiii",
                    $session_code,
                    $attendance_date,
                    $grade_level_id,
                    $section_id,
                    $counts['total'],
                    $counts['present'],
                    $counts['late'],
                    $counts['absent'],
                    $counts['excused'],
                    $recorded_by
                );
                $insertSession->execute();
                $session_id = $conn->insert_id;
            }

            $success_count = 0;

            foreach ($attendanceData as $record) {
                $student_id = (int)$record['student_id'];
                $status = sanitizeInput($record['status']);
                $time_in = sanitizeInput($record['time_in']);
                $time_out = sanitizeInput($record['time_out'] ?? '');
                $remarks = sanitizeInput($record['remarks'] ?? '');
                $excuse_letter = $record['excuse_letter'] ?? null;

                if (!in_array($status, ['Present', 'Late', 'Absent', 'Excused'])) {
                    continue;
                }

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

                $checkStmt = $conn->prepare("SELECT id, excuse_letter FROM attendance WHERE student_id = ? AND attendance_date = ?");
                $checkStmt->bind_param("is", $student_id, $attendance_date);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->fetch_assoc();

                if ($existing) {
                    if ($existing['excuse_letter'] && ($status !== 'Excused' || $excuse_letter)) {
                        if (function_exists('deleteExcuseLetter')) {
                            deleteExcuseLetter($existing['excuse_letter']);
                        }
                    }

                    if ($time_in !== null) {
                        if ($time_out !== null) {
                            $stmt = $conn->prepare("UPDATE attendance SET session_id = ?, status = ?, time_in = ?, time_out = ?, remarks = ?, excuse_letter = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("isssssii", $session_id, $status, $time_in, $time_out, $remarks, $excuse_letter, $recorded_by, $existing['id']);
                        } else {
                            $stmt = $conn->prepare("UPDATE attendance SET session_id = ?, status = ?, time_in = ?, time_out = NULL, remarks = ?, excuse_letter = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmt->bind_param("issssii", $session_id, $status, $time_in, $remarks, $excuse_letter, $recorded_by, $existing['id']);
                        }
                    } else {
                        $stmt = $conn->prepare("UPDATE attendance SET session_id = ?, status = ?, time_in = NULL, time_out = NULL, remarks = ?, excuse_letter = ?, recorded_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->bind_param("isssii", $session_id, $status, $remarks, $excuse_letter, $recorded_by, $existing['id']);
                    }
                } else {
                    if ($time_in !== null) {
                        if ($time_out !== null) {
                            $stmt = $conn->prepare("INSERT INTO attendance (session_id, student_id, attendance_date, status, time_in, time_out, remarks, excuse_letter, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("iissssssi", $session_id, $student_id, $attendance_date, $status, $time_in, $time_out, $remarks, $excuse_letter, $recorded_by);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO attendance (session_id, student_id, attendance_date, status, time_in, remarks, excuse_letter, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("iisssssi", $session_id, $student_id, $attendance_date, $status, $time_in, $remarks, $excuse_letter, $recorded_by);
                        }
                    } else {
                        $stmt = $conn->prepare("INSERT INTO attendance (session_id, student_id, attendance_date, status, remarks, excuse_letter, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iissssi", $session_id, $student_id, $attendance_date, $status, $remarks, $excuse_letter, $recorded_by);
                    }
                }

                if ($stmt->execute()) {
                    $success_count++;
                }
            }

            $conn->commit();

            if (function_exists('logActivity')) {
                logActivity($conn, $_SESSION['user_id'], 'Save Attendance', "Saved attendance session: $session_code with $success_count students");
            }

            $response = [
                'success' => true,
                'message' => "Attendance saved successfully (Session: $session_code)",
                'data' => ['session_id' => $session_id]
            ];

            ob_clean();
            echo json_encode($response);
            exit;
        }

        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    } catch (Exception $e) {
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        error_log("Attendance error: " . $e->getMessage());
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}
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
    <link rel="stylesheet" href="attendance.css">
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
                const showExcuseLetter = (status === 'Excused') ? 'flex' : 'none';

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
                    <div class="excuse-letter-container" data-student-id="${student.id}" style="display: ${showExcuseLetter};">
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
                        ${excuse_letter ? 
                            `<a href="uploads/excuse_letters/${excuse_letter}" target="_blank" class="excuse-filename uploaded">
                                <i class="fas fa-file"></i> View Letter
                            </a>` 
                            : 
                            `<span class="excuse-filename" data-student-id="${student.id}"></span>`
                        }
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
            const excuseContainer = row.querySelector('.excuse-letter-container');

            buttons.forEach(btn => btn.classList.remove('active'));

            row.querySelector(`.btn-status.${status.toLowerCase()}`).classList.add('active');

            if (status === 'Absent') {
                timeInInput.value = '';
                timeInInput.disabled = true;
                if (excuseContainer) excuseContainer.style.display = 'none';
            } else {
                timeInInput.disabled = false;
                if (!timeInInput.value) {
                    timeInInput.value = new Date().toTimeString().slice(0, 5);
                }

                if (excuseContainer) {
                    excuseContainer.style.display = status === 'Excused' ? 'flex' : 'none';
                }
            }

            const studentIndex = studentsData.findIndex(s => s.id == studentId);
            if (studentIndex !== -1) {
                studentsData[studentIndex].status = status;
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
            const grade_level_id = document.getElementById('grade_level_id').value;
            const section_id = document.getElementById('section_id').value;

            // Validate required fields
            if (!attendance_date || !grade_level_id || !section_id) {
                showMessage('error', 'Please select date, grade level, and section');
                return;
            }

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
                const time_in = timeInInput && !timeInInput.disabled ? timeInInput.value : '';

                const time_out = '';

                const remarksInput = row.querySelector('.remarks-input');
                const remarks = remarksInput ? remarksInput.value : '';

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
            formData.append('grade_level_id', grade_level_id);
            formData.append('section_id', section_id);
            formData.append('attendance_data', JSON.stringify(attendanceData));

            fetch('attendance.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    // Log the response text first to see what's actually returned
                    return response.text().then(text => {
                        console.log('Raw response:', text);
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            console.error('Response text:', text);
                            throw new Error('Invalid JSON response from server');
                        }
                    });
                })
                .then(data => {
                    showMessage(data.success ? 'success' : 'error', data.message);
                    if (data.success) {
                        setTimeout(() => loadStudents(), 1500);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'Failed to save attendance: ' + error.message);
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
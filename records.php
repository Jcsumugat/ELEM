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

    if ($action === 'get_sessions') {
        $date_from = sanitizeInput($_POST['date_from'] ?? '');
        $date_to = sanitizeInput($_POST['date_to'] ?? '');
        $grade_level_id = (int)($_POST['grade_level_id'] ?? 0);
        $section_id = (int)($_POST['section_id'] ?? 0);

        $query = "SELECT 
            s.id,
            s.session_code,
            s.attendance_date,
            s.total_students,
            s.present_count,
            s.late_count,
            s.absent_count,
            s.excused_count,
            gl.grade_name,
            sec.name as section_name,
            u.fullname as recorded_by_name,
            s.created_at
            FROM attendance_sessions s
            JOIN grade_levels gl ON s.grade_level_id = gl.id
            JOIN sections sec ON s.section_id = sec.id
            JOIN users u ON s.recorded_by = u.id
            WHERE 1=1";

        $params = [];
        $types = "";

        if ($date_from && $date_to) {
            $query .= " AND s.attendance_date BETWEEN ? AND ?";
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

        $query .= " ORDER BY s.attendance_date DESC, s.created_at DESC";

        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $sessions[] = $row;
        }

        jsonResponse(true, 'Sessions loaded', $sessions);
        exit;
    }

    if ($action === 'get_session_details') {
        $session_id = (int)$_POST['session_id'];

        $query = "SELECT 
            a.id,
            a.status,
            a.time_in,
            a.time_out,
            a.remarks,
            a.excuse_letter,
            s.student_id,
            CONCAT(s.firstname, ' ', COALESCE(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as student_name,
            s.gender
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.session_id = ?
            ORDER BY s.lastname, s.firstname";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        jsonResponse(true, 'Session details loaded', $students);
        exit;
    }

    if ($action === 'delete_session') {
        $session_id = (int)$_POST['session_id'];

        // Get session info for logging
        $sessionInfo = $conn->query("SELECT session_code FROM attendance_sessions WHERE id = $session_id")->fetch_assoc();

        // Delete session (will cascade delete attendance records)
        $stmt = $conn->prepare("DELETE FROM attendance_sessions WHERE id = ?");
        $stmt->bind_param("i", $session_id);

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'Delete Attendance Session', "Deleted session: " . $sessionInfo['session_code']);
            jsonResponse(true, 'Session deleted successfully');
        } else {
            jsonResponse(false, 'Failed to delete session');
        }
        exit;
    }

    jsonResponse(false, 'Invalid action');
    exit;
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
$stats['month_sessions'] = $conn->query("SELECT COUNT(*) as count FROM attendance_sessions WHERE DATE_FORMAT(attendance_date, '%Y-%m') = '$currentMonth'")->fetch_assoc()['count'];
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
    <link rel="stylesheet" href="records.css">
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
                        <h4>Month Sessions</h4>
                        <p><?php echo $stats['month_sessions']; ?></p>
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
                </div>

                <div class="filter-actions">
                    <button class="btn-filter" onclick="applyFilters()">
                        <i class="fas fa-search"></i>
                        Load Sessions
                    </button>
                    <button class="btn-reset" onclick="resetFilters()">
                        <i class="fas fa-redo"></i>
                        Reset
                    </button>
                </div>
            </div>

            <!-- Records Section -->
            <div class="records-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-list"></i>
                        <span id="recordsCount">Attendance Sessions</span>
                    </h3>
                </div>

                <div id="sessionsTableContainer">
                    <div class="loading">
                        <i class="fas fa-spinner"></i>
                        <p>Loading sessions...</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Session Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-eye"></i>
                    <span id="modalTitle">Session Details</span>
                </h2>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="detailsContent">
                <div class="loading">
                    <i class="fas fa-spinner"></i>
                    <p>Loading details...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentSessions = [];

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
                });
        }

        function applyFilters() {
            const container = document.getElementById('sessionsTableContainer');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><p>Loading sessions...</p></div>';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_sessions');
            formData.append('date_from', document.getElementById('date_from').value);
            formData.append('date_to', document.getElementById('date_to').value);
            formData.append('grade_level_id', document.getElementById('filter_grade').value);
            formData.append('section_id', document.getElementById('filter_section').value);

            fetch('records.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentSessions = data.data;
                        renderSessionsTable();
                        document.getElementById('recordsCount').textContent = `${data.data.length} Session(s) Found`;
                        showMessage('success', `Loaded ${data.data.length} sessions`);
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
                        <p>Failed to load sessions</p>
                    </div>
                `;
                });
        }

        function renderSessionsTable() {
            const container = document.getElementById('sessionsTableContainer');

            if (currentSessions.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No Sessions Found</h3>
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
                            <th style="width: 15%">Date</th>
                            <th style="width: 15%">Grade & Section</th>
                            <th style="width: 10%">Total</th>
                            <th style="width: 10%">Present</th>
                            <th style="width: 10%">Late</th>
                            <th style="width: 10%">Absent</th>
                            <th style="width: 10%">Excused</th>
                            <th style="width: 15%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            currentSessions.forEach((session, index) => {
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${formatDate(session.attendance_date)}</td>
                        <td>
                            <strong>${session.grade_name}</strong><br>
                            <small>${session.section_name}</small>
                        </td>
                        <td><strong>${session.total_students}</strong></td>
                        <td><span class="status-badge status-present">${session.present_count}</span></td>
                        <td><span class="status-badge status-late">${session.late_count}</span></td>
                        <td><span class="status-badge status-absent">${session.absent_count}</span></td>
                        <td><span class="status-badge status-excused">${session.excused_count}</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view" onclick="viewSessionDetails(${session.id}, '${session.grade_name} - ${session.section_name}', '${session.attendance_date}')" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-icon btn-delete" onclick="deleteSession(${session.id})" title="Delete Session">
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
            const date = new Date(dateString + 'T00:00:00');
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

        function viewSessionDetails(sessionId, className, date) {
            document.getElementById('modalTitle').textContent = `${className} - ${formatDate(date)}`;
            document.getElementById('detailsModal').classList.add('active');
            
            const content = document.getElementById('detailsContent');
            content.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><p>Loading details...</p></div>';

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_session_details');
            formData.append('session_id', sessionId);

            fetch('records.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderSessionDetails(data.data);
                    } else {
                        content.innerHTML = `<p class="error">Failed to load details</p>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = `<p class="error">Error loading details</p>`;
                });
        }

        function renderSessionDetails(students) {
            const content = document.getElementById('detailsContent');
            
            if (students.length === 0) {
                content.innerHTML = '<p>No students found</p>';
                return;
            }

            let html = `
                <table class="records-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Gender</th>
                            <th>Status</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            students.forEach((student, index) => {
                const genderIcon = student.gender === 'Male' ? 'mars' : 'venus';
                const genderClass = student.gender.toLowerCase();
                
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td><strong>${student.student_id}</strong></td>
                        <td>${student.student_name}</td>
                        <td>
                            <div class="gender-icon ${genderClass}">
                                <i class="fas fa-${genderIcon}"></i>
                            </div>
                        </td>
                        <td><span class="status-badge status-${student.status.toLowerCase()}">${student.status}</span></td>
                        <td>${formatTime(student.time_in)}</td>
                        <td>${formatTime(student.time_out)}</td>
                        <td>${student.remarks || '-'}</td>
                    </tr>
                `;
            });

            html += '</tbody></table>';
            content.innerHTML = html;
        }

        function deleteSession(sessionId) {
            if (!confirm('Are you sure you want to delete this entire attendance session? This will delete all student attendance records for this session and cannot be undone.')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'delete_session');
            formData.append('session_id', sessionId);

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
                    showMessage('error', 'Failed to delete session');
                });
        }

        function closeModal() {
            document.getElementById('detailsModal').classList.remove('active');
        }

        function resetFilters() {
            document.getElementById('date_from').value = '<?php echo date('Y-m-01'); ?>';
            document.getElementById('date_to').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('filter_grade').value = '';
            document.getElementById('filter_section').innerHTML = '<option value="">All Sections</option>';
            applyFilters();
        }

        // Close modal when clicking outside
        document.getElementById('detailsModal').addEventListener('click', function(e) {
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

        // Load sessions on page load
        window.addEventListener('DOMContentLoaded', function() {
            applyFilters();
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>
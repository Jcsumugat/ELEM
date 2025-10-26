<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection(true);
$user_id = $_SESSION['user_id'];

$userQuery = $conn->prepare("SELECT fullname, email, role FROM users WHERE id = ?");
$userQuery->bind_param("i", $user_id);
$userQuery->execute();
$result = $userQuery->get_result();
$user = $result->fetch_assoc();

if (!isset($_SESSION['dashboard_filters'])) {
    $_SESSION['dashboard_filters'] = [
        'grade' => '',
        'section' => '',
        'subject' => '',
        'date_from' => date('Y-m-01'),
        'date_to' => date('Y-m-d')
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_filters'])) {
    $_SESSION['dashboard_filters'] = [
        'grade' => $_POST['filter_grade'] ?? '',
        'section' => $_POST['filter_section'] ?? '',
        'subject' => $_POST['filter_subject'] ?? '',
        'date_from' => $_POST['filter_date_from'] ?? date('Y-m-01'),
        'date_to' => $_POST['filter_date_to'] ?? date('Y-m-d')
    ];
}

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
}

$filters = $_SESSION['dashboard_filters'];

$whereConditions = ["s.is_active = 1"];
$params = [];
$types = "";

if (!empty($filters['grade'])) {
    $whereConditions[] = "s.grade_level_id = ?";
    $params[] = $filters['grade'];
    $types .= "i";
}

if (!empty($filters['section'])) {
    $whereConditions[] = "s.section_id = ?";
    $params[] = $filters['section'];
    $types .= "i";
}

$whereClause = implode(" AND ", $whereConditions);

$studentQuery = "SELECT COUNT(*) as total FROM students s WHERE $whereClause";
if (!empty($params)) {
    $stmt = $conn->prepare($studentQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalStudents = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $totalStudents = $conn->query($studentQuery)->fetch_assoc()['total'];
}

$today = date('Y-m-d');
$todayQuery = "SELECT COUNT(DISTINCT a.student_id) as present 
    FROM attendance a 
    JOIN students s ON a.student_id = s.id 
    WHERE a.attendance_date = ? AND a.status IN ('Present', 'Late') AND $whereClause";

$todayParams = array_merge([$today], $params);
$todayTypes = "s" . $types;

$stmt = $conn->prepare($todayQuery);
if (!empty($todayParams)) {
    $stmt->bind_param($todayTypes, ...$todayParams);
}
$stmt->execute();
$todayPresent = $stmt->get_result()->fetch_assoc()['present'];

$todayAbsent = $totalStudents - $todayPresent;

$attendanceRateQuery = "SELECT 
    COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) as present_count,
    COUNT(*) as total_count
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.attendance_date BETWEEN ? AND ? AND $whereClause";

$rateParams = array_merge([$filters['date_from'], $filters['date_to']], $params);
$rateTypes = "ss" . $types;

$stmt = $conn->prepare($attendanceRateQuery);
$stmt->bind_param($rateTypes, ...$rateParams);
$stmt->execute();
$monthAttendance = $stmt->get_result()->fetch_assoc();

$attendanceRate = $monthAttendance['total_count'] > 0
    ? round(($monthAttendance['present_count'] / $monthAttendance['total_count']) * 100, 1)
    : 0;

$chartQuery = "SELECT 
    DATE_FORMAT(a.attendance_date, '%b %d') as date_label,
    COUNT(CASE WHEN a.status IN ('Present', 'Late') THEN 1 END) as present,
    COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND $whereClause
    GROUP BY a.attendance_date
    ORDER BY a.attendance_date ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($chartQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $recentQuery = $stmt->get_result();
} else {
    $recentQuery = $conn->query($chartQuery);
}

$chartLabels = [];
$presentData = [];
$absentData = [];

while ($row = $recentQuery->fetch_assoc()) {
    $chartLabels[] = $row['date_label'];
    $presentData[] = (int)$row['present'];
    $absentData[] = (int)$row['absent'];
}

$latestQuery = "SELECT 
    a.id,
    s.student_id,
    CONCAT(s.firstname, ' ', IFNULL(CONCAT(LEFT(s.middlename, 1), '. '), ''), s.lastname) as student_name,
    gl.grade_name,
    sec.name as section_name,
    a.attendance_date,
    a.time_in,
    a.status
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE $whereClause
    ORDER BY a.created_at DESC
    LIMIT 10";

if (!empty($params)) {
    $stmt = $conn->prepare($latestQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $latestRecords = $stmt->get_result();
} else {
    $latestRecords = $conn->query($latestQuery);
}

$grade_levels = $conn->query("SELECT id, grade_number, grade_name FROM grade_levels WHERE is_active = 1 ORDER BY grade_number");

$sections = $conn->query("SELECT id, name FROM sections WHERE is_active = 1 ORDER BY name");

$subjects = $conn->query("SELECT id, code, name FROM subjects WHERE is_active = 1 ORDER BY name");

$filter_sections = null;
if (!empty($filters['grade'])) {
    $stmt = $conn->prepare("SELECT id, name FROM sections WHERE grade_level_id = ? AND is_active = 1 ORDER BY name");
    $stmt->bind_param("i", $filters['grade']);
    $stmt->execute();
    $filter_sections = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elementary Attendance Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <link rel="stylesheet" href="elementary_dashboard.css">
</head>
<body>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Elementary School</h2>
                <p>Attendance System</p>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item active">
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="location.href='students.php'">
                    <span>Students</span>
                </div>
                <div class="nav-item" onclick="location.href='attendance.php'">
                    <span>Mark Attendance</span>
                </div>
                <div class="nav-item" onclick="location.href='records.php'">
                    <span>Attendance Records</span>
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
                <h1 class="page-title">Dashboard</h1>
                <div class="header-actions">
                    <div class="date-display">
                        <i class="fas fa-calendar-day"></i>
                        <span><?php echo date('l, F d, Y'); ?></span>
                    </div>
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($user['fullname'], 0, 2)); ?></div>
                        <span><?php echo htmlspecialchars($user['fullname']); ?></span>
                    </div>
                </div>
            </div>

            <div id="message-container"></div>

            <div class="filter-section">
                <div class="filter-header">
                    <h3>
                        <i class="fas fa-filter"></i>
                        Filters
                    </h3>
                    <button class="filter-toggle" onclick="toggleFilters()">
                        <i class="fas fa-chevron-down" id="filterIcon"></i>
                        <span id="filterToggleText">Show Filters</span>
                    </button>
                </div>

                <?php if (!empty($filters['grade']) || !empty($filters['section']) || !empty($filters['subject'])): ?>
                <div class="active-filters">
                    <span style="color: var(--text-secondary); font-weight: 500;">Active Filters:</span>
                    <?php if (!empty($filters['grade'])): 
                        $gradeStmt = $conn->prepare("SELECT grade_name FROM grade_levels WHERE id = ?");
                        $gradeStmt->bind_param("i", $filters['grade']);
                        $gradeStmt->execute();
                        $gradeInfo = $gradeStmt->get_result()->fetch_assoc();
                    ?>
                        <span class="filter-badge">
                            <i class="fas fa-graduation-cap"></i>
                            <?php echo htmlspecialchars($gradeInfo['grade_name']); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($filters['section'])): 
                        $secStmt = $conn->prepare("SELECT name FROM sections WHERE id = ?");
                        $secStmt->bind_param("i", $filters['section']);
                        $secStmt->execute();
                        $secInfo = $secStmt->get_result()->fetch_assoc();
                    ?>
                        <span class="filter-badge">
                            <i class="fas fa-users"></i>
                            Section <?php echo htmlspecialchars($secInfo['name']); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($filters['subject'])): 
                        $subjStmt = $conn->prepare("SELECT name FROM subjects WHERE id = ?");
                        $subjStmt->bind_param("i", $filters['subject']);
                        $subjStmt->execute();
                        $subjInfo = $subjStmt->get_result()->fetch_assoc();
                    ?>
                        <span class="filter-badge">
                            <i class="fas fa-book"></i>
                            <?php echo htmlspecialchars($subjInfo['name']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form class="filter-content" id="filterForm" method="POST">
                    <input type="hidden" name="update_filters" value="1">
                    
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Date From</label>
                            <input type="date" name="filter_date_from" id="filter_date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                        </div>

                        <div class="filter-group">
                            <label>Date To</label>
                            <input type="date" name="filter_date_to" id="filter_date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="filter-group">
                            <label>Grade Level</label>
                            <select name="filter_grade" id="filter_grade" onchange="loadFilterSections()">
                                <option value="">All Grades</option>
                                <?php while ($grade = $grade_levels->fetch_assoc()): ?>
                                    <option value="<?php echo $grade['id']; ?>" <?php echo ($filters['grade'] == $grade['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($grade['grade_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Section</label>
                            <select name="filter_section" id="filter_section">
                                <option value="">All Sections</option>
                                <?php
                                if ($filter_sections && $filter_sections->num_rows > 0):
                                    while ($section = $filter_sections->fetch_assoc()):
                                ?>
                                        <option value="<?php echo $section['id']; ?>" <?php echo ($filters['section'] == $section['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($section['name']); ?>
                                        </option>
                                <?php
                                    endwhile;
                                endif;
                                ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Subject</label>
                            <select name="filter_subject" id="filter_subject">
                                <option value="">All Subjects</option>
                                <?php while ($subject = $subjects->fetch_assoc()): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo ($filters['subject'] == $subject['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-apply-filter">
                            <i class="fas fa-check"></i>
                            Apply Filters
                        </button>
                        <button type="button" class="btn-clear-filter" onclick="clearFilters()">
                            <i class="fas fa-times"></i>
                            Clear Filters
                        </button>
                    </div>
                </form>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Students</div>
                        <div class="stat-card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($totalStudents); ?></div>
                    <div class="stat-card-change">
                        <i class="fas fa-info-circle"></i> <?php echo (!empty($filters['grade']) || !empty($filters['section'])) ? 'Filtered students' : 'Active students'; ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Present Today</div>
                        <div class="stat-card-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($todayPresent); ?></div>
                    <div class="stat-card-change">
                        <i class="fas fa-calendar-day"></i> <?php echo date('F d, Y'); ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Absent Today</div>
                        <div class="stat-card-icon">
                            <i class="fas fa-user-times"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($todayAbsent); ?></div>
                    <div class="stat-card-change">
                        <?php if ($todayAbsent > 0): ?>
                            <i class="fas fa-exclamation-triangle"></i> Requires attention
                        <?php else: ?>
                            <i class="fas fa-check-circle"></i> All present
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Attendance Rate</div>
                        <div class="stat-card-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $attendanceRate; ?>%</div>
                    <div class="stat-card-change">
                        <i class="fas fa-calendar-alt"></i> <?php echo date('M d', strtotime($filters['date_from'])) . ' - ' . date('M d', strtotime($filters['date_to'])); ?>
                    </div>
                </div>
            </div>

            <div class="chart-container">
                <h3>
                    <i class="fas fa-chart-area"></i>
                    7-Day Attendance Trend
                </h3>
                <?php if (count($chartLabels) > 0): ?>
                    <canvas id="attendanceChart" height="80"></canvas>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-light);">
                        <i class="fas fa-chart-line" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px;"></i>
                        <p>No attendance data available for the last 7 days</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-container">
                <h3>
                    <i class="fas fa-clock"></i>
                    Recent Attendance Records
                </h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Grade</th>
                            <th>Section</th>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($latestRecords->num_rows > 0): ?>
                            <?php while ($record = $latestRecords->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['grade_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['section_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></td>
                                    <td><?php echo $record['time_in'] ? date('h:i A', strtotime($record['time_in'])) : '-'; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($record['status']); ?>">
                                            <?php echo htmlspecialchars($record['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h3>No Attendance Records</h3>
                                        <p>No attendance has been recorded yet with the current filters</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
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

        function toggleFilters() {
            const filterContent = document.querySelector('.filter-content');
            const filterIcon = document.getElementById('filterIcon');
            const filterToggleText = document.getElementById('filterToggleText');

            filterContent.classList.toggle('active');

            if (filterContent.classList.contains('active')) {
                filterIcon.classList.remove('fa-chevron-down');
                filterIcon.classList.add('fa-chevron-up');
                filterToggleText.textContent = 'Hide Filters';
            } else {
                filterIcon.classList.remove('fa-chevron-up');
                filterIcon.classList.add('fa-chevron-down');
                filterToggleText.textContent = 'Show Filters';
            }
        }

        function loadFilterSections() {
            const gradeSelect = document.getElementById('filter_grade');
            const sectionSelect = document.getElementById('filter_section');
            const gradeId = gradeSelect.value;

            sectionSelect.innerHTML = '<option value="">All Sections</option>';

            if (gradeId) {
                sectionSelect.disabled = true;

                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('action', 'get_sections');
                formData.append('grade_id', gradeId);

                fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        data.data.forEach(section => {
                            const option = document.createElement('option');
                            option.value = section.id;
                            option.textContent = section.name;
                            sectionSelect.appendChild(option);
                        });
                    }
                    sectionSelect.disabled = false;
                })
                .catch(error => {
                    console.error('Error loading sections:', error);
                    sectionSelect.disabled = false;
                    showMessage('error', 'Failed to load sections');
                });
            }
        }

        function clearFilters() {
            document.getElementById('filter_grade').value = '';
            document.getElementById('filter_section').value = '';
            document.getElementById('filter_subject').value = '';
            
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const formattedFirstDay = firstDay.toISOString().split('T')[0];
            const formattedToday = today.toISOString().split('T')[0];
            
            document.getElementById('filter_date_from').value = formattedFirstDay;
            document.getElementById('filter_date_to').value = formattedToday;

            document.getElementById('filterForm').submit();
        }

        window.addEventListener('DOMContentLoaded', function() {
            const chartLabels = <?php echo json_encode($chartLabels); ?>;
            const presentData = <?php echo json_encode($presentData); ?>;
            const absentData = <?php echo json_encode($absentData); ?>;

            if (chartLabels.length > 0) {
                const ctx = document.getElementById('attendanceChart').getContext('2d');

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Present',
                            data: presentData,
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderColor: '#10B981',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: '#10B981',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#10B981',
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 2
                        }, {
                            label: 'Absent',
                            data: absentData,
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            borderColor: '#EF4444',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: '#EF4444',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#EF4444',
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: '#475569',
                                    font: {
                                        size: 13,
                                        family: 'Inter',
                                        weight: '500'
                                    },
                                    padding: 15,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(255, 255, 255, 0.95)',
                                titleColor: '#0F172A',
                                bodyColor: '#475569',
                                borderColor: '#E2E8F0',
                                borderWidth: 1,
                                padding: 12,
                                displayColors: true,
                                titleFont: {
                                    size: 13,
                                    family: 'Inter',
                                    weight: '600'
                                },
                                bodyFont: {
                                    size: 12,
                                    family: 'Inter',
                                    weight: '500'
                                },
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y + ' students';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    color: '#94A3B8',
                                    font: {
                                        size: 12,
                                        family: 'Inter'
                                    }
                                },
                                grid: {
                                    color: '#E2E8F0',
                                    drawBorder: false
                                }
                            },
                            x: {
                                ticks: {
                                    color: '#94A3B8',
                                    font: {
                                        size: 12,
                                        family: 'Inter'
                                    }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</body>
</html>
<?php
$conn->close();
?>
<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Initialize session filters if not set
if (!isset($_SESSION['student_filters'])) {
    $_SESSION['student_filters'] = [
        'department' => '',
        'course' => '',
        'year' => '',
        'section' => ''
    ];
}

// Update filters from POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_filters'])) {
    $_SESSION['student_filters'] = [
        'department' => $_POST['filter_department'] ?? '',
        'course' => $_POST['filter_course'] ?? '',
        'year' => $_POST['filter_year'] ?? '',
        'section' => $_POST['filter_section'] ?? ''
    ];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
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
    }

    if ($action === 'add') {
        $student_id = sanitizeInput($_POST['student_id']);
        $firstname = sanitizeInput($_POST['firstname']);
        $lastname = sanitizeInput($_POST['lastname']);
        $middlename = sanitizeInput($_POST['middlename']);
        $email = sanitizeInput($_POST['email']);
        $department_id = (int)$_POST['department_id'];
        $course_id = (int)$_POST['course_id'];
        $year_level = sanitizeInput($_POST['year_level']);
        $section_id = (int)$_POST['section_id'];
        $contact = sanitizeInput($_POST['contact']);

        // Check if student_id already exists
        $checkStmt = $conn->prepare("SELECT id FROM students WHERE student_id = ?");
        $checkStmt->bind_param("s", $student_id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            jsonResponse(false, 'Student ID already exists');
        }

        $stmt = $conn->prepare("INSERT INTO students (student_id, firstname, lastname, middlename, email, department_id, course_id, year_level, section_id, contact) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssiisis", $student_id, $firstname, $lastname, $middlename, $email, $department_id, $course_id, $year_level, $section_id, $contact);

        if ($stmt->execute()) {
            jsonResponse(true, 'Student added successfully');
        } else {
            jsonResponse(false, 'Failed to add student');
        }
    }

    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $student_id = sanitizeInput($_POST['student_id']);
        $firstname = sanitizeInput($_POST['firstname']);
        $lastname = sanitizeInput($_POST['lastname']);
        $middlename = sanitizeInput($_POST['middlename']);
        $email = sanitizeInput($_POST['email']);
        $department_id = (int)$_POST['department_id'];
        $course_id = (int)$_POST['course_id'];
        $year_level = sanitizeInput($_POST['year_level']);
        $section_id = (int)$_POST['section_id'];
        $contact = sanitizeInput($_POST['contact']);

        // Check if student_id exists for other students
        $checkStmt = $conn->prepare("SELECT id FROM students WHERE student_id = ? AND id != ?");
        $checkStmt->bind_param("si", $student_id, $id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            jsonResponse(false, 'Student ID already exists');
        }

        $stmt = $conn->prepare("UPDATE students SET student_id=?, firstname=?, lastname=?, middlename=?, email=?, department_id=?, course_id=?, year_level=?, section_id=?, contact=? WHERE id=?");
        $stmt->bind_param("sssssiisisi", $student_id, $firstname, $lastname, $middlename, $email, $department_id, $course_id, $year_level, $section_id, $contact, $id);

        if ($stmt->execute()) {
            jsonResponse(true, 'Student updated successfully');
        } else {
            jsonResponse(false, 'Failed to update student');
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE students SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            jsonResponse(true, 'Student deleted successfully');
        } else {
            jsonResponse(false, 'Failed to delete student');
        }
    }

    if ($action === 'get') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $student = $result->fetch_assoc();

        if ($student) {
            jsonResponse(true, 'Student found', $student);
        } else {
            jsonResponse(false, 'Student not found');
        }
    }
}

// Build query with filters
$whereConditions = ["s.is_active = 1"];
$params = [];
$types = "";

$filters = $_SESSION['student_filters'];

if (!empty($filters['department'])) {
    $whereConditions[] = "s.department_id = ?";
    $params[] = $filters['department'];
    $types .= "i";
}

if (!empty($filters['course'])) {
    $whereConditions[] = "s.course_id = ?";
    $params[] = $filters['course'];
    $types .= "i";
}

if (!empty($filters['year'])) {
    $whereConditions[] = "s.year_level = ?";
    $params[] = $filters['year'];
    $types .= "s";
}

if (!empty($filters['section'])) {
    $whereConditions[] = "s.section_id = ?";
    $params[] = $filters['section'];
    $types .= "i";
}

$whereClause = implode(" AND ", $whereConditions);

$query = "SELECT 
    s.*,
    d.code as department_code,
    d.name as department_name,
    c.code as course_code,
    c.name as course_name,
    sec.name as section_name
    FROM students s
    JOIN departments d ON s.department_id = d.id
    JOIN courses c ON s.course_id = c.id
    JOIN sections sec ON s.section_id = sec.id
    WHERE $whereClause
    ORDER BY s.created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result();
} else {
    $students = $conn->query($query);
}

// Get departments for dropdown
$departments = $conn->query("SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY name");

// Get sections for dropdown
$sections = $conn->query("SELECT id, name FROM sections WHERE is_active = 1 ORDER BY name");

// Get courses for the selected department filter
$filter_courses = [];
if (!empty($filters['department'])) {
    $stmt = $conn->prepare("SELECT id, code, name FROM courses WHERE department_id = ? AND is_active = 1 ORDER BY name");
    $stmt->bind_param("i", $filters['department']);
    $stmt->execute();
    $filter_courses = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students Management - Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            max-width: 400px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 12px 45px 12px 20px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 10px;
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .quick-filter-section {
            background: var(--card-bg);
            border: 1px solid rgba(157, 78, 221, 0.3);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .quick-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .quick-filter-grid .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .quick-filter-grid .filter-group label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .quick-filter-grid .filter-group select {
            padding: 10px 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(157, 78, 221, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .quick-filter-grid .filter-group select option {
            background: var(--card-bg);
            color: var(--text-light);
        }

        .quick-filter-grid .filter-group select:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .btn-apply-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            height: 42px;
        }

        .btn-apply-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(74, 144, 226, 0.3);
        }

        .btn-clear-filter {
            padding: 10px 20px;
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
            border: 2px solid var(--error);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            height: 42px;
        }

        .btn-clear-filter:hover {
            background: var(--error);
            color: white;
        }

        .add-btn {
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

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 208, 132, 0.3);
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
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
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

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-icon {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
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

        .year-section {
            display: inline-block;
            padding: 4px 10px;
            background: rgba(74, 144, 226, 0.1);
            border-radius: 6px;
            color: var(--primary-blue);
            font-weight: 600;
            font-size: 0.85rem;
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
                <div class="nav-item active">
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
                <h1 class="page-title">Students Management</h1>
                <div class="header-actions">
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['fullname'], 0, 2)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
                    </div>
                </div>
            </div>

            <div id="message-container"></div>

            <!-- Quick Filter Section -->
            <div class="quick-filter-section">
                <h3 style="color: var(--text-light); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-filter"></i>
                    Quick Filters
                </h3>
                <form method="POST" id="filterForm">
                    <input type="hidden" name="update_filters" value="1">
                    <div class="quick-filter-grid">
                        <div class="filter-group">
                            <label>Department</label>
                            <select id="quick_department" name="filter_department" onchange="loadQuickCourses()">
                                <option value="">All Departments</option>
                                <?php
                                $departments->data_seek(0);
                                while ($dept = $departments->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($filters['department'] == $dept['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Course</label>
                            <select id="quick_course" name="filter_course">
                                <option value="">All Courses</option>
                                <?php
                                if ($filter_courses && $filter_courses->num_rows > 0):
                                    while ($course = $filter_courses->fetch_assoc()):
                                ?>
                                        <option value="<?php echo $course['id']; ?>" <?php echo ($filters['course'] == $course['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course['code'] . ' - ' . $course['name']); ?>
                                        </option>
                                <?php
                                    endwhile;
                                endif;
                                ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Year Level</label>
                            <select id="quick_year" name="filter_year">
                                <option value="">All Years</option>
                                <option value="1st Year" <?php echo ($filters['year'] == '1st Year') ? 'selected' : ''; ?>>1st Year</option>
                                <option value="2nd Year" <?php echo ($filters['year'] == '2nd Year') ? 'selected' : ''; ?>>2nd Year</option>
                                <option value="3rd Year" <?php echo ($filters['year'] == '3rd Year') ? 'selected' : ''; ?>>3rd Year</option>
                                <option value="4th Year" <?php echo ($filters['year'] == '4th Year') ? 'selected' : ''; ?>>4th Year</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Section</label>
                            <select id="quick_section" name="filter_section">
                                <option value="">All Sections</option>
                                <?php
                                $sections->data_seek(0);
                                while ($section = $sections->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $section['id']; ?>" <?php echo ($filters['section'] == $section['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($section['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <button type="submit" class="btn-apply-filter">
                                <i class="fas fa-check"></i>
                                Apply Filter
                            </button>
                        </div>

                        <div class="filter-group">
                            <button type="button" class="btn-clear-filter" onclick="clearFilters()">
                                <i class="fas fa-times"></i>
                                Clear Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="action-bar">
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search students...">
                    <i class="fas fa-search"></i>
                </div>
                <button class="add-btn" onclick="openAddModal()">
                    <i class="fas fa-plus"></i>
                    Add Student
                </button>
            </div>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Department</th>
                            <th>Course</th>
                            <th>Year & Section</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="studentsTable">
                        <?php if ($students->num_rows > 0): ?>
                            <?php while ($student = $students->fetch_assoc()): ?>
                                <tr data-search="<?php echo strtolower($student['student_id'] . ' ' . $student['firstname'] . ' ' . $student['lastname'] . ' ' . $student['email'] . ' ' . $student['course_code'] . ' ' . $student['department_code']); ?>">
                                    <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($student['firstname'] . ' ' . ($student['middlename'] ? substr($student['middlename'], 0, 1) . '. ' : '') . $student['lastname']); ?></td>
                                    <td><?php echo htmlspecialchars($student['department_code']); ?></td>
                                    <td><?php echo htmlspecialchars($student['course_code']); ?></td>
                                    <td>
                                        <span class="year-section">
                                            <?php echo htmlspecialchars($student['year_level'] . ' - ' . $student['section_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                                    <td><?php echo htmlspecialchars($student['contact'] ?: 'N/A'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-edit" onclick="editStudent(<?php echo $student['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" onclick="deleteStudent(<?php echo $student['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <h3>No Students Found</h3>
                                        <p>Try adjusting your filters or click "Add Student" to get started</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Add/Edit Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-user-plus"></i>
                    <span id="modalTitle">Add Student</span>
                </h2>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="studentForm">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="studentId">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Student ID *</label>
                        <input type="text" name="student_id" id="student_id" required>
                    </div>

                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="firstname" id="firstname" required>
                    </div>

                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middlename" id="middlename">
                    </div>

                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="lastname" id="lastname" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Email *</label>
                        <input type="email" name="email" id="email" required>
                    </div>

                    <div class="form-group">
                        <label>Department *</label>
                        <select name="department_id" id="department_id" required onchange="loadCourses()">
                            <option value="">Select Department</option>
                            <?php
                            $departments->data_seek(0);
                            while ($dept = $departments->fetch_assoc()):
                            ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo (!empty($filters['department']) && $filters['department'] == $dept['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Course *</label>
                        <select name="course_id" id="course_id" required>
                            <option value="">Select Department First</option>
                            <?php
                            if (!empty($filters['department'])):
                                $modal_courses_stmt = $conn->prepare("SELECT id, code, name FROM courses WHERE department_id = ? AND is_active = 1 ORDER BY name");
                                $modal_courses_stmt->bind_param("i", $filters['department']);
                                $modal_courses_stmt->execute();
                                $modal_courses = $modal_courses_stmt->get_result();
                                while ($course = $modal_courses->fetch_assoc()):
                            ?>
                                    <option value="<?php echo $course['id']; ?>" <?php echo (!empty($filters['course']) && $filters['course'] == $course['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($course['code'] . ' - ' . $course['name']); ?>
                                    </option>
                            <?php
                                endwhile;
                            endif;
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Year Level *</label>
                        <select name="year_level" id="year_level" required>
                            <option value="">Select Year</option>
                            <option value="1st Year" <?php echo (!empty($filters['year']) && $filters['year'] == '1st Year') ? 'selected' : ''; ?>>1st Year</option>
                            <option value="2nd Year" <?php echo (!empty($filters['year']) && $filters['year'] == '2nd Year') ? 'selected' : ''; ?>>2nd Year</option>
                            <option value="3rd Year" <?php echo (!empty($filters['year']) && $filters['year'] == '3rd Year') ? 'selected' : ''; ?>>3rd Year</option>
                            <option value="4th Year" <?php echo (!empty($filters['year']) && $filters['year'] == '4th Year') ? 'selected' : ''; ?>>4th Year</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Section *</label>
                        <select name="section_id" id="section_id" required>
                            <option value="">Select Section</option>
                            <?php
                            $sections->data_seek(0);
                            while ($section = $sections->fetch_assoc()):
                            ?>
                                <option value="<?php echo $section['id']; ?>" <?php echo (!empty($filters['section']) && $filters['section'] == $section['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Contact Number</label>
                        <input type="text" name="contact" id="contact">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Student
                    </button>
                    <button type="button" class="btn-cancel" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
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

        function loadCourses() {
            const departmentId = document.getElementById('department_id').value;
            const courseSelect = document.getElementById('course_id');

            if (!departmentId) {
                courseSelect.innerHTML = '<option value="">Select Department First</option>';
                return Promise.resolve();
            }

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_courses');
            formData.append('department_id', departmentId);

            return fetch('students.php', {
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
                    }
                });
        }

        function loadQuickCourses() {
            const departmentId = document.getElementById('quick_department').value;
            const courseSelect = document.getElementById('quick_course');
            const currentCourseValue = courseSelect.value;

            if (!departmentId) {
                courseSelect.innerHTML = '<option value="">All Courses</option>';
                return;
            }

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
                            option.textContent = `${course.code} - ${course.name}`;
                            if (course.id == currentCourseValue) {
                                option.selected = true;
                            }
                            courseSelect.appendChild(option);
                        });
                    }
                });
        }

        function clearFilters() {
            document.getElementById('quick_department').value = '';
            document.getElementById('quick_course').innerHTML = '<option value="">All Courses</option>';
            document.getElementById('quick_year').value = '';
            document.getElementById('quick_section').value = '';
            document.getElementById('filterForm').submit();
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Student';
            document.getElementById('formAction').value = 'add';


            document.getElementById('student_id').value = '';
            document.getElementById('firstname').value = '';
            document.getElementById('middlename').value = '';
            document.getElementById('lastname').value = '';
            document.getElementById('email').value = '';
            document.getElementById('contact').value = '';
            document.getElementById('studentId').value = '';

            const deptValue = document.getElementById('department_id').value;
            if (deptValue && document.getElementById('course_id').options.length <= 1) {
                loadCourses();
            }

            document.getElementById('studentModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('studentModal').classList.remove('active');
        }

        function editStudent(id) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get');
            formData.append('id', id);

            fetch('students.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const student = data.data;

                        document.getElementById('modalTitle').textContent = 'Edit Student';
                        document.getElementById('formAction').value = 'edit';
                        document.getElementById('studentId').value = student.id;
                        document.getElementById('student_id').value = student.student_id;
                        document.getElementById('firstname').value = student.firstname;
                        document.getElementById('middlename').value = student.middlename || '';
                        document.getElementById('lastname').value = student.lastname;
                        document.getElementById('email').value = student.email;
                        document.getElementById('department_id').value = student.department_id;
                        document.getElementById('year_level').value = student.year_level;
                        document.getElementById('section_id').value = student.section_id;
                        document.getElementById('contact').value = student.contact || '';

                        loadCourses().then(() => {
                            document.getElementById('course_id').value = student.course_id;
                        });

                        document.getElementById('studentModal').classList.add('active');
                    } else {
                        showMessage('error', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'Failed to load student data');
                });
        }

        function deleteStudent(id) {
            if (!confirm('Are you sure you want to delete this student?')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('students.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    showMessage(data.success ? 'success' : 'error', data.message);
                    if (data.success) {
                        setTimeout(() => location.reload(), 1500);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'Failed to delete student');
                });
        }

        document.getElementById('studentForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch('students.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    showMessage(data.success ? 'success' : 'error', data.message);
                    if (data.success) {
                        closeModal();
                        setTimeout(() => location.reload(), 1500);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'An error occurred while saving');
                });
        });

        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#studentsTable tr');

            rows.forEach(row => {
                const searchData = row.getAttribute('data-search');
                if (searchData) {
                    if (searchData.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        });

        document.getElementById('studentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        window.addEventListener('DOMContentLoaded', function() {
            const selectedDept = document.getElementById('quick_department').value;
            const selectedCourse = '<?php echo $filters['course']; ?>';

            if (selectedDept && selectedCourse) {
                const courseSelect = document.getElementById('quick_course');
                const currentOption = courseSelect.querySelector(`option[value="${selectedCourse}"]`);

                if (!currentOption || currentOption.value === '') {
                    loadQuickCourses();
                }
            }
        });
    </script>
</body>

</html>
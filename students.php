<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Initialize session filters if not set
if (!isset($_SESSION['student_filters'])) {
    $_SESSION['student_filters'] = [
        'grade' => '',
        'section' => ''
    ];
}

// Update filters from POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_filters'])) {
    $_SESSION['student_filters'] = [
        'grade' => $_POST['filter_grade'] ?? '',
        'section' => $_POST['filter_section'] ?? ''
    ];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
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
    }

    if ($action === 'add') {
        $firstname = sanitizeInput($_POST['firstname']);
        $lastname = sanitizeInput($_POST['lastname']);
        $middlename = sanitizeInput($_POST['middlename']);
        $grade_level_id = (int)$_POST['grade_level_id'];
        $section_id = (int)$_POST['section_id'];
        $date_of_birth = sanitizeInput($_POST['date_of_birth']);
        $gender = sanitizeInput($_POST['gender']);
        $parent_name = sanitizeInput($_POST['parent_name']);
        $parent_contact = sanitizeInput($_POST['parent_contact']);
        $parent_email = sanitizeInput($_POST['parent_email']);
        $address = sanitizeInput($_POST['address']);

        // Generate student ID
        $student_id = generateStudentID($conn, $grade_level_id);

        $stmt = $conn->prepare("INSERT INTO students (student_id, firstname, lastname, middlename, grade_level_id, section_id, date_of_birth, gender, parent_name, parent_contact, parent_email, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiisssssss", $student_id, $firstname, $lastname, $middlename, $grade_level_id, $section_id, $date_of_birth, $gender, $parent_name, $parent_contact, $parent_email, $address);

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'Add Student', "Added student: $firstname $lastname ($student_id)");
            jsonResponse(true, 'Student added successfully', ['student_id' => $student_id]);
        } else {
            jsonResponse(false, 'Failed to add student');
        }
    }

    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $firstname = sanitizeInput($_POST['firstname']);
        $lastname = sanitizeInput($_POST['lastname']);
        $middlename = sanitizeInput($_POST['middlename']);
        $grade_level_id = (int)$_POST['grade_level_id'];
        $section_id = (int)$_POST['section_id'];
        $date_of_birth = sanitizeInput($_POST['date_of_birth']);
        $gender = sanitizeInput($_POST['gender']);
        $parent_name = sanitizeInput($_POST['parent_name']);
        $parent_contact = sanitizeInput($_POST['parent_contact']);
        $parent_email = sanitizeInput($_POST['parent_email']);
        $address = sanitizeInput($_POST['address']);

        $stmt = $conn->prepare("UPDATE students SET firstname=?, lastname=?, middlename=?, grade_level_id=?, section_id=?, date_of_birth=?, gender=?, parent_name=?, parent_contact=?, parent_email=?, address=? WHERE id=?");
        $stmt->bind_param("ssiisssssssi", $firstname, $lastname, $middlename, $grade_level_id, $section_id, $date_of_birth, $gender, $parent_name, $parent_contact, $parent_email, $address, $id);

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'Update Student', "Updated student: $firstname $lastname (ID: $id)");
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
            logActivity($conn, $_SESSION['user_id'], 'Delete Student', "Deleted student ID: $id");
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

$query = "SELECT 
    s.*,
    gl.grade_name,
    gl.grade_number,
    sec.name as section_name,
    sec.room_number
    FROM students s
    JOIN grade_levels gl ON s.grade_level_id = gl.id
    JOIN sections sec ON s.section_id = sec.id
    WHERE $whereClause
    ORDER BY gl.grade_number, sec.name, s.lastname, s.firstname";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result();
} else {
    $students = $conn->query($query);
}

// Get grade levels for dropdown
$grade_levels = $conn->query("SELECT id, grade_number, grade_name FROM grade_levels WHERE is_active = 1 ORDER BY grade_number");

// Get sections for the selected grade level filter
$filter_sections = [];
if (!empty($filters['grade'])) {
    $stmt = $conn->prepare("SELECT id, name, room_number FROM sections WHERE grade_level_id = ? AND is_active = 1 ORDER BY name");
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
    <title>Students Management - Elementary Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="elementary_dashboard.css">
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
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
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
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .quick-filter-section {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            border: 3px dashed var(--accent-yellow);
            position: relative;
        }

        .quick-filter-section::before {
            position: absolute;
            top: -15px;
            left: 30px;
            font-size: 2rem;
            background: white;
            padding: 0 10px;
        }

        .quick-filter-section h3 {
            color: var(--text-primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
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
            gap: 6px;
        }

        .quick-filter-grid .filter-group label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .quick-filter-grid .filter-group select {
            padding: 10px 14px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .quick-filter-grid .filter-group select:focus {
            outline: none;
            border-color: var(--accent-purple);
            background: white;
            box-shadow: 0 0 0 4px rgba(181, 101, 216, 0.1);
        }

        .add-btn {
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
            font-size: 0.9rem;
            box-shadow: var(--shadow);
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-purple);
            background: white;
            box-shadow: 0 0 0 4px rgba(181, 101, 216, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            grid-column: 1 / -1;
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
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: var(--shadow);
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
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-cancel:hover {
            background: var(--danger);
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn-icon {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
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

        .grade-section-badge {
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

        .student-id-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            border-radius: 20px;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            box-shadow: var(--shadow);
        }

        .gender-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .gender-male {
            background: rgba(74, 144, 226, 0.1);
            color: var(--primary-blue);
            border: 2px solid var(--primary-blue);
        }

        .gender-female {
            background: rgba(255, 111, 145, 0.1);
            color: var(--accent-pink);
            border: 2px solid var(--accent-pink);
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .action-bar {
                flex-direction: column;
            }

            .search-box {
                max-width: 100%;
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
                <div class="nav-item active">
                    <span>Students</span>
                </div>
                <div class="nav-item" onclick="location.href='attendance.php'">
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
                <h1 class="page-title">Students Management</h1>
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

            <!-- Quick Filter Section -->
            <div class="quick-filter-section">
                <h3>
                    <i class="fas fa-filter"></i>
                    Quick Filters
                </h3>
                <form method="POST" id="filterForm">
                    <input type="hidden" name="update_filters" value="1">
                    <div class="quick-filter-grid">
                        <div class="filter-group">
                            <label>Grade Level</label>
                            <select id="quick_grade" name="filter_grade" onchange="loadQuickSections()">
                                <option value="">All Grades</option>
                                <?php
                                $grade_levels->data_seek(0);
                                while ($grade = $grade_levels->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $grade['id']; ?>" <?php echo ($filters['grade'] == $grade['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($grade['grade_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Section</label>
                            <select id="quick_section" name="filter_section">
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
                    <input type="text" id="searchInput" placeholder="Search students by name, ID, or parent...">
                    <i class="fas fa-search"></i>
                </div>
                <button class="add-btn" onclick="openAddModal()">
                    <i class="fas fa-plus"></i>
                    Add Student
                </button>
            </div>

            <div class="table-container">
                <h3>
                    <i class="fas fa-users"></i>
                    Student Records
                </h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Grade & Section</th>
                            <th>Gender</th>
                            <th>Date of Birth</th>
                            <th>Parent/Guardian</th>
                            <th>Contact</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="studentsTable">
                        <?php if ($students->num_rows > 0): ?>
                            <?php while ($student = $students->fetch_assoc()): ?>
                                <tr data-search="<?php echo strtolower($student['student_id'] . ' ' . $student['firstname'] . ' ' . $student['lastname'] . ' ' . $student['parent_name'] . ' ' . $student['grade_name'] . ' ' . $student['section_name']); ?>">
                                    <td>
                                        <span class="student-id-badge">
                                            <i class="fas fa-id-card"></i>
                                            <?php echo htmlspecialchars($student['student_id']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($student['firstname'] . ' ' . ($student['middlename'] ? substr($student['middlename'], 0, 1) . '. ' : '') . $student['lastname']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="grade-section-badge">
                                            <i class="fas fa-graduation-cap"></i>
                                            <?php echo htmlspecialchars($student['grade_name'] . ' - ' . $student['section_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="gender-badge gender-<?php echo strtolower($student['gender']); ?>">
                                            <i class="fas fa-<?php echo $student['gender'] == 'Male' ? 'mars' : 'venus'; ?>"></i>
                                            <?php echo htmlspecialchars($student['gender']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $student['date_of_birth'] ? formatDate($student['date_of_birth']) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($student['parent_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['parent_contact'] ?: 'N/A'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-edit" onclick="editStudent(<?php echo $student['id']; ?>)" title="Edit Student">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" onclick="deleteStudent(<?php echo $student['id']; ?>)" title="Delete Student">
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
                        <label><i class="fas fa-user"></i> First Name *</label>
                        <input type="text" name="firstname" id="firstname" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Last Name *</label>
                        <input type="text" name="lastname" id="lastname" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Middle Name</label>
                        <input type="text" name="middlename" id="middlename">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-venus-mars"></i> Gender *</label>
                        <select name="gender" id="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-birthday-cake"></i> Date of Birth *</label>
                        <input type="date" name="date_of_birth" id="date_of_birth" required max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-graduation-cap"></i> Grade Level *</label>
                        <select name="grade_level_id" id="grade_level_id" required onchange="loadSections()">
                            <option value="">Select Grade Level</option>
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

                    <div class="form-group">
                        <label><i class="fas fa-users"></i> Section *</label>
                        <select name="section_id" id="section_id" required>
                            <option value="">Select Grade First</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label><i class="fas fa-user-friends"></i> Parent/Guardian Name</label>
                        <input type="text" name="parent_name" id="parent_name">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Parent Contact Number</label>
                        <input type="text" name="parent_contact" id="parent_contact" placeholder="e.g., 09123456789">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Parent Email</label>
                        <input type="email" name="parent_email" id="parent_email" placeholder="parent@example.com">
                    </div>

                    <div class="form-group full-width">
                        <label><i class="fas fa-map-marker-alt"></i> Complete Address</label>
                        <textarea name="address" id="address" rows="3"></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-cancel" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i> Save Student
                        </button>

                    </div>
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

        function loadSections() {
            const gradeId = document.getElementById('grade_level_id').value;
            const sectionSelect = document.getElementById('section_id');

            if (!gradeId) {
                sectionSelect.innerHTML = '<option value="">Select Grade First</option>';
                return Promise.resolve();
            }

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_sections');
            formData.append('grade_id', gradeId);

            return fetch('students.php', {
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
                    }
                })
                .catch(error => {
                    console.error('Error loading sections:', error);
                    showMessage('error', 'Failed to load sections');
                });
        }

        function loadQuickSections() {
            const gradeId = document.getElementById('quick_grade').value;
            const sectionSelect = document.getElementById('quick_section');
            const currentSectionValue = sectionSelect.value;

            if (!gradeId) {
                sectionSelect.innerHTML = '<option value="">All Sections</option>';
                return;
            }

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get_sections');
            formData.append('grade_id', gradeId);

            fetch('students.php', {
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
                            if (section.id == currentSectionValue) {
                                option.selected = true;
                            }
                            sectionSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading sections:', error);
                    showMessage('error', 'Failed to load sections');
                });
        }

        function clearFilters() {
            document.getElementById('quick_grade').value = '';
            document.getElementById('quick_section').innerHTML = '<option value="">All Sections</option>';
            document.getElementById('filterForm').submit();
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Student';
            document.getElementById('formAction').value = 'add';
            document.getElementById('studentId').value = '';

            // Clear all form fields
            document.getElementById('firstname').value = '';
            document.getElementById('lastname').value = '';
            document.getElementById('middlename').value = '';
            document.getElementById('gender').value = '';
            document.getElementById('date_of_birth').value = '';
            document.getElementById('grade_level_id').value = '';
            document.getElementById('section_id').innerHTML = '<option value="">Select Grade First</option>';
            document.getElementById('parent_name').value = '';
            document.getElementById('parent_contact').value = '';
            document.getElementById('parent_email').value = '';
            document.getElementById('address').value = '';

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
                        document.getElementById('firstname').value = student.firstname;
                        document.getElementById('lastname').value = student.lastname;
                        document.getElementById('middlename').value = student.middlename || '';
                        document.getElementById('gender').value = student.gender;
                        document.getElementById('date_of_birth').value = student.date_of_birth || '';
                        document.getElementById('grade_level_id').value = student.grade_level_id;
                        document.getElementById('parent_name').value = student.parent_name || '';
                        document.getElementById('parent_contact').value = student.parent_contact || '';
                        document.getElementById('parent_email').value = student.parent_email || '';
                        document.getElementById('address').value = student.address || '';

                        // Load sections then set the value
                        loadSections().then(() => {
                            document.getElementById('section_id').value = student.section_id;
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
            if (!confirm('Are you sure you want to delete this student? This action cannot be undone.')) {
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

        // Form submission
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

        // Search functionality
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

        // Close modal on outside click
        document.getElementById('studentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Load sections on page load if grade is selected
        window.addEventListener('DOMContentLoaded', function() {
            const selectedGrade = document.getElementById('quick_grade').value;
            const selectedSection = '<?php echo $filters['section']; ?>';

            if (selectedGrade && selectedSection) {
                const sectionSelect = document.getElementById('quick_section');
                const currentOption = sectionSelect.querySelector(`option[value="${selectedSection}"]`);

                if (!currentOption || currentOption.value === '') {
                    loadQuickSections();
                }
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>
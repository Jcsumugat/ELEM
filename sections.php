<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = sanitizeInput($_POST['name']);
        $grade_level_id = (int)$_POST['grade_level_id'];
        $room_number = sanitizeInput($_POST['room_number']);
        $capacity = (int)$_POST['capacity'];
        $adviser_id = !empty($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : null;

        // Check if section name already exists for this grade level
        $checkStmt = $conn->prepare("SELECT id FROM sections WHERE name = ? AND grade_level_id = ?");
        $checkStmt->bind_param("si", $name, $grade_level_id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            jsonResponse(false, 'Section name already exists for this grade level');
        }

        if ($adviser_id) {
            $stmt = $conn->prepare("INSERT INTO sections (name, grade_level_id, room_number, capacity, adviser_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sisii", $name, $grade_level_id, $room_number, $capacity, $adviser_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO sections (name, grade_level_id, room_number, capacity) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sisi", $name, $grade_level_id, $room_number, $capacity);
        }

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'Add Section', "Added section: $name for grade level ID $grade_level_id");
            jsonResponse(true, 'Section added successfully');
        } else {
            jsonResponse(false, 'Failed to add section');
        }
    }

    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $name = sanitizeInput($_POST['name']);
        $grade_level_id = (int)$_POST['grade_level_id'];
        $room_number = sanitizeInput($_POST['room_number']);
        $capacity = (int)$_POST['capacity'];
        $adviser_id = !empty($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : null;

        // Check if section name exists for other sections in same grade
        $checkStmt = $conn->prepare("SELECT id FROM sections WHERE name = ? AND grade_level_id = ? AND id != ?");
        $checkStmt->bind_param("sii", $name, $grade_level_id, $id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            jsonResponse(false, 'Section name already exists for this grade level');
        }

        if ($adviser_id) {
            $stmt = $conn->prepare("UPDATE sections SET name=?, grade_level_id=?, room_number=?, capacity=?, adviser_id=? WHERE id=?");
            $stmt->bind_param("sisiii", $name, $grade_level_id, $room_number, $capacity, $adviser_id, $id);
        } else {
            $stmt = $conn->prepare("UPDATE sections SET name=?, grade_level_id=?, room_number=?, capacity=?, adviser_id=NULL WHERE id=?");
            $stmt->bind_param("sisii", $name, $grade_level_id, $room_number, $capacity, $id);
        }

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'Update Section', "Updated section ID: $id");
            jsonResponse(true, 'Section updated successfully');
        } else {
            jsonResponse(false, 'Failed to update section');
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];

        // Check if section has students
        $checkStmt = $conn->prepare("SELECT COUNT(*) as student_count FROM students WHERE section_id = ? AND is_active = 1");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $result = $checkStmt->get_result()->fetch_assoc();

        if ($result['student_count'] > 0) {
            jsonResponse(false, 'Cannot delete section with active students. Please reassign students first.');
        }

        $stmt = $conn->prepare("UPDATE sections SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'Delete Section', "Deleted section ID: $id");
            jsonResponse(true, 'Section deleted successfully');
        } else {
            jsonResponse(false, 'Failed to delete section');
        }
    }

    if ($action === 'get') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("SELECT * FROM sections WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $section = $result->fetch_assoc();

        if ($section) {
            jsonResponse(true, 'Section found', $section);
        } else {
            jsonResponse(false, 'Section not found');
        }
    }
}

// Get all sections with grade level info
$query = "SELECT 
    s.*,
    gl.grade_name,
    gl.grade_number,
    u.fullname as adviser_name,
    (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id AND st.is_active = 1) as student_count
    FROM sections s
    JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN users u ON s.adviser_id = u.id
    WHERE s.is_active = 1
    ORDER BY gl.grade_number, s.name";

$sections = $conn->query($query);

// Get grade levels for dropdown
$grade_levels = $conn->query("SELECT id, grade_number, grade_name FROM grade_levels WHERE is_active = 1 ORDER BY grade_number");

// Get teachers for adviser dropdown
$teachers = $conn->query("SELECT id, fullname FROM users WHERE role = 'Teacher' AND is_active = 1 ORDER BY fullname");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sections - Elementary Attendance System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="elementary_dashboard.css">
    <style>
        :root {
            --primary-blue: #4A90E2;
            --primary-teal: #14B8A6;
            --accent-orange: #FF9671;
            --accent-yellow: #FFC75F;
            --accent-green: #7BC96F;
            --accent-pink: #FF6F91;
            --accent-purple: #B565D8;

            --bg-main: #F8FAFC;
            --bg-sidebar: #FFFFFF;
            --card-bg: #FFFFFF;

            --text-primary: #2C3E50;
            --text-secondary: #546E7A;
            --text-light: #78909C;

            --border-color: #E2E8F0;
            --hover-bg: #F1F5F9;

            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;

            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 8px 20px rgba(0, 0, 0, 0.12);

        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #FFF8E7 0%, #FFE5D9 20%, #E8F5E9 50%, #E3F2FD 80%, #F3E5F5 100%);
            background-attachment: fixed;
            min-height: 100vh;
            color: var(--text-primary);
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 50%, rgba(255, 150, 113, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(181, 101, 216, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(123, 201, 111, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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
            max-width: 600px;
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

        .sidebar {
            width: 260px;
            background: var(--bg-sidebar);
            color: var(--text-primary);
            padding: 24px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.05);
            border-right: 3px solid transparent;
            border-image: linear-gradient(180deg, var(--accent-orange), var(--accent-pink), var(--accent-purple)) 1;
        }

        .sidebar-header {
            padding: 0 20px 24px;
            text-align: center;
            margin-bottom: 24px;
            position: relative;
        }

        .sidebar-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80%;
            height: 3px;
            background: linear-gradient(90deg, var(--accent-orange), var(--accent-pink), var(--accent-purple));
            border-radius: 2px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .form-group input,
        .form-group select {
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

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-purple);
            background: white;
            box-shadow: 0 0 0 4px rgba(181, 101, 216, 0.1);
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
            font-size: 0.9rem;
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

        .capacity-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            border-radius: 20px;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            box-shadow: var(--shadow);
        }

        .student-count {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            border-radius: 20px;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            box-shadow: var(--shadow);
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Elementary School</h2>
                <p>Attendance System</p>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item" onclick="location.href='dashboard.php'">
                    <span>Dashboard</span>
                </div>
                <div class="sidebar-nav">
                    <div class="nav-item active">
                        <span>Sections</span>
                    </div>
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
                <h1 class="page-title">Manage Sections</h1>
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

            <div class="action-bar">
                <h3 style="color: var(--text-primary); font-size: 1.1rem;">
                    <i class="fas fa-door-open"></i> Sections List
                </h3>
                <button class="add-btn" onclick="openAddModal()">
                    <i class="fas fa-plus"></i>
                    Add Section
                </button>
            </div>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Section Name</th>
                            <th>Grade Level</th>
                            <th>Room Number</th>
                            <th>Capacity</th>
                            <th>Students</th>
                            <th>Adviser</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($sections->num_rows > 0): ?>
                            <?php while ($section = $sections->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($section['name']); ?></strong></td>
                                    <td>
                                        <span class="grade-badge">
                                            <i class="fas fa-graduation-cap"></i>
                                            <?php echo htmlspecialchars($section['grade_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($section['room_number'] ?: 'N/A'); ?></td>
                                    <td>
                                        <span class="capacity-badge">
                                            <i class="fas fa-chair"></i>
                                            <?php echo $section['capacity']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="student-count">
                                            <i class="fas fa-users"></i>
                                            <?php echo $section['student_count']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($section['adviser_name'] ?: 'Not assigned'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-edit" onclick="editSection(<?php echo $section['id']; ?>)" title="Edit Section">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" onclick="deleteSection(<?php echo $section['id']; ?>)" title="Delete Section">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-door-open"></i>
                                        <h3>No Sections Found</h3>
                                        <p>Click "Add Section" to create your first section</p>
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
    <div id="sectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-door-open"></i>
                    <span id="modalTitle">Add Section</span>
                </h2>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="sectionForm">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="sectionId">

                <div class="form-group">
                    <label><i class="fas fa-graduation-cap"></i> Grade Level *</label>
                    <select name="grade_level_id" id="grade_level_id" required>
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
                    <label><i class="fas fa-tag"></i> Section Name *</label>
                    <input type="text" name="name" id="name" required placeholder="e.g., Rose, Lily, Sunflower">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-door-closed"></i> Room Number</label>
                    <input type="text" name="room_number" id="room_number" placeholder="e.g., 101, A-1">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-chair"></i> Capacity *</label>
                    <input type="number" name="capacity" id="capacity" required value="40" min="1" max="100">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-chalkboard-teacher"></i> Adviser (Optional)</label>
                    <select name="adviser_id" id="adviser_id">
                        <option value="">No adviser assigned</option>
                        <?php
                        $teachers->data_seek(0);
                        while ($teacher = $teachers->fetch_assoc()):
                        ?>
                            <option value="<?php echo $teacher['id']; ?>">
                                <?php echo htmlspecialchars($teacher['fullname']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-actions">

                    <button type="button" class="btn-cancel" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Section
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

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Section';
            document.getElementById('formAction').value = 'add';
            document.getElementById('sectionId').value = '';
            document.getElementById('name').value = '';
            document.getElementById('grade_level_id').value = '';
            document.getElementById('room_number').value = '';
            document.getElementById('capacity').value = '40';
            document.getElementById('adviser_id').value = '';

            document.getElementById('sectionModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('sectionModal').classList.remove('active');
        }

        function editSection(id) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'get');
            formData.append('id', id);

            fetch('sections.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const section = data.data;

                        document.getElementById('modalTitle').textContent = 'Edit Section';
                        document.getElementById('formAction').value = 'edit';
                        document.getElementById('sectionId').value = section.id;
                        document.getElementById('name').value = section.name;
                        document.getElementById('grade_level_id').value = section.grade_level_id;
                        document.getElementById('room_number').value = section.room_number || '';
                        document.getElementById('capacity').value = section.capacity;
                        document.getElementById('adviser_id').value = section.adviser_id || '';

                        document.getElementById('sectionModal').classList.add('active');
                    } else {
                        showMessage('error', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('error', 'Failed to load section data');
                });
        }

        function deleteSection(id) {
            if (!confirm('Are you sure you want to delete this section?')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('sections.php', {
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
                    showMessage('error', 'Failed to delete section');
                });
        }

        document.getElementById('sectionForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch('sections.php', {
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

        document.getElementById('sectionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>
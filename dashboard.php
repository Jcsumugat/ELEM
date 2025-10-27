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

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elementary Attendance Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .dashboard-container {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 1;
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

        .sidebar-header .school-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 12px;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-yellow));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            box-shadow: var(--shadow-md);
            transform: rotate(-5deg);
            transition: transform 0.3s ease;
        }

        .sidebar-header .school-icon:hover {
            transform: rotate(0deg) scale(1.05);
        }

        .sidebar-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 4px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sidebar-header p {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .nav-item {
            padding: 12px 20px;
            margin: 4px 12px;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-secondary);
            position: relative;
            overflow: hidden;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, var(--accent-orange), var(--accent-pink));
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .nav-item i {
            font-size: 1.2rem;
            width: 24px;
            transition: transform 0.3s ease;
        }

        .nav-item:hover {
            background: linear-gradient(135deg, rgba(255, 150, 113, 0.1), rgba(255, 111, 145, 0.1));
            color: var(--text-primary);
            transform: translateX(4px);
        }

        .nav-item:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        .nav-item:hover::before {
            transform: scaleY(1);
        }

        .nav-item.active {
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-pink));
            color: white;
            box-shadow: var(--shadow);
        }

        .nav-item.active::before {
            display: none;
        }

        .logout-btn {
            margin: 24px 12px 0;
            margin-top: 20rem;
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

        .logout-btn:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 24px;
        }

        .header {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
            border: 2px solid transparent;
            background-image:
                linear-gradient(white, white),
                linear-gradient(135deg, var(--accent-orange), var(--accent-pink), var(--accent-purple));
            background-origin: border-box;
            background-clip: padding-box, border-box;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title::before {
            content: '📚';
            font-size: 2rem;
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-5px);
            }
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .date-display {
            background: linear-gradient(135deg, var(--accent-green), var(--primary-teal));
            color: white;
            padding: 10px 18px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: var(--shadow);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: var(--hover-bg);
            border-radius: 12px;
            border: 2px solid var(--border-color);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-yellow));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 0.9rem;
            box-shadow: var(--shadow);
        }

        .user-profile span {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .welcome-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 200px);
        }

        .welcome-card {
            max-width: 700px;
            width: 100%;
            background: var(--card-bg);
            border-radius: 24px;
            padding: 50px 40px;
            box-shadow: var(--shadow-lg);
            border: 2px solid transparent;
            background-image:
                linear-gradient(white, white),
                linear-gradient(135deg, var(--accent-orange), var(--accent-pink), var(--accent-purple));
            background-origin: border-box;
            background-clip: padding-box, border-box;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        .welcome-icon {
            font-size: 3.5rem;
            margin-bottom: 20px;
            animation: wave 2s ease-in-out infinite;
            display: inline-block;
            position: relative;
            z-index: 1;
        }

        @keyframes wave {
            0%, 100% {
                transform: rotate(0deg);
            }
            25% {
                transform: rotate(20deg);
            }
            75% {
                transform: rotate(-20deg);
            }
        }

        .welcome-title {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 12px;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-teal));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            z-index: 1;
        }

        .welcome-name {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 30px;
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-pink), var(--accent-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: fadeInUp 0.8s ease;
            position: relative;
            z-index: 1;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .quote-container {
            margin-top: 35px;
            padding: 25px 30px;
            background: linear-gradient(135deg, rgba(255, 150, 113, 0.08), rgba(255, 111, 145, 0.08));
            border-radius: 16px;
            border: 2px solid var(--accent-orange);
            position: relative;
            z-index: 1;
        }

        .quote-icon {
            font-size: 2rem;
            color: var(--accent-orange);
            opacity: 0.50;
            margin-bottom: 12px;
        }

        .quote-text {
            font-size: 1.05rem;
            font-style: italic;
            color: var(--text-primary);
            line-height: 1.7;
            margin-bottom: 12px;
            font-weight: 500;
        }

        .quote-author {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .decorative-elements {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            z-index: 0;
        }

        .floating-icon {
            position: absolute;
            font-size: 1.5rem;
            opacity: 0.08;
            animation: float 6s ease-in-out infinite;
        }

        .floating-icon:nth-child(1) {
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }

        .floating-icon:nth-child(2) {
            top: 20%;
            right: 15%;
            animation-delay: 1s;
        }

        .floating-icon:nth-child(3) {
            bottom: 15%;
            left: 15%;
            animation-delay: 2s;
        }

        .floating-icon:nth-child(4) {
            bottom: 10%;
            right: 10%;
            animation-delay: 3s;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .welcome-card {
                padding: 35px 25px;
            }

            .welcome-title {
                font-size: 1.3rem;
            }

            .welcome-name {
                font-size: 1.5rem;
            }

            .quote-text {
                font-size: 0.95rem;
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
                <div class="nav-item active">
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
                <h1 class="page-title">Home</h1>
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

            <div class="welcome-container">
                <div class="welcome-card">
                    <div class="decorative-elements">
                        <div class="floating-icon">📚</div>
                        <div class="floating-icon">✏️</div>
                        <div class="floating-icon">🎨</div>
                        <div class="floating-icon">⭐</div>
                    </div>

                    <div class="welcome-icon">👋</div>
                    <h1 class="welcome-title">Welcome Back!</h1>
                    <h2 class="welcome-name">Ma'am/Sir <?php echo htmlspecialchars($user['fullname']); ?></h2>

                    <div class="quote-container">
                        <div class="quote-icon">
                            <i class="fas fa-quote-left"></i>
                        </div>
                        <p class="quote-text">
                            "Education is the most powerful weapon which you can use to change the world".
                        </p>
                        <p class="quote-author">— Nelson Mandela</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }
    </script>
</body>

</html>
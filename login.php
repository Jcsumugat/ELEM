<?php
session_start();

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Attendance Monitoring System - Login</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="bg-animation">
        <div class="orb orb1"></div>
        <div class="orb orb2"></div>
        <div class="orb orb3"></div>
    </div>

    <div class="container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-user-check"></i>
                <h1>Student Attendance</h1>
            </div>
            <p class="tagline">Monitoring System</p>
        </div>

        <div id="message-container"></div>

        <div class="form-section">
            <h2 class="form-title">
                <i class="fas fa-sign-in-alt"></i> System Login
            </h2>
            <form id="loginForm">
                <input type="hidden" name="action" value="login">
                
                <div class="input-group">
                    <label>Username</label>
                    <div class="input-wrapper">
                        <input type="text" name="username" placeholder="Enter your username" required>
                        <i class="fas fa-user"></i>
                    </div>
                </div>

                <div class="input-group">
                    <label>Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        <i class="fas fa-lock"></i>
                        <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn">
                    Sign In <i class="fas fa-arrow-right btn-icon"></i>
                </button>
            </form>
        </div>

        <div class="footer">
            <div class="footer-links">
                <a href="#">Help</a>
                <a href="#">Privacy</a>
                <a href="#">Contact</a>
            </div>
            <p>&copy; 2025 Student Attendance System. All rights reserved.</p>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const input = document.getElementById('password');
            const icon = event.target.closest('.toggle-password').querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('success', data.message);
                    setTimeout(() => {
                        window.location.href = data.data.redirect;
                    }, 1000);
                } else {
                    showMessage('error', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('error', 'An error occurred. Please try again.');
            });
        });

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
    </script>
</body>
</html>
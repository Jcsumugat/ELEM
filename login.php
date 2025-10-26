<?php
session_start();
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
    <title>Elementary Attendance Monitoring System - Login</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <div class="login-container">
        <div class="logo-container">
            <div class="logo-icon">
                <i class="fas fa-school"></i>
            </div>
            <h1>Welcome Back</h1>
            <p>Elementary Attendance Monitoring System</p>
        </div>

        <div id="messageBox" class="message"></div>

        <!-- Login Form -->
        <form id="loginForm">
            <input type="hidden" name="action" value="login">
            <input type="hidden" id="systemInput" name="system" value="elementary">

            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder="Enter your email" required autocomplete="email">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                    <button type="button" class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <div class="remember-me">
                    <input type="checkbox" id="rememberMe" name="remember_me" value="true">
                    <label for="rememberMe">Remember me</label>
                </div>
                <a href="#" class="forgot-password">Forgot Password?</a>
            </div>

            <button type="submit" class="login-btn" id="loginBtn">
                <span class="btn-text">
                    Sign In <i class="fas fa-arrow-right"></i>
                </span>
                <div class="spinner"></div>
            </button>
        </form>

        <div class="divider">
            <span>Don't have an account?</span>
        </div>

        <button type="button" class="register-link" id="showRegister">
            <i class="fas fa-user-plus"></i> Create New Account
        </button>

        <div class="footer-links">
            <a href="#">Help</a>
            <a href="#">Privacy</a>
            <a href="#">Contact</a>
        </div>
    </div>

    <!-- Registration Form -->
    <div class="login-container register-container" id="registerContainer" style="display: none;">
        <div class="logo-container">
            <div class="logo-icon">
                <i class="fas fa-user-plus"></i>
            </div>
            <h1>Create Account</h1>
            <p>Elementary Attendance Monitoring System</p>
        </div>

        <div id="registerMessageBox" class="message"></div>

        <form id="registerForm">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="system" value="elementary">

            <div class="form-group">
                <label for="reg_fullname">Full Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-id-card"></i>
                    <input type="text" id="reg_fullname" name="fullname" placeholder="Enter your full name" required>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="reg_username" name="username" placeholder="Choose a username" required>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_email">Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="reg_email" name="email" placeholder="Enter your email" required>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_role">Role</label>
                <div class="input-wrapper">
                    <i class="fas fa-user-tag"></i>
                    <select id="reg_role" name="role" required>
                        <option value="Teacher">Teacher</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="reg_password" name="password" placeholder="Create a password" required>
                    <button type="button" class="password-toggle" onclick="toggleRegPassword('reg_password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_confirm_password">Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="reg_confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                    <button type="button" class="password-toggle" onclick="toggleRegPassword('reg_confirm_password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="login-btn" id="registerBtn">
                <span class="btn-text">
                    Create Account <i class="fas fa-arrow-right"></i>
                </span>
                <div class="spinner"></div>
            </button>
        </form>

        <div class="divider">
            <span>Already have an account?</span>
        </div>

        <button type="button" class="register-link" id="showLogin">
            <i class="fas fa-sign-in-alt"></i> Back to Login
        </button>

        <div class="footer-links">
            <a href="#">Help</a>
            <a href="#">Privacy</a>
            <a href="#">Contact</a>
        </div>
    </div>

    <script>
        // Get all elements
        const loginContainer = document.querySelector('.login-container:not(.register-container)');
        const registerContainer = document.getElementById('registerContainer');
        const showRegisterBtn = document.getElementById('showRegister');
        const showLoginBtn = document.getElementById('showLogin');

        const systemInput = document.getElementById('systemInput');
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const messageBox = document.getElementById('messageBox');

        const registerForm = document.getElementById('registerForm');
        const registerBtn = document.getElementById('registerBtn');
        const registerMessageBox = document.getElementById('registerMessageBox');

        // Toggle between login and register
        showRegisterBtn.addEventListener('click', function() {
            loginContainer.style.display = 'none';
            registerContainer.style.display = 'block';
            registerContainer.style.animation = 'slideUp 0.6s ease-out';
        });

        showLoginBtn.addEventListener('click', function() {
            registerContainer.style.display = 'none';
            loginContainer.style.display = 'block';
            loginContainer.style.animation = 'slideUp 0.6s ease-out';
        });

        // Toggle password visibility for login
        togglePassword.addEventListener('click', function() {
            const icon = this.querySelector('i');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Toggle register password visibility
        function toggleRegPassword(fieldId) {
            const input = document.getElementById(fieldId);
            const button = event.target.closest('.password-toggle');
            const icon = button.querySelector('i');

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

        // Make toggleRegPassword global
        window.toggleRegPassword = toggleRegPassword;

        // Show message
        function showMessage(type, message, container = messageBox) {
            container.className = `message ${type} show`;
            container.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span>${message}</span>
    `;

            setTimeout(() => {
                container.classList.remove('show');
            }, 5000);
        }

        // Login form submission
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            loginBtn.disabled = true;
            loginBtn.classList.add('loading');

            const formData = new FormData(this);

            try {
                const response = await fetch('auth.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error('Server error: ' + response.status);
                }

                const text = await response.text();

                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error('Response text:', text);
                    throw new Error('Invalid JSON response from server. Please check auth.php for errors.');
                }

                if (data.success) {
                    showMessage('success', data.message);

                    setTimeout(() => {
                        window.location.href = data.data.redirect;
                    }, 1000);
                } else {
                    showMessage('error', data.message);
                    loginBtn.disabled = false;
                    loginBtn.classList.remove('loading');
                }
            } catch (error) {
                console.error('Error:', error);
                showMessage('error', error.message || 'Connection error. Please try again.');
                loginBtn.disabled = false;
                loginBtn.classList.remove('loading');
            }
        });

        // Register form submission
        registerForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Validate passwords match
            const password = document.getElementById('reg_password').value;
            const confirmPassword = document.getElementById('reg_confirm_password').value;

            if (password !== confirmPassword) {
                showMessage('error', 'Passwords do not match!', registerMessageBox);
                return;
            }

            if (password.length < 6) {
                showMessage('error', 'Password must be at least 6 characters long!', registerMessageBox);
                return;
            }

            registerBtn.disabled = true;
            registerBtn.classList.add('loading');

            const formData = new FormData(this);

            try {
                const response = await fetch('auth.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error('Server error: ' + response.status);
                }

                const text = await response.text();

                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error('Response text:', text);
                    throw new Error('Invalid JSON response from server. Please check auth.php for errors.');
                }

                if (data.success) {
                    showMessage('success', data.message, registerMessageBox);

                    // Clear form
                    registerForm.reset();

                    // Switch to login after 2 seconds
                    setTimeout(() => {
                        registerContainer.style.display = 'none';
                        loginContainer.style.display = 'block';
                        showMessage('success', 'Registration successful! Please login.');
                    }, 2000);
                } else {
                    showMessage('error', data.message, registerMessageBox);
                    registerBtn.disabled = false;
                    registerBtn.classList.remove('loading');
                }
            } catch (error) {
                console.error('Error:', error);
                showMessage('error', error.message || 'Connection error. Please try again.', registerMessageBox);
                registerBtn.disabled = false;
                registerBtn.classList.remove('loading');
            }
        });
    </script>
</body>

</html>
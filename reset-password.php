<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - EazyCount</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="bg">
    <div class="login-container">
        <div class="login-header">
                    <h2>Reset Password</h2>
        </div>
        <div class="login-card">
            <div class="form-content">
                
                <form class="login-form" id="resetForm" method="POST">
                    <div class="input-group">
                        <i class="fas fa-building input-icon"></i>
                        <input type="text" placeholder="Company Id" id="company-id" value="c168" readonly required />
                    </div>
                    
                    <div class="input-group">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" placeholder="Enter your email address" id="email" name="email" required />
                    </div>
                    
                    <div class="tac-container">
                        <div class="input-group">
                            <i class="fas fa-key input-icon"></i>
                            <input type="text" placeholder="TAC" id="tac-field" name="tac" />
                        </div>
                        <button type="button" id="get-tac-btn" class="tac-btn">SEND</button>
                    </div>
                    
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" placeholder="New Password" id="new-password" name="new_password" required />
                    </div>
                    
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" placeholder="Confirm New Password" id="confirm-password" name="confirm_password" required />
                    </div>

                    <button type="submit" class="login-btn">
                        <span>Reset Password</span>
                    </button>
                    
                    <div class="language-switch-container">
                        <a href="/cn/reset-password.php" class="lang-switch" title="Switch Language">
                            <span class="lang-option">中文</span>
                            <span class="lang-option active">English</span>
                        </a>
                    </div>
                    
                    <div class="back-to-login">
                        <a href="index.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            Back to Login
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Password validation
        const newPassword = document.getElementById('new-password');
        const confirmPassword = document.getElementById('confirm-password');

        function validatePassword() {
            const password = newPassword.value;
            const confirm = confirmPassword.value;
            
            if (confirm && password !== confirm) {
                confirmPassword.style.borderColor = '#dc3545';
                return false;
            } else {
                confirmPassword.style.borderColor = '#e1e5e9';
                return true;
            }
        }

        newPassword.addEventListener('input', validatePassword);
        confirmPassword.addEventListener('input', validatePassword);

        // TAC functionality
        const getTacBtn = document.getElementById('get-tac-btn');
        const emailField = document.getElementById('email');

        getTacBtn.addEventListener('click', function() {
            const email = emailField.value;
            
            if (!email) {
                alert('Please enter your email address first');
                return;
            }
            
            // Disable button to prevent multiple clicks
            getTacBtn.disabled = true;
            getTacBtn.textContent = 'Sending...';
            
            // Simulate TAC sending (replace with actual API call)
            setTimeout(() => {
                alert('TAC code has been sent to your email');
                document.getElementById('tac-field').focus();
                getTacBtn.disabled = false;
                getTacBtn.textContent = 'Send TAC';
            }, 2000);
        });

        // Form submission
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!validatePassword()) {
                alert('Passwords do not match');
                return;
            }
            
            const tac = document.getElementById('tac-field').value;
            if (!tac) {
                alert('Please enter the TAC code');
                return;
            }
            
            // Here you would typically send the data to your backend
            alert('Password reset successful! Redirecting to login...');
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 2000);
        });

        // Add input focus effects
        document.querySelectorAll('.input-group input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
    </script>
</body>

</html>
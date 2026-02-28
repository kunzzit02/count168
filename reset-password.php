<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - EazyCount</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/reset-password.css?v=<?php echo time(); ?>">
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
                        <input type="text" placeholder="Company Id (or Owner Code for owner)" id="company-id" name="company_id" required />
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

    <script src="js/reset-password.js?v=<?php echo time(); ?>"></script>
</body>

</html>
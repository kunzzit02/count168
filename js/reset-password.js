/**
 * reset-password.php - 重置密码页逻辑
 */
(function() {
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

    if (newPassword) newPassword.addEventListener('input', validatePassword);
    if (confirmPassword) confirmPassword.addEventListener('input', validatePassword);

    const getTacBtn = document.getElementById('get-tac-btn');
    const emailField = document.getElementById('email');

    if (getTacBtn && emailField) {
        getTacBtn.addEventListener('click', function() {
            const email = emailField.value;

            if (!email) {
                alert('Please enter your email address first');
                return;
            }

            getTacBtn.disabled = true;
            getTacBtn.textContent = 'Sending...';

            setTimeout(() => {
                alert('TAC code has been sent to your email');
                const tacField = document.getElementById('tac-field');
                if (tacField) tacField.focus();
                getTacBtn.disabled = false;
                getTacBtn.textContent = 'Send TAC';
            }, 2000);
        });
    }

    const resetForm = document.getElementById('resetForm');
    if (resetForm) {
        resetForm.addEventListener('submit', function(e) {
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

            alert('Password reset successful! Redirecting to login...');
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 2000);
        });
    }

    document.querySelectorAll('.input-group input').forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'scale(1.02)';
        });
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'scale(1)';
        });
    });
})();

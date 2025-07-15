<?php
require_once 'config.php';

$errors = [];
$success = false;

// Form submission handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($first_name)) {
        $errors['first_name'] = 'First name is required';
    }
    
    if (empty($last_name)) {
        $errors['last_name'] = 'Last name is required';
    }
    
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM members WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $errors['email'] = 'Email already registered';
        }
    }
    
    if (empty($password)) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters';
    }
    
    if ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match';
    }
    
    // If no errors, register the member
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $membership_start_date = date('Y-m-d');
        $membership_end_date = date('Y-m-d', strtotime('+1 year'));
        
        $stmt = $conn->prepare("INSERT INTO members (first_name, last_name, email, phone, password_hash, membership_start_date, membership_end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $first_name, $last_name, $email, $phone, $password_hash, $membership_start_date, $membership_end_date);
        
        if ($stmt->execute()) {
            $success = true;
            
            // Clear form values
            $first_name = $last_name = $email = $phone = '';
        } else {
            $errors['database'] = 'Registration failed. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Management - Member Registration</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .registration-container {
            max-width: 600px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .registration-container h1 {
            text-align: center;
            margin-bottom: 1.5rem;
            color: #2c3e50;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .form-group input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .error-message {
            color: #e74c3c;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .btn-register {
            width: 100%;
            padding: 0.75rem;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-register:hover {
            background: #1a252f;
        }
        
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .login-link a {
            color: #3498db;
            text-decoration: none;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        .password-strength {
            margin-top: 0.25rem;
            height: 4px;
            background: #eee;
            border-radius: 2px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            background: #e74c3c;
            transition: width 0.3s, background 0.3s;
        }
    </style>
</head>
<body>
    <header>
        <div class="container header-container">
            <div class="logo">Community Library</div>
            <nav>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="catalog.php">Catalog</a></li>
                    <li><a href="about.php">About</a></li>
                    <li><a href="contact.php">Contact</a></li>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php" class="active">Register</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="registration-container">
            <h1>Member Registration</h1>
            
            <?php if ($success): ?>
                <div class="success-message">
                    Registration successful! You can now <a href="login.php">login</a> to your account.
                </div>
            <?php endif; ?>
            
            <?php if (isset($errors['database'])): ?>
                <div class="error-message" style="margin-bottom: 1.5rem; text-align: center;">
                    <?php echo $errors['database']; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="register.php">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" 
                           value="<?php echo htmlspecialchars($first_name ?? ''); ?>" required>
                    <?php if (isset($errors['first_name'])): ?>
                        <span class="error-message"><?php echo $errors['first_name']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" 
                           value="<?php echo htmlspecialchars($last_name ?? ''); ?>" required>
                    <?php if (isset($errors['last_name'])): ?>
                        <span class="error-message"><?php echo $errors['last_name']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" 
                           value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                    <?php if (isset($errors['email'])): ?>
                        <span class="error-message"><?php echo $errors['email']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number (Optional)</label>
                    <input type="tel" id="phone" name="phone" 
                           value="<?php echo htmlspecialchars($phone ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="password-strength-bar"></div>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <span class="error-message"><?php echo $errors['password']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <?php if (isset($errors['confirm_password'])): ?>
                        <span class="error-message"><?php echo $errors['confirm_password']; ?></span>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn-register">Register</button>
                
                <div class="login-link">
                    Already have an account? <a href="login.php">Login here</a>
                </div>
            </form>
        </div>
    </main>

    <footer>
        <div class="container" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3>Community Library</h3>
                <p>123 Library Street, Knowledge City</p>
                <p>Phone: (123) 456-7890</p>
            </div>
            <div>
                <h3>Quick Links</h3>
                <ul style="list-style: none; padding: 0;">
                    <li><a href="index.php" style="color: white;">Home</a></li>
                    <li><a href="catalog.php" style="color: white;">Catalog</a></li>
                    <li><a href="about.php" style="color: white;">About Us</a></li>
                    <li><a href="contact.php" style="color: white;">Contact</a></li>
                </ul>
            </div>
        </div>
        <div class="container" style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1);">
            <p>&copy; <?php echo date('Y'); ?> Community Library. All rights reserved.</p>
        </div>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
        // Password strength indicator
        document.getElementById('password').addEventListener('input', function(e) {
            const password = e.target.value;
            const strengthBar = document.getElementById('password-strength-bar');
            let strength = 0;
            
            // Check password length
            if (password.length > 7) strength += 1;
            if (password.length > 11) strength += 1;
            
            // Check for mixed case
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength += 1;
            
            // Check for numbers
            if (password.match(/\d/)) strength += 1;
            
            // Check for special chars
            if (password.match(/[^a-zA-Z0-9]/)) strength += 1;
            
            // Update strength bar
            const width = strength * 20;
            strengthBar.style.width = width + '%';
            
            // Change color based on strength
            if (strength < 2) {
                strengthBar.style.background = '#e74c3c';
            } else if (strength < 4) {
                strengthBar.style.background = '#f39c12';
            } else {
                strengthBar.style.background = '#2ecc71';
            }
        });
        
        // Confirm password match
        document.getElementById('confirm_password').addEventListener('input', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = e.target.value;
            
            if (confirmPassword && password !== confirmPassword) {
                e.target.setCustomValidity('Passwords do not match');
            } else {
                e.target.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
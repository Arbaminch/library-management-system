<?php
require_once 'config.php';

// Check if member is logged in
if (!isset($_SESSION['member_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: ../login.php');
    exit;
}

// Check if book_id is provided
if (!isset($_GET['book_id']) || !is_numeric($_GET['book_id'])) {
    $_SESSION['error'] = 'Invalid book selection';
    header('Location: ../catalog.php');
    exit;
}

$book_id = intval($_GET['book_id']);
$member_id = $_SESSION['member_id'];

// Check if book exists and is available
$book_stmt = $conn->prepare("SELECT * FROM books WHERE id = ? AND status = 'Available'");
$book_stmt->bind_param("i", $book_id);
$book_stmt->execute();
$book_result = $book_stmt->get_result();

if ($book_result->num_rows === 0) {
    $_SESSION['error'] = 'Book is not available for loan';
    header('Location: ../catalog.php');
    exit;
}

$book = $book_result->fetch_assoc();

// Check if member already has this book on loan
$existing_loan_stmt = $conn->prepare("SELECT id FROM loans WHERE book_id = ? AND member_id = ? AND status IN ('Active', 'Overdue')");
$existing_loan_stmt->bind_param("ii", $book_id, $member_id);
$existing_loan_stmt->execute();
$existing_loan_result = $existing_loan_stmt->get_result();

if ($existing_loan_result->num_rows > 0) {
    $_SESSION['error'] = 'You already have this book on loan';
    header('Location: ../catalog.php');
    exit;
}

// Check if member has too many active loans (max 5)
$active_loans_stmt = $conn->prepare("SELECT COUNT(*) as count FROM loans WHERE member_id = ? AND status IN ('Active', 'Overdue')");
$active_loans_stmt->bind_param("i", $member_id);
$active_loans_stmt->execute();
$active_loans_result = $active_loans_stmt->get_result();
$active_loans = $active_loans_result->fetch_assoc()['count'];

if ($active_loans >= 5) {
    $_SESSION['error'] = 'You have reached the maximum number of active loans (5)';
    header('Location: ../catalog.php');
    exit;
}

// Check if member has any overdue books
$overdue_loans_stmt = $conn->prepare("SELECT COUNT(*) as count FROM loans WHERE member_id = ? AND status = 'Overdue'");
$overdue_loans_stmt->bind_param("i", $member_id);
$overdue_loans_stmt->execute();
$overdue_loans_result = $overdue_loans_stmt->get_result();
$overdue_loans = $overdue_loans_result->fetch_assoc()['count'];

if ($overdue_loans > 0) {
    $_SESSION['error'] = 'You cannot borrow new books while you have overdue loans';
    header('Location: ../catalog.php');
    exit;
}

// Process loan request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+14 days')); // 2-week loan period
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Create loan record
        $loan_stmt = $conn->prepare("INSERT INTO loans (book_id, member_id, loan_date, due_date, status) VALUES (?, ?, ?, ?, 'Active')");
        $loan_stmt->bind_param("iiss", $book_id, $member_id, $loan_date, $due_date);
        $loan_stmt->execute();
        
        // Update book status
        $book_update_stmt = $conn->prepare("UPDATE books SET status = 'On Loan' WHERE id = ?");
        $book_update_stmt->bind_param("i", $book_id);
        $book_update_stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = 'Book loaned successfully! Please return by ' . date('F j, Y', strtotime($due_date));
        header('Location: dashboard.php');
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error'] = 'Error processing loan: ' . $e->getMessage();
        header('Location: ../catalog.php');
        exit;
    }
}

// Get member details
$member_stmt = $conn->prepare("SELECT first_name, last_name, email FROM members WHERE id = ?");
$member_stmt->bind_param("i", $member_id);
$member_stmt->execute();
$member_result = $member_stmt->get_result();
$member = $member_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Book Loan</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .loan-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .loan-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .loan-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .book-info, .member-info {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: 8px;
        }
        
        .info-label {
            font-weight: bold;
            color: #555;
            margin-bottom: 0.5rem;
        }
        
        .info-value {
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .loan-terms {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f0f7ff;
            border-radius: 8px;
        }
        
        .loan-terms h3 {
            margin-top: 0;
            color: #2c3e50;
        }
        
        .loan-terms ul {
            padding-left: 1.5rem;
        }
        
        .loan-terms li {
            margin-bottom: 0.5rem;
        }
        
        .form-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn-confirm {
            background: #2ecc71;
            color: white;
        }
        
        .btn-cancel {
            background: #e74c3c;
            color: white;
        }
    </style>
</head>
<body>
    <header>
        <div class="container header-container">
            <div class="logo">Community Library</div>
            <nav>
                <ul>
                    <li><a href="../index.php">Home</a></li>
                    <li><a href="../catalog.php">Catalog</a></li>
                    <li><a href="dashboard.php">My Account</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="loan-container">
            <div class="loan-header">
                <h1>Request Book Loan</h1>
                <p>Please review the details below before confirming your loan</p>
            </div>
            
            <div class="loan-details">
                <div class="book-info">
                    <h2>Book Information</h2>
                    <div class="info-label">Title</div>
                    <div class="info-value"><?php echo htmlspecialchars($book['title']); ?></div>
                    
                    <div class="info-label">Author</div>
                    <div class="info-value"><?php echo htmlspecialchars($book['author']); ?></div>
                    
                    <div class="info-label">ISBN</div>
                    <div class="info-value"><?php echo htmlspecialchars($book['isbn']); ?></div>
                    
                    <div class="info-label">Publisher</div>
                    <div class="info-value"><?php echo htmlspecialchars($book['publisher'] ?? 'N/A'); ?></div>
                </div>
                
                <div class="member-info">
                    <h2>Your Information</h2>
                    <div class="info-label">Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($member['first_name']) . ' ' . htmlspecialchars($member['last_name']); ?></div>
                    
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($member['email']); ?></div>
                    
                    <div class="info-label">Active Loans</div>
                    <div class="info-value"><?php echo $active_loans; ?> of 5 maximum</div>
                    
                    <div class="info-label">Overdue Loans</div>
                    <div class="info-value"><?php echo $overdue_loans; ?></div>
                </div>
            </div>
            
            <div class="loan-terms">
                <h3>Loan Terms & Conditions</h3>
                <ul>
                    <li>Loan period: 14 days from today (<?php echo date('F j, Y'); ?>)</li>
                    <li>Due date: <?php echo date('F j, Y', strtotime('+14 days')); ?></li>
                    <li>Maximum of 5 active loans at any time</li>
                    <li>Late returns will incur fines of $0.50 per day</li>
                    <li>Books must be returned in the same condition as borrowed</li>
                    <li>You will receive email reminders 3 days before due date</li>
                </ul>
            </div>
            
            <form method="POST" action="request_loan.php?book_id=<?php echo $book_id; ?>">
                <div class="form-actions">
                    <a href="../catalog.php" class="btn btn-cancel">Cancel</a>
                    <button type="submit" class="btn btn-confirm">Confirm Loan</button>
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
                    <li><a href="../index.php" style="color: white;">Home</a></li>
                    <li><a href="../catalog.php" style="color: white;">Catalog</a></li>
                    <li><a href="dashboard.php" style="color: white;">My Account</a></li>
                    <li><a href="../contact.php" style="color: white;">Contact</a></li>
                </ul>
            </div>
        </div>
        <div class="container" style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1);">
            <p>&copy; <?php echo date('Y'); ?> Community Library. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
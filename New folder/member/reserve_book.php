<?php
require_once 'config.php';

// Check if member is logged in
if (!isset($_SESSION['member_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

// Check if book_id is provided
if (!isset($_GET['book_id']) || !is_numeric($_GET['book_id'])) {
    $_SESSION['error'] = 'Invalid book selection';
    header('Location: catalog.php');
    exit;
}

$book_id = intval($_GET['book_id']);
$member_id = $_SESSION['member_id'];

// Check if book exists and is not available
$book_stmt = $conn->prepare("
    SELECT b.*, 
           (SELECT COUNT(*) FROM reservations WHERE book_id = b.id AND status = 'Pending') as pending_reserves
    FROM books b 
    WHERE b.id = ? AND b.status != 'Available'
");
$book_stmt->bind_param("i", $book_id);
$book_stmt->execute();
$book_result = $book_stmt->get_result();

if ($book_result->num_rows === 0) {
    $_SESSION['error'] = 'Book is available for immediate checkout or does not exist';
    header('Location: catalog.php');
    exit;
}

$book = $book_result->fetch_assoc();

// Check reservation limits (max 3 pending reserves per book)
if ($book['pending_reserves'] >= 3) {
    $_SESSION['error'] = 'This book already has the maximum number of reservations (3)';
    header('Location: catalog.php');
    exit;
}

// Check if member already has this book reserved
$existing_reserve_stmt = $conn->prepare("
    SELECT id FROM reservations 
    WHERE book_id = ? AND member_id = ? AND status = 'Pending'
");
$existing_reserve_stmt->bind_param("ii", $book_id, $member_id);
$existing_reserve_stmt->execute();
$existing_reserve_result = $existing_reserve_stmt->get_result();

if ($existing_reserve_result->num_rows > 0) {
    $_SESSION['error'] = 'You already have a pending reservation for this book';
    header('Location: catalog.php');
    exit;
}

// Check if member already has this book on loan
$existing_loan_stmt = $conn->prepare("
    SELECT id FROM loans 
    WHERE book_id = ? AND member_id = ? AND status IN ('Active', 'Overdue')
");
$existing_loan_stmt->bind_param("ii", $book_id, $member_id);
$existing_loan_stmt->execute();
$existing_loan_result = $existing_loan_stmt->get_result();

if ($existing_loan_result->num_rows > 0) {
    $_SESSION['error'] = 'You already have this book on loan';
    header('Location: catalog.php');
    exit;
}

// Check if member has too many active reservations (max 5)
$active_reserves_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM reservations 
    WHERE member_id = ? AND status = 'Pending'
");
$active_reserves_stmt->bind_param("i", $member_id);
$active_reserves_stmt->execute();
$active_reserves_result = $active_reserves_stmt->get_result();
$active_reserves = $active_reserves_result->fetch_assoc()['count'];

if ($active_reserves >= 5) {
    $_SESSION['error'] = 'You have reached the maximum number of active reservations (5)';
    header('Location: catalog.php');
    exit;
}

// Process reservation request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reservation_date = date('Y-m-d');
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Create reservation record
        $reserve_stmt = $conn->prepare("
            INSERT INTO reservations (book_id, member_id, reservation_date, status)
            VALUES (?, ?, ?, 'Pending')
        ");
        $reserve_stmt->bind_param("iis", $book_id, $member_id, $reservation_date);
        $reserve_stmt->execute();
        
        // Update book status if it's not already reserved
        if ($book['status'] !== 'Reserved') {
            $book_update_stmt = $conn->prepare("
                UPDATE books SET status = 'Reserved' WHERE id = ?
            ");
            $book_update_stmt->bind_param("i", $book_id);
            $book_update_stmt->execute();
        }
        
        // Commit transaction
        $conn->commit();
        
        // Get member details for notification
        $member_stmt = $conn->prepare("SELECT first_name, email FROM members WHERE id = ?");
        $member_stmt->bind_param("i", $member_id);
        $member_stmt->execute();
        $member_result = $member_stmt->get_result();
        $member = $member_result->fetch_assoc();
        
        // Send email notification
        $to = $member['email'];
        $subject = "Library Reservation Confirmation: " . $book['title'];
        $message = "Hello " . $member['first_name'] . ",\n\n";
        $message .= "You have successfully reserved:\n";
        $message .= $book['title'] . " by " . $book['author'] . "\n\n";
        $message .= "You will be notified when the book becomes available for pickup.\n";
        $message .= "Your position in queue: " . ($book['pending_reserves'] + 1) . "\n\n";
        $message .= "Thank you for using our library!";
        
        mail($to, $subject, $message);
        
        $_SESSION['success'] = 'Book reserved successfully! You will be notified when it becomes available.';
        header('Location: dashboard.php');
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error'] = 'Error processing reservation: ' . $e->getMessage();
        header('Location: catalog.php');
        exit;
    }
}

// Get member details
$member_stmt = $conn->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
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
    <title>Reserve Book - Library Management System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .reservation-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .reservation-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .reservation-details {
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
        
        .reservation-terms {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f0f7ff;
            border-radius: 8px;
        }
        
        .reservation-terms h3 {
            margin-top: 0;
            color: #2c3e50;
        }
        
        .reservation-terms ul {
            padding-left: 1.5rem;
        }
        
        .reservation-terms li {
            margin-bottom: 0.5rem;
        }
        
        .queue-position {
            text-align: center;
            font-size: 1.2rem;
            margin: 1rem 0;
            padding: 1rem;
            background: #e8f4fc;
            border-radius: 8px;
            font-weight: bold;
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
        <div class="reservation-container">
            <div class="reservation-header">
                <h1>Reserve Book</h1>
                <p>Please review the details below before confirming your reservation</p>
            </div>
            
            <div class="reservation-details">
                <div class="book-info">
                    <h2>Book Information</h2>
                    <div class="info-label">Title</div>
                    <div class="info-value"><?php echo htmlspecialchars($book['title']); ?></div>
                    
                    <div class="info-label">Author</div>
                    <div class="info-value"><?php echo htmlspecialchars($book['author']); ?></div>
                    
                    <div class="info-label">ISBN</div>
                    <div class="info-value"><?php echo htmlspecialchars($book['isbn']); ?></div>
                    
                    <div class="info-label">Current Status</div>
                    <div class="info-value"><?php echo htmlspecialchars($book['status']); ?></div>
                </div>
                
                <div class="member-info">
                    <h2>Your Information</h2>
                    <div class="info-label">Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></div>
                    
                    <div class="info-label">Active Reservations</div>
                    <div class="info-value"><?php echo $active_reserves; ?> of 5 maximum</div>
                </div>
            </div>
            
            <div class="queue-position">
                Your position in the reservation queue: <?php echo ($book['pending_reserves'] + 1); ?>
            </div>
            
            <div class="reservation-terms">
                <h3>Reservation Terms & Conditions</h3>
                <ul>
                    <li>You will be notified by email when the book becomes available</li>
                    <li>You will have 3 days to pick up the book once notified</li>
                    <li>Maximum of 5 active reservations per member</li>
                    <li>Maximum of 3 reservations per book</li>
                    <li>Reservations are processed in the order they are received</li>
                    <li>You cannot reserve a book you already have on loan</li>
                </ul>
            </div>
            
            <form method="POST" action="reserve_book.php?book_id=<?php echo $book_id; ?>">
                <div class="form-actions">
                    <a href="../catalog.php" class="btn btn-cancel">Cancel</a>
                    <button type="submit" class="btn btn-confirm">Confirm Reservation</button>
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
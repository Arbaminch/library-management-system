<?php
require_once 'config.php';

if (!isset($_SESSION['member_id'])) {
    redirect('../login.php');
}

// Get member's active loans
$member_id = $_SESSION['member_id'];
// Active loans
$stmt = $conn->prepare("
    SELECT l.*, b.title, b.author 
    FROM loans l
    JOIN books b ON l.book_id = b.id
    WHERE l.member_id = ? AND l.status = 'Active'
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$active_loans = $stmt->get_result();
// Overdue loans
$stmt2 = $conn->prepare("
    SELECT l.*, b.title, b.author 
    FROM loans l
    JOIN books b ON l.book_id = b.id
    WHERE l.member_id = ? AND l.status = 'Overdue'
");
$stmt2->bind_param("i", $member_id);
$stmt2->execute();
$overdue_loans = $stmt2->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Management - Member Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header>
        <div class="container header-container">
            <div class="logo">Library Management</div>
            <nav>
                <ul>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="books.php">Browse Books</a></li>
                    <li><a href="loans.php">My Loans</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <h1>Welcome, <?php echo $_SESSION['member_name']; ?></h1>

        <div class="dashboard-cards">
            <div class="card">
                <h3>Active Loans</h3>
                <div class="count"><?php echo $active_loans->num_rows; ?></div>
            </div>
            <div class="card">
                <h3>Overdue Loans</h3>
                <div class="count"><?php echo $overdue_loans->num_rows; ?></div>
            </div>
        </div>

        <section>
            <h2>My Active Loans</h2>
            <?php if ($active_loans->num_rows > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>Loan Date</th>
                            <th>Due Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($loan = $active_loans->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $loan['title']; ?></td>
                                <td><?php echo $loan['author']; ?></td>
                                <td><?php echo $loan['loan_date']; ?></td>
                                <td><?php echo $loan['due_date']; ?></td>
                                <td>
                                    <a href="return_book.php?loan_id=<?php echo $loan['id']; ?>" class="btn">Return</a>
                                    <a href="renew_loan.php?loan_id=<?php echo $loan['id']; ?>" class="btn">Renew</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>You have no active loans.</p>
            <?php endif; ?>
        </section>
    </main>

    <script src="../assets/js/main.js"></script>
</body>
</html>
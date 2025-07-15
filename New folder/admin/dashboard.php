<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}

// Get stats
$books_count = $conn->query("SELECT COUNT(*) FROM books")->fetch_row()[0];
$members_count = $conn->query("SELECT COUNT(*) FROM members")->fetch_row()[0];
$active_loans = $conn->query("SELECT COUNT(*) FROM loans WHERE status = 'Active'")->fetch_row()[0];
$overdue_loans = $conn->query("SELECT COUNT(*) FROM loans WHERE status = 'Overdue'")->fetch_row()[0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Management - Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header>
        <div class="container header-container">
            <div class="logo">Library Management</div>
            <nav>
                <ul>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="books.php">Books</a></li>
                    <li><a href="members.php">Members</a></li>
                    <li><a href="loans.php">Loans</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <h1>Admin Dashboard</h1>
        <p>Welcome, <?php echo $_SESSION['username']; ?></p>

        <div class="dashboard-cards">
            <div class="card">
                <h3>Total Books</h3>
                <div class="count"><?php echo $books_count; ?></div>
            </div>
            <div class="card">
                <h3>Total Members</h3>
                <div class="count"><?php echo $members_count; ?></div>
            </div>
            <div class="card">
                <h3>Active Loans</h3>
                <div class="count"><?php echo $active_loans; ?></div>
            </div>
            <div class="card">
                <h3>Overdue Loans</h3>
                <div class="count"><?php echo $overdue_loans; ?></div>
            </div>
        </div>
    </main>

    <script src="../assets/js/main.js"></script>
</body>
</html>
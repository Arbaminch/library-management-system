<?php
require_once 'config.php';

// Check if user is logged in and is a librarian/admin
if (!isLoggedIn() || (!isAdmin() && $_SESSION['role'] !== 'Librarian')) {
    header('Location: login.php');
    exit;
}

// Handle loan return
if (isset($_GET['return'])) {
    $loan_id = intval($_GET['return']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get loan details
        $loan_stmt = $conn->prepare("
            SELECT l.*, b.title, b.status as book_status 
            FROM loans l
            JOIN books b ON l.book_id = b.id
            WHERE l.id = ?
        ");
        $loan_stmt->bind_param("i", $loan_id);
        $loan_stmt->execute();
        $loan = $loan_stmt->get_result()->fetch_assoc();
        
        if (!$loan) {
            throw new Exception("Loan not found");
        }
        
        // Update loan status
        $update_loan = $conn->prepare("
            UPDATE loans 
            SET status = 'Returned', return_date = CURDATE() 
            WHERE id = ?
        ");
        $update_loan->bind_param("i", $loan_id);
        $update_loan->execute();
        
        // Update book status if it was on loan
        if ($loan['book_status'] === 'On Loan') {
            // Check if there are pending reservations
            $reserve_check = $conn->prepare("
                SELECT id FROM reservations 
                WHERE book_id = ? AND status = 'Pending' 
                ORDER BY reservation_date ASC 
                LIMIT 1
            ");
            $reserve_check->bind_param("i", $loan['book_id']);
            $reserve_check->execute();
            $has_reservations = $reserve_check->get_result()->num_rows > 0;
            
            if ($has_reservations) {
                // Book is reserved for next person
                $update_book = $conn->prepare("
                    UPDATE books SET status = 'Reserved' WHERE id = ?
                ");
            } else {
                // Book is available
                $update_book = $conn->prepare("
                    UPDATE books SET status = 'Available' WHERE id = ?
                ");
            }
            $update_book->bind_param("i", $loan['book_id']);
            $update_book->execute();
        }
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = "Book '{$loan['title']}' has been returned successfully";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error returning book: " . $e->getMessage();
    }
    
    header('Location: loans.php');
    exit;
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!isset($_POST['selected_loans']) || empty($_POST['selected_loans'])) {
        $_SESSION['error'] = 'No loans selected';
    } else {
        $selected_loans = array_map('intval', $_POST['selected_loans']);
        $placeholders = implode(',', array_fill(0, count($selected_loans), '?'));
        
        if ($_POST['bulk_action'] === 'mark_returned') {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get books that will be affected
                $books_stmt = $conn->prepare("
                    SELECT l.book_id, b.status as book_status 
                    FROM loans l
                    JOIN books b ON l.book_id = b.id
                    WHERE l.id IN ($placeholders)
                ");
                $types = str_repeat('i', count($selected_loans));
                $books_stmt->bind_param($types, ...$selected_loans);
                $books_stmt->execute();
                $books = $books_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                // Update loans
                $update_loans = $conn->prepare("
                    UPDATE loans 
                    SET status = 'Returned', return_date = CURDATE() 
                    WHERE id IN ($placeholders)
                ");
                $update_loans->bind_param($types, ...$selected_loans);
                $update_loans->execute();
                
                // Update book statuses
                foreach ($books as $book) {
                    if ($book['book_status'] === 'On Loan') {
                        // Check if there are pending reservations
                        $reserve_check = $conn->prepare("
                            SELECT id FROM reservations 
                            WHERE book_id = ? AND status = 'Pending' 
                            ORDER BY reservation_date ASC 
                            LIMIT 1
                        ");
                        $reserve_check->bind_param("i", $book['book_id']);
                        $reserve_check->execute();
                        $has_reservations = $reserve_check->get_result()->num_rows > 0;
                        
                        if ($has_reservations) {
                            // Book is reserved for next person
                            $update_book = $conn->prepare("
                                UPDATE books SET status = 'Reserved' WHERE id = ?
                            ");
                        } else {
                            // Book is available
                            $update_book = $conn->prepare("
                                UPDATE books SET status = 'Available' WHERE id = ?
                            ");
                        }
                        $update_book->bind_param("i", $book['book_id']);
                        $update_book->execute();
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                $_SESSION['success'] = 'Marked ' . $update_loans->affected_rows . ' loans as returned';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = 'Error processing bulk return: ' . $e->getMessage();
            }
        }
    }
    
    header('Location: loans.php');
    exit;
}

// Pagination and filtering
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$status_filter = $_GET['status'] ?? 'Active';
$member_search = $_GET['member'] ?? '';
$book_search = $_GET['book'] ?? '';
$overdue_only = isset($_GET['overdue']);

// Build query
$query = "
    SELECT l.*, 
           b.title as book_title, b.isbn,
           m.first_name, m.last_name, m.email,
           DATEDIFF(l.due_date, CURDATE()) as days_remaining
    FROM loans l
    JOIN books b ON l.book_id = b.id
    JOIN members m ON l.member_id = m.id
    WHERE 1=1
";

$count_query = "
    SELECT COUNT(*) as total
    FROM loans l
    JOIN books b ON l.book_id = b.id
    JOIN members m ON l.member_id = m.id
    WHERE 1=1
";

$params = [];
$types = '';

if (!empty($status_filter)) {
    $query .= " AND l.status = ?";
    $count_query .= " AND l.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($member_search)) {
    $query .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.email LIKE ?)";
    $count_query .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.email LIKE ?)";
    $member_term = "%$member_search%";
    $params = array_merge($params, [$member_term, $member_term, $member_term]);
    $types .= 'sss';
}

if (!empty($book_search)) {
    $query .= " AND (b.title LIKE ? OR b.isbn LIKE ?)";
    $count_query .= " AND (b.title LIKE ? OR b.isbn LIKE ?)";
    $book_term = "%$book_search%";
    $params = array_merge($params, [$book_term, $book_term]);
    $types .= 'ss';
}

if ($overdue_only) {
    $query .= " AND l.status = 'Active' AND l.due_date < CURDATE()";
    $count_query .= " AND l.status = 'Active' AND l.due_date < CURDATE()";
}

// Add sorting
$sort = $_GET['sort'] ?? 'due_date';
$order = $_GET['order'] ?? 'asc';
$valid_sorts = ['book_title', 'first_name', 'due_date', 'loan_date', 'return_date', 'status'];
$valid_orders = ['asc', 'desc'];

if (!in_array($sort, $valid_sorts)) $sort = 'due_date';
if (!in_array($order, $valid_orders)) $order = 'asc';

$query .= " ORDER BY $sort $order";

// Add pagination
$query .= " LIMIT ? OFFSET ?";
$params = array_merge($params, [$limit, $offset]);
$types .= 'ii';

// Execute query
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total count
$count_stmt = $conn->prepare($count_query);
if ($types) {
    // Remove pagination parameters for count query
    $count_params = array_slice($params, 0, count($params) - 2);
    $count_types = substr($types, 0, -2);
    if ($count_types) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

// Get counts for status tabs
$status_counts = [];
$statuses = ['Active', 'Overdue', 'Returned'];
foreach ($statuses as $status) {
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM loans 
        WHERE status = ?
    ");
    $count_stmt->bind_param("s", $status);
    $count_stmt->execute();
    $result = $count_stmt->get_result()->fetch_assoc();
    $status_counts[$status] = $result['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Loans - Library Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .loans-management {
            max-width: 1200px;
            margin: 2rem auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .status-tabs {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 1rem;
        }
        
        .status-tab {
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            border: 1px solid transparent;
            border-bottom: none;
            margin-right: 0.5rem;
            border-radius: 4px 4px 0 0;
            position: relative;
            bottom: -1px;
        }
        
        .status-tab:hover {
            background: #f5f5f5;
        }
        
        .status-tab.active {
            border-color: #ddd;
            border-bottom-color: white;
            background: white;
            font-weight: bold;
        }
        
        .status-tab .count {
            background: #eee;
            padding: 0.25rem 0.5rem;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }
        
        .search-filters {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .filter-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .bulk-actions {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .loans-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .loans-table th,
        .loans-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .loans-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .loans-table tr:hover {
            background: #f5f5f5;
        }
        
        .sort-link {
            color: inherit;
            text-decoration: none;
            display: flex;
            align-items: center;
        }
        
        .sort-link:hover {
            color: #3498db;
        }
        
        .sort-icon {
            margin-left: 0.5rem;
            font-size: 0.8rem;
        }
        
        .status-active {
            color: #155724;
            background: #d4edda;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-overdue {
            color: #856404;
            background: #fff3cd;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-returned {
            color: #0c5460;
            background: #d1ecf1;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .days-remaining {
            font-weight: bold;
        }
        
        .days-overdue {
            color: #721c24;
            font-weight: bold;
        }
        
        .action-links {
            display: flex;
            gap: 0.5rem;
        }
        
        .action-link {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            text-decoration: none;
        }
        
        .action-link.view {
            background: #3498db;
            color: white;
        }
        
        .action-link.return {
            background: #2ecc71;
            color: white;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
        }
        
        .pagination a:hover {
            background: #f5f5f5;
        }
        
        .pagination .current {
            background: #2c3e50;
            color: white;
            border-color: #2c3e50;
        }
        
        .no-results {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
    </style>
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
                    <li><a href="loans.php" class="active">Loans</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="loans-management">
            <div class="page-header">
                <h1>Manage Loans</h1>
                <div>
                    <a href="new_loan.php" class="btn">Create New Loan</a>
                </div>
            </div>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            
            <div class="status-tabs">
                <a href="loans.php?status=Active" class="status-tab <?php echo $status_filter === 'Active' ? 'active' : ''; ?>">
                    Active
                    <span class="count"><?php echo $status_counts['Active']; ?></span>
                </a>
                <a href="loans.php?status=Overdue" class="status-tab <?php echo $status_filter === 'Overdue' ? 'active' : ''; ?>">
                    Overdue
                    <span class="count"><?php echo $status_counts['Overdue']; ?></span>
                </a>
                <a href="loans.php?status=Returned" class="status-tab <?php echo $status_filter === 'Returned' ? 'active' : ''; ?>">
                    Returned
                    <span class="count"><?php echo $status_counts['Returned']; ?></span>
                </a>
                <?php if ($status_filter === 'Active'): ?>
                    <a href="loans.php?status=Active&overdue=1" class="status-tab <?php echo $overdue_only ? 'active' : ''; ?>">
                        Overdue Only
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="search-filters">
                <form method="GET" action="loans.php">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <?php if ($overdue_only): ?>
                        <input type="hidden" name="overdue" value="1">
                    <?php endif; ?>
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="member">Search Members</label>
                            <input type="text" id="member" name="member" value="<?php echo htmlspecialchars($member_search); ?>" placeholder="Name or email">
                        </div>
                        <div class="filter-group">
                            <label for="book">Search Books</label>
                            <input type="text" id="book" name="book" value="<?php echo htmlspecialchars($book_search); ?>" placeholder="Title or ISBN">
                        </div>
                    </div>
                    <div class="filter-row">
                        <button type="submit" class="btn">Apply Filters</button>
                        <a href="loans.php?status=<?php echo htmlspecialchars($status_filter); ?>" class="btn">Reset Filters</a>
                    </div>
                </form>
            </div>
            
            <form method="POST" action="loans.php">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <input type="hidden" name="member" value="<?php echo htmlspecialchars($member_search); ?>">
                <input type="hidden" name="book" value="<?php echo htmlspecialchars($book_search); ?>">
                <?php if ($overdue_only): ?>
                    <input type="hidden" name="overdue" value="1">
                <?php endif; ?>
                
                <?php if ($status_filter === 'Active' || $status_filter === 'Overdue'): ?>
                    <div class="bulk-actions">
                        <div>
                            <label for="bulk_action">With Selected:</label>
                            <select id="bulk_action" name="bulk_action">
                                <option value="mark_returned">Mark as Returned</option>
                            </select>
                        </div>
                        <button type="submit" class="btn">Apply</button>
                    </div>
                <?php endif; ?>
                
                <?php if (count($loans) > 0): ?>
                    <table class="loans-table">
                        <thead>
                            <tr>
                                <?php if ($status_filter === 'Active' || $status_filter === 'Overdue'): ?>
                                    <th width="30"><input type="checkbox" id="select-all"></th>
                                <?php endif; ?>
                                <th>
                                    <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'book_title', 'order' => $sort === 'book_title' && $order === 'asc' ? 'desc' : 'asc'])); ?>" class="sort-link">
                                        Book
                                        <span class="sort-icon">
                                            <?php if ($sort === 'book_title'): ?>
                                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'first_name', 'order' => $sort === 'first_name' && $order === 'asc' ? 'desc' : 'asc'])); ?>" class="sort-link">
                                        Member
                                        <span class="sort-icon">
                                            <?php if ($sort === 'first_name'): ?>
                                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'loan_date', 'order' => $sort === 'loan_date' && $order === 'asc' ? 'desc' : 'asc'])); ?>" class="sort-link">
                                        Loan Date
                                        <span class="sort-icon">
                                            <?php if ($sort === 'loan_date'): ?>
                                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'due_date', 'order' => $sort === 'due_date' && $order === 'asc' ? 'desc' : 'asc'])); ?>" class="sort-link">
                                        Due Date
                                        <span class="sort-icon">
                                            <?php if ($sort === 'due_date'): ?>
                                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loans as $loan): ?>
                                <tr>
                                    <?php if ($status_filter === 'Active' || $status_filter === 'Overdue'): ?>
                                        <td><input type="checkbox" name="selected_loans[]" value="<?php echo $loan['id']; ?>"></td>
                                    <?php endif; ?>
                                    <td>
                                        <strong><?php echo htmlspecialchars($loan['book_title']); ?></strong><br>
                                        <small>ISBN: <?php echo htmlspecialchars($loan['isbn']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?><br>
                                        <small><?php echo htmlspecialchars($loan['email']); ?></small>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($loan['loan_date'])); ?></td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($loan['due_date'])); ?>
                                        <?php if ($loan['status'] === 'Active'): ?>
                                            <br>
                                            <?php if ($loan['days_remaining'] >= 0): ?>
                                                <small class="days-remaining">(<?php echo $loan['days_remaining']; ?> days remaining)</small>
                                            <?php else: ?>
                                                <small class="days-overdue">(<?php echo abs($loan['days_remaining']); ?> days overdue)</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-<?php echo strtolower($loan['status']); ?>">
                                            <?php echo $loan['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-links">
                                            <a href="view_loan.php?id=<?php echo $loan['id']; ?>" class="action-link view">View</a>
                                            <?php if ($loan['status'] === 'Active' || $loan['status'] === 'Overdue'): ?>
                                                <a href="loans.php?return=<?php echo $loan['id']; ?>" class="action-link return">Return</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-results">
                        <h3>No loans found matching your criteria</h3>
                        <p>Try adjusting your search filters or <a href="loans.php">view all loans</a>.</p>
                    </div>
                <?php endif; ?>
            </form>
            
            <?php if (count($loans) > 0): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">First</a>
                        <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                    <?php endif; ?>
                    
                    <?php 
                    // Show page numbers
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                        <a href="loans.php?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
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

    <script>
        // Select all checkbox functionality
        document.getElementById('select-all')?.addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('input[name="selected_loans[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
        });
        
        // Update select all checkbox when individual checkboxes change
        document.querySelectorAll('input[name="selected_loans[]"]')?.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = document.querySelectorAll('input[name="selected_loans[]"]:checked').length === 
                                 document.querySelectorAll('input[name="selected_loans[]"]').length;
                document.getElementById('select-all').checked = allChecked;
            });
        });
    </script>
</body>
</html>
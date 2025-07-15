<?php
require_once 'config.php';

// Check if user is logged in and is a librarian/admin
if (!isLoggedIn() || (!isAdmin() && $_SESSION['role'] !== 'Librarian')) {
    header('Location: login.php');
    exit;
}

// Handle book deletion
if (isset($_GET['delete']) && isAdmin()) {
    $id = intval($_GET['delete']);
    
    // Check if book is currently on loan
    $loan_check = $conn->prepare("SELECT id FROM loans WHERE book_id = ? AND status = 'Active'");
    $loan_check->bind_param("i", $id);
    $loan_check->execute();
    
    if ($loan_check->get_result()->num_rows > 0) {
        $_SESSION['error'] = 'Cannot delete book that is currently on loan';
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete reservations first
            $delete_reserves = $conn->prepare("DELETE FROM reservations WHERE book_id = ?");
            $delete_reserves->bind_param("i", $id);
            $delete_reserves->execute();
            
            // Delete book
            $delete_book = $conn->prepare("DELETE FROM books WHERE id = ?");
            $delete_book->bind_param("i", $id);
            $delete_book->execute();
            
            // Commit transaction
            $conn->commit();
            $_SESSION['success'] = 'Book deleted successfully';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error deleting book: ' . $e->getMessage();
        }
    }
    
    header('Location: books.php');
    exit;
}

// Handle bulk status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!isset($_POST['selected_books']) || empty($_POST['selected_books'])) {
        $_SESSION['error'] = 'No books selected';
    } else {
        $selected_books = array_map('intval', $_POST['selected_books']);
        $placeholders = implode(',', array_fill(0, count($selected_books), '?'));
        $status = sanitize($_POST['new_status']);
        
        // Validate status
        if (!in_array($status, ['Available', 'On Loan', 'Reserved'])) {
            $_SESSION['error'] = 'Invalid status selected';
        } else {
            $update_stmt = $conn->prepare("
                UPDATE books 
                SET status = ? 
                WHERE id IN ($placeholders)
            ");
            $types = str_repeat('i', count($selected_books));
            $update_stmt->bind_param("s$types", $status, ...$selected_books);
            
            if ($update_stmt->execute()) {
                $_SESSION['success'] = 'Updated status for ' . $update_stmt->affected_rows . ' books';
            } else {
                $_SESSION['error'] = 'Error updating books';
            }
        }
    }
    
    header('Location: books.php');
    exit;
}

// Pagination and filtering
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query
$query = "SELECT * FROM books WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM books WHERE 1=1";
$params = [];
$types = '';

if (!empty($search)) {
    $query .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $count_query .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
    $types .= 'sss';
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $count_query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

// Add sorting
$sort = $_GET['sort'] ?? 'title';
$order = $_GET['order'] ?? 'asc';
$valid_sorts = ['title', 'author', 'status', 'created_at'];
$valid_orders = ['asc', 'desc'];

if (!in_array($sort, $valid_sorts)) $sort = 'title';
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
$books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Books - Library Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .books-management {
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
        
        .books-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .books-table th,
        .books-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .books-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .books-table tr:hover {
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
        
        .status-available {
            color: #155724;
            background: #d4edda;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-loan {
            color: #856404;
            background: #fff3cd;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-reserved {
            color: #721c24;
            background: #f8d7da;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
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
        
        .action-link.edit {
            background: #f39c12;
            color: white;
        }
        
        .action-link.delete {
            background: #e74c3c;
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
                    <li><a href="books.php" class="active">Books</a></li>
                    <li><a href="members.php">Members</a></li>
                    <li><a href="loans.php">Loans</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="books-management">
            <div class="page-header">
                <h1>Manage Books</h1>
                <div>
                    <a href="add_book.php" class="btn">Add New Book</a>
                </div>
            </div>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            
            <div class="search-filters">
                <form method="GET" action="books.php">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search">Search</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Title, Author or ISBN">
                        </div>
                        <div class="filter-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="Available" <?php if ($status_filter === 'Available') echo 'selected'; ?>>Available</option>
                                <option value="On Loan" <?php if ($status_filter === 'On Loan') echo 'selected'; ?>>On Loan</option>
                                <option value="Reserved" <?php if ($status_filter === 'Reserved') echo 'selected'; ?>>Reserved</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-row">
                        <button type="submit" class="btn">Apply Filters</button>
                        <a href="books.php" class="btn">Reset Filters</a>
                    </div>
                </form>
            </div>
            
            <form method="POST" action="books.php">
                <div class="bulk-actions">
                    <div>
                        <label for="bulk_action">With Selected:</label>
                        <select id="bulk_action" name="bulk_action">
                            <option value="update_status">Update Status</option>
                        </select>
                        <select name="new_status">
                            <option value="Available">Available</option>
                            <option value="On Loan">On Loan</option>
                            <option value="Reserved">Reserved</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">Apply</button>
                </div>
                
                <?php if (count($books) > 0): ?>
                    <table class="books-table">
                        <thead>
                            <tr>
                                <th width="30"><input type="checkbox" id="select-all"></th>
                                <th>
                                    <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'title', 'order' => $sort === 'title' && $order === 'asc' ? 'desc' : 'asc'])); ?>" class="sort-link">
                                        Title
                                        <span class="sort-icon">
                                            <?php if ($sort === 'title'): ?>
                                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'author', 'order' => $sort === 'author' && $order === 'asc' ? 'desc' : 'asc'])); ?>" class="sort-link">
                                        Author
                                        <span class="sort-icon">
                                            <?php if ($sort === 'author'): ?>
                                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>ISBN</th>
                                <th>Publisher</th>
                                <th>
                                    <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'status', 'order' => $sort === 'status' && $order === 'asc' ? 'desc' : 'asc'])); ?>" class="sort-link">
                                        Status
                                        <span class="sort-icon">
                                            <?php if ($sort === 'status'): ?>
                                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort === 'created_at' && $order === 'asc' ? 'desc' : 'asc'])); ?>" class="sort-link">
                                        Added On
                                        <span class="sort-icon">
                                            <?php if ($sort === 'created_at'): ?>
                                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($books as $book): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_books[]" value="<?php echo $book['id']; ?>"></td>
                                    <td><?php echo htmlspecialchars($book['title']); ?></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo htmlspecialchars($book['isbn']); ?></td>
                                    <td><?php echo htmlspecialchars($book['publisher'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="status-<?php echo strtolower(str_replace(' ', '-', $book['status'])); ?>">
                                            <?php echo $book['status']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($book['created_at'])); ?></td>
                                    <td>
                                        <div class="action-links">
                                            <a href="view_book.php?id=<?php echo $book['id']; ?>" class="action-link view">View</a>
                                            <a href="edit_book.php?id=<?php echo $book['id']; ?>" class="action-link edit">Edit</a>
                                            <?php if (isAdmin()): ?>
                                                <a href="books.php?delete=<?php echo $book['id']; ?>" class="action-link delete" onclick="return confirm('Are you sure you want to delete this book?')">Delete</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-results">
                        <h3>No books found matching your criteria</h3>
                        <p>Try adjusting your search filters or <a href="books.php">view all books</a>.</p>
                    </div>
                <?php endif; ?>
            </form>
            
            <?php if (count($books) > 0): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">First</a>
                        <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                    <?php endif; ?>
                    
                    <?php 
                    // Show page numbers
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                        <a href="books.php?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last</a>
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
        document.getElementById('select-all').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('input[name="selected_books[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
        });
        
        // Update select all checkbox when individual checkboxes change
        document.querySelectorAll('input[name="selected_books[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = document.querySelectorAll('input[name="selected_books[]"]:checked').length === 
                                 document.querySelectorAll('input[name="selected_books[]"]').length;
                document.getElementById('select-all').checked = allChecked;
            });
        });
    </script>
</body>
</html>
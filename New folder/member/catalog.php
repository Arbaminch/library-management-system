<?php
require_once 'config.php';

// Initialize variables
$search = $_GET['search'] ?? '';
$author = $_GET['author'] ?? '';
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Build the base query
$query = "SELECT * FROM books WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM books WHERE 1=1";
$params = [];
$types = '';

// Add search conditions
if (!empty($search)) {
    $query .= " AND (title LIKE ? OR isbn LIKE ?)";
    $count_query .= " AND (title LIKE ? OR isbn LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term]);
    $types .= 'ss';
}

if (!empty($author)) {
    $query .= " AND author LIKE ?";
    $count_query .= " AND author LIKE ?";
    $author_term = "%$author%";
    $params[] = $author_term;
    $types .= 's';
}

if (!empty($status)) {
    $query .= " AND status = ?";
    $count_query .= " AND status = ?";
    $params[] = $status;
    $types .= 's';
}

// Add pagination to the main query
$query .= " LIMIT ? OFFSET ?";
$params = array_merge($params, [$limit, $offset]);
$types .= 'ii';

// Prepare and execute the query
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$books = $result->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$count_stmt = $conn->prepare($count_query);
if ($types) {
    // Remove the pagination parameters for count query
    $count_params = array_slice($params, 0, count($params) - 2);
    $count_types = substr($types, 0, -2);
    if ($count_types) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

// Get all distinct authors for filter
$authors = $conn->query("SELECT DISTINCT author FROM books ORDER BY author")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Catalog</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .catalog-header {
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
        
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .book-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .book-cover {
            height: 280px;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #777;
            position: relative;
        }
        
        .book-status {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        .status-available {
            background: #d4edda;
            color: #155724;
        }
        
        .status-loan {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-reserved {
            background: #f8d7da;
            color: #721c24;
        }
        
        .book-info {
            padding: 1rem;
        }
        
        .book-title {
            font-weight: bold;
            margin-bottom: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .book-author {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .book-isbn {
            color: #999;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }
        
        .book-actions {
            margin-top: 1rem;
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
<?php include 'nav.php'; ?>

    <main class="container">
        <div class="catalog-header">
            <div>
                <?php if (isset($_SESSION['member_id'])): ?>
                    <a href="/member/dashboard.php" class="btn">My Dashboard</a>
                <?php else: ?>
                    <a href="/member/login.php" class="btn">Member Login</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="search-filters">
            <form method="get" action="catalog.php">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="search">Search by Title/ISBN</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="author">Filter by Author</label>
                        <select id="author" name="author">
                            <option value="">All Authors</option>
                            <?php foreach ($authors as $a): ?>
                                <option value="<?php echo htmlspecialchars($a['author']); ?>" 
                                    <?php if ($author === $a['author']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($a['author']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="status">Filter by Status</label>
                        <select id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="Available" <?php if ($status === 'Available') echo 'selected'; ?>>Available</option>
                            <option value="On Loan" <?php if ($status === 'On Loan') echo 'selected'; ?>>On Loan</option>
                            <option value="Reserved" <?php if ($status === 'Reserved') echo 'selected'; ?>>Reserved</option>
                        </select>
                    </div>
                </div>
                <div class="filter-row">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="catalog.php" class="btn">Reset Filters</a>
                </div>
            </form>
        </div>

        <?php if (count($books) > 0): ?>
            <div class="books-grid">
                <?php foreach ($books as $book): ?>
                    <div class="book-card">
                        <div class="book-cover">
                            <?php echo substr($book['title'], 0, 1); ?>
                            <span class="book-status status-<?php echo strtolower(str_replace(' ', '-', $book['status'])); ?>">
                                <?php echo $book['status']; ?>
                            </span>
                        </div>
                        <div class="book-info">
                            <div class="book-title" title="<?php echo htmlspecialchars($book['title']); ?>">
                                <?php echo htmlspecialchars($book['title']); ?>
                            </div>
                            <div class="book-author">by <?php echo htmlspecialchars($book['author']); ?></div>
                            <div class="book-isbn">ISBN: <?php echo htmlspecialchars($book['isbn']); ?></div>
                            <div class="book-actions">
                                <?php if (isset($_SESSION['member_id'])): ?>
                                    <?php if ($book['status'] === 'Available'): ?>
                                        <a href="/member/request_loan.php?book_id=<?php echo $book['id']; ?>"
                                         class="btn">Borrow</a>
                                    <?php elseif ($book['status'] === 'On Loan'): ?>
                                        <button class="btn" disabled>Borrow</button>
                                        <small>Available soon</small>
                                    <?php else: ?>
                                        <a href="reserve_book.php?book_id=<?php echo 
                                        $book['id']; ?>" class="btn">Reserve</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="login.php" class="btn">Login to Borrow</a>
                                <?php endif; ?>
                                <a href="book_details.php?id=<?php echo $book['id']; ?>" class="btn">Details</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="/member/catalog.php?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">First</a>
                    <a href="/member/catalog.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1]));
                     ?>">Previous</a>
                <?php endif; ?>

                <?php 
                // Show page numbers
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                
                for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="/member/catalog.php?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="/member/catalog.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1]));
                     ?>">Next</a>
                    <a href="/member/catalog.php?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-results">
                <h3>No books found matching your criteria</h3>
                <p>Try adjusting your search filters or <a href="catalog.php">view all books</a>.</p>
            </div>
        <?php endif; ?>
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
                    <li><a href="/member/index.php" style="color: white;">Home</a></li>
                    <li><a href="/member/catalog.php" style="color: white;">Catalog</a></li>
                    <li><a href="/member/about.php" style="color: white;">About Us</a></li>
                    <li><a href="/member/contact.php" style="color: white;">Contact</a></li>
                </ul>
            </div>
        </div>
        <div class="container" style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1);">
            <p>&copy; <?php echo date('Y'); ?> Community Library. All rights reserved.</p>
        </div>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
        // Enhance the author filter with select2-like functionality
        document.addEventListener('DOMContentLoaded', function() {
            const authorSelect = document.getElementById('author');
            
            // Convert the select to a searchable select
            authorSelect.addEventListener('focus', function() {
                this.size = 5;
            });
            
            authorSelect.addEventListener('blur', function() {
                this.size = 1;
            });
            
            authorSelect.addEventListener('change', function() {
                this.size = 1;
                this.blur();
            });
            
            // Add quick search functionality
            const searchInput = document.getElementById('search');
            searchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    this.form.submit();
                }
            });
        });
    </script>
</body>
</html>
<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}

// Handle book deletion
if (isset($_GET['delete']) && isAdmin()) {
    $id = sanitize($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM books WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $_SESSION['message'] = "Book deleted successfully";
    redirect('books.php');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Management - Books</title>
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
        <h1>Books Management</h1>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
        <?php endif; ?>

        <div class="toolbar">
            <a href="add_book.php" class="btn">Add New Book</a>
            <form id="searchForm" class="search-form">
                <input type="text" id="searchInput" placeholder="Search books...">
                <select id="statusFilter">
                    <option value="">All Statuses</option>
                    <option value="Available">Available</option>
                    <option value="On Loan">On Loan</option>
                    <option value="Reserved">Reserved</option>
                </select>
                <button type="submit" class="btn">Search</button>
            </form>
        </div>

        <div id="booksTable">
            <!-- Books will be loaded here via AJAX -->
            Loading books...
        </div>
    </main>

    <script src="../assets/js/main.js"></script>
    <script>
        // Load books when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadBooks();
            
            // Handle search form submission
            document.getElementById('searchForm').addEventListener('submit', function(e) {
                e.preventDefault();
                loadBooks();
            });
        });

        function loadBooks(page = 1) {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            
            fetch(`../api/books.php?action=get_books&page=${page}&search=${encodeURIComponent(search)}&status=${status}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderBooks(data.books, data.total, page);
                    } else {
                        document.getElementById('booksTable').innerHTML = 
                            '<div class="alert alert-danger">Error loading books</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('booksTable').innerHTML = 
                        '<div class="alert alert-danger">Error loading books</div>';
                });
        }

        function renderBooks(books, total, currentPage) {
            let html = `
                <table class="table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Author</th>
                            <th>ISBN</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            books.forEach(book => {
                html += `
                    <tr>
                        <td>${book.title}</td>
                        <td>${book.author}</td>
                        <td>${book.isbn}</td>
                        <td>${book.status}</td>
                        <td>
                            <a href="view_book.php?id=${book.id}" class="btn">View</a>
                            <a href="edit_book.php?id=${book.id}" class="btn">Edit</a>
                            <?php if (isAdmin()): ?>
                                <a href="books.php?delete=${book.id}" class="btn btn-danger" 
                                   onclick="return confirm('Are you sure you want to delete this book?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
            `;

            // Add pagination
            const totalPages = Math.ceil(total / 10);
            if (totalPages > 1) {
                html += '<div class="pagination">';
                if (currentPage > 1) {
                    html += `<button onclick="loadBooks(${currentPage - 1})">Previous</button>`;
                }
                html += ` Page ${currentPage} of ${totalPages} `;
                if (currentPage < totalPages) {
                    html += `<button onclick="loadBooks(${currentPage + 1})">Next</button>`;
                }
                html += '</div>';
            }

            document.getElementById('booksTable').innerHTML = html;
        }
    </script>
</body>
</html>
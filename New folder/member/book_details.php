<?php
require_once 'config.php';

// Check if book ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'Invalid book selection';
    header('Location: catalog.php');
    exit;
}

$book_id = intval($_GET['id']);

// Get book details
$book_stmt = $conn->prepare("
    SELECT b.*, 
           (SELECT COUNT(*) FROM loans WHERE book_id = b.id AND status = 'Active') as active_loans,
           (SELECT COUNT(*) FROM reservations WHERE book_id = b.id AND status = 'Pending') as pending_reserves
    FROM books b 
    WHERE b.id = ?
");
$book_stmt->bind_param("i", $book_id);
$book_stmt->execute();
$book_result = $book_stmt->get_result();

if ($book_result->num_rows === 0) {
    $_SESSION['error'] = 'Book not found';
    header('Location: catalog.php');
    exit;
}

$book = $book_result->fetch_assoc();

// Check if member is logged in
$member_id = $_SESSION['member_id'] ?? null;
$can_borrow = false;
$can_reserve = false;

if ($member_id) {
    // Check if member can borrow this book
    $can_borrow = ($book['status'] === 'Available');
    
    // Check if member can reserve this book
    $can_reserve = ($book['status'] !== 'Available') && ($book['pending_reserves'] < 3);
    
    // Check if member already has this book reserved
    $reserve_check = $conn->prepare("
        SELECT id FROM reservations 
        WHERE book_id = ? AND member_id = ? AND status = 'Pending'
    ");
    $reserve_check->bind_param("ii", $book_id, $member_id);
    $reserve_check->execute();
    $reserve_result = $reserve_check->get_result();
    $can_reserve = $can_reserve && ($reserve_result->num_rows === 0);
    $reserve_result->free(); // Free the result set to avoid 'commands out of sync'
}

// Get similar books (by same author)
$similar_books_stmt = $conn->prepare("
    SELECT id, title, status 
    FROM books 
    WHERE author = ? AND id != ? 
    LIMIT 3
");
$similar_books_stmt->bind_param("si", $book['author'], $book_id);
$similar_books_stmt->execute();
$similar_books = $similar_books_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get book reviews
$reviews_stmt = $conn->prepare("
    SELECT r.*, m.first_name, m.last_name 
    FROM reviews r
    JOIN members m ON r.member_id = m.id
    WHERE r.book_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$reviews_stmt->bind_param("i", $book_id);
$reviews_stmt->execute();
$reviews = $reviews_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && $member_id) {
    $rating = intval($_POST['rating']);
    $comment = sanitize($_POST['comment'] ?? '');
    
    // Validate rating
    if ($rating < 1 || $rating > 5) {
        $review_error = 'Please select a rating between 1 and 5';
    } elseif (strlen($comment) < 10) {
        $review_error = 'Review comment must be at least 10 characters';
    } else {
        // Check if member already reviewed this book
        $existing_review = $conn->prepare("SELECT id FROM reviews WHERE book_id = ? AND member_id = ?");
        $existing_review->bind_param("ii", $book_id, $member_id);
        $existing_review->execute();
        
        if ($existing_review->get_result()->num_rows > 0) {
            $review_error = 'You have already reviewed this book';
        } else {
            // Insert new review
            $insert_review = $conn->prepare("
                INSERT INTO reviews (book_id, member_id, rating, comment)
                VALUES (?, ?, ?, ?)
            ");
            $insert_review->bind_param("iiis", $book_id, $member_id, $rating, $comment);
            
            if ($insert_review->execute()) {
                $_SESSION['success'] = 'Thank you for your review!';
                header("Location: book_details.php?id=$book_id");
                exit;
            } else {
                $review_error = 'Error submitting review. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($book['title']); ?> - Library Catalog</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .book-detail-container {
            max-width: 1000px;
            margin: 2rem auto;
        }
        
        .book-header {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .book-cover {
            flex: 0 0 250px;
            height: 350px;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            color: #777;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .book-info {
            flex: 1;
            min-width: 300px;
        }
        
        .book-title {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }
        
        .book-author {
            font-size: 1.2rem;
            color: #555;
            margin-bottom: 1rem;
        }
        
        .book-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .meta-item {
            background: #f0f7ff;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .book-status {
            font-weight: bold;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 1rem;
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
        
        .book-description {
            margin: 2rem 0;
            line-height: 1.6;
        }
        
        .book-actions {
            display: flex;
            gap: 1rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }
        
        .section-title {
            font-size: 1.5rem;
            margin: 2rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #eee;
            color: #2c3e50;
        }
        
        .similar-books {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .similar-book {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .similar-book:hover {
            transform: translateY(-5px);
        }
        
        .similar-cover {
            height: 150px;
            background: #eee;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #777;
        }
        
        .similar-info {
            padding: 1rem;
        }
        
        .similar-title {
            font-weight: bold;
            margin-bottom: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .similar-status {
            font-size: 0.8rem;
            color: #666;
        }
        
        .reviews-container {
            margin: 2rem 0;
        }
        
        .review {
            background: white;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .review-author {
            font-weight: bold;
        }
        
        .review-rating {
            color: #f39c12;
            font-weight: bold;
        }
        
        .review-date {
            color: #777;
            font-size: 0.9rem;
        }
        
        .review-form {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-top: 2rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }
        
        .rating-stars {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .rating-stars input[type="radio"] {
            display: none;
        }
        
        .rating-stars label {
            font-size: 1.5rem;
            color: #ddd;
            cursor: pointer;
        }
        
        .rating-stars input[type="radio"]:checked ~ label {
            color: #f39c12;
        }
        
        .rating-stars label:hover,
        .rating-stars label:hover ~ label {
            color: #f39c12;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            min-height: 100px;
        }
        
        .error-message {
            color: #e74c3c;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>

    <main class="container">
        <div class="book-detail-container">
            <div class="book-header">
                <div class="book-cover">
                    <?php echo substr($book['title'], 0, 1); ?>
                </div>
                <div class="book-info">
                    <h1 class="book-title"><?php echo htmlspecialchars($book['title']); ?></h1>
                    <div class="book-author">by <?php echo htmlspecialchars($book['author']); ?></div>
                    
                    <div class="book-meta">
                        <span class="meta-item">ISBN: <?php echo htmlspecialchars($book['isbn']); ?></span>
                        <?php if ($book['publisher']): ?>
                            <span class="meta-item">Publisher: <?php echo htmlspecialchars($book['publisher']); ?></span>
                        <?php endif; ?>
                        <?php if ($book['publication_date']): ?>
                            <span class="meta-item">Published: <?php echo date('F Y', strtotime($book['publication_date'])); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="book-status status-<?php echo strtolower(str_replace(' ', '-', $book['status'])); ?>">
                        <?php echo $book['status']; ?>
                        <?php if ($book['status'] === 'On Loan'): ?>
                            (<?php echo $book['active_loans']; ?> active loan<?php echo $book['active_loans'] != 1 ? 's' : ''; ?>)
                        <?php elseif ($book['status'] === 'Reserved'): ?>
                            (<?php echo $book['pending_reserves']; ?> reservation<?php echo $book['pending_reserves'] != 1 ? 's' : ''; ?>)
                        <?php endif; ?>
                    </div>
                    
                    <?php if (isset($_SESSION['member_id'])): ?>
                        <div class="book-actions">
                            <?php if ($can_borrow): ?>
                                <a href="request_loan.php?book_id=<?php echo $book_id; ?>" class="btn">Borrow This Book</a>
                            <?php elseif ($can_reserve): ?>
                                <a href="reserve_book.php?book_id=<?php echo $book_id; ?>" class="btn">Reserve This Book</a>
                            <?php endif; ?>
                            <a href="catalog.php" class="btn">Back to Catalog</a>
                        </div>
                    <?php else: ?>
                        <div class="book-actions">
                            <a href="/member/login.php" class="btn">Login to Borrow</a>
                            <a href="/member/catalog.php" class="btn">Back to Catalog</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($book['description']): ?>
                <div class="book-description">
                    <h2 class="section-title">Description</h2>
                    <p><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($similar_books)): ?>
                <h2 class="section-title">More by <?php echo htmlspecialchars($book['author']); ?></h2>
                <div class="similar-books">
                    <?php foreach ($similar_books as $similar): ?>
                        <a href="/member/book_details.php?id=<?php echo $similar['id']; ?>" class="similar-book">
                            <div class="similar-cover">
                                <?php echo substr($similar['title'], 0, 1); ?>
                            </div>
                            <div class="similar-info">
                                <div class="similar-title"><?php echo htmlspecialchars($similar['title']); ?></div>
                                <div class="similar-status">Status: <?php echo $similar['status']; ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="reviews-container">
                <h2 class="section-title">Reader Reviews</h2>
                
                <?php if (!empty($reviews)): ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review">
                            <div class="review-header">
                                <div class="review-author"><?php echo htmlspecialchars($review['first_name'] . ' ' . htmlspecialchars($review['last_name'])); ?></div>
                                <div class="review-rating"><?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?></div>
                            </div>
                            <div class="review-date"><?php echo date('F j, Y', strtotime($review['created_at'])); ?></div>
                            <p><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No reviews yet. Be the first to review this book!</p>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['member_id'])): ?>
                    <?php
                    // Check if member has borrowed this book before
                    $has_borrowed = false;
                    $borrow_check = $conn->prepare("
                        SELECT id FROM loans 
                        WHERE book_id = ? AND member_id = ? AND status = 'Returned'
                        LIMIT 1
                    ");
                    $borrow_check->bind_param("ii", $book_id, $member_id);
                    $borrow_check->execute();
                    $has_borrowed = ($borrow_check->get_result()->num_rows > 0);
                    ?>
                    
                    <?php if ($has_borrowed): ?>
                        <div class="review-form">
                            <h3>Write a Review</h3>
                            <?php if (isset($review_error)): ?>
                                <div class="error-message"><?php echo $review_error; ?></div>
                            <?php endif; ?>
                            
                            <form method="POST" action="/member/book_details.php?id=<?php echo $book_id; ?>">
                                <div class="form-group">
                                    <label>Your Rating</label>
                                    <div class="rating-stars">
                                        <input type="radio" id="star5" name="rating" value="5">
                                        <label for="star5">★</label>
                                        <input type="radio" id="star4" name="rating" value="4">
                                        <label for="star4">★</label>
                                        <input type="radio" id="star3" name="rating" value="3">
                                        <label for="star3">★</label>
                                        <input type="radio" id="star2" name="rating" value="2">
                                        <label for="star2">★</label>
                                        <input type="radio" id="star1" name="rating" value="1">
                                        <label for="star1">★</label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="comment">Your Review</label>
                                    <textarea id="comment" name="comment" required></textarea>
                                </div>
                                
                                <button type="submit" name="submit_review" class="btn">Submit Review</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <p>You can review this book after you've borrowed and returned it.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p><a href="/member/login.php">Login</a> to write a review (must have borrowed this book before).</p>
                <?php endif; ?>
            </div>
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
        // Star rating interaction
        document.querySelectorAll('.rating-stars input').forEach(star => {
            star.addEventListener('change', function() {
                const stars = this.parentElement.querySelectorAll('label');
                const rating = parseInt(this.value);
                
                stars.forEach((label, index) => {
                    if (index < rating) {
                        label.style.color = '#f39c12';
                    } else {
                        label.style.color = '#ddd';
                    }
                });
            });
        });
    </script>
</body>
</html>
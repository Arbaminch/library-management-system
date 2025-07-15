<?php
require_once 'config.php';

// Get featured books (available books)
$featured_books = $conn->query("
    SELECT * FROM books 
    WHERE status = 'Available'
    ORDER BY created_at DESC
    LIMIT 5
");
if (!$featured_books) {
    die("Error fetching books: " . $conn->error);
}
// Get latest news/announcements
$announcements = $conn->query("
    SELECT * FROM announcements
    ORDER BY created_at DESC
    LIMIT 3
");
if (!$announcements) {
    die("Error fetching announcements: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Our Library</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .hero {
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), url('../assets/images/library-bg.jpg');
            background-size: cover;
            background-position: center;
            color: white;
            text-align: center;
            padding: 5rem 1rem;
            margin-bottom: 2rem;
        }
        
        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .hero p {
            font-size: 1.2rem;
            max-width: 800px;
            margin: 0 auto 2rem;
        }
        
        .section {
            margin: 3rem 0;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 2rem;
            color: #2c3e50;
        }
        
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        
        .book-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .book-card:hover {
            transform: translateY(-5px);
        }
        
        .book-cover {
            height: 250px;
            background: #eee;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #777;
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
        }
        
        .announcements-list {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .announcement {
            background: white;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .announcement-date {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .announcement-title {
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }
        
        .cta-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin: 2rem 0;
        }

        nav ul {
            display: flex;
            flex-direction: row;
            list-style: none;
            padding: 0;
            margin: 0;
            gap: 1.5rem; /* space between links */
        }

        nav ul li {
            display: inline-block;
        }

        nav ul li a {
            text-decoration: none;
            color: inherit;
            padding: 0.5rem 1rem;
            transition: background 0.2s, color 0.2s;
        }

        nav ul li a.active,
        nav ul li a:hover {
            background: #2c3e50;
            color: #fff;
            border-radius: 4px;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
        }
        .logo {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
        }
        nav {
            flex: 1;
            display: flex;
            justify-content: flex-end;
        }
        nav ul {
            display: flex;
            flex-direction: row;
            list-style: none;
            padding: 0;
            margin: 0;
            gap: 1.5rem;
        }
        nav ul li {
            display: inline-block;
        }
        nav ul li a {
            text-decoration: none;
            color: #2c3e50;
            padding: 0.5rem 1.2rem;
            font-weight: 500;
            border-radius: 4px;
            transition: background 0.2s, color 0.2s;
        }
        nav ul li a.active,
        nav ul li a:hover {
            background: #2c3e50;
            color: #fff;
        }
        @media (max-width: 700px) {
            .header-container {
                flex-direction: column;
                align-items: flex-start;
            }
            nav {
                width: 100%;
                justify-content: flex-start;
            }
            nav ul {
                gap: 0.5rem;
            }
            nav ul li a {
                padding: 0.5rem 0.7rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container header-container">
            <div class="logo">Community Library</div>
            <nav>
                <ul>
                    <li><a href="index.php" class="active">Home</a></li>
                    <li><a href="catalog.php">Catalog</a></li>
                    <li><a href="about.php">About</a></li>
                    <li><a href="contact.php">Contact</a></li>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php">Register</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="hero">
        <h1>Welcome to Our Community Library</h1>
        <p>Discover thousands of books, connect with other readers, and expand your knowledge with our vast collection of resources.</p>
        <div class="cta-buttons">
            <a href="catalog.php" class="btn">Browse Catalog</a>
            <a href="register.php" class="btn">Become a Member</a>
        </div>
    </div>

    <main class="container">
        <section class="section">
            <h2 class="section-title">Featured Books</h2>
            <div class="books-grid">
                <?php while ($book = $featured_books->fetch_assoc()): ?>
                    <div class="book-card">
                        <div class="book-cover">
                            <?php echo substr($book['title'], 0, 1); ?>
                        </div>
                        <div class="book-info">
                            <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
                            <div class="book-author">by <?php echo htmlspecialchars($book['author']); ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
            <div style="text-align: center; margin-top: 2rem;">
                <a href="catalog.php" class="btn">View All Books</a>
            </div>
        </section>

        <section class="section">
            <h2 class="section-title">Library News & Announcements</h2>
            <div class="announcements-list">
                <?php if ($announcements->num_rows > 0): ?>
                    <?php while ($announcement = $announcements->fetch_assoc()): ?>
                        <div class="announcement">
                            <div class="announcement-date">
                                <?php echo date('F j, Y', strtotime($announcement['created_at'])); ?>
                            </div>
                            <h3 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                            <p><?php echo htmlspecialchars($announcement['content']); ?></p>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="text-align: center;">No announcements at this time.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="section">
            <h2 class="section-title">Library Hours</h2>
            <div style="max-width: 500px; margin: 0 auto; background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                <table style="width: 100%;">
                    <tr>
                        <td style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">Monday - Thursday</td>
                        <td style="padding: 0.5rem 0; border-bottom: 1px solid #eee; text-align: right;">9:00 AM - 8:00 PM</td>
                    </tr>
                    <tr>
                        <td style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">Friday</td>
                        <td style="padding: 0.5rem 0; border-bottom: 1px solid #eee; text-align: right;">9:00 AM - 6:00 PM</td>
                    </tr>
                    <tr>
                        <td style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">Saturday</td>
                        <td style="padding: 0.5rem 0; border-bottom: 1px solid #eee; text-align: right;">10:00 AM - 5:00 PM</td>
                    </tr>
                    <tr>
                        <td style="padding: 0.5rem 0;">Sunday</td>
                        <td style="padding: 0.5rem 0; text-align: right;">Closed</td>
                    </tr>
                </table>
            </div>
        </section>
    </main>

    <footer style="background: #2c3e50; color: white; padding: 2rem 0; margin-top: 3rem;">
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

    <script src="../assets/js/main.js"></script>
</body>
</html>
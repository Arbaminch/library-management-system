<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_books':
        $page = $_GET['page'] ?? 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        
        $query = "SELECT * FROM books WHERE 1=1";
        $params = [];
        $types = '';
        
        if (!empty($search)) {
            $query .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
            $types .= 'sss';
        }
        
        if (!empty($status)) {
            $query .= " AND status = ?";
            $params[] = $status;
            $types .= 's';
        }
        
        $query .= " LIMIT ? OFFSET ?";
        $params = array_merge($params, [$limit, $offset]);
        $types .= 'ii';
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $books = [];
        while ($row = $result->fetch_assoc()) {
            $books[] = $row;
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM books WHERE 1=1";
        if (!empty($search)) {
            $countQuery .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
        }
        if (!empty($status)) {
            $countQuery .= " AND status = ?";
        }
        
        $stmt = $conn->prepare($countQuery);
        if (!empty($search) || !empty($status)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        
        echo json_encode([
            'success' => true,
            'books' => $books,
            'total' => $total,
            'page' => $page
        ]);
        break;
        
    case 'add_book':
        if (!isAdmin()) {
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $title = sanitize($data['title']);
        $author = sanitize($data['author']);
        $isbn = sanitize($data['isbn']);
        $publisher = sanitize($data['publisher'] ?? '');
        $status = sanitize($data['status'] ?? 'Available');
        
        $stmt = $conn->prepare("INSERT INTO books (title, author, isbn, publisher, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $title, $author, $isbn, $publisher, $status);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['error' => 'Failed to add book']);
        }
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
}
?>
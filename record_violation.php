<?php
session_start();
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// Récupérer les données JSON
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    exit('Invalid data');
}

try {
    // Préparer l'insertion
    $stmt = $pdo->prepare("
        INSERT INTO exam_violations 
        (user_id, exam_id, violation_type, description, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");

    // Insérer la violation
    $stmt->execute([
        $_SESSION['user_id'],
        $_SESSION['current_exam_id'],
        $data['type'],
        json_encode($data['data'])
    ]);

    http_response_code(200);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

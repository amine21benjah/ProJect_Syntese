<?php
session_start();
header('Content-Type: application/json');

$response = [
    'loggedIn' => isset($_SESSION['user_id']),
    'userType' => isset($_SESSION['user_type']) ? $_SESSION['user_type'] : null
];

echo json_encode($response);
?>

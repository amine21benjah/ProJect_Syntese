<?php
require_once 'config.php';
require_once 'includes/quiz_functions.php';

header('Content-Type: application/json');

$quizzes = getQuizzes();
echo json_encode($quizzes);
?>

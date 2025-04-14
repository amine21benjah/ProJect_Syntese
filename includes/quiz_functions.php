<?php
require_once __DIR__ . '/../config.php';

function createQuiz($title, $description, $creator_id, $duration) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO quizzes (title, description, creator_id, duration) 
                              VALUES (?, ?, ?, ?)");
        return $stmt->execute([$title, $description, $creator_id, $duration]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        return false;
    }
}

function updateQuiz($quiz_id, $title, $description, $duration) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("UPDATE quizzes 
                              SET title = ?, description = ?, duration = ? 
                              WHERE id = ?");
        return $stmt->execute([$title, $description, $duration, $quiz_id]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        return false;
    }
}

function deleteQuiz($quiz_id, $user_id) {
    global $pdo;
    
    try {
        // Vérifier si l'utilisateur est un administrateur
        $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user['user_type'] === 'admin') {
            // Si c'est un admin, permettre la suppression sans vérifier le creator_id
            $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = ?");
            return $stmt->execute([$quiz_id]);
        } else {
            // Si ce n'est pas un admin, vérifier le creator_id
            $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = ? AND creator_id = ?");
            return $stmt->execute([$quiz_id, $user_id]);
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        return false;
    }
}

function getQuizzes() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT q.*, u.name as creator_name 
                              FROM quizzes q 
                              LEFT JOIN users u ON q.creator_id = u.id 
                              WHERE q.is_active = 1
                              ORDER BY q.created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        return [];
    }
}

function getQuizById($quiz_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT q.*, u.name as creator_name 
                              FROM quizzes q 
                              LEFT JOIN users u ON q.creator_id = u.id 
                              WHERE q.id = ? AND q.is_active = 1");
        $stmt->execute([$quiz_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        return null;
    }
}

function addQuestionToQuiz($quiz_id, $question_text, $question_type, $points, $order_num) {
    global $pdo;
    try {
        $sql = "INSERT INTO quiz_questions (quiz_id, question_text, question_type, points, order_num) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quiz_id, $question_text, $question_type, $points, $order_num]);
        return $pdo->lastInsertId();
    } catch(PDOException $e) {
        return false;
    }
}

function addQuestionOption($question_id, $option_text, $is_correct) {
    global $pdo;
    try {
        $sql = "INSERT INTO quiz_options (question_id, option_text, is_correct) 
                VALUES (?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$question_id, $option_text, $is_correct]);
        return true;
    } catch(PDOException $e) {
        return false;
    }
}

function startQuizAttempt($quiz_id, $user_id) {
    global $pdo;
    try {
        $sql = "INSERT INTO quiz_attempts (quiz_id, user_id) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quiz_id, $user_id]);
        return $pdo->lastInsertId();
    } catch(PDOException $e) {
        return false;
    }
}

function submitQuizAnswer($attempt_id, $question_id, $answer_text, $selected_option_id) {
    global $pdo;
    
    // Check if the answer is correct
    $is_correct = false;
    $points_earned = 0;
    
    if ($selected_option_id) {
        $sql = "SELECT is_correct FROM quiz_options WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$selected_option_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $is_correct = $result['is_correct'];
        
        if ($is_correct) {
            $sql = "SELECT points FROM quiz_questions WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$question_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $points_earned = $result['points'];
        }
    }
    
    $sql = "INSERT INTO quiz_answers (attempt_id, question_id, answer_text, selected_option_id, is_correct, points_earned) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$attempt_id, $question_id, $answer_text, $selected_option_id, $is_correct, $points_earned]);
    return true;
}

function completeQuizAttempt($attempt_id) {
    global $pdo;
    try {
        $sql = "UPDATE quiz_attempts SET 
                status = 'completed', 
                end_time = CURRENT_TIMESTAMP,
                score = (SELECT SUM(points_earned) FROM quiz_answers WHERE attempt_id = ?)
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$attempt_id, $attempt_id]);
        return true;
    } catch(PDOException $e) {
        return false;
    }
}

function createQuizWithQuestions($title, $description, $creator_id, $duration, $questions, $options, $correct_answers) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Create quiz
        $stmt = $pdo->prepare("INSERT INTO quizzes (title, description, creator_id, duration) VALUES (?, ?, ?, ?)");
        $stmt->execute([$title, $description, $creator_id, $duration]);
        $quiz_id = $pdo->lastInsertId();
        
        // Add questions and options
        foreach ($questions as $index => $question_text) {
            // Add question
            $stmt = $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question_text) VALUES (?, ?)");
            $stmt->execute([$quiz_id, $question_text]);
            $question_id = $pdo->lastInsertId();
            
            // Add options for this question
            if (isset($options[$index])) {
                foreach ($options[$index] as $option_index => $option_text) {
                    $is_correct = isset($correct_answers[$index]) && 
                                in_array($option_index, $correct_answers[$index]) ? 1 : 0;
                    
                    $stmt = $pdo->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct) 
                                         VALUES (?, ?, ?)");
                    $stmt->execute([$question_id, $option_text, $is_correct]);
                }
            }
        }
        
        $pdo->commit();
        return true;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        return false;
    }
}

function getQuizByIdWithQuestions($quiz_id) {
    global $pdo;
    
    try {
        // Get quiz details
        $stmt = $pdo->prepare("SELECT q.*, u.name as creator_name 
                              FROM quizzes q 
                              LEFT JOIN users u ON q.creator_id = u.id 
                              WHERE q.id = ? AND q.is_active = 1");
        $stmt->execute([$quiz_id]);
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$quiz) {
            return null;
        }
        
        // Get questions
        $stmt = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ?");
        $stmt->execute([$quiz_id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get options for each question
        foreach ($questions as &$question) {
            $stmt = $pdo->prepare("SELECT * FROM quiz_options WHERE question_id = ?");
            $stmt->execute([$question['id']]);
            $question['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $quiz['questions'] = $questions;
        return $quiz;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        return null;
    }
}
?>

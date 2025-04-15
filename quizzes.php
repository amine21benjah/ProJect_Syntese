<?php
session_start();
require_once 'config.php';

// Get all active public quizzes
$stmt = $pdo->prepare("SELECT q.*, u.name as creator_name 
                       FROM quizzes q 
                       LEFT JOIN users u ON q.creator_id = u.id 
                       WHERE q.is_active = TRUE
                       ORDER BY q.created_at DESC");
$stmt->execute();
$quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Quizzes - ExamEnLigne</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            padding: 4rem 0;
            color: white;
            margin-bottom: 2rem;
        }
        .quiz-card {
            transition: transform 0.2s;
            height: 100%;
        }
        .quiz-card:hover {
            transform: translateY(-5px);
        }
        .quiz-title {
            color: #2c3e50;
            font-weight: 600;
        }
        .module-name {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .creator-name {
            color: #495057;
            font-size: 0.9rem;
        }
        .duration-badge {
            background-color: #e9ecef;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            color: #495057;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-take-quiz {
            background-color: #4f46e5;
            border-color: #4f46e5;
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 0.5rem;
        }
        .btn-take-quiz:hover {
            background-color: #4338ca;
            border-color: #4338ca;
            color: white;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 mx-auto text-center">
                    <h1 class="display-4 mb-3">Welcome to ExamEnLigne</h1>
                    <p class="lead mb-4">Test your knowledge with our collection of free public quizzes</p>
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <div class="mt-4">
                            <a href="login.php" class="btn btn-light btn-lg me-3">Login</a>
                            <a href="register.php" class="btn btn-outline-light btn-lg">Register</a>
                        </div>
                    <?php else: ?>
                        <div class="mt-4">
                            <a href="create_quiz.php" class="btn btn-light btn-lg">Create New Quiz</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <!-- Available Quizzes -->
        <div class="row g-4">
            <?php if (!empty($quizzes)): ?>
                <?php foreach ($quizzes as $quiz): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card quiz-card shadow-sm">
                            <div class="card-body">
                                <h5 class="quiz-title mb-3"><?php echo htmlspecialchars($quiz['title']); ?></h5>
                                
                                <div class="mb-3">
                                    <div class="creator-name">
                                        <i class="fas fa-user me-2"></i>
                                        <?php echo htmlspecialchars($quiz['creator_name']); ?>
                                    </div>
                                </div>

                                <p class="card-text text-muted mb-3">
                                    <?php 
                                    $description = $quiz['description'] ?? 'Test your knowledge with this quiz!';
                                    echo htmlspecialchars(substr($description, 0, 100)) . (strlen($description) > 100 ? '...' : '');
                                    ?>
                                </p>

                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="duration-badge">
                                        <i class="fas fa-clock"></i>
                                        <?php echo $quiz['duration']; ?> minutes
                                    </span>
                                    <a href="take_quiz.php?id=<?php echo $quiz['id']; ?>" 
                                       class="btn btn-take-quiz">
                                        Start Quiz
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <div class="text-muted">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h4>No Public Quizzes Available</h4>
                        <p>Check back later for new quizzes!</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

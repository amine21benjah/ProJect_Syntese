<?php
session_start();
require_once '../config.php';
require_once '../includes/quiz_functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$message = '';

// Handle quiz actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_quiz'])) {
        $title = $_POST['title'];
        $description = $_POST['description'];
        $duration = $_POST['duration'];
        
        if (createQuiz($title, $description, $_SESSION['user_id'], $duration)) {
            $message = 'Quiz created successfully';
        } else {
            $message = 'Error creating quiz';
        }
    } elseif (isset($_POST['update_quiz'])) {
        $quiz_id = $_POST['quiz_id'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $duration = $_POST['duration'];
        
        if (updateQuiz($quiz_id, $title, $description, $duration)) {
            $message = 'Quiz updated successfully';
        } else {
            $message = 'Error updating quiz';
        }
    } elseif (isset($_POST['delete_quiz'])) {
        $quiz_id = $_POST['quiz_id'];
        if (deleteQuiz($quiz_id, $_SESSION['user_id'])) {
            $message = 'Quiz deleted successfully';
        } else {
            $message = 'Error deleting quiz';
        }
    }
}

// Get all quizzes
$stmt = $pdo->prepare("SELECT q.*, u.name as creator_name 
                       FROM quizzes q 
                       LEFT JOIN users u ON q.creator_id = u.id 
                       ORDER BY q.created_at DESC");
$stmt->execute();
$quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Management - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Quiz Management</h2>
            <a href="../create_quiz.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Create New Quiz
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Created By</th>
                                <th>Duration</th>
                                <th>Created At</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quizzes as $quiz): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($quiz['title']); ?></td>
                                    <td><?php echo htmlspecialchars($quiz['creator_name']); ?></td>
                                    <td><?php echo $quiz['duration']; ?> minutes</td>
                                    <td><?php echo $quiz['created_at']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $quiz['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $quiz['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" onclick="editQuiz(<?php echo htmlspecialchars(json_encode($quiz)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                            <button type="submit" name="delete_quiz" class="btn btn-danger btn-sm" 
                                                    onclick="return confirm('Are you sure you want to delete this quiz?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Quiz Modal -->
    <div class="modal fade" id="editQuizModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Quiz</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="quiz_id" id="edit_quiz_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" id="edit_title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" name="duration" id="edit_duration" class="form-control" required min="1">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_quiz" class="btn btn-primary">Update Quiz</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editQuiz(quiz) {
            document.getElementById('edit_quiz_id').value = quiz.id;
            document.getElementById('edit_title').value = quiz.title;
            document.getElementById('edit_description').value = quiz.description;
            document.getElementById('edit_duration').value = quiz.duration;
            
            new bootstrap.Modal(document.getElementById('editQuizModal')).show();
        }
    </script>
</body>
</html>

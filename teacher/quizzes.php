<?php
session_start();
require_once '../config.php';
require_once '../includes/quiz_functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header('Location: ../login.php');
    exit();
}

$user_type = $_SESSION['user_type'];
$message = '';

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

// Get all quizzes for this teacher
$stmt = $pdo->prepare("SELECT q.*, u.user_type 
                       FROM quizzes q 
                       LEFT JOIN users u ON q.creator_id = u.id
                       WHERE q.creator_id = ?
                       ORDER BY q.created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Management - Teacher</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <h2>Quiz Management</h2>
        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if (in_array($user_type, ['admin', 'teacher', 'student'])): ?>
        <a href="create_quiz.php" class="btn btn-primary mb-3">
            Create New Quiz
        </a>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Duration</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quizzes as $quiz): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($quiz['title']); ?></td>
                            <td><?php echo $quiz['duration']; ?> minutes</td>
                            <td><?php echo $quiz['created_at']; ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn btn-info btn-sm">Edit Questions</a>
                                    <button type="button" class="btn btn-primary btn-sm" 
                                            onclick="editQuizDetails(<?php echo htmlspecialchars(json_encode($quiz)); ?>)"
                                            title="Edit Details">
                                        <i class="fas fa-cog"></i> Details
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                        <button type="submit" name="delete_quiz" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Are you sure you want to delete this quiz?')"
                                                title="Delete Quiz">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create Quiz Modal -->
    <div class="modal fade" id="createQuizModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Quiz</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" name="duration" class="form-control" required min="1">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="create_quiz" class="btn btn-primary">Create Quiz</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Quiz Modal -->
    <div class="modal fade" id="editQuizModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Quiz Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" id="edit_quiz_id" name="quiz_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="edit_title" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="edit_duration" class="form-label">Duration (minutes)</label>
                            <input type="number" class="form-control" id="edit_duration" name="duration" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_quiz" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editQuizDetails(quiz) {
            document.getElementById('edit_quiz_id').value = quiz.id;
            document.getElementById('edit_title').value = quiz.title;
            document.getElementById('edit_description').value = quiz.description || '';
            document.getElementById('edit_duration').value = quiz.duration;
            
            new bootstrap.Modal(document.getElementById('editQuizModal')).show();
        }
    </script>
</body>
</html>

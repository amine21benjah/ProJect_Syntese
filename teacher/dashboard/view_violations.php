<?php
session_start();
require_once '../../config.php';

// Vérifier si l'utilisateur est connecté et est un enseignant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../../login.php');
    exit();
}

// Récupérer l'ID du quiz
if (!isset($_GET['quiz_id'])) {
    header('Location: ../quizzes.php');
    exit();
}

$quiz_id = $_GET['quiz_id'];

// Récupérer les détails du quiz
$stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
$stmt->execute([$quiz_id]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    header('Location: ../quizzes.php');
    exit();
}

// Récupérer toutes les violations pour ce quiz
$stmt = $pdo->prepare("
    SELECT 
        v.*,
        u.name as student_name,
        u.email as student_email
    FROM exam_violations v
    JOIN users u ON v.user_id = u.id
    WHERE v.exam_id = ?
    ORDER BY v.created_at DESC
");
$stmt->execute([$quiz_id]);
$violations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouper les violations par étudiant
$violations_by_student = [];
foreach ($violations as $violation) {
    $student_id = $violation['user_id'];
    if (!isset($violations_by_student[$student_id])) {
        $violations_by_student[$student_id] = [
            'student_name' => $violation['student_name'],
            'student_email' => $violation['student_email'],
            'violations' => []
        ];
    }
    $violations_by_student[$student_id]['violations'][] = $violation;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Violations - <?php echo htmlspecialchars($quiz['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        .violation-card {
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .violation-header {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px 8px 0 0;
        }
        .violation-body {
            padding: 15px;
        }
        .violation-item {
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        .violation-item:last-child {
            border-bottom: none;
        }
        .severity-high {
            color: #dc3545;
        }
        .severity-medium {
            color: #ffc107;
        }
        .severity-low {
            color: #0dcaf0;
        }
    </style>
</head>
<body class="bg-light">
    <?php include '../../includes/teacher_navbar.php'; ?>

    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Exam Violations Report</h1>
            <a href="../quizzes.php" class="btn btn-secondary">Back to Quizzes</a>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title"><?php echo htmlspecialchars($quiz['title']); ?></h5>
                <p class="card-text">
                    <strong>Total Students with Violations:</strong> <?php echo count($violations_by_student); ?><br>
                    <strong>Total Violations:</strong> <?php echo count($violations); ?>
                </p>
            </div>
        </div>

        <!-- Résumé des violations -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-white bg-danger">
                    <div class="card-body">
                        <h5 class="card-title">High Severity</h5>
                        <p class="card-text h2">
                            <?php 
                            echo count(array_filter($violations, function($v) {
                                return strpos(strtolower($v['violation_type']), 'tab switch') !== false ||
                                       strpos(strtolower($v['violation_type']), 'screen capture') !== false;
                            }));
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-dark bg-warning">
                    <div class="card-body">
                        <h5 class="card-title">Medium Severity</h5>
                        <p class="card-text h2">
                            <?php 
                            echo count(array_filter($violations, function($v) {
                                return strpos(strtolower($v['violation_type']), 'copy attempt') !== false;
                            }));
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-dark bg-info">
                    <div class="card-body">
                        <h5 class="card-title">Low Severity</h5>
                        <p class="card-text h2">
                            <?php 
                            echo count(array_filter($violations, function($v) {
                                return strpos(strtolower($v['violation_type']), 'suspicious') !== false;
                            }));
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Liste détaillée des violations par étudiant -->
        <?php foreach ($violations_by_student as $student_id => $data): ?>
            <div class="violation-card">
                <div class="violation-header">
                    <h5 class="mb-0"><?php echo htmlspecialchars($data['student_name']); ?></h5>
                    <small class="text-muted"><?php echo htmlspecialchars($data['student_email']); ?></small>
                    <span class="badge bg-danger float-end">
                        <?php echo count($data['violations']); ?> violations
                    </span>
                </div>
                <div class="violation-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['violations'] as $violation): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime($violation['created_at'])); ?></td>
                                        <td>
                                            <?php 
                                            $type = strtolower($violation['violation_type']);
                                            $severity_class = 'severity-low';
                                            if (strpos($type, 'tab switch') !== false || strpos($type, 'screen capture') !== false) {
                                                $severity_class = 'severity-high';
                                            } elseif (strpos($type, 'copy attempt') !== false) {
                                                $severity_class = 'severity-medium';
                                            }
                                            ?>
                                            <span class="<?php echo $severity_class; ?>">
                                                <?php echo htmlspecialchars($violation['violation_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($violation['description']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
</body>
</html>

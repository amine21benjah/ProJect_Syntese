<?php
if (!isset($_SESSION['user_type'])) {
    header('Location: ../login.php');
    exit();
}

$user_type = $_SESSION['user_type'];
?>

<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
    <div class="container">
        <a class="navbar-brand" href="../<?php echo $user_type; ?>/dashboard.php">ExamEnLigne</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="../<?php echo $user_type; ?>/dashboard.php">Dashboard</a>
                </li>
                <?php if ($user_type === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../admin/teachers.php">Teachers</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../admin/students.php">Students</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../admin/groups.php">Groups</a>
                    </li>
                <?php endif; ?>
                <?php if ($user_type === 'teacher'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../teacher/groups.php">My Groups</a>
                    </li>
                <?php endif; ?>
                <?php if ($user_type === 'student'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../student/groups.php">My Groups</a>
                    </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="../<?php echo $user_type; ?>/quizzes.php">Quizzes</a>
                </li>
            </ul>
            <div class="d-flex align-items-center">
                <span class="me-3">
                    <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>
                </span>
                <a href="../logout.php" class="btn btn-outline-danger">Logout</a>
            </div>
        </div>
    </div>
</nav>

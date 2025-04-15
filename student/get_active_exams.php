<?php
session_start();
require_once '../config.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    exit('Unauthorized');
}

// Get active exams
$stmt = $pdo->prepare("
    SELECT e.*, g.name as group_name,
           CASE 
               WHEN NOW() < e.start_datetime THEN 'not_started'
               WHEN NOW() BETWEEN e.start_datetime AND DATE_ADD(e.start_datetime, INTERVAL e.duration MINUTE) THEN 'in_progress'
               ELSE 'ended'
           END as exam_status
    FROM exams e
    JOIN study_groups g ON e.group_id = g.id
    JOIN group_students sg ON g.id = sg.group_id
    JOIN users u ON sg.student_id = u.id
    WHERE sg.student_id = ? 
    AND sg.status = 'approved'
    AND u.created_at <= e.start_datetime
    AND NOW() BETWEEN e.start_datetime AND DATE_ADD(e.start_datetime, INTERVAL e.duration MINUTE)
    ORDER BY e.start_datetime DESC
");
$stmt->execute([$_SESSION['user_id']]);
$active_exams = $stmt->fetchAll();

// Check submitted exams
$submitted_exams = [];
foreach ($active_exams as $exam) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM student_answers WHERE student_id = ? AND exam_id = ?");
    $stmt->execute([$_SESSION['user_id'], $exam['id']]);
    if ($stmt->fetch()['count'] > 0) {
        $submitted_exams[$exam['id']] = true;
    }
}

// Only output content if there are active exams
if (!empty($active_exams)) {
?>
    <div id="active-exams-section" class="bg-white shadow rounded-lg mb-6 p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">Examens en cours</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Titre</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupe</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Temps restant</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($active_exams as $exam): ?>
                        <?php
                        $end_time = strtotime($exam['start_datetime']) + ($exam['duration'] * 60);
                        $remaining_minutes = ceil(($end_time - time()) / 60);
                        ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['title']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($exam['group_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="remaining-time" 
                                      data-end-time="<?php echo $end_time; ?>"
                                      data-exam-id="<?php echo $exam['id']; ?>">
                                    <?php echo $remaining_minutes; ?> minutes
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if (isset($submitted_exams[$exam['id']])): ?>
                                    <span class="text-green-600">Soumis</span>
                                <?php else: ?>
                                    <a href="take_exam.php?exam_id=<?php echo $exam['id']; ?>" 
                                       class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                                        Commencer l'examen
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
}
?> 
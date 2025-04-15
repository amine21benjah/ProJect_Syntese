<?php
require_once 'config.php';

try {
    // First check if columns exist
    $stmt = $pdo->query("DESCRIBE study_groups");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Remove teacher_id column if it exists (since we're using group_teachers table)
    if (in_array('teacher_id', $columns)) {
        $pdo->exec("ALTER TABLE study_groups DROP COLUMN teacher_id");
    }
    
    // Add timestamp columns if they don't exist
    if (!in_array('created_at', $columns)) {
        $pdo->exec("ALTER TABLE study_groups ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
    
    if (!in_array('updated_at', $columns)) {
        $pdo->exec("ALTER TABLE study_groups ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    
    echo "Database structure updated successfully!";
} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage();
} 
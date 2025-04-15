<?php
require_once 'config.php';

try {
    // Add timestamp columns to study_groups if they don't exist
    $pdo->exec("
        ALTER TABLE study_groups 
        ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ");
    
    echo "Database updated successfully!";
} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage();
} 
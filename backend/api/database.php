<?php // backend/database.php

function getDbConnection() {
    $dbPath = __DIR__ . '/cargo_database.sqlite'; // Path relative to this file (database.php)
    try {
        // Make sure the path is correct relative to THIS file's location
        $resolvedDbPath = realpath($dbPath); 
        if ($resolvedDbPath === false) {
             error_log("FATAL: Database file not found at calculated path: " . $dbPath);
             return null;
        }
        
        $db = new PDO('sqlite:' . $resolvedDbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON;');
        error_log("Database connection successful via getDbConnection() to: " . $resolvedDbPath); // Add log
        return $db;
    } catch (PDOException $e) {
        error_log("FATAL: Database connection error in getDbConnection(): " . $e->getMessage());
        return null; 
    }
}
?>
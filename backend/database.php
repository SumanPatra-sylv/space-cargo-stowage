<?php // backend/database.php

/* // START COMMENTED OUT - CORS handled by individual endpoint scripts
// --- CORS HEADERS ---
// Allow requests from your frontend development server origin
header("Access-Control-Allow-Origin: http://localhost:5173"); // Adjust port if your frontend runs elsewhere
// Allow common methods
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
// Allow specific headers often sent by JavaScript requests
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, Cache-Control");
// Allow credentials if needed (e.g., cookies, Authorization header with credentials) - set to true if necessary
// header("Access-Control-Allow-Credentials: true");

// --- Handle potential OPTIONS preflight requests ---
// Browsers send an OPTIONS request first for "non-simple" requests (like POST with JSON)
// to check if the actual request is allowed.
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // Respond with allowed methods/headers and exit early.
    // No actual processing needed for OPTIONS.
    http_response_code(204); // 204 No Content is typical
    exit();
}
// --- END CORS HEADERS ---
*/ // END COMMENTED OUT


// --- DATABASE CONNECTION FUNCTION ---

/**
 * Establishes a connection to the SQLite database.
 *
 * @return PDO|null Returns a PDO database connection object on success, or null on failure.
 */
function getDbConnection() {
    // Assuming database.php is in the 'backend' directory, and cargo_database.sqlite is also there.
    $dbPath = __DIR__ . '/cargo_database.sqlite'; // Path relative to this file

    try {
        // Check if the file exists and is readable *before* trying to connect.
        if (!file_exists($dbPath) || !is_readable($dbPath)) {
            error_log("FATAL: Database file not found or not readable at path: " . $dbPath . " (from __DIR__: " . __DIR__ . ")");
            return null; // Return null if file not found/readable
        }

        $db = new PDO('sqlite:' . $dbPath);

        // Set attributes for error handling and fetch mode
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Throw exceptions on error
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // Fetch associative arrays

        // Enable foreign key constraints for SQLite (important for data integrity)
        $db->exec('PRAGMA foreign_keys = ON;');

        return $db; // Return the database connection object

    } catch (PDOException $e) {
        // Log any connection errors
        error_log("FATAL: Database connection error in getDbConnection(): " . $e->getMessage());
        return null; // Return null on failure
    }
}

// End of database.php - closing ?>
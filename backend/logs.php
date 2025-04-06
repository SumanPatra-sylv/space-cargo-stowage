<?php // backend/logs.php (Modified for central index.php handling)

/* --- REMOVED/COMMENTED OUT - Handled by index.php ---
// ini_set('display_errors', 1); // Handled by index.php
// error_reporting(E_ALL);      // Handled by index.php
// Set header LATER only if success, or text/plain on error
// header('Content-Type: application/json'); // REMOVED - Handled by index.php
// header('Access-Control-Allow-Origin: *');   // REMOVED - Handled by index.php
// GET request
--- END REMOVED/COMMENTED OUT --- */

// --- Database Connection ---
// Corrected path assuming database.php is in the same directory (backend/) as logs.php
require_once __DIR__ . '/database.php'; // Defines getDbConnection()

error_log("Logs API: Attempting DB connection...");
$db = getDbConnection();

if ($db === null) {
    error_log("Logs API: Failed DB connection.");
    http_response_code(500); // Service Unavailable
    // header('Content-Type: application/json'); // REMOVED - Handled by index.php
    // index.php already set Content-Type
    echo json_encode(['success' => false, 'logs' => [], 'message' => 'Database connection failed.']);
    exit; // Exit here on critical DB connection failure
}
error_log("Logs API: DB connection successful.");
// --- End Database Connection ---

// --- Response Structure ---
$response = [
    'success' => false,
    'logs' => [],
    'message' => ''
];

// --- Handle GET Request & Query Parameters ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    error_log("Logs API: Received method " . $_SERVER['REQUEST_METHOD']);
    // header('Content-Type: application/json'); // REMOVED - Handled by index.php
    // index.php already set Content-Type
    echo json_encode($response);
    $db = null; // Close connection before exiting
    exit; // Exit on wrong method
}

// Get optional query parameters
$startDate = $_GET['startDate'] ?? null;
$endDate = $_GET['endDate'] ?? null;
$itemId = $_GET['itemId'] ?? null;
$userId = $_GET['userId'] ?? null;
$actionType = $_GET['actionType'] ?? null;

error_log("Logs API: Raw Params - Start: $startDate, End: $endDate, Item: $itemId, User: $userId, Action: $actionType");

// --- ADD COMPREHENSIVE DEBUGGING ---
// This debugging block remains useful for development
ob_start(); // Start output buffering to capture var_dumps
echo "\n--- PARAMETER DEBUG ---\n";
echo "REQUEST method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "GET params: "; var_dump($_GET);
// echo "POST params: "; var_dump($_POST); // Not relevant for GET
echo "Extracted values:\n";
echo "startDate: "; var_dump($startDate);
echo "endDate: "; var_dump($endDate);
echo "itemId: "; var_dump($itemId);
echo "userId: "; var_dump($userId);
echo "actionType: "; var_dump($actionType);
echo "--- END PARAMETER DEBUG ---\n\n";
$paramDebugOutput = ob_get_clean(); // Get buffered output
error_log($paramDebugOutput); // Send var_dumps to error log instead of mixing with JSON
// --- END COMPREHENSIVE DEBUGGING ---

// --- Core Logic: Build Query and Fetch Logs ---
try {
    $sql = "SELECT logId, timestamp, userId, actionType, itemId, detailsJson FROM logs WHERE 1=1";
    $params = [];

    // Append conditions using !empty() checks
    if (!empty($startDate)) {
        $sql .= " AND timestamp >= :startDate";
        // Attempt to format date, fallback to original string if invalid
        try {
             $startDateTime = new DateTime($startDate);
             // If only date is provided, assume start of day
             if (strpos($startDate, ' ') === false) {
                 $params[':startDate'] = $startDateTime->format('Y-m-d 00:00:00');
             } else {
                 $params[':startDate'] = $startDateTime->format('Y-m-d H:i:s');
             }
         } catch (Exception $e) {
             error_log("Logs API: Invalid start date format '$startDate'. Using as is.");
             $params[':startDate'] = $startDate; // Use as is, DB might handle it or fail
         }
    }
    if (!empty($endDate)) {
        $sql .= " AND timestamp <= :endDate";
         // Attempt to format date, fallback to original string if invalid
         try {
             $endDateTime = new DateTime($endDate);
             // If only date is provided, assume end of day
             if (strpos($endDate, ' ') === false) {
                 $params[':endDate'] = $endDateTime->format('Y-m-d 23:59:59');
             } else {
                 $params[':endDate'] = $endDateTime->format('Y-m-d H:i:s');
             }
         } catch (Exception $e) {
             error_log("Logs API: Invalid end date format '$endDate'. Using as is.");
             $params[':endDate'] = $endDate; // Use as is
         }
    }
    if (!empty($itemId)) { // Check !empty
        $sql .= " AND itemId = :itemId";
        $params[':itemId'] = $itemId;
    }
     if (!empty($userId)) { // Check !empty
        $sql .= " AND userId = :userId";
        $params[':userId'] = $userId;
    }
     if (!empty($actionType)) { // Check !empty
        $sql .= " AND actionType = :actionType";
        $params[':actionType'] = $actionType;
    }

    $sql .= " ORDER BY timestamp DESC";

    // --- ADD QUERY DEBUGGING ---
    error_log("--- QUERY DEBUG ---");
    error_log("SQL Query String: " . $sql);
    error_log("Parameters Array: " . json_encode($params)); // Log params as JSON
    error_log("--- END QUERY DEBUG ---");
    // --- END QUERY DEBUGGING ---


    $stmt = $db->prepare($sql);

    // Execute (passing params array works for named placeholders)
    $stmt->execute($params);

    $logsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Logs API: Found " . count($logsResult) . " log entries matching criteria.");

    $formattedLogs = [];
    foreach ($logsResult as $log) {
        $details = json_decode($log['detailsJson'], true);
        // Handle potential JSON decoding errors gracefully
        if ($details === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log("Logs API: Failed to decode detailsJson for logId " . $log['logId'] . ". Error: " . json_last_error_msg() . ". JSON: " . $log['detailsJson']);
            $log['details'] = ['error' => 'Failed to decode details', 'originalJson' => $log['detailsJson']]; // Provide error info instead of null
        } else {
             $log['details'] = $details;
        }
        unset($log['detailsJson']); // Remove original JSON string field
        $formattedLogs[] = $log;
    }

    $response['success'] = true;
    $response['logs'] = $formattedLogs;
    $response['message'] = "Retrieved " . count($formattedLogs) . " log entries.";
    http_response_code(200); // OK

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    $response['success'] = false;
    $response['logs'] = []; // Ensure logs array is empty on error
    $response['message'] = 'Database query failed: ' . $e->getMessage(); // Provide error in message
    error_log("Logs API: PDOException - " . $e->getMessage());
    // header('Content-Type: application/json'); // REMOVED - Handled by index.php
    echo json_encode($response); // Output the error response
    $db = null; exit; // Exit after error

} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    $response['success'] = false;
    $response['logs'] = [];
    $response['message'] = 'An unexpected error occurred: ' . $e->getMessage();
    error_log("Logs API: Exception - " . $e->getMessage());
    // header('Content-Type: application/json'); // REMOVED - Handled by index.php
    echo json_encode($response); // Output the error response
    $db = null; exit; // Exit after error

} finally {
     // Ensure connection is closed even if exceptions occurred
    $db = null;
    error_log("Logs API: DB connection closed in finally block.");
}
// --- End Core Logic ---

// --- Final Output ---
// header('Content-Type: application/json'); // REMOVED - Handled by index.php
// index.php handles headers and script exit
echo json_encode($response, JSON_PRETTY_PRINT); // Send the success response
error_log("Logs API: Finished successfully.");
?>
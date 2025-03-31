<?php // backend/api/logs.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

// Set header LATER only if success, or text/plain on error
// header('Content-Type: application/json'); // Set later
header('Access-Control-Allow-Origin: *'); 
// GET request

// --- Database Connection ---
require_once __DIR__ . '/../database.php'; 
error_log("Logs API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) { 
    error_log("Logs API: Failed DB connection.");
    http_response_code(500); 
    header('Content-Type: application/json'); // Set header before output
    echo json_encode(['success' => false, 'logs' => [], 'message' => 'Database connection failed.']);
    exit; 
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
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    error_log("Logs API: Received method " . $_SERVER['REQUEST_METHOD']);
     header('Content-Type: application/json');
    echo json_encode($response);
    $db = null; exit; 
}

// Get optional query parameters
$startDate = $_GET['startDate'] ?? null; 
$endDate = $_GET['endDate'] ?? null;     
$itemId = $_GET['itemId'] ?? null;
$userId = $_GET['userId'] ?? null;
$actionType = $_GET['actionType'] ?? null; 

error_log("Logs API: Raw Params - Start: $startDate, End: $endDate, Item: $itemId, User: $userId, Action: $actionType");

// --- ADD COMPREHENSIVE DEBUGGING ---
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
        try { $params[':startDate'] = (new DateTime($startDate))->format('Y-m-d H:i:s'); } 
        catch (Exception $e) { $params[':startDate'] = $startDate; }
    }
    if (!empty($endDate)) {
        $sql .= " AND timestamp <= :endDate"; 
         try { $params[':endDate'] = (new DateTime($endDate))->format('Y-m-d 23:59:59'); } 
         catch (Exception $e) { $params[':endDate'] = $endDate; }
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
        $log['details'] = ($details === null && json_last_error() !== JSON_ERROR_NONE) ? null : $details; 
        unset($log['detailsJson']); 
        $formattedLogs[] = $log;
    }

    $response['success'] = true;
    $response['logs'] = $formattedLogs;
    $response['message'] = "Retrieved " . count($formattedLogs) . " log entries.";
    http_response_code(200);

} catch (PDOException $e) { /* ... existing catch ... */ } 
  catch (Exception $e) { /* ... existing catch ... */ } 
  finally { $db = null; }
// --- End Core Logic ---

// --- Final Output ---
header('Content-Type: application/json'); // Set JSON header *before* output
echo json_encode($response, JSON_PRETTY_PRINT); 
error_log("Logs API: Finished.");
?>
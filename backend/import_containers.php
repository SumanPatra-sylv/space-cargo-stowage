<?php // backend/import_containers.php (Initialize errors array)

// --- Initial Setup ---
$response = [
    'success' => false,
    'containersImported' => 0,
    'skippedCount' => 0,
    'errors' => [], // <<< INITIALIZE ERRORS ARRAY HERE
    'message' => ''
];
$db = null; $handle = null; $stmt = null;
// REMOVE $logData initialization

// --- Database Connection ---
require_once __DIR__ . '/database.php';
try { $db = getDbConnection(); if ($db === null) { throw new Exception("DB Connection Failed", 503); } }
catch (Exception $e) { /* ... handle DB connection error & exit ... */ }

// --- Request Validation ---
// ... (keep existing request validation) ...
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /*...*/ exit(); } if (!isset($_FILES['file']) /*...*/) { /*...*/ exit(); }
$fileTmpPath = $_FILES['file']['tmp_name']; $fileName = basename($_FILES['file']['name']); // Keep filename
// ... rest of file validation ...
if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv') { /* ... handle file type error & exit ... */ }

// --- File Handling & Processing ---
$fileToOpen = $fileTmpPath; error_log("Processing container import: " . $fileName);
if (!file_exists($fileToOpen) /*...*/) { /*...*/ exit(); }

// --- CSV Parsing and Database Insertion ---
$importedCount = 0; $skippedCount = 0; $rowNumber = 0; $expectedColumns = 5; $containerId = null; $zone = null; $width = null; $depth = null; $height = null; $now = null;
$sql = "INSERT INTO containers (containerId, zone, width, depth, height, createdAt) VALUES (:containerId, :zone, :width, :depth, :height, :createdAt)";
// $processingErrorOccurred = false; // No longer strictly needed

if (!$db->inTransaction()) { $db->beginTransaction(); }

try {
    $handle = fopen($fileToOpen, "r"); if ($handle === FALSE) { throw new Exception("Could not open CSV file."); }
    $headerRow = fgetcsv($handle, 1000, ",", '"', "\\"); $rowNumber = 1; if ($headerRow === FALSE || count($headerRow) !== $expectedColumns) { throw new Exception("Invalid CSV header."); }
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare($sql); // Prepare once
    // Bind params before loop
    $stmt->bindParam(':containerId', $containerId, PDO::PARAM_STR); $stmt->bindParam(':zone', $zone, PDO::PARAM_STR); $stmt->bindParam(':width', $width); $stmt->bindParam(':depth', $depth); $stmt->bindParam(':height', $height); $stmt->bindParam(':createdAt', $now, PDO::PARAM_STR);

    while (($data = fgetcsv($handle, 1000, ",", '"', "\\")) !== FALSE) {
        if ($data === NULL || (count($data) === 1 && trim($data[0]) === '')) { continue; } $rowNumber++;
        if (count($data) !== $expectedColumns) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Incorrect count.']; $skippedCount++; continue; }
        // Assign to bound variables
        $containerId = trim($data[0]); $zone = trim($data[1]); $width = trim($data[2]); $depth = trim($data[3]); $height = trim($data[4]);

        $rowError = false; // --- Keep validation logic ---
        if (empty($containerId)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'ID required.']; $rowError = true; } if (empty($zone)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Zone required.']; $rowError = true; } if (!is_numeric($width) || $width <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => "Invalid Width '$width'."]; $rowError = true; } if (!is_numeric($depth) || $depth <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => "Invalid Depth '$depth'."]; $rowError = true; } if (!is_numeric($height) || $height <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => "Invalid Height '$height'."]; $rowError = true; }
        if ($rowError) { $skippedCount++; continue; }

        // Execute using bound variables
        $stmt->execute(); $importedCount++;
    } // End while

    fclose($handle); $handle = null;
    if ($db->inTransaction()) { $db->commit(); $response['success'] = true; error_log("Committed."); }

} catch (PDOException $pdoe) {
    // --- Keep existing PDO catch block (sets response['success']=false, adds to response['errors']) ---
     if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Rolled back (PDOException)."); } http_response_code(400); $response['success'] = false; $importedCount = 0; $errorCode = $pdoe->errorInfo[1] ?? $pdoe->getCode(); $errorMessage = $pdoe->getMessage(); if ($errorCode === 19 || stripos($errorMessage, 'UNIQUE constraint') !== false) { $response['message'] = "Import failed: Duplicate ID (Row $rowNumber). Rolled back."; $response['errors'][] = ['row' => $rowNumber, 'message' => "Duplicate ID: " . ($containerId ?? 'N/A')]; } else { $response['message'] = "DB error (Row $rowNumber). Rolled back."; $response['errors'][] = ['row' => $rowNumber, 'message' => "DB Error [$errorCode]: " . $errorMessage]; } error_log("PDOException (Row $rowNumber): " . $errorMessage);
} catch (Exception $e) {
    // --- Keep existing Exception catch block (sets response['success']=false) ---
     if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Rolled back (Exception)."); } $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500; http_response_code($statusCode); $response['success'] = false; $importedCount = 0; $response['message'] = 'Error processing file: ' . $e->getMessage(); error_log("Exception: " . $e->getMessage());
} finally {
    if (isset($handle) && $handle && is_resource($handle)) { fclose($handle); }
    $stmt = null;
}


// --- Finalize Response ---
$response['containersImported'] = $importedCount;
$response['skippedCount'] = $skippedCount;
if ($response['success']) { /* Set success message */ }
else { /* Set/ensure error message and status code */ }


// --- Perform Logging AFTER response is fully finalized ---
try {
    if ($db) {
        $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, :actionType, :details, :timestamp)";
        $logStmt = $db->prepare($logSql);
        // Build final details for logging
        $logDetails = [
            'importType' => 'containers',
            'fileName' => $fileName ?? 'N/A',
            'success' => $response['success'], // Use final success status
            'importedCount' => $response['containersImported'],
            'skippedCount' => $response['skippedCount'],
            'errors' => $response['errors'], // Use final errors array (now guaranteed to exist)
            'finalMessage' => $response['message']
        ];
        $logParams = [
            ':userId' => 'System_Import', ':actionType' => 'import_containers',
            ':details' => json_encode($logDetails), ':timestamp' => date('Y-m-d H:i:s')
        ];
        $logStmt->execute($logParams); error_log("Container import action logged correctly.");
    }
} catch (Exception $logEx) { error_log("CRITICAL: Failed to log container import action! Error: " . $logEx->getMessage()); }
// --- END LOGGING ---


// --- Send Output ---
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
echo json_encode($response);
$db = null; exit();

?>
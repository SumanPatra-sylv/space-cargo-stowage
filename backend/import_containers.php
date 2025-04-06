<?php // backend/import_containers.php (Modified with execute check & detailed logging)

$response = [
    'success' => false,
    'containersImported' => 0,
    'skippedCount' => 0,
    'errors' => [],
    'message' => ''
];
$db = null; $handle = null; $stmt = null;
$fileName = 'N/A'; // Initialize filename

// --- Database Connection ---
require_once __DIR__ . '/database.php';
try {
    $db = getDbConnection();
    if ($db === null) {
        throw new Exception("DB Connection Failed", 503);
    }
    error_log("Container Import: DB connection successful."); // Log connection success
}
catch (Exception $e) {
    // Simplified error handling for connection
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 503;
    http_response_code($statusCode);
    $response['message'] = "Error connecting to database: " . $e->getMessage();
    $response['errors'][] = ['row' => 0, 'message' => $response['message']];
    error_log("Container Import DB Connection Exception: " . $e->getMessage());
    echo json_encode($response);
    exit;
}

// --- Request Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     http_response_code(405); $response['message'] = 'Method Not Allowed.'; echo json_encode($response); exit;
}
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); $response['message'] = 'File upload error: ' . ($_FILES['file']['error'] ?? 'Unknown error'); echo json_encode($response); exit;
}
$fileTmpPath = $_FILES['file']['tmp_name'];
$fileName = basename($_FILES['file']['name'] ?? 'uploaded_file.csv'); // Use actual name for logging

if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv') {
    http_response_code(400); $response['message'] = 'Invalid file type. Only CSV allowed.'; echo json_encode($response); exit;
}
if (!file_exists($fileTmpPath) || !is_readable($fileTmpPath)) {
    http_response_code(500); $response['message'] = 'Server error: Cannot read uploaded file.'; error_log("Cannot read uploaded file: $fileTmpPath"); echo json_encode($response); exit;
}

// --- File Handling & Processing ---
error_log("Processing container import: " . $fileName);
$importedCount = 0; $skippedCount = 0; $rowNumber = 0; $expectedColumns = 5; // Assuming containerId,zone,width,depth,height
$now = date('Y-m-d H:i:s');
$sql = "INSERT INTO containers (containerId, zone, width, depth, height, createdAt) VALUES (:containerId, :zone, :width, :depth, :height, :createdAt)";

// Variables to be bound - Initialize outside loop
$containerId = null; $zone = null; $width = null; $depth = null; $height = null;

if (!$db->inTransaction()) { $db->beginTransaction(); error_log("Container Import: Transaction started."); }

try {
    $handle = fopen($fileTmpPath, "r");
    if ($handle === FALSE) { throw new Exception("Could not open CSV file '$fileName'."); }

    // Read and validate header
    $headerRow = fgetcsv($handle);
    $rowNumber = 1; // Start counting rows from header
    if ($headerRow === FALSE || count($headerRow) < $expectedColumns) { // Use < to allow extra cols if needed, == for strict
        throw new Exception("Invalid CSV header in '$fileName'. Expected $expectedColumns columns, found " . count($headerRow));
    }
    // You could add specific header name checks here if needed

    $stmt = $db->prepare($sql); // Prepare statement once outside loop

    // Bind parameters before the loop
    $stmt->bindParam(':containerId', $containerId, PDO::PARAM_STR);
    $stmt->bindParam(':zone', $zone, PDO::PARAM_STR);
    // For numeric types, PDO often handles strings okay, but can use PDO::PARAM_INT or cast later
    $stmt->bindParam(':width', $width);
    $stmt->bindParam(':depth', $depth);
    $stmt->bindParam(':height', $height);
    $stmt->bindParam(':createdAt', $now, PDO::PARAM_STR);

    // Process data rows
    while (($data = fgetcsv($handle)) !== FALSE) {
        $rowNumber++; // Increment for data rows (row 2 in file is first data row)

        // Skip empty lines silently
        if ($data === NULL || (count($data) === 1 && trim($data[0]) === '')) { continue; }

        // Check column count for this row
        if (count($data) !== $expectedColumns) {
            $response['errors'][] = ['row' => $rowNumber, 'message' => "Incorrect column count. Expected $expectedColumns, found " . count($data)];
            $skippedCount++;
            error_log("Container Import [Row $rowNumber]: Skipped due to column count mismatch.");
            continue; // Skip this row
        }

        // Assign to bound variables (trim data)
        $containerId = trim($data[0]);
        $zone = trim($data[1]);
        $width = trim($data[2]);
        $depth = trim($data[3]);
        $height = trim($data[4]);

        // --- ADDED: Detailed Row Logging ---
        error_log("Container Import [Row $rowNumber]: Processing ID=$containerId, Zone=$zone, W=$width, D=$depth, H=$height");

        // Validate row data
        $rowError = false;
        $validationErrors = []; // Collect specific validation errors for the row
        if (empty($containerId)) { $validationErrors[] = 'Container ID is required.'; $rowError = true; }
        if (empty($zone)) { $validationErrors[] = 'Zone is required.'; $rowError = true; }
        if (!is_numeric($width) || $width <= 0) { $validationErrors[] = "Invalid Width '$width'. Must be positive number."; $rowError = true; }
        if (!is_numeric($depth) || $depth <= 0) { $validationErrors[] = "Invalid Depth '$depth'. Must be positive number."; $rowError = true; }
        if (!is_numeric($height) || $height <= 0) { $validationErrors[] = "Invalid Height '$height'. Must be positive number."; $rowError = true; }

        // If validation errors occurred for this row
        if ($rowError) {
            $combinedErrorMessage = implode(' ', $validationErrors);
            $response['errors'][] = ['row' => $rowNumber, 'message' => $combinedErrorMessage];
            $skippedCount++;
            error_log("Container Import [Row $rowNumber]: Skipped due to validation errors: $combinedErrorMessage");
            continue; // Skip this row
        }

        // Attempt to execute the prepared statement with the bound variables
        // --- ADDED: Explicit Execute Check ---
        if ($stmt->execute()) {
            $importedCount++;
             error_log("Container Import [Row $rowNumber]: Successfully executed INSERT for ID=$containerId.");
        } else {
            // Execution failed, likely a DB constraint (e.g., UNIQUE) or other PDO error
            $errorInfo = $stmt->errorInfo();
            $errorMessage = "DB Execute Failed: " . ($errorInfo[2] ?? 'Unknown Error');
            $response['errors'][] = ['row' => $rowNumber, 'message' => $errorMessage];
            $skippedCount++;
            error_log("Container Import [Row $rowNumber]: FAILED DB execute for ID=$containerId. Error: $errorMessage");
            // Since we are in a transaction, we MUST throw an exception to trigger rollback
            // Otherwise, subsequent rows might succeed, leading to partial import on error which is bad.
            throw new PDOException("Database execution failed for row $rowNumber: $errorMessage", $errorInfo[1] ?? 0);
        }
        // --- END Explicit Execute Check ---

    } // End while loop

    fclose($handle);
    $handle = null; // Ensure handle is null after closing

    // If loop completed without exceptions, commit the transaction
    if ($db->inTransaction()) {
        $db->commit();
        $response['success'] = true;
        error_log("Container Import: Transaction committed successfully. Imported: $importedCount, Skipped: $skippedCount");
    } else {
         // Should not happen if beginTransaction was called, indicates logic error
         error_log("Container Import Error: Attempted to finish but not in transaction.");
          throw new Exception("Transaction state error.");
    }

} catch (PDOException $pdoe) {
    // Handle database-specific errors (like UNIQUE constraint from execute check)
    if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Container Import: Rolled back transaction due to PDOException."); }
    http_response_code(400); // Often Bad Request due to duplicate data etc.
    $response['success'] = false;
    $importedCount = 0; // Reset count as transaction failed
    $errorCode = $pdoe->errorInfo[1] ?? $pdoe->getCode();
    $errorMessage = $pdoe->getMessage();

    // Customize message for UNIQUE constraint violation
    if ($errorCode === 19 || stripos($errorMessage, 'UNIQUE constraint') !== false) {
        $failedId = $containerId ?? 'N/A'; // $containerId holds the value from the failing row
        $response['message'] = "Import failed: Duplicate Container ID encountered near row $rowNumber (ID: $failedId). Rolled back.";
        $response['errors'][] = ['row' => $rowNumber, 'message' => "Duplicate ID constraint violated for ID: " . $failedId];
        error_log("Container Import PDOException (Row $rowNumber) - Duplicate ID: $failedId. " . $errorMessage);
    } else {
        $response['message'] = "Database error occurred near row $rowNumber. Import failed and rolled back.";
        $response['errors'][] = ['row' => $rowNumber, 'message' => "DB Error [$errorCode]: " . $errorMessage];
        error_log("Container Import PDOException (Row $rowNumber): [$errorCode] " . $errorMessage);
    }

} catch (Exception $e) {
    // Handle general errors (file open, CSV format, etc.)
    if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Container Import: Rolled back transaction due to Exception."); }
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500; // Use exception code if valid HTTP error
    http_response_code($statusCode);
    $response['success'] = false;
    $importedCount = 0; // Reset count
    $response['message'] = 'Error processing file: ' . $e->getMessage();
     // Add error details if not already present from specific row validation
     if (empty(array_filter($response['errors'], fn($err) => $err['row'] === $rowNumber))) {
        $response['errors'][] = ['row' => $rowNumber, 'message' => $e->getMessage()];
     }
    error_log("Container Import Exception (Row $rowNumber): " . $e->getMessage());

} finally {
    // Ensure file handle is closed if it was opened
    if (isset($handle) && $handle && is_resource($handle)) {
        fclose($handle);
        error_log("Container Import: File handle closed in finally block.");
    }
    // PDO statement object is automatically cleaned up, but setting to null is okay
    $stmt = null;
}


// --- Finalize Response ---
$response['containersImported'] = $importedCount;
$response['skippedCount'] = $skippedCount;
if ($response['success']) {
    $response['message'] = "Container import finished. Imported: $importedCount, Skipped: $skippedCount.";
    if(count($response['errors']) > 0) {
        $response['message'] .= " Some rows had errors."; // Append if there were skipped rows with errors
    }
}
// Ensure error message is set if success is false
else if (empty($response['message'])) {
    $response['message'] = "Container import failed. Imported: $importedCount, Skipped: $skippedCount. Check errors for details.";
     if (http_response_code() < 400) { // Ensure error status code if not already set
        http_response_code(400); // Default to Bad Request if specific error code wasn't set
     }
}


// --- Perform Logging AFTER response is fully finalized ---
// Use a separate try/catch for logging so logging failure doesn't break the main response
try {
    if ($db) { // Only log if DB connection was successful initially
        // Use the previously included log_action function/logic if available
        // Or insert directly into logs table here
        $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, :actionType, :details, :timestamp)";
        $logStmt = $db->prepare($logSql);

        // Build final details for logging, ensuring all keys exist
        $logDetails = [
            'importType' => 'containers',
            'fileName' => $fileName, // Already captured
            'success' => $response['success'],
            'importedCount' => $response['containersImported'],
            'skippedCount' => $response['skippedCount'],
            'errors' => $response['errors'], // Use final errors array
            'finalMessage' => $response['message']
        ];

        $logParams = [
            ':userId' => 'System_Import',
            ':actionType' => 'import_containers',
            ':details' => json_encode($logDetails), // Encode the final details
            ':timestamp' => $now // Use the timestamp from the start of processing
        ];
        $logStmt->execute($logParams);
        error_log("Container import action logged.");
    } else {
         error_log("Container Import Warning: DB connection was not available, skipping logging.");
    }
} catch (Exception $logEx) {
    // Log critical logging failure, but don't change the original HTTP response
    error_log("CRITICAL: Failed to log container import action! Error: " . $logEx->getMessage());
}
// --- END LOGGING ---


// --- Send Output ---
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}
// Remove pretty print for production
echo json_encode($response /*, JSON_PRETTY_PRINT */);
$db = null; // Ensure connection is closed before exit
exit(); // Exit cleanly

?>
<?php // backend/import_items.php (Minimal Change Strategy Applied)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// --- Response Structure ---
$response = [
    'success' => false,
    'message' => '',
    'insertedCount' => 0,
    'skippedCount' => 0,
    'errors' => [] // <<< Initialize errors array here
];
$db = null; // Initialize $db
$fileHandle = null; // Initialize $fileHandle
$stmt = null; // Initialize $stmt
$fileName = 'N/A'; // Initialize fileName for logging scope

// --- Include Database ---
require_once __DIR__ . '/database.php';

// --- Check Request Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); } // Set header if not already set
    echo json_encode($response);
    exit;
}

// --- File Upload Handling ---
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $uploadError = $_FILES['file']['error'] ?? 'No file uploaded';
    $phpUploadErrors = [ /* ... map codes to messages ... */ ]; // Assuming you have this mapping elsewhere or handle directly
    $response['message'] = $phpUploadErrors[$uploadError] ?? "File upload error code: ($uploadError)";
    error_log("Import Items API: File upload error: " . $response['message']);
    if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
    echo json_encode($response);
    exit;
}

$uploadedFile = $_FILES['file'];
$csvFilePath = $uploadedFile['tmp_name'];
$fileName = basename($uploadedFile['name']); // Get filename for logging

// Validate file type (Optional but recommended)
if (function_exists('mime_content_type')) {
    $fileType = mime_content_type($csvFilePath);
    $allowedTypes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
    if (!in_array(strtolower($fileType), $allowedTypes)) {
        http_response_code(400);
        $response['message'] = "Invalid file type. Only CSV files are allowed. Detected: " . $fileType;
        error_log("Import Items API: Invalid file type: " . $fileType);
        if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
        echo json_encode($response);
        exit;
    }
} else {
    // Fallback: check extension if mime_content_type is unavailable
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($fileExtension !== 'csv') {
         http_response_code(400);
         $response['message'] = "Invalid file extension. Only CSV files are allowed.";
         error_log("Import Items API: Invalid file extension: " . $fileExtension);
         if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
         echo json_encode($response);
         exit;
    }
}


// --- Database Processing ---
try {
    $db = getDbConnection();
    if ($db === null) {
        throw new Exception("Database connection failed.", 503); // Throw to handle below
    }

    $insertedCount = 0;
    $skippedCount = 0;
    $rowNumber = 0; // Start at 0 to track file lines
    // Removed local $errorMessages initialization, using $response['errors'] directly

    $fileHandle = fopen($csvFilePath, 'r');
    if (!$fileHandle) {
        throw new Exception('Failed to open uploaded file for processing.', 500);
    }

    // Define SQL OUTSIDE the loop (as in your original)
    $sql = "INSERT INTO items (
                itemId, name, dimensionW, dimensionD, dimensionH, mass, priority,
                expiryDate, usageLimit, remainingUses, preferredZone,
                status, lastUpdated,
                containerId, positionX, positionY, positionZ,
                placedDimensionW, placedDimensionH, placedDimensionD
            ) VALUES (
                :itemId, :name, :dimW, :dimD, :dimH, :mass, :priority,
                :expiry, :usageLimit, :remainingUses, :prefZone,
                :status, :lastUpdated,
                NULL, NULL, NULL, NULL, NULL, NULL, NULL
            )";

    // --- Read header row ---
    $headerRow = fgetcsv($fileHandle, 0, ',', '"', '\\');
    $rowNumber = 1; // We are now processing row 1 (header)
    if ($headerRow === false) { throw new Exception("Could not read header row (empty file?)."); }
    // Optional: Add header column validation if needed
    // --- End Header Read ---

    $db->beginTransaction(); // Start transaction AFTER opening file and reading header

    $lastUpdated = date('Y-m-d H:i:s'); // Get timestamp once

    while (($row = fgetcsv($fileHandle, 0, ',', '"', '\\')) !== false) {
        $rowNumber++;
        if ($row === NULL || (count($row) === 1 && trim($row[0]) === '')) { continue; } // Skip empty lines

        // Check column count matches expected CSV format
        $expectedCols = 10;
        if (count($row) < $expectedCols) {
            $skippedCount++;
            // <<< MODIFICATION: Add error directly to $response['errors'] >>>
            $response['errors'][] = ['row' => $rowNumber, 'message' => "Skipped: Incorrect column count (" . count($row) . " found, expected $expectedCols)."];
            error_log("Import Items API: Row $rowNumber skipped - incorrect column count.");
            continue;
        }

        // Assign CSV data with null coalescing for safety
        $itemId        = trim($row[0] ?? ''); $name          = trim($row[1] ?? '');
        $width         = trim($row[2] ?? ''); $depth         = trim($row[3] ?? '');
        $height        = trim($row[4] ?? ''); $mass          = trim($row[5] ?? '');
        $priority      = trim($row[6] ?? ''); $expiryDate    = trim($row[7] ?? '');
        $usageLimit    = trim($row[8] ?? ''); $preferredZone = trim($row[9] ?? '');

        // --- Data Validation & Transformation ---
        $isValid = true;
        $validationErrors = []; // Collect errors for this specific row

        if (empty($itemId)) { $isValid = false; $validationErrors[] = "itemId cannot be empty."; }
        if (empty($name)) { $isValid = false; $validationErrors[] = "name cannot be empty."; }
        if (!is_numeric($width) || $width <= 0) { $isValid = false; $validationErrors[] = "invalid width '$width'."; }
        if (!is_numeric($depth) || $depth <= 0) { $isValid = false; $validationErrors[] = "invalid depth '$depth'."; }
        if (!is_numeric($height) || $height <= 0) { $isValid = false; $validationErrors[] = "invalid height '$height'."; }
        if (!is_numeric($mass) || $mass < 0) { $isValid = false; $validationErrors[] = "invalid mass '$mass'."; }
        if (!is_numeric($priority) || $priority < 0 || $priority > 100) { $isValid = false; $validationErrors[] = "invalid priority '$priority' (0-100)."; }

        $dbExpiryDate = null;
        if (!empty($expiryDate) && strtolower($expiryDate) !== 'null' && strtolower($expiryDate) !== 'n/a') { // Also check n/a
            $d = DateTime::createFromFormat('Y-m-d', $expiryDate);
            if ($d && $d->format('Y-m-d') === $expiryDate) { $dbExpiryDate = $expiryDate; }
            else { $isValid = false; $validationErrors[] = "invalid expiryDate format '$expiryDate' (Use YYYY-MM-DD or leave empty/NULL/N/A)."; }
        }

        $dbUsageLimit = null; $dbRemainingUses = null;
        if (!empty($usageLimit) && strtolower($usageLimit) !== 'null' && strtolower($usageLimit) !== 'n/a') { // Also check n/a
            if (!is_numeric($usageLimit) || !ctype_digit($usageLimit) || (int)$usageLimit < 0 ) {
                $isValid = false; $validationErrors[] = "invalid usageLimit '$usageLimit' (Must be non-negative integer or leave empty/NULL/N/A).";
            } else { $dbUsageLimit = (int)$usageLimit; $dbRemainingUses = $dbUsageLimit; }
        }

        $dbPreferredZone = (!empty($preferredZone) && strtolower($preferredZone) !== 'null' && strtolower($preferredZone) !== 'n/a') ? $preferredZone : null; // Also check n/a
        $status = 'available'; // Default status - Changed from 'imported' in previous versions? Ensure this is correct.

        // Skip row if validation failed
        if (!$isValid) {
            $skippedCount++;
            // <<< MODIFICATION: Add error directly to $response['errors'] >>>
            $response['errors'][] = ['row' => $rowNumber, 'message' => "Skipped - Validation failed: " . implode('; ', $validationErrors)]; // Combine validation messages
            error_log("Import Items API: Row $rowNumber validation failed for itemId ($itemId): " . implode('; ', $validationErrors));
            continue; // Move to the next row
        }

        // --- MODIFICATION: Prepare statement INSIDE the loop (as in your original) ---
        $stmt = $db->prepare($sql);
        if (!$stmt) { // Add check if prepare failed
             $skippedCount++;
             $dbErrorMsg = $db->errorInfo()[2] ?? 'Unknown prepare error';
             $response['errors'][] = ['row' => $rowNumber, 'message' => "Skipped - DB statement preparation failed: $dbErrorMsg"];
             error_log("Import Items API: Row $rowNumber statement preparation failed for itemId ($itemId): $dbErrorMsg");
             continue; // Skip this row if prepare fails
        }
        // --- END MODIFICATION ---

        // --- Bind parameters and Execute ---
        try {
            $params = [
                ':itemId'         => $itemId, ':name'           => $name,
                ':dimW'           => (float)$width, ':dimD'           => (float)$depth, ':dimH'           => (float)$height,
                ':mass'           => (float)$mass, ':priority'       => (int)$priority,
                ':expiry'         => $dbExpiryDate, ':usageLimit'     => $dbUsageLimit, ':remainingUses'  => $dbRemainingUses,
                ':prefZone'       => $dbPreferredZone, ':status'         => $status,
                ':lastUpdated'    => $lastUpdated
            ];

            $stmt->execute($params);
            $insertedCount++;

        // <<< MODIFICATION: Simplified Catch Block >>>
        } catch (PDOException $e) {
            // Minimal handling: Log the error, record it for the response, and continue
            $skippedCount++;
            $errorMessage = $e->getMessage(); // Get the core error message
            // Add error WITH row number to the main response array directly
            $response['errors'][] = ['row' => $rowNumber, 'message' => "Skipped - DB error: " . $errorMessage];
            error_log("Import Items API: Row $rowNumber DB error for itemId '$itemId': " . $errorMessage);
            // Explicitly continue to the next iteration of the while loop
            continue;
        }
        // <<< END MODIFICATION >>>

    } // End while loop

    fclose($fileHandle); $fileHandle = null;

    // Commit the transaction
    if ($db->inTransaction()) {
        $db->commit();
        $response['success'] = true; // Mark as success even if some rows were skipped
        $response['message'] = "Item import finished. Inserted: $insertedCount, Skipped: $skippedCount.";
         if (count($response['errors']) > 0) {
             $response['message'] .= " Some rows had errors."; // Add nuance
         }
        http_response_code(200);
        error_log("Import Items API: Committed. Inserted: $insertedCount, Skipped: $skippedCount.");
    } else {
         $response['message'] = "Item import finished, but transaction commit state unclear. Inserted: $insertedCount, Skipped: $skippedCount.";
         $response['success'] = ($insertedCount > 0 || $skippedCount > 0);
         http_response_code($response['success'] ? 200 : 500);
         error_log("Import Items API: Finished with no active transaction. Inserted: $insertedCount, Skipped: $skippedCount.");
    }

} catch (Exception $e) {
    // Catch general exceptions
    if ($db !== null && $db->inTransaction()) { $db->rollBack(); error_log("Import Items API: Transaction rolled back."); }
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($statusCode);
    $response['message'] = 'An error occurred during import: ' . $e->getMessage();
    $response['success'] = false;
    error_log("Import Items API: Exception: " . $e->getMessage());
    if (isset($fileHandle) && is_resource($fileHandle)) { fclose($fileHandle); }

} finally {
    $db = null; // Close DB connection
    if (isset($fileHandle) && is_resource($fileHandle)) { fclose($fileHandle); } // Ensure handle closed
    $stmt = null; // Release statement handle
}

$response['insertedCount'] = $insertedCount ?? 0; // Ensure counts are set
$response['skippedCount'] = $skippedCount ?? 0;
// $response['errors'] is already populated directly

// --- <<< ADDED LOGGING BLOCK >>> ---
// --- Perform Logging AFTER response is fully finalized ---
try {
    // Re-establish connection if necessary, or use existing if still valid (depends on error handling)
    // Simplified: assume connection needs re-establishing if db is null after main try-catch
    if ($db === null) { $db = getDbConnection(); } // Attempt reconnect for logging

    if ($db) { // Check DB connection is valid before logging
        $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, :actionType, :details, :timestamp)";
        $logStmt = $db->prepare($logSql);
        // Build final details for logging
        $logDetails = [
            'importType' => 'items', // Indicate item import
            'fileName' => $fileName, // Use filename captured earlier
            'success' => $response['success'], // Use final success status
            'insertedCount' => $response['insertedCount'], // Use final count
            'skippedCount' => $response['skippedCount'], // Use final count
            'errors' => $response['errors'], // Log collected errors (now directly from response)
            'finalMessage' => $response['message'] // Use final message
        ];
        $logParams = [
            ':userId' => 'System_Import',
            ':actionType' => 'import_items', // Correct action type
            ':details' => json_encode($logDetails),
            ':timestamp' => date('Y-m-d H:i:s')
        ];
        $logStmt->execute($logParams);
        error_log("Item import action logged correctly.");
    } else {
         error_log("CRITICAL: Cannot log item import action! DB connection unavailable.");
    }
} catch (Exception $logEx) {
     error_log("CRITICAL: Failed to log item import action! Error: " . $logEx->getMessage());
} finally {
    $db = null; // Ensure DB connection used for logging is closed
}
// --- END LOGGING ---

// Send final JSON response
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
echo json_encode($response);
exit(); // Explicitly exit after sending response

?>
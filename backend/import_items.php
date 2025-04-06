<?php // backend/import_items.php (Modified with execute check & detailed logging)

ini_set('display_errors', 0); // Keep errors internal
ini_set('log_errors', 1);    // Log errors to server log
error_reporting(E_ALL);

// --- Response Structure ---
$response = [
    'success' => false,
    'message' => '',
    'insertedCount' => 0,
    'skippedCount' => 0,
    'errors' => []
];
$db = null;
$fileHandle = null;
$stmt = null;
$fileName = 'N/A';

// --- Include Database ---
require_once __DIR__ . '/database.php';

// --- Check Request Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); $response['message'] = 'Invalid request method.'; echo json_encode($response); exit;
}

// --- File Upload Handling ---
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $uploadError = $_FILES['file']['error'] ?? 'No file uploaded';
    $phpUploadErrors = [ /* ... define error map if needed ... */ ];
    $response['message'] = $phpUploadErrors[$uploadError] ?? "File upload error code: ($uploadError)";
    error_log("Import Items API: File upload error: " . $response['message']);
    echo json_encode($response); exit;
}

$uploadedFile = $_FILES['file'];
$csvFilePath = $uploadedFile['tmp_name'];
$fileName = basename($uploadedFile['name'] ?? 'items_upload.csv'); // Use actual filename

// Optional but recommended file type check (using extension as fallback)
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($fileExtension !== 'csv') {
     http_response_code(400); $response['message'] = "Invalid file extension. Only CSV files are allowed."; error_log("Import Items API: Invalid file extension: " . $fileExtension); echo json_encode($response); exit;
}
if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
    http_response_code(500); $response['message'] = 'Server error: Cannot read uploaded file.'; error_log("Cannot read uploaded file: $csvFilePath"); echo json_encode($response); exit;
}


// --- Database Processing ---
$insertedCount = 0;
$skippedCount = 0;
$rowNumber = 0;
$lastUpdated = date('Y-m-d H:i:s');

try {
    $db = getDbConnection();
    if ($db === null) { throw new Exception("Database connection failed.", 503); }
     error_log("Item Import: DB connection successful for $fileName.");

    $fileHandle = fopen($csvFilePath, 'r');
    if (!$fileHandle) { throw new Exception('Failed to open uploaded file for processing.', 500); }

    // Define SQL (NULLs for placement fields)
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

    // Read header row
    $headerRow = fgetcsv($fileHandle); // Defaults: , delimiter, " enclosure
    $rowNumber = 1;
    if ($headerRow === false) { throw new Exception("Could not read header row (empty file?)."); }
    $expectedCols = 10; // itemId, name, W, D, H, mass, priority, expiry, usage, zone
    if (count($headerRow) < $expectedCols) { throw new Exception("Invalid CSV header. Expected at least $expectedCols columns, found " . count($headerRow)); }
    // Optional: Validate specific header names here if desired

    $db->beginTransaction(); // Start transaction
    error_log("Item Import: Transaction started for $fileName.");

    $stmt = $db->prepare($sql); // Prepare statement once

    while (($row = fgetcsv($fileHandle)) !== false) {
        $rowNumber++;
        if ($row === NULL || (count($row) === 1 && trim($row[0]) === '')) { continue; } // Skip empty lines

        if (count($row) < $expectedCols) {
            $skippedCount++;
            $response['errors'][] = ['row' => $rowNumber, 'message' => "Skipped: Incorrect column count (" . count($row) . " found, expected $expectedCols)."];
            error_log("Item Import [Row $rowNumber]: Skipped - incorrect column count.");
            continue;
        }

        // Assign CSV data
        $itemId        = trim($row[0] ?? ''); $name          = trim($row[1] ?? '');
        $width         = trim($row[2] ?? ''); $depth         = trim($row[3] ?? '');
        $height        = trim($row[4] ?? ''); $mass          = trim($row[5] ?? '');
        $priority      = trim($row[6] ?? ''); $expiryDate    = trim($row[7] ?? '');
        $usageLimit    = trim($row[8] ?? ''); $preferredZone = trim($row[9] ?? '');

        // --- ADDED: Detailed Row Logging ---
        error_log("Item Import [Row $rowNumber]: Processing ID=$itemId, Name=$name");

        // --- Data Validation ---
        $isValid = true;
        $validationErrors = [];
        if (empty($itemId)) { $isValid = false; $validationErrors[] = "itemId cannot be empty."; }
        if (empty($name)) { $isValid = false; $validationErrors[] = "name cannot be empty."; }
        // Robust numeric checks: allow floats for dimensions/mass, ints for priority/usage
        if (!is_numeric($width) || (float)$width <= 0) { $isValid = false; $validationErrors[] = "invalid width '$width'."; }
        if (!is_numeric($depth) || (float)$depth <= 0) { $isValid = false; $validationErrors[] = "invalid depth '$depth'."; }
        if (!is_numeric($height) || (float)$height <= 0) { $isValid = false; $validationErrors[] = "invalid height '$height'."; }
        if (!is_numeric($mass) || (float)$mass < 0) { $isValid = false; $validationErrors[] = "invalid mass '$mass'."; } // Allow 0 mass? Check reqs.
        if (!is_numeric($priority) || !ctype_digit($priority) || (int)$priority < 0 || (int)$priority > 100) { $isValid = false; $validationErrors[] = "invalid priority '$priority' (0-100 integer)."; }

        $dbExpiryDate = null;
        if (!empty($expiryDate) && strtolower($expiryDate) !== 'null' && strtolower($expiryDate) !== 'n/a') {
            try { // Use try-catch for date parsing
                $d = new DateTime($expiryDate);
                $dbExpiryDate = $d->format('Y-m-d'); // Format consistently
            } catch (Exception $dateEx) {
                 $isValid = false; $validationErrors[] = "invalid expiryDate '$expiryDate' (Use YYYY-MM-DD or leave empty/NULL/N/A).";
            }
        }

        $dbUsageLimit = null; $dbRemainingUses = null;
        if (!empty($usageLimit) && strtolower($usageLimit) !== 'null' && strtolower($usageLimit) !== 'n/a') {
            if (!is_numeric($usageLimit) || !ctype_digit($usageLimit) || (int)$usageLimit < 0 ) {
                $isValid = false; $validationErrors[] = "invalid usageLimit '$usageLimit' (Must be non-negative integer or leave empty/NULL/N/A).";
            } else { $dbUsageLimit = (int)$usageLimit; $dbRemainingUses = $dbUsageLimit; } // Set remaining uses initially
        }

        $dbPreferredZone = (!empty($preferredZone) && strtolower($preferredZone) !== 'null' && strtolower($preferredZone) !== 'n/a') ? $preferredZone : null;
        $status = 'available'; // Default status for new items

        if (!$isValid) {
            $skippedCount++;
            $combinedErrorMessage = implode('; ', $validationErrors);
            $response['errors'][] = ['row' => $rowNumber, 'message' => "Skipped - Validation failed: " . $combinedErrorMessage];
            error_log("Item Import [Row $rowNumber]: Validation failed for ID ($itemId): " . $combinedErrorMessage);
            continue;
        }

        // --- Bind parameters and Execute (using array for execute) ---
        $params = [
            ':itemId'         => $itemId, ':name'           => $name,
            ':dimW'           => (float)$width, ':dimD'           => (float)$depth, ':dimH'           => (float)$height,
            ':mass'           => (float)$mass, ':priority'       => (int)$priority,
            ':expiry'         => $dbExpiryDate, ':usageLimit'     => $dbUsageLimit, ':remainingUses'  => $dbRemainingUses,
            ':prefZone'       => $dbPreferredZone, ':status'         => $status,
            ':lastUpdated'    => $lastUpdated
        ];

        // --- ADDED: Explicit Execute Check ---
        try {
            if ($stmt->execute($params)) {
                $insertedCount++;
                 error_log("Item Import [Row $rowNumber]: Successfully executed INSERT for ID=$itemId.");
            } else {
                // This else block might not be reached if PDO throws exception on failure
                $errorInfo = $stmt->errorInfo();
                $errorMessage = "DB Execute Failed: " . ($errorInfo[2] ?? 'Unknown Error');
                 error_log("Item Import [Row $rowNumber]: FAILED DB execute (returned false) for ID=$itemId. Error: $errorMessage");
                // Throw exception to ensure rollback on non-exception failure
                 throw new Exception("Database execution failed (returned false) for row $rowNumber.");
            }
        } catch (PDOException $e) {
             // Catch PDO exceptions (like UNIQUE constraint) during execute
             $skippedCount++;
             $errorMessage = $e->getMessage();
             $response['errors'][] = ['row' => $rowNumber, 'message' => "Skipped - DB error: " . $errorMessage];
             error_log("Item Import [Row $rowNumber]: DB PDOException for ID '$itemId': " . $errorMessage);
             // Throw again to trigger transaction rollback
             throw $e; // Re-throw the original PDOException
        }
        // --- END Explicit Execute Check ---

    } // End while loop

    fclose($fileHandle); $fileHandle = null;

    // Commit the transaction IF NO EXCEPTIONS WERE THROWN
    if ($db->inTransaction()) {
        $db->commit();
        $response['success'] = true;
        $response['message'] = "Item import finished. Inserted: $insertedCount, Skipped: $skippedCount.";
         if (count($response['errors']) > 0) { $response['message'] .= " Some rows had errors."; }
        http_response_code(200);
        error_log("Item Import: Committed. Inserted: $insertedCount, Skipped: $skippedCount for $fileName.");
    } else {
        // This state implies rollback occurred due to an exception
         error_log("Item Import: Finished, but transaction was not active (likely rolled back) for $fileName.");
         // Success remains false unless explicitly set true on commit
         if (empty($response['message'])) {
             $response['message'] = "Item import failed or finished with errors. Inserted: $insertedCount, Skipped: $skippedCount.";
         }
         http_response_code( $insertedCount > 0 ? 207 : 400 ); // Multi-Status if partial, Bad Request if all failed/skipped
    }

} catch (PDOException $e) { // Catch re-thrown PDOExceptions or initial connection/prepare errors
    if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Item Import: Rolled back transaction due to PDOException in outer block."); }
    http_response_code(400); // Bad Request (usually duplicate ID) or 500
    $response['success'] = false;
    $insertedCount = 0; // Reset count
    $errorCode = $e->errorInfo[1] ?? $e->getCode();
    $errorMessage = $e->getMessage();
    if ($errorCode === 19 || stripos($errorMessage, 'UNIQUE constraint') !== false) {
        $response['message'] = "Import failed: Duplicate Item ID encountered near row $rowNumber. Rolled back.";
        // Error might already be in array from inner catch, but add generic if needed
        if (empty(array_filter($response['errors'], fn($err) => $err['row'] === $rowNumber))) {
             $response['errors'][] = ['row' => $rowNumber, 'message' => "Duplicate ID constraint violated."];
        }
         error_log("Item Import Outer PDOException (Row $rowNumber) - Duplicate ID. " . $errorMessage);
    } else {
        $response['message'] = "Database error occurred near row $rowNumber. Import failed and rolled back.";
         if (empty(array_filter($response['errors'], fn($err) => $err['row'] === $rowNumber))) {
            $response['errors'][] = ['row' => $rowNumber, 'message' => "DB Error [$errorCode]: " . $errorMessage];
         }
        error_log("Item Import Outer PDOException (Row $rowNumber): [$errorCode] " . $errorMessage);
    }

} catch (Exception $e) { // Catch general exceptions (file open, header error, execute check failure etc.)
    if ($db && $db->inTransaction()) { $db->rollBack(); error_log("Item Import: Rolled back transaction due to general Exception."); }
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($statusCode);
    $response['success'] = false;
    $insertedCount = 0;
    $response['message'] = 'An error occurred during import: ' . $e->getMessage();
    if (empty(array_filter($response['errors'], fn($err) => $err['row'] === $rowNumber))) {
         $response['errors'][] = ['row' => $rowNumber, 'message' => $e->getMessage()];
    }
    error_log("Item Import Exception (Row $rowNumber): " . $e->getMessage());

} finally {
    // Ensure file handle is closed
    if (isset($fileHandle) && $fileHandle && is_resource($fileHandle)) { fclose($fileHandle); }
    $stmt = null; // Release statement
    // DB connection closed in logging block or here if logging fails
}

// Finalize counts for response
$response['insertedCount'] = $insertedCount;
$response['skippedCount'] = $skippedCount;

// --- Logging Block ---
try {
    if ($db === null) { $db = getDbConnection(); } // Attempt reconnect for logging if needed

    if ($db) {
        $logSql = "INSERT INTO logs (userId, actionType, detailsJson, timestamp) VALUES (:userId, :actionType, :details, :timestamp)";
        $logStmt = $db->prepare($logSql);
        $logDetails = [
            'importType' => 'items',
            'fileName' => $fileName,
            'success' => $response['success'],
            'insertedCount' => $response['insertedCount'],
            'skippedCount' => $response['skippedCount'],
            'errors' => $response['errors'],
            'finalMessage' => $response['message']
        ];
        $logParams = [
            ':userId' => 'System_Import',
            ':actionType' => 'import_items',
            ':details' => json_encode($logDetails),
            ':timestamp' => $lastUpdated // Use timestamp from start of process
        ];
        $logStmt->execute($logParams);
        error_log("Item import action logged.");
    } else {
         error_log("CRITICAL: Cannot log item import action! DB connection unavailable.");
    }
} catch (Exception $logEx) {
     error_log("CRITICAL: Failed to log item import action! Error: " . $logEx->getMessage());
} finally {
     $db = null; // Ensure logging DB handle is closed
}
// --- END Logging ---

// Send final JSON response
if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
echo json_encode($response);
exit();

?>
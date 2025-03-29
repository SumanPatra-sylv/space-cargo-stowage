<?php // backend/api/import_containers.php

// Set header to return JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow requests from any origin (for development)
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request (pre-flight request for CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- Database Connection ---
$dbPath = __DIR__ . '/../cargo_database.sqlite'; // Path relative to this file
$db = null; // Initialize db variable

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // Fetch as associative arrays
    $db->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException $e) {
    error_log("FATAL: Database connection error: " . $e->getMessage()); 
    http_response_code(500); 
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error: ' . $e->getMessage(),
        'containersImported' => 0,
        'errors' => []
    ]);
    exit; 
}


// --- Request Handling ---
$response = [
    'success' => false,
    'containersImported' => 0,
    'errors' => []
];

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Invalid request method received: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    echo json_encode($response);
    exit;
}

// Check if a file was uploaded
if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = isset($_FILES['csvFile']['error']) ? $_FILES['csvFile']['error'] : 'Not Set';
    error_log("File upload error. Code: " . $errorCode);
    http_response_code(400); 
    $response['message'] = 'No CSV file uploaded or error during upload.';
    if (isset($_FILES['csvFile']['error'])) {
         $uploadErrors = [ /* ... Error codes ... */ ];
         $response['uploadError'] = $uploadErrors[$errorCode] ?? "Unknown upload error code: $errorCode";
         error_log("Upload Error Details: " . $response['uploadError']); 
    }
    echo json_encode($response);
    exit;
}

$fileTmpPath = $_FILES['csvFile']['tmp_name'];
$fileName = $_FILES['csvFile']['name'];
$fileSize = $_FILES['csvFile']['size'];


// --- Debugging Temporary File ---
error_log("Received upload: " . $fileName . " at temporary path: " . $fileTmpPath . " | Size: " . $fileSize);
error_log("Does temporary file exist? " . (file_exists($fileTmpPath) ? 'Yes' : 'No'));
error_log("Is temporary file readable? " . (is_readable($fileTmpPath) ? 'Yes' : 'No'));
// --- End Debugging ---


// Basic validation (e.g., check file extension)
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($fileExtension !== 'csv') {
    error_log("Invalid file type uploaded: " . $fileName . " (Extension: " . $fileExtension . ")");
    http_response_code(400); 
    $response['message'] = 'Invalid file type. Only CSV files are accepted.';
    echo json_encode($response);
    exit;
}


// --- Move Uploaded File ---
$destinationPath = __DIR__ . '/../uploads/'; 
$fileToOpen = null; 

if (!is_dir($destinationPath)) {
    if (!mkdir($destinationPath, 0777, true)) {
        error_log("Failed to create uploads directory: " . $destinationPath . " - Error: " . print_r(error_get_last(), true));
        error_log("WARNING: Proceeding using temporary file path due to directory creation failure.");
        $fileToOpen = $fileTmpPath;
    } else {
         error_log("Created uploads directory: " . $destinationPath);
         chmod($destinationPath, 0777);
    }
}

if ($fileToOpen === null) {
    if (is_dir($destinationPath) && is_writable($destinationPath)) {
        $safeBaseName = basename($fileName); 
        $newFilePath = $destinationPath . $safeBaseName; 
        
        error_log("Attempting move_uploaded_file from $fileTmpPath to $newFilePath");
        if (move_uploaded_file($fileTmpPath, $newFilePath)) {
            error_log("Successfully moved uploaded file to: " . $newFilePath);
            $fileToOpen = $newFilePath; 
        } else {
            error_log("Failed to move uploaded file from $fileTmpPath to $newFilePath. Check permissions/paths. Error: " . print_r(error_get_last(), true));
            $fileToOpen = $fileTmpPath; 
        }
    } else {
         error_log("Uploads directory is not writable or does not exist: " . $destinationPath . ". Using temporary path.");
         $fileToOpen = $fileTmpPath; 
    }
}
// --- End Move Uploaded File ---


// --- CSV Parsing and Database Insertion ---
$importedCount = 0;
$rowNumber = 0; 
$skippedHeader = false;
$stmt = null; 
$handle = null; 

$sql = "INSERT INTO containers (containerId, zone, width, depth, height) VALUES (:containerId, :zone, :width, :depth, :height)";

if (empty($fileToOpen)) {
     error_log("FATAL: File path to open is empty after upload/move attempt.");
     http_response_code(500);
     $response['message'] = "Internal server error: Could not determine file path to process.";
     echo json_encode($response);
     exit;
}

error_log("Final check before fopen - Path: $fileToOpen | Exists: " . (file_exists($fileToOpen)?'Yes':'No') . " | Readable: " . (is_readable($fileToOpen)?'Yes':'No'));

$db->beginTransaction();

try {
    $stmt = $db->prepare($sql); 

    ini_set('auto_detect_line_endings', TRUE); 
    error_log("Attempting fopen on: " . $fileToOpen); 
    $handle = fopen($fileToOpen, "r"); 

    if ($handle !== FALSE) {
        error_log("fopen successful for: " . $fileToOpen); 

        // *** Use the standard fgetcsv loop ***
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) { 
            if ($data === NULL) { 
                 error_log("fgetcsv returned NULL (likely blank line), skipping. Row number was: " . $rowNumber);
                 continue; 
            }

            $rowNumber++; 

            if ($rowNumber == 1 && !$skippedHeader) {
                 error_log("Skipping header row: " . print_r($data, true));
                 $skippedHeader = true; 
                 continue; 
            }

            error_log("Processing Row $rowNumber: " . print_r($data, true)); 

            // Validation
            if (count($data) !== 5) { 
                 $response['errors'][] = ['row' => $rowNumber, 'message' => 'Wrong number of columns (' . count($data) . '). Expected exactly 5.'];
                 error_log("Row $rowNumber skipped: Wrong number of columns (" . count($data) . ").");
                 continue; 
            }
             $containerId = isset($data[0]) ? trim($data[0]) : '';
             $zone        = isset($data[1]) ? trim($data[1]) : '';
             $width       = isset($data[2]) ? trim($data[2]) : '';
             $depth       = isset($data[3]) ? trim($data[3]) : '';
             $height      = isset($data[4]) ? trim($data[4]) : '';
             if (empty($containerId)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Container ID empty']; error_log("Row $rowNumber skipped: Empty Container ID."); continue; }
             if (empty($zone)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Zone empty']; error_log("Row $rowNumber skipped: Empty Zone."); continue; }
             if (!is_numeric($width)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Width not numeric: '.$width]; error_log("Row $rowNumber skipped: Width not numeric ($width)."); continue; }
             if (!is_numeric($depth)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Depth not numeric: '.$depth]; error_log("Row $rowNumber skipped: Depth not numeric ($depth)."); continue; }
             if (!is_numeric($height)) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Height not numeric: '.$height]; error_log("Row $rowNumber skipped: Height not numeric ($height)."); continue; }
             if ($width <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Width not positive: '.$width]; error_log("Row $rowNumber skipped: Width not positive ($width)."); continue; }
             if ($depth <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Depth not positive: '.$depth]; error_log("Row $rowNumber skipped: Depth not positive ($depth)."); continue; }
             if ($height <= 0) { $response['errors'][] = ['row' => $rowNumber, 'message' => 'Height not positive: '.$height]; error_log("Row $rowNumber skipped: Height not positive ($height)."); continue; }
            
            // Insertion
             try {
                 if (!$stmt) { throw new Exception("DB statement not prepared."); }
                 $stmt->bindParam(':containerId', $containerId);
                 $stmt->bindParam(':zone', $zone);
                 $stmt->bindParam(':width', $width);
                 $stmt->bindParam(':depth', $depth);
                 $stmt->bindParam(':height', $height);
                 $stmt->execute();
                 $importedCount++;
                 error_log("Row $rowNumber inserted successfully: $containerId");
             } catch (PDOException $e) {
                 $response['errors'][] = ['row' => $rowNumber, 'message' => 'DB Error for ' . $containerId . ': ' . $e->getMessage()];
                 error_log("Row $rowNumber DB Error for $containerId: " . $e->getMessage());
             }

        } // End while loop

        error_log("Exited while loop. Checking if handle needs closing.");
        if (is_resource($handle)) { 
             fclose($handle);
             error_log("Closed file handle.");
        } else {
             error_log("File handle was not valid before fclose.");
        }


    } else { // if fopen fails
        error_log("fopen FAILED for path: " . $fileToOpen . " - Error: " . print_r(error_get_last(), true)); 
        throw new Exception("Could not open the CSV file at: " . $fileToOpen); 
    } // End if fopen

    if ($db->inTransaction()) {
        $db->commit();
        error_log("Transaction committed. Total imported: $importedCount");
    } else {
         error_log("Skipped commit as no transaction was active.");
    }
    $response['success'] = true;

} catch (Exception $e) {
    if ($db->inTransaction()) {
         $db->rollBack();
         error_log("Transaction rolled back due to exception.");
    }
    http_response_code(500); 
    $response['success'] = false;
    $response['message'] = 'Error processing CSV file: ' . $e->getMessage();
    error_log("EXCEPTION CAUGHT: " . $e->getMessage());

} finally {
    if ($handle && is_resource($handle)) {
        fclose($handle); 
        error_log("Closed file handle in finally block.");
    }
    $stmt = null;
    $db = null;
    error_log("Script execution finished."); 
}


// --- Send Response ---
if (!$response['success']) {
    if (http_response_code() === 200) { http_response_code(400); }
    if (empty($response['message'])) { $response['message'] = "Import failed. Check server logs."; }
} else if (!empty($response['errors']) && $importedCount == 0) {
    http_response_code(400); 
    $response['message'] = "Import failed. No containers were imported validly.";
} else if (!empty($response['errors'])) {
    http_response_code(200); 
    $response['message'] = "Import partially successful. Some rows were skipped - see errors for details.";
} else {
    http_response_code(200); // OK
    $response['message'] = "Import successful.";
}

$response['containersImported'] = $importedCount; 

echo json_encode($response, JSON_PRETTY_PRINT); 

?>
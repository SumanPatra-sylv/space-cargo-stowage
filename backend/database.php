<?php // backend/database.php (Corrected Auto-Initialization Schema)

// Function to initialize the database schema and simulation date file
function initializeDatabaseAndFiles(string $dbPath): ?PDO {
    error_log("Database file not found at $dbPath. Initializing schema...");
    try {
        // Create the directory if it doesn't exist
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                 error_log("FATAL: Failed to create directory for database: $dir");
                 return null;
            }
             error_log("Created directory: $dir");
        }

        // Create the database connection (this will create the empty file)
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON;');
        error_log("Initial PDO connection created for new DB file.");

        // --- Define Schema ---
        // Containers Table
        $db->exec("
            CREATE TABLE IF NOT EXISTS containers (
                containerId TEXT PRIMARY KEY,
                zone TEXT NOT NULL,
                width REAL NOT NULL CHECK(width > 0),
                depth REAL NOT NULL CHECK(depth > 0),
                height REAL NOT NULL CHECK(height > 0),
                createdAt TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
        error_log("Schema: containers table checked/created.");

        // Items Table (CORRECTED - Added preferredContainerId)
        $db->exec("
            CREATE TABLE IF NOT EXISTS items (
                itemId TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                dimensionW REAL NOT NULL CHECK(dimensionW > 0),
                dimensionD REAL NOT NULL CHECK(dimensionD > 0),
                dimensionH REAL NOT NULL CHECK(dimensionH > 0),
                mass REAL CHECK(mass >= 0),
                priority INTEGER NOT NULL CHECK(priority >= 0 AND priority <= 100),
                expiryDate TEXT,
                usageLimit INTEGER,
                remainingUses INTEGER,
                preferredZone TEXT NULL,
                preferredContainerId TEXT NULL,                 -- <<<<<<< CORRECTED: Added this line
                status TEXT DEFAULT 'available' CHECK(status IN ('available', 'stowed', 'disposed', 'reserved')),
                createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
                lastUpdated TEXT DEFAULT CURRENT_TIMESTAMP,
                containerId TEXT NULL,
                positionX REAL NULL,
                positionY REAL NULL,
                positionZ REAL NULL,
                placedDimensionW REAL NULL,
                placedDimensionD REAL NULL,
                placedDimensionH REAL NULL,
                FOREIGN KEY (containerId) REFERENCES containers(containerId)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            );
        ");
         error_log("Schema: items table checked/created (with preferredContainerId)."); // Updated log

        // Logs Table
        $db->exec("
            CREATE TABLE IF NOT EXISTS logs (
                logId INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                userId TEXT,
                actionType TEXT NOT NULL,
                itemId TEXT,
                detailsJson TEXT,
                FOREIGN KEY (itemId) REFERENCES items(itemId) ON DELETE SET NULL
            );
        ");
        error_log("Schema: logs table checked/created.");

         // Add Indexes
        $db->exec("CREATE INDEX IF NOT EXISTS idx_items_status ON items(status);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_items_containerId ON items(containerId);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_actionType ON logs(actionType);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON logs(timestamp);");
        error_log("Schema: Indexes checked/created.");

        // --- Initialize simulation_date.txt ---
        // (Keep your existing logic for this file)
        $simDatePath = __DIR__ . '/simulation_date.txt';
        if (!file_exists($simDatePath)) {
            $defaultStartDate = date('Y-m-d');
            if (file_put_contents($simDatePath, $defaultStartDate) === false) {
                error_log("Warning: Failed to create initial simulation_date.txt at $simDatePath");
            } else {
                error_log("State: Initial simulation_date.txt created with date $defaultStartDate.");
            }
        } else {
             error_log("State: simulation_date.txt already exists.");
        }


        error_log("Database schema and simulation date file initialized successfully.");
        return $db; // Return the newly created and initialized connection

    } catch (PDOException | Exception $e) {
        error_log("FATAL: Error during database initialization: " . $e->getMessage());
        // Attempt to delete the potentially incomplete DB file on error
        if (isset($dbPath) && file_exists($dbPath)) {
             error_log("Attempting to delete potentially corrupt DB file at $dbPath");
             @unlink($dbPath);
        }
        return null;
    }
}

/**
 * Establishes a connection to the SQLite database.
 * Automatically initializes the schema if the database file does not exist.
 *
 * @return PDO|null Returns a PDO database connection object on success, or null on failure.
 */
function getDbConnection() {
    error_log("getDbConnection function called. Checking path..."); // Keep this log

    $dbPath = __DIR__ . '/cargo_database.sqlite';
    error_log("getDbConnection: Database path target: $dbPath"); // Log the target path

    try {
        // Check if the file exists. If not, initialize it.
        if (!file_exists($dbPath)) {
             error_log("getDbConnection: Database file does not exist. Calling initializeDatabaseAndFiles...");
            return initializeDatabaseAndFiles($dbPath); // Call initializer
        }

        // If file exists, try to connect normally
         error_log("getDbConnection: Database file exists. Checking permissions...");
        if (!is_readable($dbPath)) {
             error_log("FATAL: Database file exists but is not readable at path: $dbPath");
             return null;
        }
         if (!is_writable($dbPath) || !is_writable(dirname($dbPath))) {
             // Log as warning, maybe read-only operations are okay? But placement needs write.
             error_log("WARNING/FATAL: Database file/directory exists but is not writable at path: $dbPath - Placement will likely fail.");
             // Depending on use case, you might allow read-only connection here,
             // but for this app, write is needed, so treat as fatal.
             return null;
        }
         error_log("getDbConnection: Permissions seem ok. Attempting PDO connection...");


        $db = new PDO('sqlite:' . $dbPath);
        error_log("getDbConnection: PDO connection successful.");

        // Set attributes for error handling and fetch mode
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Enable foreign key constraints for SQLite
        $db->exec('PRAGMA foreign_keys = ON;');
         error_log("getDbConnection: Attributes set and foreign keys enabled.");

        return $db; // Return the existing database connection object

    } catch (PDOException $e) {
        // Log any connection errors
        error_log("FATAL: Database connection error in getDbConnection(): " . $e->getMessage());
        return null; // Return null on failure
    }
}

// End of database.php
?>
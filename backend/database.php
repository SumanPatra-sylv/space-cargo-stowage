<?php // backend/database.php (with Auto-Initialization & Extra Logging)

// Function to initialize the database schema and simulation date file
function initializeDatabaseAndFiles(string $dbPath): ?PDO {
    error_log("Database file not found at $dbPath. Initializing schema...");
    try {
        // Create the directory if it doesn't exist (relevant if DB is in a subdir)
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) { // Use 0755 for permissions, recursive
                 error_log("FATAL: Failed to create directory for database: $dir");
                 return null;
            }
        }

        // Create the database connection (this will create the empty file)
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON;');

        // --- Define Schema ---
        // Containers Table (Matches your init_db.php)
        $db->exec("
            CREATE TABLE IF NOT EXISTS containers (
                containerId TEXT PRIMARY KEY,
                zone TEXT NOT NULL,
                width REAL NOT NULL,
                depth REAL NOT NULL,
                height REAL NOT NULL,
                createdAt TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
        error_log("Schema: containers table checked/created.");

        // Items Table (EXACT MATCH to your init_db.php)
        $db->exec("
            CREATE TABLE IF NOT EXISTS items (
                itemId TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                dimensionW REAL NOT NULL,
                dimensionD REAL NOT NULL,
                dimensionH REAL NOT NULL,
                mass REAL,
                priority INTEGER NOT NULL CHECK(priority >= 0 AND priority <= 100), -- Keep CHECK from your init
                expiryDate TEXT,
                usageLimit INTEGER,
                remainingUses INTEGER,
                preferredZone TEXT,
                status TEXT DEFAULT 'available',
                createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
                lastUpdated TEXT DEFAULT CURRENT_TIMESTAMP,
                containerId TEXT,
                positionX REAL,
                positionY REAL,
                positionZ REAL,
                placedDimensionW REAL,
                placedDimensionD REAL,
                placedDimensionH REAL,
                FOREIGN KEY (containerId) REFERENCES containers(containerId)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            );
        ");
         error_log("Schema: items table checked/created.");

        // Logs Table (Matches your init_db.php)
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

        // --- Initialize simulation_date.txt ---
        $simDatePath = __DIR__ . '/simulation_date.txt';
        if (!file_exists($simDatePath)) {
            $defaultStartDate = date('Y-m-d'); // Use today as default
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

    } catch (PDOException | Exception $e) { // Catch PDO or general errors during init
        error_log("FATAL: Error during database initialization: " . $e->getMessage());
        if (isset($dbPath) && file_exists($dbPath)) { @unlink($dbPath); } // Attempt cleanup
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
    // --- <<< ADDED TRACING LOG >>> ---
    error_log("getDbConnection function called. Checking path...");
    // --- <<< END TRACING LOG >>> ---

    // Define path relative to this file's location (__DIR__)
    $dbPath = __DIR__ . '/cargo_database.sqlite';

    try {
        // Check if the file exists. If not, initialize it.
        if (!file_exists($dbPath)) {
            return initializeDatabaseAndFiles($dbPath); // Call initializer
        }

        // If file exists, try to connect normally
        // Check if readable AND writable *before* trying to connect.
        if (!is_readable($dbPath)) {
             error_log("FATAL: Database file exists but is not readable at path: $dbPath");
             return null;
        }
         if (!is_writable($dbPath) || !is_writable(dirname($dbPath))) {
             error_log("FATAL: Database file/directory exists but is not writable at path: $dbPath");
             return null;
        }


        $db = new PDO('sqlite:' . $dbPath);

        // Set attributes for error handling and fetch mode
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Throw exceptions on error
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // Fetch associative arrays

        // Enable foreign key constraints for SQLite (important for data integrity)
        $db->exec('PRAGMA foreign_keys = ON;');

        return $db; // Return the existing database connection object

    } catch (PDOException $e) {
        // Log any connection errors
        error_log("FATAL: Database connection error in getDbConnection(): " . $e->getMessage());
        return null; // Return null on failure
    }
}

// End of database.php - closing ?>
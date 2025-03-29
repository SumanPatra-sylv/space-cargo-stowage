<?php // backend/init_db.php

// Define path to the SQLite database file
$dbPath = __DIR__ . '/cargo_database.sqlite'; // Store DB in backend folder

// --- Database Connection ---
try {
    // Create (connect to) the database file. If it doesn't exist, it will be created.
    $db = new PDO('sqlite:' . $dbPath);
    // Set errormode to exceptions for easier error handling
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Enable foreign key constraints
    $db->exec('PRAGMA foreign_keys = ON;');

    echo "Database connection established successfully.\n";

} catch (PDOException $e) {
    // Handle connection error
    echo "Database Connection Error: " . $e->getMessage() . "\n";
    exit(1); // Exit if connection fails
}


// --- CREATE TABLES ---
try {
    // Containers Table
    $db->exec("
        CREATE TABLE IF NOT EXISTS containers (
            containerId TEXT PRIMARY KEY, -- Unique identifier (e.g., 'contA')
            zone TEXT NOT NULL,           -- Location zone (e.g., 'Crew Quarters')
            width REAL NOT NULL,          -- Dimension (cm) along open face
            depth REAL NOT NULL,          -- Dimension (cm) into container
            height REAL NOT NULL,         -- Dimension (cm) vertical along open face
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP -- Track when added
        );
    ");
    echo "Table 'containers' created or already exists.\n";

    // Items Table
    $db->exec("
        CREATE TABLE IF NOT EXISTS items (
            itemId TEXT PRIMARY KEY,         -- Unique identifier (e.g., '001')
            name TEXT NOT NULL,              -- Human-readable name
            width REAL NOT NULL,             -- Item dimension (cm)
            depth REAL NOT NULL,             -- Item dimension (cm)
            height REAL NOT NULL,            -- Item dimension (cm)
            mass REAL,                       -- Item mass (kg)
            priority INTEGER NOT NULL CHECK(priority >= 0 AND priority <= 100), -- Priority (0-100)
            expiryDate TEXT,                 -- Expiry date (ISO 8601 format: YYYY-MM-DD) or NULL
            usageLimit INTEGER,              -- Max uses or NULL
            remainingUses INTEGER,           -- Current uses left
            preferredZone TEXT,              -- Suggested storage zone
            status TEXT DEFAULT 'stowed',    -- 'stowed', 'waste_expired', 'waste_depleted'
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP, -- Track when added

            -- Location Information (if currently stowed)
            currentContainerId TEXT,         -- Which container it's in (NULL if not placed)
            pos_w REAL,                      -- Position: Width coordinate of bottom-left-front corner
            pos_d REAL,                      -- Position: Depth coordinate
            pos_h REAL,                      -- Position: Height coordinate

            -- Define Foreign Key constraint
            FOREIGN KEY (currentContainerId) REFERENCES containers(containerId)
                ON DELETE SET NULL -- If container is deleted, item location becomes unknown
                ON UPDATE CASCADE  -- If containerId changes, update here too
        );
    ");
    echo "Table 'items' created or already exists.\n";

     // Logs Table (for tracking actions)
    $db->exec("
        CREATE TABLE IF NOT EXISTS logs (
            logId INTEGER PRIMARY KEY AUTOINCREMENT, -- Unique log entry ID
            timestamp TEXT DEFAULT CURRENT_TIMESTAMP, -- When the action occurred (ISO 8601)
            userId TEXT,                             -- Identifier for the astronaut performing action (optional)
            actionType TEXT NOT NULL,                -- 'placement', 'retrieval', 'rearrangement', 'disposal', 'import', 'simulation'
            itemId TEXT,                             -- Item involved (if applicable)
            detailsJson TEXT,                        -- JSON string with extra details (e.g., from/to container, coordinates, reason)

            FOREIGN KEY (itemId) REFERENCES items(itemId) ON DELETE SET NULL -- Keep log even if item deleted
        );
    ");
    echo "Table 'logs' created or already exists.\n";


    echo "Database schema initialized successfully!\n";

} catch (PDOException $e) {
    echo "Schema Initialization Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Close the connection (optional for script end, good practice in long running processes)
$db = null;

?>
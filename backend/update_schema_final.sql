-- update_schema_final.sql

-- Step 1: Disable foreign keys
PRAGMA foreign_keys=off;

-- Step 2: Begin transaction
BEGIN TRANSACTION;

-- Step 3: Rename the existing table (which has currentContainerId, pos_w/d/h AND the extra containerId)
ALTER TABLE items RENAME TO items_old;

-- Step 4: Create the NEW items table with the CORRECT final schema
-- Note: We use 'containerId', 'positionX/Y/Z' and OMIT 'currentContainerId', 'pos_w/d/h'
--       and initially omit the FOREIGN KEY.
CREATE TABLE items (
    itemId TEXT PRIMARY KEY,
    name TEXT,
    width REAL,
    depth REAL,
    height REAL,
    mass REAL,
    priority INTEGER,
    expiryDate TEXT,
    usageLimit INTEGER, -- Verify this column name is correct
    status TEXT,
    createdAt TEXT,
    lastUpdated TEXT,
    preferredZone TEXT,
    -- The corrected/new columns:
    containerId TEXT,    -- Final correct name
    positionX REAL,      -- Final correct name (Mapped from pos_w)
    positionY REAL,      -- Final correct name (Mapped from pos_h)
    positionZ REAL       -- Final correct name (Mapped from pos_d)
    -- Verify NO other columns are missing from your original schema!
);

-- Step 5: Copy data, mapping OLD names to NEW names
-- SELECT from items_old using its actual columns (currentContainerId, pos_w/d/h)
-- INSERT into the new items table using the new names (containerId, positionX/Y/Z)
INSERT INTO items (itemId, name, width, depth, height, mass, priority, expiryDate, usageLimit, status, createdAt, lastUpdated, preferredZone, containerId, positionX, positionY, positionZ)
SELECT            itemId, name, width, depth, height, mass, priority, expiryDate, usageLimit, status, createdAt, lastUpdated, preferredZone, currentContainerId, pos_w, pos_h, pos_d
FROM items_old;

-- Step 6: Drop the old table
DROP TABLE items_old;

-- Step 7: Commit the transaction
COMMIT;

-- Step 8: Re-enable foreign keys
PRAGMA foreign_keys=on;
// frontend/src/pages/ImportDataPage.jsx
import React, { useState } from 'react';
// Assuming api.js now exports these correctly
import {
    importContainers,
    importItems,
    getItems,
    getContainers,
    placeItems,
    logAction // <-- Added logAction import
} from '../services/api';
// Optional: Add a CSS file for styling later if needed
// import './ImportDataPage.css';

function ImportDataPage() {
    // State for file inputs
    const [containerFile, setContainerFile] = useState(null);
    const [itemFile, setItemFile] = useState(null);

    // State for container import
    const [isContainerImporting, setIsContainerImporting] = useState(false);
    const [containerImportResult, setContainerImportResult] = useState(null);
    const [containerImportError, setContainerImportError] = useState(null);

    // State for item import
    const [isItemImporting, setIsItemImporting] = useState(false);
    const [itemImportResult, setItemImportResult] = useState(null);
    const [itemImportError, setItemImportError] = useState(null);

    // State for Placement
    const [isPlacing, setIsPlacing] = useState(false);
    const [placementResult, setPlacementResult] = useState(null); // Stores { success, placements, rearrangements, unplacedItems? }
    const [placementError, setPlacementError] = useState(null);

    // Handlers for file input changes
    const handleContainerFileChange = (event) => {
        setContainerFile(event.target.files[0]);
        setContainerImportResult(null); // Clear previous results on new file selection
        setContainerImportError(null);
        setPlacementResult(null); // Clear placement result if files change
        setPlacementError(null);
    };

    const handleItemFileChange = (event) => {
        setItemFile(event.target.files[0]);
        setItemImportResult(null); // Clear previous results
        setItemImportError(null);
        setPlacementResult(null); // Clear placement result if files change
        setPlacementError(null);
    };

    // Handler for container import submission
    const handleContainerSubmit = async (event) => {
        event.preventDefault();
        if (!containerFile) {
            setContainerImportError("Please select a container file first.");
            return;
        }
        const formData = new FormData();
        formData.append('file', containerFile);
        setIsContainerImporting(true);
        setContainerImportResult(null);
        setContainerImportError(null);
        setPlacementResult(null);
        setPlacementError(null);
        try {
            const result = await importContainers(formData);
            setContainerImportResult(result); // Store the full result {success, message, counts, errors}
             if (result.success === false) {
                 setContainerImportError(result.message || "Container import failed. Check details.");
             }
        } catch (error) {
            console.error("Container import failed:", error);
            setContainerImportError(error.message || "Failed to import containers.");
            setContainerImportResult(null);
        } finally {
            setIsContainerImporting(false);
        }
    };

    // Handler for item import submission
    const handleItemSubmit = async (event) => {
        event.preventDefault();
        if (!itemFile) {
            setItemImportError("Please select an item file first.");
            return;
        }
        const formData = new FormData();
        formData.append('file', itemFile);
        setIsItemImporting(true);
        setItemImportResult(null);
        setItemImportError(null);
        setPlacementResult(null);
        setPlacementError(null);
        try {
            const result = await importItems(formData);
            setItemImportResult(result); // Store the full result {success, message, counts, errors}
             if (result.success === false) {
                 setItemImportError(result.message || "Item import failed. Check details.");
             }
        } catch (error) {
            console.error("Item import failed:", error);
            setItemImportError(error.message || "Failed to import items.");
            setItemImportResult(null);
        } finally {
            setIsItemImporting(false);
        }
    };

    // Handler for Running Placement Algorithm
    const handleRunPlacement = async () => {
        setIsPlacing(true);
        setPlacementResult(null);
        setPlacementError(null);
        console.log("Attempting to run placement algorithm...");
        let itemsConsideredCount = 0; // To store count for logging

        try {
            // --- Step 1: Fetch available items ---
            console.log("Fetching available items...");
            const itemsResponse = await getItems({ status: 'available' });
            console.log("Raw response received from getItems:", itemsResponse); // Keep this log for now

            // Validate items response
            if (!itemsResponse || itemsResponse.success !== true || !Array.isArray(itemsResponse.items)) {
                 // Removed the verbose diagnostic logs from here
                 console.error("getItems response failed validation or is not structured as expected:", itemsResponse);
                 const errorMessage = itemsResponse?.message || 'Failed to fetch available items or unexpected response format.';
                 throw new Error(errorMessage);
             }

            const availableItems = itemsResponse.items;
            itemsConsideredCount = availableItems.length; // Store count for logging later
            console.log(`Found ${itemsConsideredCount} available items.`);

            // Handle case of no available items
            if (itemsConsideredCount === 0) {
                // Set a success message, as placement wasn't needed but didn't fail
                setPlacementResult({ success: true, message: "No items with status 'available' found to place.", placements: [], rearrangements: [] });
                setPlacementError(null); // Clear any previous error
                setIsPlacing(false); // Stop loading state
                return; // Exit function early
            }

            // --- Step 2: Fetch containers ---
            console.log("Fetching containers...");
            const containersResponse = await getContainers();
            console.log("Raw response received from getContainers:", containersResponse); // Keep log

            // Validate containers response
             if (!containersResponse || containersResponse.success !== true || !Array.isArray(containersResponse.containers)) {
                  console.error("getContainers response failed validation or is not structured as expected:", containersResponse);
                  const errorMessage = containersResponse?.message || 'Failed to fetch containers or unexpected response format.';
                  throw new Error(errorMessage);
             }
            const allContainers = containersResponse.containers;
            console.log(`Found ${allContainers.length} containers.`);

             // Handle case of no containers
             if (allContainers.length === 0) {
                 throw new Error("No containers found in the system. Cannot run placement.");
             }

            // --- Step 3: Call Placement API ---
            const payload = { items: availableItems, containers: allContainers };
            console.log(`Sending placement payload with ${payload.items.length} items and ${payload.containers.length} containers.`);
            const result = await placeItems(payload); // Call API
            setPlacementResult(result); // Store result in state immediately
            console.log("Placement API result:", result);

            // --- Step 4: Check Placement Result and Log (if successful) ---
             if (result.success === false) {
                  // Handle placement API failure
                  throw new Error(result.message || "Placement algorithm failed on the backend.");
             } else {
                 // Placement API call was successful (result.success === true)
                 console.log("Placement successful, attempting to log action via API...");
                 try {
                     // Prepare details for logging
                     const logDetails = {
                         success: true, // Indicate placement itself was successful
                         itemsConsidered: itemsConsideredCount,
                         itemsPlaced: result.placements?.length || 0,
                         rearrangements: result.rearrangements?.length || 0,
                         // Include unplaced count if backend provides it, otherwise default to 0 or calculate
                         unplacedItems: result.unplacedItems?.length ?? (itemsConsideredCount - (result.placements?.length || 0)),
                         message: result.message || "Placement attempt finished." // Include any message
                     };
                     // Call the logAction API endpoint
                     await logAction({
                         actionType: 'placement',
                         userId: 'System_Placement', // Use a system identifier or user ID if available
                         details: logDetails // Send the collected details
                     });
                     console.log("Placement action logged successfully via API.");
                 } catch (logError) {
                     // If logging fails, log the error but don't fail the operation
                     console.error("Failed to log placement action via API:", logError);
                     // Don't throw here, allow the successful placement result to be shown
                 }
             }

        } catch (error) { // Catches errors from getItems, getContainers, placeItems, or explicit throws
            console.error("Placement process failed inside catch block:", error);
            setPlacementError(error.message || "An unknown error occurred during placement.");
            setPlacementResult(null); // Clear any potentially misleading partial result
        } finally {
            setIsPlacing(false); // Ensure loading state is turned off regardless of outcome
        }
    };


    // --- Simple Styles (can be moved to CSS) ---
    const sectionStyle = { marginBottom: '25px', padding: '15px', border: '1px solid #eee', borderRadius: '5px', backgroundColor: '#f9f9f9' };
    const formGroupStyle = { marginBottom: '15px' };
    const labelStyle = { marginRight: '10px', display: 'inline-block', minWidth: '150px', fontWeight: 'bold'};
    const buttonStyle = { padding: '10px 18px', cursor: 'pointer', backgroundColor: '#007bff', color: 'white', border: 'none', borderRadius: '4px', fontSize: '1em' };
    const buttonDisabledStyle = { ...buttonStyle, backgroundColor: '#cccccc', cursor: 'not-allowed' };
    const inputStyle = { padding: '8px', border: '1px solid #ccc', borderRadius: '4px' };
    const messageBaseStyle = { marginTop: '15px', border: '1px solid', padding: '12px', borderRadius: '4px', wordBreak: 'break-word' };
    const errorMessageStyle = { ...messageBaseStyle, color: '#721c24', backgroundColor: '#f8d7da', borderColor: '#f5c6cb' };
    const successMessageStyle = { ...messageBaseStyle, color: '#155724', backgroundColor: '#d4edda', borderColor: '#c3e6cb' };
    const listStyle = { listStyle: 'disc', paddingLeft: '25px', margin: '5px 0 0 0' };
    const preStyle = { whiteSpace: 'pre-wrap', wordBreak: 'break-all', maxHeight: '200px', overflowY: 'auto', backgroundColor: '#eee', padding: '5px', marginTop: '5px', borderRadius: '3px' };


    // --- JSX Rendering ---
    return (
        <div style={{ padding: '20px', fontFamily: 'Arial, sans-serif', maxWidth: '800px', margin: 'auto' }}>
            <h1>Import Data & Placement</h1>
            <p style={{ marginBottom: '20px', color: '#555' }}>
                Upload CSV files for containers and items. After importing, run the placement algorithm
                to assign available items to suitable containers.
            </p>

            {/* Container Import Form */}
            <section style={sectionStyle}>
                <h2>1. Import Containers</h2>
                <form onSubmit={handleContainerSubmit}>
                    <div style={formGroupStyle}>
                        <label htmlFor="containerFile" style={labelStyle}>Container CSV File:</label>
                        <input
                            style={inputStyle}
                            type="file"
                            id="containerFile"
                            accept=".csv, text/csv"
                            onChange={handleContainerFileChange}
                            disabled={isContainerImporting}
                        />
                    </div>
                    <button
                        type="submit"
                        style={isContainerImporting || !containerFile ? buttonDisabledStyle : buttonStyle}
                        disabled={isContainerImporting || !containerFile}
                    >
                        {isContainerImporting ? 'Importing...' : 'Upload Containers'}
                    </button>
                </form>
                {isContainerImporting && <p style={{ marginTop: '10px', fontStyle: 'italic' }}>Importing containers, please wait...</p>}
                {containerImportError && !containerImportResult && (
                    <p style={errorMessageStyle}>Error: {containerImportError}</p>
                )}
                {containerImportResult && (
                    <div style={containerImportResult.success ? successMessageStyle : errorMessageStyle}>
                        <p style={{ fontWeight: 'bold' }}>Container Import {containerImportResult.success ? 'Finished' : 'Failed'}</p>
                        {containerImportResult.message && <p>{containerImportResult.message}</p>}
                         {containerImportResult.containersImported != null && <p>Containers Processed/Imported: {containerImportResult.containersImported}</p>}
                         {containerImportResult.skippedCount != null && <p>Rows Skipped (Validation/Duplicates/Other): {containerImportResult.skippedCount}</p>}
                         {containerImportResult.errors && containerImportResult.errors.length > 0 && (
                             <div>
                                 <p style={{ fontWeight: 'bold', marginTop: '8px' }}>Import Errors ({containerImportResult.errors.length} total):</p>
                                 <ul style={listStyle}>
                                     {containerImportResult.errors.slice(0, 10).map((err, index) => (
                                         <li key={index}>
                                             Row {err.row || 'N/A'}: {err.message}
                                             {err.details && <pre style={preStyle}>{JSON.stringify(err.details, null, 2)}</pre>}
                                         </li>
                                     ))}
                                     {containerImportResult.errors.length > 10 && <li>... and {containerImportResult.errors.length - 10} more errors (check server logs for full details).</li>}
                                 </ul>
                             </div>
                         )}
                    </div>
                )}
            </section>

            <hr style={{ margin: '30px 0', border: '0', borderTop: '1px solid #ccc' }}/>

            {/* Item Import Form */}
            <section style={sectionStyle}>
                <h2>2. Import Items</h2>
                <form onSubmit={handleItemSubmit}>
                    <div style={formGroupStyle}>
                        <label htmlFor="itemFile" style={labelStyle}>Item CSV File:</label>
                        <input
                            style={inputStyle}
                            type="file"
                            id="itemFile"
                            accept=".csv, text/csv"
                            onChange={handleItemFileChange}
                            disabled={isItemImporting}
                        />
                    </div>
                    <button
                        type="submit"
                        style={isItemImporting || !itemFile ? buttonDisabledStyle : buttonStyle}
                        disabled={isItemImporting || !itemFile}
                    >
                        {isItemImporting ? 'Importing...' : 'Upload Items'}
                    </button>
                </form>
                 {isItemImporting && <p style={{ marginTop: '10px', fontStyle: 'italic' }}>Importing items, please wait...</p>}
                 {itemImportError && !itemImportResult && (
                    <p style={errorMessageStyle}>Error: {itemImportError}</p>
                 )}
                {itemImportResult && (
                     <div style={itemImportResult.success ? successMessageStyle : errorMessageStyle}>
                        <p style={{ fontWeight: 'bold' }}>Item Import {itemImportResult.success ? 'Finished' : 'Failed'}</p>
                        {itemImportResult.message && <p>{itemImportResult.message}</p>}
                        {itemImportResult.insertedCount != null && <p>Items Imported/Updated: {itemImportResult.insertedCount}</p>}
                        {itemImportResult.skippedCount != null && <p>Items Skipped (Validation/Duplicates/Other): {itemImportResult.skippedCount}</p>}
                        {itemImportResult.errors && itemImportResult.errors.length > 0 && (
                            <div>
                                <p style={{ fontWeight: 'bold', marginTop: '8px' }}>Import Errors ({itemImportResult.errors.length} total):</p>
                                <ul style={listStyle}>
                                    {itemImportResult.errors.slice(0, 10).map((error, index) => (
                                        <li key={index}>
                                            Row {error.row || 'N/A'}: {error.message}
                                            {error.details && <pre style={preStyle}>{JSON.stringify(error.details, null, 2)}</pre>}
                                        </li>
                                    ))}
                                     {itemImportResult.errors.length > 10 && <li>... and {itemImportResult.errors.length - 10} more errors (check server logs for full details).</li>}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </section>

            <hr style={{ margin: '30px 0', border: '0', borderTop: '1px solid #ccc' }}/>

            {/* Placement Section */}
            <section style={sectionStyle}>
                <h2>3. Run Placement Algorithm</h2>
                <p style={{ color: '#555' }}>
                    Click below to attempt placement for all items currently marked as 'available'
                    using the containers currently in the system. This may take a few moments.
                </p>
                 <button
                     onClick={handleRunPlacement}
                     disabled={isPlacing || isItemImporting || isContainerImporting}
                     style={(isPlacing || isItemImporting || isContainerImporting) ? buttonDisabledStyle : buttonStyle}
                 >
                     {isPlacing ? 'Placing Items...' : 'Run Placement Algorithm'}
                 </button>

                {isPlacing && <p style={{ marginTop: '10px', fontStyle: 'italic' }}>Running placement algorithm, please wait...</p>}
                {/* Display Error OR Success Result for Placement */}
                {/* Show error only if it exists AND there's no result object (or result failed) */}
                {placementError && (!placementResult || !placementResult.success) && (
                    <p style={errorMessageStyle}>Placement Error: {placementError}</p>
                )}
                {/* Display placement results */}
                {placementResult && (
                    <div style={placementResult.success ? successMessageStyle : errorMessageStyle}>
                        <p style={{ fontWeight: 'bold' }}>Placement {placementResult.success ? 'Attempt Finished' : 'Attempt Failed'}</p>
                         {placementResult.message && <p>{placementResult.message}</p>}
                        {/* Only show counts if placement was technically successful, even if 0 items placed */}
                        {placementResult.success && (
                            <>
                                {/* Use optional chaining and nullish coalescing for safety */}
                                <p>Items Placed: {placementResult.placements?.length ?? 0}</p>
                                {/* Check if unplacedItems exists and is an array */}
                                {Array.isArray(placementResult.unplacedItems) && <p>Items Unable to Place: {placementResult.unplacedItems.length}</p> }
                                <p>Rearrangements Suggested: {placementResult.rearrangements?.length ?? 0}</p>
                                {/* Display unplaced item IDs if available */}
                                {Array.isArray(placementResult.unplacedItems) && placementResult.unplacedItems.length > 0 && (
                                    <div>
                                        <p style={{marginTop: '5px'}}>Unplaced Item IDs ({placementResult.unplacedItems.length}):</p>
                                        {/* Display first few IDs */}
                                        <pre style={{...preStyle, maxHeight:'100px'}}>
                                            {placementResult.unplacedItems.slice(0, 20).map(item => item.itemId || item.id || 'N/A').join(', ')}
                                            {placementResult.unplacedItems.length > 20 ? ' ...' : ''}
                                        </pre>
                                    </div>
                                )}
                            </>
                        )}
                        {/* Display specific backend errors if success is false */}
                         {!placementResult.success && placementResult.error && (
                             <p>Details: {placementResult.error}</p>
                         )}
                    </div>
                )}
            </section>
        </div>
    );
}

export default ImportDataPage;
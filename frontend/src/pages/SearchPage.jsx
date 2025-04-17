// frontend/src/pages/SearchPage.jsx
import React, { useState, useEffect } from 'react';
// Import logAction along with the others
import { searchItems, retrieveItem, manualPlaceItem, logAction } from '../services/api';

// Removed CSS import as the file doesn't exist
// import './SearchPage.css';

function SearchPage() {
    const [searchTerm, setSearchTerm] = useState('');
    const [searchResult, setSearchResult] = useState(null);
    const [isLoading, setIsLoading] = useState(false); // Combined loading state for simplicity
    const [error, setError] = useState(null); // General error display
    const [showManualPlaceForm, setShowManualPlaceForm] = useState(false);
    const [manualPlaceData, setManualPlaceData] = useState({
        containerId: '',
        startX: '', startY: '', startZ: '',
        endX: '', endY: '', endZ: ''
    });
    const [manualPlaceMessage, setManualPlaceMessage] = useState(''); // Specific message for place form


    const handleSearch = async (e) => {
        e.preventDefault();
        const term = searchTerm.trim(); // Trim once at the beginning

        if (!term) {
            setError("Please enter an Item ID or Name to search.");
            setSearchResult(null);
            return;
        }
        setIsLoading(true);
        setError(null);
        setSearchResult(null);
        setShowManualPlaceForm(false);
        setManualPlaceMessage('');

        const searchParams = {};

        // --- CORRECTED LOGIC: Check for ITM prefix OR if it's purely numeric ---
        // Determines if the search term looks like a potential ID (ITM... or all digits)
        // Adjust this regex if your ID formats change significantly (e.g., contain dashes)
        if (/^ITM\d+$/i.test(term) || /^\d+$/.test(term)) {
             // If it starts with ITM... OR if it's purely numeric, treat as itemId
             searchParams.itemId = term;
        } else {
             // Otherwise, treat it as an itemName for a LIKE search
             searchParams.itemName = term;
        }
        // --- END CORRECTION ---

        console.log("Search Parameters being sent:", searchParams); // Verify this log

        try {
            const response = await searchItems(searchParams);
            console.log("API Response:", response);

            if (response.success && response.found && response.item) {
                setSearchResult(response);
            } else if (response.success && !response.found) {
                 setError("No item found matching the criteria.");
            } else {
                 setError(response.error || response.message || "Search failed. Unexpected response.");
            }
        } catch (err) {
            console.error("Search API Error:", err);
            setError(err.message || "An error occurred during search.");
            setSearchResult(null); // Clear results on error too
        } finally {
            setIsLoading(false);
        }
    };

    const handleRetrieve = async () => {
        if (!searchResult || !searchResult.item) return;

        const originalItemState = { ...searchResult.item };

        setIsLoading(true);
        setError(null); // Clear general error
        // Use alert for now, replace with better UI feedback later

        try {
             const retrievePayload = {
                 itemId: originalItemState.itemId,
                 userId: 'astronaut_01', // Replace with actual user ID if available
                 timestamp: new Date().toISOString()
             };
             // --- Call the retrieve API ---
             const retrieveResponse = await retrieveItem(retrievePayload);
             // <<< --- ADDED DEBUG LOG 1 --- >>>
             console.log("Retrieve API Response:", retrieveResponse);

             // --- CORRECTED: Check for success BEFORE logging ---
             if (retrieveResponse.success) {
                 // --- Step 2: Log the Action ONLY on Success ---
                 const logPayload = {
                     userId: 'astronaut_01',
                     actionType: 'retrieve',
                     itemId: originalItemState.itemId, // Use original state for context
                     details: {
                         retrievedBy: 'astronaut_01',
                         previousStatus: originalItemState.status, // Should be 'stowed' if we got here
                         previousRemainingUses: originalItemState.remainingUses,
                         newStatus: retrieveResponse.newState?.status ?? 'retrieved', // Get from successful response
                         newRemainingUses: retrieveResponse.newState?.remainingUses ?? null // Get from successful response
                     }
                 };

                 // <<< --- ADDED DEBUG LOG 2 --- >>>
                 console.log("Calling logAction with payload:", logPayload);

                 // --- Call the log action API ---
                 try {
                     const logResult = await logAction(logPayload);
                     if (logResult.success) {
                         alert('Retrieve successful: Item updated successfully. Logging successful.'); // Simple alert for now
                     } else {
                         alert(`Retrieve successful: Item updated successfully. BUT logging failed: ${logResult.message || 'Unknown error'}`);
                     }
                 } catch (logErr) {
                      alert(`Retrieve successful: Item updated successfully. BUT logging failed: ${logErr.message || 'Network error'}`);
                 }

                 // Clear results after successful update (regardless of log status)
                 setSearchResult(null);
                 setSearchTerm('');

             } else {
                 // --- Handle Retrieve API Failure ---
                 // Retrieve (update) failed - DO NOT log
                 setError(retrieveResponse.message || "Failed to retrieve item (API Error).");
                 // Do not clear search result here, so user sees the error context
             }
        } catch (err) {
             console.error("Retrieve Process Error:", err);
             setError(err.message || "An error occurred during the retrieval process.");
        } finally {
            setIsLoading(false);
        }
    };

     const handleShowManualPlace = () => {
        if (!searchResult || !searchResult.item) return;
        setManualPlaceData({
            containerId: '',
            startX: '', startY: '', startZ: '',
            endX: '', endY: '', endZ: ''
        });
        setShowManualPlaceForm(true);
        setManualPlaceMessage(''); // Clear previous messages
        setError(''); // Clear general errors
    };

    const handleManualPlaceInputChange = (e) => {
        const { name, value } = e.target;
        setManualPlaceData(prev => ({ ...prev, [name]: value }));
    };

    // --- handleManualPlaceSubmit with Logging ---
    const handleManualPlaceSubmit = async (e) => {
         e.preventDefault();
         if (!searchResult || !searchResult.item) return; // Need item context

         setIsLoading(true);
         setManualPlaceMessage('');
         setError(''); // Clear general errors

         const placeUserId = 'astronaut_02'; // User performing manual place

         // Construct the payload for /api/place
         const placePayload = {
             itemId: searchResult.item.itemId,
             containerId: manualPlaceData.containerId,
             position: {
                 startCoordinates: {
                     width: parseFloat(manualPlaceData.startX) || 0,
                     depth: parseFloat(manualPlaceData.startY) || 0,
                     height: parseFloat(manualPlaceData.startZ) || 0,
                 },
                 endCoordinates: {
                     width: parseFloat(manualPlaceData.endX) || 0,
                     depth: parseFloat(manualPlaceData.endY) || 0,
                     height: parseFloat(manualPlaceData.endZ) || 0,
                 }
             },
             userId: placeUserId,
             timestamp: new Date().toISOString()
         };

         console.log("Submitting Manual Place Payload:", placePayload);

         try {
             // --- Step 1: Call the place API ---
             const placeResponse = await manualPlaceItem(placePayload);

             if (placeResponse.success) {
                 // --- Step 2: If placement succeeded, call the log API ---
                 const placedW = placePayload.position.endCoordinates.width - placePayload.position.startCoordinates.width;
                 const placedD = placePayload.position.endCoordinates.depth - placePayload.position.startCoordinates.depth;
                 const placedH = placePayload.position.endCoordinates.height - placePayload.position.startCoordinates.height;

                 const logPayload = {
                    userId: placeUserId,
                    actionType: 'manual_place', // Specific action type
                    itemId: placePayload.itemId,
                    details: {
                        placedBy: placeUserId,
                        containerId: placePayload.containerId,
                        position: placePayload.position, // Log the full position object
                        placedDimensions: { w: placedW.toFixed(2), d: placedD.toFixed(2), h: placedH.toFixed(2) } // Log calculated dimensions (formatted)
                    }
                 };

                 // Debug log for manual place (already existed in your original)
                 console.log("Calling logAction with payload:", logPayload);

                 try {
                     const logResult = await logAction(logPayload);
                     if (logResult.success) {
                         // Both succeeded!
                         setManualPlaceMessage(`Success: Item ${placePayload.itemId} placed and action logged.`);
                         // Clear everything on full success
                         setShowManualPlaceForm(false);
                         setSearchResult(null);
                         setSearchTerm('');
                     } else {
                         // Placement succeeded, logging failed
                         setManualPlaceMessage(`Partial Success: Item ${placePayload.itemId} placed, BUT logging failed: ${logResult.message || 'Unknown error'}`);
                         // Still clear form/results after partial success
                         setShowManualPlaceForm(false);
                         setSearchResult(null);
                         setSearchTerm('');
                     }
                 } catch (logErr) {
                     // Placement succeeded, logging failed (network/other error)
                      setManualPlaceMessage(`Partial Success: Item ${placePayload.itemId} placed, BUT logging failed: ${logErr.message || 'Network error'}`);
                      // Still clear form/results after partial success
                      setShowManualPlaceForm(false);
                      setSearchResult(null);
                      setSearchTerm('');
                 }
             } else {
                 // Placement itself failed
                 setManualPlaceMessage(placeResponse.error || 'Failed to place item.');
             }
         } catch (err) {
             console.error("Manual Place Process Error:", err);
             setManualPlaceMessage(err.message || 'An error occurred during manual placement process.');
         } finally {
             setIsLoading(false);
         }
    };
    // --- END handleManualPlaceSubmit ---


    const getPlacedDimensions = () => {
        if (!searchResult?.item?.position?.startCoordinates || !searchResult?.item?.position?.endCoordinates) {
            return 'N/A';
        }
        const start = searchResult.item.position.startCoordinates;
        const end = searchResult.item.position.endCoordinates;
        const isValidNumber = (...nums) => nums.every(num => typeof num === 'number' && !isNaN(num));
        if (!isValidNumber(start.width, start.depth, start.height, end.width, end.depth, end.height)) {
            return 'Invalid Coords';
        }
        const placedWidth = (end.width - start.width).toFixed(1);
        const placedDepth = (end.depth - start.depth).toFixed(1);
        const placedHeight = (end.height - start.height).toFixed(1);
        if (placedWidth < 0 || placedDepth < 0 || placedHeight < 0) {
             console.warn("Calculated negative placed dimensions:", {placedWidth, placedDepth, placedHeight, start, end});
             return 'Calc Error';
        }
        return `${placedWidth} x ${placedDepth} x ${placedHeight}`;
    };

    // Using basic inline styles as SearchPage.css was removed
    return (
        <div style={{ padding: '20px', fontFamily: 'sans-serif' }}>
            <h1>Search Item / Retrieve / Manual Place</h1>

            <form onSubmit={handleSearch} style={{ marginBottom: '20px' }}>
                <input
                    type="text" // Keep as text to preserve leading zeros
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    placeholder="Enter Item ID or Name"
                    aria-label="Search term"
                    style={{ marginRight: '10px', padding: '8px', minWidth: '250px', fontSize: '1rem' }}
                    disabled={isLoading}
                />
                <button type="submit" disabled={isLoading} style={{ padding: '8px 15px', fontSize: '1rem' }}>
                    {isLoading ? 'Searching...' : 'Search'}
                </button>
            </form>

            {/* General Error Display Area */}
            {error && <p style={{ color: 'red', marginTop: '15px', border: '1px solid red', padding: '10px', backgroundColor: '#ffebee' }}>Error: {error}</p>}

            {/* Loading Indicator for Search */}
            {isLoading && !searchResult && <p style={{ marginTop: '15px' }}>Loading search results...</p>}

            {/* Search Result Display Area */}
            {searchResult && searchResult.found && searchResult.item && (
                 <div style={{ marginTop: '20px', border: '1px solid #ccc', padding: '15px', borderRadius: '5px', backgroundColor: '#f9f9f9' }}>
                    <h2>Search Result</h2>
                    {/* --- Display Item Details --- */}
                    <div style={{ display: 'grid', gridTemplateColumns: '150px auto', gap: '5px 10px', marginBottom: '15px' }}>
                        <strong>ID:</strong>               <span>{searchResult.item.itemId}</span>
                        <strong>Name:</strong>             <span>{searchResult.item.name}</span>
                        <strong>Container:</strong>        <span>{searchResult.item.containerId || 'N/A'}</span>
                        <strong>Zone:</strong>             <span>{searchResult.item.zone || 'N/A'}</span>
                        <strong>Position (Start):</strong> <span>
                            W: {searchResult.item.position?.startCoordinates?.width?.toFixed(1) ?? 'N/A'},{' '}
                            D: {searchResult.item.position?.startCoordinates?.depth?.toFixed(1) ?? 'N/A'},{' '}
                            H: {searchResult.item.position?.startCoordinates?.height?.toFixed(1) ?? 'N/A'}
                        </span>
                        <strong>Dimensions (Placed):</strong> <span>W x D x H: {getPlacedDimensions()}</span>
                        <strong>Mass:</strong>             <span>{searchResult.item.mass ? `${searchResult.item.mass} kg` : 'N/A'}</span>
                        <strong>Uses Remaining:</strong>   <span>{searchResult.item.remainingUses ?? 'N/A (Unlimited)'}</span>
                        <strong>Expiry Date:</strong>      <span>{searchResult.item.expiryDate || 'N/A'}</span>
                        <strong>Status:</strong>           <span style={{ fontWeight: 'bold', color: searchResult.item.status === 'stowed' ? 'green' : 'orange' }}>{searchResult.item.status || 'N/A'}</span>
                        <strong>Priority:</strong>         <span>{searchResult.item.priority ?? 'N/A'}</span>
                        <strong>Last Updated:</strong>     <span>{searchResult.item.lastUpdated ? new Date(searchResult.item.lastUpdated).toLocaleString() : 'N/A'}</span>
                    </div>


                    {/* --- Display Retrieval Steps --- */}
                    <h3>Retrieval Steps ({searchResult.retrievalSteps?.length || 0}):</h3>
                    {searchResult.retrievalSteps && searchResult.retrievalSteps.length > 0 ? (
                        <ol style={{ paddingLeft: '20px', marginBottom: '15px', border: '1px dashed #aaa', padding: '10px', backgroundColor: 'white' }}>
                            {searchResult.retrievalSteps.map((step) => (
                                <li key={step.step} style={{ marginBottom: '5px', color: step.action === 'retrieve' ? '#d32f2f' : '#333' }}>
                                    <strong>Step {step.step}:</strong> {step.action === 'remove' ? 'Remove Obstruction' : 'Retrieve Target'}:
                                    Item <code>{step.itemId}</code> ({step.itemName})
                                    {step.action === 'remove' && step.position?.startCoordinates && (
                                        <span style={{fontSize: '0.8em', color: '#555'}}> (at W: {step.position.startCoordinates.width.toFixed(1)}, D: {step.position.startCoordinates.depth.toFixed(1)})</span>
                                    )}
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <p style={{ marginBottom: '15px', fontStyle: 'italic' }}>
                            {searchResult.item.status === 'stowed' ? 'No obstructions. Item is directly accessible.' : 'Item not currently stowed.'}
                         </p>
                    )}

                    {/* --- Action Buttons --- */}
                    <div style={{ marginTop: '15px', borderTop: '1px solid #eee', paddingTop: '15px' }}>
                         {/* Only show retrieve button if item is stowed and not already in manual place mode */}
                         {searchResult.item.status === 'stowed' && (
                             <button
                                onClick={handleRetrieve}
                                disabled={isLoading || showManualPlaceForm}
                                style={{ padding: '8px 15px', marginRight: '10px', backgroundColor: '#4CAF50', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                             >
                                Confirm Item Retrieved
                             </button>
                         )}
                         {/* Only show manual place button if not already in manual place mode */}
                         <button
                            onClick={handleShowManualPlace}
                            disabled={isLoading || showManualPlaceForm}
                            style={{ padding: '8px 15px', backgroundColor: '#2196F3', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                         >
                            Manually Place This Item
                         </button>
                    </div>

                    {/* --- Manual Placement Form --- */}
                    {showManualPlaceForm && (
                        <div style={{ marginTop: '20px', borderTop: '2px solid #2196F3', paddingTop: '15px', backgroundColor: '#e3f2fd', padding: '15px', borderRadius: '5px' }}>
                            <h3>Manual Placement for {searchResult.item.itemId}</h3>
                             {/* Message area specific to manual placement */}
                             {manualPlaceMessage && <p style={{ marginTop: '10px', marginBottom:'15px', padding:'10px', border: `1px solid ${manualPlaceMessage.includes('Success:') ? 'green' : 'red'}`, color: manualPlaceMessage.includes('Success:') ? 'darkgreen' : 'darkred', backgroundColor: manualPlaceMessage.includes('Success:') ? '#c8e6c9' : '#ffcdd2', borderRadius: '4px' }}>{manualPlaceMessage}</p>}
                            <form onSubmit={handleManualPlaceSubmit}>
                                <div style={{ marginBottom: '10px' }}>
                                    <label htmlFor="containerId" style={{ marginRight: '5px', display: 'inline-block', width: '100px', fontWeight: 'bold' }}>Container ID:</label>
                                    <input type="text" id="containerId" name="containerId" value={manualPlaceData.containerId} onChange={handleManualPlaceInputChange} required disabled={isLoading} style={{ padding: '5px' }} />
                                </div>
                                <fieldset style={{ border: '1px solid #ccc', padding: '10px 15px', marginBottom: '15px', backgroundColor: 'white' }}>
                                    <legend style={{ fontWeight: 'bold' }}>Occupied Box Coordinates</legend>
                                    <p style={{fontSize: '0.9em', color: '#555', marginTop: '0', marginBottom: '10px'}}>Enter the start (min W, D, H) and end (max W, D, H) coordinates defining the box.</p>
                                    {/* Grouping inputs for better layout */}
                                    <div style={{ display: 'grid', gridTemplateColumns: '120px 100px 120px 100px', gap: '10px 15px', alignItems: 'center' }}>
                                        <label htmlFor="startX">Start W (X):</label>
                                        <input type="number" step="any" id="startX" name="startX" value={manualPlaceData.startX} onChange={handleManualPlaceInputChange} required style={{ width: '90px', padding: '5px' }} disabled={isLoading}/>

                                        <label htmlFor="endX">End W (X):</label>
                                        <input type="number" step="any" id="endX" name="endX" value={manualPlaceData.endX} onChange={handleManualPlaceInputChange} required style={{ width: '90px', padding: '5px' }} disabled={isLoading}/>

                                        <label htmlFor="startY">Start D (Y):</label>
                                        <input type="number" step="any" id="startY" name="startY" value={manualPlaceData.startY} onChange={handleManualPlaceInputChange} required style={{ width: '90px', padding: '5px' }} disabled={isLoading}/>

                                        <label htmlFor="endY">End D (Y):</label>
                                        <input type="number" step="any" id="endY" name="endY" value={manualPlaceData.endY} onChange={handleManualPlaceInputChange} required style={{ width: '90px', padding: '5px' }} disabled={isLoading}/>

                                        <label htmlFor="startZ">Start H (Z):</label>
                                        <input type="number" step="any" id="startZ" name="startZ" value={manualPlaceData.startZ} onChange={handleManualPlaceInputChange} required style={{ width: '90px', padding: '5px' }} disabled={isLoading} />

                                        <label htmlFor="endZ">End H (Z):</label>
                                        <input type="number" step="any" id="endZ" name="endZ" value={manualPlaceData.endZ} onChange={handleManualPlaceInputChange} required style={{ width: '90px', padding: '5px' }} disabled={isLoading}/>
                                    </div>
                                </fieldset>
                                <button type="submit" disabled={isLoading} style={{ padding: '8px 15px', backgroundColor: '#ff9800', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
                                    {isLoading ? 'Placing...' : 'Confirm Manual Placement'}
                                 </button>
                            </form>
                        </div>
                    )}
                 </div>
            )} {/* End Search Result Display Area */}

        </div> // Closing div for the main component wrapper
    ); // Closing parenthesis for return
} // Closing brace for SearchPage function

export default SearchPage;
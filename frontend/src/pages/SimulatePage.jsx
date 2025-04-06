// frontend/src/pages/SimulatePage.jsx
import React, { useState } from 'react';
import { simulateDay, logAction } from '../services/api'; // <-- Import logAction

function SimulatePage() {
    // State for simulation parameters
    const [numDays, setNumDays] = useState('1');
    const [toTimestamp, setToTimestamp] = useState(''); // Optional: Add input if needed

    // State for building the list of items to use
    const [currentItemId, setCurrentItemId] = useState('');
    const [currentQuantity, setCurrentQuantity] = useState('1');
    const [itemsToUseList, setItemsToUseList] = useState([]); // Array of { id: uniqueKey, itemId: string, quantity: number }

    // State for API results
    const [simResult, setSimResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    // Handler for NumDays/Timestamp changes
    const handleDayOrTimestampChange = (e) => {
        const { name, value } = e.target;
        if (name === 'numDays') {
            setNumDays(value);
            setToTimestamp(''); // Clear timestamp if days are entered
        } else if (name === 'toTimestamp') {
            setToTimestamp(value);
            setNumDays(''); // Clear days if timestamp is entered
        }
    };

    // Handlers for the "item to add" form
    const handleItemIdChange = (e) => setCurrentItemId(e.target.value);
    const handleQuantityChange = (e) => setCurrentQuantity(e.target.value);

    // Add item to the list
    const handleAddItemToList = (e) => {
        e.preventDefault(); // Prevent form submission if inside a form
        const trimmedItemId = currentItemId.trim();
        const quantity = parseInt(currentQuantity, 10);

        if (!trimmedItemId) {
            setError("Please enter an Item ID to add.");
            return;
        }
        if (isNaN(quantity) || quantity <= 0) {
             setError("Quantity must be a positive whole number.");
             return;
        }

        setItemsToUseList(prevList => [
            ...prevList,
            { id: Date.now() + Math.random(), itemId: trimmedItemId, quantity: quantity }
        ]);
        setCurrentItemId('');
        setCurrentQuantity('1');
        setError(null);
    };

    // Remove item from the list
    const handleRemoveItemFromList = (idToRemove) => {
        setItemsToUseList(prevList => prevList.filter(item => item.id !== idToRemove));
    };


    // Handler for running the simulation
    const handleSimulate = async (e) => {
        e.preventDefault();
        setLoading(true);
        setError(null);
        setSimResult(null);

        // --- Capture inputs for logging BEFORE the try block potentially modifies them ---
        const simulationInputDays = numDays;
        const simulationInputTimestamp = toTimestamp;
        // Map the list state to the structure needed by API & logging
        const simulationInputItemsUsed = itemsToUseList.map(({ itemId, quantity }) => ({ itemId, quantity }));

        try {
            // Validate days or timestamp
            const days = parseInt(simulationInputDays, 10);
            if (simulationInputTimestamp && !/^\d{4}-\d{2}-\d{2}/.test(simulationInputTimestamp)) {
                throw new Error("Target date must be in YYYY-MM-DD format.");
            }
            if (!simulationInputTimestamp && (isNaN(days) || days <= 0)) {
                 throw new Error("Number of days must be a positive integer if no target date is set.");
            }
            if(simulationInputTimestamp && !isNaN(days) && days > 0) {
                 throw new Error("Provide Number of Days OR Target Date, not both.");
            }

            // Prepare payload using the captured inputs
            const payload = {
                 ...(days > 0 && !simulationInputTimestamp && { numOfDays: days }),
                 ...(simulationInputTimestamp && { toTimestamp: simulationInputTimestamp }),
                 itemsToBeUsedPerDay: simulationInputItemsUsed // Use the mapped array
            };

            console.log("Simulation Payload:", payload);

            // --- Call the Simulation API ---
            const result = await simulateDay(payload);
            setSimResult(result); // Store full result

             if (!result.success) {
                 // Handle API returning success: false but call finished
                 throw new Error(result.message || "Simulation API returned failure.");
             }

            // --- >>> ADD LOGGING CALL HERE on SUCCESS <<< ---
            console.log("Simulation successful, attempting to log action via API...");
            try {
                 const logDetails = {
                     success: true, // Simulation API call was successful
                     inputParameters: { // Log the parameters used
                         ...(days > 0 && { numOfDays: days }),
                         ...(simulationInputTimestamp && { toTimestamp: simulationInputTimestamp }),
                         itemsUsedInput: simulationInputItemsUsed
                     },
                     results: { // Log the results obtained
                        newDate: result.newDate,
                        changes: result.changes // Include the detailed changes object
                     },
                     message: result.message // Include any message from simulation API
                 };

                await logAction({
                    actionType: 'simulate',
                    userId: 'System_Simulation', // Or derive from user input/context
                    itemId: null, // Simulation affects multiple/no specific item
                    details: logDetails
                });
                console.log("Simulation action logged successfully via API.");

            } catch (logError) {
                 // Log the error but don't block the UI from showing simulation success
                 console.error("Failed to log simulation action:", logError);
            }
            // --- >>> END LOGGING CALL <<< ---

        } catch (err) { // Catches errors from validation or simulateDay call
            console.error("Simulation failed:", err);
            setError(`Simulation failed: ${err.message}`);
             setSimResult(null); // Clear results on error
        } finally {
            setLoading(false);
        }
    };

    // Helper to render changes nicely
    const renderChanges = (changes) => {
        // ...(renderChanges function remains the same)...
        if (!changes) return <p>No changes reported.</p>;
        return (
            <div>
                {Object.entries(changes).map(([key, items]) => (
                     items && items.length > 0 && (
                         <div key={key} style={{marginTop: '10px'}}>
                             <h5 style={{textTransform: 'capitalize', marginBottom:'5px'}}>{key.replace(/([A-Z])/g, ' $1').trim()}:</h5>
                             <ul style={{margin:0, paddingLeft:'20px', fontSize:'0.9em'}}>
                                 {items.map((item, index) => (
                                     <li key={`${key}-${item.itemId || index}-${index}`}> {/* Use index if itemId might be missing */}
                                         {item.itemId || 'Unknown ID'} ({item.name || 'Unknown Name'})
                                         {item.remainingUses != null && ` - Uses Left: ${item.remainingUses}`}
                                     </li>
                                 ))}
                             </ul>
                         </div>
                    )
                ))}
                {Object.values(changes).every(arr => arr == null || arr.length === 0) && <p>No specific item changes recorded for this simulation period.</p>}
            </div>
        );
    };

  return (
    <div style={{ padding: '20px', fontFamily: 'sans-serif' }}>
      <h2>Simulate Time Progression</h2>

      {/* Simulation Parameters Form */}
      {/* ...(JSX for form remains the same)... */}
       <form onSubmit={handleSimulate} style={{ border: '1px solid #eee', padding: '15px', marginBottom: '20px', backgroundColor: '#f9f9f9', borderRadius: '5px' }}>
         <h4>Simulation Controls</h4>
        <div style={{ marginBottom: '10px' }}>
          <label style={{ marginRight: '5px' }}>Simulate for: </label>
          <input
            type="number"
            name="numDays"
            value={numDays}
            onChange={handleDayOrTimestampChange}
            min="1"
            style={{ width: '80px', padding: '5px', marginRight: '10px' }}
            disabled={loading || !!toTimestamp} // Disable if timestamp is entered
          />
          <label> days</label>
        </div>
        <div style={{ marginBottom: '10px' }}>
           <span style={{ marginRight: '5px', fontStyle: 'italic' }}>OR</span>
        </div>
        <div style={{ marginBottom: '15px' }}>
           <label style={{ marginRight: '5px' }}>Simulate until Date: </label>
           <input
             type="date"
             name="toTimestamp" // Name matches state key
             value={toTimestamp}
             onChange={handleDayOrTimestampChange}
             style={{ padding: '5px' }}
             disabled={loading || (numDays !== '' && parseInt(numDays,10) > 0)} // Disable if numDays entered
           /> (YYYY-MM-DD)
        </div>

        {/* Item Usage List Builder */}
        <div style={{ borderTop: '1px dashed #ccc', paddingTop: '15px', marginTop:'15px' }}>
            <h4>Items to Use Per Day (Optional)</h4>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap:'wrap' }}>
                 <label htmlFor="sim-itemId">Item ID:</label>
                 <input
                     type="text"
                     id="sim-itemId"
                     value={currentItemId}
                     onChange={handleItemIdChange}
                     placeholder="e.g., ITM001"
                     disabled={loading}
                     style={{ padding: '5px' }}
                 />
                 <label htmlFor="sim-quantity">Quantity:</label>
                 <input
                     type="number"
                     id="sim-quantity"
                     value={currentQuantity}
                     onChange={handleQuantityChange}
                     min="1"
                     style={{ width: '60px', padding: '5px' }}
                     disabled={loading}
                 />
                 <button onClick={handleAddItemToList} type="button" disabled={loading || !currentItemId.trim()}>
                     Add Item
                 </button>
            </div>
            {/* Display the list of items to be used */}
             {itemsToUseList.length > 0 && (
                 <div style={{ marginTop: '10px' }}>
                     <strong>Using per day:</strong>
                     <ul style={{ listStyle: 'none', paddingLeft: 0 }}>
                         {itemsToUseList.map((item) => (
                             <li key={item.id} style={{ marginBottom: '3px' }}>
                                 Item <code>{item.itemId}</code> (Qty: {item.quantity})
                                 <button
                                     onClick={() => handleRemoveItemFromList(item.id)}
                                     style={{ marginLeft: '10px', fontSize: '0.8em', cursor: 'pointer', color:'red', border:'none', background:'none' }}
                                     type="button"
                                     disabled={loading}
                                     title={`Remove ${item.itemId}`}>
                                     × {/* Multiplication sign for remove */}
                                 </button>
                             </li>
                         ))}
                     </ul>
                 </div>
             )}
        </div>

        {/* Submit Button */}
        <button type="submit" style={{marginTop: '20px', padding: '10px 20px', fontSize: '1.1em'}} disabled={loading}>
          {loading ? 'Simulating...' : 'Run Simulation'}
        </button>
      </form>


       {/* Results Area */}
       {/* ...(JSX for results remains the same)... */}
        {error && <p style={{ color: 'red', border: '1px solid red', padding: '10px' }}>Error: {error}</p>}
       {loading && <p style={{ marginTop: '15px' }}>Simulation in progress...</p>}

       {simResult && !loading && ( // Show result only when not loading
            <div style={{marginTop: '20px', border: '1px solid #eee', padding: '15px', backgroundColor: '#f0fff0'}}>
                <h3>Simulation Results</h3>
                {simResult.success ? (
                     <>
                        <p style={{fontWeight: 'bold'}}>Simulation Successful!</p>
                        <p><strong>New Simulation Date:</strong> {simResult.newDate}</p>
                        <h4>Changes Summary:</h4>
                        {renderChanges(simResult.changes)}
                     </>
                ) : (
                    // Display error message from successful API call but logical failure
                     <p style={{ color: 'orange', fontWeight: 'bold' }}>Simulation Warning/Failed: {simResult.message || 'An issue occurred.'}</p>
                )}
            </div>
       )}

    </div>
  );
}
export default SimulatePage;
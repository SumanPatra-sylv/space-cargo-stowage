// frontend/src/pages/WastePage.jsx
import React, { useState, useEffect } from 'react';
// Import the necessary API functions
import { identifyWaste, getWasteReturnPlan, completeWasteUndocking } from '../services/api';
// Optional: Add CSS later
// import './WastePage.css';

function WastePage() {
    // State Variables
    const [wasteItems, setWasteItems] = useState([]); // To store results from identifyWaste
    const [isLoadingIdentify, setIsLoadingIdentify] = useState(false);
    const [errorIdentify, setErrorIdentify] = useState(null);

    // State for Return Plan Form
    const [planInputs, setPlanInputs] = useState({
        undockingContainerId: '',
        undockingDate: '', // Use YYYY-MM-DD format
        maxWeight: ''
    });
    const [planResult, setPlanResult] = useState(null); // To store results from getWasteReturnPlan
    const [isLoadingPlan, setIsLoadingPlan] = useState(false);
    const [errorPlan, setErrorPlan] = useState(null);

    // State for Undocking Completion
    const [isLoadingUndock, setIsLoadingUndock] = useState(false);
    const [errorUndock, setErrorUndock] = useState(null);
    const [undockResult, setUndockResult] = useState(null); // To store result message/count


    // --- Function to Identify Waste ---
    const handleIdentifyWaste = async () => {
        setIsLoadingIdentify(true);
        setErrorIdentify(null);
        setWasteItems([]); // Clear previous results
        // Keep plan inputs but clear results when re-identifying
        setPlanResult(null);
        setUndockResult(null);

        try {
            const response = await identifyWaste(); // Call the API function
            if (response.success) {
                setWasteItems(response.wasteItems || []); // Update state with found items
                if (!response.wasteItems || response.wasteItems.length === 0) {
                     // Optionally set a message if no waste found
                     setErrorIdentify("No waste items currently identified."); // Use error state for feedback
                }
            } else {
                setErrorIdentify(response.message || "Failed to identify waste items.");
            }
        } catch (err) {
            console.error("Identify Waste Error:", err);
            setErrorIdentify(err.message || "An error occurred while identifying waste.");
        } finally {
            setIsLoadingIdentify(false);
        }
    };

    // --- Function to Handle Return Plan Form Change ---
    const handlePlanInputChange = (e) => {
        const { name, value } = e.target;
        setPlanInputs(prev => ({ ...prev, [name]: value }));
    };

    // --- Function to Handle Return Plan Submit ---
    const handlePlanSubmit = async (e) => {
        e.preventDefault();
        // Add validation here if needed
        setIsLoadingPlan(true);
        setErrorPlan(null);
        setPlanResult(null); // Clear previous plan
        setUndockResult(null); // Clear old undock result

        try {
            const payload = {
                undockingContainerId: planInputs.undockingContainerId,
                undockingDate: planInputs.undockingDate,
                maxWeight: parseFloat(planInputs.maxWeight) // Ensure it's a number
            };
             if (!payload.undockingContainerId.trim()) {
                throw new Error("Undocking Container ID cannot be empty.");
            }
             if (isNaN(payload.maxWeight) || payload.maxWeight < 0) {
                throw new Error("Max Weight must be a non-negative number.");
            }
             // Basic date format check
             if (!/^\d{4}-\d{2}-\d{2}$/.test(payload.undockingDate)) {
                 throw new Error("Undocking Date must be in YYYY-MM-DD format.");
             }

            console.log("Submitting plan payload:", payload);
            const response = await getWasteReturnPlan(payload);
            console.log("Plan response:", response);

            if (response.success) {
                setPlanResult(response); // Store the whole response object
                if (!response.returnManifest?.returnItems || response.returnManifest.returnItems.length === 0) {
                     // Set a message if the plan was generated but resulted in no items being selected
                     setErrorPlan("Plan generated, but no waste items selected (check weight limit or item availability).");
                }
            } else {
                setErrorPlan(response.message || response.error || "Failed to generate return plan.");
            }
        } catch (err) {
            console.error("Get Waste Return Plan Error:", err);
            setErrorPlan(err.message || "An error occurred while generating the return plan.");
        } finally {
            setIsLoadingPlan(false);
        }
    };


    // --- Function to Handle Complete Undocking (Uses ID from Plan Result) ---
    const handleCompleteUndocking = async () => {
        // Get ID from planResult (safer approach)
        if (!planResult?.returnManifest?.undockingContainerId) {
            setErrorUndock("Please generate a valid return plan first using the form above.");
            setUndockResult(null); // Clear previous result if any
            return;
        }
        const containerIdToUndock = planResult.returnManifest.undockingContainerId;

        setIsLoadingUndock(true);
        setErrorUndock(null);
        setUndockResult(null);

        try {
            const payload = {
                 undockingContainerId: containerIdToUndock, // Use the ID confirmed from the plan
                 timestamp: new Date().toISOString(),
                 userId: 'astronaut_waste_disposal' // Optional specific user
            };
            console.log("Completing undocking with payload:", payload); // Log payload
            const response = await completeWasteUndocking(payload);

            if (response.success) {
                setUndockResult(response); // Store success response { success, itemsRemoved, message }
                // Optionally clear the plan and form after successful undocking
                setPlanResult(null); // Clear the plan display
                // setPlanInputs({ undockingContainerId: '', undockingDate: '', maxWeight: '' }); // Option: Reset form inputs
                handleIdentifyWaste(); // Refresh the identified waste list
            } else {
                setErrorUndock(response.message || "Failed to complete undocking process.");
            }

        } catch (err) {
             console.error("Complete Undocking Error:", err);
             setErrorUndock(err.message || "An error occurred during undocking completion.");
        } finally {
             setIsLoadingUndock(false);
        }
    };


    // --- Render Logic ---
    return (
        <div style={{ padding: '20px', fontFamily: 'sans-serif' }}>
            <h1>Waste Management</h1>

            {/* Section 1: Identify Waste */}
            <div style={{ marginBottom: '30px', paddingBottom: '20px', borderBottom: '1px solid #ccc' }}>
                <h2>1. Identify Waste Items</h2>
                <button onClick={handleIdentifyWaste} disabled={isLoadingIdentify} style={{ padding: '8px 15px' }}>
                    {isLoadingIdentify ? 'Identifying...' : 'Identify Current Waste'}
                </button>
                {errorIdentify && <p style={{ color: 'red', marginTop: '10px' }}>Error: {errorIdentify}</p>}

                {isLoadingIdentify && <p style={{ marginTop: '10px' }}>Loading waste items...</p>}

                {!isLoadingIdentify && wasteItems.length > 0 && (
                    <div style={{ marginTop: '15px', maxHeight: '300px', overflowY: 'auto' }}>
                        <h3>Identified Waste ({wasteItems.length}):</h3>
                        <table border="1" style={{ borderCollapse: 'collapse', width: '95%' }}>
                            <thead>
                                <tr style={{backgroundColor: '#f2f2f2'}}>
                                    <th style={{padding: '8px', textAlign: 'left'}}>Item ID</th>
                                    <th style={{padding: '8px', textAlign: 'left'}}>Name</th>
                                    <th style={{padding: '8px', textAlign: 'left'}}>Reason</th>
                                    <th style={{padding: '8px', textAlign: 'left'}}>Container ID</th>
                                    <th style={{padding: '8px', textAlign: 'left'}}>Position (Start WDH)</th>
                                </tr>
                            </thead>
                            <tbody>
                                {wasteItems.map(item => (
                                    <tr key={item.itemId}>
                                        <td style={{padding: '5px'}}>{item.itemId}</td>
                                        <td style={{padding: '5px'}}>{item.name}</td>
                                        <td style={{padding: '5px'}}>{item.reason}</td>
                                        <td style={{padding: '5px'}}>{item.containerId || 'N/A'}</td>
                                        <td style={{padding: '5px'}}>
                                            {item.position?.startCoordinates
                                                ? `(${item.position.startCoordinates.width?.toFixed(1)}, ${item.position.startCoordinates.depth?.toFixed(1)}, ${item.position.startCoordinates.height?.toFixed(1)})`
                                                : 'N/A'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                 {!isLoadingIdentify && !errorIdentify && wasteItems.length === 0 && (
                     <p style={{marginTop: '10px', fontStyle: 'italic'}}>No waste items currently identified (or Identify button not clicked).</p>
                 )}
            </div>

            {/* Section 2: Plan Return */}
            <div style={{ marginBottom: '30px', paddingBottom: '20px', borderBottom: '1px solid #ccc' }}>
                 <h2>2. Generate Waste Return Plan</h2>
                 <form onSubmit={handlePlanSubmit}>
                    <div style={{marginBottom: '10px'}}>
                        <label htmlFor="undockingContainerId" style={{marginRight:'5px', display:'inline-block', width:'180px'}}>Undocking Container ID:</label>
                        <input type="text" id="undockingContainerId" name="undockingContainerId" value={planInputs.undockingContainerId} onChange={handlePlanInputChange} required disabled={isLoadingPlan} style={{padding: '5px'}}/>
                    </div>
                     <div style={{marginBottom: '10px'}}>
                        <label htmlFor="undockingDate" style={{marginRight:'5px', display:'inline-block', width:'180px'}}>Undocking Date:</label>
                        {/* Changed type to text to avoid browser date picker issues, rely on regex */}
                        <input type="text" id="undockingDate" name="undockingDate" placeholder="YYYY-MM-DD" value={planInputs.undockingDate} onChange={handlePlanInputChange} required disabled={isLoadingPlan} style={{padding: '5px'}}/>
                    </div>
                     <div style={{marginBottom: '10px'}}>
                        <label htmlFor="maxWeight" style={{marginRight:'5px', display:'inline-block', width:'180px'}}>Max Weight (kg):</label>
                        <input type="number" step="any" id="maxWeight" name="maxWeight" value={planInputs.maxWeight} onChange={handlePlanInputChange} required min="0" disabled={isLoadingPlan} style={{padding: '5px', width: '100px'}}/>
                    </div>
                    <button type="submit" disabled={isLoadingPlan} style={{ padding: '8px 15px' }}>
                        {isLoadingPlan ? 'Generating Plan...' : 'Generate Return Plan'}
                    </button>
                 </form>
                 {errorPlan && <p style={{ color: 'red', marginTop: '10px' }}>Error: {errorPlan}</p>}
                 {isLoadingPlan && <p style={{ marginTop: '10px' }}>Generating plan...</p>}

                 {/* Display Plan Results */}
                 {planResult && !isLoadingPlan && ( // Only show if not loading plan
                    <div style={{marginTop: '20px', border: '1px solid #ddd', padding: '15px', backgroundColor: '#fcfcfc'}}>
                        <h3>Return Plan Generated</h3>
                        <p>{planResult.message}</p>
                        {planResult.errors && planResult.errors.length > 0 && (
                            <div style={{border: '1px solid orange', padding: '5px', margin: '10px 0'}}>
                                <h4>Planning Errors/Warnings:</h4>
                                <ul>{planResult.errors.map((err, i) => <li key={i}>{err.itemId ? `Item ${err.itemId}: ` : ''}{err.message}</li>)}</ul>
                            </div>
                        )}

                        {/* Manifest */}
                        {planResult.returnManifest && (
                            <div style={{marginTop:'15px', border:'1px solid #eee', padding:'10px'}}>
                                <h4>Return Manifest</h4>
                                <p><strong>Target Container:</strong> {planResult.returnManifest.undockingContainerId}</p>
                                <p><strong>Date:</strong> {planResult.returnManifest.undockingDate}</p>
                                <p><strong>Total Items:</strong> {planResult.returnManifest.returnItems?.length ?? 0}</p>
                                <p><strong>Total Weight:</strong> {planResult.returnManifest.totalWeight?.toFixed(3) ?? 0} kg</p>
                                <p><strong>Total Volume:</strong> {planResult.returnManifest.totalVolume?.toFixed(3) ?? 0}</p> {/* Added units maybe? */}
                                <h5>Items in Manifest:</h5>
                                {planResult.returnManifest.returnItems && planResult.returnManifest.returnItems.length > 0 ? (
                                    <ul style={{listStyle:'disc', paddingLeft:'20px'}}>{planResult.returnManifest.returnItems.map(item => <li key={item.itemId}>{item.itemId} ({item.name}) - Reason: {item.reason}</li>)}</ul>
                                ) : <p>No items selected for return.</p>}
                            </div>
                        )}

                        {/* Return Plan Steps */}
                         {planResult.returnPlan && planResult.returnPlan.length > 0 && (
                            <div style={{marginTop:'15px'}}>
                                <h4>Return Plan Steps (Move Items to Undocking Container):</h4>
                                <ol style={{listStyle:'decimal', paddingLeft:'20px'}}>{planResult.returnPlan.map(step => <li key={step.step}>Move Item <strong>{step.itemId}</strong> ({step.itemName}) from {step.fromContainer} to {step.toContainer}</li>)}</ol>
                            </div>
                        )}

                        {/* Retrieval Steps */}
                        {planResult.retrievalSteps && planResult.retrievalSteps.length > 0 && (
                            <div style={{marginTop:'15px'}}>
                                <h4>Retrieval Steps Required (Before Moving):</h4>
                                <ol style={{listStyle:'decimal', paddingLeft:'20px'}}>{planResult.retrievalSteps.map(step => (
                                     <li key={step.step} style={{margin: '3px 0'}}>
                                         <strong>{step.action}:</strong> Item <code>{step.itemId}</code> ({step.itemName})
                                         {step.position && step.position.startCoordinates && ` at (W:${step.position.startCoordinates.width.toFixed(1)}, D:${step.position.startCoordinates.depth.toFixed(1)}, H:${step.position.startCoordinates.height.toFixed(1)})`}
                                         {step.containerId && ` in ${step.containerId}`}
                                     </li>
                                ))}
                                </ol>
                            </div>
                        )}
                    </div>
                 )}
            </div>

            {/* Section 3: Complete Undocking */}
             <div style={{ marginTop: '30px' }}>
                 <h2>3. Confirm Undocking Complete</h2>
                 <button
                    onClick={handleCompleteUndocking}
                    disabled={isLoadingUndock || isLoadingPlan || !planResult?.returnManifest?.undockingContainerId} // Disable based on plan result
                    title={!planResult?.returnManifest?.undockingContainerId ? "Generate a plan with items to return first" : ""}
                    style={{ padding: '8px 15px' }}
                 >
                     {isLoadingUndock ? 'Processing Undocking...' : `Mark Items in '${planResult?.returnManifest?.undockingContainerId || '...'}' as Disposed`}
                 </button>
                 {errorUndock && <p style={{ color: 'red', marginTop: '10px' }}>Error: {errorUndock}</p>}
                 {isLoadingUndock && <p style={{ marginTop: '10px' }}>Processing undocking...</p>}
                 {undockResult && undockResult.success && (
                    <p style={{color: 'green', marginTop: '10px', border: '1px solid green', padding: '10px', backgroundColor: '#e8f5e9'}}>
                        Undocking Complete for {planResult?.returnManifest?.undockingContainerId || 'N/A'}: {undockResult.itemsRemoved} items marked as disposed. Waste list refreshed.
                    </p>
                 )}
                  {undockResult && !undockResult.success && ( // Display failure message if needed
                      <p style={{ color: 'red', marginTop: '10px' }}>
                          Undocking failed for {planResult?.returnManifest?.undockingContainerId || 'N/A'}.
                      </p>
                  )}
             </div>

        </div>
    );
}

export default WastePage;
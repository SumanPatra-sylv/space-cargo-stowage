// frontend/src/services/api.js

// *** IMPORTANT: Adjust if your PHP server runs on a different port or address ***
const API_BASE_URL = 'http://localhost:8000'; // Base URL for the PHP backend

// --- Helper Function for Handling API Responses ---
/**
 * Handles the response from fetch requests.
 * Checks for successful status codes and parses JSON.
 * Throws an error for bad responses.
 * @param {Response} response - The fetch response object.
 * @returns {Promise<any>} - A promise that resolves with the parsed JSON data or success indicator.
 */
const handleResponse = async (response) => {
    // Check if the response status is successful (e.g., 200-299)
    if (response.ok) {
        // Handle 204 No Content specifically - often used for successful actions with no body
        if (response.status === 204) {
            return { success: true, message: 'Action successful.' }; // Provide a success indicator
        }
        // Try to parse JSON, handle potential empty body for other 2xx codes
        try {
             // Check if there's content to parse
             const text = await response.text();
             if (!text) {
                 // If body is empty for a 200 OK, treat as success
                 return { success: true, message: 'Action successful, no content returned.' };
             }
             const data = JSON.parse(text); // Parse text we already read
             // Add basic check for backend indicating failure despite 2xx code
             if (data.success === false) {
                 // Use backend message if available, otherwise generic
                 throw new Error(data.message || data.error || 'Backend indicated failure.');
             }
             // --- Ensure the data object itself is returned ---
             return data; // Return the parsed data (which should include success:true)
        } catch (error) {
             // Handle cases where response is OK but not JSON or JSON parsing fails
             console.error("API Response OK, but processing failed:", error);
             // Check if the error was from our explicit throw above
             if (error.message.includes('Backend indicated failure')) {
                 throw error; // Re-throw the specific backend error
             }
             throw new Error('Received unexpected response format from server.');
        }
    } else {
        // If response status is not OK, try to parse error details from the body
        let errorMessage = `HTTP error! Status: ${response.status} ${response.statusText}`;
        try {
            const errorData = await response.text(); // Get raw text first
            console.error("API Error Response Body:", errorData); // Log the raw error structure
            try {
                // Try to parse as JSON
                const parsedError = JSON.parse(errorData);
                // Use message or error field from backend if available
                errorMessage = parsedError.message || parsedError.error || errorMessage;
            } catch (parseError) {
                // If JSON parsing fails, use the raw text if it's not empty/too long
                if (errorData && errorData.length < 500) { // Avoid logging huge HTML error pages
                    errorMessage = `${errorMessage} - Server Response: ${errorData}`;
                } else if (errorData) {
                    errorMessage = `${errorMessage} - Server returned non-JSON error content.`;
                }
            }
        } catch (textError) {
            // Ignore if reading text body fails
             console.error("Could not read error response text:", textError);
        }
        // Throw an error to be caught by the calling function
        const error = new Error(errorMessage);
        error.status = response.status; // Attach status code to error object
        throw error;
    }
};

// --- API Functions ---

/**
 * Fetches all containers.
 */
export const getContainers = async () => {
    const response = await fetch(`${API_BASE_URL}/api/containers`, { method: 'GET' });
    const data = await handleResponse(response);
    // --- MODIFICATION: Return the full data object ---
    // Components expecting the full object { success: true, containers: [...] }
    // will now receive it correctly.
    // Components that ONLY expected the array need to be updated to access data.containers
    return data;
    // --- END MODIFICATION ---
    // Original was: return data.containers || [];
};

/**
 * Fetches items, optionally filtering by status.
 */
export const getItems = async ({ status } = {}) => {
    let url = `${API_BASE_URL}/api/items`;
    if (status) { url += `?status=${encodeURIComponent(status)}`; }
    const response = await fetch(url, { method: 'GET' });
    const data = await handleResponse(response);
    // --- MODIFICATION: Return the full data object ---
    // This ensures ImportDataPage receives { success: true, items: [...] }
    return data;
    // --- END MODIFICATION ---
    // Original was: return data.items || [];
};

/**
 * Triggers the placement algorithm on the backend.
 */
export const placeItems = async (payload) => {
    const response = await fetch(`${API_BASE_URL}/api/placement`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Imports items from a CSV file.
 */
export const importItems = async (formData) => {
    const response = await fetch(`${API_BASE_URL}/api/import/items`, {
        method: 'POST',
        body: formData,
    });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Imports containers from a CSV file.
 */
export const importContainers = async (formData) => {
    const response = await fetch(`${API_BASE_URL}/api/import/containers`, {
        method: 'POST',
        body: formData,
    });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Searches for an item by ID or name.
 */
export const searchItems = async ({ itemId, itemName, userId }) => {
    const queryParams = new URLSearchParams();
    if (itemId) queryParams.append('itemId', itemId);
    if (itemName) queryParams.append('itemName', itemName);
    if (userId) queryParams.append('userId', userId);
    const response = await fetch(`${API_BASE_URL}/api/search?${queryParams.toString()}`, { method: 'GET' });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Confirms retrieval (updates item state - UPDATE ONLY).
 */
export const retrieveItem = async ({ itemId, userId, timestamp }) => {
    const response = await fetch(`${API_BASE_URL}/api/retrieve`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ itemId, userId, timestamp }),
    });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Manually updates the placement of an item.
 */
export const manualPlaceItem = async (payload) => {
    const response = await fetch(`${API_BASE_URL}/api/place`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Logs a specific action via a separate endpoint.
 */
export const logAction = async (payload) => {
    const response = await fetch(`${API_BASE_URL}/api/log-action`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return handleResponse(response); // Returns full object (Correct)
};


/**
 * Simulates the passage of time and item usage.
 */
export const simulateDay = async (payload) => {
    const response = await fetch(`${API_BASE_URL}/api/simulate/day`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Fetches the current inventory (placed items).
 */
export const getInventory = async () => {
    const response = await fetch(`${API_BASE_URL}/api/inventory`, { method: 'GET' });
     const data = await handleResponse(response);
     // --- MODIFICATION CONSISTENCY (Optional but recommended) ---
     // Return the full object for consistency. Components using this
     // need to be updated to access data.inventory
     return data;
     // --- END MODIFICATION ---
     // Original was: return data.inventory || data || []; (the '|| data' part was likely buggy)
};


/**
 * Identifies waste items.
 */
export const identifyWaste = async () => {
    const response = await fetch(`${API_BASE_URL}/api/waste/identify`, { method: 'GET' });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Generates a waste return plan.
 */
export const getWasteReturnPlan = async (payload) => {
    const response = await fetch(`${API_BASE_URL}/api/waste/return-plan`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Confirms undocking and removal of waste items.
 */
export const completeWasteUndocking = async (payload) => {
    const response = await fetch(`${API_BASE_URL}/api/waste/complete-undocking`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return handleResponse(response); // Returns full object (Correct)
};

/**
 * Fetches system logs.
 */
export const getLogs = async (params = {}) => {
    const queryParams = new URLSearchParams(
        Object.entries(params).filter(([, value]) => value != null && value !== '')
    ).toString();
    const url = `${API_BASE_URL}/api/logs${queryParams ? `?${queryParams}` : ''}`;

    try {
        const response = await fetch(url, {
             method: 'GET',
             headers: { 'Accept': 'application/json' }
         });
        return handleResponse(response); // Returns { success, logs, message } (Already Correct)
    } catch (error) {
        console.error('Network error fetching logs:', error);
        throw new Error(`Network error fetching logs: ${error.message}`);
    }
};

/**
 * Exports the current cargo arrangement as CSV.
 */
export const exportArrangement = async () => {
    const response = await fetch(`${API_BASE_URL}/api/export/arrangement`, { method: 'GET' });
    if (!response.ok) {
         let errorMessage = `HTTP error! Status: ${response.status} ${response.statusText}`;
         try {
             const errorText = await response.text();
              if (errorText && errorText.length < 500) { errorMessage = `${errorMessage} - ${errorText}`; }
              else if (errorText) { errorMessage = `${errorMessage} - Server returned non-JSON error content.`; }
         } catch(e) { /* Ignore */ }
         const error = new Error(errorMessage);
         error.status = response.status;
         throw error;
    }
    return response; // Return raw response for blob handling (Correct)
};
// --- END OF FILE ---
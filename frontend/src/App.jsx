// src/App.jsx
import React from 'react';
import { Routes, Route } from 'react-router-dom';
import Navbar from './components/Navbar';
import InventoryPage from './pages/InventoryPage';
import ImportDataPage from './pages/ImportDataPage';
import SearchPage from './pages/SearchPage';
import WastePage from './pages/WastePage';
import SimulatePage from './pages/SimulatePage';
import LogsPage from './pages/LogsPage';
import './App.css'; // Main app styles

function App() {
  return (
    <div className="App">
      <Navbar />
      <main className="content"> {/* Added content wrapper */}
        <Routes>
          <Route path="/" element={<InventoryPage />} />
          <Route path="/import" element={<ImportDataPage />} />
          <Route path="/search" element={<SearchPage />} />
          {/* <Route path="/placement" element={<PlacementPage />} /> */} {/* Add if needed */}
          <Route path="/waste" element={<WastePage />} />
          <Route path="/simulate" element={<SimulatePage />} />
          <Route path="/logs" element={<LogsPage />} />
          {/* Add a 404 Not Found route maybe */}
           <Route path="*" element={<InventoryPage />} /> {/* Default to inventory */}
        </Routes>
      </main>
    </div>
  );
}

export default App;
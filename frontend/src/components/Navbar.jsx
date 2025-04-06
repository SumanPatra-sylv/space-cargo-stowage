// src/components/Navbar.jsx
import React from 'react';
import { Link } from 'react-router-dom';
import './Navbar.css'; // We'll create this CSS file next

function Navbar() {
  return (
    <nav className="navbar">
      <div className="navbar-brand">CargoStowage Management</div>
      <ul className="navbar-links">
        <li><Link to="/">Inventory</Link></li>
        <li><Link to="/import">Import</Link></li>
        <li><Link to="/search">Search/Retrieve</Link></li>
        {/* Placement might be part of import workflow, decided later */}
        <li><Link to="/waste">Waste Mgmt</Link></li>
        <li><Link to="/simulate">Simulation</Link></li>
        <li><Link to="/logs">Logs</Link></li>
      </ul>
    </nav>
  );
}

export default Navbar;
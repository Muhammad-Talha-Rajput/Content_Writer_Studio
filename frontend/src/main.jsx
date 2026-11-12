import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import './studio.css'
import Studio from './Studio.jsx'
import { AuthProvider } from './auth/AuthContext'
import './auth.css'
import './workspace.css'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter><AuthProvider><Studio /></AuthProvider></BrowserRouter>
  </StrictMode>,
)

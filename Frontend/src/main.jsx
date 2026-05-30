import React from 'react'
import { BrowserRouter } from 'react-router-dom'
import ReactDOM from 'react-dom/client'
import App from './App'
import('bootstrap/dist/css/bootstrap.min.css')
import 'bootstrap/dist/js/bootstrap.bundle.min'
import('aos/dist/aos.css')
import 'aos/dist/aos'
import 'react-app-polyfill/stable'
import('slick-carousel/slick/slick.css')
import('slick-carousel/slick/slick-theme.css')

document.addEventListener('DOMContentLoaded', () => {
  const rootElement = document.getElementById('root')
  if (!rootElement) {
    return
  }
  const root = ReactDOM.createRoot(rootElement)
  root.render(
    <React.StrictMode>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </React.StrictMode>,
  )
})

import { useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'

function ScrollToTop() {
  const { pathname } = useLocation()
  const firstRun = useRef(true)

  useEffect(() => {
    window.scrollTo(0, 0)

    // On route CHANGE (not the initial load), move keyboard focus to the main
    // region so screen readers announce the new page and focus isn't stranded on
    // a control that just unmounted. Skip the first run so a fresh keyboard user
    // still reaches the skip link first.
    if (firstRun.current) {
      firstRun.current = false
      return
    }
    const main = document.getElementById('main-content')
    if (main) main.focus()
  }, [pathname])

  return null
}

export default ScrollToTop

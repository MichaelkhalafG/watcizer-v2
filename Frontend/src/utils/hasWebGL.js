// Cached WebGL capability probe. Creating a throwaway <canvas> and requesting a
// context is the reliable way to know whether the browser can ACTUALLY grant one
// — it accounts for driver blocklists, the per-page context cap, and the
// "context creation blocked" state — BEFORE we mount an R3F <Canvas> that would
// otherwise construct a WebGLRenderer and throw during commit.
//
// The result is cached because the gate is read on every render: without caching
// we'd spawn a fresh probe context each time, which itself churns the cap.
let cached

export function hasWebGL() {
  if (cached !== undefined) return cached
  try {
    const canvas = document.createElement('canvas')
    const gl =
      canvas.getContext('webgl2') ||
      canvas.getContext('webgl') ||
      canvas.getContext('experimental-webgl')
    cached = Boolean(gl)
    // Release the probe context immediately so it never counts against the cap.
    if (gl && typeof gl.getExtension === 'function') {
      const lose = gl.getExtension('WEBGL_lose_context')
      if (lose) lose.loseContext()
    }
  } catch {
    cached = false
  }
  return cached
}

export default hasWebGL

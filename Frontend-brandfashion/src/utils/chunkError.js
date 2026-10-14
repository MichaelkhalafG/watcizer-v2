// "Try Again" for the error boundaries (2026-10-11, backlog PERF-NAV-CHUNK).
//
// When a route's JavaScript chunk fails to load (a connection that drops at the click, a blocker), the
// boundary shows the error screen — and reset() re-renders the same failed load, so "Try Again" NEVER
// recovered: measured on a local build, two clicks after the network came back, still the error screen.
// A full page load fetches the chunk afresh. It happens only on the visitor's click, never on its own,
// so it cannot turn into a reload loop: a failing network shows the same error screen again, with the
// same buttons. Every other error keeps reset(), unchanged.
//
// A tab left open across a deploy is NOT this case: Next compares the build id on the route fetch and
// does a full page load by itself (measured the same day: 3/3 old tabs landed on the new build).
export function isChunkLoadError(error) {
  if (!error) return false
  if (error.name === 'ChunkLoadError') return true
  return /Loading (CSS )?chunk \S+ failed/i.test(String(error.message || ''))
}

export function retryAfterError(error, reset) {
  if (isChunkLoadError(error)) window.location.reload()
  else reset()
}

// Next calls register() once when the server starts (2026-10-30): the process-level log lines, src/lib/processLog.js.
// Node runtime only — the middleware's edge runtime has no process events, and the import stays out of its bundle.
export async function register() {
  if (process.env.NEXT_RUNTIME === 'nodejs') {
    const { installProcessLog } = await import('./src/lib/processLog.js')
    installProcessLog()
  }
}

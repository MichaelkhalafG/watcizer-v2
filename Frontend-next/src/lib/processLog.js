// Process-level lines for the server log (2026-10-30). The live app restarted at 10:06, 10:08 and 10:23 UTC with an
// empty stderr.log, and Hostinger ruled out deploys, limits and network: the next stop has to say why. One JSON line on
// stderr per event — start, uncaughtException, unhandledRejection, SIGTERM, SIGINT, exit — with the time, the uptime
// and the memory at that moment. On the server: hbuilds/versions/<current>/nodejs, stderr.log (console.log carries
// Hostinger's restart banners). A `start` line with no `exit` line before it means the process was killed outright
// (SIGKILL, the OOM killer): nothing inside a process can log that.
//
// It LOGS ONLY. Nothing here changes what happens next:
// - uncaught exceptions are watched through `uncaughtExceptionMonitor`, which observes without handling;
// - Next's server already listens for `unhandledRejection` (it logs and carries on — next-server.js), so one more
//   listener changes nothing; if ours is ever the only one, it re-throws, which is what Node does with no listener;
// - SIGTERM and SIGINT: Next's start-server listens (closes the server, then exits 0). Ours runs FIRST
//   (prependListener) and returns; if ours is ever the only one, it re-raises the signal with the default behaviour;
// - exit: writeSync, because nothing asynchronous runs during `exit`.
import { writeSync } from 'node:fs'

const INSTALLED = Symbol.for('storefront.processLog')

const stderr = (line) => {
  writeSync(2, line + '\n')
}

function describe(err) {
  if (err instanceof Error) return { name: err.name, message: err.message, stack: err.stack }
  return { value: String(err) }
}

/** The line for one event: ISO time, the event, then the process's own figures. */
export function processLine(event, proc, extra = {}) {
  const mem = proc.memoryUsage()
  return JSON.stringify({
    ts: new Date().toISOString(),
    event,
    app: proc.env.NEXT_PUBLIC_STOREFRONT_CODE || null,
    pid: proc.pid,
    uptime_s: Math.round(proc.uptime() * 10) / 10,
    rss: mem.rss,
    heapUsed: mem.heapUsed,
    ...extra,
  })
}

/**
 * Install once per process. Returns false when it already was (a second register() in the same process).
 * `proc` and `write` are the real process and stderr; the tests pass their own.
 */
export function installProcessLog(proc = process, write = stderr) {
  if (proc[INSTALLED]) return false
  proc[INSTALLED] = true

  const log = (event, extra) => {
    try {
      write(processLine(event, proc, extra))
    } catch {
      // a log line that cannot be written must never become the error
    }
  }

  proc.on('uncaughtExceptionMonitor', (err, origin) => log('uncaughtException', { origin, error: describe(err) }))

  const onRejection = (reason) => {
    log('unhandledRejection', { error: describe(reason) })
    if (proc.listenerCount('unhandledRejection') === 1) throw reason
  }
  proc.on('unhandledRejection', onRejection)

  for (const signal of ['SIGTERM', 'SIGINT']) {
    const onSignal = () => {
      log(signal)
      if (proc.listenerCount(signal) === 1) {
        proc.removeListener(signal, onSignal)
        proc.kill(proc.pid, signal)
      }
    }
    proc.prependListener(signal, onSignal)
  }

  proc.on('exit', (code) => log('exit', { code }))

  log('start', { node: proc.version, port: proc.env.PORT ?? null })
  return true
}

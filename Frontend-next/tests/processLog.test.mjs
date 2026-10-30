// The process-level log (src/lib/processLog.js): one JSON line per event, and NOTHING about what happens next changes.
import { EventEmitter } from 'node:events'
import { describe, expect, it } from 'vitest'
import { installProcessLog, processLine } from '../src/lib/processLog.js'

function fakeProcess() {
  const proc = new EventEmitter()
  proc.pid = 4242
  proc.version = 'v24.16.0'
  proc.env = { PORT: '3000', NEXT_PUBLIC_STOREFRONT_CODE: 'watchizer' }
  proc.uptime = () => 12.34
  proc.memoryUsage = () => ({ rss: 1000, heapUsed: 400 })
  proc.kills = []
  proc.kill = (pid, signal) => proc.kills.push([pid, signal])
  return proc
}

function install() {
  const proc = fakeProcess()
  const lines = []
  installProcessLog(proc, (line) => lines.push(JSON.parse(line)))
  return { proc, lines }
}

describe('processLog', () => {
  it('writes a start line with the time, the process and its memory', () => {
    const { lines } = install()
    expect(lines).toHaveLength(1)
    expect(lines[0]).toMatchObject({ event: 'start', app: 'watchizer', pid: 4242, uptime_s: 12.3, rss: 1000, heapUsed: 400, node: 'v24.16.0', port: '3000' })
    expect(new Date(lines[0].ts).toISOString()).toBe(lines[0].ts)
  })

  it('installs once per process', () => {
    const proc = fakeProcess()
    expect(installProcessLog(proc, () => {})).toBe(true)
    expect(installProcessLog(proc, () => {})).toBe(false)
    expect(proc.listenerCount('exit')).toBe(1)
  })

  it('watches uncaught exceptions without handling them', () => {
    const { proc, lines } = install()
    expect(proc.listenerCount('uncaughtException')).toBe(0)
    proc.emit('uncaughtExceptionMonitor', new TypeError('boom'), 'uncaughtException')
    expect(lines[1]).toMatchObject({ event: 'uncaughtException', origin: 'uncaughtException', error: { name: 'TypeError', message: 'boom' } })
    expect(lines[1].error.stack).toContain('boom')
  })

  it('logs a rejection and leaves it to the listener already there (Next logs and carries on)', () => {
    const { proc, lines } = install()
    const seen = []
    proc.on('unhandledRejection', (reason) => seen.push(reason))
    expect(() => proc.emit('unhandledRejection', 'nope')).not.toThrow()
    expect(lines[1]).toMatchObject({ event: 'unhandledRejection', error: { value: 'nope' } })
    expect(seen).toEqual(['nope'])
  })

  it('re-throws a rejection when it is the only listener, as Node does with none', () => {
    const { proc } = install()
    const err = new Error('lonely')
    expect(() => proc.emit('unhandledRejection', err)).toThrow('lonely')
  })

  it('logs SIGTERM before the listener already there, and lets that one decide', () => {
    // the real order: Next's start-server listens first, register() runs later and prepends ours
    const proc = fakeProcess()
    const order = []
    const lines = []
    proc.on('SIGTERM', () => order.push('next'))
    installProcessLog(proc, (line) => {
      order.push('log')
      lines.push(JSON.parse(line))
    })
    order.length = 0
    proc.emit('SIGTERM')
    expect(order).toEqual(['log', 'next'])
    expect(lines[1].event).toBe('SIGTERM')
    expect(proc.kills).toEqual([])
  })

  it('re-raises SIGINT with the default behaviour when it is the only listener', () => {
    const { proc, lines } = install()
    proc.emit('SIGINT')
    expect(lines[1].event).toBe('SIGINT')
    expect(proc.listenerCount('SIGINT')).toBe(0)
    expect(proc.kills).toEqual([[4242, 'SIGINT']])
  })

  it('writes the exit code', () => {
    const { proc, lines } = install()
    proc.emit('exit', 1)
    expect(lines[1]).toMatchObject({ event: 'exit', code: 1, uptime_s: 12.3 })
  })

  it('never throws when the line cannot be written', () => {
    const proc = fakeProcess()
    installProcessLog(proc, () => {
      throw new Error('EPIPE')
    })
    expect(() => proc.emit('exit', 0)).not.toThrow()
  })

  it('is one line of JSON', () => {
    const line = processLine('exit', fakeProcess(), { code: 0 })
    expect(line).not.toContain('\n')
    expect(JSON.parse(line).code).toBe(0)
  })
})

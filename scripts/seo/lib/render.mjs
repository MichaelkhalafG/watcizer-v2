// Headless Chrome over raw CDP (Node's built-in WebSocket) — the RENDERED document, to compare with
// the server HTML. One tab, one page at a time. Read-only: it only loads pages.
import { spawn } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'

const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

export async function openBrowser(port = 9341) {
  if (!fs.existsSync(CHROME)) throw new Error(`Chrome not found at ${CHROME} (set CHROME=)`)
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'seo-crawl-chrome-'))
  const proc = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${dir}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', 'about:blank'], { stdio: 'ignore' })
  let ver
  for (let i = 0; i < 50 && !ver; i++) {
    await sleep(200)
    try {
      ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()
    } catch {}
  }
  if (!ver) throw new Error('Chrome did not open its debugging port')
  const tab = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json()
  const ws = new WebSocket(tab.webSocketDebuggerUrl)
  await new Promise((r, j) => {
    ws.onopen = r
    ws.onerror = j
  })
  let id = 0
  const pending = new Map()
  const listeners = []
  ws.onmessage = (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) {
      pending.get(m.id)(m)
      pending.delete(m.id)
    } else if (m.method) listeners.forEach((l) => l(m))
  }
  const send = (method, params = {}) =>
    new Promise((r) => {
      const i = ++id
      pending.set(i, r)
      ws.send(JSON.stringify({ id: i, method, params }))
    })
  await send('Page.enable')
  await send('Runtime.enable')
  await send('Network.enable')
  await send('Network.setUserAgentOverride', { userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36' })

  async function render(url, { settleMs = 12000, timeoutMs = 60000 } = {}) {
    let loaded = false
    let inflight = 0
    let lastNet = Date.now()
    const l = (m) => {
      if (m.method === 'Page.loadEventFired') loaded = true
      if (m.method === 'Network.requestWillBeSent') {
        inflight++
        lastNet = Date.now()
      }
      if (m.method === 'Network.loadingFinished' || m.method === 'Network.loadingFailed') {
        inflight = Math.max(0, inflight - 1)
        lastNet = Date.now()
      }
    }
    // a crawler keeps no state between pages: no cookies, no storage (a language chosen on one page must not
    // leak into the next — measured 2026-11-13: it does, on the client)
    await send('Page.navigate', { url: 'about:blank' })
    await send('Network.clearBrowserCookies')
    await send('Storage.clearDataForOrigin', { origin: new URL(url).origin, storageTypes: 'all' })
    listeners.push(l)
    const t0 = Date.now()
    await send('Page.navigate', { url })
    while (!loaded && Date.now() - t0 < timeoutMs) await sleep(200)
    // settle: quiet network for 2 s, or settleMs after load, whichever first
    const tl = Date.now()
    while (Date.now() - tl < settleMs && !(Date.now() - lastNet > 2000 && inflight <= 1)) await sleep(250)
    const r = await send('Runtime.evaluate', { expression: 'document.documentElement.outerHTML', returnByValue: true })
    listeners.splice(listeners.indexOf(l), 1)
    return { html: r.result?.result?.value || '', ms: Date.now() - t0, loaded }
  }

  async function close() {
    try {
      ws.close()
    } catch {}
    proc.kill()
    await sleep(500)
    try {
      fs.rmSync(dir, { recursive: true, force: true })
    } catch {}
  }
  return { render, close }
}

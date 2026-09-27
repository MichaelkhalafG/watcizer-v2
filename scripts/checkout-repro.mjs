// ── Checkout diagnosis, 2026-09-27 — KNOWN NOT WORKING YET: read before running ────────────────
//
// What it is for: the "checkout does nothing for some returning browsers" bug (backlog, session
// record 2026-09-27). It adds a product as a fresh guest in a 390x844 touch viewport, taps
// CHECKOUT on an undrifted cart (CONTROL), then makes the SERVER copy of the cart line carry an old
// price (the tab's sessionStorage copy stays current) and taps again, then removes only
// localStorage.wz_guest_token and taps again — logging cart/validate's answer, the URL, and what the
// shopper sees.
//
// STATUS: the CONTROL does not work yet. On the local build (2026-09-27) even the fresh, undrifted
// cart produced NO cart/validate request and stayed on /cart, by touch and by mouse (MOUSE=1). Until
// the control reaches /checkout, the drift result means nothing. Also: the elementFromPoint probe
// prints "undefined" — its template interpolation is broken; fix it first, it is the quickest way to
// see what the tap actually lands on.
//
// Run it against a LOCAL stack only:
//   core:        cd core && C:/tools/php83/php.exe artisan serve --host=127.0.0.1 --port=8000
//   storefront:  cd Frontend-next && npx next build && npx next start -p 3000
//   browse http://localhost:3000 (core's local CORS allows only localhost:3000)
//   deps:        npm install chrome-launcher chrome-remote-interface   (in a scratch folder, or add
//                them there and run the script from it)
//   node scripts/checkout-repro.mjs http://localhost:3000        # touch taps
//   MOUSE=1 node scripts/checkout-repro.mjs http://localhost:3000
//
// SIDE EFFECTS — it WRITES to the dev database (u591083448_watchizer): one guest cart per run, and
// it edits that cart's line price. Record `SELECT MAX(id) FROM carts` / `cart_items` before, and
// delete the rows above them after (the runs of 2026-09-27 were cleaned up that way). Never point it
// at production.
//
// ────────────────────────────────────────────────────────────────────────────────────────────────
// Checkout tap with a DRIFTED server cart (a line stored at an old price), then with only the
// guest token removed. Mobile viewport, touch. usage: node checkout_repro.mjs <origin>
import * as chromeLauncher from 'chrome-launcher'
import CDP from 'chrome-remote-interface'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'

const origin = process.argv[2]
const MYSQL = 'C:/xampp/mysql/bin/mysql.exe'
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', 'u591083448_watchizer', '-e', q], { encoding: 'utf8' }).trim()
const chrome = await chromeLauncher.launch({ chromeFlags: ['--no-first-run', '--window-size=600,1000', '--disable-features=CalculateNativeWinOcclusion', '--disable-backgrounding-occluded-windows', '--disable-renderer-backgrounding'] })
const client = await CDP({ port: chrome.port })
const { Page, Runtime, Input, Network, Emulation } = client
const events = []
const bodies = new Map()
Network.responseReceived(({ requestId, response }) => { if (/cart\/validate|add_to_cart|_rsc=|\/checkout/.test(response.url)) bodies.set(requestId, { url: response.url.replace(/^https?:\/\/[^/]+/, '').slice(0, 70), status: response.status }) })
Network.loadingFinished(async ({ requestId }) => {
  const r = bodies.get(requestId); if (!r) return
  let body = ''
  if (/cart\/validate/.test(r.url)) { try { body = (await Network.getResponseBody({ requestId })).body.slice(0, 400) } catch {} }
  events.push(`${r.status} ${r.url} ${body}`)
})
Network.requestWillBeSent(({ request }) => { if (/cart\/validate|\/checkout/.test(request.url)) events.push(`SENT ${request.method} ${request.url.replace(/^https?:\/\/[^/]+/, '').slice(0, 60)}`) })
Network.loadingFailed(({ requestId, errorText, blockedReason, corsErrorStatus }) => { const r = bodies.get(requestId); events.push(`FAILED ${errorText} ${blockedReason || ''} ${corsErrorStatus ? JSON.stringify(corsErrorStatus) : ''} ${r ? r.url : ''}`) })
Runtime.consoleAPICalled(({ type, args }) => { if (type === 'error') events.push('console.error ' + args.map((a) => a.value ?? a.description ?? '').join(' ').slice(0, 200)) })
await Promise.all([Page.enable(), Runtime.enable(), Network.enable()])
await Page.bringToFront()
await Emulation.setDeviceMetricsOverride({ width: 390, height: 844, deviceScaleFactor: 3, mobile: true })
await Emulation.setTouchEmulationEnabled({ enabled: true, maxTouchPoints: 5 })
const get = async (e) => (await Runtime.evaluate({ expression: e, returnByValue: true, awaitPromise: true })).result.value
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const log = (...a) => console.log(...a)
async function tap(expr) {
  const pt = await get(`(() => { const el = ${expr}; if (!el) return null; el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, text: el.textContent.trim().slice(0, 30) } })()`)
  if (!pt) return null
  await sleep(400)
  if (process.env.MOUSE) {
    await Input.dispatchMouseEvent({ type: 'mousePressed', x: pt.x, y: pt.y, button: 'left', clickCount: 1 })
    await Input.dispatchMouseEvent({ type: 'mouseReleased', x: pt.x, y: pt.y, button: 'left', clickCount: 1 })
  } else {
    await Input.dispatchTouchEvent({ type: 'touchStart', touchPoints: [{ x: pt.x, y: pt.y }] })
    await Input.dispatchTouchEvent({ type: 'touchEnd', touchPoints: [] })
  }
  const under = await get(`(() => { const e = document.elementFromPoint(${'${pt.x}'}, ${'${pt.y}'}); return e ? e.tagName + '.' + String(e.className).slice(0, 40) + ' "' + e.textContent.trim().slice(0, 20) + '"' : null })()`)
  events.push('tapped ' + pt.text + ' | under point: ' + under)
  return pt.text
}
const shopperSees = () => get(`({ url: location.pathname, button: [...document.querySelectorAll('button')].find((b) => /checkout|processing|جار/i.test(b.textContent))?.textContent.trim(),
  banner: [...document.querySelectorAll('[class*="warn"], [role="alert"], [class*="toast"]')].map((e) => e.textContent.trim().slice(0, 120)).filter(Boolean) })`)
const checkoutBtn = `[...document.querySelectorAll('button')].find((b) => /^checkout$/i.test(b.textContent.trim()))`

// 1. A fresh guest adds a product (the first one with a Pre-Order / Add to Cart button).
await Page.navigate({ url: origin + '/listing' }); await sleep(8000)
const hrefs = await get(`[...document.querySelectorAll('.wz-listing-grid a[href^="/product/"]')].map((a) => a.getAttribute('href')).filter((h, i, a) => a.indexOf(h) === i).slice(0, 6)`)
let added = null
for (const href of hrefs) {
  await Page.navigate({ url: origin + href }); await sleep(7000)
  const t = await tap(`[...document.querySelectorAll('button')].find((x) => /Add to Cart|Pre-Order/i.test(x.textContent) && !x.disabled)`)
  if (t) { added = href; await sleep(3000); break }
}
const token = await get(`localStorage.getItem('wz_guest_token')`)
const cartId = sql(`SELECT id FROM carts WHERE guest_token = '${token}'`)
log('added', added, '| guest token', token, '| server cart', cartId, '| lines', sql(`SELECT GROUP_CONCAT(CONCAT(id,':',product_id,'@',piece_price)) FROM cart_items WHERE cart_id = ${cartId || 0}`))

// 2. Control: an undrifted cart → checkout.
await Page.navigate({ url: origin + '/cart' }); await sleep(6000)
events.length = 0
await tap(checkoutBtn); await sleep(7000)
log('CONTROL (server cart matches):', JSON.stringify(await shopperSees()), '\n   ', events.join('\n    '))

// 3. Drift: the server's copy of the line carries an OLD price (the tab's copy stays current).
await Page.navigate({ url: origin + '/cart' }); await sleep(6000)
sql(`UPDATE cart_items SET piece_price = piece_price + 300 WHERE cart_id = ${cartId}`)
log('drifted server lines to', sql(`SELECT GROUP_CONCAT(CONCAT(id,'@',piece_price)) FROM cart_items WHERE cart_id = ${cartId}`))
await Page.reload(); await sleep(6000)
log('before tap:', JSON.stringify(await shopperSees()))
events.length = 0
await tap(checkoutBtn); await sleep(7000)
const drifted = await shopperSees()
log('DRIFTED:', JSON.stringify(drifted), '\n   ', events.join('\n    '))
const { data: shotA } = await Page.captureScreenshot({ format: 'png' }); fs.writeFileSync('repro_drifted_after_tap.png', Buffer.from(shotA, 'base64'))
events.length = 0
await tap(checkoutBtn); await sleep(5000)
log('DRIFTED, second tap:', JSON.stringify(await shopperSees()), '|', events.join(' || '))

// 4. Remove ONLY the guest token (the tab's session cart stays), reload, tap.
await get(`(() => { localStorage.removeItem('wz_guest_token'); return 1 })()`)
await Page.reload(); await sleep(6000)
events.length = 0
await tap(checkoutBtn); await sleep(7000)
log('TOKEN REMOVED:', JSON.stringify(await shopperSees()), '\n   ', events.join('\n    '), '\n    new token', await get(`localStorage.getItem('wz_guest_token')`))
await client.close()
try { await chrome.kill() } catch {}

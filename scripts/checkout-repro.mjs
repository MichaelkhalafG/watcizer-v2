// ── Checkout diagnosis, 2026-09-27 — KNOWN NOT WORKING YET: read before running ────────────────
//
// What it is for: the "checkout does nothing for some returning browsers" bug (backlog, session
// record 2026-09-27). It adds a product as a fresh guest in a 390x844 touch viewport, taps
// CHECKOUT on an undrifted cart (CONTROL), then makes the SERVER copy of the cart line carry an old
// price (the tab's sessionStorage copy stays current) and taps again, then removes only
// localStorage.wz_guest_token and taps again — logging cart/validate's answer, the URL, and what the
// shopper sees.
//
// STATUS: on 2026-09-27 the CONTROL did not work: even the fresh, undrifted cart produced NO
// cart/validate request and stayed on /cart, by touch and by mouse (MOUSE=1). Until the control
// reaches /checkout, the drift result means nothing. 2026-09-28: the under-the-tap probe is fixed
// (it passed a literal "${pt.x}" into the page, the page threw, and `get` turned the error into
// `undefined`); it now measures after the scroll settles and reports, BEFORE the tap, what the
// point hits. Page errors now throw.
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
// SIDE EFFECTS — it WRITES to the dev database (u591083448_watchizer): a guest cart per run, and it
// edits that cart's line price. Since 2026-09-28 it cleans up after itself on exit (every guest cart
// created after its start marks, and their lines) and prints what it removed; it refuses any origin
// that is not a local storefront. Never point it at production.
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

// It writes to the DEV database directly, so it refuses anything but a local storefront.
if (!/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(origin || '')) {
  console.error('refusing: origin must be a LOCAL storefront, e.g. http://localhost:3000')
  process.exit(2)
}
// Clean up after itself, whatever happens: every GUEST cart created after these marks (and its
// lines) is deleted on exit — including a run that crashes halfway.
const baseline = { carts: Number(sql('SELECT COALESCE(MAX(id),0) FROM carts')), items: Number(sql('SELECT COALESCE(MAX(id),0) FROM cart_items')), at: sql('SELECT NOW()') }
process.on('exit', () => {
  const where = `id > ${baseline.carts} AND user_id IS NULL AND created_at >= '${baseline.at}'`
  const ids = sql(`SELECT GROUP_CONCAT(id) FROM carts WHERE ${where}`)
  if (ids && ids !== 'NULL') {
    sql(`DELETE FROM cart_items WHERE cart_id IN (${ids}) AND id > ${baseline.items}`)
    sql(`DELETE FROM carts WHERE id IN (${ids}) AND ${where}`)
  }
  console.log(`cleanup: removed test guest carts [${ids && ids !== 'NULL' ? ids : 'none'}]; carts above ${baseline.carts} now: ${sql(`SELECT COUNT(*) FROM carts WHERE id > ${baseline.carts}`)}, items above ${baseline.items}: ${sql(`SELECT COUNT(*) FROM cart_items WHERE id > ${baseline.items}`)}`)
})
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
// WZ_LANG=ar runs the whole sequence in the Arabic shop (the language is a cookie).
if (process.env.WZ_LANG === 'ar') await Network.setCookie({ name: 'wz-lang', value: 'ar', url: origin })
await Page.bringToFront()
await Emulation.setDeviceMetricsOverride({ width: 390, height: 844, deviceScaleFactor: 3, mobile: true })
await Emulation.setTouchEmulationEnabled({ enabled: true, maxTouchPoints: 5 })
// A page error must fail loudly: it used to come back as `undefined` and read like a real answer.
const get = async (e) => {
  const r = await Runtime.evaluate({ expression: e, returnByValue: true, awaitPromise: true })
  if (r.exceptionDetails) throw new Error('page error: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text).slice(0, 300))
  return r.result.value
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const log = (...a) => console.log(...a)
// Tap the element `expr` finds: scroll it into view, let the layout settle, measure it THEN, and
// record — BEFORE tapping — which element really sits under that point and whether it is the
// target (or inside it). A tap that lands on something else is reported, not silently counted.
async function tap(expr) {
  const found = await get(`(() => { const el = ${expr}; if (!el) return false; window.__tapTarget = el; el.scrollIntoView({ block: 'center' }); return true })()`)
  if (!found) {
    events.push('tap: target not found')
    return null
  }
  await sleep(800)
  const pt = await get(`(() => { const el = window.__tapTarget; const r = el.getBoundingClientRect(); const x = r.left + r.width / 2, y = r.top + r.height / 2;
    const hit = document.elementFromPoint(x, y);
    const describe = (e) => e ? e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\\s+/).join('.') : '') : 'nothing';
    return { x, y, text: el.textContent.trim().slice(0, 30), disabled: !!el.disabled, onTarget: !!hit && (hit === el || el.contains(hit)), under: describe(hit), viewport: innerWidth + 'x' + innerHeight } })()`)
  events.push(`tap "${pt.text}" at ${Math.round(pt.x)},${Math.round(pt.y)} (viewport ${pt.viewport}) disabled=${pt.disabled} | under the point: ${pt.under} | on target: ${pt.onTarget}`)
  if (process.env.MOUSE) {
    await Input.dispatchMouseEvent({ type: 'mouseMoved', x: pt.x, y: pt.y })
    await Input.dispatchMouseEvent({ type: 'mousePressed', x: pt.x, y: pt.y, button: 'left', clickCount: 1 })
    await Input.dispatchMouseEvent({ type: 'mouseReleased', x: pt.x, y: pt.y, button: 'left', clickCount: 1 })
  } else {
    await Input.dispatchTouchEvent({ type: 'touchStart', touchPoints: [{ x: pt.x, y: pt.y }] })
    await Input.dispatchTouchEvent({ type: 'touchEnd', touchPoints: [] })
  }
  return pt.text
}
const shopperSees = () => get(`({ url: location.pathname, button: [...document.querySelectorAll('button')].find((b) => /checkout|processing|جار|إتمام الشراء/i.test(b.textContent))?.textContent.trim(),
  banner: [...document.querySelectorAll('[class*="warn"], [role="alert"], [class*="toast"]')].map((e) => e.textContent.trim().slice(0, 120)).filter(Boolean) })`)
const checkoutBtn = `[...document.querySelectorAll('button')].find((b) => /^(checkout|إتمام الشراء)$/i.test(b.textContent.trim()))`

// 1. A fresh guest adds a product (the first one with a Pre-Order / Add to Cart button).
await Page.navigate({ url: origin + '/listing' }); await sleep(8000)
const hrefs = await get(`[...document.querySelectorAll('.wz-listing-grid a[href^="/product/"]')].map((a) => a.getAttribute('href')).filter((h, i, a) => a.indexOf(h) === i).slice(0, 6)`)
let added = null
for (const href of hrefs) {
  await Page.navigate({ url: origin + href }); await sleep(7000)
  const t = await tap(`[...document.querySelectorAll('button')].find((x) => /Add to Cart|Pre-Order|أضف إلى السلة|اطلب مسبقاً/i.test(x.textContent) && !x.disabled)`)
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
// 5. The check itself cannot run (the validate request is blocked): the shopper must be TOLD, and
//    must not be sent on — never a silent pass, never a silent stop.
await Network.setBlockedURLs({ urls: ['*cart/validate*'] })
await Page.navigate({ url: origin + '/cart' }); await sleep(6000)
events.length = 0
await tap(checkoutBtn); await sleep(5000)
log('VALIDATE UNREACHABLE:', JSON.stringify(await shopperSees()), '\n   ', events.join('\n    '))
await Network.setBlockedURLs({ urls: [] })
await client.close()
try { await chrome.kill() } catch {}

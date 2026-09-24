// JSON-LD that is safe to put inside a <script> tag.
//
// ── Why JSON.stringify alone is NOT safe here (security audit, Finding 3) ─────
//
// JSON.stringify escapes quotes and backslashes and nothing else an HTML parser
// cares about. The browser ends a <script> element at the first `</script>` it
// sees — it does not know it is inside a JSON string — so a stored name like
//
//     Watches</script><img src=x onerror=…>
//
// closes the JSON-LD block and runs the rest as markup, on every page that
// renders that name. The names come from the dashboard (categories, sub-types,
// brands, grades, product and offer titles, blog titles), and the customer JWT
// lives in sessionStorage with a 30-day life, so one stored name was a 30-day
// account takeover for every shopper who opened a listing page.
//
// The fix is the standard one: escape `<`, `>` and `&` to their \u00XX forms.
// They are still the same characters to a JSON parser — JSON.parse of the output
// is IDENTICAL to the input, so search engines read exactly the same data — but
// an HTML parser never sees a `<` and cannot leave the script element. U+2028 and
// U+2029 are escaped too: legal in JSON, line terminators in older JavaScript.
//
// Every <script type="application/ld+json"> in this app goes through this. A core
// test (JsonLdSinkTest) fails the build of any file that feeds JSON.stringify
// straight into dangerouslySetInnerHTML again.

// No escape sequence appears in this file's CODE, on purpose. The first two versions were written
// with `U+2028` and `'\u003c'` literals, and a tool on the way to disk turned the first into a raw
// line terminator (a syntax error) and the second into a bare `<` (escaping nothing at all). Every
// character below is built from its code point, so what runs is what is written.
const BACKSLASH = String.fromCharCode(92)
const LS = String.fromCharCode(0x2028)
const PS = String.fromCharCode(0x2029)

const ESCAPES = {
  '<': BACKSLASH + 'u003c',
  '>': BACKSLASH + 'u003e',
  '&': BACKSLASH + 'u0026',
  [LS]: BACKSLASH + 'u2028',
  [PS]: BACKSLASH + 'u2029',
}

const UNSAFE = new RegExp('[<>&' + LS + PS + ']', 'g')

export function safeJsonLd(data) {
  return JSON.stringify(data).replace(UNSAFE, (ch) => ESCAPES[ch])
}

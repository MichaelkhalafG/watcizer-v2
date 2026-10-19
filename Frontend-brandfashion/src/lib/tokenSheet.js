// Server-only: read the design tokens from app/globals.css as the browser will see them.
//
// The tokens page measures the REAL sheet — the file Next ships — not a JavaScript copy of it, so a
// value changed in CSS and not here cannot make the page lie. Only `--wz-*` custom properties inside
// `:root { … }` blocks are read; a property declared twice keeps its last value, as CSS does.

import fs from 'node:fs'
import path from 'node:path'

const SHEET = path.join(process.cwd(), 'app', 'globals.css')

/** @returns {{ file: string, tokens: Array<{name: string, value: string, comment: string}>, byName: Record<string, string> }} */
export function readTokenSheet() {
  const css = fs.readFileSync(SHEET, 'utf8')
  const tokens = []
  const byName = {}
  // every `:root { … }` block, in order
  const blocks = css.match(/:root\s*\{[^}]*\}/g) ?? []
  for (const block of blocks) {
    const re = /(--wz-[a-z0-9-]+)\s*:\s*([^;]+);\s*(?:\/\*\s*([^*]*?)\s*\*\/)?/g
    let m
    while ((m = re.exec(block)) !== null) {
      const name = m[1]
      const value = m[2].trim()
      const comment = (m[3] ?? '').replace(/\s+/g, ' ').trim()
      const existing = tokens.findIndex((t) => t.name === name)
      if (existing >= 0) tokens[existing] = { name, value, comment }
      else tokens.push({ name, value, comment })
      byName[name] = value
    }
  }
  return { file: 'app/globals.css', tokens, byName }
}

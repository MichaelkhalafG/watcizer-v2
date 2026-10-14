// An article's body as the dashboard stores it — plain text — rendered as HTML ELEMENTS, never as
// HTML: a blank line separates paragraphs, a line starting "## " is a subheading, lines starting
// "- " are a list. Nothing the team types is ever interpreted as markup (2026-10-01).
export default function ArticleBody({ text }) {
  const blocks = []
  let list = null
  let para = []
  const flushPara = () => {
    if (para.length) blocks.push({ type: 'p', text: para.join(' ') })
    para = []
  }
  const flushList = () => {
    if (list) blocks.push({ type: 'ul', items: list })
    list = null
  }
  for (const raw of (text || '').split(/\r?\n/)) {
    const line = raw.trim()
    if (line === '') {
      flushPara()
      flushList()
    } else if (line.startsWith('## ')) {
      flushPara()
      flushList()
      blocks.push({ type: 'h2', text: line.slice(3).trim() })
    } else if (line.startsWith('- ')) {
      flushPara()
      list = list || []
      list.push(line.slice(2).trim())
    } else {
      flushList()
      para.push(line)
    }
  }
  flushPara()
  flushList()

  return (
    <div className="wz-article-body">
      {blocks.map((b, i) =>
        b.type === 'h2' ? (
          <h2 key={i}>{b.text}</h2>
        ) : b.type === 'ul' ? (
          <ul key={i}>
            {b.items.map((item, j) => (
              <li key={j}>{item}</li>
            ))}
          </ul>
        ) : (
          <p key={i}>{b.text}</p>
        ),
      )}
    </div>
  )
}

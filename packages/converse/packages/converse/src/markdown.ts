/**
 * The link schemes that may be rendered as an anchor.
 */
const SAFE_LINK = /^(https?:|mailto:)/i

/**
 * The private-use character that brackets a stashed piece of markup.
 */
const STASH_MARKER = '\uE000'

/**
 * A stashed piece of markup, by its index.
 */
const STASHED = /\uE000(\d+)\uE000/g

/**
 * The private-use character that stands in for a pipe that does not split a table row.
 */
const LITERAL_PIPE = '\uE001'

/**
 * A table's delimiter row, such as `| --- | :-: |`.
 */
const DELIMITER_ROW = /^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?$/

/**
 * A delimiter row that is still arriving, holding nothing but its own characters so far.
 */
const PARTIAL_DELIMITER_ROW = /^[\s|:-]*$/

/**
 * How a table column aligns, where `null` leaves it to the stylesheet.
 */
type Alignment = 'left' | 'center' | 'right' | null

/**
 * A table being read, row by row.
 */
type Table = { alignments: Alignment[]; header: string[]; rows: string[][] }

/**
 * Render markdown that is still arriving, escaped first and emitting fixed tags.
 */
export function renderStreamingMarkdown(markdown: string): string {
  const escaped = escapeHtml(markdown)
  const lines = escaped.split('\n')

  const html: string[] = []
  let paragraph: string[] = []
  let list: 'ul' | 'ol' | null = null
  let quote = false
  let fence: string | null = null
  let code: string[] = []
  let table: Table | null = null

  const closeParagraph = (): void => {
    if (paragraph.length > 0) {
      html.push(`<p>${inline(paragraph.join(' '))}</p>`)
      paragraph = []
    }
  }

  const closeList = (): void => {
    if (list) {
      html.push(`</${list}>`)
      list = null
    }
  }

  const closeQuote = (): void => {
    if (quote) {
      html.push('</blockquote>')
      quote = false
    }
  }

  const closeTable = (): void => {
    if (table) {
      html.push(tableHtml(table))
      table = null
    }
  }

  const closeBlocks = (): void => {
    closeParagraph()
    closeList()
    closeQuote()
    closeTable()
  }

  for (let index = 0; index < lines.length; index++) {
    const line = lines[index]
    // A fence may never close, so everything after an opening one stays code.
    const fenceMatch = /^(```+|~~~+)\s*([^`~\s]*)$/.exec(line.trim())

    const closesFence = fence !== null && fenceMatch !== null && fenceMatch[1].startsWith(fence[0])

    if (closesFence) {
      html.push(`<pre><code>${code.join('\n')}</code></pre>`)
      fence = null
      code = []

      continue
    }

    if (fence !== null) {
      code.push(line)

      continue
    }

    if (fenceMatch) {
      closeBlocks()
      fence = fenceMatch[1]

      continue
    }

    if (table && isTableRow(line)) {
      // The row still arriving waits for its first cell, or it would flash as an empty row.
      if (index < lines.length - 1 || !/^[\s|]*$/.test(line)) {
        table.rows.push(cellsOf(line))
      }

      continue
    }

    closeTable()

    if (line.trim() === '') {
      closeBlocks()

      continue
    }

    const heading = /^(#{1,6})\s+(\S.*|)$/.exec(line)

    if (heading) {
      closeBlocks()
      const level = heading[1].length
      html.push(`<h${level}>${inline(heading[2])}</h${level}>`)

      continue
    }

    if (/^\s*(---+|\*\*\*+|___+)\s*$/.test(line)) {
      closeBlocks()
      html.push('<hr>')

      continue
    }

    const quoted = /^\s*&gt;\s?(.*)$/.exec(line)

    if (quoted) {
      closeParagraph()
      closeList()

      if (!quote) {
        html.push('<blockquote>')
        quote = true
      }

      html.push(`<p>${inline(quoted[1])}</p>`)

      continue
    }

    const item = /^\s*([-*+]|\d+[.)])\s+(\S.*|)$/.exec(line)

    if (item) {
      closeParagraph()
      const kind = /^\d/.test(item[1]) ? 'ol' : 'ul'

      if (list !== kind) {
        closeList()
        html.push(`<${kind}>`)
        list = kind
      }

      html.push(`<li>${inline(item[2])}</li>`)

      continue
    }

    const alignments = isTableRow(line) ? alignmentsFor(line, lines[index + 1]) : null

    if (alignments) {
      closeBlocks()
      table = { alignments, header: cellsOf(line), rows: [] }
      index += 1

      continue
    }

    // A header still waiting for its delimiter row is shown as one, rather than as a line of pipes.
    if (isPendingHeader(lines, index)) {
      closeBlocks()

      const header = cellsOf(line)

      if (header.some((cell) => cell !== '')) {
        table = { alignments: header.map(() => null), header, rows: [] }
      }

      break
    }

    if (list && html[html.length - 1]?.startsWith('<li>')) {
      const previous = html.pop() as string
      html.push(`${previous.slice(0, -'</li>'.length)} ${inline(line.trim())}</li>`)

      continue
    }

    closeList()
    paragraph.push(line.trim())
  }

  if (fence !== null) {
    html.push(`<pre><code>${code.join('\n')}</code></pre>`)
  }

  closeBlocks()

  return html.join('')
}

/**
 * Determine if a line holds a pipe that splits it into cells.
 */
function isTableRow(line: string): boolean {
  return withLiteralPipes(line).includes('|')
}

/**
 * Get the column alignments a delimiter row gives a header, or `null` when it is not the header's delimiter.
 */
function alignmentsFor(header: string, delimiter: string | undefined): Alignment[] | null {
  if (delimiter === undefined || !delimiter.includes('|') || !DELIMITER_ROW.test(delimiter.trim())) {
    return null
  }

  const cells = cellsOf(delimiter)

  return cells.length === cellsOf(header).length ? cells.map(alignmentOf) : null
}

/**
 * Get the alignment a delimiter cell's colons ask for.
 */
function alignmentOf(cell: string): Alignment {
  if (cell.startsWith(':') && cell.endsWith(':')) {
    return 'center'
  }

  if (cell.endsWith(':')) {
    return 'right'
  }

  return cell.startsWith(':') ? 'left' : null
}

/**
 * Determine if a line opens a table whose delimiter row has not fully arrived, which only the end of the text can be.
 */
function isPendingHeader(lines: string[], index: number): boolean {
  const rest = lines.slice(index + 1)

  return (
    lines[index].trim().startsWith('|') && rest.length <= 1 && rest.every((line) => PARTIAL_DELIMITER_ROW.test(line))
  )
}

/**
 * Split a table row into its cells, leaving the pipes in code spans and the escaped ones whole.
 */
function cellsOf(row: string): string[] {
  return withLiteralPipes(row)
    .trim()
    .replace(/^\|/, '')
    .replace(/\|$/, '')
    .split('|')
    .map((cell) => cell.trim().replaceAll(LITERAL_PIPE, '|'))
}

/**
 * Swap the pipes that do not split a row for a placeholder.
 */
function withLiteralPipes(row: string): string {
  return row
    .replaceAll(LITERAL_PIPE, '')
    .replace(/\\\|/g, LITERAL_PIPE)
    .replace(/`[^`]+`/g, (span) => span.replaceAll('|', LITERAL_PIPE))
}

/**
 * Render a table, padding a short row and dropping the cells past the header's.
 */
function tableHtml(table: Table): string {
  const cells = (row: string[], tag: 'th' | 'td'): string =>
    table.alignments
      .map((alignment, column) => {
        const align = alignment ? ` align="${alignment}"` : ''

        return `<${tag}${align}>${inline(row[column] ?? '')}</${tag}>`
      })
      .join('')

  const body = table.rows.map((row) => `<tr>${cells(row, 'td')}</tr>`).join('')

  return `<table><thead><tr>${cells(table.header, 'th')}</tr></thead>${body === '' ? '' : `<tbody>${body}</tbody>`}</table>`
}

/**
 * Render the inline markup within a block.
 */
function inline(text: string): string {
  // Code spans and link targets are stashed, so the emphasis passes cannot rewrite an underscore inside them.
  const stashed: string[] = []

  const stash = (markup: string): string => {
    stashed.push(markup)

    return `${STASH_MARKER}${stashed.length - 1}${STASH_MARKER}`
  }

  let html = text.replaceAll(STASH_MARKER, '')

  html = html.replace(/`([^`]+)`/g, (_, code: string) => stash(`<code>${code}</code>`))

  html = html.replace(/\[([^\]]+)\]\(([^\s)]+)\)/g, (whole: string, label: string, href: string) => {
    if (!SAFE_LINK.test(href.replace(/&amp;/g, '&'))) {
      return whole
    }

    return stash(`<a href="${href}" target="_blank" rel="noopener noreferrer">`) + label + stash('</a>')
  })

  html = html.replace(/(\*\*|__)(?=\S)([\s\S]*?\S)\1/g, (_, __, body: string) => `<strong>${body}</strong>`)

  html = html.replace(/(?<![\w*])\*(?=\S)([^*]*?\S)\*(?![\w*])/g, (_, body: string) => `<em>${body}</em>`)

  html = html.replace(/(?<![\w_])_(?=\S)([^_]*?\S)_(?![\w_])/g, (_, body: string) => `<em>${body}</em>`)

  html = html.replace(/~~(?=\S)([\s\S]*?\S)~~/g, (_, body: string) => `<del>${body}</del>`)

  return html.replace(STASHED, (_, index: string) => stashed[Number(index)])
}

/**
 * Escape the characters that would otherwise be read as markup.
 */
function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

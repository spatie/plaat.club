/**
 * The only code in this repository that writes HTML, and therefore the only
 * place a model could put markup on the page. The escaping cases are the point;
 * the block cases are here so a refactor cannot quietly trade them away.
 */

import { describe, expect, it } from 'vitest'
import { renderStreamingMarkdown } from '../src/markdown'

describe('escaping', () => {
  it('escapes a tag the model wrote rather than emitting it', () => {
    expect(renderStreamingMarkdown('<script>alert(1)</script>')).toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>')
  })

  it('escapes an image with an inline handler', () => {
    expect(renderStreamingMarkdown('<img src=x onerror=alert(1)>')).toBe('<p>&lt;img src=x onerror=alert(1)&gt;</p>')
  })

  it('escapes quotes and ampersands in prose', () => {
    expect(renderStreamingMarkdown('He said "hi" & left')).toBe('<p>He said &quot;hi&quot; &amp; left</p>')
  })

  it('escapes markup inside a code span rather than rendering it', () => {
    expect(renderStreamingMarkdown('`<b>not bold</b>`')).toBe('<p><code>&lt;b&gt;not bold&lt;/b&gt;</code></p>')
  })

  it('escapes markup inside a fenced block', () => {
    expect(renderStreamingMarkdown('```\n<script>x</script>\n```')).toBe(
      '<pre><code>&lt;script&gt;x&lt;/script&gt;</code></pre>',
    )
  })

  it('escapes a tag used as a link label', () => {
    expect(renderStreamingMarkdown('[<img src=x onerror=alert(1)>](https://a.com)')).toBe(
      '<p><a href="https://a.com" target="_blank" rel="noopener noreferrer">&lt;img src=x onerror=alert(1)&gt;</a></p>',
    )
  })
})

describe('link schemes', () => {
  it('links http and https', () => {
    expect(renderStreamingMarkdown('[Laravel](https://laravel.com)')).toBe(
      '<p><a href="https://laravel.com" target="_blank" rel="noopener noreferrer">Laravel</a></p>',
    )
  })

  it('links mailto', () => {
    expect(renderStreamingMarkdown('[mail](mailto:a@b.com)')).toBe(
      '<p><a href="mailto:a@b.com" target="_blank" rel="noopener noreferrer">mail</a></p>',
    )
  })

  it('refuses a javascript: url, leaving the source literal', () => {
    expect(renderStreamingMarkdown('[click](javascript:alert)')).toBe('<p>[click](javascript:alert)</p>')
  })

  it('refuses a data: url, leaving the source literal', () => {
    expect(renderStreamingMarkdown('[click](data:text/html,<b>x</b>)')).toBe(
      '<p>[click](data:text/html,&lt;b&gt;x&lt;/b&gt;)</p>',
    )
  })

  it('cannot be closed out of the href attribute', () => {
    expect(renderStreamingMarkdown('[x](https://a.com" onmouseover="alert(1))')).toBe(
      '<p>[x](https://a.com&quot; onmouseover=&quot;alert(1))</p>',
    )
  })

  it('keeps an escaped ampersand in a query string linkable', () => {
    expect(renderStreamingMarkdown('[q](https://a.com/?a=1&b=2)')).toBe(
      '<p><a href="https://a.com/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">q</a></p>',
    )
  })
})

describe('text that is still arriving', () => {
  it('closes a fence the stream never closed', () => {
    expect(renderStreamingMarkdown('```php\n$a = 1;')).toBe('<pre><code>$a = 1;</code></pre>')
  })

  it('leaves an unclosed bold delimiter literal', () => {
    expect(renderStreamingMarkdown('**half arrived')).toBe('<p>**half arrived</p>')
  })

  it('leaves an unclosed code delimiter literal', () => {
    expect(renderStreamingMarkdown('`half')).toBe('<p>`half</p>')
  })

  it('renders nothing for an empty answer', () => {
    expect(renderStreamingMarkdown('')).toBe('')
  })
})

describe('blocks', () => {
  it('renders a heading at its level', () => {
    expect(renderStreamingMarkdown('## Hello *world*')).toBe('<h2>Hello <em>world</em></h2>')
  })

  it('renders a bulleted list', () => {
    expect(renderStreamingMarkdown('- one\n- two')).toBe('<ul><li>one</li><li>two</li></ul>')
  })

  it('renders a numbered list', () => {
    expect(renderStreamingMarkdown('1. one\n2. two')).toBe('<ol><li>one</li><li>two</li></ol>')
  })

  it('continues a wrapped list item rather than starting another', () => {
    expect(renderStreamingMarkdown('- one\n  continued')).toBe('<ul><li>one continued</li></ul>')
  })

  it('renders a blockquote', () => {
    expect(renderStreamingMarkdown('> quoted *text*')).toBe('<blockquote><p>quoted <em>text</em></p></blockquote>')
  })

  it('renders a horizontal rule', () => {
    expect(renderStreamingMarkdown('---')).toBe('<hr>')
  })

  it('joins wrapped lines into one paragraph and splits on a blank line', () => {
    expect(renderStreamingMarkdown('one\ntwo\n\nthree')).toBe('<p>one two</p><p>three</p>')
  })

  it('closes a fenced block on its closing fence', () => {
    expect(renderStreamingMarkdown('```php\n$a = 1;\n```')).toBe('<pre><code>$a = 1;</code></pre>')
  })
})

describe('inline spans', () => {
  it('renders both bold delimiters', () => {
    expect(renderStreamingMarkdown('**bold** and __also__')).toBe(
      '<p><strong>bold</strong> and <strong>also</strong></p>',
    )
  })

  it('renders both emphasis delimiters', () => {
    expect(renderStreamingMarkdown('*em* and _em_')).toBe('<p><em>em</em> and <em>em</em></p>')
  })

  it('renders strikethrough', () => {
    expect(renderStreamingMarkdown('~~gone~~')).toBe('<p><del>gone</del></p>')
  })

  it('leaves underscores inside a word alone', () => {
    expect(renderStreamingMarkdown('some_var_name here')).toBe('<p>some_var_name here</p>')
  })

  it('leaves emphasis delimiters inside a code span alone', () => {
    expect(renderStreamingMarkdown('call `_private_` or `*star*`')).toBe(
      '<p>call <code>_private_</code> or <code>*star*</code></p>',
    )
  })

  it('leaves emphasis delimiters inside a link target alone', () => {
    expect(renderStreamingMarkdown('[docs](https://example.com/_a_/*b*)')).toBe(
      '<p><a href="https://example.com/_a_/*b*" target="_blank" rel="noopener noreferrer">docs</a></p>',
    )
  })

  it('still renders emphasis inside a link label', () => {
    expect(renderStreamingMarkdown('[_docs_](https://example.com)')).toBe(
      '<p><a href="https://example.com" target="_blank" rel="noopener noreferrer"><em>docs</em></a></p>',
    )
  })

  it('renders a code span inside bold without re-reading its contents', () => {
    expect(renderStreamingMarkdown('**bold with `code` in it**')).toBe(
      '<p><strong>bold with <code>code</code> in it</strong></p>',
    )
  })
})

describe('tables', () => {
  it('renders a header, its body and the alignment each delimiter cell asks for', () => {
    expect(
      renderStreamingMarkdown('| Name | Role | Team | Age |\n|---|:---|:---:|---:|\n| Taylor | Founder | Core | 38 |'),
    ).toBe(
      '<table><thead><tr><th>Name</th><th align="left">Role</th><th align="center">Team</th><th align="right">Age</th></tr></thead>' +
        '<tbody><tr><td>Taylor</td><td align="left">Founder</td><td align="center">Core</td><td align="right">38</td></tr></tbody></table>',
    )
  })

  it('reads a table written without its outer pipes', () => {
    expect(renderStreamingMarkdown('Name | Role\n--- | ---\nTaylor | Founder')).toBe(
      '<table><thead><tr><th>Name</th><th>Role</th></tr></thead><tbody><tr><td>Taylor</td><td>Founder</td></tr></tbody></table>',
    )
  })

  it('renders links, code and emphasis inside cells', () => {
    expect(
      renderStreamingMarkdown('| Package | Docs |\n|---|---|\n| **`laravel/ai`** | [_docs_](https://laravel.com) |'),
    ).toBe(
      '<table><thead><tr><th>Package</th><th>Docs</th></tr></thead><tbody><tr><td><strong><code>laravel/ai</code></strong></td>' +
        '<td><a href="https://laravel.com" target="_blank" rel="noopener noreferrer"><em>docs</em></a></td></tr></tbody></table>',
    )
  })

  it('escapes markup inside cells', () => {
    expect(renderStreamingMarkdown('| <b>Head</b> |\n| --- |\n| <script>alert(1)</script> |')).toBe(
      '<table><thead><tr><th>&lt;b&gt;Head&lt;/b&gt;</th></tr></thead><tbody><tr><td>&lt;script&gt;alert(1)&lt;/script&gt;</td></tr></tbody></table>',
    )
  })

  it('keeps a pipe inside a code span or escaped with a backslash in its cell', () => {
    expect(renderStreamingMarkdown('| Operator | Meaning |\n|---|---|\n| `a || b` | either \\| or |')).toBe(
      '<table><thead><tr><th>Operator</th><th>Meaning</th></tr></thead><tbody><tr><td><code>a || b</code></td><td>either | or</td></tr></tbody></table>',
    )
  })

  it('pads a short row and drops the cells past the header', () => {
    expect(renderStreamingMarkdown('| a | b |\n|---|---|\n| 1 |\n| 1 | 2 | 3 |')).toBe(
      '<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>1</td><td></td></tr><tr><td>1</td><td>2</td></tr></tbody></table>',
    )
  })

  it('ends at a blank line or a line without a pipe', () => {
    expect(renderStreamingMarkdown('| a |\n|---|\n| 1 |\n\nAfter.')).toBe(
      '<table><thead><tr><th>a</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table><p>After.</p>',
    )
    expect(renderStreamingMarkdown('Before.\n| a |\n|---|\n| 1 |\nAfter.')).toBe(
      '<p>Before.</p><table><thead><tr><th>a</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table><p>After.</p>',
    )
  })

  it('leaves a line with a pipe in its prose as text', () => {
    expect(renderStreamingMarkdown('Pipe it with a | b.\nThen carry on.')).toBe(
      '<p>Pipe it with a | b. Then carry on.</p>',
    )
    expect(renderStreamingMarkdown('cats | dogs')).toBe('<p>cats | dogs</p>')
  })

  it('leaves a header whose delimiter row has another column count as text', () => {
    expect(renderStreamingMarkdown('| a | b |\n|---|\nAfter.')).toBe('<p>| a | b | |---| After.</p>')
  })

  it('leaves a code span with a pipe in it as text outside a table', () => {
    expect(renderStreamingMarkdown('Run `a | b` now.')).toBe('<p>Run <code>a | b</code> now.</p>')
  })
})

describe('a table that is still arriving', () => {
  it('shows a header waiting for its delimiter row as a header rather than as pipes', () => {
    const header = '<table><thead><tr><th>Name</th><th>Role</th></tr></thead></table>'

    expect(renderStreamingMarkdown('Users:\n| Name | Role |')).toBe(`<p>Users:</p>${header}`)
    expect(renderStreamingMarkdown('Users:\n| Name | Role |\n')).toBe(`<p>Users:</p>${header}`)
    expect(renderStreamingMarkdown('Users:\n| Name | Role |\n|--')).toBe(`<p>Users:</p>${header}`)
    expect(renderStreamingMarkdown('Users:\n| Name | Role |\n|---|:')).toBe(`<p>Users:</p>${header}`)
  })

  it('takes the alignment once the delimiter row is whole', () => {
    expect(renderStreamingMarkdown('| Name | Age |\n|---|--:|')).toBe(
      '<table><thead><tr><th>Name</th><th align="right">Age</th></tr></thead></table>',
    )
  })

  it('shows nothing for a header that is only its opening pipe so far', () => {
    expect(renderStreamingMarkdown('Users:\n|')).toBe('<p>Users:</p>')
  })

  it('shows the complete rows and a row still arriving once it has a cell', () => {
    const header = '<thead><tr><th>Name</th><th>Role</th></tr></thead>'
    const taylor = '<tr><td>Taylor</td><td>Founder</td></tr>'

    expect(renderStreamingMarkdown('| Name | Role |\n|---|---|\n| Taylor | Founder |\n|')).toBe(
      `<table>${header}<tbody>${taylor}</tbody></table>`,
    )
    expect(renderStreamingMarkdown('| Name | Role |\n|---|---|\n| Taylor | Founder |\n| Nu')).toBe(
      `<table>${header}<tbody>${taylor}<tr><td>Nu</td><td></td></tr></tbody></table>`,
    )
  })
})

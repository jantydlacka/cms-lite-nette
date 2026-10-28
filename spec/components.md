# Components in content

The content of a page or a news item is CommonMark. A line of its own can place a component, such as a list of the latest news:

```
## Welcome

We are a small company.

::: novinky 3
```

The content is then a sequence of text and components. Cutting it at the marker lines and joining the parts again gives the original text byte for byte.

## Marker

A **marker line** is a line that, after at most three spaces of indentation, starts with `:::`. Every marker line is a marker: either a valid one or a malformed one, never plain text.

A marker is **valid** when, after the indentation, the line consists of:

1. `:::`,
2. one or more spaces,
3. the component type: a lower-case ASCII letter followed by lower-case ASCII letters, digits and hyphens,
4. zero or more arguments, each preceded by one or more spaces: a lower-case ASCII letter or digit followed by lower-case ASCII letters, digits and hyphens,
5. optional trailing spaces.

Only the space character (U+0020) separates the parts; a tab or any other character makes the line malformed. Arguments are identifiers and numbers, never markup. What the arguments of a type mean is up to the type.

```ebnf
marker line    = indentation , ":::" , rest ;
valid marker   = indentation , ":::" , spaces , type , { spaces , argument } , [ spaces ] ;
indentation    = [ " " ] , [ " " ] , [ " " ] ;   (* at most three spaces *)
spaces         = " " , { " " } ;
type           = lower , { lower | digit | "-" } ;
argument       = ( lower | digit ) , { lower | digit | "-" } ;
lower          = "a" | "b" | ... | "z" ;
digit          = "0" | "1" | ... | "9" ;
rest           = { any character except a line ending } ;
```

Lines end with LF, CRLF or CR, as in CommonMark; line numbers start at 1.

## Where markers count

| Place | Result |
|-------|--------|
| A line of its own at the top level of the document | Marker. |
| Right after a paragraph line, without a blank line | Marker; it ends the paragraph, like an ATX heading. |
| Right after a list item line or a quote line, without a blank line | Marker at the top level; it ends the list or the quote (the line is not a lazy continuation). |
| Inside a list item or a quote (`- ::: novinky`, `> ::: novinky`, or indented into a list item) | Misplaced marker. |
| Inside an HTML block (for example between `<div>` and `</div>` without blank lines) | Misplaced marker. |
| Inside a fenced or indented code block | Text of the code block. |
| Inside inline code on the same line (`` `::: novinky` ``) | Text of the code. |
| Escaped as `\:::` | Text `:::`. |
| Indented by four or more spaces | An indented code block, or text when it continues a paragraph. |
| Not at the start of the line (`Text ::: novinky`) | Text. |

A marker line belongs to the block structure, so it ends inline code opened on the line above, as an ATX heading would.

In a list item or a quote, a malformed marker is reported as malformed and a well-formed one as misplaced. Every marker line inside an HTML block is reported as misplaced, whatever its form.

## Examples

`spec/components-cases.json` holds the full list as data: each case has the Markdown, the valid markers (`line`, `type`, `arguments`), the errors (`line`, `kind`: `syntax` or `misplaced`) and, for cases without markers, the expected HTML. An implementation conforms when it finds exactly these markers and errors.

| Markdown | Result |
|----------|--------|
| `::: novinky` | Marker `novinky`, no arguments. |
| `::: novinky 3` | Marker `novinky`, argument `3`. |
| `   ::: novinky 3  ` | Marker `novinky`, argument `3`. |
| `Text` + `::: novinky 3` on the next line | Paragraph `Text`, marker on line 2. |
| `:::novinky` | Malformed (no space after the colons). |
| `::: Novinky` | Malformed (upper-case letter). |
| `::: novinky <b>` | Malformed (markup as an argument). |
| `:::` | Malformed (no type). |
| `- ::: novinky` | Misplaced (list item). |
| `> ::: novinky` | Misplaced (quote). |
| `<div>` + `::: novinky 3` + `</div>` | Misplaced on line 2 (HTML block). |
| `- item` + `::: novinky 3` | List, then marker on line 2. |
| `> quote` + `::: novinky 3` | Quote, then marker on line 2. |
| ```` ``` ```` + `::: novinky 3` + ```` ``` ```` | Code block with the text `::: novinky 3`. |
| `` `::: novinky` `` | Inline code. |
| `\::: novinky` | Text `::: novinky`. |

## Rendering

The website and the preview render content with the same parser and the same check as saving.

| Marker | Website | Preview |
|--------|---------|---------|
| Valid | The component's HTML in place of the line. | The same. |
| Valid, but the component has nothing to show (`::: novinky` without visible news) | Nothing. | A note in its place (`admin.content.preview.noNews`), in the language of the admin. |
| Malformed, misplaced, unknown, not allowed or with invalid arguments | Left out; a warning is logged with the type (or the malformed line), the line, the kind and id of the content and its language. | The problem, translated, in its place; inside an HTML block right after that block. |

Such stored content can exist only from before markers were checked or from a direct edit of the database: saving refuses it. A warning is logged at most once per request for the same content, language and line, so it is logged again on every view of the page.

A misplaced marker inside a list item leaves the item empty; a marker line inside an HTML block is removed from the block's text.

Components render in the language of the content, also in the preview of a translation; notes and problems for the editor in the preview are in the language of the admin. The news component (`::: novinky [count]`, count 1 to 20, 3 when missing; pages only) lists the newest visible news of that language with the same item markup as the news list, followed by a link to the news list of that language.


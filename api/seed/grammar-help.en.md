> This page covers the complete editing syntax supported by this wiki. Feel free to copy the examples and practice.

## Headings

```md
# Heading 1 (maps to the page title)
## Heading 2 (appears in the table of contents)
### Heading 3 (appears in the table of contents)
#### Heading 4
##### Heading 5
###### Heading 6
```

> Note: Level 2 (`##`) and level 3 (`###`) headings automatically generate the table of contents (TOC) for navigation; click to jump.

## Text styles

- `**bold**` → **bold**
- `*italic*` → *italic*
- `__underline__` → __underline__
- `~~strikethrough~~` → ~~strikethrough~~
- `` `inline code` `` → `inline code`

## [Custom coloring and styles]{%section}

Use the `[text]{color*}` syntax to color text. The `color*` (inside braces) supports **hex**, **RGB**, **class name**, and **Scss variable**:

- Hex: `[sample]{3c9c5c}` → [sample]{3c9c5c} (you can also write `[sample]{#3c9c5c}`)
- RGB: `[sample]{60:156:92}` → [sample]{60:156:92}
- Class name: `[sample]{%text}` → [sample]{%text} applies the `.text` rule defined in this page's style sheet (`%` is followed by a class name, e.g. `%text` → `.text`)
- Scss variable: `[sample]{$color}` → [sample]{$color} applies the `$color` variable defined in the page style sheet
- Theme color: `[sample]{$TC}` → [sample]{$TC} built-in theme color (`var(--ui-primary)`)
- Inline syntax still works inside a highlight, e.g. `[**bold**]{$TC}` → [**bold**]{$TC}

> Note: For hex, you may omit the `#` prefix (`3c9c5c` and `#3c9c5c` are equivalent). For RGB, use English colons as separators (red:green:blue), with each value in the range 0–255. `%` is followed by a class name (e.g. `%text` → `.text`) defined in the page style sheet (the "style editor"); `$` is followed by a Scss variable name taken from the page style sheet's top-level variables; `$TC` is a built-in special variable (the site theme color `var(--ui-primary)`) that cannot be customized by the style editor.

## Lists

Unordered list (any of `-`, `*`, `+` works):

- Item one
- Item two

Ordered list:

1. First item
2. Second item

## Links

- Internal link: `[[PageName]]` or `[[display text|PageName]]`, e.g. [[Grammar Help|GrammarHelp]]
- External link: `[display text](https://example.com)`, e.g. [Nuxt official website](https://nuxt.com)
- Image link: `[![alt text](image url)](target link)`, e.g. [![Nuxt UI](https://img.shields.io/badge/Made%20with-Nuxt%20UI-00DC82?logo=nuxt&labelColor=020420)](https://ui.nuxt.com)

## Blockquote

> This is a quote used to emphasize important content.

## Containers

Wrap a block of content using `::: type [text]` and close it with a standalone line of `:::`. Available types: **info**, **tip**, **warning**, **danger**, **details** (collapsible container). The `[text]` (title) is optional. Each type uses its own default colors (background and title color).

::: info
This is an **info** note.
:::

::: tip A tip
A **tip** container, with the title specified by `[text]`.
:::

::: warning
This is a **warning** container.
:::

::: danger
This is a **danger** container.
:::

::: details Click to expand
A **details** container is collapsible — click the title to expand/collapse the content.
This is the collapsed body content.
:::

> The title and background of info / tip / warning / danger / details use the default blue, green, orange, red, and gray colors respectively.

## Code blocks

Fenced code block (specify a language for auto highlighting; a one-click copy button appears in the top-right corner):

```js
console.log('Hello, NuxtWiki!');
```

Indenting the first line by 4 spaces also creates a code block:

    This code block was written by indenting the first line with 4 spaces.

## Tables

```md
| Column one | Column two | Column three |
| :--- | :---: | ---: |
| Left | Center | Right |
| A | B | C |
```

Rendered result:

| Column one | Column two | Column three |
| :--- | :---: | ---: |
| Left | Center | Right |
| A | B | C |

## HTML nesting

This site supports **HTML whitelist tags**, and you can keep writing Markdown (nesting) inside them.

### Inline tags

Common inline tags: `span`, `mark`, `strong`, `em`, `a`, `code`, `kbd`, `sub`, `sup`, `del`, `ins`, and more.

Example: this is some <mark>highlighted text</mark>, where **bold** inside still works, 2<sup>3</sup> = 8, and H<sub>2</sub>O.

### Block containers

Common block containers: `div`, `section`, `details`, `summary`, `table`, `blockquote`, and more. Block-level syntax such as headings, lists, and quotes can be written inside.

<details>
<summary>Collapsible panel (click to expand)</summary>

Only the "summary" text above is shown before you click; after expanding, you see the content below:

- List item A
- List item B

</details>

> Tip: Hovering over the "summary" title of a collapsible panel shows a pointer cursor, indicating it can be clicked to expand / collapse.

### Native HTML tables

Markdown also works inside table cells:

<table>
<tr>
<th>Item</th>
<th>Description</th>
</tr>
<tr>
<td>**bold**</td>
<td>Markdown works inside cells</td>
</tr>
<tr>
<td>`inline code`</td>
<td>Also supported</td>
</tr>
</table>

### Raw content & security

Content inside the `pre` tag is shown as-is and Markdown is not parsed:

<pre>**bold will not be applied** <script>alert(1)</script></pre>

> Security note: tags outside the whitelist are escaped, dangerous protocols such as `javascript:` are blocked, and `on*` event attributes are filtered out. Feel free to use it safely.

## Horizontal rule

---

## Editing tips

- Fill in the "edit summary" when saving to make each version change easy to trace
- View, compare, and revert to historical versions on the page's "History" tab
- Page permissions are configured by level on the "Permissions" tab to control who can view and who can edit
- To learn about deployment and operations, see DEPLOY.md in the repository
# Architecture decision records

Short records of the decisions that shape the code: what the problem was, what was chosen and what it costs. Each record links to the code and the tests that implement it.

| # | Decision |
|---|----------|
| [0001](0001-ports-and-adapters-in-the-domain.md) | Ports and adapters inside each domain: repository interfaces in `App\Model`, Nette Database adapters in `<Domain>\Database`, wired by type, boundaries checked by phpat |
| [0002](0002-utc-storage-and-one-site-time-zone.md) | Times are stored in UTC; the site time zone is applied in one class |
| [0003](0003-flat-paths-materialized-path-and-redirects.md) | Flat page URLs from a materialized `path`, 301 redirects from old paths, reserved first segments |
| [0004](0004-concurrent-writes.md) | Concurrent writes: optimistic version columns, the page tree lock, news row locks and the order of redirect writes |
| [0005](0005-components-as-markers-in-markdown.md) | Components are block markers in Markdown, not blocks in the database; the grammar and its cases live in `spec/` |
| [0006](0006-multilingual-ready-content.md) | Content is ready for several languages: a locale strategy, a translation row per language, translated fixed segments |

Format: Context / Decision / Consequences, about one page each. Paths are relative to the repository root.

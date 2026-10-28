# 0005 Components are block markers in Markdown, not blocks in the database

## Context

Editors need to place dynamic pieces (today the latest news, later a slider or banners) inside the content of a page or a news item. A page builder with typed blocks in the database is heavy, ties the data to one admin UI and has to be re-implemented by every version of this CMS in another framework. Raw HTML in content is forbidden, so components cannot be HTML snippets either. A broken component must never disappear without anyone noticing.

## Decision

- **Content stays one Markdown column; a component is a marker line** such as `::: novinky 3`: a line of its own at the top level, type and arguments being lower-case identifiers or numbers. A page is a linear sequence "text, component, text", so a later move to stored blocks is a mechanical cut at the markers; `ContentBlocks` does that cut and `join(split(x))` returns `x` byte for byte (`app/Model/Content/ContentBlocks.php`).
- **The grammar and the placement rules are specified in `spec/components.md`**, and every case is also data in `spec/components-cases.json` (Markdown, expected markers, expected errors, expected HTML for cases without markers). Another implementation conforms when it finds exactly these markers and errors. The PHP tests load the same file (`tests/Core/Markdown/ComponentScannerTest.phpt`, `tests/Core/Markdown/CommonMarkRendererTest.phpt`, `tests/Model/Content/ContentCheckerTest.phpt`).
- **Markers are parsed by a block-parser extension of league/commonmark**, not by regular expressions (`app/Core/Markdown/Component/`), so code blocks, inline code and `\:::` behave as CommonMark readers expect. **One environment** serves both checking and rendering (`app/Core/Markdown/CommonMarkEnvironment.php`, used by `CommonMarkRenderer` and `CommonMarkComponentScanner`), with `html_input: escape` and `allow_unsafe_links: false`, so the checker and the renderer cannot disagree on what a marker is.
- **Components are classes implementing `ContentComponent`** (`app/Model/Content/ContentComponent.php`), registered in `cms: components:` and reached only through `ComponentRegistry` (see [0001](0001-ports-and-adapters-in-the-domain.md)). Each declares where it may be placed (`allowedIn()`) and validates its arguments, with `nette/schema` in `NewsListComponent`. Its HTML comes from its own Latte template with autoescaping (`app/Presentation/Components/LatteComponentRenderer.php`).
- **No silent loss**, at three levels:
  - **saving refuses** content with a malformed, misplaced, unknown or not allowed marker, or invalid arguments, as a draft and as published; nothing is written, the text stays in the form and each problem is shown with its line, in line order (`app/Model/Content/ContentChecker.php`, called by `PageService` and `NewsService`);
  - **the preview shows** the translated problem in place of the marker;
  - **the website leaves the marker out** and logs a warning, never a 500. Such content can exist only after a direct database edit, from before markers were checked, after a component was removed from the configuration or after its argument schema was tightened.

## Consequences

- Log de-duplication is best effort: `ComponentWarningGate` (`app/Core/Markdown/ComponentWarningGate.php`) lets a warning through about once a day per content kind, id, language and line, keyed with a hash of the content so an edited content is reported again; it takes no lock, so concurrent first views can both log, and a cache that cannot be written lets every view log.
- Markers cannot nest and cannot sit inside lists or quotes; a component with inner content would need a new grammar version in `spec/`.
- A component's target that disappears later (for example a slider that was deleted) is left out by the component itself; `ContentComponent` requires that loading its data never throws for a missing target.
- Tests besides those above: `tests/Model/Content/ContentBlocksTest.phpt`, `tests/Core/Markdown/ComponentWarningGateTest.phpt`, `tests/Presentation/Front/ComponentRenderingTest.phpt` ("a broken marker stored in a page is left out with a warning, never a server error or a session"), `tests/Model/Page/PageServiceTest.phpt` ("broken component markers refuse createPage and saveTranslation …"), `tests/Presentation/Admin/PageEditTest.phpt` ("the preview shows a broken marker as its problem, with the line"), `tests/Presentation/Admin/NewsEditTest.phpt` ("a news item refuses the news component and a broken marker, ordered by line, and the text stays").

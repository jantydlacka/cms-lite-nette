# 0001 Ports and adapters inside each domain

## Context

The CMS should stay open to replacing its storage (Nette Database today, possibly Doctrine later) and other infrastructure without touching the domain code. A classic `Domain/` + `Infrastructure/` split scatters one feature over two trees; Nette projects usually group code by feature. The boundary has to hold without relying on code review.

## Decision

- **Ports are interfaces in the domain namespace**, next to the records they return: `App\Model\Page\PageRepository`, `App\Model\News\NewsRepository`, `App\Model\Redirect\PathRedirectRepository`, `App\Model\Page\PageTreeLock`, `App\Model\Transaction`, `App\Model\Content\MarkdownRenderer`, and others. Repositories return `readonly` records, never `ActiveRow`.
- **Database adapters live in a `Database` sub-namespace of their domain**: `app/Model/Page/Database/DatabasePageRepository.php`, `app/Model/News/Database/DatabaseNewsRepository.php`, and so on. Adapters that are not about the database live in `App\Core` (`app/Core/Database/ExplorerTransaction.php`, `app/Core/Markdown/CommonMarkRenderer.php`, `app/Core/Security/NettePasswordHasher.php`).
- **DI registers each adapter typed as its port** (`config/services.neon`):

  ```neon
  pageRepository:
      create: App\Model\Page\Database\DatabasePageRepository
      type: App\Model\Page\PageRepository
  ```

  With `type:` the container knows the service only as the interface, so only the port is autowired. `autowired: PageRepository` alone would not be enough: Nette autowires a service for every type of its class that is a subtype of the listed one (`vendor/nette/di/src/DI/Autowiring.php`), and the adapter class is itself such a subtype, so `DatabasePageRepository` would still be injectable by class name.
- **Content components are not autowired at all.** `cms: components:` may list any number of `ContentComponent` classes; `app/Core/DI/CmsExtension.php` registers each as a numbered service typed `ContentComponent` with `setAutowired(false)` and hands them to `ComponentRegistry`, the only way to reach them.
- **phpat rules in PHPStan enforce the direction** (`tests/Architecture/ArchitectureTest.php`, wired in `phpstan.neon`): the model may use only `Nette\Utils`, `Nette\Schema` and `Psr\Clock` from vendors; only `App\Model\*\Database\*` adapters may add `Nette\Database`; the model never uses `App\Core` or `App\Presentation`; `App\Core` and `App\Presentation` never use an adapter; `App\Core` never uses `App\Presentation`; classes directly in `App\Model` (in no domain) never use a database adapter; a domain never uses another domain's adapter.

## Consequences

- Replacing an adapter is a change of one `create:` line plus the new class. Each repository has a contract test meant to run against every implementation (`tests/Model/Page/PageRepositoryContract.php`, `tests/Model/News/NewsRepositoryContract.php`, `tests/Model/User/UserRepositoryContract.php`).
- Replacement is not free everywhere: reads swap easily, but writes take and return `readonly` records, while Doctrine works by mutating a managed entity and calling `flush()`. A Doctrine `save()` would have to load the entity and copy the record onto it. Locking (`lockTranslation`, `lockNews`, `PageTreeLock`) is part of the ports and would need an equivalent.
- The per-domain adapter rule lists the domains by hand, because phpat cannot refer to the subject's domain from the target selector; `tests/Architecture/DomainsGuardTest.phpt` fails when `app/Model/*` and that list differ.
- The rules themselves are tested on fixtures that must be rejected or accepted (`tests/Architecture/ArchitectureRulesTest.phpt`, `tests/Architecture/fixtures/`).
- `tests/Core/DI/ServiceWiringTest.phpt` scans `app/` and checks that every model interface has exactly one autowired service, that no implementation is autowired under its own class, and that content components are never autowired, however many are configured.

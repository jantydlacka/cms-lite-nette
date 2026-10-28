# 0002 Times are stored in UTC; the site time zone is applied in one class

## Context

News items have a publication date that is entered by an editor, compared with the current time to decide visibility, and shown on the website. The server, the database session and the editor may each sit in a different time zone, and the site zone (`Europe/Prague` by default) has daylight saving changes: one local hour a year does not exist and one exists twice. MySQL `DATETIME` stores no zone.

## Decision

- **Everything is stored and compared in UTC.** `App\Bootstrap` sets the PHP default zone to UTC (`app/Bootstrap.php`), `App\Core\SystemClock` returns UTC (`app/Core/SystemClock.php`), and adapters convert any `DateTimeImmutable` to UTC before writing. The visibility condition compares `published_at` with the clock's "now" passed as a parameter, never with the database's `NOW()` (`app/Model/News/Database/DatabaseNewsRepository.php`, `visible()`).
- **`DATETIME` columns are selected as text** (`CAST(… AS CHAR)`) and parsed as UTC in the adapters, because the driver would build a `DateTime` in the PHP default zone and shift values that zone skips.
- **One class converts between UTC and the site zone:** `App\Core\Localization\SiteTime` (`app/Core/Localization/SiteTime.php`), built from `cms: timezone`. `toSite()` is used for display by the `siteDate` / `siteDateTime` Latte filters (`app/Core/Latte/CmsLatteExtension.php`) and to fill the date field in the administration; `fromInput()` reads a value typed in the form as wall time in the site zone and returns UTC (`app/Presentation/Admin/News/NewsPresenter.php`).
- **Daylight saving** follows PHP: a local time skipped by the change to summer time is moved forward by the gap (02:30 becomes 03:30 in Prague), and an hour that occurs twice at the change to winter time is read as its second occurrence. A shifted value is not changed silently: the administration reports it with the message `admin.news.timeShifted` (see `spec/urls.md` and `spec/messages.md`).
- Time is read through `Psr\Clock\ClockInterface`, so tests can move it (`tests/Support/FrozenClock.php`).

## Consequences

- Changing `cms: timezone` changes only how existing dates are displayed and entered; stored values stay correct.
- The second occurrence of an ambiguous hour cannot be told apart from the first in the form, so an editor cannot enter the first one (02:30 summer time on the day of the autumn change); unlike the spring gap, this is not reported.
- Code outside `SiteTime` must not call `setTimezone()` with the site zone; this is a convention, not a static check.
- Tests: `tests/Core/Localization/SiteTimeTest.phpt` (spring gap, autumn overlap, another zone), `tests/Core/SystemClockTest.phpt` (UTC regardless of the default zone), `tests/Presentation/Admin/NewsEditTest.phpt` ("a local time skipped by the change to summer time is saved shifted and reported").

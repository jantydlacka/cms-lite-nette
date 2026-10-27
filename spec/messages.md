# Messages

Translation keys of the public UI and the administration with their Czech (`lang/cs.neon`) and English (`lang/en.neon`) text. Every UI string goes through the translator; both catalogs have the same keys.

| Key | Czech | English |
|-----|-------|---------|
| `front.theme.label` | Vzhled | Theme |
| `front.theme.dark` | Tmavý režim | Dark mode |
| `front.menu.label` | Hlavní menu | Main menu |
| `front.breadcrumbs.label` | Drobečková navigace | Breadcrumbs |
| `error.404.title` | Stránka nenalezena | Page not found |
| `error.4xx.title` | Požadavek nelze vyřídit | The request cannot be processed |
| `error.500.title` | Něco se pokazilo | Something went wrong |
| `admin.sign.title` | Přihlášení | Sign in |
| `admin.sign.email` | E-mail | E-mail |
| `admin.sign.password` | Heslo | Password |
| `admin.sign.submit` | Přihlásit | Sign in |
| `admin.sign.invalid` | Nesprávný e-mail nebo heslo. | Incorrect e-mail or password. |
| `admin.sign.throttled` | Příliš mnoho pokusů, zkuste to později. | Too many attempts, please try again later. |
| `admin.sign.out` | Odhlásit | Sign out |
| `admin.sign.outDone` | Byli jste odhlášeni. | You have been signed out. |
| `admin.sign.expired` | Byli jste odhlášeni kvůli nečinnosti. | You were signed out due to inactivity. |
| `admin.sign.required` | Pro pokračování se přihlaste. | Please sign in to continue. |
| `admin.dashboard.title` | Administrace | Administration |
| `admin.demo.banner` | Běží demo data – účty se známým heslem, nepoužívat na ostrém webu. | Demo data are enabled – accounts with a known password, never use on a live site. |
| `admin.role.editor` | Editor | Editor |
| `admin.role.admin` | Administrátor | Administrator |
| `admin.nav.dashboard` | Nástěnka | Dashboard |
| `admin.nav.pages` | Stránky | Pages |
| `admin.pages.title` | Stránky | Pages |
| `admin.pages.new` | Nová stránka | New page |
| `admin.pages.empty` | Zatím tu nejsou žádné stránky. | There are no pages yet. |
| `admin.pages.homepage` | Úvodní | Homepage |
| `admin.pages.draft` | Koncept | Draft |
| `admin.pages.missing` | Chybí překlad | Missing translation |
| `admin.pages.up` | Posunout výš | Move up |
| `admin.pages.down` | Posunout níž | Move down |
| `admin.pages.moved` | Pořadí bylo změněno. | The order has been changed. |
| `admin.pages.properties` | Vlastnosti stránky | Page properties |
| `admin.pages.parent` | Nadřazená stránka | Parent page |
| `admin.pages.root` | (žádná – nejvyšší úroveň) | (none – top level) |
| `admin.pages.inMenu` | Zobrazit v menu | Show in menu |
| `admin.pages.field.title` | Název | Title |
| `admin.pages.field.slug` | Adresa | Address |
| `admin.pages.field.slugHint` | Prázdné = vytvoří se z názvu. | Leave empty to derive it from the title. |
| `admin.pages.field.content` | Obsah (Markdown) | Content (Markdown) |
| `admin.pages.field.meta` | Popis pro vyhledávače | Search description |
| `admin.pages.field.status` | Stav | Status |
| `admin.pages.field.titleRequired` | Vyplňte název. | Fill in the title. |
| `admin.pages.field.tooLong` | Text je příliš dlouhý. | The text is too long. |
| `admin.pages.status.draft` | Koncept | Draft |
| `admin.pages.status.published` | Publikováno | Published |
| `admin.pages.save` | Uložit | Save |
| `admin.pages.saveProperties` | Uložit vlastnosti | Save properties |
| `admin.pages.saveTranslation` | Uložit text | Save text |
| `admin.pages.preview` | Náhled | Preview |
| `admin.pages.previewTitle` | Náhled (neuloženo) | Preview (not saved) |
| `admin.pages.addTranslation` | Přidat překlad | Add translation |
| `admin.pages.saved` | Stránka byla uložena. | The page has been saved. |
| `admin.pages.created` | Stránka byla vytvořena. | The page has been created. |
| `admin.pages.stale` | Stránku mezitím upravil někdo jiný. Tvoje úpravy zůstaly ve formuláři – zkopíruj si je, načti aktuální verzi a ulož znovu. | Someone else has changed this page in the meantime. Your changes are still in the form – copy them, reload the current version and save again. |
| `admin.pages.setHomepage` | Nastavit jako úvodní | Set as homepage |
| `admin.pages.homepageSet` | Úvodní stránka byla nastavena. | The homepage has been set. |
| `admin.pages.homepageNeedsPublished` | Úvodní stránka musí mít publikovaný překlad ve výchozím jazyce. | The homepage needs a published translation in the default language. |
| `admin.pages.homepageProtected` | Úvodní stránku nejde smazat ani skrýt, dokud nevyberete jinou. | The homepage cannot be deleted or hidden until another one is chosen. |
| `admin.pages.homepageMissing` | Úvodní stránka nemá publikovaný překlad v jazyce: %locales% | The homepage has no published translation in: %locales% |
| `admin.pages.menuHiddenByParent` | V menu se nezobrazí, protože nadřazená stránka v menu není. | Not shown in the menu because the parent page is not in the menu. |
| `admin.pages.invalidParent` | Stránku nejde přesunout pod sebe ani pod svou podstránku. | A page cannot be moved under itself or its subpage. |
| `admin.pages.deletedMeanwhile` | Stránka mezitím byla smazána. Text zůstal ve formuláři – zkopírujte si ho. | The page was deleted in the meantime. Your text is still in the form – copy it. |
| `admin.pages.parentGone` | Vybraná nadřazená stránka už neexistuje, vyberte jinou. | The selected parent page no longer exists, choose another one. |
| `admin.form.invalidChoice` | Vyberte platnou hodnotu. | Choose a valid value. |
| `admin.pages.slug.format` | Adresa smí obsahovat jen malá písmena bez diakritiky, číslice a pomlčky. | The address may contain only lowercase letters, digits and hyphens. |
| `admin.pages.slug.reserved` | Tento slug je vyhrazený systémem. | This address is reserved by the system. |
| `admin.pages.slug.taken` | Tuto adresu už používá jiná stránka. | Another page already uses this address. |
| `admin.pages.slug.long` | Adresa může mít nejvýš 100 znaků. | The address may have at most 100 characters. |
| `admin.pages.delete` | Smazat stránku | Delete page |
| `admin.pages.deleteConfirm` | Opravdu smazat stránku „%title%“? Smažou se všechny jazyky a staré adresy přestanou přesměrovávat. | Really delete the page “%title%”? All languages are deleted and old addresses stop redirecting. |
| `admin.pages.deleteBack` | Zpět | Back |
| `admin.pages.deleted` | Stránka byla smazána. | The page has been deleted. |
| `admin.pages.hasChildren` | Stránku s podstránkami nejde smazat. | A page with subpages cannot be deleted. |
| `error.403.title` | Na tuto stránku nemáte oprávnění. | You do not have permission to view this page. |

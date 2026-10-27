<?php

declare(strict_types=1);

namespace App\Presentation\Front\News;

use App\Model\News\NewsListItem;
use App\Model\News\NewsRepository;
use App\Model\News\NewsSegments;
use App\Model\Redirect\PathRedirectRepository;
use App\Presentation\Front\FrontPresenter;
use Nette\Utils\Paginator;

final class NewsPresenter extends FrontPresenter
{
	private const PAGE_PATTERN = '~^[1-9][0-9]*$~';

	/** @var list<NewsListItem> */
	private array $items = [];

	private Paginator $paginator;

	private NewsListItem $item;


	public function __construct(
		private readonly NewsRepository $news,
		private readonly PathRedirectRepository $redirects,
	) {
		parent::__construct();
	}


	protected function startup(): void
	{
		// The route pattern does not filter a query value; `01` would otherwise canonicalize to another URL instead of 404.
		if ($this->getAction() === 'default') {
			$page = $this->getParameter('page');
			if ($page !== 1 && !(is_string($page) && preg_match(self::PAGE_PATTERN, $page) === 1)) {
				$this->error();
			}
		}

		parent::startup();

		if ($this->getAction() === 'default') {
			$this->redirectForeignPageParameter();
		}
	}


	public function actionDefault(int $page = 1): void
	{
		$locale = $this->currentLocale->get();
		$paginator = new Paginator();
		$paginator->setItemsPerPage($this->cmsConfig->newsPerPage);
		$paginator->setItemCount($this->news->countVisible($locale));
		// Paginator would clamp an out-of-range page silently.
		if ($page > $paginator->getLastPage()) {
			$this->error();
		}

		$paginator->setPage($page);
		$this->paginator = $paginator;
		$this->items = $this->news->findVisible($locale, $paginator->getItemsPerPage(), $paginator->getOffset());
	}


	public function renderDefault(): void
	{
		$this->template->items = $this->items;
		$this->template->paginator = $this->paginator;
	}


	public function actionDetail(string $slug): void
	{
		$locale = $this->currentLocale->get();
		// A live item wins over a redirect from the same path.
		$item = $this->news->findVisibleBySlug($locale, $slug);
		if ($item === null) {
			$this->redirectFromOldSlug($locale, $slug);
			$this->error();
		}

		$this->item = $item;
	}


	public function renderDetail(): void
	{
		$translation = $this->item->translation;
		$this->template->article = $this->item;
		$this->template->metaDescription = $translation->metaDescription !== '' ? $translation->metaDescription : $translation->perex;
	}


	/** The page parameter of another language (`/en/news?strana=2`) moves to the clean URL instead of silently showing page 1. */
	private function redirectForeignPageParameter(): void
	{
		$own = NewsSegments::forLocale($this->currentLocale->get())['pageParameter'];
		$foreign = array_values(array_diff(NewsSegments::allPageParameters(), [$own]));
		$query = $this->getHttpRequest()->getQuery();
		if (array_intersect_key($query, array_flip($foreign)) === []) {
			return;
		}

		$this->redirectPermanent('this', array_fill_keys($foreign, null));
	}


	/** An old slug leads to the current slug of a visible item; anything else falls through to 404. */
	private function redirectFromOldSlug(string $locale, string $slug): void
	{
		$target = $this->redirects->findTarget($locale, NewsSegments::forLocale($locale)['segment'] . '/' . $slug);
		if ($target === null || $target->type !== 'news') {
			return;
		}

		$translation = $this->news->findTranslation($target->id, $locale);
		if ($translation === null) {
			return;
		}

		// Visibility is decided by the repository alone.
		$visible = $this->news->findVisibleBySlug($locale, $translation->slug);
		if ($visible === null || $visible->news->id !== $target->id) {
			return;
		}

		$this->redirectPermanent('this', ['slug' => $translation->slug]);
	}
}

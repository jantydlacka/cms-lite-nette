<?php

declare(strict_types=1);

namespace App\Presentation\Admin\News;

use App\Core\Security\Resource;
use App\Model\News\NewsRepository;
use App\Model\News\NewsState;
use App\Model\User\UserRepository;
use App\Presentation\Admin\AdminPresenter;
use Nette\DI\Attributes\Inject;
use Nette\Utils\Paginator;
use Psr\Clock\ClockInterface;

final class NewsPresenter extends AdminPresenter
{
	protected const Resource RESOURCE = Resource::News;

	private const ITEMS_PER_PAGE = 20;

	/** The route pattern does not filter a query value. */
	private const PAGE_PATTERN = '~^[1-9][0-9]*$~D';

	#[Inject]
	public NewsRepository $news;

	#[Inject]
	public UserRepository $users;

	#[Inject]
	public ClockInterface $clock;

	/** @var list<NewsRow> */
	private array $rows = [];

	private Paginator $paginator;


	protected function startup(): void
	{
		// `01` would otherwise canonicalize to another URL instead of 404.
		if ($this->getAction() === 'default') {
			$page = $this->getParameter('page');
			if ($page !== 1 && !(is_string($page) && preg_match(self::PAGE_PATTERN, $page) === 1)) {
				$this->error();
			}
		}

		parent::startup();
	}


	public function actionDefault(int $page = 1): void
	{
		$paginator = new Paginator();
		$paginator->setItemsPerPage(self::ITEMS_PER_PAGE);
		$paginator->setItemCount($this->news->countAll());
		// Paginator would clamp an out-of-range page silently.
		if ($page > $paginator->getLastPage()) {
			$this->error();
		}

		$paginator->setPage($page);
		$this->paginator = $paginator;

		$now = $this->clock->now();
		$authors = [];
		$this->rows = [];
		foreach ($this->news->findAll($paginator->getItemsPerPage(), $paginator->getOffset()) as $news) {
			$translations = $this->news->findTranslations($news->id);
			$title = ($translations[$this->cmsConfig->defaultLocale] ?? ($translations === [] ? null : reset($translations)))?->title;
			if ($news->authorId !== null && !array_key_exists($news->authorId, $authors)) {
				$authors[$news->authorId] = $this->users->findById($news->authorId)?->name;
			}

			$states = [];
			foreach ($this->cmsConfig->locales as $locale) {
				$states[$locale] = NewsState::of($news, $translations[$locale] ?? null, $now);
			}

			$this->rows[] = new NewsRow($news, $title, $news->authorId === null ? null : $authors[$news->authorId], $states);
		}
	}


	public function renderDefault(): void
	{
		$this->template->rows = $this->rows;
		$this->template->paginator = $this->paginator;
	}
}

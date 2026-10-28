<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\News\News;
use App\Model\News\NewsListItem;
use App\Model\News\NewsRepository;
use App\Model\News\NewsTranslation;
use DateTimeImmutable;
use LogicException;

/** Answers findVisible() only, from the given items, and records its calls. */
final class FakeNewsRepository implements NewsRepository
{
	/** @var list<array{string, int, int}> */
	public array $calls = [];


	/** @param list<NewsListItem> $items */
	public function __construct(private readonly array $items = [])
	{
	}


	/** @return list<NewsListItem> */
	public function findVisible(string $locale, int $limit, int $offset): array
	{
		$this->calls[] = [$locale, $limit, $offset];

		return array_slice($this->items, $offset, $limit);
	}


	public function countVisible(string $locale): int
	{
		throw new LogicException('Not used.');
	}


	public function findVisibleBySlug(string $locale, string $slug): ?NewsListItem
	{
		throw new LogicException('Not used.');
	}


	public function getNews(int $id): ?News
	{
		throw new LogicException('Not used.');
	}


	public function lockNews(int $id): ?News
	{
		throw new LogicException('Not used.');
	}


	public function findTranslation(int $newsId, string $locale): ?NewsTranslation
	{
		throw new LogicException('Not used.');
	}


	/** @return array<string, NewsTranslation> */
	public function findTranslations(int $newsId): array
	{
		throw new LogicException('Not used.');
	}


	public function createNews(?DateTimeImmutable $publishedAt, ?int $authorId): int
	{
		throw new LogicException('Not used.');
	}


	public function updateNews(News $news): void
	{
		throw new LogicException('Not used.');
	}


	public function setPublishedAt(int $id, DateTimeImmutable $at): void
	{
		throw new LogicException('Not used.');
	}


	public function saveTranslation(NewsTranslation $translation): void
	{
		throw new LogicException('Not used.');
	}


	public function isSlugTaken(string $locale, string $slug, ?int $exceptNewsId): bool
	{
		throw new LogicException('Not used.');
	}


	public function deleteNews(int $id): void
	{
		throw new LogicException('Not used.');
	}


	/** @return list<News> */
	public function findAll(int $limit, int $offset): array
	{
		throw new LogicException('Not used.');
	}


	public function countAll(): int
	{
		throw new LogicException('Not used.');
	}
}

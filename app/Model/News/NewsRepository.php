<?php

declare(strict_types=1);

namespace App\Model\News;

use App\Model\InvalidSlugException;
use DateTimeImmutable;

interface NewsRepository
{
	/**
	 * Visible in $locale at the repository's clock (see NewsState::of()), newest first: published_at, then id, descending.
	 *
	 * @return list<NewsListItem>
	 */
	public function findVisible(string $locale, int $limit, int $offset): array;


	public function countVisible(string $locale): int;


	public function findVisibleBySlug(string $locale, string $slug): ?NewsListItem;


	public function getNews(int $id): ?News;


	/** Must run inside a transaction: takes a row lock (SELECT ... FOR UPDATE) on the news item. */
	public function lockNews(int $id): ?News;


	/** Any publication status. */
	public function findTranslation(int $newsId, string $locale): ?NewsTranslation;


	/** @return array<string, NewsTranslation> by locale */
	public function findTranslations(int $newsId): array;


	public function createNews(?DateTimeImmutable $publishedAt, ?int $authorId): int;


	/** Writes publishedAt; the version argument is ignored, the stored one goes up by 1. */
	public function updateNews(News $news): void;


	/** Leaves the version alone (publishing a translation dates the item without counting as an edit of it). */
	public function setPublishedAt(int $id, DateTimeImmutable $at): void;


	/**
	 * Inserts or updates by (newsId, locale) and stores updatedAt of the argument; the version argument is ignored (the store counts).
	 * Its insert-or-update check is a non-locking snapshot read: the caller must take lockNews() as the first read of its transaction.
	 *
	 * @throws InvalidSlugException taken, when the (locale, slug) pair belongs to another news item
	 */
	public function saveTranslation(NewsTranslation $translation): void;


	public function isSlugTaken(string $locale, string $slug, ?int $exceptNewsId): bool;


	/** Translations go with the item (ON DELETE CASCADE). */
	public function deleteNews(int $id): void;


	/**
	 * Every item for the admin: undated first, then published_at and id descending.
	 *
	 * @return list<News>
	 */
	public function findAll(int $limit, int $offset): array;


	public function countAll(): int;
}

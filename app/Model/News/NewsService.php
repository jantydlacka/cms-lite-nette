<?php

declare(strict_types=1);

namespace App\Model\News;

use App\Model\CmsConfig;
use App\Model\InvalidSlugException;
use App\Model\PublicationStatus;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use App\Model\Slug;
use App\Model\Transaction;
use InvalidArgumentException;
use LogicException;
use Nette\Utils\Strings;
use Psr\Clock\ClockInterface;
use UnexpectedValueException;

final class NewsService
{
	private const TITLE_MAX_LENGTH = 200;
	private const PEREX_MAX_LENGTH = 300;
	private const META_DESCRIPTION_MAX_LENGTH = 300;


	public function __construct(
		private readonly NewsRepository $news,
		private readonly PathRedirectRepository $redirects,
		private readonly Transaction $transaction,
		private readonly CmsConfig $config,
		private readonly ClockInterface $clock,
	) {
	}


	/**
	 * Creates the item together with its first translation; a failing translation leaves no item.
	 *
	 * @throws InvalidSlugException nothing is written then
	 * @throws InvalidArgumentException when the input is invalid as in saveTranslation()
	 */
	public function createNews(CreateNews $command): NewsTranslation
	{
		$this->assertOwnTransaction();
		[$title, $slug, $perex] = $this->checkInput($command->locale, $command->title, $command->slug, $command->perex, $command->metaDescription);

		return $this->transaction->run(function () use ($command, $title, $slug, $perex): NewsTranslation {
			$id = $this->news->createNews($command->publishedAt, $command->authorId);

			return $this->writeTranslation(
				$this->lockOrFail($id),
				$command->locale,
				$title,
				$slug,
				$perex,
				$command->content,
				$command->metaDescription,
				$command->status,
				null,
			);
		});
	}


	/**
	 * Problems are reported in this order: locale, title, perex and description; slug long, format (all before the
	 * transaction); then under the row lock: missing item, stale version, slug taken. An empty slug is derived from the title.
	 * A published translation of an undated item dates it now, leaving the item's version alone.
	 *
	 * @throws InvalidSlugException nothing is written then
	 * @throws StaleVersionException when the stored version differs from $command->expectedVersion; nothing is written then
	 * @throws InvalidArgumentException when the item does not exist, the locale is not configured, or the title, perex or description is blank or too long
	 */
	public function saveTranslation(SaveNewsTranslation $command): NewsTranslation
	{
		$this->assertOwnTransaction();
		[$title, $slug, $perex] = $this->checkInput($command->locale, $command->title, $command->slug, $command->perex, $command->metaDescription);

		return $this->transaction->run(function () use ($command, $title, $slug, $perex): NewsTranslation {
			$news = $this->news->lockNews($command->newsId)
				?? throw new InvalidArgumentException('News item ' . $command->newsId . ' does not exist.');

			return $this->writeTranslation(
				$news,
				$command->locale,
				$title,
				$slug,
				$perex,
				$command->content,
				$command->metaDescription,
				$command->status,
				$command->expectedVersion,
			);
		});
	}


	/**
	 * An empty date on an item with a published translation becomes now, so a published translation is never undated.
	 *
	 * @throws StaleVersionException when the stored version differs from $command->expectedVersion; nothing is written then
	 * @throws InvalidArgumentException when the item does not exist
	 */
	public function updateNews(UpdateNews $command): News
	{
		$this->assertOwnTransaction();

		return $this->transaction->run(function () use ($command): News {
			$news = $this->news->lockNews($command->newsId)
				?? throw new InvalidArgumentException('News item ' . $command->newsId . ' does not exist.');
			if ($news->version !== $command->expectedVersion) {
				throw new StaleVersionException('News item ' . $news->id . ' has changed since version ' . $command->expectedVersion . '.');
			}

			$publishedAt = $command->publishedAt;
			if ($publishedAt === null && $this->hasPublishedTranslation($news->id)) {
				$publishedAt = $this->clock->now();
			}
			$this->news->updateNews(new News($news->id, $publishedAt, $news->authorId, $news->version, $news->createdAt));

			return $this->news->getNews($news->id) ?? throw new UnexpectedValueException('News item vanished.');
		});
	}


	/**
	 * Deletes the item with all its translations and the redirects to it.
	 *
	 * @throws InvalidArgumentException when the item does not exist
	 */
	public function deleteNews(int $newsId): void
	{
		$this->assertOwnTransaction();
		$this->transaction->run(function () use ($newsId): void {
			if ($this->news->lockNews($newsId) === null) {
				throw new InvalidArgumentException('News item ' . $newsId . ' does not exist.');
			}
			$this->redirects->deleteForTarget(new RedirectTarget('news', $newsId));
			$this->news->deleteNews($newsId);
		});
	}


	/**
	 * A write must open its transaction itself and lock the news row first: under REPEATABLE READ a read before the
	 * lock would fix a snapshot older than the lock.
	 */
	private function assertOwnTransaction(): void
	{
		if ($this->transaction->inTransaction()) {
			throw new LogicException('News writes must start their own transaction; one is already open.');
		}
	}


	/**
	 * Checks that need no stored data.
	 *
	 * @return array{string, string, string} trimmed title, slug (derived from the title when empty), trimmed perex
	 */
	private function checkInput(string $locale, string $title, string $slug, string $perex, string $metaDescription): array
	{
		if (!in_array($locale, $this->config->locales, true)) {
			throw new InvalidArgumentException('Locale "' . $locale . '" is not configured.');
		}
		$title = Strings::trim($title);
		if ($title === '' || mb_strlen($title) > self::TITLE_MAX_LENGTH) {
			throw new InvalidArgumentException('Title must be 1-' . self::TITLE_MAX_LENGTH . ' characters.');
		}
		$perex = Strings::trim($perex);
		if ($perex === '' || mb_strlen($perex) > self::PEREX_MAX_LENGTH) {
			throw new InvalidArgumentException('Perex must be 1-' . self::PEREX_MAX_LENGTH . ' characters.');
		}
		if (mb_strlen($metaDescription) > self::META_DESCRIPTION_MAX_LENGTH) {
			throw new InvalidArgumentException('Meta description must be at most ' . self::META_DESCRIPTION_MAX_LENGTH . ' characters.');
		}

		if ($slug === '') {
			$slug = Slug::fromTitle($title);
		}
		Slug::assertValid($slug);

		return [$title, $slug, $perex];
	}


	/** Must run inside the transaction, after lockNews() of $news. */
	private function writeTranslation(
		News $news,
		string $locale,
		string $title,
		string $slug,
		string $perex,
		string $content,
		string $metaDescription,
		PublicationStatus $status,
		?int $expectedVersion,
	): NewsTranslation {
		// A plain read: FOR UPDATE on a missing row takes a gap lock, and two first translations in one gap deadlock on insert.
		// Every translation writer holds the news row lock, so this read already sees the latest committed row.
		$existing = $this->news->findTranslation($news->id, $locale);
		if ($existing?->version !== $expectedVersion) {
			throw new StaleVersionException('Translation ' . $news->id . '/' . $locale . ' has changed since version ' . ($expectedVersion ?? 'none') . '.');
		}
		if ($this->news->isSlugTaken($locale, $slug, $news->id)) {
			throw new InvalidSlugException(InvalidSlugException::TAKEN);
		}

		$this->moveRedirects($news->id, $locale, $existing?->slug, $slug);
		$now = $this->clock->now();
		// The repository sets the version itself (1 on insert, +1 on update); the argument is ignored.
		$this->news->saveTranslation(new NewsTranslation($news->id, $locale, $title, $slug, $perex, $content, $metaDescription, $status, $now, 1));
		if ($status === PublicationStatus::Published && $news->publishedAt === null) {
			$this->news->setPublishedAt($news->id, $now);
		}

		return $this->news->findTranslation($news->id, $locale)
			?? throw new UnexpectedValueException('Saved translation vanished.');
	}


	/** Live content wins: a redirect from the new path is dropped; the old path of this item starts redirecting. */
	private function moveRedirects(int $newsId, string $locale, ?string $oldSlug, string $newSlug): void
	{
		$segment = NewsSegments::forLocale($locale)['segment'];
		// record() before deleteByPath(): after the gap lock of the DELETE, no insert into path_redirect may follow in this transaction, or concurrent writers deadlock.
		if ($oldSlug !== null && $oldSlug !== $newSlug) {
			$this->redirects->record($locale, $segment . '/' . $oldSlug, new RedirectTarget('news', $newsId));
		}
		$this->redirects->deleteByPath($locale, $segment . '/' . $newSlug);
	}


	private function hasPublishedTranslation(int $newsId): bool
	{
		foreach ($this->news->findTranslations($newsId) as $translation) {
			if ($translation->status === PublicationStatus::Published) {
				return true;
			}
		}

		return false;
	}


	private function lockOrFail(int $newsId): News
	{
		return $this->news->lockNews($newsId) ?? throw new UnexpectedValueException('News item vanished.');
	}
}

<?php

declare(strict_types=1);

namespace App\Model\News\Database;

use App\Model\InvalidSlugException;
use App\Model\News\News;
use App\Model\News\NewsListItem;
use App\Model\News\NewsRepository;
use App\Model\News\NewsTranslation;
use App\Model\PublicationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\SqlLiteral;
use Nette\Database\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use UnexpectedValueException;

final class DatabaseNewsRepository implements NewsRepository
{
	private const FORMAT = 'Y-m-d H:i:s';
	private const SLUG_KEY = 'uq_news_translation_slug';

	// DATETIME is selected as text: the driver would build a DateTime in the PHP default zone and shift values that zone skips.
	private const NEWS_COLUMNS = 'n.id, CAST(n.published_at AS CHAR) AS published_at_text, n.author_id, n.version AS news_version, CAST(n.created_at AS CHAR) AS created_at_text';
	private const TRANSLATION_COLUMNS = 't.news_id, t.locale, t.title, t.slug, t.perex, t.content, t.meta_description, t.status, t.version, CAST(t.updated_at AS CHAR) AS updated_at_text';


	public function __construct(
		private readonly Explorer $explorer,
		private readonly ClockInterface $clock,
	) {
	}


	/** @return list<NewsListItem> */
	public function findVisible(string $locale, int $limit, int $offset): array
	{
		[$condition, $params] = $this->visible();
		$rows = $this->explorer->query(
			'SELECT ' . self::NEWS_COLUMNS . ', ' . self::TRANSLATION_COLUMNS . '
			FROM news n JOIN news_translation t ON t.news_id = n.id
			WHERE t.locale = ? AND ' . $condition . '
			ORDER BY n.published_at DESC, n.id DESC
			LIMIT ? OFFSET ?',
			$locale,
			...[...$params, $limit, $offset],
		)->fetchAll();

		return array_map(fn (Row $row): NewsListItem => new NewsListItem($this->hydrateNews($row), $this->hydrateTranslation($row)), $rows);
	}


	public function countVisible(string $locale): int
	{
		[$condition, $params] = $this->visible();

		return self::int($this->explorer->query(
			'SELECT COUNT(*) FROM news n JOIN news_translation t ON t.news_id = n.id WHERE t.locale = ? AND ' . $condition,
			$locale,
			...$params,
		)->fetchField());
	}


	public function findVisibleBySlug(string $locale, string $slug): ?NewsListItem
	{
		[$condition, $params] = $this->visible();
		$row = $this->explorer->query(
			'SELECT ' . self::NEWS_COLUMNS . ', ' . self::TRANSLATION_COLUMNS . '
			FROM news n JOIN news_translation t ON t.news_id = n.id
			WHERE t.locale = ? AND t.slug = ? AND ' . $condition,
			$locale,
			$slug,
			...$params,
		)->fetch();

		return $row === null ? null : new NewsListItem($this->hydrateNews($row), $this->hydrateTranslation($row));
	}


	public function getNews(int $id): ?News
	{
		$row = $this->explorer->query('SELECT ' . self::NEWS_COLUMNS . ' FROM news n WHERE n.id = ?', $id)->fetch();

		return $row === null ? null : $this->hydrateNews($row);
	}


	public function lockNews(int $id): ?News
	{
		$row = $this->explorer->query('SELECT ' . self::NEWS_COLUMNS . ' FROM news n WHERE n.id = ? FOR UPDATE', $id)->fetch();

		return $row === null ? null : $this->hydrateNews($row);
	}


	public function findTranslation(int $newsId, string $locale): ?NewsTranslation
	{
		$row = $this->explorer->query('SELECT ' . self::TRANSLATION_COLUMNS . ' FROM news_translation t WHERE t.news_id = ? AND t.locale = ?', $newsId, $locale)->fetch();

		return $row === null ? null : $this->hydrateTranslation($row);
	}


	/** @return array<string, NewsTranslation> */
	public function findTranslations(int $newsId): array
	{
		$translations = [];
		foreach ($this->explorer->query('SELECT ' . self::TRANSLATION_COLUMNS . ' FROM news_translation t WHERE t.news_id = ? ORDER BY t.locale', $newsId)->fetchAll() as $row) {
			$translation = $this->hydrateTranslation($row);
			$translations[$translation->locale] = $translation;
		}

		return $translations;
	}


	public function createNews(?DateTimeImmutable $publishedAt, ?int $authorId): int
	{
		$now = $this->now();
		$this->explorer->query('INSERT INTO news ?', [
			'published_at' => $publishedAt === null ? null : self::format($publishedAt),
			'author_id' => $authorId,
			'created_at' => $now,
			'updated_at' => $now,
		]);

		return self::int($this->explorer->getInsertId());
	}


	public function updateNews(News $news): void
	{
		$this->explorer->table('news')->where('id', $news->id)->update([
			'published_at' => $news->publishedAt === null ? null : self::format($news->publishedAt),
			'updated_at' => $this->now(),
			'version' => new SqlLiteral('version + 1'),
		]);
	}


	public function setPublishedAt(int $id, DateTimeImmutable $at): void
	{
		$this->explorer->table('news')->where('id', $id)->update(['published_at' => self::format($at), 'updated_at' => $this->now()]);
	}


	public function saveTranslation(NewsTranslation $translation): void
	{
		$now = self::format($translation->updatedAt);
		$values = [
			'title' => $translation->title,
			'slug' => $translation->slug,
			'perex' => $translation->perex,
			'content' => $translation->content,
			'meta_description' => $translation->metaDescription,
			'status' => $translation->status->value,
			'updated_at' => $now,
		];
		$existing = $this->explorer->table('news_translation')
			->where('news_id', $translation->newsId)
			->where('locale', $translation->locale);

		// Not ON DUPLICATE KEY UPDATE: a clash on the (locale, slug) unique key would overwrite another item's row.
		try {
			if ($existing->count('*') > 0) {
				$existing->update($values + ['version' => new SqlLiteral('version + 1')]);
			} else {
				$this->explorer->table('news_translation')->insert($values + [
					'news_id' => $translation->newsId,
					'locale' => $translation->locale,
					'created_at' => $now,
				]);
			}
		} catch (UniqueConstraintViolationException $e) {
			// Only the slug key means "slug taken"; a clash on the primary key is a different problem and must surface.
			throw str_contains($e->getMessage(), self::SLUG_KEY) ? new InvalidSlugException(InvalidSlugException::TAKEN, $e) : $e;
		}
	}


	public function isSlugTaken(string $locale, string $slug, ?int $exceptNewsId): bool
	{
		$selection = $this->explorer->table('news_translation')
			->where('locale', $locale)
			->where('slug', $slug);
		if ($exceptNewsId !== null) {
			$selection->where('news_id <> ?', $exceptNewsId);
		}

		return $selection->count('*') > 0;
	}


	public function deleteNews(int $id): void
	{
		$this->explorer->table('news')->where('id', $id)->delete();
	}


	/** @return list<News> */
	public function findAll(int $limit, int $offset): array
	{
		$rows = $this->explorer->query(
			'SELECT ' . self::NEWS_COLUMNS . ' FROM news n
			ORDER BY n.published_at IS NULL DESC, n.published_at DESC, n.id DESC
			LIMIT ? OFFSET ?',
			$limit,
			$offset,
		)->fetchAll();

		return array_map(fn (Row $row): News => $this->hydrateNews($row), $rows);
	}


	public function countAll(): int
	{
		return self::int($this->explorer->query('SELECT COUNT(*) FROM news')->fetchField());
	}


	/**
	 * The one visibility condition of the website (listing, count, detail); NewsState::of() is its admin counterpart.
	 * Expects the aliases `n` (news) and `t` (news_translation).
	 *
	 * @return array{literal-string, list<string>}
	 */
	private function visible(): array
	{
		return [
			't.status = ? AND n.published_at IS NOT NULL AND n.published_at <= ?',
			[PublicationStatus::Published->value, $this->now()],
		];
	}


	private function hydrateNews(Row $row): News
	{
		return new News(
			self::int($row->id),
			$row->published_at_text === null ? null : self::readTime($row->published_at_text),
			$row->author_id === null ? null : self::int($row->author_id),
			self::int($row->news_version),
			self::readTime($row->created_at_text),
		);
	}


	private function hydrateTranslation(Row $row): NewsTranslation
	{
		return new NewsTranslation(
			self::int($row->news_id),
			self::string($row->locale),
			self::string($row->title),
			self::string($row->slug),
			self::string($row->perex),
			self::string($row->content),
			self::string($row->meta_description),
			PublicationStatus::from(self::string($row->status)),
			self::readTime($row->updated_at_text),
			self::int($row->version),
		);
	}


	private function now(): string
	{
		return self::format($this->clock->now());
	}


	private static function format(DateTimeImmutable $time): string
	{
		return $time->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
	}


	private static function readTime(mixed $text): DateTimeImmutable
	{
		$time = is_string($text) ? DateTimeImmutable::createFromFormat('!' . self::FORMAT, $text, new DateTimeZone('UTC')) : false;
		if ($time === false) {
			throw new UnexpectedValueException('Stored timestamp is not a valid UTC datetime.');
		}

		return $time;
	}


	private static function int(mixed $value): int
	{
		assert(is_int($value) || is_string($value));

		return (int) $value;
	}


	private static function string(mixed $value): string
	{
		assert(is_string($value));

		return $value;
	}
}

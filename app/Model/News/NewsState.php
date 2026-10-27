<?php

declare(strict_types=1);

namespace App\Model\News;

use App\Model\PublicationStatus;
use DateTimeImmutable;

enum NewsState
{
	case Draft;
	case Scheduled;
	case Visible;
	case Missing;


	/**
	 * The admin's view of the visibility rule NewsRepository applies on the website; both must agree.
	 * Compared in whole seconds, as stored. A published translation without a date (never stored: publishing a translation sets the date) counts as Draft.
	 */
	public static function of(News $news, ?NewsTranslation $translation, DateTimeImmutable $now): self
	{
		if ($translation === null) {
			return self::Missing;
		}
		if ($translation->status !== PublicationStatus::Published || $news->publishedAt === null) {
			return self::Draft;
		}

		return $news->publishedAt->getTimestamp() <= $now->getTimestamp() ? self::Visible : self::Scheduled;
	}
}

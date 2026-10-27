<?php

declare(strict_types=1);

namespace App\Presentation\Admin\News;

use DateTimeImmutable;

final class NewsPropertiesFormData
{
	/** Typed wall time labelled UTC by the control; null when empty (see NewsPresenter::publishedAt()). */
	public ?DateTimeImmutable $publishedAt;

	/** News version the form was rendered from. */
	public string $version;
}

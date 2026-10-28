<?php

declare(strict_types=1);

namespace App\Model\ArchProbe;

use League\CommonMark\MarkdownConverter;

final class ModelWithCommonMark
{
	public function __construct(public readonly MarkdownConverter $converter)
	{
	}
}

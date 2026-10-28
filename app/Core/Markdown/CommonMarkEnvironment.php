<?php

declare(strict_types=1);

namespace App\Core\Markdown;

use App\Core\Markdown\Component\ComponentExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;

/** The one environment of checking and rendering, so that both find the same markers. */
final class CommonMarkEnvironment
{
	public static function create(): Environment
	{
		// Both options default to the unsafe value in league/commonmark (html_input: allow, allow_unsafe_links: true).
		$environment = new Environment([
			'html_input' => 'escape',
			'allow_unsafe_links' => false,
		]);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new ComponentExtension());

		return $environment;
	}
}

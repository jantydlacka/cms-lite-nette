<?php

declare(strict_types=1);

namespace App\Model\News;

use App\Model\Content\ContentComponent;
use App\Model\Content\ContentKind;
use App\Model\Content\InvalidComponentArguments;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use stdClass;

/** `::: novinky [count]`: the newest visible news of the content's language. */
final class NewsListComponent implements ContentComponent
{
	private const int DefaultCount = 3;


	public function __construct(private readonly NewsRepository $news)
	{
	}


	public function name(): string
	{
		return 'novinky';
	}


	public function allowedIn(ContentKind $kind): bool
	{
		return $kind === ContentKind::Page;
	}


	/**
	 * @param list<string> $arguments
	 * @return array<string, mixed>
	 */
	public function arguments(array $arguments): array
	{
		if (count($arguments) > 1) {
			throw new InvalidComponentArguments('news.component.arguments');
		}

		$schema = Expect::structure([
			'count' => Expect::string((string) self::DefaultCount)->pattern('[1-9]|1[0-9]|20'),
		]);

		try {
			$data = (new Processor())->process($schema, $arguments === [] ? [] : ['count' => $arguments[0]]);
		} catch (ValidationException) {
			throw new InvalidComponentArguments('news.component.arguments');
		}

		assert($data instanceof stdClass && (is_int($data->count) || is_string($data->count)));

		return ['count' => (int) $data->count];
	}


	/**
	 * @param array<string, mixed> $arguments as returned by arguments()
	 * @return list<NewsListItem>
	 */
	public function items(array $arguments, string $locale): array
	{
		$count = $arguments['count'] ?? self::DefaultCount;
		assert(is_int($count));

		return $this->news->findVisible($locale, $count, 0);
	}
}

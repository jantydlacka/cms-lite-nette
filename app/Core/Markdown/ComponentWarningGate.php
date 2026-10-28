<?php

declare(strict_types=1);

namespace App\Core\Markdown;

use App\Model\Content\ContentContext;
use Nette\Caching\Cache;
use Nette\Caching\Storage;

/**
 * Lets the warning about a broken stored marker through about once a day per content, language and line;
 * the key holds a hash of the content, so an edited content is reported again. Without a lock, concurrent first
 * views can both pass, and a cache that cannot be written lets every view pass.
 */
final class ComponentWarningGate
{
	private const ExpireSeconds = 86400;

	private readonly Cache $cache;


	public function __construct(Storage $storage)
	{
		$this->cache = new Cache($storage, 'cms.componentWarnings');
	}


	public function pass(ContentContext $context, int $line, string $content): bool
	{
		$key = "{$context->kind->value}:$context->id:$context->locale:$line:" . sha1($content);
		if ($this->cache->load($key) !== null) {
			return false;
		}
		$this->cache->save($key, true, [Cache::Expire => self::ExpireSeconds]);

		return true;
	}
}

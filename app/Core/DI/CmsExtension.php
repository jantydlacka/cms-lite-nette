<?php

declare(strict_types=1);

namespace App\Core\DI;

use App\Model\CmsConfig;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use stdClass;

/**
 * @property-read stdClass $config
 */
final class CmsExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'siteName' => Expect::string()->required()->assert(static fn (mixed $name): bool => is_string($name) && trim($name) !== '', 'siteName must not be empty'),
			'defaultLocale' => Expect::string('cs'),
			'locales' => Expect::listOf(Expect::string()->pattern('[a-z]{2}'))
				->min(1)
				->default(['cs'])
				->mergeDefaults(false)
				->assert(static fn (mixed $locales): bool => is_array($locales) && count($locales) === count(array_unique($locales, SORT_REGULAR)), 'locales must not contain duplicates'),
			'newsPerPage' => Expect::int(10)->min(1),
		])->assert(
			static fn (mixed $config): bool => $config instanceof stdClass
				&& is_array($config->locales)
				&& in_array($config->defaultLocale, $config->locales, true),
			'defaultLocale must be one of locales',
		);
	}


	public function loadConfiguration(): void
	{
		$config = $this->config;
		$this->getContainerBuilder()->addDefinition($this->prefix('config'))
			->setFactory(CmsConfig::class, [
				$config->siteName,
				$config->defaultLocale,
				$config->locales,
				$config->newsPerPage,
			]);
	}
}

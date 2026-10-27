<?php

declare(strict_types=1);

namespace App\Core\DI;

use App\Core\Security\CmsAuthenticator;
use App\Model\CmsConfig;
use App\Model\Login\LoginLimits;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
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
			'login' => Expect::structure([
				'maxPairFailures' => Expect::int(5)->min(1),
				'maxIpFailures' => Expect::int(20)->min(1),
				'windowMinutes' => Expect::int(15)->min(1),
			]),
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
		/** @var stdClass $login */
		$login = $config->login;
		$this->getContainerBuilder()->addDefinition($this->prefix('config'))
			->setFactory(CmsConfig::class, [
				$config->siteName,
				$config->defaultLocale,
				$config->locales,
				$config->newsPerPage,
			]);
		$this->getContainerBuilder()->addDefinition($this->prefix('loginLimits'))
			->setFactory(LoginLimits::class, [
				$login->maxPairFailures,
				$login->maxIpFailures,
				$login->windowMinutes,
			]);
	}


	public function beforeCompile(): void
	{
		// Computed per container build, so it always matches the PHP default algorithm and cost.
		// The container cache key includes the PHP minor version.
		// Containers without the security services (extension-only tests) have nothing to patch.
		foreach ($this->getContainerBuilder()->findByType(CmsAuthenticator::class) as $authenticator) {
			assert($authenticator instanceof ServiceDefinition);
			$authenticator->getFactory()->arguments['dummyHash'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
		}
	}
}

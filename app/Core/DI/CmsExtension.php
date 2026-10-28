<?php

declare(strict_types=1);

namespace App\Core\DI;

use App\Core\Security\CmsAuthenticator;
use App\Model\CmsConfig;
use App\Model\Content\ComponentRegistry;
use App\Model\Content\ContentComponent;
use App\Model\Login\LoginLimits;
use App\Model\News\NewsListComponent;
use DateTimeZone;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
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
			'timezone' => Expect::string('Europe/Prague')->assert(static fn (mixed $zone): bool => is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true), 'timezone must be a valid time zone identifier'),
			'newsInMenu' => Expect::bool(true),
			'components' => Expect::arrayOf(
				Expect::string()->assert(
					static fn (mixed $class): bool => is_string($class) && is_a($class, ContentComponent::class, true),
					'component class must exist and implement ' . ContentComponent::class,
				),
				Expect::string()->pattern('[a-z][a-z0-9-]*'),
			)->default(['novinky' => NewsListComponent::class]),
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
		// Only from the static %demoData% parameter (env CMS_DEMO_DATA or debug mode), never a cms: key.
		$demoData = $this->getContainerBuilder()->parameters['demoData'] ?? false;
		/** @var stdClass $login */
		$login = $config->login;
		$this->getContainerBuilder()->addDefinition($this->prefix('config'))
			->setFactory(CmsConfig::class, [
				$config->siteName,
				$config->defaultLocale,
				$config->locales,
				$config->newsPerPage,
				$demoData === true,
				$config->timezone,
				$config->newsInMenu,
			]);
		$this->getContainerBuilder()->addDefinition($this->prefix('loginLimits'))
			->setFactory(LoginLimits::class, [
				$login->maxPairFailures,
				$login->maxIpFailures,
				$login->windowMinutes,
			]);

		// Typed as the port and not autowired: the registry is the only way to them.
		$components = [];
		/** @var array<string, class-string<ContentComponent>> $classes */
		$classes = $config->components;
		foreach (array_keys($classes) as $index => $name) {
			// Numbered, because a component name may contain hyphens, which a service name may not.
			$service = $this->prefix('component' . $index);
			$this->getContainerBuilder()->addDefinition($service)
				->setFactory($classes[$name])
				->setType(ContentComponent::class)
				->setAutowired(false);
			$components[$name] = new Reference($service);
		}
		$this->getContainerBuilder()->addDefinition($this->prefix('componentRegistry'))
			->setFactory(ComponentRegistry::class, [$components]);
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

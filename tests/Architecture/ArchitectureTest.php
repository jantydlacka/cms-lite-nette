<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Dependency boundaries, enforced by PHPStan (see phpstan.neon).
 */
final class ArchitectureTest
{
	private const string DatabaseAdapter = '/^App\\\\Model\\\\[^\\\\]+\\\\Database\\\\/';

	/** Domains under App\Model; keep in sync with app/Model/*. */
	private const array Domains = ['Content', 'Login', 'Page', 'Redirect', 'Setting', 'User'];


	#[TestRule]
	public function modelUsesOnlyAllowedVendorNamespaces(): Rule
	{
		return PHPat::rule()
			->classes(
				Selector::inNamespace('App\Model'),
			)
			->excluding(Selector::classname(self::DatabaseAdapter, true))
			->canOnly()->dependOn()
			->classes(
				Selector::inNamespace('App\Model'),
				Selector::inNamespace('Nette\Utils'),
				Selector::inNamespace('Psr\Clock'),
			)
			->because('The domain model may use only Nette\Utils and Psr\Clock from vendors.');
	}


	#[TestRule]
	public function modelDoesNotUseFrameworkNamespaces(): Rule
	{
		return PHPat::rule()
			->classes(
				Selector::inNamespace('App\Model'),
			)
			->excluding(Selector::classname(self::DatabaseAdapter, true))
			->shouldNot()->dependOn()
			->classes(
				Selector::inNamespace('Nette\Application'),
				Selector::inNamespace('Nette\Forms'),
				Selector::inNamespace('Nette\Http'),
				Selector::inNamespace('Nette\Database'),
				Selector::inNamespace('Latte'),
				Selector::inNamespace('Tracy'),
				Selector::inNamespace('Nextras'),
			)
			->because('Framework, database and rendering code belongs outside the domain model.');
	}


	/** Adapters are exempt from the two rules above only to reach Nette\Database. */
	#[TestRule]
	public function databaseAdapterUsesOnlyAllowedVendorNamespaces(): Rule
	{
		return PHPat::rule()
			->classes(Selector::classname(self::DatabaseAdapter, true))
			->canOnly()->dependOn()
			->classes(
				Selector::inNamespace('App\Model'),
				Selector::inNamespace('Nette\Utils'),
				Selector::inNamespace('Psr\Clock'),
				Selector::inNamespace('Nette\Database'),
			)
			->because('A model database adapter may add only Nette\Database to the model vendor allowlist.');
	}


	#[TestRule]
	public function modelDoesNotDependOnApplicationLayers(): Rule
	{
		return PHPat::rule()
			->classes(
				Selector::inNamespace('App\Model'),
			)
			->shouldNot()->dependOn()
			->classes(
				Selector::inNamespace('App\Presentation'),
				Selector::inNamespace('App\Core'),
			)
			->because('Dependencies point towards the model, never out of it.');
	}


	#[TestRule]
	public function layersDoNotDependOnDatabaseAdapters(): Rule
	{
		return PHPat::rule()
			->classes(
				Selector::inNamespace('App\Presentation'),
				Selector::inNamespace('App\Core'),
			)
			->shouldNot()->dependOn()
			->classes(Selector::classname(self::DatabaseAdapter, true))
			->because('Adapters are wired only in DI; layers above the model know only interfaces.');
	}


	/**
	 * phpat cannot refer to the subject's domain from the target selector, so each domain is listed.
	 * A new domain under App\Model must be added to Domains by hand.
	 *
	 * @return iterable<string, Rule> identifiers get the domain name appended
	 */
	#[TestRule]
	public function modelDoesNotDependOnOtherDomainAdapters(): iterable
	{
		foreach (self::Domains as $domain) {
			yield $domain => PHPat::rule()
				->classes(Selector::classname('/^App\\\\Model\\\\' . $domain . '\\\\/', true))
				->shouldNot()->dependOn()
				->classes(Selector::classname('/^App\\\\Model\\\\(?!' . $domain . '\\\\)[^\\\\]+\\\\Database\\\\/', true))
				->because('A domain depends on other domains only through their interfaces and records, never their adapters.');
		}
	}


	#[TestRule]
	public function coreDoesNotDependOnPresentation(): Rule
	{
		return PHPat::rule()
			->classes(Selector::inNamespace('App\\Core'))
			->shouldNot()->dependOn()
			->classes(Selector::inNamespace('App\\Presentation'))
			->because('Core is below the presentation layer.');
	}


	/** Classes directly in App\Model belong to no domain, so the per-domain rule does not cover them. */
	#[TestRule]
	public function rootModelDoesNotDependOnDatabaseAdapters(): Rule
	{
		return PHPat::rule()
			->classes(Selector::classname('/^App\\\\Model\\\\[^\\\\]+$/', true))
			->shouldNot()->dependOn()
			->classes(Selector::classname(self::DatabaseAdapter, true))
			->because('Model classes outside any domain know no adapters.');
	}
}

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
}

<?php

declare(strict_types=1);

use Tester\Assert;
use Tests\Architecture\ArchitectureTest;

require __DIR__ . '/../bootstrap.php';


test('ArchitectureTest::Domains lists every directory under app/Model', function (): void {
	$directories = glob(__DIR__ . '/../../app/Model/*', GLOB_ONLYDIR);
	if ($directories === false) {
		throw new RuntimeException('Cannot list app/Model.');
	}

	$actual = array_map('basename', $directories);
	sort($actual);

	$listed = (new ReflectionClassConstant(ArchitectureTest::class, 'Domains'))->getValue();
	/** @var list<string> $listed */
	sort($listed);

	Assert::same($actual, $listed);
});

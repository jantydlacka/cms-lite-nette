<?php

declare(strict_types=1);

use App\Bootstrap;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('debug mode is enabled only by the exact value 1', function (): void {
	Assert::true(Bootstrap::isDebugMode('1'));
	Assert::false(Bootstrap::isDebugMode('0'));
	Assert::false(Bootstrap::isDebugMode(false));
	Assert::false(Bootstrap::isDebugMode('true'));
});


test('demo data follows debug mode unless CMS_DEMO_DATA is set', function (): void {
	Assert::true(Bootstrap::isDemoData(false, true));
	Assert::false(Bootstrap::isDemoData(false, false));
	Assert::true(Bootstrap::isDemoData('1', false));
	Assert::false(Bootstrap::isDemoData('0', true));
	Assert::false(Bootstrap::isDemoData('', true));
});

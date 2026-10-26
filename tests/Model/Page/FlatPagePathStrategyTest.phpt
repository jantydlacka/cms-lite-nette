<?php

declare(strict_types=1);

use App\Model\Page\FlatPagePathStrategy;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

test('the path is the slug, ancestors are ignored on purpose', function (): void {
	$strategy = new FlatPagePathStrategy();
	Assert::same('o-nas', $strategy->pathFor('o-nas', []));
	Assert::same('o-nas', $strategy->pathFor('o-nas', ['firma', 'tym']));
});

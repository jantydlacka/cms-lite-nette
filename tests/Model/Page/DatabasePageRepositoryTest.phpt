<?php

declare(strict_types=1);

use App\Model\Page\Database\DatabasePageRepository;
use App\Model\Page\PageRepository;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Model\Page\PageRepositoryContract;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$repo = new DatabasePageRepository($explorer, $clock);
$reset = static fn () => TestDatabase::prepare($container);

PageRepositoryContract::run($repo, $reset, static fn (DateTimeImmutable $now) => $clock->set($now));

test('the container autowires the database adapter for PageRepository', function () use ($container): void {
	Assert::type(DatabasePageRepository::class, $container->getByType(PageRepository::class));
});

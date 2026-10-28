<?php

declare(strict_types=1);

use App\Core\Markdown\ComponentWarningGate;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentKind;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\MemoryStorage;
use Tester\Assert;

use function Tests\container;

require __DIR__ . '/../../bootstrap.php';


function context(ContentKind $kind = ContentKind::Page, int $id = 7, string $locale = 'cs'): ContentContext
{
	return new ContentContext($kind, $id, $locale, false);
}


test('the gate lets a warning through once per content, language and line', function (): void {
	$gate = new ComponentWarningGate(new MemoryStorage());

	Assert::true($gate->pass(context(), 3, 'a'));
	Assert::false($gate->pass(context(), 3, 'a'));
	Assert::true($gate->pass(context(), 4, 'a'), 'another line');
	Assert::true($gate->pass(context(), 3, 'b'), 'changed content');
	Assert::true($gate->pass(context(locale: 'en'), 3, 'a'), 'another language');
	Assert::true($gate->pass(context(id: 8), 3, 'a'), 'another id');
	Assert::true($gate->pass(context(ContentKind::News), 3, 'a'), 'another kind');
});


test('the gate remembers a warning for one day', function (): void {
	$storage = new class() implements Storage {
		/** @var list<array{string, array<string, mixed>}> */
		public array $writes = [];


		public function read(string $key): mixed
		{
			return null;
		}


		public function lock(string $key): void
		{
		}


		/** @param array<string, mixed> $dependencies */
		public function write(string $key, mixed $data, array $dependencies): void
		{
			$this->writes[] = [$key, $dependencies];
		}


		public function remove(string $key): void
		{
		}


		/** @param array<string, mixed> $conditions */
		public function clean(array $conditions): void
		{
		}
	};

	(new ComponentWarningGate($storage))->pass(context(ContentKind::News, 12, 'en'), 5, "::: neznamy\n");

	Assert::count(1, $storage->writes);
	$dependencies = $storage->writes[0][1];
	$expire = $dependencies[Cache::Expire] ?? null;
	assert(is_int($expire));
	Assert::true($expire >= 86399 && $expire <= 86400, (string) $expire);
});


test('the gate stores the warning under the kind, id, language, line and content hash', function (): void {
	$storage = new MemoryStorage();
	(new ComponentWarningGate($storage))->pass(context(ContentKind::News, 12, 'en'), 5, "::: neznamy\n");

	Assert::true((new Cache($storage, 'cms.componentWarnings'))->load('news:12:en:5:' . sha1("::: neznamy\n")));
});


test('the container wires the gate', function (): void {
	Assert::type(ComponentWarningGate::class, container()->getByType(ComponentWarningGate::class));
});

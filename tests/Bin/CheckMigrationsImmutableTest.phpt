<?php

declare(strict_types=1);

use Tester\Assert;
use Tester\Helpers;

require __DIR__ . '/../bootstrap.php';

const GUARD = __DIR__ . '/../../bin/check-migrations-immutable';


/**
 * @param list<string> $command
 * @return array{int, string} exit code and combined output
 */
function execIn(string $cwd, array $command): array
{
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
	if ($process === false) {
		throw new RuntimeException('Cannot start the command.');
	}

	$output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return [proc_close($process), $output];
}


function gitIn(string $cwd, string ...$args): void
{
	[$code, $output] = execIn($cwd, ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', ...array_values($args)]);
	Assert::same(0, $code, $output);
}


/** Repository with `main` holding migrations/structures/0001.sql, and the current branch `work` created from it. */
function guardRepository(): string
{
	$dir = sys_get_temp_dir() . '/cms-migration-guard-' . bin2hex(random_bytes(4));
	mkdir($dir . '/migrations/structures', 0777, true);
	gitIn($dir, 'init', '-q');
	gitIn($dir, 'checkout', '-q', '-b', 'main');
	file_put_contents($dir . '/migrations/structures/0001.sql', "SELECT 1;\n");
	gitIn($dir, 'add', '.');
	gitIn($dir, 'commit', '-q', '-m', 'base');
	gitIn($dir, 'checkout', '-q', '-b', 'work');

	return $dir;
}


/** @return array{int, string} */
function runGuard(string $dir, string $base = 'main'): array
{
	$result = execIn($dir, [GUARD, $base]);
	Helpers::purge($dir);
	rmdir($dir);

	return $result;
}


function commitAll(string $dir): void
{
	gitIn($dir, 'add', '-A');
	gitIn($dir, 'commit', '-q', '-m', 'change');
}


test('an added migration passes', function (): void {
	$dir = guardRepository();
	file_put_contents($dir . '/migrations/structures/0002.sql', "SELECT 2;\n");
	commitAll($dir);

	Assert::same(0, runGuard($dir)[0]);
});


test('an edited migration fails and is named', function (): void {
	$dir = guardRepository();
	file_put_contents($dir . '/migrations/structures/0001.sql', "SELECT 2;\n");
	commitAll($dir);
	[$code, $output] = runGuard($dir);

	Assert::same(1, $code);
	Assert::contains('migrations/structures/0001.sql', $output);
});


test('a renamed migration fails', function (): void {
	$dir = guardRepository();
	gitIn($dir, 'mv', 'migrations/structures/0001.sql', 'migrations/structures/0009.sql');
	commitAll($dir);
	[$code, $output] = runGuard($dir);

	Assert::same(1, $code);
	Assert::contains('0001.sql', $output);
});


test('a deleted migration fails', function (): void {
	$dir = guardRepository();
	unlink($dir . '/migrations/structures/0001.sql');
	commitAll($dir);
	[$code, $output] = runGuard($dir);

	Assert::same(1, $code);
	Assert::contains('migrations/structures/0001.sql', $output);
});


test('a migration changed to a symlink fails', function (): void {
	$dir = guardRepository();
	unlink($dir . '/migrations/structures/0001.sql');
	symlink('elsewhere.sql', $dir . '/migrations/structures/0001.sql');
	commitAll($dir);
	[$code, $output] = runGuard($dir);

	Assert::same(1, $code);
	Assert::contains('migrations/structures/0001.sql', $output);
});


test('a missing base fails with code 2', function (): void {
	$dir = guardRepository();

	Assert::same(2, runGuard($dir, 'origin/main')[0]);
});


test('a migration added to the base after the branch was created passes', function (): void {
	$dir = guardRepository();
	gitIn($dir, 'checkout', '-q', 'main');
	file_put_contents($dir . '/migrations/structures/0002.sql', "SELECT 2;\n");
	commitAll($dir);
	gitIn($dir, 'checkout', '-q', 'work');
	file_put_contents($dir . '/other.txt', "x\n");
	commitAll($dir);

	Assert::same(0, runGuard($dir)[0]);
});


/**
 * Runs the pre-push hook's guard function in $dir (a repository without any remote).
 *
 * @return array{int, string}
 */
function runHookGuard(string $dir): array
{
	$result = execIn($dir, ['bash', '-c', 'set -eu; . "$1"; check_migrations_immutable', 'hook', __DIR__ . '/../../.githooks/common.sh']);
	Helpers::purge($dir);
	rmdir($dir);

	return $result;
}


test('without origin/main the hook compares against local main and fails on an edited migration', function (): void {
	$dir = guardRepository();
	file_put_contents($dir . '/migrations/structures/0001.sql', "SELECT 2;\n");
	commitAll($dir);
	[$code, $output] = runHookGuard($dir);

	Assert::same(1, $code, $output);
	Assert::contains('migrations/structures/0001.sql', $output);
});


test('without origin/main the hook passes an added migration against local main', function (): void {
	$dir = guardRepository();
	file_put_contents($dir . '/migrations/structures/0002.sql', "SELECT 2;\n");
	commitAll($dir);
	[$code, $output] = runHookGuard($dir);

	Assert::same(0, $code, $output);
	Assert::notContains('skipped', $output);
});


test('without origin/main and local main the hook skips with a warning', function (): void {
	$dir = guardRepository();
	gitIn($dir, 'branch', '-q', '-m', 'main', 'trunk');
	file_put_contents($dir . '/migrations/structures/0001.sql', "SELECT 2;\n");
	commitAll($dir);
	[$code, $output] = runHookGuard($dir);

	Assert::same(0, $code, $output);
	Assert::contains('migration guard skipped', $output);
});


test('without origin/main the hook on main itself skips with a warning', function (): void {
	$dir = guardRepository();
	gitIn($dir, 'checkout', '-q', 'main');
	[$code, $output] = runHookGuard($dir);

	Assert::same(0, $code, $output);
	Assert::contains('HEAD is main', $output);
});

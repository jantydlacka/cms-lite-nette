<?php

declare(strict_types=1);

use Tester\Assert;
use Tester\Helpers;

require __DIR__ . '/../bootstrap.php';

const LINT = __DIR__ . '/../../bin/latte-lint';


/** @return array{int, string} exit code and combined output */
function lintTemplate(string $template): array
{
	$dir = sys_get_temp_dir() . '/cms-latte-lint-' . bin2hex(random_bytes(4));
	mkdir($dir);
	file_put_contents($dir . '/t.latte', $template);
	$process = proc_open([LINT, $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if ($process === false) {
		throw new RuntimeException('Cannot start the linter.');
	}

	$output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($process);
	Helpers::purge($dir);
	rmdir($dir);

	return [$code, $output];
}


test('a valid template passes', function (): void {
	Assert::same(0, lintTemplate('<p>{$x|upper}</p>')[0]);
});


test('an unknown filter fails', function (): void {
	[$code, $output] = lintTemplate('<p>{$x|nosuchfilter}</p>');

	Assert::same(1, $code);
	Assert::contains('nosuchfilter', $output);
});


test('a syntax error fails', function (): void {
	Assert::same(1, lintTemplate('{if}')[0]);
});


test('the application templates lint without a reachable database', function (): void {
	$dir = realpath(__DIR__ . '/../../app');
	if ($dir === false) {
		throw new RuntimeException('The app directory is missing.');
	}

	$process = proc_open([LINT, $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['CMS_DB_HOST' => '127.0.0.1', 'CMS_DB_PORT' => '1'] + getenv());
	if ($process === false) {
		throw new RuntimeException('Cannot start the linter.');
	}

	$output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	Assert::same(0, proc_close($process), $output);
});

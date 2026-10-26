<?php

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

const BADGE_SCRIPT = __DIR__ . '/../../bin/coverage-badge.php';
const CLOVER_FIXTURE = __DIR__ . '/fixtures/clover.xml';


/**
 * @return array{int, string, string} exit code, stdout, stderr
 */
function badge(string ...$args): array
{
	$process = proc_open(
		[PHP_BINARY, BADGE_SCRIPT, ...array_values($args)],
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
	);
	if ($process === false) {
		throw new RuntimeException('Cannot start the script.');
	}

	$stdout = (string) stream_get_contents($pipes[1]);
	$stderr = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return [proc_close($process), $stdout, $stderr];
}


function tempFile(): string
{
	$path = tempnam(sys_get_temp_dir(), 'cms-badge-');
	if ($path === false) {
		throw new RuntimeException('Cannot create a temporary file.');
	}

	return $path;
}


function cloverWith(int $statements, int $covered): string
{
	$path = tempFile();
	file_put_contents($path, sprintf(
		'<?xml version="1.0"?><coverage><project><metrics statements="%d" coveredstatements="%d"/>'
		. '<file name="x"><metrics statements="1" coveredstatements="1"/></file></project></coverage>',
		$statements,
		$covered,
	));

	return $path;
}


test('150 of 200 statements renders 75% in orange, using project totals only', function (): void {
	$out = tempFile();
	[$code, , $stderr] = badge(CLOVER_FIXTURE, $out);

	Assert::same(0, $code);
	Assert::same('', $stderr);
	$svg = (string) file_get_contents($out);
	Assert::contains('75%', $svg);
	Assert::contains('coverage', $svg);
	Assert::contains('#fe7d37', $svg);
	Assert::notContains('#e05d44', $svg);
	Assert::notContains('#4c1', $svg);
	Assert::noError(static fn () => new SimpleXMLElement($svg));
	unlink($out);
});


test('zero statements renders 0% without dividing by zero', function (): void {
	$clover = cloverWith(0, 0);
	$out = tempFile();
	[$code, , $stderr] = badge($clover, $out);

	Assert::same(0, $code);
	Assert::same('', $stderr);
	Assert::contains('0%', (string) file_get_contents($out));
	Assert::contains('#e05d44', (string) file_get_contents($out));
	unlink($clover);
	unlink($out);
});


test('colour thresholds: below 50 red, below 80 orange, otherwise green (rounded like Tester)', function (): void {
	$cases = [[49, '#e05d44'], [50, '#fe7d37'], [79, '#fe7d37'], [80, '#4c1'], [100, '#4c1']];
	foreach ($cases as [$percent, $colour]) {
		$clover = cloverWith(100, $percent);
		$out = tempFile();
		badge($clover, $out);
		Assert::contains($colour, (string) file_get_contents($out), "$percent%");
		unlink($clover);
		unlink($out);
	}
});


test('missing input file exits non-zero with a message on stderr and writes nothing', function (): void {
	$out = sys_get_temp_dir() . '/cms-badge-missing-' . bin2hex(random_bytes(4)) . '.svg';
	[$code, $stdout, $stderr] = badge('/nonexistent/clover.xml', $out);

	Assert::notSame(0, $code);
	Assert::same('', $stdout);
	Assert::contains('clover.xml', $stderr);
	Assert::false(file_exists($out));
});


test('wrong argument count and malformed XML fail with a message', function (): void {
	[$code, , $stderr] = badge();
	Assert::notSame(0, $code);
	Assert::contains('Usage', $stderr);

	$bad = tempFile();
	file_put_contents($bad, '<coverage><project></project></coverage>');
	[$code, , $stderr] = badge($bad, $bad . '.svg');
	Assert::notSame(0, $code);
	Assert::notSame('', $stderr);
	unlink($bad);
});


test('percentage is rounded, not floored (94.6% shows 95%)', function (): void {
	$clover = cloverWith(1000, 946);
	$out = tempFile();
	badge($clover, $out);
	Assert::contains('95%', (string) file_get_contents($out));
	// 49.6% rounds up to 50% and leaves the red band.
	$clover2 = cloverWith(1000, 496);
	badge($clover2, $out);
	Assert::contains('#fe7d37', (string) file_get_contents($out));
	unlink($clover);
	unlink($clover2);
	unlink($out);
});


test('non-XML, covered > total and non-numeric counts fail with a message', function (): void {
	$cases = [
		'not xml at all',
		'<coverage><project><metrics statements="10" coveredstatements="11"/></project></coverage>',
		'<coverage><project><metrics statements="abc" coveredstatements="1"/></project></coverage>',
		'<coverage><project><metrics statements="-5" coveredstatements="-6"/></project></coverage>',
	];
	foreach ($cases as $content) {
		$in = tempFile();
		$out = $in . '.svg';
		file_put_contents($in, $content);
		[$code, , $stderr] = badge($in, $out);
		Assert::notSame(0, $code, $content);
		Assert::notSame('', $stderr, $content);
		Assert::false(file_exists($out), $content);
		unlink($in);
	}
});

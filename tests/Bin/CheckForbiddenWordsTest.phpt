<?php

declare(strict_types=1);

use Tester\Assert;
use Tester\Helpers;

require __DIR__ . '/../bootstrap.php';

const SCRIPT = __DIR__ . '/../../bin/check-forbidden-words';
const WORD_LIST = __DIR__ . '/fixtures/forbidden-words.txt';


/**
 * @param array<string, string> $env
 * @return array{int, string} exit code and combined output
 */
function run(string $cwd, ?string $wordList, array $env = [], string ...$prefix): array
{
	$process = proc_open(
		array_values([SCRIPT, ...$prefix, ...($wordList === null ? [] : [$wordList])]),
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
		$cwd,
		$env + getenv(),
	);
	if ($process === false) {
		throw new RuntimeException('Cannot start the script.');
	}

	$output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return [proc_close($process), $output];
}


function git(string $cwd, string ...$args): void
{
	$process = proc_open(
		['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', ...array_values($args)],
		[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
		$pipes,
		$cwd,
	);
	if ($process === false) {
		throw new RuntimeException('Cannot start git.');
	}

	Assert::same(0, proc_close($process));
}


/**
 * Creates a temporary repository with one tracked file and one commit.
 */
function repository(string $fileContent, string $commitMessage = 'chore: initial'): string
{
	$dir = sys_get_temp_dir() . '/cms-forbidden-words-' . bin2hex(random_bytes(4));
	mkdir($dir);
	git($dir, 'init', '-q');
	file_put_contents($dir . '/page.txt', $fileContent);
	git($dir, 'add', 'page.txt');
	git($dir, 'commit', '-q', '-m', $commitMessage);

	return $dir;
}


function cleanup(string $dir): void
{
	Helpers::purge($dir);
	rmdir($dir);
}


test('reports file and line of a tracked file with a match, without printing the word', function (): void {
	$dir = repository("clean line\ncontains forbiddenword here\n");
	[$code, $output] = run($dir, WORD_LIST);
	cleanup($dir);

	Assert::same(1, $code);
	Assert::contains('page.txt:2', $output);
	Assert::notContains('forbiddenword', $output);
});


test('reports a commit message with a match', function (): void {
	$dir = repository("clean\n", 'chore: mention forbiddenword');
	[$code, $output] = run($dir, WORD_LIST);
	cleanup($dir);

	Assert::same(1, $code);
	Assert::contains('commit message', $output);
	Assert::notContains('forbiddenword', $output);
});


test('passes when nothing matches', function (): void {
	$dir = repository("clean\n");
	[$code, $output] = run($dir, WORD_LIST);
	cleanup($dir);

	Assert::same(0, $code);
	Assert::notContains('forbiddenword', $output);
});


test('skips with a notice when the word list does not exist', function (): void {
	$dir = repository("contains forbiddenword here\n");
	[$code, $output] = run($dir, $dir . '/missing-list.txt');
	cleanup($dir);

	Assert::same(0, $code);
	Assert::contains('no word list, skipped', $output);
});


test('without an argument reads the list from CMS_FORBIDDEN_WORDS', function (): void {
	$dir = repository("contains forbiddenword here\n");
	[$code, $output] = run($dir, null, ['CMS_FORBIDDEN_WORDS' => WORD_LIST]);
	cleanup($dir);

	Assert::same(1, $code);
	Assert::contains('page.txt:1', $output);
});


test('without an argument and without a list skips with a notice', function (): void {
	$dir = repository("contains forbiddenword here\n");
	[$code, $output] = run($dir, null, ['CMS_FORBIDDEN_WORDS' => $dir . '/missing-list.txt']);
	cleanup($dir);

	Assert::same(0, $code);
	Assert::contains('no word list, skipped', $output);
});


test('--message checks only the given commit message file', function (): void {
	$dir = repository("clean\n");
	file_put_contents($dir . '/msg-bad', "feat: forbiddenword\n");
	file_put_contents($dir . '/msg-ok', "feat: fine\n");
	[$badCode, $badOutput] = run($dir, WORD_LIST, [], '--message', $dir . '/msg-bad');
	[$okCode] = run($dir, WORD_LIST, [], '--message', $dir . '/msg-ok');
	cleanup($dir);

	Assert::same(1, $badCode);
	Assert::contains('commit message', $badOutput);
	Assert::notContains('forbiddenword', $badOutput);
	Assert::same(0, $okCode);
});


test('an invalid pattern ends with a fixed message and exit code 2, without echoing the pattern', function (): void {
	$dir = repository("clean\n");
	file_put_contents($dir . '/bad-list.txt', "broken(pattern\n");
	[$code, $output] = run($dir, $dir . '/bad-list.txt');
	cleanup($dir);

	Assert::same(2, $code);
	Assert::same("invalid pattern in word list\n", $output);
});


test('an empty or comment-only list is skipped', function (): void {
	$dir = repository("contains forbiddenword here\n");
	file_put_contents($dir . '/empty-list.txt', "# comment\n\n   \n");
	[$code, $output] = run($dir, $dir . '/empty-list.txt');
	cleanup($dir);

	Assert::same(0, $code);
	Assert::contains('no word list, skipped', $output);
});


test('--message without a file or with a missing file is a usage error', function (): void {
	$dir = repository("clean\n");
	[$noFileCode, $noFileOutput] = run($dir, null, [], '--message');
	[$missingCode, $missingOutput] = run($dir, WORD_LIST, [], '--message', $dir . '/nope');
	cleanup($dir);

	Assert::same(2, $noFileCode);
	Assert::contains('usage:', $noFileOutput);
	Assert::same(2, $missingCode);
	Assert::contains('usage:', $missingOutput);
});


test('scans the index, not the working tree', function (): void {
	$dir = repository("clean\n");
	file_put_contents($dir . '/staged.txt', "x\nforbiddenword\n");
	git($dir, 'add', 'staged.txt');
	file_put_contents($dir . '/staged.txt', "clean again\n");
	file_put_contents($dir . '/untracked.txt', "forbiddenword\n");
	[$code, $output] = run($dir, WORD_LIST);
	cleanup($dir);

	Assert::same(1, $code);
	Assert::contains('staged.txt:2', $output);
	Assert::notContains('untracked.txt', $output);
});


test('reports paths containing a colon intact', function (): void {
	$dir = repository("clean\n");
	file_put_contents($dir . '/a:b.txt', "one\nforbiddenword\n");
	git($dir, 'add', 'a:b.txt');
	[$code, $output] = run($dir, WORD_LIST);
	cleanup($dir);

	Assert::same(1, $code);
	Assert::contains("a:b.txt:2\n", $output);
});

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Usage: php bin/coverage-badge.php <clover.xml> <out.svg>
 *
 * Renders a "coverage | NN%" badge from the project-level statement totals.
 */

function fail(string $message, int $code = 1): never
{
	fwrite(STDERR, $message . "\n");
	exit($code);
}


if ($argc !== 3) {
	fail('Usage: php bin/coverage-badge.php <clover.xml> <out.svg>', 2);
}

[, $input, $output] = $argv;
if (!is_file($input) || !is_readable($input)) {
	fail("Cannot read coverage file: $input");
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string((string) file_get_contents($input));
// Direct child only: per-file <metrics> elements must not be counted.
$metrics = $xml === false ? null : ($xml->project->metrics ?? null);
if ($metrics === null || !isset($metrics['statements'], $metrics['coveredstatements'])) {
	fail("Not a clover report with project metrics: $input");
}

$statements = (string) $metrics['statements'];
$covered = (string) $metrics['coveredstatements'];
if (!ctype_digit($statements) || !ctype_digit($covered) || (int) $covered > (int) $statements) {
	fail("Invalid statement counts in: $input");
}

$statements = (int) $statements;
$covered = (int) $covered;
$percent = $statements === 0 ? 0 : (int) round($covered / $statements * 100);

$colour = match (true) {
	$percent < 50 => '#e05d44',
	$percent < 80 => '#fe7d37',
	default => '#4c1',
};

$label = 'coverage';
$value = $percent . '%';
$labelWidth = 62;
$valueWidth = 42;
$width = $labelWidth + $valueWidth;
$labelX = $labelWidth / 2;
$valueX = $labelWidth + $valueWidth / 2;

$svg = <<<SVG
	<svg xmlns="http://www.w3.org/2000/svg" width="$width" height="20" role="img" aria-label="$label: $value">
	<title>$label: $value</title>
	<rect width="$labelWidth" height="20" fill="#555"/>
	<rect x="$labelWidth" width="$valueWidth" height="20" fill="$colour"/>
	<g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">
	<text x="$labelX" y="14">$label</text>
	<text x="$valueX" y="14">$value</text>
	</g>
	</svg>

	SVG;

if (file_put_contents($output, $svg) === false) {
	fail("Cannot write badge: $output");
}

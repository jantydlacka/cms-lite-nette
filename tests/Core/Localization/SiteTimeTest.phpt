<?php

declare(strict_types=1);

use App\Core\Localization\SiteTime;
use App\Model\CmsConfig;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$siteTime = new SiteTime(new CmsConfig('Demo', 'cs', ['cs'], 10));
$utc = new DateTimeZone('UTC');


test('the zone is the configured one', function () use ($siteTime): void {
	Assert::same('Europe/Prague', $siteTime->zone()->getName());
});


test('toSite shows the local calendar day across midnight', function () use ($siteTime, $utc): void {
	$local = $siteTime->toSite(new DateTimeImmutable('2025-12-31 23:30:00', $utc));
	Assert::same('2026-01-01 00:30:00', $local->format('Y-m-d H:i:s'));
	Assert::same('Europe/Prague', $local->getTimezone()->getName());
});


test('fromInput reads the wall time in the site zone and returns UTC', function () use ($siteTime, $utc): void {
	$winter = $siteTime->fromInput(new DateTimeImmutable('2026-11-01 08:00:00', $utc));
	Assert::same('2026-11-01 07:00:00', $winter->format('Y-m-d H:i:s'));
	Assert::same('UTC', $winter->getTimezone()->getName());

	Assert::same('2026-07-01 06:00:00', $siteTime->fromInput(new DateTimeImmutable('2026-07-01 08:00:00', $utc))->format('Y-m-d H:i:s'));
});


test('fromInput ignores the zone of its argument', function () use ($siteTime): void {
	$other = new DateTimeImmutable('2026-07-01 08:00:00', new DateTimeZone('Asia/Tokyo'));
	Assert::same('2026-07-01 06:00:00', $siteTime->fromInput($other)->format('Y-m-d H:i:s'));
});


test('a local time skipped by the spring change is shifted forward', function () use ($siteTime, $utc): void {
	$moment = $siteTime->fromInput(new DateTimeImmutable('2026-03-29 02:30:00', $utc));
	Assert::same('2026-03-29 01:30:00', $moment->format('Y-m-d H:i:s'));
	Assert::same('2026-03-29 03:30:00', $siteTime->toSite($moment)->format('Y-m-d H:i:s'));
});


test('an ambiguous local time of the autumn change is the second occurrence', function () use ($siteTime, $utc): void {
	$moment = $siteTime->fromInput(new DateTimeImmutable('2026-10-25 02:30:00', $utc));
	Assert::same('2026-10-25 01:30:00', $moment->format('Y-m-d H:i:s'));
	Assert::same('+01:00', $siteTime->toSite($moment)->format('P'));
});


test('another configured zone is used', function () use ($utc): void {
	$tokyo = new SiteTime(new CmsConfig('Demo', 'cs', ['cs'], 10, false, 'Asia/Tokyo'));
	Assert::same('2026-01-01 09:00:00', $tokyo->toSite(new DateTimeImmutable('2026-01-01 00:00:00', $utc))->format('Y-m-d H:i:s'));
});

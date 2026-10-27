<?php

declare(strict_types=1);

use App\Model\News\News;
use App\Model\News\NewsState;
use App\Model\News\NewsTranslation;
use App\Model\PublicationStatus;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$now = new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC'));
$news = static fn (?DateTimeImmutable $at): News => new News(1, $at, null, 1, $now);
$translation = static fn (PublicationStatus $status): NewsTranslation => new NewsTranslation(1, 'cs', 'T', 't', 'P', '', '', $status, $now, 1);

test('without a translation the state is Missing whatever the date', function () use ($news, $now): void {
	Assert::same(NewsState::Missing, NewsState::of($news(null), null, $now));
	Assert::same(NewsState::Missing, NewsState::of($news($now->modify('-1 day')), null, $now));
});

test('a draft translation is Draft whatever the date', function () use ($news, $translation, $now): void {
	foreach ([null, $now->modify('-1 day'), $now, $now->modify('+1 day')] as $at) {
		Assert::same(NewsState::Draft, NewsState::of($news($at), $translation(PublicationStatus::Draft), $now));
	}
});

test('a published translation is Visible from its date on, Scheduled before it', function () use ($news, $translation, $now): void {
	$published = $translation(PublicationStatus::Published);
	Assert::same(NewsState::Visible, NewsState::of($news($now->modify('-1 day')), $published, $now));
	Assert::same(NewsState::Visible, NewsState::of($news($now), $published, $now));
	Assert::same(NewsState::Scheduled, NewsState::of($news($now->modify('+1 second')), $published, $now));
});

test('the moment compares across time zones', function () use ($news, $translation, $now): void {
	$prague = new DateTimeImmutable('2026-01-15 13:00:00', new DateTimeZone('Europe/Prague'));
	Assert::same(NewsState::Visible, NewsState::of($news($prague), $translation(PublicationStatus::Published), $now));
});

test('a published translation without a date is not visible (publishing never stores one; listed as Draft)', function () use ($news, $translation, $now): void {
	Assert::same(NewsState::Draft, NewsState::of($news(null), $translation(PublicationStatus::Published), $now));
});

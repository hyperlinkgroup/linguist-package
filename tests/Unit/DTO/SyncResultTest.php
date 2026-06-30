<?php

use Hyperlinkgroup\Linguist\DTO\SyncResult;

test('sync result correctly identifies overall success', function () {
	$result = new SyncResult(
		overallSuccess: true,
		languageResults: [
			'EN' => ['success' => true],
			'DE' => ['success' => true],
		],
	);

	expect($result->overallSuccess)->toBeTrue()
		->and($result->hasPartialSuccess())->toBeFalse();
});

test('sync result correctly identifies partial success', function () {
	$result = new SyncResult(
		overallSuccess: true,
		languageResults: [
			'EN' => ['success' => true],
			'DE' => ['success' => false, 'message' => 'Failed'],
		],
	);

	expect($result->hasPartialSuccess())->toBeTrue()
		->and($result->getFailedLanguages())->toBe(['DE'])
		->and($result->getSuccessfulLanguages())->toBe(['EN']);
});

test('sync result returns all languages as failed when none succeed', function () {
	$result = new SyncResult(
		overallSuccess: false,
		languageResults: [
			'EN' => ['success' => false],
			'DE' => ['success' => false],
		],
	);

	expect($result->hasPartialSuccess())->toBeFalse()
		->and($result->getFailedLanguages())->toHaveCount(2)
		->and($result->getSuccessfulLanguages())->toBeEmpty();
});

test('sync result generates summary for full success', function () {
	$result = new SyncResult(
		overallSuccess: true,
		languageResults: [
			'EN' => ['success' => true],
			'DE' => ['success' => true],
		],
		keysProcessed: 100,
	);

	$summary = $result->getSummaryMessage();

	expect($summary)->toContain('All languages synced successfully')
		->and($summary)->toContain('100 keys');
});

test('sync result generates summary for partial success', function () {
	$result = new SyncResult(
		overallSuccess: true,
		languageResults: [
			'EN' => ['success' => true],
			'DE' => ['success' => false, 'message' => 'Failed'],
			'FR' => ['success' => false, 'message' => 'Failed'],
		],
		keysProcessed: 50,
		keysFailed: 10,
	);

	$summary = $result->getSummaryMessage();

	expect($summary)->toContain('Partial sync completed')
		->and($summary)->toContain('1 languages successful')
		->and($summary)->toContain('2 languages failed');
});

test('sync result generates summary for failure', function () {
	$result = new SyncResult(
		overallSuccess: false,
		errors: ['Connection failed', 'Invalid credentials'],
	);

	$summary = $result->getSummaryMessage();

	expect($summary)->toContain('Sync failed')
		->and($summary)->toContain('Connection failed');
});

test('sync result handles empty results', function () {
	$result = new SyncResult(overallSuccess: false);

	expect($result->hasPartialSuccess())->toBeFalse()
		->and($result->getFailedLanguages())->toBeEmpty()
		->and($result->getSuccessfulLanguages())->toBeEmpty();
});

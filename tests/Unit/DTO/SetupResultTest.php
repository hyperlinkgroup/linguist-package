<?php

use Hyperlinkgroup\Linguist\DTO\SetupResult;
use Hyperlinkgroup\Linguist\DTO\SyncResult;

test('setup result has correct default values', function () {
	$result = new SetupResult;

	expect($result->configPersisted)->toBeFalse()
		->and($result->projectCreated)->toBeFalse()
		->and($result->projectSlug)->toBeNull()
		->and($result->syncResult)->toBeNull()
		->and($result->autoTranslate)->toBeFalse()
		->and($result->errors)->toBe([]);
});

test('setup result reports no errors when errors are empty', function () {
	$result = new SetupResult;

	expect($result->hasErrors())->toBeFalse();
});

test('setup result reports errors when errors are present', function () {
	$result = new SetupResult(errors: ['Something went wrong']);

	expect($result->hasErrors())->toBeTrue();
});

test('setup result holds all assigned properties', function () {
	$syncResult = new SyncResult(overallSuccess: true);

	$result = new SetupResult(
		configPersisted: true,
		projectCreated: true,
		projectSlug: 'my-project',
		syncResult: $syncResult,
		autoTranslate: true,
		errors: [],
	);

	expect($result->configPersisted)->toBeTrue()
		->and($result->projectCreated)->toBeTrue()
		->and($result->projectSlug)->toBe('my-project')
		->and($result->syncResult)->toBe($syncResult)
		->and($result->autoTranslate)->toBeTrue()
		->and($result->hasErrors())->toBeFalse();
});

test('setup result with multiple errors reports has errors', function () {
	$result = new SetupResult(errors: ['Error one', 'Error two']);

	expect($result->hasErrors())->toBeTrue()
		->and($result->errors)->toHaveCount(2);
});

<?php

use Hyperlinkgroup\Linguist\DTO\SetupInput;

test('setup input correctly identifies new project', function () {
	$input = new SetupInput(
		apiToken: 'test-token',
		newProjectName: 'My New Project',
	);

	expect($input->isNewProject())->toBeTrue()
		->and($input->isExistingProject())->toBeFalse();
});

test('setup input correctly identifies existing project', function () {
	$input = new SetupInput(
		apiToken: 'test-token',
		projectSlug: 'existing-project',
	);

	expect($input->isExistingProject())->toBeTrue()
		->and($input->isNewProject())->toBeFalse();
});

test('setup input returns correct project identifier', function () {
	$newInput = new SetupInput(
		apiToken: 'test-token',
		newProjectName: 'New Project',
	);

	$existingInput = new SetupInput(
		apiToken: 'test-token',
		projectSlug: 'existing-slug',
	);

	expect($newInput->getProjectIdentifier())->toBe('New Project')
		->and($existingInput->getProjectIdentifier())->toBe('existing-slug');
});

test('setup input can be created from array', function () {
	$data = [
		'api_token' => 'array-token',
		'project_slug' => 'from-array',
		'sync_mode' => 'push',
		'prune_remote_keys' => true,
		'activate_missing_languages' => false,
		'trigger_auto_translate' => true,
	];

	$input = SetupInput::fromArray($data);

	expect($input->apiToken)->toBe('array-token')
		->and($input->projectSlug)->toBe('from-array')
		->and($input->syncMode)->toBe('push')
		->and($input->pruneRemoteKeys)->toBeTrue()
		->and($input->activateMissingLanguages)->toBeFalse()
		->and($input->triggerAutoTranslate)->toBeTrue();
});

test('setup input can be converted to array', function () {
	$input = new SetupInput(
		apiToken: 'test-token',
		projectSlug: 'my-project',
		syncMode: 'sync',
	);

	$array = $input->toArray();

	expect($array)->toHaveKeys([
		'api_token',
		'project_slug',
		'new_project_name',
		'new_project_team_id',
		'sync_mode',
		'prune_remote_keys',
		'activate_missing_languages',
		'trigger_auto_translate',
	])
		->and($array['api_token'])->toBe('test-token');
});

test('setup input has default values', function () {
	$input = new SetupInput(apiToken: 'token');

	expect($input->syncMode)->toBe('sync')
		->and($input->pruneRemoteKeys)->toBeFalse()
		->and($input->activateMissingLanguages)->toBeTrue()
		->and($input->triggerAutoTranslate)->toBeFalse()
		->and($input->newProjectTeamId)->toBeNull()
		->and($input->targetLanguageIds)->toBe([]);
});

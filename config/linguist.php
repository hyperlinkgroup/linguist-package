<?php

return [
	/*
	 |--------------------------------------------------------------------------
	 | Linguist API URL
	 |--------------------------------------------------------------------------
	 */
	'url' => env('LINGUIST_URL', 'https://api.linguist.eu/v2'),

	/*
	 |--------------------------------------------------------------------------
	 | Linguist Project Slug
	 |--------------------------------------------------------------------------
	 */
	'project' => env('LINGUIST_PROJECT', ''),

	/*
	 |--------------------------------------------------------------------------
	 | Linguist API Token
	 |--------------------------------------------------------------------------
	 */
	'token' => env('LINGUIST_TOKEN', ''),

	/*
	 |--------------------------------------------------------------------------
	 | Linguist API Token Settings URL
	 |--------------------------------------------------------------------------
	 */
	'api_tokens_url' => env('LINGUIST_API_TOKENS_URL', 'https://app.linguist.eu/settings/api-tokens'),

	/*
	 |--------------------------------------------------------------------------
	 | Temporary Directory for Translations while processing
	 |--------------------------------------------------------------------------
	 */
	'temporary_directory' => 'tmp/translations',

	/*
	 |--------------------------------------------------------------------------
	 | Pull Minified
	 |--------------------------------------------------------------------------
	 | When enabled, pulled translation files are written as minified JSON.
	 | By default, files are pretty-printed for readability and easier diffing.
	 |--------------------------------------------------------------------------
	 */
	'pull_minified' => env('LINGUIST_PULL_MINIFIED', false),
];

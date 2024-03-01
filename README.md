# This is my package linguist

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hyperlinkgroup/linguist.svg?style=flat-square)](https://packagist.org/packages/hyperlinkgroup/linguist)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/hyperlinkgroup/linguist-package/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/hyperlinkgroup/linguist/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/hyperlinkgroup/linguist-package/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/hyperlinkgroup/linguist/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/hyperlink/linguist.svg?style=flat-square)](https://packagist.org/packages/hyperlink/linguist)

A package to help you download your language files from [Linguist](https://app.linguist.eu).

## Installation

You can install the package via composer:

```bash
composer require hyperlinkgroup/linguist
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="linguist-config"
```

This is the contents of the published config file:

```php
return [
	/*
	 |--------------------------------------------------------------------------
	 | Linguist API URL
	 |--------------------------------------------------------------------------
	 */
	'url' => env('LINGUIST_URL', 'https://api.linguist.eu/'),

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
	 | Temporary Directory for Translations while processing
	 |--------------------------------------------------------------------------
	 */
	'temporary_directory' => 'tmp/translations',
];
```

## Usage

```php
\Hyperlinkgroup\Linguist\Linguist::handle();
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Katalam](https://github.com/Katalam)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

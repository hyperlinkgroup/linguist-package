# Linguist Connector for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hyperlinkgroup/linguist.svg?style=flat-square)](https://packagist.org/packages/hyperlinkgroup/linguist)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/hyperlinkgroup/linguist-package/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/hyperlinkgroup/linguist-package/actions?query=workflow%3ACI+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/hyperlinkgroup/linguist.svg?style=flat-square)](https://packagist.org/packages/hyperlinkgroup/linguist)

A package to help you manage your translation files with [Linguist](https://app.linguist.eu) — A better way to manage your language files.

<img src="./art/header.jpg" alt="linguist-package-header">

## Installation

You can install the package via composer:

```bash
composer require hyperlinkgroup/linguist
```

You can optionally publish the config file with:

```bash
php artisan vendor:publish --tag="linguist-config"
```

## Quick Start (Setup Wizard)

The easiest way to get started is using the interactive setup wizard:

```bash
php artisan linguist:setup
```

This wizard will guide you through:

1. **API Token** - Enter your Linguist API token
2. **Project Selection** - Choose an existing project or create a new one
3. **Sync Mode** - Select how you want to synchronize translations:
   - **Sync**: Merge local and remote translations (two-way)
   - **Pull**: Download from Linguist (overwrite local)
   - **Push**: Upload local translations to Linguist
4. **Options** - Configure setup preferences and optional auto-translation

### Interactive Only

`linguist:setup` now runs as an interactive wizard and prompts for all setup values during execution.

## Configuration

The published config file (`config/linguist.php`):

```php
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
```

The setup wizard automatically writes these values to your `.env` file.

`LINGUIST_URL` should point to the versioned API base URL (for example `https://api.your-domain.com/v2`).
`LINGUIST_API_TOKENS_URL` is the URL opened when users follow the **Linguist API settings** link printed before the token prompt (clickable in terminals that support hyperlinks; otherwise the label is still shown as plain text).

## Commands

### `linguist:setup`

Interactive wizard for initial setup and configuration. Handles project creation/selection, sync mode configuration, and API token management.

### `linguist:sync`

Runs translation synchronization in one of three modes (`sync`, `pull`, `push`).

```bash
php artisan linguist:sync
```

If run interactively, the command prompts for mode selection (same choices as setup).
For scheduler/non-interactive execution, the command defaults to `sync` mode unless you pass an explicit mode flag:

```bash
php artisan linguist:sync --sync
php artisan linguist:sync --pull
php artisan linguist:sync --push
```

Mode-specific options:

```bash
# sync mode options
php artisan linguist:sync --sync --prune-remote-keys
php artisan linguist:sync --sync --no-activate-missing-languages

# push mode options
php artisan linguist:sync --push --overwrite
php artisan linguist:sync --push --languages=EN,DE
```

`--prune-remote-keys` now deletes remote keys that no longer exist locally before upload.
`--no-activate-missing-languages` now skips uploading local languages that are not active in the remote project.
If missing-language activation is enabled (default), sync attempts to activate them before upload when resolvable from project metadata.

## Sync Modes

### Sync Mode (Recommended)

Performs a two-phase sync:

- Uploads local keys first (`push`)
- Downloads the resulting remote state (`pull`) into local files

### Pull Mode

Downloads all translations from Linguist and overwrites local files:

- Fetches all active languages
- Downloads translation files
- Replaces local files entirely in Laravel native locale JSON format (`lang/en.json`, `lang/de.json`, ...)

### Push Mode

Uploads local translations to Linguist:

- Reads all local translation files
- Creates/updates keys on remote
- Preserves remote-only keys

## Events

The package dispatches Laravel events after successful operations:

- `Hyperlinkgroup\Linguist\Events\SyncCompleted`
- `Hyperlinkgroup\Linguist\Events\PullCompleted`
- `Hyperlinkgroup\Linguist\Events\PushCompleted`

Each event contains:

- `$projectSlug` (string)
- `$result` (`Hyperlinkgroup\Linguist\DTO\SyncResult`)

Example listener:

```php
use Hyperlinkgroup\Linguist\Events\SyncCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(SyncCompleted::class, function (SyncCompleted $event): void {
    logger()->info('Linguist sync completed', [
        'project' => $event->projectSlug,
        'keys_processed' => $event->result->keysProcessed,
    ]);
});
```

## Advanced Usage

### Using Actions Directly

For custom implementations, you can call the provided actions directly:

```php
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Hyperlinkgroup\Linguist\Actions\SyncTranslations;

// API Client
$client = app(LinguistApiClient::class);
$projects = $client->listProjects()->json('data');

// Sync Action
$result = SyncTranslations::run(
    projectSlug: 'my-project',
    pruneRemoteKeys: true,
);

echo $result->getSummaryMessage();
```

### Data Transfer Objects

The package provides typed DTOs for configuration:

```php
use Hyperlinkgroup\Linguist\DTO\SetupInput;
use Hyperlinkgroup\Linguist\DTO\SyncResult;

$input = new SetupInput(
    apiToken: 'your-token',
    projectSlug: 'my-project',
    syncMode: 'sync',
    pruneRemoteKeys: false,
    activateMissingLanguages: true,
);

// From array
$input = SetupInput::fromArray([
    'api_token' => 'your-token',
    'project_slug' => 'my-project',
]);

// Sync results
$result = SyncTranslations::run(projectSlug: 'my-project');
$result->overallSuccess; // bool
$result->hasPartialSuccess(); // bool
$result->getFailedLanguages(); // array
$result->getSummaryMessage(); // string
```

## Error Handling and Reporting

All sync operations return detailed `SyncResult` objects with:

- **Per-language results**: Success/failure status for each language
- **Partial success detection**: Identify which languages succeeded or failed
- **Detailed error messages**: Specific failure reasons per language
- **Key counts**: Total processed and failed key counts

Example output:

```
Sync Results:
  Partial sync completed. 2 languages successful, 1 languages failed. (150 keys processed, 3 failed)

Per-language results:
  ✓ EN
  ✓ DE
  ✗ FR: Failed to download translations
```

## Testing

```bash
composer test
```

The test suite includes:

- Unit tests for actions and core orchestration logic
- DTO validation tests
- Legacy integration tests
- HTTP mocking for API interactions

## API Endpoints

The package communicates with Linguist's REST API (`/v2`):

- `GET /v2/projects` - List available projects
- `POST /v2/projects` - Create new project
- `PATCH /v2/projects/{project}` - Update project
- `GET /v2/projects/{project}/languages` - List project languages
- `GET /v2/projects/{project}/export/json/{language}` - Get export URL
- `GET /v2/projects/{project}/translation-keys` - List translation keys
- `POST /v2/projects/{project}/translation-keys` - Create/update key
- `PATCH /v2/projects/{project}/translation-keys/{key}` - Update key
- `DELETE /v2/projects/{project}/translation-keys/{key}` - Delete key
- `POST /v2/projects/{project}/translate` - Trigger auto-translation

All endpoints require Bearer token authentication.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

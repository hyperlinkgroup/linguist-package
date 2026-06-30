<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Events;

use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Illuminate\Foundation\Events\Dispatchable;

final class PushCompleted
{
	use Dispatchable;

	public function __construct(
		public readonly string $projectSlug,
		public readonly SyncResult $result,
	) {}
}

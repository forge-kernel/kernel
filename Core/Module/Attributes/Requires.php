<?php
declare(strict_types=1);

namespace Forge\Core\Module\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Requires
{
	public function __construct(
		public ?string $interface = null,
		public ?string $module = null,
		public string $version = '>=0.1.0'
	)
	{
		if ($interface === null && $module === null) {
			throw new InvalidArgumentException(
				'Requires attribute must specify at least one of "interface" or "module".'
			);
		}
	}
}
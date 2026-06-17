<?php
declare(strict_types=1);

namespace Forge\tests\Engine\Fixtures;

use Forge\Core\Dto\Attributes\Sanitize;
use Forge\Core\Dto\BaseDto;

#[Sanitize(properties: ["secret", "password"])]
class DummyDto extends BaseDto
{
    public string $username;
    public ?string $secret;
    public ?string $password;

    public function __construct(
        string  $username = '',
        ?string $secret = null,
        ?string $password = null
    )
    {
        $this->username = $username;
        $this->secret = $secret;
        $this->password = $password;
    }
}
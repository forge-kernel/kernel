<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use DateTimeImmutable;
use Forge\Traits\DTOHelper;

class SampleDto
{
    use DTOHelper;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $email = null,
        public readonly ?DateTimeImmutable $created_at = null,
        public readonly ?DateTimeImmutable $updated_at = null,
    ) {
    }
}

#[Group('helpers')]
final class DTOHelperTest extends TestCase
{
    #[Test('toArray returns all public properties')]
    public function to_array_returns_properties(): void
    {
        $dto = new SampleDto(1, 'Alice', 'alice@example.com');
        $arr = $dto->toArray();
        $this->assertEquals(1, $arr['id']);
        $this->assertEquals('Alice', $arr['name']);
        $this->assertEquals('alice@example.com', $arr['email']);
    }

    #[Test('toJson returns valid JSON string')]
    public function to_json_is_valid(): void
    {
        $dto = new SampleDto(2, 'Bob');
        $json = $dto->toJson();
        $decoded = json_decode($json, true);
        $this->assertEquals(2, $decoded['id']);
        $this->assertEquals('Bob', $decoded['name']);
    }

    #[Test('toCreate strips the id field')]
    public function to_create_strips_id(): void
    {
        $dto = new SampleDto(5, 'Carol');
        $data = $dto->toCreate();
        $this->assertFalse(array_key_exists('id', $data));
        $this->assertEquals('Carol', $data['name']);
    }

    #[Test('toCreate strips created_at and updated_at')]
    public function to_create_strips_timestamps(): void
    {
        $dto = new SampleDto(1, 'Dave', null, new DateTimeImmutable(), new DateTimeImmutable());
        $data = $dto->toCreate();
        $this->assertFalse(array_key_exists('created_at', $data));
        $this->assertFalse(array_key_exists('updated_at', $data));
    }

    #[Test('toUpdate strips id and null values')]
    public function to_update_strips_id_and_nulls(): void
    {
        $dto = new SampleDto(3, 'Eve', null);
        $data = $dto->toUpdate();
        $this->assertFalse(array_key_exists('id', $data));
        $this->assertFalse(array_key_exists('email', $data));
        $this->assertEquals('Eve', $data['name']);
    }

    #[Test('toUpdate formats DateTimeImmutable to Y-m-d H:i:s string')]
    public function to_update_formats_datetime(): void
    {
        $dt = new DateTimeImmutable('2025-01-15 10:30:00');
        $dto = new SampleDto(1, 'Frank', null, $dt, $dt);
        $data = $dto->toUpdate();
        $this->assertEquals('2025-01-15 10:30:00', $data['created_at']);
        $this->assertEquals('2025-01-15 10:30:00', $data['updated_at']);
    }

    #[Test('jsonSerialize returns same as toArray')]
    public function json_serialize_matches_to_array(): void
    {
        $dto = new SampleDto(7, 'Grace');
        $this->assertEquals($dto->toArray(), $dto->jsonSerialize());
    }
}

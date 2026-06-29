<?php

declare(strict_types=1);

namespace Forge\tests;

use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Core\Validation\ValidationDefinition;
use Forge\Core\Validation\Validator;
use Forge\Exceptions\ValidationException;

#[Group('validator')]
final class ValidatorTest extends TestCase
{
    #[Test('Validate required rule')]
    public function validate_required_rule(): void
    {
        $isValid = false;
        try {
            $data = [
                "identifier" => "juan"
            ];
            $rules = [
                'identifier' => ["required"],
            ];
            $this->validate($data, $rules);
            $isValid = true;
        } catch (ValidationException) {
            $isValid = false;
        }
        $this->assertTrue($isValid);
    }

    protected function validate(array $data, array $rules, array $customMessages = []): void
    {
        $validator = new Validator(new ValidationDefinition($data, $rules, $customMessages));
        $validator->validate();
    }

    #[Test('Validate validation failing')]
    public function validate_should_fail(): void
    {
        $this->shouldFail(function () {
            $data = [
                "identifier" => ""
            ];
            $rules = [
                'identifier' => ["required"],
            ];
            $this->validate($data, $rules);
        });
    }

    #[Test('Validate validation failing')]
    public function validate_min_rule(): void
    {
        $isValid = false;
        try {
            $data = [
                "identifier" => "abca"
            ];
            $rules = [
                'identifier' => ["min:4"],
            ];
            $this->validate($data, $rules);
            $isValid = true;
        } catch (ValidationException) {
            $isValid = false;
        }
        $this->assertTrue($isValid);
    }

    #[Test('Validate email rule')]
    public function validate_email_rule(): void
    {
        $isValid = false;
        try {
            $data = [
                "identifier" => "jhon@domain.com"
            ];
            $rules = [
                'identifier' => ["required", "email"],
            ];

            $this->validate($data, $rules);
            $isValid = true;
        } catch (ValidationException) {
            $isValid = false;
        }
        $this->assertTrue($isValid);
    }

    #[Test('Not valid email should fail')]
    public function validate_not_valid_email_should_fail(): void
    {
        $this->shouldFail(function () {
            $data = [
                "identifier" => "jhon"
            ];
            $rules = [
                'identifier' => ["required", "email"],
            ];

            $this->validate($data, $rules);
        });
    }

    #[Test('Validate match rule')]
    public function validate_match_rule(): void
    {
        $isValid = false;
        try {
            $data = [
                "password" => "example",
                "confirm_password" => "example"
            ];
            $rules = [
                'password' => ["match:confirm_password"],
            ];

            $this->validate($data, $rules);
            $isValid = true;
        } catch (ValidationException) {
            $isValid = false;
        }
        $this->assertTrue($isValid);
    }

    #[Test('Match should fail')]
    public function validate_match_should_fail(): void
    {
        $this->shouldFail(function () {
            $data = [
                "password" => "example",
                "confirm_password" => "145"
            ];
            $rules = [
                'password' => ["match:confirm_password"],
            ];

            $this->validate($data, $rules);
        });
    }

    #[Test('Validate unique rule')]
    #[Group("Database")]
    public function validate_unique_rule(): void
    {
        $isValid = true;
        try {
            $data = [
                "identifier" => "admin"
            ];
            $rules = [
                'identifier' => ["required", "min:3", "unique:users,identifier"],
            ];

            $this->validate($data, $rules);
            $isValid = true;
        } catch (ValidationException) {
            $isValid = false;
        }
        $this->assertFalse($isValid);
    }
}

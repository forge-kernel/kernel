<?php

declare(strict_types=1);

namespace Forge\Core\Validation;

use Forge\Core\Contracts\Database\QueryBuilderInterface;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use Forge\Exceptions\ValidationException;
use Forge\Core\DI\Container;
use ReflectionException;

final class Validator
{
    public function __construct(
        private ValidationDefinition $definition,
        private bool $onlyPresent = false,
    ) {
    }

    /**
     * @throws ValidationException
     * @throws ReflectionException
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    public function validate(): void
    {
        $errors = [];

        foreach ($this->definition->rules as $field => $ruleset) {
            if ($this->onlyPresent && !array_key_exists($field, $this->definition->data)) {
                continue;
            }

            $value = $this->definition->data[$field] ?? "";
            $value = is_string($value) ? $value : (string) $value;

            foreach ($ruleset as $rule) {
                [$ruleName, $param] = explode(":", $rule . ":");

                if ($ruleName === "required" && empty($value)) {
                    $errors[$field][] = $this->format("required", $field);
                    break;
                }

                if ($ruleName === "min" && strlen($value) < (int) $param) {
                    $errors[$field][] = $this->format("min", $field, $param);
                }

                if ($ruleName === "max" && strlen($value) > (int) $param) {
                    $errors[$field][] = $this->format("max", $field, $param);
                }

                if (
                    $ruleName === "email" &&
                    !filter_var($value, FILTER_VALIDATE_EMAIL)
                ) {
                    $errors[$field][] = $this->format("email", $field);
                }

                if (
                    $ruleName === "match" &&
                    $value !== ($this->definition->data[$param] ?? "")
                ) {
                    $errors[$field][] = $this->format("match", $field);
                }

                if ($ruleName === "unique") {
                    if (!Container::getInstance()->has(QueryBuilderInterface::class)) {
                        throw new ValidationException(["$field" => "Database not available for unique validation"]);
                    }

                    [$table, $column] = explode(",", $param);
                    /** @var QueryBuilderInterface $query */
                    $query = Container::getInstance()->get(QueryBuilderInterface::class);

                    if (!$table || !$column) {
                        throw new ValidationException(["$field" => "Invalid unique rule syntax: $param"]);
                    }

                    $exists = $query
                        ->setTable($table)
                        ->where($column, "=", $value)
                        ->first();

                    if ($exists) {
                        $errors[$field][] = $this->format("unique", $field);
                    }
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    private function format(
        string $rule,
        string $field,
        string|int $value = '',
    ): string {
        $keySpecific = "{$field}.{$rule}";
        $keyGeneric = $rule;

        $defaultMessages = [
            'required' => 'The :field field is required.',
            'min' => 'The :field field must be at least :value characters.',
            'max' => 'The :field field must not exceed :value characters.',
            'email' => 'The :field field must be a valid email address.',
            'match' => 'The :field field does not match.',
            'unique' => 'The :field field has already been taken.',
        ];

        $message =
            $this->messages[$keySpecific]
            ?? $this->messages[$keyGeneric]
            ?? ($defaultMessages[$rule] ?? 'The :field field is invalid.');

        return str_replace(
            [':field', ':value'],
            [$field, (string) $value],
            $message
        );
    }
}

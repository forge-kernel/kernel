<?php
declare(strict_types=1);

namespace Forge\Traits;
use Forge\Core\Validation\Validator;
use Forge\Core\Validation\ValidationDefinition;

trait ValidatorHelper
{
    /**
     * Validate data against definition rules
     *
     * @param ValidationDefinition $definition raw data to be validated
     * @return void
     */
    private static function validateData(ValidationDefinition $definition): void
    {
        $validator = new Validator($definition);
        $validator->validate();
    }

}

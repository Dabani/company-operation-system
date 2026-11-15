<?php

namespace App\Rules;

use App\Support\Html;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Minimum length of the visible text of a rich-text (HTML) value: tags such as <p> do not count. */
class RichTextMin implements ValidationRule
{
    public function __construct(private int $min = 1) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && mb_strlen(Html::plain($value)) < $this->min) {
            $fail(__('validation.min.string', ['attribute' => str_replace('_', ' ', $attribute), 'min' => $this->min]));
        }
    }
}

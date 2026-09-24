<?php

namespace App\Domain\Cms\Support;

use App\Support\Ids;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

final class MediaExists implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Ids::isUuid($value) || ! DB::table('cms_media')->where('id', Ids::toBinary($value))->exists()) {
            $fail('The :attribute must be the id of an uploaded media item.');
        }
    }
}

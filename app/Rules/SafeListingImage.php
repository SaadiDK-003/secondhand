<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SafeListingImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Choose a valid photo.');

            return;
        }
        $size = @getimagesize($value->getPathname());
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        $mime = $value->getMimeType();
        if (! $size || ! isset($extensions[$mime]) || $size['mime'] !== $mime
            || ! in_array(strtolower($value->getClientOriginalExtension()), $extensions[$mime], true)
            || $size[0] < 1 || $size[1] < 1 || $size[0] > 8000 || $size[1] > 8000 || $size[0] * $size[1] > 20000000) {
            $fail('Use a JPG, PNG or WebP photo, at most 8000 pixels per side and 20 megapixels.');
        }
    }
}

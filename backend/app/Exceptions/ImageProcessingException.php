<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an uploaded admin image can't be processed (too large / unsupported
 * format / Imagick failure). The exception handler converts it into a graceful
 * back()->withInput() redirect with a friendly validation error, so the data
 * entry team never sees a raw 500 and never loses their form input.
 *
 * The message intentionally matches the one used by the product form so the whole
 * admin gives a consistent experience.
 */
class ImageProcessingException extends Exception
{
    public function __construct(public string $field = 'image')
    {
        parent::__construct(
            'We could not process this image (it may be too large or an unsupported format). '
            . 'Please upload a JPG or PNG under ~4 MB and try again — the rest of your entries are kept.'
        );
    }
}

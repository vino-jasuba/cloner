<?php

namespace Bkwld\Cloner;

use Illuminate\Database\Eloquent\Model;

interface AttachmentAdapter
{
    /**
     * Duplicate a file, identified by the reference string, which was pulled from
     * a model attribute.
     *
     * @param string $reference
     * @param Model $clone
     *
     * @return string New reference to duplicated file
     */
    public function duplicate(string $reference, Model $clone): string;
}

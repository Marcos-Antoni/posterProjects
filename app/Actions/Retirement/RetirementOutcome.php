<?php

namespace App\Actions\Retirement;

use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;

/**
 * What a handler did: the root history row, the elements a split created,
 * how many children were moved and how many were retired along.
 */
final readonly class RetirementOutcome
{
    /**
     * @param  list<Model>  $created
     */
    public function __construct(
        public Retirement $retirement,
        public array $created = [],
        public int $moved = 0,
        public int $cascaded = 0,
    ) {}
}

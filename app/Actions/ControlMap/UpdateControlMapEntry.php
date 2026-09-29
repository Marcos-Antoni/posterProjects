<?php

namespace App\Actions\ControlMap;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\ControlZone;
use App\Models\ControlMapEntry;

/**
 * Edits an entry's text and/or moves it to another zone.
 */
class UpdateControlMapEntry
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    /**
     * @param  array{text?: string, zone?: string}  $data
     */
    public function __invoke(Actor $actor, ControlMapEntry $entry, array $data): ControlMapEntry
    {
        $objective = $entry->owningObjective();

        $this->ensureOwned($actor, $objective);
        $this->ensureWritable($objective);

        return $this->transaction->run($actor, Operation::EditControlMap, $entry, function () use ($entry, $data): ControlMapEntry {
            if (isset($data['text'])) {
                $entry->text = trim($data['text']);
            }

            if (isset($data['zone'])) {
                $entry->zone = ControlZone::from($data['zone']);
            }

            $entry->save();

            return $entry;
        });
    }
}

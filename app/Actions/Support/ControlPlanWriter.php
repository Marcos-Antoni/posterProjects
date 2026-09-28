<?php

namespace App\Actions\Support;

use App\Enums\ControlZone;
use App\Models\ControlMapEntry;
use App\Models\ControlPlan;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Writes the Control 5-point plan and the initial control map of an
 * objective or a plan from validated input, and enforces completeness when
 * the owner asks for it to be active (control-plan spec).
 *
 * Input keys: `outcome`, `deadline` (Y-m-d), `metric` ({name, target,
 * current}), `risks` (list of strings), `contingency`, `control_map`
 * (list of {zone, text}). Absent keys are left untouched.
 */
class ControlPlanWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function write(Objective|Plan $plannable, array $data): ControlPlan
    {
        /** @var ControlPlan $controlPlan */
        $controlPlan = $plannable->controlPlan()->firstOrNew();

        if (array_key_exists('outcome', $data)) {
            $controlPlan->outcome = $this->clean($data['outcome']);
        }

        if (array_key_exists('deadline', $data)) {
            $controlPlan->deadline = $data['deadline'] ?: null;
        }

        if (array_key_exists('metric', $data)) {
            $metric = is_array($data['metric']) ? $data['metric'] : [];
            $controlPlan->metric_name = $this->clean($metric['name'] ?? null);
            $controlPlan->metric_target = $this->number($metric['target'] ?? null);
            $controlPlan->metric_current = $this->number($metric['current'] ?? null);
        }

        if (array_key_exists('metric_current', $data)) {
            $controlPlan->metric_current = $this->number($data['metric_current']);
        }

        if (array_key_exists('risks', $data)) {
            $controlPlan->risks = array_values(array_filter(
                array_map(fn ($risk): string => trim(is_scalar($risk) ? (string) $risk : ''), Arr::wrap($data['risks'])),
                fn (string $risk): bool => $risk !== '',
            ));
        }

        if (array_key_exists('contingency', $data)) {
            $controlPlan->contingency = $this->clean($data['contingency']);
        }

        $plannable->controlPlan()->save($controlPlan);

        foreach ($data['control_map'] ?? [] as $entry) {
            $this->appendEntry($plannable, ControlZone::from($entry['zone']), (string) $entry['text']);
        }

        $plannable->unsetRelation('controlPlan');

        return $controlPlan;
    }

    /**
     * @throws ValidationException naming every missing point in Spanish.
     */
    public function ensureComplete(Objective|Plan $plannable): void
    {
        $controlPlan = $plannable->controlPlan()->first() ?? new ControlPlan;

        $missing = $controlPlan->missingPoints();

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    public function appendEntry(Objective|Plan $plannable, ControlZone $zone, string $text): ControlMapEntry
    {
        $max = $plannable->controlMapEntries()->reorder()->max('position');

        /** @var ControlMapEntry $entry */
        $entry = $plannable->controlMapEntries()->create([
            'zone' => $zone,
            'text' => trim($text),
            'position' => $max === null ? 0 : ((int) $max) + 1,
        ]);

        return $entry;
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function number(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
    }
}

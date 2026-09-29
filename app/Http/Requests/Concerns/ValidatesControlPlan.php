<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ControlZone;
use Illuminate\Validation\Rule;

/**
 * Shape rules for the Control 5-point plan and control map shared by the
 * objective and plan requests (web, and MCP through `ReplaysFormRequest`).
 * Completeness is NOT checked here: the domain actions refuse activation
 * naming each missing point, identically on every surface.
 */
trait ValidatesControlPlan
{
    /**
     * @return array<string, mixed>
     */
    protected function controlPlanRules(): array
    {
        return [
            'outcome' => ['nullable', 'string', 'max:2000'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            // `array:name,target,current` refuses a LIST of metrics (keys 0, 1…):
            // exactly one metric per objective or plan.
            'metric' => ['nullable', 'array:name,target,current'],
            'metric.name' => ['nullable', 'string', 'max:255'],
            'metric.target' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'metric.current' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'metrics' => ['prohibited'],
            'risks' => ['nullable', 'array', 'max:10'],
            'risks.*' => ['nullable', 'string', 'max:500'],
            'contingency' => ['nullable', 'string', 'max:2000'],
            'control_map' => ['nullable', 'array', 'max:30'],
            'control_map.*.zone' => ['required', Rule::enum(ControlZone::class)],
            'control_map.*.text' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function controlPlanMessages(string $noun): array
    {
        $oneMetric = "Solo una métrica por {$noun}. Elegí la que mejor te diga si vas bien.";

        return [
            'outcome.max' => 'El resultado no puede tener más de :max caracteres.',
            'deadline.date_format' => 'La fecha límite no es válida.',
            'metric.array' => $oneMetric,
            'metrics.prohibited' => $oneMetric,
            'metric.name.max' => 'El nombre de la métrica no puede tener más de :max caracteres.',
            'metric.target.numeric' => 'La meta de la métrica tiene que ser un número.',
            'metric.target.min' => 'La meta de la métrica no puede ser negativa.',
            'metric.target.max' => 'La meta de la métrica es demasiado grande.',
            'metric.current.numeric' => 'El valor actual tiene que ser un número.',
            'metric.current.min' => 'El valor actual no puede ser negativo.',
            'metric.current.max' => 'El valor actual es demasiado grande.',
            'risks.array' => 'Los riesgos tienen que ser una lista.',
            'risks.max' => 'Anotá como mucho :max riesgos.',
            'risks.*.max' => 'Cada riesgo puede tener hasta :max caracteres.',
            'contingency.max' => 'La contingencia no puede tener más de :max caracteres.',
            'control_map.*.zone.required' => 'Elegí la zona del mapa de control.',
            'control_map.*.zone.enum' => 'La zona del mapa de control no es válida.',
            'control_map.*.text.required' => 'Escribí la entrada del mapa de control.',
            'control_map.*.text.max' => 'Cada entrada del mapa puede tener hasta :max caracteres.',
        ];
    }
}

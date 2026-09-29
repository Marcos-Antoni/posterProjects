<?php

namespace App\Actions\Retirement;

use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The registry of retirement handlers, keyed by model class. Bound as a
 * singleton; Phase 7 registers captures with
 * `app(RetirementHandlers::class)->register(Capture::class, CaptureRetirementHandler::class)`.
 */
class RetirementHandlers
{
    /**
     * @var array<class-string<Model>, class-string>
     */
    private array $handlers = [
        Item::class => ItemRetirementHandler::class,
        Plan::class => PlanRetirementHandler::class,
        Objective::class => ObjectiveRetirementHandler::class,
        Habit::class => HabitRetirementHandler::class,
    ];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<Model>  $model
     * @param  class-string  $handler
     */
    public function register(string $model, string $handler): void
    {
        $this->handlers[$model] = $handler;
    }

    /**
     * @return RetirementHandler<Model>
     */
    public function for(Model $element): RetirementHandler
    {
        $handler = $this->handlers[$element::class] ?? null;

        if ($handler === null) {
            throw new InvalidArgumentException('No retirement handler for '.$element::class.'.');
        }

        $instance = $this->container->make($handler);

        if (! $instance instanceof RetirementHandler) {
            throw new InvalidArgumentException("{$handler} is not a retirement handler.");
        }

        return $instance;
    }

    /**
     * Whether the model class can be retired.
     *
     * @param  class-string<Model>  $model
     */
    public function supports(string $model): bool
    {
        return isset($this->handlers[$model]);
    }
}

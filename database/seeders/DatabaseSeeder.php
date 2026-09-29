<?php

namespace Database\Seeders;

use App\Actions\Items\AddDependency;
use App\Actions\Items\AddItem;
use App\Actions\Items\CheckItem;
use App\Actions\Objectives\CreateObjective;
use App\Actions\Plans\CreatePlan;
use App\Actions\Support\Actor;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->seedDailyObjective(Actor::ownerWeb($owner));
        $this->seedExamsObjective(Actor::ownerWeb($owner));
    }

    /**
     * "Marcos OS en uso diario" (the realistic data of the screens brief):
     * a complete 5-point plan and control map, an active level-1 plan whose
     * items unlock each other (with a parallel branch and a milestone), a
     * draft level-2 plan and a closing plan. Everything goes through the
     * domain actions, so the seed obeys the same rules as the app.
     */
    private function seedDailyObjective(Actor $actor): void
    {
        $objective = app(CreateObjective::class)($actor, [
            'key' => 'DIARIO',
            'title' => 'Marcos OS en uso diario',
            'identity_statement' => 'Soy alguien que construye cada día.',
            'outcome' => 'Uso Marcos OS a mano cada día y sé qué necesita la pantalla Ahora.',
            'deadline' => '2026-10-11',
            'metric' => ['name' => 'Días con el ciclo diario completo', 'target' => 14, 'current' => 1],
            'risks' => ['Me engancho con YouTube después de cenar.', 'Pierdo un día y quiero rehacer todo el sistema.'],
            'contingency' => 'Cuando pierda un día, entonces al siguiente retomo con 2 minutos y no rehago el plan.',
            'control_map' => [
                ['zone' => 'mine', 'text' => 'Abrir la libreta a las 06:45'],
                ['zone' => 'mine', 'text' => 'Capturar 10 min antes de desayunar'],
                ['zone' => 'influence', 'text' => 'Horario del gimnasio con mi amigo'],
                ['zone' => 'outside', 'text' => 'Cortes de luz'],
                ['zone' => 'outside', 'text' => 'Carga de la facultad en octubre'],
            ],
        ]);

        $week1 = app(CreatePlan::class)($actor, $objective, [
            'title' => 'Semana 1: primer ciclo',
            'level' => 1,
            'activate' => true,
            'outcome' => 'Cumplí el ciclo diario (despertar, captura, clasificar) 5 de 7 días.',
            'deadline' => '2026-10-03',
            'metric' => ['name' => 'Días con el ciclo completo', 'target' => 5, 'current' => 1],
            'risks' => ['El domingo no hay rutina y lo salteo.'],
            'contingency' => 'Cuando sea domingo, entonces hago solo la versión de 2 minutos.',
        ]);

        $items = $this->addItems($actor, $week1, [
            ['Mesa lista', 'despejar la mesa y dejar solo la libreta'],
            ['Captura 10 min', 'abrir el inbox y escribir una línea'],
            ['Clasificar y elegir prioridad', 'abrir el inbox y leer la primera línea'],
            ['Revisar video Física', 'abrir el video en 1.5x y anotar el minuto'],
            ['Revisar fórmula cuadrática', 'escribir la fórmula de memoria en una hoja'],
            ['Poster desde el teléfono', 'abrir Poster en el teléfono y marcar un hábito'],
            ['Semana 1: boceto de Ahora', 'abrir una hoja y dibujar un rectángulo', 'milestone'],
        ]);

        $link = app(AddDependency::class);
        $link($actor, $items[0], $items[1]);
        $link($actor, $items[1], $items[2]);

        foreach ([3, 4, 5] as $parallel) {
            $link($actor, $items[2], $items[$parallel]);
            $link($actor, $items[$parallel], $items[6]);
        }

        app(CheckItem::class)($actor, $items[0]);

        app(CreatePlan::class)($actor, $objective, [
            'title' => 'Semana 2: afinar Ahora',
            'level' => 2,
            'outcome' => 'Sé qué pide la pantalla Ahora para escribir su spec.',
        ]);

        app(CreatePlan::class)($actor, $objective, ['title' => 'Cierre']);
    }

    /**
     * "Aprobar finales": a second active objective with one plan.
     */
    private function seedExamsObjective(Actor $actor): void
    {
        $objective = app(CreateObjective::class)($actor, [
            'key' => 'FINALES',
            'title' => 'Aprobar finales',
            'identity_statement' => 'Física II y Microeconomía.',
            'outcome' => 'Apruebo Física II y Microeconomía en diciembre.',
            'deadline' => '2026-12-11',
            'metric' => ['name' => 'Materias aprobadas', 'target' => 2, 'current' => 0],
            'risks' => ['Estudio todo la última semana.'],
            'contingency' => 'Cuando falten dos semanas, entonces hago un examen de práctica por día.',
        ]);

        $plan = app(CreatePlan::class)($actor, $objective, [
            'title' => 'Física II',
            'activate' => true,
            'outcome' => 'Resuelvo la guía y el examen de práctica.',
            'deadline' => '2026-11-20',
            'metric' => ['name' => 'Guías resueltas', 'target' => 1, 'current' => 0],
            'risks' => ['Me trabo en electromagnetismo.'],
            'contingency' => 'Cuando me trabe, entonces pido la clase de consulta.',
        ]);

        $items = $this->addItems($actor, $plan, [
            ['Física II: guía resuelta', 'abrir la guía en el primer ejercicio'],
            ['Física II: examen', 'abrir el PDF del examen de práctica', 'milestone'],
        ]);

        app(AddDependency::class)($actor, $items[0], $items[1]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $rows  [title, 2-minute version, kind]
     * @return list<Item>
     */
    private function addItems(Actor $actor, Plan $plan, array $rows): array
    {
        return array_map(fn (array $row): Item => app(AddItem::class)($actor, $plan, [
            'title' => $row[0],
            'two_minute_version' => $row[1],
            'kind' => $row[2] ?? 'task',
        ]), $rows);
    }
}

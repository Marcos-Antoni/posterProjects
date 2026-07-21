<?php

namespace App\Http\Requests;

use App\Models\BoardColumn;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DestroyBoardColumnRequest extends FormRequest
{
    /**
     * Only the owner of the column's project may delete it. The route
     * scopes `{boardColumn}` to the project via `scopeBindings()`.
     */
    public function authorize(): bool
    {
        /** @var BoardColumn $boardColumn */
        $boardColumn = $this->route('boardColumn');

        return $this->user()?->can('delete', $boardColumn) ?? false;
    }

    /**
     * A destination column is only required when the column being
     * deleted still has issues in it — an empty column deletes outright.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'destination_board_column_id' => [
                $this->columnHasIssues() ? 'required' : 'nullable',
                'integer',
                Rule::exists('board_columns', 'id')->where('project_id', $project->id),
            ],
        ];
    }

    /**
     * The destination column must be a different column than the one
     * being deleted.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var BoardColumn $boardColumn */
                $boardColumn = $this->route('boardColumn');
                $destinationId = $this->input('destination_board_column_id');

                if ($destinationId !== null && (int) $destinationId === $boardColumn->id) {
                    $validator->errors()->add(
                        'destination_board_column_id',
                        'La columna destino debe ser distinta de la columna que estás borrando.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination_board_column_id.required' => 'Elegí una columna destino para mover las tareas antes de borrar esta columna.',
            'destination_board_column_id.exists' => 'La columna destino no pertenece a este proyecto.',
        ];
    }

    private function columnHasIssues(): bool
    {
        /** @var BoardColumn $boardColumn */
        $boardColumn = $this->route('boardColumn');

        return $boardColumn->issues()->exists();
    }
}

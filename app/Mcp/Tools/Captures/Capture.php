<?php

namespace App\Mcp\Tools\Captures;

use App\Actions\Captures\CreateCapture;
use App\Actions\Support\Actor;
use App\Enums\CaptureSource;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: minor (applied directly and audited). Note an idea down for Marco to triage later, exactly like the web quick-capture (one field, max 500 characters): it never carries priority, objective, plan or date, and it never appears on Now. Use this while working on something else, to park an idea without derailing the current task — never to record structured work (use propose-change or the relevant minor tool for that).')]
class Capture extends Tool
{
    use ResolvesAuthenticatedUser;

    public function __construct(
        private CreateCapture $createCapture,
        private ResourceLinker $links,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        try {
            $capture = ($this->createCapture)(Actor::aiMcp($user), (string) $request->get('text'), CaptureSource::Ai);
        } catch (ValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'capture' => ['id' => $capture->id, 'text' => $capture->text],
            'url' => $this->links->inbox(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()
                ->description('The idea to note down, up to 500 characters.')
                ->required(),
        ];
    }
}

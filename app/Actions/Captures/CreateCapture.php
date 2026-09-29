<?php

namespace App\Actions\Captures;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\Operation;
use App\Enums\CaptureSource;
use App\Models\Capture;
use Illuminate\Validation\ValidationException;

/**
 * Captures one idea (capture-inbox spec "Capture Takes One Field And
 * Assigns Nothing"): one field, its source and a UTC timestamp; never a
 * priority, objective, plan or date, and never the Now task. Minor for AI
 * (the "ai" source): noting an idea down while it works is not a structural
 * change and needs no proposal.
 */
class CreateCapture
{
    public const EMPTY = 'Escribí algo para guardar la captura.';

    public const MAX_LENGTH = 500;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, string $text, CaptureSource $source): Capture
    {
        $text = trim($text);

        if ($text === '') {
            throw ValidationException::withMessages(['text' => self::EMPTY]);
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['text' => 'La captura tiene como máximo '.self::MAX_LENGTH.' caracteres.']);
        }

        $capture = new Capture([
            'user_id' => $actor->user->id,
            'text' => $text,
            'source' => $source,
        ]);

        return $this->transaction->run($actor, Operation::CreateCapture, $capture, function () use ($capture): Capture {
            $capture->save();

            return $capture;
        });
    }
}

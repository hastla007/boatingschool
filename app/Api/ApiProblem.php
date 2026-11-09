<?php

namespace App\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fachlicher Fehler der Mobile-API mit stabilem, maschinenlesbarem Code
 * (Antwort als application/problem+json, siehe ProblemResponse).
 */
class ApiProblem extends HttpException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $extra  zusätzliche Felder (z. B. challenge_token)
     */
    public function __construct(
        int $status,
        public readonly string $problemCode,
        public readonly string $title,
        ?string $detail = null,
        public readonly array $errors = [],
        array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($status, $detail ?? $title, null, $headers);
    }

    public static function notFound(string $what = 'Ressource'): self
    {
        return new self(404, 'RESOURCE_NOT_FOUND', "{$what} nicht gefunden");
    }
}

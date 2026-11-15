<?php

namespace Tests;

use App\Models\User;
use App\Support\AnswerToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Kennung je Person einer Antwort, wie sie Web und App ausgeben (SEC-A1). */
    protected function answerToken(User|string $user, ?string $answerId): ?string
    {
        return $answerId === null ? null : AnswerToken::for($user instanceof User ? $user->id : $user, $answerId);
    }

    /**
     * @param  iterable<string>  $answerIds
     * @return list<string>
     */
    protected function answerTokens(User|string $user, iterable $answerIds): array
    {
        return AnswerToken::mapMany($user instanceof User ? $user->id : $user, $answerIds);
    }
}

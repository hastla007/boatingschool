<?php

namespace Tests\Unit;

use App\Support\TextDiff;
use PHPUnit\Framework\TestCase;

class TextDiffTest extends TestCase
{
    public function test_changed_words_are_marked(): void
    {
        $this->assertSame(
            'Was zeigt ein <del>rotes</del><ins>grünes</ins> Licht?',
            TextDiff::html('Was zeigt ein rotes Licht?', 'Was zeigt ein grünes Licht?')
        );
    }

    public function test_added_and_removed_text_and_escaping(): void
    {
        $this->assertSame('A <ins>&lt;b&gt; </ins>B', TextDiff::html('A B', 'A <b> B'));
        $this->assertSame('Ende<del>.</del>', TextDiff::html('Ende.', 'Ende'));
        $this->assertSame('<ins>Neu</ins>', TextDiff::html(null, 'Neu'));
        $this->assertSame('gleich &amp; gut', TextDiff::html('gleich & gut', 'gleich & gut'));
    }
}

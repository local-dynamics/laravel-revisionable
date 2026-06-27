<?php

namespace LocalDynamics\Revisionable\Tests;

use LocalDynamics\Revisionable\FieldModifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

class FieldModifierTest extends BaseTestCase
{
    #[Test]
    public function sort_json_keys_sorts_every_level_including_the_top()
    {
        $sorted = FieldModifier::sortJsonKeys([
            'z' => 1,
            'a' => 2,
            'm' => ['y' => 1, 'b' => 2],
        ]);

        $this->assertSame(['a', 'm', 'z'], array_keys($sorted));
        $this->assertSame(['b', 'y'], array_keys($sorted['m']));
    }

    #[Test]
    public function sort_json_keys_preserves_list_order()
    {
        $this->assertSame(['c', 'a', 'b'], FieldModifier::sortJsonKeys(['c', 'a', 'b']));
    }

    #[Test]
    public function canonical_json_is_independent_of_key_order()
    {
        $a = FieldModifier::canonicalJson('{"alpha":1,"b":2,"gamma":3}');
        $b = FieldModifier::canonicalJson('{"b":2,"gamma":3,"alpha":1}');

        $this->assertSame($a, $b);
    }

    #[Test]
    public function canonical_json_still_differs_for_real_changes()
    {
        $a = FieldModifier::canonicalJson('{"a":1}');
        $b = FieldModifier::canonicalJson('{"a":2}');

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function canonical_json_passes_null_through()
    {
        $this->assertNull(FieldModifier::canonicalJson(null));
    }
}

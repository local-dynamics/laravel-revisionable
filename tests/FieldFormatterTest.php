<?php

namespace LocalDynamics\Revisionable\Tests;

use LocalDynamics\Revisionable\FieldFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

class FieldFormatterTest extends BaseTestCase
{
    #[Test]
    public function it_formats_strings()
    {
        $formats = ['title' => 'string:<strong>%s</strong>'];

        $this->assertSame('<strong>Hi</strong>', FieldFormatter::format('title', 'Hi', $formats));
    }

    #[Test]
    public function it_formats_booleans()
    {
        $formats = ['public' => 'boolean:No|Yes'];

        $this->assertSame('Yes', FieldFormatter::format('public', 1, $formats));
        $this->assertSame('No', FieldFormatter::format('public', 0, $formats));
    }

    #[Test]
    public function it_formats_is_empty()
    {
        $formats = ['deleted_at' => 'isEmpty:Active|Deleted'];

        $this->assertSame('Active', FieldFormatter::format('deleted_at', null, $formats));
        $this->assertSame('Deleted', FieldFormatter::format('deleted_at', '2020-01-01', $formats));
    }

    #[Test]
    public function is_empty_can_echo_the_value()
    {
        $formats = ['name' => 'isEmpty:Nothing|%s'];

        $this->assertSame('Nothing', FieldFormatter::format('name', '', $formats));
        $this->assertSame('Bob', FieldFormatter::format('name', 'Bob', $formats));
    }

    #[Test]
    public function it_formats_datetimes()
    {
        $formats = ['modified' => 'datetime:Y-m-d'];

        $this->assertSame('2020-01-02', FieldFormatter::format('modified', '2020-01-02 03:04:05', $formats));
    }

    #[Test]
    public function it_returns_the_raw_value_for_unknown_formats()
    {
        $this->assertSame('raw', FieldFormatter::format('other', 'raw', ['title' => 'string:%s']));
    }
}

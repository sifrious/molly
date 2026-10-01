<?php

namespace App\Ai\Agents;

use Sifrious\Molly\Agents\ChangeWriter;

class TeamChangeWriter extends ChangeWriter
{
    public function instructions(): string
    {
        return parent::instructions()."\n".<<<'TEXT'
        Team rules for this application:
        Validate controller input with a Form Request class.
        Return JSON with response()->json().
        TEXT;
    }
}

<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Plan;

class ShowPlan
{
    public function handle(string $id): ?Plan
    {
        return Plan::find($id);
    }
}

<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Database\Eloquent\Collection;
use Sifrious\Molly\Models\Plan;

class ListPlans
{
    /** @return Collection<int, Plan> */
    public function handle(): Collection
    {
        return Plan::latest()->orderByDesc('id')->limit(20)->get();
    }
}

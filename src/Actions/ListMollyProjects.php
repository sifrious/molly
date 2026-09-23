<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;

final class ListMollyProjects
{
    public function __construct(private ProjectRegistry $registry) {}

    /** @return list<MollyProject> */
    public function handle(): array
    {
        return $this->registry->all();
    }
}

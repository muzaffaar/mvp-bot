<?php

namespace App\Services\Staff;

use App\Models\Staff;

final readonly class StaffActivationResult
{
    public function __construct(
        public Staff $staff,
        public string $login,
        public string $password,
    ) {}
}

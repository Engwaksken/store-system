<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case OverallManager = 'overall_manager';
    case ProjectManager = 'project_manager';
    case Storekeeper = 'storekeeper';
    case Staff = 'staff';
    case Accountant = 'accountant';
    case Procurement = 'procurement';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Owner / Admin',
            self::OverallManager => 'Overall Manager',
            self::ProjectManager => 'Project Manager',
            self::Storekeeper => 'Storekeeper',
            self::Staff => 'Staff',
            self::Accountant => 'Accountant',
            self::Procurement => 'Procurement Officer',
            self::Auditor => 'Auditor (read-only)',
        };
    }
}

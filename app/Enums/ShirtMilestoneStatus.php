<?php

namespace App\Enums;

enum ShirtMilestoneStatus: string
{
    case PendingEntry = 'pending_entry';
    case Submitted = 'submitted';
    case Ordered = 'ordered';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}

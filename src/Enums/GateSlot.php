<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Enums;

enum GateSlot: string
{
    case Before = 'before';
    case After = 'after';
}

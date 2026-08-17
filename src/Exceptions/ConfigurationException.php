<?php

declare(strict_types=1);

namespace ElPandaPe\Bouncer\Exceptions;

use InvalidArgumentException;

/**
 * A developer-facing misconfiguration or misuse: plain English by design.
 */
class ConfigurationException extends InvalidArgumentException {}

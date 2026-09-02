<?php

namespace App\Exceptions;

use RuntimeException;

/** A bounded, non-secret-leaking diagnostic for a missing host execution capability. */
class AgentCapabilityException extends RuntimeException {}

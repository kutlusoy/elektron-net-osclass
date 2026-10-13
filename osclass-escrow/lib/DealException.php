<?php

namespace ElektronNet\Escrow;

use RuntimeException;

/**
 * A rejected workflow action. The message is safe to show to the acting user.
 */
final class DealException extends RuntimeException
{
}

<?php
/**
 * Import failures.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * A file, feed or page that could not be imported. The message is written
 * for the site owner and safe to show them.
 */
final class SourceException extends \RuntimeException {
}

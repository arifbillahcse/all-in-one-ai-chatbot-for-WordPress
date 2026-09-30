<?php
/**
 * CRM failures that retrying will not fix.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * A permanent CRM error (wrong key, unknown list, rejected contact). The
 * message is shown to the site owner on the lead. Temporary failures
 * (network, rate limits, 5xx) are plain RuntimeExceptions, which the queue
 * retries.
 */
final class CrmException extends \RuntimeException {
}

<?php
/**
 * A lead a CRM cannot take.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Not an error: the lead lacks what this CRM needs (e.g. Mailchimp needs an
 * email address). Recorded as "skipped" with the reason.
 */
final class CrmSkipped extends \RuntimeException {
}

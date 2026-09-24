<?php
/**
 * A tool the model may call.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Name, description and JSON Schema of a tool, in a provider-neutral form.
 */
final class ToolDefinition {

	/**
	 * Constructor.
	 *
	 * @param string               $name        Tool name (a-z, 0-9, _).
	 * @param string               $description When and how to use it — the model reads this.
	 * @param array<string, mixed> $parameters  JSON Schema of the arguments.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly array $parameters,
	) {
	}

	/**
	 * Anthropic format.
	 *
	 * @return array<string, mixed>
	 */
	public function to_claude(): array {
		return array(
			'name'         => $this->name,
			'description'  => $this->description,
			'input_schema' => $this->schema(),
		);
	}

	/**
	 * OpenAI / DeepSeek format.
	 *
	 * @return array<string, mixed>
	 */
	public function to_openai(): array {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $this->name,
				'description' => $this->description,
				'parameters'  => $this->schema(),
			),
		);
	}

	/**
	 * The schema, with an empty properties list encoded as an object, not [].
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$schema = $this->parameters + array( 'type' => 'object' );

		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		}

		return $schema;
	}
}

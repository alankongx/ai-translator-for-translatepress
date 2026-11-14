<?php
/**
 * OpenAI-compatible Chat Completions API: OpenAI, DeepSeek, Zhipu, OpenRouter, Groq,
 * relay services, local servers.
 *
 * Optional features (JSON schema, JSON mode, reasoning effort, max_completion_tokens) are
 * tried first and switched off automatically when an endpoint rejects them.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AITP_Provider_OpenAI extends AITP_Provider {

	/**
	 * Presets: label => base URL.
	 *
	 * @return array<string,array{label:string,base_url:string,model:string}>
	 */
	public static function presets() {
		return array(
			'openai'     => array( 'label' => 'OpenAI (ChatGPT)', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4.1-mini' ),
			'deepseek'   => array( 'label' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-flash' ),
			'zhipu'      => array( 'label' => 'Zhipu GLM (智谱)', 'base_url' => 'https://open.bigmodel.cn/api/paas/v4', 'model' => 'glm-4-flash' ),
			'openrouter' => array( 'label' => 'OpenRouter', 'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'google/gemini-2.5-flash' ),
			'groq'       => array( 'label' => 'Groq', 'base_url' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile' ),
			'custom'     => array( 'label' => 'Custom / relay', 'base_url' => '', 'model' => '' ),
		);
	}

	/** @return string */
	public function id() {
		return 'openai';
	}

	/** @return string */
	public function default_base_url() {
		return 'https://api.openai.com/v1';
	}

	/** @return bool */
	private function is_openai_host() {
		$host = strtolower( (string) parse_url( $this->base_url(), PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return $host === 'api.openai.com' || substr( $host, -20 ) === '.openai.azure.com';
	}

	/** @return bool */
	private function is_deepseek_host() {
		$host = strtolower( (string) parse_url( $this->base_url(), PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return $host === 'api.deepseek.com';
	}

	/**
	 * Caps remembered before 0.6.6 had thinking off for DeepSeek because the plugin did not
	 * know its switch; a new key makes them be found out again.
	 *
	 * @return string
	 */
	public function fingerprint() {
		return parent::fingerprint() . ( $this->is_deepseek_host() ? '|ds-thinking' : '' ) . ( $this->is_zhipu_thinking_model() ? '|zp-thinking' : '' );
	}

	/** @return bool */
	private function is_zhipu_host() {
		$host = strtolower( (string) parse_url( $this->base_url(), PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return $host === 'open.bigmodel.cn' || $host === 'api.z.ai';
	}

	/** @return bool Zhipu models that think by default and take thinking:{type}. */
	private function is_zhipu_thinking_model() {
		return $this->is_zhipu_host() && (bool) preg_match( '~glm-(?:4\.[5-9]|[5-9])~', strtolower( $this->model() ) );
	}

	/** @return bool */
	private function is_reasoning_model() {
		return (bool) preg_match( '~(^|/)(o\d|gpt-5|gpt-[6-9])~', strtolower( $this->model() ) );
	}

	/**
	 * Models that may spend output tokens on reasoning even when not asked to: the output
	 * budget gets a reserve so the translation is not cut off.
	 *
	 * @return bool
	 */
	private function may_think() {
		return (bool) preg_match( '~reason|r1|think|qwq|(^|[-/])t1|glm-(?:4\.[5-9]|[5-9])|qwen3|kimi-k2|minimax|gemini-(?:2\.5|[3-9])|gemini-(?:flash|pro)-latest|claude-(?:3-7|[4-9])~', strtolower( $this->model() ) );
	}

	/** @return array<string,bool> */
	public function default_caps() {
		$structured = $this->config['structured'];
		return array(
			// DeepSeek rejects json_schema; with "auto" it goes straight to JSON mode.
			'schema'                => $structured === 'schema' || ( $structured === 'auto' && ! $this->is_deepseek_host() ),
			'json'                  => $structured !== 'off',
			'thinking'              => $this->is_reasoning_model() || $this->is_deepseek_host() || $this->is_zhipu_thinking_model(),
			'effort_minimal'        => true,
			'max_completion_tokens' => $this->is_openai_host(),
			'temperature'           => $this->config['temperature'] !== null && $this->config['temperature'] !== '',
		);
	}

	/**
	 * @param array  $caps    Caps.
	 * @param string $feature Feature.
	 * @return array|null
	 */
	public function downgrade( array $caps, $feature ) {
		if ( $feature === 'max_completion_tokens' ) {
			// Toggle between max_tokens and max_completion_tokens once; remember the switch.
			if ( empty( $caps['mct_toggled'] ) ) {
				$caps['max_completion_tokens'] = empty( $caps['max_completion_tokens'] );
				$caps['mct_toggled']           = true;
				return $caps;
			}
			return null;
		}
		// Reasoning models that refuse "minimal" (o3, o4-mini …) get "low" before reasoning
		// control is dropped altogether (which would leave them at their default, higher level).
		if ( $feature === 'thinking' && $this->is_reasoning_model() && ! empty( $caps['effort_minimal'] ) && $this->config['thinking'] === 'off' ) {
			$caps['effort_minimal'] = false;
			return $caps;
		}
		if ( $feature === 'schema' && ! empty( $caps['schema'] ) ) {
			$caps['schema'] = false;
			return $caps;
		}
		if ( in_array( $feature, array( 'json', 'schema' ), true ) && ! empty( $caps['json'] ) ) {
			$caps['json']   = false;
			$caps['schema'] = false;
			return $caps;
		}
		if ( isset( $caps[ $feature ] ) && $caps[ $feature ] ) {
			$caps[ $feature ] = false;
			return $caps;
		}
		return null;
	}

	/**
	 * @param array $caps Caps.
	 * @return int
	 */
	public function thinking_reserve( array $caps ) {
		if ( $this->is_reasoning_model() ) {
			return ! empty( $caps['thinking'] ) ? 4096 : 8192;
		}
		if ( $this->is_deepseek_host() || $this->is_zhipu_thinking_model() ) {
			if ( preg_match( '~reason|r1~', strtolower( $this->model() ) ) ) {
				return 8192; // deepseek-reasoner thinks whatever the request says.
			}
			return ! empty( $caps['thinking'] ) && $this->config['thinking'] === 'off' ? 0 : 8192;
		}
		return $this->may_think() ? 8192 : 0;
	}

	/**
	 * @param string $system  System.
	 * @param string $user    User.
	 * @param int    $max_out Max output.
	 * @param array  $caps    Caps.
	 * @return array
	 */
	public function build_request( $system, $user, $max_out, array $caps ) {
		$body = array(
			'model'    => $this->model(),
			'messages' => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
		);
		if ( ! empty( $caps['json'] ) ) {
			if ( ! empty( $caps['schema'] ) ) {
				$body['response_format'] = array(
					'type'        => 'json_schema',
					'json_schema' => array(
						'name'   => 'translations',
						'strict' => true,
						'schema' => AITP_Protocol::json_schema(),
					),
				);
			} else {
				$body['response_format'] = array( 'type' => 'json_object' );
			}
		}
		if ( ! empty( $caps['max_completion_tokens'] ) ) {
			$body['max_completion_tokens'] = (int) $max_out;
		} else {
			$body['max_tokens'] = (int) $max_out;
		}
		if ( ! empty( $caps['thinking'] ) && $this->is_reasoning_model() ) {
			$body['reasoning_effort'] = $this->config['thinking'] === 'off' && ( ! isset( $caps['effort_minimal'] ) || $caps['effort_minimal'] ) ? 'minimal' : 'low';
		} elseif ( ! empty( $caps['thinking'] ) && ( $this->is_deepseek_host() || $this->is_zhipu_thinking_model() ) && $this->config['thinking'] !== 'auto' ) {
			// DeepSeek and GLM 4.5+ think by default; translation does not need it (slower and dearer).
			$body['thinking'] = array( 'type' => $this->config['thinking'] === 'off' ? 'disabled' : 'enabled' );
		}
		if ( ! empty( $caps['temperature'] ) ) {
			$body['temperature'] = (float) $this->config['temperature'];
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . (string) $this->config['api_key'],
		);
		if ( strpos( strtolower( (string) parse_url( $this->base_url(), PHP_URL_HOST ) ), 'openrouter.ai' ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$headers['X-Title'] = 'AI Translator for TranslatePress';
			if ( ! empty( $this->config['site_url'] ) ) {
				$headers['HTTP-Referer'] = (string) $this->config['site_url'];
			}
		}

		return array(
			'url'     => $this->base_url() . '/chat/completions',
			'headers' => $headers,
			'body'    => (string) json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * @param int    $status  Status.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param array  $caps    Caps.
	 * @return array
	 */
	public function parse_response( $status, array $headers, $body, array $caps = array() ) {
		$data = json_decode( (string) $body, true );
		$err  = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : null;

		if ( (int) $status !== 200 || $err !== null ) {
			$msg  = '';
			$code = (int) $status;
			if ( is_array( $err ) ) {
				$msg = trim( ( isset( $err['type'] ) ? $err['type'] . ' ' : '' ) . ( isset( $err['code'] ) && ! is_array( $err['code'] ) ? $err['code'] . ' ' : '' ) . ( isset( $err['message'] ) && is_string( $err['message'] ) ? $err['message'] : '' ) );
				if ( $code === 200 ) {
					$code = isset( $err['code'] ) && is_numeric( $err['code'] ) ? (int) $err['code'] : 500;
				}
			} elseif ( is_string( $err ) ) {
				$msg = $err;
				if ( $code === 200 ) {
					$code = 500;
				}
			}
			return $this->error_result( $code, $headers, is_string( $body ) ? substr( $body, 0, 2000 ) : '', $msg, $caps );
		}

		$r               = $this->base_result();
		$r['request_id'] = self::header( $headers, 'x-request-id' );
		if ( $r['request_id'] === '' && is_array( $data ) && isset( $data['id'] ) ) {
			$r['request_id'] = (string) $data['id'];
		}
		if ( ! is_array( $data ) || empty( $data['choices'][0] ) || ! is_array( $data['choices'][0] ) ) {
			$r['error']   = 'invalid_response';
			$r['message'] = 'No choices in response';
			return $r;
		}
		if ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$u        = $data['usage'];
			$thinking = isset( $u['completion_tokens_details']['reasoning_tokens'] ) ? (int) $u['completion_tokens_details']['reasoning_tokens'] : 0;
			// "output" is the visible answer, as with Gemini; reasoning is billed as output too.
			$r['usage'] = array(
				'input'    => isset( $u['prompt_tokens'] ) ? (int) $u['prompt_tokens'] : 0,
				'output'   => max( 0, ( isset( $u['completion_tokens'] ) ? (int) $u['completion_tokens'] : 0 ) - $thinking ),
				'thinking' => $thinking,
			);
		}
		$choice  = $data['choices'][0];
		$message = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();
		$content = isset( $message['content'] ) ? $message['content'] : '';
		if ( is_array( $content ) ) {
			$joined = '';
			foreach ( $content as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) && ( ! isset( $part['type'] ) || $part['type'] === 'text' || $part['type'] === 'output_text' ) ) {
					$joined .= $part['text'];
				}
			}
			$content = $joined;
		}
		$r['ok']   = true;
		$r['text'] = is_string( $content ) ? $content : '';

		$reason = isset( $choice['finish_reason'] ) ? strtolower( (string) $choice['finish_reason'] ) : '';
		if ( ! empty( $message['refusal'] ) ) {
			$r['finish'] = 'safety';
		} elseif ( in_array( $reason, array( 'stop', 'eos', 'end_turn', 'stop_sequence', '' ), true ) ) {
			$r['finish'] = 'stop';
		} elseif ( in_array( $reason, array( 'length', 'max_tokens' ), true ) ) {
			$r['finish'] = 'length';
		} elseif ( in_array( $reason, array( 'content_filter', 'safety', 'sensitive' ), true ) ) {
			$r['finish'] = 'safety';
		} else {
			$r['finish'] = 'other';
		}
		$r['message'] = 'finish_reason=' . ( $reason !== '' ? $reason : 'none' );
		return $r;
	}
}

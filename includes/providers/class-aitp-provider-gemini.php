<?php
/**
 * Google Gemini native API (generateContent). Also works with relays that expose the
 * same API under a different base URL.
 *
 * The key is sent in the x-goog-api-key header, never in the URL.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AITP_Provider_Gemini extends AITP_Provider {

	/** @return string */
	public function id() {
		return 'gemini';
	}

	/** @return string */
	public function default_base_url() {
		return 'https://generativelanguage.googleapis.com/v1beta';
	}

	/** @return array<string,bool> */
	public function default_caps() {
		$structured = $this->config['structured'];
		return array(
			'schema'           => in_array( $structured, array( 'auto', 'schema' ), true ),
			'json'             => $structured !== 'off',
			'thinking'         => true,
			'thinking_minimal' => true,
			'thinking_budget0' => true,
			'temperature'      => $this->config['temperature'] !== null && $this->config['temperature'] !== '',
		);
	}

	/**
	 * @return string
	 */
	private function model_name() {
		return preg_replace( '~^models/~', '', $this->model() );
	}

	/**
	 * @param array $caps Caps.
	 * @return array|null
	 */
	private function thinking_config( array $caps ) {
		if ( empty( $caps['thinking'] ) ) {
			return null;
		}
		$m    = strtolower( $this->model_name() );
		$mode = $this->config['thinking'];
		if ( preg_match( '~gemini-(?:[3-9]|\d{2})~', $m ) ) {
			// Thinking off: MINIMAL where the model accepts it; models that refuse MINIMAL
			// (e.g. 3.8 Flash) still accept a zero budget; LOW only when both were refused.
			if ( $mode === 'off' && ! empty( $caps['thinking_minimal'] ) ) {
				return array( 'thinkingLevel' => 'minimal' );
			}
			if ( $mode === 'off' && ( ! isset( $caps['thinking_budget0'] ) || $caps['thinking_budget0'] ) ) {
				return array( 'thinkingBudget' => 0 );
			}
			return array( 'thinkingLevel' => 'low' );
		}
		if ( strpos( $m, 'gemini-2.5-flash' ) !== false ) {
			return array( 'thinkingBudget' => $mode === 'low' ? 512 : 0 );
		}
		if ( strpos( $m, 'gemini-2.5-pro' ) !== false ) {
			return array( 'thinkingBudget' => $mode === 'low' ? 1024 : 128 );
		}
		return null;
	}

	/**
	 * @param array $caps Caps.
	 * @return int
	 */
	public function thinking_reserve( array $caps ) {
		$t = $this->thinking_config( $caps );
		if ( $t === null ) {
			// Unknown model or thinking control unsupported: the model may think at its default level.
			return preg_match( '~gemini-(?:2\.5|[3-9]|\d{2})~', strtolower( $this->model_name() ) ) ? 8192 : 1024;
		}
		if ( isset( $t['thinkingLevel'] ) ) {
			return $t['thinkingLevel'] === 'minimal' ? 1024 : 4096;
		}
		return (int) $t['thinkingBudget'] + 512;
	}

	/**
	 * Switch off a capability after the endpoint rejected it.
	 *
	 * @param array  $caps    Caps.
	 * @param string $feature Feature reported by detect_unsupported().
	 * @return array|null New caps or null when nothing can be downgraded.
	 */
	public function downgrade( array $caps, $feature ) {
		if ( $feature === 'thinking' && ! empty( $caps['thinking_minimal'] ) && $this->config['thinking'] === 'off' ) {
			$caps['thinking_minimal'] = false;
			return $caps;
		}
		if ( $feature === 'thinking' && ( ! isset( $caps['thinking_budget0'] ) || $caps['thinking_budget0'] ) && $this->config['thinking'] === 'off' && preg_match( '~gemini-(?:[3-9]|\d{2})~', strtolower( $this->model_name() ) ) ) {
			$caps['thinking_budget0'] = false;
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
	 * @param string $system  System.
	 * @param string $user    User.
	 * @param int    $max_out Max output tokens.
	 * @param array  $caps    Caps.
	 * @return array
	 */
	public function build_request( $system, $user, $max_out, array $caps ) {
		$gen = array( 'maxOutputTokens' => (int) $max_out );
		if ( ! empty( $caps['json'] ) ) {
			$gen['responseMimeType'] = 'application/json';
			if ( ! empty( $caps['schema'] ) ) {
				$gen['responseSchema'] = AITP_Protocol::gemini_schema();
			}
		}
		$thinking = $this->thinking_config( $caps );
		if ( $thinking !== null ) {
			$gen['thinkingConfig'] = $thinking;
		}
		if ( ! empty( $caps['temperature'] ) ) {
			$gen['temperature'] = (float) $this->config['temperature'];
		}

		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $user ) ),
				),
			),
			'generationConfig'  => $gen,
			'safetySettings'    => array(
				array( 'category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
				array( 'category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH' ),
				array( 'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
				array( 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
			),
		);

		return array(
			'url'     => $this->base_url() . '/models/' . rawurlencode( $this->model_name() ) . ':generateContent',
			'headers' => array(
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
				'x-goog-api-key' => (string) $this->config['api_key'],
			),
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
		if ( (int) $status !== 200 ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
			if ( is_array( $data ) && isset( $data['error']['status'] ) ) {
				$msg = $data['error']['status'] . ' ' . $msg;
			}
			$r = $this->error_result( (int) $status, $headers, is_string( $body ) ? substr( $body, 0, 2000 ) : '', $msg, $caps );
			if ( $r['retry_after'] === null && is_array( $data ) && ! empty( $data['error']['details'] ) && is_array( $data['error']['details'] ) ) {
				foreach ( $data['error']['details'] as $d ) {
					if ( is_array( $d ) && isset( $d['retryDelay'] ) ) {
						$r['retry_after'] = self::seconds( $d['retryDelay'] );
					}
				}
			}
			return $r;
		}

		$r               = $this->base_result();
		$r['request_id'] = is_array( $data ) && isset( $data['responseId'] ) ? (string) $data['responseId'] : self::header( $headers, 'x-request-id' );
		if ( ! is_array( $data ) ) {
			$r['error']   = 'invalid_response';
			$r['message'] = 'Response is not JSON';
			return $r;
		}
		if ( isset( $data['usageMetadata'] ) && is_array( $data['usageMetadata'] ) ) {
			$u          = $data['usageMetadata'];
			$r['usage'] = array(
				'input'    => isset( $u['promptTokenCount'] ) ? (int) $u['promptTokenCount'] : 0,
				'output'   => isset( $u['candidatesTokenCount'] ) ? (int) $u['candidatesTokenCount'] : 0,
				'thinking' => isset( $u['thoughtsTokenCount'] ) ? (int) $u['thoughtsTokenCount'] : 0,
			);
		}
		if ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
			$r['ok']      = true;
			$r['finish']  = 'safety';
			$r['message'] = 'Prompt blocked: ' . $data['promptFeedback']['blockReason'];
			return $r;
		}
		if ( empty( $data['candidates'][0] ) || ! is_array( $data['candidates'][0] ) ) {
			$r['error']   = 'invalid_response';
			$r['message'] = 'No candidates in response';
			return $r;
		}
		$cand   = $data['candidates'][0];
		$reason = isset( $cand['finishReason'] ) ? (string) $cand['finishReason'] : '';
		$text   = '';
		if ( isset( $cand['content']['parts'] ) && is_array( $cand['content']['parts'] ) ) {
			foreach ( $cand['content']['parts'] as $part ) {
				if ( is_array( $part ) && empty( $part['thought'] ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		$r['ok']   = true;
		$r['text'] = $text;
		if ( $reason === 'STOP' ) {
			$r['finish'] = 'stop';
		} elseif ( $reason === 'MAX_TOKENS' ) {
			$r['finish'] = 'length';
		} elseif ( in_array( $reason, array( 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' ), true ) ) {
			$r['finish'] = 'safety';
		} else {
			$r['finish'] = 'other';
		}
		$r['message'] = 'finishReason=' . ( $reason !== '' ? $reason : 'none' );
		return $r;
	}
}

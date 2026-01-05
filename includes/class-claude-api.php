<?php
/**
 * Claude API Integration Class
 */

class LendCity_Claude_API {

    private $api_key;
    private $api_url = 'https://api.anthropic.com/v1/messages';
    private $model = 'claude-opus-4-5-20251101';

    // Retry configuration
    private $max_retries = 3;
    private $retry_base_delay = 1; // seconds

    public function __construct() {
        $this->api_key = get_option('lendcity_claude_api_key');
    }

    /**
     * Make HTTP request with exponential backoff retry logic
     * Retries on transient network errors and 5xx server errors
     *
     * @param array $args Arguments for wp_remote_post
     * @param int $timeout Request timeout in seconds
     * @return array|WP_Error Response or error
     */
    private function http_request_with_retry($args, $timeout = 60) {
        $last_error = null;

        for ($attempt = 0; $attempt < $this->max_retries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 1s, 2s, 4s
                $delay = $this->retry_base_delay * pow(2, $attempt - 1);
                lendcity_debug_log("API retry attempt {$attempt} after {$delay}s delay");
                sleep($delay);
            }

            $args['timeout'] = $timeout;
            $response = wp_remote_post($this->api_url, $args);

            // If successful response, return it
            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);

                // Success - return response
                if ($response_code >= 200 && $response_code < 300) {
                    return $response;
                }

                // Client error (4xx) - don't retry, return immediately
                if ($response_code >= 400 && $response_code < 500) {
                    return $response;
                }

                // Server error (5xx) - retry
                $last_error = new WP_Error('server_error', 'Server error: HTTP ' . $response_code);
                lendcity_debug_log("API server error (HTTP {$response_code}), will retry");
                continue;
            }

            // Network error - check if retryable
            $error_code = $response->get_error_code();
            $retryable_errors = array(
                'http_request_failed',
                'http_failure',
                'operation_timedout',
                'connect_error',
                'curl_error'
            );

            if (in_array($error_code, $retryable_errors) || strpos($error_code, 'curl') !== false) {
                $last_error = $response;
                lendcity_debug_log("API network error ({$error_code}), will retry: " . $response->get_error_message());
                continue;
            }

            // Non-retryable error - return immediately
            return $response;
        }

        // All retries exhausted
        lendcity_log("API request failed after {$this->max_retries} attempts");
        return $last_error;
    }
    
    /**
     * Simple completion for short prompts (metadata, titles, etc)
     */
    public function simple_completion($prompt, $max_tokens = 300) {
        if (empty($this->api_key)) {
            error_log('LendCity Claude API Error: API key is empty or not set');
            return false;
        }

        error_log('LendCity Claude API: Making request with model ' . $this->model);
        
        // Sanitize prompt to ensure valid UTF-8
        $prompt = mb_convert_encoding($prompt, 'UTF-8', 'UTF-8');
        $prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $prompt);

        $body = array(
            'model' => $this->model,
            'max_tokens' => $max_tokens,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        );

        $json_body = json_encode($body);
        if ($json_body === false) {
            error_log('LendCity Claude API Error: Failed to encode request body - ' . json_last_error_msg());
            return false;
        }

        $response = $this->http_request_with_retry(array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => $json_body
        ), 60);

        if (is_wp_error($response)) {
            error_log('LendCity Claude API Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($raw_body, true);

        // Log detailed error info for non-200 responses
        if ($response_code !== 200) {
            $error_msg = isset($response_body['error']['message'])
                ? $response_body['error']['message']
                : 'Unknown error';
            error_log('LendCity Claude API Error (HTTP ' . $response_code . '): ' . $error_msg);
            error_log('LendCity Claude API Model: ' . $this->model);
            error_log('LendCity Claude API Full Response: ' . substr($raw_body, 0, 500));
            return false;
        }

        if (isset($response_body['content'][0]['text'])) {
            return $response_body['content'][0]['text'];
        }

        error_log('LendCity Claude API Error: Response missing content - ' . substr($raw_body, 0, 500));
        return false;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'API key is not set'
            );
        }
        
        // Simple test message
        $test_prompt = "Respond with just the word 'success' if you can read this.";
        
        $body = array(
            'model' => $this->model,
            'max_tokens' => 50,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $test_prompt
                )
            )
        );
        
        $response = wp_remote_post($this->api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message(),
                'error_code' => $response->get_error_code()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code !== 200) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            return array(
                'success' => false,
                'message' => 'API Error (Code ' . $response_code . '): ' . $error_msg,
                'response_code' => $response_code,
                'full_response' => $body
            );
        }
        
        return array(
            'success' => true,
            'message' => 'Connection successful!',
            'model' => $this->model
        );
    }
    
    /**
     * Analyze content and generate tags/keywords
     */
    public function analyze_content($post_id, $content, $gsc_data = array(), $ilj_keywords = array(), $existing_tags = array()) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'API key not set'
            );
        }
        
        $post = get_post($post_id);
        $post_title = $post->post_title;
        $post_url = get_permalink($post_id);
        
        // Build context for Claude
        $prompt = $this->build_analysis_prompt(
            $post_title,
            $content,
            $gsc_data,
            $ilj_keywords,
            $existing_tags
        );
        
        $response = $this->make_api_request($prompt);
        
        if ($response['success']) {
            return $this->parse_response($response['data']);
        }
        
        return $response;
    }
    
    /**
     * Build the prompt for Claude
     */
    private function build_analysis_prompt($title, $content, $gsc_data, $ilj_keywords, $existing_tags) {
        $prompt = "You are analyzing content for LendCity Mortgages, a Canadian mortgage brokerage specializing in investment property financing.\n\n";
        
        $prompt .= "POST TITLE: {$title}\n\n";
        $prompt .= "POST CONTENT:\n{$content}\n\n";
        
        if (!empty($gsc_data['site_wide_queries'])) {
            $prompt .= "TOP SITE-WIDE SEARCH QUERIES (Google Search Console):\n";
            foreach ($gsc_data['site_wide_queries'] as $query) {
                $prompt .= "- {$query['query']} (Impressions: {$query['impressions']}, Clicks: {$query['clicks']})\n";
            }
            $prompt .= "\n";
        }
        
        if (!empty($gsc_data['page_queries'])) {
            $prompt .= "SEARCH QUERIES FOR THIS PAGE:\n";
            foreach ($gsc_data['page_queries'] as $query) {
                $prompt .= "- {$query['query']} (Impressions: {$query['impressions']}, Clicks: {$query['clicks']})\n";
            }
            $prompt .= "\n";
        }
        
        if (!empty($ilj_keywords)) {
            $prompt .= "EXISTING INTERNAL LINK JUICER KEYWORDS:\n";
            foreach ($ilj_keywords as $kw) {
                $prompt .= "- {$kw}\n";
            }
            $prompt .= "\n";
        }
        
        if (!empty($existing_tags)) {
            $prompt .= "EXISTING SITE TAGS (check against these):\n";
            $prompt .= implode(', ', $existing_tags) . "\n\n";
        }
        
        $prompt .= "CRITICAL TASK - Two Different Types of Output:\n\n";
        
        $prompt .= "1. TAGS (3-5 BROAD TOPICS ONLY):\n";
        $prompt .= "   - High-level, general topics (e.g., 'Mortgage Financing', 'Real Estate Investment')\n";
        $prompt .= "   - Topics that could apply to MULTIPLE posts\n";
        $prompt .= "   - Will be visible to users on the website\n";
        $prompt .= "   - Prefer existing site tags when they match\n";
        $prompt .= "   - ONLY 3-5 tags maximum\n\n";
        
        $prompt .= "2. KEYWORDS (5-10 SPECIFIC PHRASES):\n";
        $prompt .= "   - Specific phrases from GSC data for internal linking\n";
        $prompt .= "   - More specific than tags (e.g., 'stated income mortgage programs', 'Canadian borrowers qualify')\n";
        $prompt .= "   - These create automatic links between posts (hidden from users)\n";
        $prompt .= "   - Focus on high-value search terms with:\n";
        $prompt .= "     * High impressions but low clicks (opportunity gaps)\n";
        $prompt .= "     * Terms already driving traffic (double down)\n";
        $prompt .= "   - Avoid keywords already heavily used across the site\n\n";
        
        $prompt .= "RESPONSE FORMAT (use EXACTLY this JSON structure):\n";
        $prompt .= "{\n";
        $prompt .= '  "tags": ["Broad Topic 1", "Broad Topic 2", "Broad Topic 3"],';
        $prompt .= "\n";
        $prompt .= '  "keywords": ["specific phrase one", "specific phrase two", "specific phrase three"],';
        $prompt .= "\n";
        $prompt .= '  "reasoning": "Brief explanation: Why these 3-5 broad tags? Why these 5-10 specific keywords?"';
        $prompt .= "\n}\n\n";
        $prompt .= "Return ONLY valid JSON, no other text.";
        
        return $prompt;
    }
    
    /**
     * Make API request to Claude with retry logic
     */
    private function make_api_request($prompt) {
        $body = array(
            'model' => $this->model,
            'max_tokens' => 2000,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        );

        $response = $this->http_request_with_retry(array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode($body)
        ), 60);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return array(
                'success' => false,
                'message' => $body['error']['message']
            );
        }

        return array(
            'success' => true,
            'data' => $body
        );
    }
    
    /**
     * Parse Claude's response
     */
    private function parse_response($data) {
        if (!isset($data['content'][0]['text'])) {
            return array(
                'success' => false,
                'message' => 'Invalid response format'
            );
        }
        
        $text = $data['content'][0]['text'];
        
        // Extract JSON from response (in case Claude added any preamble)
        preg_match('/\{[\s\S]*\}/', $text, $matches);
        
        if (empty($matches)) {
            return array(
                'success' => false,
                'message' => 'Could not parse JSON response',
                'raw_response' => $text
            );
        }
        
        $json = json_decode($matches[0], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'JSON decode error: ' . json_last_error_msg(),
                'raw_response' => $text
            );
        }
        
        // Validate that tags and keywords are arrays of strings
        $tags = isset($json['tags']) && is_array($json['tags']) ? $json['tags'] : array();
        $keywords = isset($json['keywords']) && is_array($json['keywords']) ? $json['keywords'] : array();
        
        // Filter out any non-string values
        $tags = array_filter($tags, function($item) {
            return is_string($item) && !empty(trim($item));
        });
        $keywords = array_filter($keywords, function($item) {
            return is_string($item) && !empty(trim($item));
        });
        
        // Re-index arrays after filtering
        $tags = array_values($tags);
        $keywords = array_values($keywords);
        
        error_log('LendCity Claude API Response - Tags: ' . count($tags) . ', Keywords: ' . count($keywords));
        if (!empty($tags)) {
            error_log('LendCity Claude Tags: ' . implode(', ', $tags));
        }
        if (!empty($keywords)) {
            error_log('LendCity Claude Keywords: ' . implode(', ', $keywords));
        }
        
        return array(
            'success' => true,
            'tags' => $tags,
            'keywords' => $keywords,
            'reasoning' => isset($json['reasoning']) ? $json['reasoning'] : ''
        );
    }
    
    /**
     * Generate long-form content (articles, rewrites, etc.)
     * Returns raw text response from Claude
     * 
     * @param string $prompt The prompt to send to Claude
     * @param int $max_tokens Maximum tokens to generate (default 4096 for ~3000 words)
     * @return string|WP_Error The generated content or error
     */
    public function generate_content($prompt, $max_tokens = 4096) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', 'Claude API key is not set.');
        }
        
        $body = array(
            'model' => $this->model,
            'max_tokens' => $max_tokens,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        );
        
        error_log('LendCity: Sending article generation request to Claude API');
        error_log('LendCity: Prompt length: ' . strlen($prompt) . ' chars');

        $response = $this->http_request_with_retry(array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode($body)
        ), 300); // 5 minutes for long content generation

        error_log('LendCity: wp_remote_post completed');

        if (is_wp_error($response)) {
            error_log('LendCity Claude API WP_Error: ' . $response->get_error_message());
            error_log('LendCity Claude API Error Code: ' . $response->get_error_code());
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('LendCity: Claude API response code: ' . $response_code);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) 
                ? $error_data['error']['message'] 
                : 'Unknown API error (code: ' . $response_code . ')';
            error_log('LendCity Claude API Error: ' . $error_message);
            return new WP_Error('api_error', $error_message);
        }
        
        $data = json_decode($response_body, true);
        
        if (!isset($data['content'][0]['text'])) {
            error_log('LendCity: Unexpected Claude API response format');
            return new WP_Error('invalid_response', 'Unexpected response format from Claude API');
        }
        
        $content = $data['content'][0]['text'];
        error_log('LendCity: Successfully received ' . strlen($content) . ' characters from Claude');
        
        return $content;
    }
}

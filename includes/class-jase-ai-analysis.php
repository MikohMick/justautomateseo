<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_AI_Analysis {

    private $api_key;

    public function __construct() {
        $this->api_key = JASE_Settings::get_openai_key();
    }

    private function chat_completion( $messages, $max_tokens = 2048, $temperature = 0.3 ) {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => 'gpt-4o-mini',
                'messages'    => $messages,
                'max_tokens'  => $max_tokens,
                'temperature' => $temperature,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'openai_error', $body['error']['message'] ?? 'OpenAI API error' );
        }

        return $body['choices'][0]['message']['content'] ?? '';
    }

    public function analyze_keywords( $keywords ) {
        $keyword_list = '';
        foreach ( $keywords as $kw ) {
            $keyword_list .= sprintf(
                "- \"%s\" (volume: %d, competition: %s, trend: %.1f)\n",
                $kw['text'],
                $kw['volume'],
                $kw['competition_level'],
                $kw['trend']
            );
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an SEO strategist. Analyze keywords and return ONLY valid JSON - no other text. Score keywords 1-10 based on: search volume opportunity, low competition potential, positive trend, and content creation potential. Return the top 5 keywords.',
            ],
            [
                'role'    => 'user',
                'content' => "Analyze these keywords and return the best 5 as JSON array:\n\n{$keyword_list}\n\nReturn ONLY a JSON array like: [{\"text\":\"keyword\",\"score\":8,\"reasoning\":\"brief reason\",\"volume\":1000,\"competition_level\":\"LOW\"}]",
            ],
        ];

        $result = $this->chat_completion( $messages, 1024, 0.3 );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $result = trim( $result );
        $result = preg_replace( '/^```json\s*/', '', $result );
        $result = preg_replace( '/\s*```$/', '', $result );

        $parsed = json_decode( $result, true );
        if ( ! is_array( $parsed ) ) {
            return new WP_Error( 'parse_error', 'Failed to parse AI keyword analysis' );
        }

        return $parsed;
    }

    public function analyze_questions( $questions, $keyword ) {
        $question_list = '';
        foreach ( $questions as $q ) {
            $question_list .= sprintf(
                "- \"%s\" (volume: %d, competition: %s)\n",
                $q['text'],
                $q['volume'],
                $q['competition_level']
            );
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an SEO content strategist. Analyze questions for a keyword and return ONLY valid JSON. Score questions 1-10 based on: search volume, content potential, user intent clarity, and ability to create comprehensive answers.',
            ],
            [
                'role'    => 'user',
                'content' => "For the keyword \"{$keyword}\", analyze these questions and return the best 5 as JSON:\n\n{$question_list}\n\nReturn ONLY a JSON array like: [{\"text\":\"question\",\"score\":8,\"reasoning\":\"brief reason\",\"volume\":500}]",
            ],
        ];

        $result = $this->chat_completion( $messages, 1024, 0.3 );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $result = trim( $result );
        $result = preg_replace( '/^```json\s*/', '', $result );
        $result = preg_replace( '/\s*```$/', '', $result );

        $parsed = json_decode( $result, true );
        if ( ! is_array( $parsed ) ) {
            return new WP_Error( 'parse_error', 'Failed to parse AI question analysis' );
        }

        return $parsed;
    }

    public function generate_title( $keyword, $competitor_snippets = '' ) {
        $messages = [
            [
                'role'    => 'system',
                'content' => "You are an expert SEO copywriter. Return ONLY the optimized title - no explanations, no quotes. 50-60 characters. Must include the target keyword naturally.",
            ],
            [
                'role'    => 'user',
                'content' => "Keyword: {$keyword}\n\nCompetitor context:\n{$competitor_snippets}\n\nCreate 1 SEO-optimized title that is actionable and specific. Return only the title.",
            ],
        ];

        return $this->chat_completion( $messages, 100, 0.7 );
    }

    public function generate_content( $title, $keyword, $questions, $sitemap_urls = '' ) {
        $question_section = '';
        if ( ! empty( $questions ) ) {
            $question_section = "Address these related questions in the content:\n";
            foreach ( $questions as $q ) {
                $question_section .= "- {$q}\n";
            }
        }

        $linking_section = '';
        if ( ! empty( $sitemap_urls ) ) {
            $linking_section = "\n\nAVAILABLE INTERNAL LINKS (use 3-5 naturally):\n{$sitemap_urls}";
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => "You are an expert content writer. Create comprehensive, SEO-optimized blog posts. Write in a helpful, authoritative tone. Your articles should:\n- Use HTML headings (h1, h2, h3) for structure\n- Include practical, actionable advice\n- Include an FAQ section\n- Be 1500-2000 words\n- Use conversational but professional tone\n- Output clean HTML without markdown formatting\n- Include internal links naturally if URLs are provided",
            ],
            [
                'role'    => 'user',
                'content' => "Write a comprehensive blog post.\n\nTitle: {$title}\nTarget keyword: {$keyword}\n\n{$question_section}{$linking_section}\n\nUse HTML tags (h1, h2, h3, p, strong, ul, li, a). No markdown. No code blocks. Output clean HTML directly.",
            ],
        ];

        return $this->chat_completion( $messages, 4000, 0.5 );
    }

    public function suggest_image_prompts( $title, $keyword ) {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a creative director. Return ONLY a JSON array of 3 image description prompts suitable for DALL-E generation. Each should be a professional blog header image concept.',
            ],
            [
                'role'    => 'user',
                'content' => "Suggest 3 featured image concepts for a blog post titled \"{$title}\" about \"{$keyword}\".\n\nReturn ONLY JSON: [{\"prompt\":\"description\",\"style\":\"modern/minimalist/etc\"}]",
            ],
        ];

        $result = $this->chat_completion( $messages, 500, 0.7 );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $result = trim( $result );
        $result = preg_replace( '/^```json\s*/', '', $result );
        $result = preg_replace( '/\s*```$/', '', $result );

        $parsed = json_decode( $result, true );
        return is_array( $parsed ) ? $parsed : [];
    }

    public function generate_image( $prompt ) {
        $response = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'           => 'dall-e-3',
                'prompt'          => $prompt,
                'n'               => 1,
                'size'            => '1792x1024',
                'quality'         => 'standard',
                'response_format' => 'url',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'dalle_error', $body['error']['message'] ?? 'Image generation failed' );
        }

        return $body['data'][0]['url'] ?? '';
    }
}

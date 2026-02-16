<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_AI_Analysis {

    private $api_key;

    public function __construct() {
        $this->api_key = JASE_Settings::get_openai_key();
    }

    private function chat_completion( $messages, $max_tokens = 2048, $temperature = 0.3, $model = 'gpt-4o-mini' ) {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => $model,
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
            $text        = $kw['text'] ?? '';
            $volume      = $kw['volume'] ?? 0;
            $competition = $kw['competition_level'] ?? '';

            if ( $volume > 0 || ! empty( $competition ) ) {
                $keyword_list .= sprintf(
                    "- \"%s\" (volume: %d, competition: %s)\n",
                    $text, $volume, $competition
                );
            } else {
                $keyword_list .= sprintf( "- \"%s\"\n", $text );
            }
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an SEO strategist. Analyze keywords from Google Autocomplete and return ONLY valid JSON - no other text. Score keywords 1-10 based on: search intent clarity, content creation potential, specificity (long-tail is better), and commercial or informational value. Return the top 5 keywords.',
            ],
            [
                'role'    => 'user',
                'content' => "These are real Google Autocomplete suggestions. Analyze them and return the best 5 as JSON array:\n\n{$keyword_list}\n\nReturn ONLY a JSON array like: [{\"text\":\"keyword\",\"score\":8,\"reasoning\":\"brief reason\",\"volume\":0,\"competition_level\":\"\"}]",
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
            $text   = $q['text'] ?? '';
            $volume = $q['volume'] ?? 0;

            if ( $volume > 0 ) {
                $question_list .= sprintf( "- \"%s\" (volume: %d)\n", $text, $volume );
            } else {
                $question_list .= sprintf( "- \"%s\"\n", $text );
            }
        }

        $system_prompt = <<<'SYSTEM'
You are an SEO content strategist trained on Neil Patel, SEMRush, and Ahrefs methodologies. Analyze questions from Google Autocomplete and score them for content creation potential.

## RANKING CRITERIA (SEMRush/Ahrefs/Neil Patel Framework)

### High-Scoring Question Types (8-10/10):
1. **"How to" questions** - Actionable, tutorial potential, aligns with informational intent
   - "how to start a blog", "how to lose weight fast"

2. **"What is/are" questions** - Definitional, comprehensive guide potential, often featured snippet targets
   - "what is SEO", "what are backlinks"

3. **"Why" questions** - Explanation-driven, builds authority, addresses pain points
   - "why does my website load slowly", "why is content marketing important"

4. **Comparison questions** - "vs", "or", "compared to" - High commercial intent, comprehensive content
   - "Ahrefs vs SEMrush", "WordPress or Wix"

5. **Listicle-style** - "top 5", "top 10", "best", "checklist" - High CTR, scannable, shareable
   - "top 10 SEO tools", "best practices for email marketing"

6. **"Which/When/Where" questions** - Specific intent, practical advice
   - "which keyword research tool is best", "when to publish blog posts"

### Medium-Scoring (5-7/10):
- Single-word modifiers without clear intent ("keyword tips", "SEO tools")
- Broad questions lacking specificity ("how to do marketing")
- Niche questions with limited content expansion potential

### Low-Scoring (1-4/10):
- Extremely narrow/local questions ("pizza near me")
- Vague questions without clear search intent
- Questions requiring real-time data ("today's weather")

## SCORING FACTORS:
- **Content depth potential** (can we write 1000-2000 words?)
- **Search intent clarity** (informational > transactional for blog content)
- **Featured snippet opportunity** (definitional, how-to, comparison)
- **Keyword specificity** (specific > broad)
- **Commercial viability** (does this attract target audience?)

Return ONLY valid JSON with the top 5 questions, sorted by score descending.
SYSTEM;

        $user_prompt = "Keyword: \"{$keyword}\"\n\n";
        $user_prompt .= "Questions from Google Autocomplete:\n{$question_list}\n\n";
        $user_prompt .= 'Analyze and return the top 5 questions as JSON: [{"text":"question","score":8,"reasoning":"brief reason citing SEO principles","volume":0}]';

        $messages = [
            [
                'role'    => 'system',
                'content' => $system_prompt,
            ],
            [
                'role'    => 'user',
                'content' => $user_prompt,
            ],
        ];

        $result = $this->chat_completion( $messages, 1536, 0.4, 'gpt-4o-mini' );
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
                'content' => "You are an expert SEO copywriter trained in the Neil Patel and Ahrefs methodology. Return ONLY the optimized title — no explanations, no quotes, no extra text.\n\nTitle rules:\n- 50-60 characters for optimal SERP display\n- Include the target keyword naturally (front-load when possible)\n- Use power words that drive clicks (Ultimate, Proven, Essential, Complete, etc.)\n- Be specific — include a number, year, or concrete benefit when relevant\n- Match search intent (informational, how-to, listicle, guide)\n- Avoid clickbait — the title must accurately reflect the content",
            ],
            [
                'role'    => 'user',
                'content' => "Target keyword: {$keyword}\n\nContext:\n{$competitor_snippets}\n\nCreate 1 high-CTR, SEO-optimized title. Return only the title text.",
            ],
        ];

        return $this->chat_completion( $messages, 100, 0.7, 'gpt-4o' );
    }

    public function generate_content( $title, $keyword, $questions, $sitemap_urls = '' ) {
        $question_section = '';
        if ( ! empty( $questions ) ) {
            $question_section = "Related questions to address within the article:\n";
            foreach ( $questions as $q ) {
                $question_section .= "- {$q}\n";
            }
        }

        $linking_section = '';
        if ( ! empty( $sitemap_urls ) ) {
            $linking_section = "\n\nINTERNAL LINKING (MANDATORY):\nInclude 4-5 internal links from the URLs below. Rules:\n- Use descriptive, keyword-rich anchor text (NEVER \"click here\" or \"read more\")\n- Spread links naturally throughout the body — not clustered in one section\n- Link contextually where the anchor text relates to the destination page\n- Each link should feel like a helpful suggestion, not forced placement\n\nAvailable URLs:\n{$sitemap_urls}";
        }

        $system_prompt = <<<'SYSTEM'
You are a world-class SEO content writer trained on the methodologies of Neil Patel, SEMRush, and Ahrefs. You produce content that ranks on the first page of Google.

## CONTENT QUALITY PRINCIPLES

### Hook & Engagement (Neil Patel Method)
- Open with a powerful hook: a surprising statistic, bold statement, provocative question, or relatable pain point
- Use the APP formula in the intro: Agree (acknowledge the reader's problem), Promise (what they'll learn), Preview (brief outline)
- Write in short paragraphs (2-4 sentences max) for scanability
- Use bucket brigades to maintain attention ("Here's the thing:", "But wait — there's more:", "Now, here's where it gets interesting:")
- Address the reader directly using "you" and "your"

### Structure & Organization (SEMRush Best Practices)
- Use a clear H2/H3 heading hierarchy — NEVER use H1 (WordPress adds it from the title)
- Each H2 section covers one core subtopic (200-300 words per section)
- Use H3 for sub-points within an H2 section
- Include a table of contents-friendly structure (descriptive H2 headings that stand alone)
- Use bullet points and numbered lists to break up dense information
- Bold key phrases and important takeaways for skimmers

### SEO On-Page Optimization (Ahrefs Guidelines)
- Place the target keyword in the first 100 words naturally
- Use the target keyword 3-5 times total (NO keyword stuffing — write for humans first)
- Include semantic variations and LSI keywords naturally throughout
- Use descriptive anchor text for all links
- Write a compelling meta-description-worthy first paragraph
- Include the keyword in at least one H2 heading

### E-E-A-T Signals (Google Quality Guidelines)
- Demonstrate Experience: include practical tips, "from experience" insights, real-world scenarios
- Show Expertise: provide specific data points, explain the "why" behind advice
- Build Authority: reference industry concepts and best practices confidently
- Establish Trust: be transparent, acknowledge limitations, give balanced viewpoints

### FAQ Section
- Include a dedicated FAQ section with 3-5 questions near the end
- Use proper HTML structure: each question in an H3, answer in a paragraph
- Answer concisely but thoroughly (2-4 sentences per answer)
- Target "People Also Ask" style questions related to the topic

## OUTPUT RULES
- Output 1200-2000 words of clean HTML
- Use ONLY these HTML tags: h2, h3, p, strong, em, ul, ol, li, a, blockquote, hr
- Do NOT output H1 tags, markdown, code blocks, or wrapper divs
- Do NOT include a title — WordPress handles this
- Every paragraph must deliver value — no filler, no fluff, no padding
- End with a strong conclusion that summarizes key takeaways and includes a clear call-to-action
SYSTEM;

        $user_prompt = "Write a high-quality, SEO-optimized blog post that would rank on Google's first page.\n\n";
        $user_prompt .= "Title: {$title}\n";
        $user_prompt .= "Target keyword: {$keyword}\n\n";
        $user_prompt .= $question_section;
        $user_prompt .= $linking_section;
        $user_prompt .= "\n\nOutput clean HTML directly. No markdown. No code fences. No H1 tag.";

        $messages = [
            [
                'role'    => 'system',
                'content' => $system_prompt,
            ],
            [
                'role'    => 'user',
                'content' => $user_prompt,
            ],
        ];

        return $this->chat_completion( $messages, 4096, 0.6, 'gpt-4o' );
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

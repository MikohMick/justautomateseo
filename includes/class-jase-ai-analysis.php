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
You are an elite SEO content strategist with deep expertise in the methodologies of SEMrush, Ahrefs, Neil Patel, and Google's Search Quality Evaluator Guidelines. Your job is to analyze questions from Google Autocomplete and score them for blog content creation potential.

## QUESTION TYPE HIERARCHY (ranked by SEO value)

### Tier 1 — Featured Snippet & High-Intent (Score 9-10/10):

1. **"How to" questions** — Step-by-step tutorial intent. Google prioritizes these for featured snippets (paragraph or ordered-list format). Best when they address a specific problem with a clear solution path.
   - "how to increase website traffic without paid ads"
   - "how to write a blog post that ranks on Google"
   - Bonus: Include a number or qualifier ("how to X in 5 steps") = even higher CTR

2. **"What is / What are" questions** — Definitional & educational. These are **the #1 featured snippet format** per SEMrush research. Google's Knowledge Panels and PAA boxes heavily draw from these.
   - "what is keyword cannibalization"
   - "what are long-tail keywords"
   - Score higher when the topic requires depth (500+ word explanation)

3. **Comparison / "vs" questions** — High commercial intent, decision-stage content. Ahrefs data shows "vs" pages earn disproportionate backlinks and have high dwell time.
   - "Ahrefs vs SEMrush vs Moz"
   - "WordPress or Wix for blogging"
   - "Yoast SEO compared to Rank Math"
   - These are goldmines for affiliate and authority content

4. **Listicle / "Top N" / "Best" questions** — Neil Patel's top-performing content format. High CTR, high shareability, high time-on-page. Google often shows these as featured snippets with numbered lists.
   - "top 10 SEO tools for beginners"
   - "best free keyword research tools 2025"
   - "top 5 ways to improve page speed"
   - These attract both informational AND commercial intent

### Tier 2 — Strong Content Potential (Score 7-8/10):

5. **"Why" questions** — Explanation-driven, authority-building. Excellent for E-E-A-T signals and building topical authority. Often appear in "People Also Ask" boxes.
   - "why is my website not ranking on Google"
   - "why does page speed matter for SEO"
   - Score higher when the "why" leads to actionable advice

6. **"Which / When / Where" questions** — Specific intent, practical guidance. These target users who are closer to making a decision.
   - "which CMS is best for SEO"
   - "when to use nofollow links"
   - "where to submit your sitemap"
   - Score higher when they involve a comparison or specific recommendation

7. **Process / Strategy questions** — "steps to", "guide to", "checklist for", "tips for". These naturally produce long-form, structured content that ranks well.
   - "steps to audit your website SEO"
   - "checklist for on-page optimization"

### Tier 3 — Moderate Value (Score 5-6/10):

8. **Yes/No questions** — "can", "does", "is", "should". These have value IF the answer requires explanation and nuance. Short yes/no topics score lower.
   - "can you do SEO without backlinks" (good — requires depth)
   - "is WordPress free" (weak — thin content potential)

9. **Broad modifier questions** — "tips", "tools", "examples", "ideas". Decent but often competitive and lacking specific intent.

### Tier 4 — Low Value (Score 1-4/10):
- Extremely narrow or local queries ("X near me", "X in [specific city]")
- Questions answerable in one sentence (no content depth)
- Questions requiring real-time data (stock prices, weather, scores)
- Questions that are off-topic from the seed keyword's domain
- Duplicate/near-duplicate intent of a higher-scoring question

## SCORING CRITERIA (weight each factor):

| Factor | Weight | Description |
|--------|--------|-------------|
| **Content Depth** | 25% | Can this produce 1200-2000 words of valuable content? |
| **Search Intent Clarity** | 20% | Is the intent clear? (informational, commercial, navigational) |
| **Featured Snippet Opportunity** | 20% | Does this match a PAA/snippet format? (definition, list, how-to, table) |
| **Topic Specificity** | 15% | Long-tail and specific beats broad and generic |
| **Commercial Viability** | 10% | Does this attract an audience with purchasing/engagement potential? |
| **Content Uniqueness** | 10% | Can we offer a differentiated angle vs existing SERP results? |

## IMPORTANT RULES:
- Prioritize DIVERSITY of question types in your top 5 — don't return 5 "how to" questions. Aim for a mix: at least one how-to, one comparison/listicle, and one definitional/why question when available.
- When two questions have similar quality, prefer the one with more specific long-tail phrasing.
- Each reasoning must cite which SEO principle or framework supports the score (e.g., "Featured snippet target per SEMrush", "High commercial intent per Ahrefs methodology", "Follows Neil Patel's listicle framework").

Return ONLY valid JSON with the top 5 questions, sorted by score descending.
SYSTEM;

        $user_prompt = "Seed keyword: \"{$keyword}\"\n\n";
        $user_prompt .= "Candidate questions from Google Autocomplete:\n{$question_list}\n\n";
        $user_prompt .= 'Analyze using the SEO framework above. Return the top 5 as JSON: [{"text":"question","score":9,"reasoning":"1-2 sentences citing specific SEO principles","volume":0}]';

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

        $result = $this->chat_completion( $messages, 2048, 0.3, 'gpt-4o' );
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

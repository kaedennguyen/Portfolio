=== Website Audit Generator ===
Contributors: you
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

A self-contained WordPress plugin that lets a visitor enter a URL and get
back an SEO / AEO / Content / Design audit with a weighted overall score.

== Installation ==

1. Zip the `website-audit-generator` folder (or upload it as-is via SFTP to
   `wp-content/plugins/`).
2. Activate the plugin from Plugins → Installed Plugins.
3. Go to Settings → Audit Generator and add your Claude API key
   (required — get one at console.anthropic.com). A Google PageSpeed API
   key is optional but recommended for higher rate limits (free from
   Google Cloud Console — enable the "PageSpeed Insights API").
4. Create or edit any page/post and add the shortcode:

   [website_audit_generator]

5. Publish the page. Visitors can now enter a URL and get an audit.

== How it works ==

- SEO score: rule-based analysis of title/meta/headings/schema/links,
  combined with Google PageSpeed's free SEO + Performance categories.
  No AI cost.
- AEO score (Answer Engine Optimization): structural detection of
  question-headings/FAQ schema plus one Claude API call that judges how
  "answer-box ready" the content is (featured snippets, voice search).
- GEO score (Generative Engine Optimization): distinct from AEO — this
  judges how likely a generative AI (ChatGPT, Perplexity, Google AI
  Overviews) would be to cite this page as a SOURCE when synthesizing an
  answer. Combines structural signals (freshness/date metadata, author
  schema, outbound citations) with one Claude API call judging
  authority/specificity vs. generic marketing copy.
- Content score: locally-computed Flesch Reading Ease + word count
  (no API cost) plus one Claude API call for qualitative content-gap
  commentary.
- Design score: PageSpeed's accessibility/best-practices categories plus
  structural HTML checks (heading hierarchy, viewport, alt text) plus one
  Claude API call for a structural (not visual) critique. No screenshots
  are taken — this plugin does not do visual/aesthetic judgment.

Overall score = weighted average: SEO 25%, AEO 20%, GEO 20%, Content 20%,
Design 15%. Weights are constants in
`includes/class-wag-audit-engine.php` if you want to adjust them.

All PageSpeed + Claude API calls run CONCURRENTLY (via curl_multi), not
sequentially — this matters for hosting timeout limits, see "Performance"
below.

== Cost control ==

Two settings keep API spend predictable:
- **Daily Audit Limit**: caps total audits per day, site-wide.
- **Result Cache (hours)**: repeat audits of the same URL within this
  window are served from cache instead of re-calling any APIs.

Each full audit makes 1 PageSpeed call (free) and 4 Claude API calls
(AEO, GEO, Content, Design). Cost depends on your model choice and page
length — with Claude Sonnet 5, expect roughly $0.03–0.05 per audit.

== Performance ==

The PageSpeed call and the 4 Claude calls all fire concurrently (via
curl_multi) rather than one after another. Running them sequentially can
take 2+ minutes combined, which exceeds most hosts' PHP execution time
limit and causes audits to hang or return a blank result. If your host
has curl_multi disabled (rare), the plugin falls back to sequential
calls automatically, and you may need to raise your host's
`max_execution_time` to 90+ seconds.

== Known limitations (by design, for cost/simplicity) ==

- No headless browser rendering — pages that are heavily JavaScript-rendered
  (e.g. a bare `<div id="root">` React shell with no server-rendered content)
  will show inaccurate content/word-count results, since this plugin reads
  the raw HTML response only.
- Design analysis is structural (HTML-based), not a visual/screenshot
  critique.
- Readability/word counts are computed on visible body text after
  stripping `<script>`, `<style>`, `<nav>`, and `<footer>` — but this is a
  heuristic, not a perfect "main content" extractor.

== Changelog ==

= 1.0.0 =
Initial release.

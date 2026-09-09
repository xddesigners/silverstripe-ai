# silverstripe-ai

Core AI platform integration for SilverStripe, built on [Symfony AI](https://symfony.com/doc/current/ai.html).
Provides a unified `AIClient` service and a `/ai/generate` endpoint that works with OpenAI, Anthropic,
Google (Gemini & Vertex AI), Azure OpenAI, Mistral, Ollama, OpenRouter, and any OpenAI-compatible endpoint.

## Requirements

- SilverStripe Framework `^6`
- PHP `^8.1`

## Installation

```bash
composer require xddesigners/silverstripe-ai
```

## Configuration

Add the following to your `.env` file:

```env
AI_PLATFORM_TYPE="openai"   # see the table below
AI_MODEL="gpt-4o-mini"
AI_API_KEY="sk-xxx"
```

### Supported platforms and models

| Platform type | Example models | Extra environment variables |
|---|---|---|
| `openai` | `gpt-4o`, `gpt-4o-mini`, `gpt-3.5-turbo` | — |
| `anthropic` (`claude`) | `claude-sonnet-4-6`, `claude-haiku-4-5` | — |
| `gemini` (`google`) | `gemini-2.0-flash`, `gemini-1.5-pro` | — |
| `azure` | your deployment name | `AI_PLATFORM_BASE_URL` (resource endpoint), `AI_AZURE_DEPLOYMENT`, `AI_AZURE_API_VERSION` (default `2024-10-21`) |
| `vertex` | `gemini-1.5-pro` | `AI_VERTEX_LOCATION`, `AI_VERTEX_PROJECT` (key optional; ADC supported) |
| `mistral` | `mistral-large-latest`, `mistral-small-latest` | — (native bridge; `AI_PLATFORM_BASE_URL` only used for the generic fallback) |
| `ollama` | any locally pulled model | `AI_PLATFORM_BASE_URL` (optional; defaults to `http://localhost:11434`) |
| `openrouter` | any OpenRouter slug, e.g. `anthropic/claude-sonnet-5` | `AI_PLATFORM_BASE_URL` (optional; set to route in-region — see below) |
| `generic` | any model on the endpoint | `AI_PLATFORM_BASE_URL` (required) — any OpenAI-compatible endpoint |

`mistral` uses the native Symfony Mistral bridge (bundled), falling back to Mistral's OpenAI-compatible EU
endpoint if that package is removed. `ollama` and `generic` reach their providers through the OpenAI-compatible
**generic** bridge, so no extra package is needed.

### EU data residency

Keep inference inside the EU by pointing an OpenAI-compatible platform at an in-region endpoint via
`AI_PLATFORM_BASE_URL`:

```env
# OpenRouter EU entry point (Business plan). Same key and model slugs; requests are processed in the EU.
AI_PLATFORM_TYPE="openrouter"
AI_PLATFORM_BASE_URL="https://eu.openrouter.ai/api"
AI_MODEL="anthropic/claude-sonnet-5"
AI_API_KEY="sk-or-xxx"
```

Other EU-friendly options: `mistral` (Mistral’s EU platform, the default endpoint), `azure` in an EU region,
or `ollama`/`generic` against a model you host in the EU — data then never leaves your infrastructure.

### Optional YAML configuration

You can override `AIClient` defaults in YAML:

```yaml
XD\SilverstripeAI\Services\AIClient:
  max_text_length: 5000
  max_instructions_length: 1000
  default_instructions: 'You are a helpful assistant and SEO expert.'
  # Cost estimates are best-effort. Add or override per-model rates (USD per 1,000,000 tokens);
  # keys may be a bare model name or an OpenRouter-style "vendor/model" slug.
  model_pricing:
    'anthropic/claude-sonnet-5':
      input: 3.00
      output: 15.00
```

## Usage

### In PHP

Inject or instantiate `AIClient` and call one of its two methods:

```php
use XD\SilverstripeAI\Services\AIClient;

$client = AIClient::create();

// Generate a plain text response
$result = $client->generateText('Write a short intro for our homepage.');

// Generate a keyed set of fields from context data
$result = $client->generateFields(
    context: ['Title' => 'My Page', 'Content' => 'Existing body text…'],
    fields: ['Title', 'Content', 'MetaDescription'],
    instructions: 'Improve the content for SEO.'
);
// Returns: ['Title' => '…', 'Content' => '…', 'MetaDescription' => '…']
```

### Via the HTTP endpoint

The module registers a `/ai/generate` route that accepts `POST` requests.

**Text mode**

```
POST /ai/generate
mode=text&text=Write+a+short+intro&instructions=Keep+it+friendly
```

Response:
```json
{ "result": "…" }
```

**Fields mode**

```
POST /ai/generate
mode=fields
&context[Title]=My+Page
&context[Content]=Existing+body+text
&fields[]=Title
&fields[]=Content
&fields[]=MetaDescription
&instructions=Improve+for+SEO
```

Response:
```json
{ "fields": { "Title": "…", "Content": "…", "MetaDescription": "…" } }
```

**Error responses**

| Status | Meaning |
|---|---|
| `400` | Missing or invalid input |
| `429` | Rate limit exceeded (includes `retry_after_seconds`) |
| `500` | AI generation failed |

## Usage logging & the AI Usage admin

Every request is logged to `AIRequestLog` (platform, model, mode, token counts and estimated cost) and can be browsed under the **AI Usage** CMS section.

> **Token usage is captured for every supported provider.** Native bridges (OpenAI, Anthropic, Gemini, Vertex AI, Mistral) report it through Symfony AI's `token_usage` metadata; for OpenAI-compatible endpoints that don't surface that metadata (OpenRouter, Ollama, the generic bridge) the counts are read from the response's `usage` block as a fallback. **Cost** is only estimated when the model is listed in `model_pricing` (see below) — otherwise the request is logged with token counts but no cost.

**Who can see it**

- **Administrators** always have access.
- Grant other groups access by ticking **“Access to 'AI Usage' section”** under *Security → Groups → Permissions*. Unlike most CMS sections, holding *“Access to all CMS sections”* does **not** reveal it.

**Hiding it from clients**

Set this in `.env` to hide the section entirely (menu + access), for everyone including admins:

```env
AI_USAGE_ADMIN_DISABLED=1
```

## Privacy & data processing

This module sends the text you pass to `AIClient` — page content, field values, prompts — to the third-party
AI provider you configure. Treat that as a data-processing step:

- **Personal data / GDPR:** if that content can contain personal data, the AI provider acts as a processor.
  Choose the provider and region accordingly, record it in your processing register, and cover it in your
  privacy policy / DPA. For EU data residency, route through an in-region endpoint (see *EU data residency*
  above) — e.g. OpenRouter's `eu.openrouter.ai`, Mistral's EU platform, Azure in an EU region, or a
  self-hosted model.
- **What is logged:** `AIRequestLog` stores only metadata (platform, model, token counts, estimated cost and
  the requesting CMS member) — **never** the prompt or the response. Enabling the AI Usage admin does not
  create a store of processed content.
- **Endpoint trust:** your `AI_API_KEY` is sent as a bearer token to `AI_PLATFORM_BASE_URL` (for the
  `openrouter` / `generic` / `mistral` / `ollama` types). Point it only at endpoints you trust.
- **Access & hardening:** `/ai/generate` requires a logged-in CMS user (`CMS_ACCESS`), is POST-only,
  CSRF-protected and per-member rate limited. Do not run the site in dev mode in production — dev mode returns
  raw error detail to the client.

## Extending the controller

By default the endpoint is **POST-only** and `AIController::assertAccess()` requires a logged-in member (returning `Security::permissionFailure()` otherwise). Subclass the controller to add your own permission logic — return an `HTTPResponse` to deny, or `null` to allow:

```php
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use XD\SilverstripeAI\Controllers\AIController;

class MyAIController extends AIController
{
    protected function assertAccess(): ?HTTPResponse
    {
        if (!Permission::check('MY_AI_PERMISSION')) {
            return Security::permissionFailure($this);
        }
        return null;
    }
}
```

Then update the route in YAML:

```yaml
SilverStripe\Control\Director:
  rules:
    'ai//$Action': 'MyAIController'
```

## Suggested modules

- **[xddesigners/silverstripe-ai-assistant](https://github.com/xddesigners/silverstripe-ai-assistant)** — adds a ready-to-use AI Assistant tab to CMS edit forms, letting editors generate and preview AI-written content for any configured fields.

## License

BSD-3-Clause © [XD Designers](https://xd.nl)

# silverstripe-ai

Core AI platform integration for SilverStripe, built on [Symfony AI](https://symfony.com/doc/current/ai.html).
Provides a unified `AIClient` service and a `/ai/generate` endpoint that works with OpenAI, Anthropic, Azure OpenAI, Google Vertex AI, and OpenRouter.

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
AI_PLATFORM_TYPE="openai"   # openai | anthropic | azure | vertex | openrouter
AI_MODEL="gpt-4o-mini"
AI_API_KEY="sk-xxx"
```

### Supported platforms and models

| Platform type | Example models |
|---|---|
| `openai` | `gpt-4o`, `gpt-4o-mini`, `gpt-3.5-turbo` |
| `anthropic` | `claude-opus-4-6`, `claude-sonnet-4-6`, `claude-haiku-4-5` |
| `azure` | `gpt-4o`, `gpt-4o-mini` |
| `vertex` | `gemini-1.5-pro` |
| `openrouter` | any model available via OpenRouter |

### Optional YAML configuration

You can override `AIClient` defaults in YAML:

```yaml
XD\SilverstripeAI\Services\AIClient:
  max_text_length: 5000
  max_instructions_length: 1000
  default_instructions: 'You are a helpful assistant and SEO expert.'
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

**Who can see it**

- **Administrators** always have access.
- Grant other groups access by ticking **“Access to 'AI Usage' section”** under *Security → Groups → Permissions*. Unlike most CMS sections, holding *“Access to all CMS sections”* does **not** reveal it.

**Hiding it from clients**

Set this in `.env` to hide the section entirely (menu + access), for everyone including admins:

```env
AI_USAGE_ADMIN_DISABLED=1
```

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

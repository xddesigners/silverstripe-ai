<?php

namespace XD\SilverstripeAI\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Security;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use XD\SilverstripeAI\Services\AIClient;

class AIController extends Controller
{
    private static array $allowed_actions = ['generate'];

    public function generate(HTTPRequest $request): HTTPResponse
    {
        $this->assertAccess();

        if ($response = $this->handleBypass($request)) {
            return $response;
        }

        try {
            $client = AIClient::create();
            $mode   = $request->requestVar('mode') ?? 'text';

            if ($mode === 'fields') {
                $context = $request->requestVar('context');
                $fields  = $request->requestVar('fields');

                if (!is_array($context) || !is_array($fields)) {
                    throw new \InvalidArgumentException('Fields mode requires context[] and fields[]');
                }

                $result = $client->generateFields(
                    $context,
                    $fields,
                    (string) $request->requestVar('instructions')
                );

                return $this->json(['fields' => $result]);
            }

            $text = $request->requestVar('text');

            if (!$text) {
                throw new \InvalidArgumentException('No input text provided');
            }

            $result = $client->generateText(
                $text,
                (string) $request->requestVar('instructions')
            );

            return $this->json(['result' => $result]);

        } catch (RateLimitExceededException $e) {
            return $this->json([
                'error'                => 'Rate limit exceeded',
                'retry_after_seconds'  => $e->getRetryAfter(),
            ], 429);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'AI generation failed: ' . $e->getMessage()], 500);
        }
    }

    protected function assertAccess(): void
    {
        Security::permissionFailure($this);
    }

    protected function handleBypass(HTTPRequest $request): ?HTTPResponse
    {
        if ($request->getVar('bypass')) {
            return $this->json(['result' => 'AI generation bypassed. Mock response.']);
        }
        return null;
    }

    protected function json(array $data, int $status = 200): HTTPResponse
    {
        $response = $this->getResponse();
        $response->addHeader('Content-Type', 'application/json');
        $response->setStatusCode($status);
        $response->setBody(json_encode($data));
        return $response;
    }
}

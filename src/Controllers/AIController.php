<?php

namespace XD\SilverstripeAI\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Security\Permission;
use SilverStripe\ORM\FieldType\DBDatetime;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use XD\SilverstripeAI\Services\AIClient;
use XD\SilverstripeAI\Models\AIRequestLog;

class AIController extends Controller
{
    private static array $allowed_actions = ['generate'];

    /**
     * Permission code required to use this paid endpoint. 'CMS_ACCESS' means any
     * CMS section access (or admin) — not merely a logged-in member. Set to a
     * specific code (e.g. a custom permission) to tighten further.
     */
    private static string $required_permission = 'CMS_ACCESS';

    /**
     * Per-member throttle: at most $rate_limit requests per $rate_limit_window
     * seconds. Set either value to 0 to disable throttling.
     */
    private static int $rate_limit = 30;
    private static int $rate_limit_window = 60;

    public function generate(HTTPRequest $request): HTTPResponse
    {
        if ($denied = $this->assertAccess()) {
            return $denied;
        }

        if (!$request->isPOST()) {
            return $this->json(['error' => _t(self::class . '.METHOD_NOT_ALLOWED', 'Method not allowed')], 405);
        }

        // CSRF: the CMS client posts the form's SecurityID; reject a missing/incorrect token.
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->json([
                'error' => _t(self::class . '.CSRF_FAILURE', 'Security token mismatch — please reload the page and try again.'),
            ], 400);
        }

        if ($response = $this->handleBypass($request)) {
            return $response;
        }

        if ($limited = $this->checkRateLimit()) {
            return $limited;
        }

        try {
            $client = AIClient::create();
            $mode   = $request->requestVar('mode') ?? 'text';

            if ($mode === 'fields') {
                $context = $request->requestVar('context');
                $fields  = $request->requestVar('fields');

                if (!is_array($context) || !is_array($fields)) {
                    throw new \InvalidArgumentException(
                        _t(self::class . '.FIELDS_MODE_REQUIRES', 'Fields mode requires context[] and fields[]')
                    );
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
                throw new \InvalidArgumentException(
                    _t(self::class . '.NO_INPUT', 'No input text provided')
                );
            }

            $result = $client->generateText(
                $text,
                (string) $request->requestVar('instructions')
            );

            return $this->json(['result' => $result]);

        } catch (RateLimitExceededException $e) {
            return $this->json([
                'error'                => _t(self::class . '.RATE_LIMIT', 'Rate limit exceeded'),
                'retry_after_seconds'  => $e->getRetryAfter(),
            ], 429);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            // Log full detail server-side; only expose the raw message to the client in dev.
            try {
                Injector::inst()->get(LoggerInterface::class)->error(
                    'AI generation failed: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            } catch (\Throwable) {
                // logging is best-effort
            }

            $message = Director::isDev()
                ? _t(self::class . '.GENERATION_FAILED', 'AI generation failed: {error}', ['error' => $e->getMessage()])
                : _t(self::class . '.GENERATION_FAILED_GENERIC', 'AI generation failed. Please try again later.');

            return $this->json(['error' => $message], 500);
        }
    }

    protected function assertAccess(): ?HTTPResponse
    {
        // Restrict this paid endpoint to CMS users (admin or any CMS section),
        // not merely any authenticated member. permissionFailure() only builds a
        // response — it does not halt execution — so we return it to the caller.
        $member   = Security::getCurrentUser();
        $required = $this->config()->get('required_permission');

        if (!$member || ($required && !Permission::checkMember($member, $required))) {
            return Security::permissionFailure($this);
        }
        return null;
    }

    /**
     * Basic per-member abuse throttle for this paid endpoint, based on recent
     * AIRequestLog entries. Returns a 429 response when the limit is exceeded.
     */
    protected function checkRateLimit(): ?HTTPResponse
    {
        $limit  = (int) $this->config()->get('rate_limit');
        $window = (int) $this->config()->get('rate_limit_window');

        if ($limit <= 0 || $window <= 0) {
            return null; // throttling disabled
        }

        $member = Security::getCurrentUser();
        if (!$member) {
            return null; // access is already enforced by assertAccess()
        }

        $since = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - $window);

        $recent = AIRequestLog::get()
            ->filter([
                'MemberID' => $member->ID,
                'Created:GreaterThanOrEqual' => $since,
            ])
            ->count();

        if ($recent >= $limit) {
            return $this->json([
                'error' => _t(self::class . '.RATE_LIMITED', 'Too many AI requests. Please wait a moment and try again.'),
                'retry_after_seconds' => $window,
            ], 429);
        }

        return null;
    }

    protected function handleBypass(HTTPRequest $request): ?HTTPResponse
    {
        if (Director::isDev() && $request->getVar('bypass')) {
            return $this->json(['result' => _t(self::class . '.BYPASS', 'AI generation bypassed. Mock response.')]);
        }
        return null;
    }

    protected function json(array $data, int $status = 200): HTTPResponse
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            $body   = '{"error":"Failed to encode response"}';
            $status = 500;
        }

        $response = $this->getResponse();
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setStatusCode($status);
        $response->setBody($body);
        return $response;
    }
}

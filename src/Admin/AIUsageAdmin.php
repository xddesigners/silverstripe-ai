<?php

namespace XD\SilverstripeAI\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use XD\SilverstripeAI\Models\AIRequestLog;
use XD\SilverstripeAI\Services\AIClient;

class AIUsageAdmin extends ModelAdmin
{
    private static string $url_segment = 'ai-usage';

    private static string $menu_title = 'AI Usage';

    private static string $menu_icon_class = 'font-icon-chart-line';

    private static array $managed_models = [
        AIRequestLog::class,
    ];

    /**
     * Dedicated permission for this section. Assign "Access to 'AI Usage' section"
     * to a group under Security > Groups to grant non-admins access. Unlike the CMS
     * default, holding "Access to all CMS sections" does NOT reveal this section
     * (see canView()).
     */
    private static string $required_permission_codes = 'CMS_ACCESS_AIUsageAdmin';

    public function canView($member = null)
    {
        if (!$member && $member !== false) {
            $member = Security::getCurrentUser();
        }

        if (!$member) {
            return false;
        }

        // Hide the whole section when AI is not configured (no AI_API_KEY in .env),
        // matching AIClient::isEnabled(). Applies to everyone, including admins.
        if (!AIClient::isEnabled()) {
            return false;
        }

        // .env off-switch: hide the whole section (menu + access) on client sites:
        //   AI_USAGE_ADMIN_DISABLED=1
        if (Environment::getEnv('AI_USAGE_ADMIN_DISABLED')) {
            return false;
        }

        // Full administrators always have access.
        if (Permission::checkMember($member, 'ADMIN')) {
            return true;
        }

        // Everyone else needs the dedicated section permission (assignable per group).
        // Note: "Access to all CMS sections" deliberately does NOT grant access here.
        return (bool) Permission::checkMember($member, 'CMS_ACCESS_AIUsageAdmin');
    }
}

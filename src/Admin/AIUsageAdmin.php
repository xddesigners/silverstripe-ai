<?php

namespace XD\SilverstripeAI\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldViewButton;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\Queries\SQLSelect;
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

    /**
     * AIRequestLog rows are written by the app, never created by hand — hide the CSV import form.
     */
    private static $showImportForm = false;

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

    /**
     * AIRequestLog rows are read-only audit logs (canEdit/canCreate/canDelete are all false), so the row
     * action should open a read-only detail view rather than an "Edit" form. Swap the edit button for a
     * view button (labelled "View" / "Bekijken") in the actions column.
     */
    protected function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();
        $config->removeComponentsByType(GridFieldEditButton::class);
        $config->addComponent(GridFieldViewButton::create());

        return $config;
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        // Prepend a usage summary (grand total + per-month breakdown) above the log grid.
        if ($this->getModelClass() === AIRequestLog::class) {
            $gridName = $this->sanitiseClassName($this->getModelClass());
            if ($form->Fields()->dataFieldByName($gridName)) {
                $form->Fields()->insertBefore(
                    $gridName,
                    LiteralField::create('AIUsageSummary', $this->renderUsageSummary())
                );
            }
        }

        return $form;
    }

    /**
     * Build an HTML summary of AI usage: a grand total plus a per-calendar-month breakdown (requests,
     * prompt/completion/total tokens and estimated cost). Rows are aggregated in PHP so the query stays
     * database-agnostic (no engine-specific date formatting in SQL); only the needed columns are selected,
     * without hydrating DataObjects.
     */
    private function renderUsageSummary(): string
    {
        $query = SQLSelect::create(
            [
                'Created'          => '"Created"',
                'PromptTokens'     => '"PromptTokens"',
                'CompletionTokens' => '"CompletionTokens"',
                'TotalTokens'      => '"TotalTokens"',
                'EstimatedCost'    => '"EstimatedCost"',
            ],
            '"AIRequestLog"'
        );

        $grand  = ['count' => 0, 'prompt' => 0, 'completion' => 0, 'total' => 0, 'cost' => 0.0];
        $months = [];

        foreach ($query->execute() as $row) {
            $month = substr((string) $row['Created'], 0, 7); // YYYY-MM
            if (!isset($months[$month])) {
                $months[$month] = ['count' => 0, 'prompt' => 0, 'completion' => 0, 'total' => 0, 'cost' => 0.0];
            }

            $prompt     = (int) $row['PromptTokens'];
            $completion = (int) $row['CompletionTokens'];
            $total      = (int) $row['TotalTokens'];
            $cost       = (float) $row['EstimatedCost'];

            $grand['count']++;
            $grand['prompt']     += $prompt;
            $grand['completion'] += $completion;
            $grand['total']      += $total;
            $grand['cost']       += $cost;

            $months[$month]['count']++;
            $months[$month]['prompt']     += $prompt;
            $months[$month]['completion'] += $completion;
            $months[$month]['total']      += $total;
            $months[$month]['cost']       += $cost;
        }

        $tTotal       = _t(self::class . '.USAGE_TOTAL', 'Total AI usage');
        $tPerMonth    = _t(self::class . '.USAGE_PER_MONTH', 'Usage per month');
        $tMonth       = _t(self::class . '.USAGE_MONTH', 'Month');
        $tRequests    = _t(self::class . '.USAGE_REQUESTS', 'Requests');
        $tPrompt      = _t(AIRequestLog::class . '.db_PromptTokens', 'Prompt tokens');
        $tCompletion  = _t(AIRequestLog::class . '.db_CompletionTokens', 'Completion tokens');
        $tTotalTokens = _t(AIRequestLog::class . '.db_TotalTokens', 'Total tokens');
        $tCost        = _t(AIRequestLog::class . '.COST_SHORT', 'Est. cost');

        if ($grand['count'] === 0) {
            return '<div class="ai-usage-summary" style="margin:1rem 0;"><p>'
                . Convert::raw2xml(_t(self::class . '.USAGE_NONE', 'No AI usage recorded yet.'))
                . '</p></div>';
        }

        krsort($months); // newest month first

        $money = static function (float $v): string {
            if ($v <= 0) {
                return '$0.00';
            }
            return $v < 0.0001 ? '&lt; $0.0001' : '$' . number_format($v, 4);
        };

        $thL = 'style="text-align:left;padding:6px 10px;border-bottom:2px solid #ccc;"';
        $th  = 'style="text-align:right;padding:6px 10px;border-bottom:2px solid #ccc;white-space:nowrap;"';
        $tdL = 'style="text-align:left;padding:6px 10px;border-bottom:1px solid #eee;"';
        $td  = 'style="text-align:right;padding:6px 10px;border-bottom:1px solid #eee;white-space:nowrap;"';
        $ftL = 'style="text-align:left;padding:8px 10px;border-top:2px solid #ccc;font-weight:bold;"';
        $ft  = 'style="text-align:right;padding:8px 10px;border-top:2px solid #ccc;font-weight:bold;white-space:nowrap;"';

        $bodyRows = '';
        foreach ($months as $month => $m) {
            $bodyRows .= '<tr>'
                . '<td ' . $tdL . '>' . Convert::raw2xml($month) . '</td>'
                . '<td ' . $td . '>' . number_format($m['count']) . '</td>'
                . '<td ' . $td . '>' . number_format($m['prompt']) . '</td>'
                . '<td ' . $td . '>' . number_format($m['completion']) . '</td>'
                . '<td ' . $td . '>' . number_format($m['total']) . '</td>'
                . '<td ' . $td . '>' . $money($m['cost']) . '</td>'
                . '</tr>';
        }

        return '<div class="ai-usage-summary" style="margin:1rem 0;">'
            . '<p style="font-size:1rem;margin:0 0 .75rem;"><strong>' . Convert::raw2xml($tTotal) . ':</strong> '
            . number_format($grand['count']) . ' ' . Convert::raw2xml($tRequests) . ' &middot; '
            . number_format($grand['total']) . ' ' . Convert::raw2xml($tTotalTokens) . ' &middot; '
            . $money($grand['cost']) . '</p>'
            . '<h4 style="margin:0 0 .5rem;">' . Convert::raw2xml($tPerMonth) . '</h4>'
            . '<table style="width:100%;border-collapse:collapse;margin:0 0 1.5rem;font-size:.9rem;">'
            . '<thead><tr>'
            . '<th ' . $thL . '>' . Convert::raw2xml($tMonth) . '</th>'
            . '<th ' . $th . '>' . Convert::raw2xml($tRequests) . '</th>'
            . '<th ' . $th . '>' . Convert::raw2xml($tPrompt) . '</th>'
            . '<th ' . $th . '>' . Convert::raw2xml($tCompletion) . '</th>'
            . '<th ' . $th . '>' . Convert::raw2xml($tTotalTokens) . '</th>'
            . '<th ' . $th . '>' . Convert::raw2xml($tCost) . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $bodyRows . '</tbody>'
            . '<tfoot><tr>'
            . '<td ' . $ftL . '>' . Convert::raw2xml($tTotal) . '</td>'
            . '<td ' . $ft . '>' . number_format($grand['count']) . '</td>'
            . '<td ' . $ft . '>' . number_format($grand['prompt']) . '</td>'
            . '<td ' . $ft . '>' . number_format($grand['completion']) . '</td>'
            . '<td ' . $ft . '>' . number_format($grand['total']) . '</td>'
            . '<td ' . $ft . '>' . $money($grand['cost']) . '</td>'
            . '</tr></tfoot>'
            . '</table></div>';
    }
}

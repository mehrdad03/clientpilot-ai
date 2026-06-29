<?php

namespace App\Services\Clients;

use Illuminate\Support\Str;

class MarketplaceSafetyPolicyService
{
    /**
     * @return array{risk_level: string, flags: array<int, array{key: string, label: string, reason: string}>}
     */
    public function detect(string $latestClientMessage, ?object $summary = null): array
    {
        $text = $this->normalize($latestClientMessage."\n".$this->summaryRiskText($summary));
        $flags = [];

        $this->addFlagWhen($flags, $this->mentionsOutsideCommunication($text), 'outside_platform_communication', 'Off-platform communication', 'Client is pushing communication outside the target platform before a safe contract path.');
        $this->addFlagWhen($flags, $this->mentionsOutsidePayment($text), 'outside_platform_payment', 'Off-platform payment', 'Client mentions payment outside the target platform or avoiding platform fees.');
        $this->addFlagWhen($flags, $this->mentionsUnfundedStart($text), 'unfunded_start', 'Starting before funded milestone', 'Client is pushing work to start before a funded milestone is clear.');
        $this->addFlagWhen($flags, $this->mentionsFreeSample($text), 'unpaid_sample', 'Unpaid/free sample', 'Client asks for free or unpaid work before contract clarity.');
        $this->addFlagWhen($flags, $this->mentionsSuspiciousFiles($text), 'suspicious_files', 'Suspicious files', 'Client mentions risky file types or unsafe downloads.');
        $this->addFlagWhen($flags, $this->mentionsSensitiveCredentials($text), 'sensitive_credentials', 'Sensitive credentials before contract', 'Client asks for sensitive access before the contract path is safe.');
        $this->addFlagWhen($flags, $this->mentionsUnrealisticDeadline($text), 'unrealistic_deadline', 'Unrealistic deadline', 'Client pushes an unrealistic delivery timeline.');
        $this->addFlagWhen($flags, $this->mentionsHugeScopeLowBudget($text), 'huge_scope_low_budget', 'Huge scope with low budget', 'Client appears to want a large scope with a low budget.');
        $this->addFlagWhen($flags, $this->mentionsFastUnclearStart($text), 'unclear_fast_start', 'Unclear fast start', 'Client is pushing a fast start without clear scope or funded milestone.');

        return [
            'risk_level' => $flags === [] ? 'low' : 'high',
            'flags' => $flags,
        ];
    }

    public function containsUnsafeTerms(string $text): bool
    {
        $text = $this->normalize($text);

        return $this->mentionsOutsideCommunication($text)
            || $this->mentionsOutsidePayment($text)
            || $this->mentionsUnfundedStart($text)
            || $this->mentionsFreeSample($text)
            || $this->mentionsSuspiciousFiles($text)
            || $this->mentionsSensitiveCredentials($text);
    }

    public function seemsReadyToClose(string $latestClientMessage): bool
    {
        $text = $this->normalize($latestClientMessage);

        return $this->containsAny($text, [
            'ready to start',
            'ready to proceed',
            'lets start',
            "let's start",
            'go ahead',
            'send the contract',
            'send contract',
            'create milestone',
            'start the contract',
            'hire you',
            'i will hire',
            'sounds good',
            'looks good',
            'approved',
            'deal',
        ]);
    }

    private function mentionsOutsideCommunication(string $text): bool
    {
        return $this->containsAny($text, [
            'whatsapp',
            'telegram',
            'phone',
            'phone number',
            'call me',
            'text me',
            'email me',
            'gmail',
            'skype',
            'outside platform',
            'off platform',
            'direct chat',
        ]) || preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text) === 1;
    }

    private function mentionsOutsidePayment(string $text): bool
    {
        return $this->containsAny($text, [
            'paypal',
            'payoneer',
            'wise',
            'crypto',
            'bitcoin',
            'usdt',
            'bank transfer',
            'wire transfer',
            'pay outside',
            'outside payment',
            'avoid platform fee',
            'avoid fees',
            'direct payment',
        ]);
    }

    private function mentionsUnfundedStart(string $text): bool
    {
        return $this->containsAny($text, [
            'start before milestone',
            'start without milestone',
            'start before funding',
            'fund later',
            'pay later',
            'milestone later',
            'no milestone yet',
            'without escrow',
            'before escrow',
            'unfunded',
        ]);
    }

    private function mentionsFreeSample(string $text): bool
    {
        return $this->containsAny($text, [
            'free sample',
            'unpaid sample',
            'free test',
            'unpaid test',
            'trial task',
            'do it free',
            'prove yourself',
            'small sample for free',
        ]);
    }

    private function mentionsSuspiciousFiles(string $text): bool
    {
        return $this->containsAny($text, [
            '.exe',
            '.scr',
            '.bat',
            '.cmd',
            '.vbs',
            '.msi',
            'enable macros',
            'macro file',
            'download this file',
            'unknown attachment',
            'cracked',
            'keygen',
        ]);
    }

    private function mentionsSensitiveCredentials(string $text): bool
    {
        return $this->containsAny($text, [
            'password',
            'credentials',
            'login details',
            'admin access',
            'root access',
            'ssh key',
            'private key',
            'api key',
            'database password',
            'cpanel',
            'hosting login',
        ]);
    }

    private function mentionsUnrealisticDeadline(string $text): bool
    {
        return $this->containsAny($text, [
            'in one hour',
            'in 1 hour',
            'few hours',
            'asap',
            'immediately',
            'tonight',
            'today',
            'by tomorrow',
            '24 hours',
            'urgent start',
        ]);
    }

    private function mentionsHugeScopeLowBudget(string $text): bool
    {
        $hugeScope = $this->containsAny($text, [
            'full app',
            'complete app',
            'complete platform',
            'marketplace',
            'crm',
            'saas',
            'automation system',
            'dashboard',
            'everything',
            'all features',
        ]);

        $lowBudget = preg_match('/\$(?:[1-9][0-9]?|1[0-9]{2}|200)\b/', $text) === 1
            || $this->containsAny($text, ['low budget', 'cheap', 'small budget', 'only 50', 'only 100', 'only 200']);

        return $hugeScope && $lowBudget;
    }

    private function mentionsFastUnclearStart(string $text): bool
    {
        return $this->containsAny($text, [
            'start now',
            'start immediately',
            'begin now',
            'begin immediately',
            'details later',
            'scope later',
            'we can discuss later',
            'no need for details',
            'just start',
        ]);
    }

    /**
     * @param  array<int, array{key: string, label: string, reason: string}>  $flags
     */
    private function addFlagWhen(array &$flags, bool $condition, string $key, string $label, string $reason): void
    {
        if (! $condition) {
            return;
        }

        $flags[] = [
            'key' => $key,
            'label' => $label,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function summaryRiskText(?object $summary): string
    {
        if ($summary === null) {
            return '';
        }

        return implode("\n", [
            (string) ($summary->risk_notes ?? ''),
            (string) ($summary->what_mehrdad_promised ?? ''),
            (string) ($summary->pricing_discussed ?? ''),
            (string) ($summary->deadline_discussed ?? ''),
            (string) ($summary->access_needed ?? ''),
        ]);
    }

    private function normalize(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->toString();
    }
}

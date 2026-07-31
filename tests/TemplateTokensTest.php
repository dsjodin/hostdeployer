<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/templates.php';

/**
 * Which tokens a template may use, and which it does.
 *
 * The renderer leaves an unknown {{TOKEN}} alone rather than blanking it, so
 * the literal travels all the way to the installer. Catching it at the point
 * the template is saved is the whole purpose of these functions, and the list
 * they check against has to be derived from what the generator actually sets
 * rather than kept alongside it.
 */
final class TemplateTokensTest extends TestCase
{
    public function testTheKnownNamesCoverBothSidesOfTheVmotionCondition(): void
    {
        $names = templateVariableNames();

        // Set only when the host has a vMotion address.
        self::assertContains('VMOTION_IP', $names);
        self::assertContains('VMOTION_NETMASK', $names);
        self::assertContains('VMOTION_VLANID', $names);
        // Set either way, which is what {{IF VMOTION_CONFIGURED}} tests.
        self::assertContains('VMOTION_CONFIGURED', $names);
        // Only the waiting template uses this one.
        self::assertContains('REGISTERED_TIME', $names);
        self::assertContains('ROOT_PASSWORD_HASH', $names);
        self::assertContains('BOOT_TOKEN', $names);
    }

    public function testTheKnownNamesAreDerivedFromTheGenerator(): void
    {
        // Not a hand-kept list: every name has to come back from one of the
        // builders, or the check would pass tokens nothing substitutes.
        $fromBuilders = array_merge(
            array_keys(kickstartVariables(['vmotion_ip' => '10.0.0.1'], [], '', '', 'standard')),
            array_keys(kickstartVariables([], [], '', '', 'vcf')),
            array_keys(waitingTemplateVariables([], []))
        );

        self::assertSame([], array_diff(templateVariableNames(), $fromBuilders));
    }

    // -----------------------------------------------------------------------
    // The check
    // -----------------------------------------------------------------------

    public function testTheRepositoryTemplatesAreClean(): void
    {
        foreach (glob(__DIR__ . '/../templates/*.cfg') ?: [] as $template) {
            self::assertSame(
                [],
                templateUnknownTokens((string)file_get_contents($template)),
                basename($template) . ' uses a token the generator does not supply'
            );
        }
    }

    public function testAnUnknownTokenIsReported(): void
    {
        // C6's example: a plausible-looking name that is not the real one.
        self::assertSame(
            ['SERVER_URI'],
            templateUnknownTokens('network --url={{SERVER_URI}}')
        );
    }

    public function testAKnownTokenIsNotReported(): void
    {
        self::assertSame([], templateUnknownTokens('network --ip={{ESXMGMT_IP}}'));
    }

    public function testAMisspelledConditionIsReported(): void
    {
        // processConditionals() evaluates an unknown name as falsy, so this
        // does not fail -- it quietly renders the wrong branch.
        self::assertSame(
            ['VMOTION_CONFIGURD'],
            templateUnknownTokens('{{IF VMOTION_CONFIGURD}}vmotion{{ENDIF}}')
        );
    }

    public function testControlDirectivesAreNotTokens(): void
    {
        self::assertSame(
            [],
            templateUnknownTokens('{{IF VMOTION_CONFIGURED}}a{{ELSE}}b{{ENDIF}}')
        );
    }

    public function testCommentsAreNotSearched(): void
    {
        // The templates carry a header documenting their own {{TOKEN}} syntax.
        self::assertSame([], templateUnknownTokens("# Every {{TOKEN}} is substituted\n"));
        self::assertSame([], templateUnknownTokens("    # indented {{TOKEN}}\n"));
        // But a comment does not disarm the rest of the line's neighbours.
        self::assertSame(['NOPE'], templateUnknownTokens("# {{TOKEN}}\nvalue={{NOPE}}\n"));
    }

    public function testEachUnknownTokenIsReportedOnce(): void
    {
        self::assertSame(
            ['NOPE'],
            templateUnknownTokens("a={{NOPE}}\nb={{NOPE}}\nc={{NOPE}}\n")
        );
    }

    public function testLowercaseAndMalformedTokensAreIgnored(): void
    {
        // The renderer substitutes [A-Za-z0-9_], but every name the generator
        // supplies is upper case; a lower-case one is shell or Python syntax
        // in a %firstboot block far more often than it is a typo'd token.
        self::assertSame([], templateUnknownTokens('${var} {{ }} {{lowercase}}'));
    }

    public function testEmptyContentHasNoTokens(): void
    {
        self::assertSame([], templateUnknownTokens(''));
    }

    // -----------------------------------------------------------------------
    // The values
    // -----------------------------------------------------------------------

    public function testVcfGetsNoVmotionValues(): void
    {
        $variables = kickstartVariables(['vmotion_ip' => '10.0.1.10'], [], '', '', 'vcf');

        self::assertFalse($variables['VMOTION_CONFIGURED']);
        self::assertArrayNotHasKey('VMOTION_IP', $variables);
    }

    public function testStandardWithoutAnAddressGetsNoVmotionValues(): void
    {
        $variables = kickstartVariables([], [], '', '', 'standard');

        self::assertFalse($variables['VMOTION_CONFIGURED']);
        self::assertArrayNotHasKey('VMOTION_IP', $variables);
    }

    public function testTheFqdnFallsBackToTheHostname(): void
    {
        $variables = kickstartVariables(['hostname' => 'esxi-01', 'fqdn' => ''], [], '', '', 'vcf');

        self::assertSame('esxi-01.local', $variables['FQDN']);
    }

    public function testTheServerUrlFallsBackToTheServerIp(): void
    {
        $variables = kickstartVariables([], ['webserver' => ['ip' => '10.0.0.5']], '', '', 'vcf');

        self::assertSame('http://10.0.0.5', $variables['SERVER_URL']);
    }

    public function testTheServerUrlLosesItsTrailingSlash(): void
    {
        // It is concatenated with paths that start with one.
        $variables = kickstartVariables([], ['webserver' => ['url' => 'http://deploy/']], '', '', 'vcf');

        self::assertSame('http://deploy', $variables['SERVER_URL']);
    }

    public function testTheRenderedKickstartCarriesNoLeftoverTokens(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../templates/kickstart_template_std.cfg');

        $rendered = renderTemplate($template, kickstartVariables(
            [
                'mac_address'        => '00:0c:29:91:cf:eb',
                'hostname'           => 'esxi-01',
                'fqdn'               => 'esxi-01.example.com',
                'management_ip'      => '10.0.0.10',
                'management_gateway' => '10.0.0.1',
                'vmotion_ip'         => '10.0.1.10',
                'vlans'              => ['management' => 100, 'vmotion' => 200],
            ],
            ['webserver' => ['ip' => '10.0.0.5'], 'network' => ['dns_servers' => ['10.0.0.2']]],
            '$6$hash',
            'token',
            'standard'
        ));

        // Comments survive rendering, and the header documents {{TOKEN}}.
        $body = (string)preg_replace('/^[ \t]*#.*$/m', '', $rendered);
        self::assertDoesNotMatchRegularExpression('/\{\{/', $body);
        self::assertStringContainsString('10.0.0.10', $rendered);
        self::assertStringContainsString('$6$hash', $rendered);
    }
}

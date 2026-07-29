<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * renderTemplate() produces the kickstart every host installs from, so a
 * conditional that leaks its false branch is a misconfigured ESXi host.
 */
final class TemplateTest extends TestCase
{
    public function testReplacesTokens(): void
    {
        self::assertSame(
            'ip=10.0.0.5 host=esxi-01',
            renderTemplate('ip={{IP}} host={{HOSTNAME}}', ['IP' => '10.0.0.5', 'HOSTNAME' => 'esxi-01'])
        );
    }

    public function testLeavesUnknownTokensAlone(): void
    {
        self::assertSame('{{MISSING}}', renderTemplate('{{MISSING}}', []));
    }

    /**
     * A replacement value must never be re-scanned for tokens; otherwise a
     * host field of "{{ROOT_PASSWORD_HASH}}" would interpolate the hash into
     * the kickstart.
     *
     * The str_replace() implementation this replaced failed exactly here: with
     * array arguments it applies each pair to the result of the previous one,
     * so any token appearing later in the map would expand inside a value.
     */
    public function testDoesNotReExpandReplacementValues(): void
    {
        self::assertSame(
            '{{SECRET}}',
            renderTemplate('{{NAME}}', ['NAME' => '{{SECRET}}', 'SECRET' => 'hunter2'])
        );
    }

    public function testDoesNotReExpandAValueUsingALaterToken(): void
    {
        self::assertSame(
            'hostname={{DATASTORE_NAME}} ds=datastore1',
            renderTemplate('hostname={{HOSTNAME}} ds={{DATASTORE_NAME}}', [
                'HOSTNAME'       => '{{DATASTORE_NAME}}',
                'DATASTORE_NAME' => 'datastore1',
            ])
        );
    }

    public function testKeepsTheBodyOfATrueConditional(): void
    {
        self::assertSame(
            'before vmk1 after',
            renderTemplate('before {{IF VMOTION}}vmk1{{ENDIF}} after', ['VMOTION' => true])
        );
    }

    public function testDropsTheBodyOfAFalseConditional(): void
    {
        foreach ([false, '', 0, null] as $falsy) {
            self::assertSame(
                'before  after',
                renderTemplate('before {{IF VMOTION}}vmk1{{ENDIF}} after', ['VMOTION' => $falsy])
            );
        }
    }

    public function testDropsTheBodyWhenTheVariableIsAbsent(): void
    {
        self::assertSame(
            'before  after',
            renderTemplate('before {{IF VMOTION}}vmk1{{ENDIF}} after', [])
        );
    }

    /**
     * IF/ELSE is expanded before plain IF. Handled the other way round, the
     * plain-IF pattern swallows the ELSE marker and emits both branches.
     */
    public function testElseBranchIsNotEmittedForATrueCondition(): void
    {
        self::assertSame(
            'yes',
            renderTemplate('{{IF X}}yes{{ELSE}}no{{ENDIF}}', ['X' => true])
        );
    }

    public function testElseBranchIsEmittedForAFalseCondition(): void
    {
        self::assertSame(
            'no',
            renderTemplate('{{IF X}}yes{{ELSE}}no{{ENDIF}}', ['X' => false])
        );
    }

    public function testConditionalsSpanMultipleLines(): void
    {
        $template = "a\n{{IF X}}\nb\n{{ENDIF}}\nc";

        self::assertSame("a\n\nb\n\nc", renderTemplate($template, ['X' => true]));
        self::assertSame("a\n\nc", renderTemplate($template, ['X' => false]));
    }

    public function testSectionSyntax(): void
    {
        self::assertSame('in', renderTemplate('{{#X}}in{{/X}}', ['X' => true]));
        self::assertSame('', renderTemplate('{{#X}}in{{/X}}', ['X' => false]));
    }

    /**
     * Booleans drive conditionals; substituting them as "1"/"" into the body
     * would put stray characters into the kickstart.
     */
    public function testBooleansAreNotSubstitutedAsText(): void
    {
        self::assertSame('x {{FLAG}} y', renderTemplate('x {{FLAG}} y', ['FLAG' => true]));
    }

    public function testRendersTheRepositoryStandardTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../templates/kickstart_template_std.cfg');
        self::assertIsString($template);

        $rendered = renderTemplate($template, [
            'ROOT_PASSWORD_HASH' => '$6$salt$hash',
            'ESXMGMT_IP'         => '10.0.0.5',
            'ESXMGMT_NETMASK'    => '255.255.255.0',
            'ESXMGMT_GATEWAY'    => '10.0.0.1',
            'ESXIMGMT_VLANID'    => 0,
            'DNS_SERVERS'        => '10.0.0.53',
            'NTP_SERVERS'        => '10.0.0.123',
            'HOSTNAME'           => 'esxi-01',
            'FQDN'               => 'esxi-01.example.com',
            'SERVER_URL'         => 'http://10.0.0.2',
            'MAC_ADDRESS'        => '00:0c:29:91:cf:eb',
            'VMOTION_CONFIGURED' => false,
        ]);

        // Comment lines are exempt: the template's own header documents the
        // {{TOKEN}} syntax and is not meant to be substituted.
        $body = preg_replace('/^\s*#.*$/m', '', $rendered);
        self::assertStringNotContainsString('{{', (string)$body, 'every token should be substituted');

        self::assertStringContainsString('rootpw --iscrypted $6$salt$hash', $rendered);
        self::assertStringNotContainsString('vmk1', $rendered, 'vMotion was not configured');
    }

    public function testStandardTemplateRendersVmotionWhenConfigured(): void
    {
        $template = file_get_contents(__DIR__ . '/../templates/kickstart_template_std.cfg');
        self::assertIsString($template);

        $rendered = renderTemplate($template, [
            'VMOTION_CONFIGURED' => true,
            'VMOTION_IP'         => '10.1.0.5',
            'VMOTION_NETMASK'    => '255.255.255.0',
            'VMOTION_VLANID'     => 20,
        ]);

        self::assertStringContainsString('--ipv4=10.1.0.5', $rendered);
        self::assertStringContainsString('--vlan-id=20', $rendered);
    }
}

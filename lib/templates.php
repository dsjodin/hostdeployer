<?php
/**
 * What a template may refer to, and what it does refer to.
 *
 * CI checked that every {{TOKEN}} in templates/*.cfg is one the generator
 * supplies -- the right check in the wrong place, because templates are edited
 * and uploaded through the admin UI and never go near CI. A template saved
 * with {{SERVER_URI}} in it was accepted without a word and reached the
 * installer with the literal still there, which is a failed install with
 * nothing on the console to explain it.
 *
 * So the check lives here, the UI warns with it on every save and upload, and
 * CI calls the same code rather than approximating it in shell.
 *
 * Nothing here touches the filesystem or the request. The variable maps are
 * built from values the caller has already gathered, which is what makes both
 * the maps and the check testable.
 *
 * This file is also where the template helpers currently buried in
 * www/templates.php are meant to end up (C10 in docs/CODE-REVIEW-2026-07.md).
 */

if (!function_exists('kickstartVariables')) {
    /**
     * The substitutions a kickstart template is rendered with.
     *
     * @param array<string, mixed> $host             Host record
     * @param array<string, mixed> $globalConfig     Global configuration
     * @param string               $rootPasswordHash Hashed ESXi root password
     * @param string               $bootToken        Token proving this host was told to boot
     * @param string               $deploymentType   'standard' or 'vcf'
     * @return array<string, mixed> Token name => value
     */
    function kickstartVariables(
        array $host,
        array $globalConfig,
        $rootPasswordHash,
        $bootToken,
        $deploymentType
    ) {
        $serverIp = $globalConfig['webserver']['ip'] ?? '';

        $variables = [
            'ROOT_PASSWORD_HASH' => $rootPasswordHash,
            'ESXMGMT_IP'         => $host['management_ip'] ?? '',
            'ESXMGMT_NETMASK'    => $host['management_netmask'] ?? '255.255.255.0',
            'ESXMGMT_GATEWAY'    => $host['management_gateway'] ?? '',
            'ESXIMGMT_VLANID'    => (int)($host['vlans']['management'] ?? 0),
            'DNS_SERVERS'        => implode(',', (array)($globalConfig['network']['dns_servers'] ?? [])),
            'NTP_SERVERS'        => implode(',', (array)($globalConfig['network']['ntp_servers'] ?? [])),
            'HOSTNAME'           => $host['hostname'] ?? '',
            'FQDN'               => ($host['fqdn'] ?? '') ?: (($host['hostname'] ?? 'esxi') . '.local'),
            'SERVER_IP'          => $serverIp,
            'SERVER_URL'         => rtrim((string)($globalConfig['webserver']['url'] ?? "http://$serverIp"), '/'),
            'MAC_ADDRESS'        => $host['mac_address'] ?? '',
            'DATASTORE_NAME'     => $host['datastore']['name'] ?? 'datastore1',
            // Carried into %firstboot so the progress beacon and the completion
            // callback can present it too. Those endpoints write to the
            // inventory, and until the token existed anything on the network
            // could call them: marking a host deployed stops its installation,
            // and the operator sees a host that hung rather than one that was
            // interfered with.
            'BOOT_TOKEN'         => $bootToken,
        ];

        // vMotion is only rendered when the host actually has an address for
        // it. VCF configures its own during bring-up.
        $vmotionIp = $host['vmotion_ip'] ?? '';
        if ($deploymentType === 'standard' && $vmotionIp !== '') {
            $variables['VMOTION_CONFIGURED'] = true;
            $variables['VMOTION_IP']         = $vmotionIp;
            $variables['VMOTION_NETMASK']    = $host['vmotion_netmask'] ?? '255.255.255.0';
            $variables['VMOTION_VLANID']     = (int)($host['vlans']['vmotion'] ?? 0);
        } else {
            $variables['VMOTION_CONFIGURED'] = false;
        }

        return $variables;
    }
}

if (!function_exists('waitingTemplateVariables')) {
    /**
     * The substitutions the waiting template is rendered with.
     *
     * A host awaiting approval gets this instead of a kickstart, so that the
     * installer idles rather than erroring out. It holds nothing worth
     * protecting and has its own, much smaller, set of tokens.
     *
     * @param array<string, mixed> $host         Host record
     * @param array<string, mixed> $globalConfig Global configuration
     * @return array<string, mixed> Token name => value
     */
    function waitingTemplateVariables(array $host, array $globalConfig) {
        return [
            'MAC_ADDRESS'     => $host['mac_address'] ?? '',
            'REGISTERED_TIME' => $host['registered_time'] ?? date('Y-m-d H:i:s'),
            'SERVER_IP'       => $globalConfig['webserver']['ip'] ?? '',
        ];
    }
}

if (!function_exists('templateVariableNames')) {
    /**
     * Every token name the application can substitute.
     *
     * Derived by asking the two variable builders rather than maintained by
     * hand, so the list cannot drift from what is actually set -- a hand-kept
     * copy would go stale exactly when a new token is added, which is the one
     * moment the check matters. Both sides of the vMotion condition are asked,
     * because a template may legitimately use either.
     *
     * The kickstart and waiting sets are merged rather than kept apart: which
     * template is which is decided by global_config.json, an uploaded file can
     * be given any name, and this list backs a warning rather than a refusal.
     *
     * @return string[]
     */
    function templateVariableNames() {
        $withVmotion = kickstartVariables(
            ['vmotion_ip' => '10.0.0.1'],
            [],
            '',
            '',
            'standard'
        );

        return array_values(array_unique(array_merge(
            array_keys($withVmotion),
            array_keys(kickstartVariables([], [], '', '', 'vcf')),
            array_keys(waitingTemplateVariables([], []))
        )));
    }
}

if (!function_exists('templateUnknownTokens')) {
    /**
     * The tokens a template uses that nothing will ever substitute.
     *
     * renderTemplate() leaves an unknown {{TOKEN}} alone rather than blanking
     * it, so a typo is visible instead of silently producing an empty value --
     * visible in the rendered kickstart, that is, on a host that is by then
     * mid-install. This is how it becomes visible at the point it was typed.
     *
     * Names used by {{IF NAME}} are checked too. processConditionals()
     * evaluates an unknown name as falsy, so a misspelled condition does not
     * fail: it quietly renders the wrong branch.
     *
     * @param string $content Template contents
     * @return string[] Token names, in the order they first appear
     */
    function templateUnknownTokens($content) {
        // Comment lines are excluded: the templates carry a header documenting
        // their own {{TOKEN}} syntax, and that is documentation, not a
        // reference to a variable.
        $stripped = (string)preg_replace('/^[ \t]*#.*$/m', '', (string)$content);

        preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $stripped, $plain);
        preg_match_all('/\{\{IF\s+([A-Z0-9_]+)\}\}/', $stripped, $conditions);

        $used = array_merge($plain[1], $conditions[1]);

        $known = array_merge(templateVariableNames(), [
            // Control directives handled by processConditionals(), not values.
            'IF', 'ELSE', 'ENDIF',
        ]);

        return array_values(array_unique(array_diff($used, $known)));
    }
}

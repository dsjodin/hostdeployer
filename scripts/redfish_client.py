#!/usr/bin/env python3
"""Redfish access to the service processors: iLO, iDRAC.

One client for both helper scripts. ilo_scanner.py spoke to Redfish with raw
requests and secure_boot_manager.py used the redfish library, so TLS policy was
decided in two places and only one of them could be tightened. The library is
gone; this is the only thing that opens a connection to a BMC.

Certificates are self-signed. Every BMC ships one, nothing signs it, and the
DNS name does not change that -- there is no chain to validate against. What
can be checked is that the certificate is the same one as last time: the
SHA-256 recorded on first contact is pinned on every connection after.

A changed digest is refused rather than re-recorded. It means a firmware reset,
a system board swap or a regenerated certificate, and all three deserve an
operator looking at them. Silently adopting the new one would leave this with
the security properties of verify=False and more code.

Authentication is HTTP basic per request rather than a Redfish session. The
session version had to be logged out on every path or the BMC ran out of its
handful of concurrent slots, and a scan of a whole rack is exactly where that
went wrong.
"""

import hashlib
import logging
import socket
import ssl

import requests
import urllib3

logger = logging.getLogger("redfish_client")

# Pinning replaces chain validation, so the chain warning is noise. It is
# disabled here rather than in each caller because this is the only module that
# makes an unverified connection.
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

DEFAULT_TIMEOUT = 15


class RedfishError(RuntimeError):
    """A BMC refused a request, or could not be reached or trusted."""


class FingerprintMismatch(RedfishError):
    """The BMC presented a different certificate than the one recorded."""


def fetch_fingerprint(address, port=443, timeout=DEFAULT_TIMEOUT):
    """The SHA-256 of the certificate a BMC is currently presenting.

    Used to learn a fingerprint on first contact. Deliberately separate from
    the pinned connection below: this one accepts anything, and the caller has
    to decide to record what it returns.

    @return Lowercase hex digest
    """
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE

    try:
        with socket.create_connection((address, port), timeout=timeout) as raw:
            with context.wrap_socket(raw, server_hostname=address) as tls:
                der = tls.getpeercert(binary_form=True)
    except (OSError, ssl.SSLError) as e:
        raise RedfishError(f"could not read the certificate of {address}: {e}") from e

    if not der:
        raise RedfishError(f"{address} presented no certificate")

    return hashlib.sha256(der).hexdigest()


class _PinnedAdapter(requests.adapters.HTTPAdapter):
    """Rejects any certificate whose SHA-256 is not the expected one.

    urllib3 does the check during the handshake, so a mismatch fails before the
    request -- and before the credentials -- goes anywhere.
    """

    def __init__(self, fingerprint, **kwargs):
        self._fingerprint = fingerprint
        super().__init__(**kwargs)

    def init_poolmanager(self, connections, maxsize, block=False, **pool_kwargs):
        pool_kwargs["assert_fingerprint"] = self._fingerprint
        super().init_poolmanager(connections, maxsize, block=block, **pool_kwargs)


class Redfish:
    """A connection to one service processor.

    @param address     Hostname or IP of the BMC
    @param username    Account with the privilege the caller needs
    @param password    Its password
    @param fingerprint Expected certificate SHA-256, or None to learn it
    """

    def __init__(self, address, username, password, fingerprint=None,
                 timeout=DEFAULT_TIMEOUT):
        self.address = address
        self.timeout = timeout
        self.learned = fingerprint is None
        self._system_path = None

        self.fingerprint = fingerprint or fetch_fingerprint(address, timeout=timeout)

        self.session = requests.Session()
        self.session.auth = (username, password)
        self.session.headers.update({"Accept": "application/json"})
        self.session.mount("https://", _PinnedAdapter(self.fingerprint))

    def url(self, path):
        """Absolute URL for a Redfish path or an @odata.id."""
        return f"https://{self.address}{path if path.startswith('/') else '/redfish/v1/' + path}"

    def system_path(self):
        """The @odata.id of this machine's ComputerSystem.

        Not a constant. HPE answers at /redfish/v1/Systems/1 and Dell at
        /redfish/v1/Systems/System.Embedded.1, and hardcoding either is how a
        tool ends up supporting one vendor. The collection is asked once and
        the answer cached for the life of the connection.
        """
        if self._system_path is None:
            systems = self.get("/redfish/v1/Systems")
            members = (systems or {}).get("Members") or []

            for member in members:
                path = member.get("@odata.id")
                if path:
                    self._system_path = path
                    break

            if self._system_path is None:
                raise RedfishError(f"{self.address} lists no ComputerSystem")

            if len(members) > 1:
                # Multi-node chassis. Taking the first is a guess, and a wrong
                # guess here disables Secure Boot on the wrong node.
                logger.warning(
                    f"{self.address} lists {len(members)} systems; using {self._system_path}"
                )

        return self._system_path

    def _request(self, method, path, **kwargs):
        url = self.url(path)

        try:
            # verify=False leaves the fingerprint as the only check. Without it
            # requests would ask for chain validation, which a self-signed
            # certificate cannot pass, and the pin would never be reached.
            response = self.session.request(
                method, url, timeout=self.timeout, verify=False, **kwargs
            )
        except requests.exceptions.SSLError as e:
            # urllib3 reports a failed pin as an SSLError like any other
            # handshake failure. Distinguishing it matters: one of these is an
            # operator's problem and the rest are the network's.
            if "fingerprint" in str(e).lower():
                raise FingerprintMismatch(
                    f"{self.address} presented an unexpected certificate. "
                    "Confirm the BMC was reset or replaced, then clear the "
                    "recorded fingerprint for this host."
                ) from e
            raise RedfishError(f"{method} {url} failed: {e}") from e
        except requests.RequestException as e:
            raise RedfishError(f"{method} {url} failed: {e}") from e

        if response.status_code == 401:
            raise RedfishError(f"{self.address} rejected the credentials")

        return response

    def get(self, path):
        """GET a resource, or None when it does not exist on this BMC.

        Redfish implementations differ in which optional resources they carry,
        so a 404 is a fact about the hardware rather than an error.
        """
        response = self._request("GET", path)

        if response.status_code == 404:
            return None

        if not response.ok:
            raise RedfishError(
                f"GET {path} on {self.address}: {response.status_code}"
            )

        try:
            return response.json()
        except ValueError as e:
            raise RedfishError(f"GET {path} on {self.address}: not JSON") from e

    def patch(self, path, body):
        """PATCH a resource. Accepts the several success codes BMCs use."""
        response = self._request(
            "PATCH", path,
            json=body,
            headers={"Content-Type": "application/json", "If-Match": "*"},
        )

        if response.status_code not in (200, 202, 204):
            raise RedfishError(
                f"PATCH {path} on {self.address}: {response.status_code} "
                f"{response.text[:200]}"
            )

        return response

    def post(self, path, body):
        """POST an action."""
        response = self._request(
            "POST", path, json=body, headers={"Content-Type": "application/json"}
        )

        if response.status_code not in (200, 202, 204):
            raise RedfishError(
                f"POST {path} on {self.address}: {response.status_code} "
                f"{response.text[:200]}"
            )

        return response

#!/usr/bin/env python3
"""Client for the hostdeployer REST API.

The helper scripts used to open config/hosts.json and config/credentials.json
directly. That made them a second implementation of the storage format, and it
meant nothing could change about how those files are written -- encrypting the
credentials, or moving the inventory into SQLite -- without changing them too.

Going through the API instead leaves PHP as the only thing that touches either
file. It also means the scripts obey the same permission model as everything
else: the token decides what they may do.

Configuration comes from the environment:

    AUTODEPLOY_API_URL      base URL (default https://localhost/api/v1)
    AUTODEPLOY_API_TOKEN    bearer token; generate with
                            php lib/api_auth.php <name>
    AUTODEPLOY_API_CA       CA bundle to verify the server against. Unset
                            means the appliance's self-signed certificate is
                            not verified, which is the default because the
                            provisioning network is isolated and the
                            certificate is generated at install time.
"""

import logging
import os

import requests
import urllib3

logger = logging.getLogger("autodeploy_api")

DEFAULT_URL = "https://localhost/api/v1"
TIMEOUT = 30


class ApiError(RuntimeError):
    """The API refused a request or could not be reached."""


class AutodeployApi:
    """Thin wrapper over the endpoints the helper scripts need."""

    def __init__(self, base_url=None, token=None, verify=None):
        self.base_url = (base_url or os.environ.get("AUTODEPLOY_API_URL") or DEFAULT_URL).rstrip("/")

        self.token = token or os.environ.get("AUTODEPLOY_API_TOKEN") or ""
        if not self.token:
            raise ApiError(
                "No API token. Set AUTODEPLOY_API_TOKEN; generate one with "
                "'php lib/api_auth.php <name>' and add it to config/auth_config.php."
            )

        if verify is None:
            ca = os.environ.get("AUTODEPLOY_API_CA")
            verify = ca if ca else False
        self.verify = verify

        if self.verify is False:
            # Otherwise every single request prints its own warning.
            urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
        })

    def _request(self, method, path, **kwargs):
        url = f"{self.base_url}/{path.lstrip('/')}"

        try:
            response = self.session.request(
                method, url, timeout=TIMEOUT, verify=self.verify, **kwargs
            )
        except requests.RequestException as e:
            raise ApiError(f"{method} {url} failed: {e}") from e

        if response.status_code == 401:
            raise ApiError(
                f"{method} {url}: the API rejected the token. Check "
                "AUTODEPLOY_API_TOKEN against config/auth_config.php."
            )
        if response.status_code == 403:
            raise ApiError(
                f"{method} {url}: the token's role does not permit this. "
                "The helper scripts need read, write and settings."
            )

        return response

    @staticmethod
    def _json(response):
        try:
            return response.json()
        except ValueError:
            return {}

    def _checked(self, method, path, **kwargs):
        response = self._request(method, path, **kwargs)
        body = self._json(response)

        if not response.ok:
            raise ApiError(
                f"{method} {path}: {response.status_code} "
                f"{body.get('error', response.text[:200])}"
            )

        return body

    # -- hosts --------------------------------------------------------------

    def get_hosts(self):
        """Every host in the inventory."""
        return self._checked("GET", "/hosts").get("hosts", [])

    def get_host(self, mac):
        """One host, or None when it is not registered."""
        response = self._request("GET", f"/hosts/{mac}")
        if response.status_code == 404:
            return None
        if not response.ok:
            raise ApiError(
                f"GET /hosts/{mac}: {response.status_code} "
                f"{self._json(response).get('error', '')}"
            )
        return self._json(response)

    def merge_discovered(self, discovered):
        """Merge hardware scan results into the inventory.

        The matching and merging happen server side, next to the storage and
        inside its lock, so a scan running while an operator edits a host
        cannot lose either change.

        Returns (updated, added).
        """
        body = self._checked("POST", "/hosts/discovered", json={"hosts": discovered})
        return body.get("updated", 0), body.get("added", 0)

    def set_secure_boot_status(self, mac, status):
        """Record the secure boot state read back from the BMC."""
        self._checked("PATCH", f"/hosts/{mac}/secure-boot", json={"status": status})

    # -- credentials --------------------------------------------------------

    def get_credentials(self, credential_type, mac=None):
        """Credentials for a type, with any per-host override applied.

        Returns an empty dict when none are configured, so a caller can report
        that plainly rather than crashing on a missing key.
        """
        params = {"mac": mac} if mac else None
        response = self._request("GET", f"/credentials/{credential_type}", params=params)

        if response.status_code == 404:
            return {}
        if not response.ok:
            raise ApiError(
                f"GET /credentials/{credential_type}: {response.status_code} "
                f"{self._json(response).get('error', '')}"
            )

        return self._json(response)

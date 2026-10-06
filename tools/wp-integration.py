"""Bootstrap and test a fresh synthetic WordPress site against a dedicated CI MySQL service."""

import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import stat
import time
import urllib.request
import urllib.error

ROOT = Path(__file__).resolve().parent.parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--wp-cli", type=Path, required=True)
    parser.add_argument("--lab", type=Path, required=True)
    parser.add_argument("--core-version", default="6.8.3")
    args = parser.parse_args()
    lab = args.lab.resolve()
    if lab.parent != Path("/tmp") or args.lab.is_symlink() or lab.exists():
        raise ValueError("Use a new direct temporary lab directory")
    if os.environ.get("COMPANION_TEST_DATABASE") != "pagenest_companion_ci":
        raise ValueError("Explicit dedicated synthetic database required")
    host = os.environ.get("COMPANION_TEST_DB_HOST", "127.0.0.1:3306")
    if host.startswith("localhost:/tmp/"):
        socket = Path(host.split(":", 1)[1])
        if socket.is_symlink() or not socket.exists() or not stat.S_ISSOCK(socket.stat().st_mode):
            raise ValueError("Explicit temporary Unix socket required")
    elif not host.startswith("127.0.0.1:"):
        raise ValueError("CI database must use explicit loopback TCP or temporary Unix socket")
    os.umask(0o077)
    lab.mkdir()
    site = lab / "site"
    site.mkdir()
    (lab / "state.json").write_text(json.dumps({"owner": "component-migration", "stopped": False}))
    env = dict(
        os.environ,
        COMPONENT_LAB_STATE=str(lab / "migration-fixtures.json"),
        COMPONENT_LAB_PROFILE=str(lab / "profile.json"),
    )
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    command = [
        "php",
        str(args.wp_cli.resolve()),
        "--skip-packages",
        "--no-color",
        "--path=" + str(site),
    ]

    def wp(*arguments, mode=None):
        current = dict(env)
        if mode is not None:
            current["COMPONENT_LAB_MODE"] = mode
        result = subprocess.run(
            command + list(arguments), env=current, text=True, capture_output=True
        )
        if result.returncode:
            raise RuntimeError(result.stderr)
        return result.stdout

    wp("core", "download", "--version=" + args.core_version)
    wp(
        "config",
        "create",
        "--dbname=pagenest_companion_ci",
        "--dbhost=" + host,
        "--dbuser=" + os.environ.get("COMPANION_TEST_DB_USER", "root"),
        "--dbpass=" + os.environ.get("COMPANION_TEST_DB_PASSWORD", ""),
        "--dbprefix=synthetic_",
        "--skip-check",
        "--skip-salts",
    )
    wp("db", "create")
    wp(
        "core",
        "install",
        "--url=http://127.0.0.1:18841",
        "--title=Synthetic Companion CI",
        "--admin_user=fixture-author",
        "--admin_password=synthetic-local-only",
        "--admin_email=author@example.invalid",
        "--skip-email",
    )
    wp("config", "set", "WP_ENVIRONMENT_TYPE", "local")
    wp(
        "user",
        "create",
        "fixture-reader",
        "reader@example.invalid",
        "--role=subscriber",
        "--user_pass=synthetic-local-only",
    )
    shutil.copytree(
        ROOT,
        site / "wp-content/plugins/pagenest-compatibility",
        ignore=shutil.ignore_patterns(
            ".git",
            "node_modules",
            "dist",
            ".runtime",
            "test-results",
            "playwright-report",
            "__pycache__",
        ),
    )
    profile = {
        "schema_version": 1,
        "paragraphs": {
            "post_type": "fixture_note",
            "data_meta": "_fixture_note_data",
            "uuid_meta": "_fixture_note_uuid",
            "document_meta": "_fixture_document",
            "registry_meta": "_fixture_registry",
            "library_meta": "_fixture_library",
            "enabled_meta": "_fixture_enabled",
            "rest_namespace": "pagenest-comments/v1",
            "rest_aliases": ["fixture-comments/v1"],
            "shortcodes": ["fixture_library"],
        },
        "theme": {"class_aliases": {"fixture-hint": "pagenest-exercise-hint"}},
        "likes": {
            "counter_meta": "bigfa_ding",
            "record_meta": "_pagenest_like_users",
            "legacy_provider": {
                "table_suffix": "fixture_events",
                "user_column": "user_id",
                "post_column": "object_id",
                "event_column": "kind",
                "event_value": "like",
                "record_column": "event_key",
                "record_prefix": "like:",
            },
        },
    }
    (lab / "profile.json").write_text(json.dumps(profile))
    wp("config", "set", "PAGENEST_COMPATIBILITY_PROFILE_FILE", str(lab / "profile.json"))
    wp("plugin", "activate", "pagenest-compatibility")
    results = {}
    for mode in ["seed", "verify"]:
        results[mode] = json.loads(wp("eval-file", str(ROOT / "tests/wp-wordpress.php"), mode=mode))
    results["settings"] = json.loads(wp("eval-file", str(ROOT / "tests/wp-settings.php")))
    subprocess.run(
        [
            "python3",
            str(ROOT / "tools/concurrency.py"),
            "--lab",
            str(lab),
            "--wp-cli",
            str(args.wp_cli.resolve()),
        ],
        env=env,
        check=True,
        capture_output=True,
        text=True,
    )
    results["concurrency"] = json.loads((lab / "concurrency-results.json").read_text())
    session = lab / "session.json"
    env["COMPONENT_LAB_SESSION"] = str(session)
    wp("eval-file", str(ROOT / "tests/wp-session.php"))
    # Verify WordPress cookie authentication and nonce protection over actual HTTP.
    server_env = dict(env, COMPONENT_LAB_SITE=str(site), COMPONENT_LAB_HTTP_HOST="127.0.0.1:18841")
    with (lab / "http.log").open("w") as log:
        process = subprocess.Popen(
            ["php", "-S", "127.0.0.1:18841", "-t", str(site), str(ROOT / "tools/wp-router.php")],
            env=server_env,
            stdout=log,
            stderr=log,
        )
        try:
            for _ in range(50):
                try:
                    opener.open("http://127.0.0.1:18841", timeout=1)
                    break
                except (OSError, urllib.error.HTTPError):
                    time.sleep(0.1)
            fixture = json.loads((lab / "migration-fixtures.json").read_text())
            auth = json.loads(session.read_text())
            cookie = "; ".join(entry["name"] + "=" + entry["value"] for entry in auth["cookies"])

            def request(nonce):
                headers = {"Cookie": cookie, "Content-Type": "application/json"}
                if nonce:
                    headers["X-WP-Nonce"] = nonce
                req = urllib.request.Request(
                    "http://127.0.0.1:18841/?rest_route=/pagenest-comments/v1/comments",
                    data=json.dumps(
                        {
                            "post_id": fixture["post"],
                            "block_id": fixture["registry"][0]["id"],
                            "text": "HTTP nonce fixture",
                            "public_confirmed": True,
                        }
                    ).encode(),
                    headers=headers,
                    method="POST",
                )
                try:
                    with opener.open(req, timeout=10) as response:
                        return response.status, json.loads(response.read())
                except urllib.error.HTTPError as error:
                    return error.code, json.loads(error.read())

            assert request(None)[0] == 401, "Cookie without REST nonce must remain anonymous"
            assert request("invalid")[0] == 403, "Invalid nonce must be rejected"
            status, data = request(auth["nonce"])
            assert status == 200, data
            results["http"] = {
                "checks": [
                    "Cookie alone cannot write",
                    "Invalid nonce rejected",
                    "Authenticated nonce permits explicit public comment",
                ]
            }
        finally:
            process.terminate()
            process.wait(timeout=10)
            wp(
                "eval",
                "$s=json_decode(file_get_contents(getenv('COMPONENT_LAB_SESSION')),true);WP_Session_Tokens::get_instance($s['user'])->destroy($s['token']);",
            )
            session.unlink(missing_ok=True)
    (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
    print(
        json.dumps({key: len(value.get("checks", [])) for key, value in results.items()}, indent=2)
    )


if __name__ == "__main__":
    main()

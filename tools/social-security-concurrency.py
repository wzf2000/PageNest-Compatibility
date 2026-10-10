"""Run account security races only in the existing owned synthetic CI lab."""

import argparse
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parent.parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--lab", type=Path, required=True)
    parser.add_argument("--wp-cli", type=Path, required=True)
    args = parser.parse_args()
    lab = args.lab.resolve()
    assert lab.parent == Path("/tmp") and (lab / "state.json").is_file()
    assert json.loads((lab / "state.json").read_text())["owner"] == "component-migration"
    marker = lab / "social-owned"
    if marker.exists():
        assert marker.read_text() == "pagenest-social-synthetic"
    else:
        marker.write_text("pagenest-social-synthetic")
    env = dict(os.environ, PAGENEST_SOCIAL_TEST_STATE=str(lab / "security-race.json"))
    command = [
        "php",
        str(args.wp_cli.resolve()),
        "--skip-packages",
        "--no-color",
        "--path=" + str(lab / "site"),
        "eval-file",
        str(ROOT / "tests/social-security-concurrency.php"),
    ]

    def worker(mode):
        result = subprocess.run(
            command + [mode], env=env, text=True, capture_output=True, check=True
        )
        return json.loads(result.stdout)

    worker("prepare")
    results = {}
    try:
        for mode in ["register", "register-username", "register-email", "email", "pending"]:
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                outcomes = list(pool.map(worker, [mode, mode]))
            assert sorted(row["result"] for row in outcomes) == ["accepted", "refused"]
            results[mode] = {"accepted": 1, "refused": 1}
        results["postconditions"] = worker("inspect")
    finally:
        worker("cleanup")
    (lab / "social-security-concurrency.json").write_text(json.dumps(results, indent=2) + "\n")
    print(json.dumps(results))


if __name__ == "__main__":
    main()

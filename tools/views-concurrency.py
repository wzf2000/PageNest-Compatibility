"""Check initialization and increments with two real WordPress processes in an owned local lab."""

from concurrent.futures import ThreadPoolExecutor
import argparse
import json
import os
from pathlib import Path
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--lab", type=Path, required=True)
    parser.add_argument("--wp-cli", type=Path, required=True)
    args = parser.parse_args()
    lab = args.lab.resolve()
    assert lab.parent == Path("/tmp") and not args.lab.is_symlink()
    assert json.loads((lab / "state.json").read_text())["owner"] == "component-migration"
    env = dict(os.environ, PAGENEST_VIEWS_TEST_STATE=str(lab / "views-test-state.json"))
    worker = Path(__file__).resolve().parent.parent / "tests/wp-views-concurrency.php"
    command = [
        "php",
        str(args.wp_cli),
        "--skip-packages",
        "--no-color",
        "--path=" + str(lab / "site"),
        "eval-file",
        str(worker),
    ]

    def run(mode):
        result = subprocess.run(
            command + [mode], env=env, capture_output=True, text=True, check=True
        )
        return json.loads(result.stdout)

    run("prepare")
    try:
        with ThreadPoolExecutor(max_workers=2) as pool:
            first = list(pool.map(lambda _: run("increment"), range(2)))
            assert sorted(value["count"] for value in first) == [41, 42], first
            later = list(pool.map(lambda _: run("increment"), range(2)))
            assert sorted(value["count"] for value in later) == [43, 44], later
        assert run("inspect") == {"count": 44, "rows": 1, "source": "40"}
        print(
            json.dumps(
                {
                    "checks": [
                        "Parallel first views seed once",
                        "Parallel existing counters retain both increments",
                        "Legacy source remains unchanged",
                    ]
                }
            )
        )
    finally:
        run("cleanup")


if __name__ == "__main__":
    main()
